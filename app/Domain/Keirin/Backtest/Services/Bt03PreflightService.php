<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\DTO\Bt03PreflightSummaryDto;

class Bt03PreflightService
{
    public function __construct(
        private readonly Bt03SourceManifest $manifest,
        private readonly Bt03SourceVerifier $sourceVerifier,
        private readonly Bt03OutcomeSnapshotVerifier $snapshotVerifier,
        private readonly Bt02BaselineFingerprintPreflightService $baselinePreflight,
        private readonly Bt02FingerprintPreflightService $bt02Preflight,
    ) {}

    public function run(): Bt03PreflightSummaryDto
    {
        $source = $this->sourceVerifier->verify();
        $snapshotHash = $this->snapshotVerifier->verify($source->outcomeSnapshotPath);
        $this->baselinePreflight->run();
        $bt02 = $this->bt02Preflight->run();

        return new Bt03PreflightSummaryDto(
            $source,
            $snapshotHash,
            4,
            $bt02,
            $this->manifest::HASH,
        );
    }
}
