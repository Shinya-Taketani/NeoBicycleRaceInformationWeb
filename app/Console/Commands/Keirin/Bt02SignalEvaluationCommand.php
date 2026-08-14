<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Backtest\Services\Bt02SignalEvaluationService;
use Illuminate\Console\Command;
use Throwable;

class Bt02SignalEvaluationCommand extends Command
{
    protected $signature = 'keirin:backtest:bt02-evaluate-signals
        {--execute : Confirm the fixed 2022-2025 Outcome evaluation execution}';

    protected $description = 'Execute the fixed BT-02 walk-forward paired signal evaluation.';

    public function handle(Bt02SignalEvaluationService $service): int
    {
        if (! $this->option('execute')) {
            $this->error('BT-02 Outcome evaluation requires the explicit --execute confirmation flag.');

            return self::FAILURE;
        }

        try {
            $summary = $service->execute();
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }

        $this->info('BT-02 SIGNAL EVALUATION SUCCEEDED');
        $this->line("run_id={$summary->runId}");
        $this->line("run_uuid={$summary->runUuid}");
        $this->line("folds={$summary->foldCount}");
        $this->line("entry_signals={$summary->signalCount}");
        $this->line("models={$summary->modelCount}");
        $this->line("metrics={$summary->metricCount}");

        return self::SUCCESS;
    }
}
