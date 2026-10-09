<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Prediction;
use Illuminate\Console\Command;
use Throwable;

final class PredictC1Stat35CompositionCommand extends Command
{
    protected $signature = 'keirin:c1:stat35-composition-predict {--artifact=} {--input=} {--output=}';

    protected $description = 'Predict from a portable composition package and outcome-free feature input.';

    public function handle(Prediction $prediction): int
    {
        try {
            FinalC1Stat35CompositionCommand::denyExternalAccess();
            $result = $prediction->run((string) $this->option('artifact'), (string) $this->option('input'), (string) $this->option('output'));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e::class.': '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
