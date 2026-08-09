<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Backtest\Services\Bt01BaselineService;
use Illuminate\Console\Command;
use Throwable;

class BuildBt01BaselineCommand extends Command
{
    protected $signature = 'keirin:backtest:bt01-baseline {--dry-run : Validate and calculate without writing backtest tables}';

    protected $description = 'Build the fixed-fold BT-01 STAT-01 baseline without exposing the 2026 final holdout.';

    public function handle(Bt01BaselineService $service): int
    {
        try {
            $summary = $service->build((bool) $this->option('dry-run'));
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }

        $this->info($summary->dryRun ? 'run=dry-run' : 'backtest_run_id='.$summary->runId);
        if ($summary->runUuid !== null) {
            $this->line('run_uuid='.$summary->runUuid);
        }
        $this->line("target_races={$summary->targetRaces} predicted_races={$summary->predictedRaces} excluded_races={$summary->excludedRaces} errors={$summary->errors}");

        return $summary->errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
