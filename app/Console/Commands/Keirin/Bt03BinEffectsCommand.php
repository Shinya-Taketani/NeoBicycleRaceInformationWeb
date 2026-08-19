<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Backtest\DTO\Bt03ProductionProgressDto;
use App\Domain\Keirin\Backtest\Services\Bt03BinEffectProductionService;
use Illuminate\Console\Command;
use Throwable;

class Bt03BinEffectsCommand extends Command
{
    protected $signature = 'keirin:backtest:bt03-bin-effects
        {--execute : Execute the fixed 72-scope BT-03 Production persistence workflow}
        {--resume-run-id= : Resume an eligible incomplete BT-03 Production run}';

    protected $description = 'Plan or execute the fixed BT-03 bin-effect Production workflow.';

    public function handle(Bt03BinEffectProductionService $service): int
    {
        $execute = (bool) $this->option('execute');
        try {
            $resume = $this->resumeRunId();
        } catch (Throwable $failure) {
            $this->error($failure->getMessage());

            return self::FAILURE;
        }
        if (! $execute && $resume !== null) {
            $this->error('--resume-run-id requires --execute.');

            return self::FAILURE;
        }

        try {
            if (! $execute) {
                $plan = $service->plan();
                $this->info('BT-03 BIN EFFECT PLAN PASS');
                $this->line("source_run_id={$plan->sourceRunId}");
                $this->line("folds={$plan->foldCount}");
                $this->line("stats={$plan->statCount}");
                $this->line("cohorts={$plan->cohortCount}");
                $this->line("scopes={$plan->scopeCount}");
                $this->line("source_bins={$plan->sourceBinCount}");
                $this->line("base_effect_rows={$plan->baseEffectCount}");
                $this->line("bootstrap_iterations={$plan->bootstrapIterations}");
                $this->line("bootstrap_seed={$plan->bootstrapSeed}");
                $this->line('2026=BLOCKED');
                foreach ($plan->schemaReadiness as $table => $ready) {
                    $this->line("schema.{$table}=".($ready ? 'READY' : 'PENDING_MIGRATION'));
                }

                return self::SUCCESS;
            }

            $summary = $service->execute($resume, function (Bt03ProductionProgressDto $progress): void {
                $this->line(sprintf(
                    'scope=%d/%d fold=%s stat=%s cohort=%s status=%s effects=%d rows=%d races=%d unseen=%d elapsed=%.3fs',
                    $progress->ordinal,
                    $progress->scopeCount,
                    $progress->foldCode,
                    $progress->statCode,
                    $progress->cohortCode,
                    $progress->status,
                    $progress->effectCount,
                    $progress->evaluationRowCount,
                    $progress->evaluationRaceCount,
                    $progress->unseenRowCount,
                    $progress->elapsedSeconds,
                ));
            });
        } catch (Throwable $failure) {
            $this->error($failure->getMessage());

            return self::FAILURE;
        }

        $this->info('BT-03 BIN EFFECT PRODUCTION SUCCEEDED');
        $this->line("run_id={$summary->runId}");
        $this->line("run_uuid={$summary->runUuid}");
        $this->line("scopes={$summary->completedScopeCount}/{$summary->scopeCount}");
        $this->line("effects={$summary->effectCount}");
        $this->line("unseen_scopes={$summary->unseenScopeCount}");
        $this->line("effect_manifest_hash={$summary->effectManifestHash}");

        return self::SUCCESS;
    }

    private function resumeRunId(): ?int
    {
        $value = $this->option('resume-run-id');
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || preg_match('/\A[1-9]\d*\z/', $value) !== 1) {
            throw new \InvalidArgumentException('--resume-run-id must be a positive integer.');
        }

        return (int) $value;
    }
}
