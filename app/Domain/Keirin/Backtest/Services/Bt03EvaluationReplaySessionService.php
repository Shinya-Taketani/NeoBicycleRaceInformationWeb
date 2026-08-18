<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Calculators\RaceClusterBootstrap;
use App\Domain\Keirin\Backtest\DTO\Bt03EvaluationReplaySelectionDto;
use App\Domain\Keirin\Backtest\DTO\Bt03EvaluationReplaySummaryDto;
use RuntimeException;
use Throwable;

class Bt03EvaluationReplaySessionService
{
    private bool $active = false;

    public function __construct(
        private readonly Bt03PreflightService $preflight,
        private readonly Bt03OutcomeSnapshotVerifier $snapshotVerifier,
        private readonly Bt02OutcomeContextSnapshotSession $snapshotSession,
        private readonly Bt03EvaluationReplayService $replay,
    ) {}

    public function replay(
        string $foldCode,
        string $statCode,
        string $cohortCode,
        int $iterations = RaceClusterBootstrap::ITERATIONS,
        int $seed = RaceClusterBootstrap::SEED,
        ?Bt03EvaluationReplaySelectionDto $selection = null,
    ): Bt03EvaluationReplaySummaryDto {
        return $this->withVerifiedSession(
            fn (Bt03EvaluationReplayService $replay): Bt03EvaluationReplaySummaryDto => $replay->replay(
                $foldCode,
                $statCode,
                $cohortCode,
                $iterations,
                $seed,
                $selection,
            ),
        );
    }

    /**
     * @template TResult
     *
     * @param  callable(Bt03EvaluationReplayService): TResult  $operation
     * @return TResult
     */
    public function withVerifiedSession(callable $operation): mixed
    {
        if ($this->active) {
            throw new RuntimeException('BT-03 verified replay session nesting was not allowed.');
        }

        $this->active = true;
        try {
            $preflight = $this->preflight->run();
            $snapshot = $this->snapshotVerifier->open($preflight->source->outcomeSnapshotPath);
            if (! hash_equals($preflight->outcomeSnapshotManifestHash, $snapshot->manifestHash())) {
                throw new RuntimeException('BT-03 verified outcome snapshot did not match the preflight result.');
            }

            $this->snapshotSession->activate($snapshot);
            try {
                $result = $operation($this->replay);
                try {
                    $this->preflight->run();
                } catch (Throwable $throwable) {
                    throw new RuntimeException(
                        'BT03_SOURCE_DRIFT_AFTER_REPLAY: '.$throwable->getMessage(),
                        previous: $throwable,
                    );
                }

                return $result;
            } finally {
                $this->snapshotSession->deactivate($snapshot->manifestHash());
            }
        } finally {
            $this->active = false;
        }
    }
}
