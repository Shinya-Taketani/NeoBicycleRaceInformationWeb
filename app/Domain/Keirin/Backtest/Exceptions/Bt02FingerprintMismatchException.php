<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Exceptions;

use RuntimeException;

class Bt02FingerprintMismatchException extends RuntimeException
{
    public function __construct(
        public readonly int $year,
        public readonly string $statCode,
        public readonly int $runId,
        public readonly string $fingerprintType,
        public readonly string $expected,
        public readonly string $actual,
    ) {
        parent::__construct(
            "BT-02 fingerprint mismatch: year={$year} stat={$statCode} run_id={$runId} type={$fingerprintType} expected={$expected} actual={$actual}",
        );
    }
}
