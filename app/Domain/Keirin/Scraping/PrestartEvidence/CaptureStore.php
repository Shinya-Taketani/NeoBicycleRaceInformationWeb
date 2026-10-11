<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartEvidence;

use DateTimeImmutable;
use RuntimeException;
use Throwable;

final class CaptureStore
{
    private readonly array $rootIdentity;

    /** @param array{route: array{scheme: string, host: string, port: int, path: string}, method: string} $policy */
    public function __construct(private readonly string $root, private readonly array $policy)
    {
        $this->rootIdentity = EvidenceFiles::writableRoot($root);
        if (! isset($policy['route']['scheme'], $policy['route']['host'], $policy['route']['port'], $policy['route']['path'], $policy['method'])
            || ! is_int($policy['route']['port']) || ! str_starts_with($policy['route']['path'], '/')) {
            throw new RuntimeException('Explicit synthetic source policy required.');
        }
    }

    public function save(SyntheticCapture $input): StoredCapture
    {
        if (EvidenceFiles::directory($this->root) !== $this->rootIdentity) {
            throw new RuntimeException('Recorded evidence root changed.');
        }
        $directory = $this->root.'/'.$input->captureId;
        if (EvidenceFiles::stat($directory) !== null) {
            throw new RuntimeException('Capture already exists; no overwrite or repair.');
        }
        $startSeal = EvidenceFiles::codeSeal();
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('Exclusive capture reservation failed.');
        }
        try {
            EvidenceFiles::writeNew($directory, 'raw.bin', $input->raw);
            EvidenceFiles::writeNew($directory, 'capture.json', EvidenceFiles::json([
                'version' => EvidenceFiles::VERSION, 'fixture_kind' => 'SYNTHETIC', 'capture_id' => $input->captureId,
                'source' => $input->source, 'raw_path' => 'raw.bin', 'raw_seal' => EvidenceFiles::seal($input->raw),
                'fetched_at' => $input->fetchedAt, 'raw_saved_at' => (new DateTimeImmutable)->format(DATE_ATOM),
                'schedule_timezone' => $input->scheduleTimezone, 'clock_evidence' => $input->clockEvidence,
                'http_method' => $input->method, 'http_status' => $input->httpStatus, 'redirect_detected' => $input->redirectDetected,
                'request_uri_sha256' => hash('sha256', $input->requestUri),
                'effective_uri_sha256' => $input->effectiveUri === null ? null : hash('sha256', $input->effectiveUri),
                'fetch_log_id' => null, 'fetch_log_reason' => 'SYNTHETIC_NO_DB', 'prediction_use' => 'NOT_AUTHORIZED',
            ]));
            EvidenceFiles::writeNew($directory, 'targets.json', EvidenceFiles::json([
                'capture_id' => $input->captureId, 'targets' => array_map(fn (RaceTarget $target): array => $target->toArray(), $input->targets),
            ]));
            EvidenceFiles::writeNew($directory, 'private-source.json', EvidenceFiles::json([
                'capture_id' => $input->captureId, 'request_uri' => $input->requestUri, 'effective_uri' => $input->effectiveUri,
                'parameters' => $input->parameters, 'policy' => $this->policy,
            ]));
            $verifier = new CaptureVerifier;
            $report = $verifier->evaluate($directory);
            EvidenceFiles::writeNew($directory, 'validation.json', EvidenceFiles::json($report));
            $files = [];
            foreach (EvidenceFiles::FILES as $name) {
                $files[$name] = EvidenceFiles::seal(EvidenceFiles::read($directory, $name));
            }
            if (EvidenceFiles::codeSeal() !== $startSeal || EvidenceFiles::json($verifier->evaluate($directory)) !== EvidenceFiles::json($report)) {
                throw new RuntimeException('Publication inputs or code changed.');
            }
            $manifest = EvidenceFiles::json(['version' => EvidenceFiles::VERSION, 'fixture_kind' => 'SYNTHETIC',
                'capture_id' => $input->captureId, 'files' => $files, 'code_seal' => $startSeal]);
            EvidenceFiles::writeNew($directory, 'manifest.json', $manifest);
            $sha = hash('sha256', $manifest);
            $verifier->verifyBeforePublication($directory, $sha);
            // The exclusive marker is the publication boundary, not directory existence.
            EvidenceFiles::writeNew($directory, $report['publication_state'].'.json', EvidenceFiles::json([
                'version' => EvidenceFiles::VERSION, 'capture_id' => $input->captureId,
                'manifest_sha256' => $sha, 'state' => $report['publication_state'],
            ]));
            $verifier->verify($directory, $sha);

            return new StoredCapture($directory, $input->captureId, $sha, $report);
        } catch (Throwable $error) {
            if (EvidenceFiles::stat($directory.'/COMPLETE.json') === null && EvidenceFiles::stat($directory.'/FAILED.json') === null) {
                EvidenceFiles::writeNew($directory, 'FAILED.json', EvidenceFiles::json([
                    'version' => EvidenceFiles::VERSION, 'capture_id' => $input->captureId,
                    'state' => 'FAILED', 'reason' => 'CAPTURE_PERSISTENCE_OR_PUBLICATION_FAILED', 'exception' => $error::class,
                ]));
            }
            throw $error;
        }
    }
}
