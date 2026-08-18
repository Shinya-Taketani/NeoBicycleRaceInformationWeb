<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Calculators\RaceClusterBootstrap;
use App\Domain\Keirin\Backtest\DTO\Bt03EvaluationReplaySelectionDto;
use App\Domain\Keirin\Backtest\DTO\Bt03EvaluationReplaySummaryDto;

class Bt03EvaluationReplaySessionService
{
    public function __construct(
        private readonly Bt03OutcomeSnapshotVerifier $snapshotVerifier,
        private readonly Bt02OutcomeContextSnapshotSession $snapshotSession,
        private readonly Bt03EvaluationReplayService $replay,
    ) {}

    public function replay(
        string $outcomeSnapshotAuditPath,
        string $foldCode,
        string $statCode,
        string $cohortCode,
        int $iterations = RaceClusterBootstrap::ITERATIONS,
        int $seed = RaceClusterBootstrap::SEED,
        ?Bt03EvaluationReplaySelectionDto $selection = null,
    ): Bt03EvaluationReplaySummaryDto {
        $snapshot = $this->snapshotVerifier->open($outcomeSnapshotAuditPath);
        $this->snapshotSession->activate($snapshot);
        try {
            return $this->replay->replay($foldCode, $statCode, $cohortCode, $iterations, $seed, $selection);
        } finally {
            $this->snapshotSession->deactivate($snapshot->manifestHash());
        }
    }
}
