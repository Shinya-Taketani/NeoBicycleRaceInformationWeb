<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Contracts\Bt02OutcomeContextSnapshot;
use RuntimeException;

class Bt02OutcomeContextSnapshotSession
{
    private ?Bt02OutcomeContextSnapshot $snapshot = null;

    public function activate(Bt02OutcomeContextSnapshot $snapshot): void
    {
        if ($this->snapshot !== null && $this->snapshot->manifestHash() !== $snapshot->manifestHash()) {
            throw new RuntimeException('BT-02 outcome snapshot session was already bound to another manifest.');
        }

        $this->snapshot = $snapshot;
    }

    public function snapshot(): Bt02OutcomeContextSnapshot
    {
        return $this->snapshot ?? throw new RuntimeException('BT-02 outcome snapshot was not activated.');
    }

    public function deactivate(string $manifestHash): void
    {
        if ($this->snapshot === null || ! hash_equals($this->snapshot->manifestHash(), $manifestHash)) {
            throw new RuntimeException('BT-02 outcome snapshot session release did not match the active manifest.');
        }

        $this->snapshot = null;
    }
}
