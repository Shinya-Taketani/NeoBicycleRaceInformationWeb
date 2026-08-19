<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\DTO\Bt03PreflightSummaryDto;
use App\Domain\Keirin\Backtest\DTO\Bt03ProductionPlanDto;
use App\Domain\Keirin\Backtest\DTO\Bt03ProductionProgressDto;
use App\Domain\Keirin\Backtest\DTO\Bt03ProductionSummaryDto;
use App\Domain\Keirin\Backtest\Repositories\Bt03BinEffectAuditRepository;
use App\Models\BacktestRun;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class Bt03BinEffectProductionService
{
    public function __construct(
        private readonly Bt03ProductionContract $contract,
        private readonly Bt03ProductionSchemaService $schema,
        private readonly Bt03ProductionAdvisoryLock $lock,
        private readonly Bt03PreflightService $preflight,
        private readonly Bt03EvaluationReplaySessionService $session,
        private readonly Bt03ReplaySummaryValidator $validator,
        private readonly Bt03BinEffectAuditRepository $audit,
    ) {}

    public function plan(): Bt03ProductionPlanDto
    {
        $preflight = $this->preflight->run();

        return new Bt03ProductionPlanDto(
            $preflight->source->sourceRunId,
            count(Bt03ProductionContract::FOLDS),
            count(Bt03SourceManifest::ENTRY_STAT_CODES),
            count(Bt03ProductionContract::COHORTS),
            count($this->contract->scopes()),
            $preflight->source->effectBinCount,
            $preflight->source->effectBinCount * Bt03ProductionContract::LABEL_COUNT,
            Bt03ProductionContract::BOOTSTRAP_ITERATIONS,
            Bt03ProductionContract::BOOTSTRAP_SEED,
            $this->schema->readiness(),
        );
    }

    /** @param callable(Bt03ProductionProgressDto): void|null $progress */
    public function execute(?int $resumeRunId = null, ?callable $progress = null): Bt03ProductionSummaryDto
    {
        $this->schema->assertReady();
        $this->lock->acquire();
        $run = null;
        $result = null;
        $primaryFailure = null;
        $skippedScopeCount = 0;
        try {
            if ($resumeRunId !== null) {
                $this->audit->assertResumeAllowed($resumeRunId);
            }
            $this->session->withVerifiedSession(function (
                Bt03EvaluationReplayService $replay,
                Bt03PreflightSummaryDto $preflight,
            ) use ($resumeRunId, $progress, &$run, &$skippedScopeCount): void {
                $run = $resumeRunId === null
                    ? $this->audit->createRun($preflight)
                    : $this->audit->resumeRun($resumeRunId, $preflight);
                $scopes = $this->audit->scopes($run);
                $definitions = $this->contract->scopes();
                foreach ($scopes as $index => $scope) {
                    $definition = $definitions[$index];
                    if ($scope->status === 'SUCCEEDED') {
                        $effectCount = $this->audit->verifySucceededScope($scope);
                        $skippedScopeCount++;
                        $this->report($progress, new Bt03ProductionProgressDto(
                            $definition->ordinal,
                            Bt03ProductionContract::SCOPE_COUNT,
                            $definition->foldCode,
                            $definition->statCode,
                            $definition->cohortCode,
                            'SKIPPED_SUCCEEDED',
                            $effectCount,
                            (int) $scope->evaluation_row_count,
                            (int) $scope->evaluation_race_count,
                            (int) $scope->unseen_row_count,
                            0.0,
                        ));

                        continue;
                    }

                    $scope = $this->audit->startScope($scope);
                    $started = hrtime(true);
                    try {
                        $summary = $replay->replay(
                            $definition->foldCode,
                            $definition->statCode,
                            $definition->cohortCode,
                            Bt03ProductionContract::BOOTSTRAP_ITERATIONS,
                            Bt03ProductionContract::BOOTSTRAP_SEED,
                            null,
                        );
                        $this->validator->validate($scope, $definition, $summary);
                        $this->audit->persistScope($scope, $definition->foldCode, $definition->statCode, $summary);
                    } catch (Throwable $failure) {
                        try {
                            $this->audit->failScope($scope, $failure);
                        } catch (Throwable $auditFailure) {
                            $this->logSecondaryFailure('BT-03 Production scope failure audit also failed.', [
                                'scope_id' => $scope->id,
                                'primary' => $failure->getMessage(),
                                'audit' => $auditFailure->getMessage(),
                            ]);
                        }
                        throw $failure;
                    }
                    $this->report($progress, new Bt03ProductionProgressDto(
                        $definition->ordinal,
                        Bt03ProductionContract::SCOPE_COUNT,
                        $definition->foldCode,
                        $definition->statCode,
                        $definition->cohortCode,
                        'SUCCEEDED',
                        count($summary->effects),
                        $summary->evaluationRowCount,
                        $summary->evaluationRaceCount,
                        $summary->unseenRowCount,
                        (hrtime(true) - $started) / 1_000_000_000,
                    ));
                }
            });
            if (! $run instanceof BacktestRun) {
                throw new RuntimeException('BT-03 Production verified session did not create or resume a run.');
            }
            $result = $this->audit->finalizeSuccess($run, $skippedScopeCount);
        } catch (Throwable $failure) {
            $primaryFailure = $failure;
            if ($run instanceof BacktestRun) {
                [$resumeAllowed, $blockReason, $diagnosticFailure] = $this->diagnoseFailure($failure);
                try {
                    $this->audit->markRunFailure($run, $failure, $resumeAllowed, $blockReason, $diagnosticFailure);
                } catch (Throwable $auditFailure) {
                    $this->logSecondaryFailure('BT-03 Production run failure audit also failed.', [
                        'run_id' => $run->id,
                        'primary' => $failure->getMessage(),
                        'audit' => $auditFailure->getMessage(),
                    ]);
                }
            }
        }

        try {
            $this->lock->release();
        } catch (Throwable $lockFailure) {
            if ($primaryFailure !== null) {
                $this->logSecondaryFailure('BT-03 Production lock release also failed.', [
                    'primary' => $primaryFailure->getMessage(),
                    'lock' => $lockFailure->getMessage(),
                ]);
            } else {
                throw $lockFailure;
            }
        }
        if ($primaryFailure !== null) {
            throw $primaryFailure;
        }

        return $result ?? throw new RuntimeException('BT-03 Production completed without a summary.');
    }

    /** @return array{bool, ?string, ?Throwable} */
    private function diagnoseFailure(Throwable $primary): array
    {
        if (str_starts_with($primary->getMessage(), 'BT03_SOURCE_DRIFT_AFTER_REPLAY:')) {
            return [false, 'SOURCE_DRIFT_AFTER_REPLAY', $primary->getPrevious()];
        }

        try {
            $this->preflight->run();

            return [true, null, null];
        } catch (Throwable $diagnosticFailure) {
            return [false, 'SOURCE_PREFLIGHT_FAILED_AFTER_EXECUTION_FAILURE', $diagnosticFailure];
        }
    }

    /** @param callable(Bt03ProductionProgressDto): void|null $progress */
    private function report(?callable $progress, Bt03ProductionProgressDto $update): void
    {
        if ($progress !== null) {
            $progress($update);
        }
    }

    /** @param array<string, mixed> $context */
    private function logSecondaryFailure(string $message, array $context): void
    {
        try {
            Log::error($message, $context);
        } catch (Throwable) {
            // A secondary logging failure must never replace the Production failure.
        }
    }
}
