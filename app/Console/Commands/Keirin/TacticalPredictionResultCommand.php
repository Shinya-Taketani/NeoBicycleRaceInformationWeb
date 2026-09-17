<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\Contract;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\ResultService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class TacticalPredictionResultCommand extends Command
{
    protected $signature = 'keirin:backtest:tactical-prediction-result
        {--plan} {--execute} {--reproduce} {--mode=} {--evaluation-id=}
        {--requests=} {--labels=} {--labels-manifest=} {--output-root=}';

    protected $description = 'Match locked development predictions to saved results without inference or database access.';

    public function handle(): int
    {
        try {
            if (count(array_filter([$this->option('plan'), $this->option('execute'), $this->option('reproduce')])) !== 1) {
                throw new RuntimeException('Specify exactly one operation.');
            }
            if ($this->option('plan')) {
                $result = Contract::plan();
            } elseif ($this->option('reproduce')) {
                $result = app(ResultService::class)->reproduce($this->required('mode'), $this->required('output-root'), $this->required('evaluation-id'));
            } else {
                $result = app(ResultService::class)->execute($this->required('mode'), $this->required('evaluation-id'), $this->required('output-root'),
                    $this->required('requests'), $this->required('labels'), $this->required('labels-manifest'));
            }
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function required(string $name): string
    {
        $value = $this->option($name);
        if (! is_string($value) || $value === '') {
            throw new RuntimeException('Explicit --'.$name.' is required.');
        }

        return $value;
    }
}
