<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture\Observation;

use App\Domain\Keirin\Scraping\PrestartCapture\Operational\Contract;
use App\Domain\Keirin\Scraping\PrestartEvidence\EvidenceFiles;
use RuntimeException;

/** One authorization directory, independent of output run and manifest revision; no deletion or reset API. */
final class AttemptLedger
{
    private mixed $lock = null;

    private array $events = [];

    private string $directory;

    private string $prefix;

    public function __construct(private readonly ObservationManifest $manifest, private readonly string $registry = ObservationContract::LEDGER_ROOT)
    {
        EvidenceFiles::writableRoot($registry);
        $this->prefix = hash('sha256', $manifest->data['authorization_id'].'|'.$manifest->data['approval_scope']);
        $this->directory = $registry.'/'.$this->prefix;
    }

    public function exclusive(callable $action): mixed
    {
        if (! is_dir($this->directory)) {
            throw new RuntimeException('Authorization ledger not provisioned; unknown is not unused budget.');
        }
        EvidenceFiles::writableRoot($this->directory);
        if (EvidenceFiles::object(EvidenceFiles::read($this->directory, 'scope.json')) !== $this->header()) {
            throw new RuntimeException('Authorization manifest changed; budget cannot be reset by revision.');
        }
        $handle = fopen($this->directory, 'r');
        if ($handle === false || ! flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('Authorization already active.');
        }
        $this->lock = $handle;
        try {
            $this->events = [];
            $names = [];
            foreach (new \DirectoryIterator($this->directory) as $file) {
                if ($file->isDot() || $file->getFilename() === 'scope.json') {
                    continue;
                }
                $names[] = $file->getFilename();
                if (count($names) > 32) {
                    throw new RuntimeException('Ledger exceeds bounded scope.');
                }
            }
            sort($names);
            foreach ($names as $index => $name) {
                if ($name !== sprintf('event-%03d.json', $index + 1)) {
                    throw new RuntimeException('Ledger gap or unexpected file.');
                }
                $this->events[] = EvidenceFiles::object(EvidenceFiles::read($this->directory, $name));
            }
            // Independent append witnesses detect a removed complete suffix, not just gaps inside the chain.
            for ($sequence = 1; $sequence <= 32; $sequence++) {
                $name = $this->witnessName($sequence);
                $exists = file_exists($this->registry.'/'.$name) || is_link($this->registry.'/'.$name);
                $event = $this->events[$sequence - 1] ?? null;
                if (($event === null) !== ! $exists || ($exists
                    && EvidenceFiles::object(EvidenceFiles::read($this->registry, $name)) !== $this->witness($event))) {
                    throw new RuntimeException('Ledger witness missing, interrupted or truncated; budget unknown.');
                }
            }
            self::validate($this->snapshot());

            return $action($this);
        } finally {
            $this->lock = null;
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Test-only provisioning is deliberately separate from execute/restart; real provisioning is not authorized. */
    public static function initializeForSimulation(ObservationManifest $manifest, string $registry): void
    {
        $ledger = new self($manifest, $registry);
        if ($registry === ObservationContract::LEDGER_ROOT || str_starts_with($registry, ObservationContract::LEDGER_ROOT.'/')
            || file_exists($ledger->directory) || is_link($ledger->directory)) {
            throw new RuntimeException('Cannot initialize or reset an existing/operational authorization.');
        }
        for ($sequence = 1; $sequence <= 32; $sequence++) {
            if (file_exists($registry.'/'.$ledger->witnessName($sequence)) || is_link($registry.'/'.$ledger->witnessName($sequence))) {
                throw new RuntimeException('Existing authorization witness forbids initialization.');
            }
        }
        if (! mkdir($ledger->directory, 0700)) {
            throw new RuntimeException('Exclusive synthetic provisioning failed.');
        }
        ObservationContract::write($ledger->directory, 'scope.json', $ledger->header());
    }

    public function reserve(int $race, int $slot, string $at): array
    {
        $this->requireLock();
        (new SlotPlanner)->requireOpen($this->manifest, $race, $slot, $at);
        $reservations = $states = [];
        foreach ($this->events as $event) {
            if ($event['kind'] === 'STOP') {
                throw new RuntimeException('Whole authorization is STOPPED.');
            }
            $id = $event['attempt_id'];
            $states[$id] = $event['kind'];
            if ($event['kind'] === 'RESERVED') {
                $reservations[] = $event;
                if (($event['race_id'] === $race && $event['slot_id'] === $slot)
                    || Contract::instant($at) - Contract::instant($event['at']) < 60000000) {
                    throw new RuntimeException('Duplicate slot, future history or cross-race interval violation.');
                }
            }
            if ($event['kind'] === 'OUTCOME' && $event['state'] !== 'SUCCEEDED') {
                throw new RuntimeException('Failed or uncertain attempt consumes budget and stops scope.');
            }
        }
        if (count($reservations) >= 4 || count(array_filter($states, fn ($state) => $state !== 'OUTCOME')) > 0) {
            throw new RuntimeException('Budget exhausted or interrupted reservation; no automatic restart.');
        }
        $event = ['kind' => 'RESERVED', 'attempt_id' => bin2hex(random_bytes(16)), 'race_id' => $race,
            'slot_id' => $slot, 'at' => $at, 'state' => 'RESERVED', 'budget_spent' => true,
            'manifest_revision' => $this->manifest->data['revision']];
        $this->append($event);

        return $event;
    }

    public function dispatch(array $reservation, string $at): void
    {
        $this->append(['kind' => 'DISPATCH', 'attempt_id' => $reservation['attempt_id'], 'at' => $at]);
    }

    public function outcome(array $reservation, string $at, string $state, ?int $http, ?string $rawSha): void
    {
        $this->append(['kind' => 'OUTCOME', 'attempt_id' => $reservation['attempt_id'], 'at' => $at,
            'state' => $state, 'http_status' => $http, 'raw_sha256' => $rawSha, 'budget_spent' => true]);
    }

    public function stop(string $reason, string $at): void
    {
        $this->append(['kind' => 'STOP', 'at' => $at, 'reason' => $reason]);
    }

    public function snapshot(): array
    {
        return ['header' => $this->header(), 'events' => $this->events];
    }

    public function requireManifest(ObservationManifest $manifest): void
    {
        if ($manifest->digest() !== $this->manifest->digest()) {
            throw new RuntimeException('Ledger authorization differs from capture manifest.');
        }
    }

    public static function validate(array $snapshot): void
    {
        $previous = null;
        $states = $slots = $reservations = [];
        $stopped = false;
        $lastTime = null;
        if (($snapshot['header']['version'] ?? null) !== ObservationContract::VERSION || count($snapshot['events'] ?? []) > 32) {
            throw new RuntimeException('Unknown ledger header.');
        }
        foreach ($snapshot['events'] as $index => $event) {
            $at = Contract::instant($event['at'] ?? null);
            $sha = $event['sha256'] ?? null;
            $payload = $event;
            unset($payload['sha256']);
            if ($stopped || $at === null || ($lastTime !== null && $at < $lastTime)
                || ($event['sequence'] ?? null) !== $index + 1 || ($event['previous_sha256'] ?? null) !== $previous
                || $sha !== Contract::digest($payload)) {
                throw new RuntimeException('Ledger chain, time or STOP violation.');
            }
            $lastTime = $at;
            $previous = $sha;
            $id = $event['attempt_id'] ?? '';
            if ($event['kind'] === 'RESERVED') {
                $key = $event['race_id'].':'.$event['slot_id'];
                if (isset($states[$id]) || isset($slots[$key]) || count($reservations) >= 4
                    || ($event['budget_spent'] ?? null) !== true || ! in_array($event['slot_id'], [1, 2], true)
                    || ($reservations !== [] && $at - end($reservations) < 60000000)) {
                    throw new RuntimeException('Ledger reservation conflict.');
                }
                $slots[$key] = true;
                $reservations[] = $at;
            } elseif ($event['kind'] === 'DISPATCH') {
                if (($states[$id] ?? null) !== 'RESERVED') {
                    throw new RuntimeException('Dispatch without reservation.');
                }
            } elseif ($event['kind'] === 'OUTCOME') {
                if (($states[$id] ?? null) !== 'DISPATCH' || ! in_array($event['state'], ['SUCCEEDED', 'FAILED', 'TIMEOUT', 'INTERRUPTED', 'UNKNOWN'], true)) {
                    throw new RuntimeException('Outcome without dispatch or known state.');
                }
            } elseif ($event['kind'] === 'STOP') {
                $stopped = true;
            } else {
                throw new RuntimeException('Unknown ledger event.');
            }
            $states[$id] = $event['kind'];
        }
    }

    private function header(): array
    {
        return ['version' => ObservationContract::VERSION, 'authorization_id' => $this->manifest->data['authorization_id'],
            'approval_scope' => $this->manifest->data['approval_scope'], 'manifest_sha256' => $this->manifest->digest()];
    }

    private function append(array $event): void
    {
        $this->requireLock();
        $event = [...$event, 'sequence' => count($this->events) + 1,
            'previous_sha256' => $this->events === [] ? null : end($this->events)['sha256']];
        $event['sha256'] = Contract::digest($event);
        self::validate(['header' => $this->header(), 'events' => [...$this->events, $event]]);
        ObservationContract::write($this->registry, $this->witnessName($event['sequence']), $this->witness($event));
        ObservationContract::write($this->directory, sprintf('event-%03d.json', $event['sequence']), $event);
        $this->events[] = $event;
    }

    private function witnessName(int $sequence): string
    {
        return $this->prefix.sprintf('-witness-%03d.json', $sequence);
    }

    private function witness(array $event): array
    {
        return ['manifest_sha256' => $this->manifest->digest(), 'sequence' => $event['sequence'], 'event_sha256' => $event['sha256']];
    }

    private function requireLock(): void
    {
        if (! is_resource($this->lock)) {
            throw new RuntimeException('Exclusive authorization lock required.');
        }
    }
}
