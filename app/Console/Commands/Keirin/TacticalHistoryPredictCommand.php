<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\PredictionService;
use Illuminate\Console\Command;
use Throwable;

final class TacticalHistoryPredictCommand extends Command
{
    protected $signature = 'keirin:backtest:tactical-history-predict
        {--artifact= : Saved final artifact.json}
        {--input= : Sealed outcome-free development JSONL, 2022 through 2025 only}
        {--output= : New prediction JSONL path}';

    protected $description = 'Restore a sealed C1 model and predict without fitting or outcome access.';

    public function handle(PredictionService $service): int
    {
        foreach (['artifact', 'input', 'output'] as $key) {
            if (! is_string($this->option($key)) || $this->option($key) === '') {
                $this->error('Explicit --'.$key.' is required.');

                return self::FAILURE;
            }
        }
        try {
            $this->line(json_encode($service->run($this->option('artifact'), $this->option('input'), $this->option('output')), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
