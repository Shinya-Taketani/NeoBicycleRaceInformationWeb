<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture;

use App\Domain\Keirin\Scraping\PrestartEvidence\EvidenceFiles;
use JsonException;
use RuntimeException;

final class CaptureVerifier
{
    public function __construct(private readonly Projection $projection = new Projection) {}

    public function verify(string $directory, string $expectedManifestSha256): array
    {
        $report = $this->payload($directory, $expectedManifestSha256);
        $marker = $report['status'] === 'FAILED' ? 'FAILED' : 'COMPLETE';
        $this->fileSet($directory, $marker.'.json');
        $saved = $this->object(EvidenceFiles::read($directory, $marker.'.json'));
        if ($saved !== ['version' => Contract::VERSION, 'capture_id' => basename($directory),
            'manifest_sha256' => $expectedManifestSha256, 'state' => $marker]) {
            throw new RuntimeException('Offline publication marker mismatch.');
        }

        return $report;
    }

    public function verifyBeforePublication(string $directory, string $expectedManifestSha256): array
    {
        $this->fileSet($directory, null);

        return $this->payload($directory, $expectedManifestSha256);
    }

    private function payload(string $directory, string $hash): array
    {
        Contract::id(basename($directory));
        $bytes = EvidenceFiles::read($directory, 'manifest.json');
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1 || ! hash_equals($hash, hash('sha256', $bytes))) {
            throw new RuntimeException('External offline manifest SHA mismatch.');
        }
        $manifest = $this->object($bytes);
        $expectedFiles = $this->files($directory);
        if (($manifest['version'] ?? null) !== Contract::VERSION || ($manifest['capture_id'] ?? null) !== basename($directory)
            || ($manifest['code_seal'] ?? null) !== Contract::codeSeal() || array_keys($manifest['files'] ?? []) !== $expectedFiles
            || ($manifest['restrictions'] ?? null) !== Contract::restrictions()) {
            throw new RuntimeException('Offline manifest contract/code/file set mismatch.');
        }
        foreach ($manifest['files'] as $name => $seal) {
            if (EvidenceFiles::seal(EvidenceFiles::read($directory, $name)) !== $seal) {
                throw new RuntimeException('Offline evidence seal mismatch: '.$name);
            }
        }
        $report = $this->evaluate($directory);
        if (EvidenceFiles::read($directory, 'validation.json') !== EvidenceFiles::json($report)) {
            throw new RuntimeException('Offline saved validation differs from independent projection.');
        }

        return $report;
    }

    public function evaluate(string $directory): array
    {
        $request = $this->object(EvidenceFiles::read($directory, 'request.json'));
        $private = $this->object(EvidenceFiles::read($directory, 'private-request.json'));
        $response = $this->object(EvidenceFiles::read($directory, 'response.json'));
        $privateResponse = $this->object(EvidenceFiles::read($directory, 'private-response.json'));
        $allowed = $this->object(EvidenceFiles::read($directory, 'allowed-targets.json'));
        $input = new CaptureRequest($request['capture_id'], $request['attempt_id'], $request['target'],
            $private['request_uri'], $private['parameters'], $allowed, $request['allowed_manifest_sha256'],
            $request['allowed_manifest_reference'], $request['mode'], $request['prediction_input_as_of'],
            $request['database_race_type'], $request['program_version']);
        if ($input->captureId !== basename($directory) || $input->publicData() !== $request) {
            throw new RuntimeException('Offline request binding mismatch.');
        }
        $body = $response['body_present'] ? EvidenceFiles::read($directory, 'raw.bin') : null;
        $output = new CaptureResponse($response['capture_id'], $response['attempt_id'], $response['request_started_at'],
            $response['response_received_at'], $response['http_status'], $privateResponse['effective_uri'],
            $response['redirect_detected'], $body, $response['content_type'], $response['timing_provenance'],
            $response['failure_class'], $response['declared_raw_seal'], $response['historical_source']);
        if ([...$output->publicData(), 'raw_saved_at' => $response['raw_saved_at']] !== $response) {
            throw new RuntimeException('Offline response/private URI binding mismatch.');
        }
        $execution = $this->object(EvidenceFiles::read($directory, 'execution.json'));
        $started = Contract::instant($execution['started_at']);
        $finished = Contract::instant($execution['finished_at']);
        $saved = Contract::instant($response['raw_saved_at']);
        if ($started === null || $finished === null || $started > $finished || ($body !== null && ($saved === null || $saved < $started || $saved > $finished))
            || ($body === null && $saved !== null) || $execution['time_basis'] !== 'CURRENT_OFFLINE_SYSTEM_CLOCK') {
            throw new RuntimeException('Offline persistence clock/order evidence mismatch.');
        }

        return $this->projection->evaluate($input, $output, $response['raw_saved_at']);
    }

    private function files(string $directory): array
    {
        $response = $this->object(EvidenceFiles::read($directory, 'response.json'));

        return [...Contract::FILES, ...($response['body_present'] ? ['raw.bin'] : [])];
    }

    private function fileSet(string $directory, ?string $marker): void
    {
        $actual = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
        $expected = [...$this->files($directory), 'manifest.json', ...($marker === null ? [] : [$marker])];
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new RuntimeException('Offline missing/extra/contradictory publication files.');
        }
    }

    private function object(string $bytes): array
    {
        try {
            return EvidenceFiles::object($bytes);
        } catch (JsonException $error) {
            throw new RuntimeException('Invalid offline evidence JSON.', 0, $error);
        }
    }
}
