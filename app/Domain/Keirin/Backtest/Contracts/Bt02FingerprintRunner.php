<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Contracts;

use App\Domain\Keirin\Backtest\Enums\Bt02FingerprintType;

interface Bt02FingerprintRunner
{
    public function assertVersionContract(): void;

    public function fingerprint(int $runId, Bt02FingerprintType $type): string;
}
