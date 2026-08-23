<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use RuntimeException;

final class Bt03e02SourceIntegrityGuard
{
    /** @param array<string, mixed> $start @param array<string, mixed> $end */
    public function assertUnchanged(array $start, array $end, string $source): void
    {
        if ($start !== $end) {
            throw new RuntimeException("BT-03E-02 {$source} drifted.");
        }
    }
}
