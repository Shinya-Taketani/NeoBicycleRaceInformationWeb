<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture\Pj0315Probe;

use App\Domain\Keirin\Scraping\PrestartCapture\Contract;
use App\Domain\Keirin\Scraping\PrestartEvidence\EvidenceFiles as Files;
use RuntimeException;
use Throwable;

/** One fixed technical check, not the operational capture budget/approval ledger. */
final class ScopeAttempt
{
    public const DIRECTORY = '/home/shinya/neo-keirin-artifacts/prestart-capture-pj0315-real-http-01/technical-scope-attempt-01/registry';

    public function __construct(private readonly string $directory = self::DIRECTORY) {}

    public static function definition(): array
    {
        return ['version' => 'PJ0315-TECHNICAL-SCOPE-ATTEMPT-v1',
            'approval_scope' => 'PRESTART-CAPTURE-PJ0315-REAL-HTTP-01',
            'verification_id' => 'SINGLE_2025_PJ0315_ENDPOINT_CHECK_01', 'attempt_limit' => 1];
    }

    public static function binding(Target $target): string
    {
        return hash('sha256', Files::json(['target' => $target->race, 'source_evidence' => $target->evidence,
            'request_binding' => Contract::binding(Target::URI, $target->parameters)]));
    }

    public function reserve(Target $target, string $output): array
    {
        if (! is_dir($this->directory) || is_link($this->directory)) {
            throw new RuntimeException('Existing initialized technical scope directory required.');
        }
        $identity = Files::writableRoot($this->directory);
        if ($output === $this->directory || str_starts_with($output, $this->directory.'/')
            || str_starts_with($this->directory, $output.'/')) {
            throw new RuntimeException('Scope storage and output must be independent.');
        }
        $lock = fopen($this->directory, 'rb');
        if ($lock === false) {
            throw new RuntimeException('Cannot open technical scope lock.');
        }
        try {
            if (! flock($lock, LOCK_EX | LOCK_NB) || Files::writableRoot($this->directory) !== $identity
                || $this->status()['state'] !== 'AVAILABLE') {
                throw new RuntimeException('Technical scope unavailable or already consumed; no dispatch permitted.');
            }
            $reservation = ['scope' => self::definition(), 'attempt_id' => bin2hex(random_bytes(16)),
                'target_binding_sha256' => self::binding($target), 'output_root' => $output,
                'state' => 'RESERVED', 'budget_consumed' => true, 'reserved_at' => Contract::now(), 'record_origin' => 'V2_RESERVATION'];
            // Exclusive durable reservation is never released, including failure or process death.
            Contract::writeNew($this->directory, 'reservation.json', Files::json($reservation));

            return $reservation;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function dispatching(array $reservation): void
    {
        $this->assertReservation($reservation);
        Contract::writeNew($this->directory, 'dispatch.json', Files::json([
            'attempt_id' => $reservation['attempt_id'], 'state' => 'DISPATCHING', 'started_at' => Contract::now()]));
    }

    public function complete(array $reservation, array $transfer): void
    {
        $this->assertReservation($reservation);
        $dispatch = $this->read('dispatch.json');
        if (($dispatch['attempt_id'] ?? null) !== $reservation['attempt_id']) {
            throw new RuntimeException('Scope dispatch identity mismatch.');
        }
        $state = match (true) {
            $transfer['failure'] === 'TIMEOUT' => 'TIMEOUT',
            $transfer['failure'] !== null => 'TRANSPORT_FAILED',
            $transfer['http_status'] === 200 => 'HTTP_SUCCEEDED',
            default => 'HTTP_FAILED',
        };
        Contract::writeNew($this->directory, 'outcome.json', Files::json([
            'attempt_id' => $reservation['attempt_id'], 'state' => $state, 'budget_consumed' => true,
            'http_status' => $transfer['http_status'], 'failure' => $transfer['failure'], 'recorded_at' => Contract::now()]));
    }

    public function status(): array
    {
        try {
            if (! is_dir($this->directory) || is_link($this->directory)) {
                throw new RuntimeException('Technical scope not established.');
            }
            Files::writableRoot($this->directory);
            if ($this->read('scope.json') !== self::definition()) {
                throw new RuntimeException('Technical scope definition mismatch.');
            }
            $names = array_values(array_diff(scandir($this->directory), ['.', '..']));
            if (array_diff($names, ['scope.json', 'reservation.json', 'dispatch.json', 'outcome.json']) !== []) {
                throw new RuntimeException('Unrecognized scope evidence.');
            }
            if ($names === ['scope.json']) {
                return ['state' => 'AVAILABLE', 'budget_consumed' => false];
            }
            $reservation = $this->read('reservation.json');
            if (($reservation['scope'] ?? null) !== self::definition() || ($reservation['budget_consumed'] ?? null) !== true
                || ($reservation['state'] ?? null) !== 'RESERVED'
                || preg_match('/^[a-f0-9]{32}$/D', $reservation['attempt_id'] ?? '') !== 1
                || preg_match('/^[a-f0-9]{64}$/D', $reservation['target_binding_sha256'] ?? '') !== 1) {
                throw new RuntimeException('Unknown reservation state.');
            }
            $state = 'RESERVED';
            if (in_array('dispatch.json', $names, true)) {
                $dispatch = $this->read('dispatch.json');
                if (($dispatch['attempt_id'] ?? null) !== $reservation['attempt_id'] || ($dispatch['state'] ?? null) !== 'DISPATCHING') {
                    throw new RuntimeException('Unknown dispatch state.');
                }
                $state = 'INTERRUPTED_OR_UNKNOWN';
            }
            if (in_array('outcome.json', $names, true)) {
                $outcome = $this->read('outcome.json');
                if ($state !== 'INTERRUPTED_OR_UNKNOWN' || ($outcome['attempt_id'] ?? null) !== $reservation['attempt_id']
                    || ($outcome['budget_consumed'] ?? null) !== true
                    || ! in_array($outcome['state'] ?? null, ['HTTP_SUCCEEDED', 'HTTP_FAILED', 'TIMEOUT', 'TRANSPORT_FAILED'], true)) {
                    throw new RuntimeException('Unknown outcome state.');
                }
                $state = $outcome['state'];
                if (! array_key_exists('http_status', $outcome) || ! array_key_exists('failure', $outcome)
                    || ($outcome['http_status'] !== null && ! is_int($outcome['http_status']))
                    || $state !== match (true) {
                        $outcome['failure'] === 'TIMEOUT' => 'TIMEOUT',
                        $outcome['failure'] !== null => 'TRANSPORT_FAILED',
                        $outcome['http_status'] === 200 => 'HTTP_SUCCEEDED',
                        default => 'HTTP_FAILED',
                    }) {
                    throw new RuntimeException('Contradictory outcome diagnostics.');
                }
            }

            return ['state' => $state, 'budget_consumed' => true];
        } catch (Throwable) {
            return ['state' => 'UNKNOWN', 'budget_consumed' => true];
        }
    }

    private function assertReservation(array $reservation): void
    {
        Files::writableRoot($this->directory);
        if ($this->read('reservation.json') !== $reservation) {
            throw new RuntimeException('Scope reservation changed.');
        }
    }

    private function read(string $name): array
    {
        if (! is_file($this->directory.'/'.$name) || is_link($this->directory.'/'.$name)) {
            throw new RuntimeException('Scope evidence unavailable.');
        }

        return Files::object(Files::read($this->directory, $name));
    }
}
