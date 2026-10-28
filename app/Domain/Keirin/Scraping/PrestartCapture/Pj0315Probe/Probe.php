<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture\Pj0315Probe;

use App\Domain\Keirin\Scraping\PrestartCapture\Contract as OfflineContract;
use App\Domain\Keirin\Scraping\PrestartEvidence\EvidenceFiles;
use RuntimeException;
use Throwable;

class Probe
{
    public const VERSION = 'PJ0315-REAL-HTTP-PROBE-v2';

    public function __construct(private readonly SinglePostTransport $transport, private readonly ScopeAttempt $scope,
        private readonly Inspector $inspector = new Inspector) {}

    public function run(Target $target, ?string $root, bool $execute): array
    {
        $base = ['version' => self::VERSION, 'target' => $target->race, 'source_evidence' => $target->evidence,
            'prediction_use' => 'NOT_AUTHORIZED', 'historical_as_of_available' => false, 'formal_adoption' => false,
            'official_publication_at' => null, 'points' => null, 'confidence' => null,
            'permission_status' => 'UNVERIFIED', 'purpose' => 'CURRENT_FETCH_OF_2025_RACE_TECHNICAL_CHECK_ONLY',
            'database_writes' => 0, 'model_updates' => 0, 'http_dispatch_count' => 0];
        if (! $execute) {
            return [...$base, 'status' => 'PLAN_ONLY_NO_HTTP'];
        }
        $writeStarted = false;
        $attemptId = null;
        $transfer = null;
        $raw = null;
        try {
            if ($root === null) {
                throw new RuntimeException('An existing dedicated private output root is required.');
            }
            $identity = EvidenceFiles::writableRoot($root);
            if (file_exists($root.'/attempt.json') || is_link($root.'/attempt.json')) {
                throw new RuntimeException('Existing output attempt cannot be overwritten.');
            }
            $code = self::codeSeal();
            $reservation = $this->scope->reserve($target, $root);
            $attemptId = $reservation['attempt_id'];
            $writeStarted = true;
            $this->write($root, 'attempt.json', [...$base, 'attempt_id' => $attemptId,
                'scope' => ScopeAttempt::definition(), 'started_at' => OfflineContract::now(), 'code_seal' => $code]);
            $this->write($root, 'private-request.json', ['uri' => Target::URI, 'method' => 'POST',
                'parameters' => $target->parameters, 'expected_entries' => $target->entries]);
            if (file_exists($root.'/raw.bin') || is_link($root.'/raw.bin')) {
                throw new RuntimeException('Raw collision; no request sent.');
            }
            $raw = fopen($root.'/raw.bin', 'x+b');
            if ($raw === false || ! chmod($root.'/raw.bin', 0600)) {
                throw new RuntimeException('Cannot exclusively create Raw sink.');
            }
            $this->scope->dispatching($reservation);
            $received = $this->transport->exchange($target, $raw);
            $transfer = $received->metadata;
            $this->scope->complete($reservation, $transfer);
            if (! is_resource($raw) || ! fflush($raw) || ! fsync($raw)) {
                throw new RuntimeException('Cannot persist original response bytes.');
            }
            $savedAt = OfflineContract::now();
            fclose($raw);
            $raw = null;
            $this->write($root, 'private-response.json', $transfer);
            $bytes = EvidenceFiles::read($root, 'raw.bin');
            $seal = EvidenceFiles::seal($bytes);
            $http = $transfer['http_status'];
            $reasons = [];
            if ($transfer['failure'] !== null) {
                $reasons[] = $transfer['failure'];
            }
            if ($http !== 200) {
                $reasons[] = $http === null ? 'NO_HTTP_RESPONSE' : 'HTTP_'.$http;
            }
            if ($transfer['effective_uri'] !== Target::URI) {
                $reasons[] = 'EFFECTIVE_URI_NOT_VERIFIED';
            }
            if (strlen($bytes) > 4194304) {
                $reasons[] = 'RESPONSE_SIZE_EXCEEDED';
            }
            // Analysis reads the persisted Raw, never the in-memory HTTP response.
            $analysis = $reasons === [] ? $this->inspector->inspect($bytes, $transfer['content_type'], $target)
                : ['status' => 'NOT_PARSED_HTTP_OR_SOURCE_FAILURE'];
            if ($reasons === [] && $analysis['status'] !== 'PARSED_IDENTITY_VERIFIED') {
                $reasons[] = $analysis['status'];
            }
            $result = [...$base, 'http_dispatch_count' => 1, 'http_status' => $http,
                'fetch_status' => $transfer['failure'] === null && $http === 200 ? 'HTTP_200_RECEIVED' : 'FETCH_FAILED',
                'request_started_at' => $transfer['request_started_at'], 'response_received_at' => $transfer['response_received_at'],
                'raw_saved_at' => $savedAt, 'content_type' => $transfer['content_type'], 'raw_seal' => $seal,
                'raw_complete' => $transfer['failure'] === null, 'effective_uri_sha256' => $transfer['effective_uri'] === null
                    ? null : hash('sha256', $transfer['effective_uri']), 'curl_request_size' => $transfer['curl_request_size'],
                'status' => $reasons === [] ? 'FETCH_AND_PARSE_VERIFIED' : 'FAILED', 'reasons' => $reasons,
                'parser' => $analysis, 'scope_attempt' => $this->scope->status()];
            $this->write($root, 'result.json', $result);
            if (EvidenceFiles::writableRoot($root) !== $identity || self::codeSeal() !== $code
                || EvidenceFiles::seal(EvidenceFiles::read($root, 'raw.bin')) !== $seal) {
                throw new RuntimeException('Code, output root or saved Raw changed before publication.');
            }
            $files = [];
            foreach (['attempt.json', 'private-request.json', 'private-response.json', 'raw.bin', 'result.json'] as $name) {
                $files[$name] = EvidenceFiles::seal(EvidenceFiles::read($root, $name));
            }
            $this->write($root, 'manifest.json', ['version' => self::VERSION, 'files' => $files, 'code_seal' => $code]);
            $marker = $reasons === [] ? 'COMPLETE' : 'FAILED';
            $this->write($root, $marker.'.json', ['state' => $marker, 'attempt_id' => $attemptId,
                'manifest_sha256' => hash_file('sha256', $root.'/manifest.json')]);

            return self::summary($result);
        } catch (Throwable $error) {
            if (is_resource($raw)) {
                fclose($raw);
            }
            $facts = ['evidence_status' => 'NOT_CREATED', 'evidence_retained' => false,
                'output_write_started' => $writeStarted, 'failed_evidence_confirmed' => false];
            if ($writeStarted) {
                $facts['evidence_status'] = 'WRITE_STARTED_RETENTION_UNCONFIRMED';
                $facts['evidence_retained'] = null;
                try {
                    if (! file_exists($root.'/FAILED.json') && ! is_link($root.'/FAILED.json')) {
                        $this->write($root, 'FAILED.json', ['state' => 'FAILED', 'attempt_id' => $attemptId,
                            'reason' => 'PERSISTENCE_OR_INTEGRITY_FAILURE', 'exception_class' => $error::class,
                            'http_dispatch_count' => $transfer['http_dispatch_count'] ?? null]);
                    }
                    $failed = EvidenceFiles::object(EvidenceFiles::read($root, 'FAILED.json'));
                    if (($failed['state'] ?? null) === 'FAILED' && ($failed['attempt_id'] ?? null) === $attemptId) {
                        $facts = ['evidence_status' => 'FAILED_EVIDENCE_CONFIRMED', 'evidence_retained' => true,
                            'output_write_started' => true, 'failed_evidence_confirmed' => true];
                    }
                } catch (Throwable) {
                    // Inability to confirm retention must stay UNKNOWN, never assumed true or deleted.
                }
            }
            throw new ProbeFailure([...$facts, 'scope_attempt' => $this->scope->status()], $error);
        }
    }

    public static function summary(array $result): array
    {
        unset($result['parser']['attributes']);

        return $result;
    }

    protected function write(string $root, string $name, array $data): void
    {
        OfflineContract::writeNew($root, $name, EvidenceFiles::json($data));
    }

    public static function codeSeal(): array
    {
        $files = [];
        foreach (glob(__DIR__.'/*.php') as $path) {
            $files[basename($path)] = ['bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)];
        }

        $root = dirname(__DIR__, 6);
        foreach (['app/Console/Commands/Keirin/ProbePj0315RealHttpCommand.php', 'composer.lock'] as $name) {
            $files[$name] = ['bytes' => filesize($root.'/'.$name), 'sha256' => hash_file('sha256', $root.'/'.$name)];
        }

        return ['version' => self::VERSION, 'probe_files' => $files, 'frozen_dependencies' => OfflineContract::codeSeal()];
    }
}
