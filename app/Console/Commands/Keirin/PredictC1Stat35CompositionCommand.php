<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Prediction;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class PredictC1Stat35CompositionCommand extends Command
{
    protected $signature = 'keirin:c1:stat35-composition-predict {--artifact=} {--input=} {--output-dir=} {--output=}';

    protected $description = 'Predict from a portable composition package and outcome-free feature input.';

    public function handle(Prediction $prediction): int
    {
        try {
            if ($this->option('output') !== null || ! is_string($this->option('output-dir')) || $this->option('output-dir') === '') {
                throw new RuntimeException('Use --output-dir=<new bundle directory>; legacy --output=<file> is no longer supported (do not specify both).');
            }
            FinalC1Stat35CompositionCommand::denyExternalAccess();
            $result = $prediction->run((string) $this->option('artifact'), (string) $this->option('input'), (string) $this->option('output-dir'));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e::class.': '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
