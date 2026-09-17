<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline\Contract;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline\Pipeline;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline\Request;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class TacticalPredictionPipelineCommand extends Command
{
    protected $signature = 'keirin:backtest:tactical-prediction-pipeline
        {--plan} {--execute} {--reproduce}
        {--mode=} {--race-id=} {--input-as-of=} {--artifact=} {--request-id=} {--output-root=}';

    protected $description = 'Build one development race input, predict with saved C1, and lock file artifacts.';

    public function handle(): int
    {
        try {
            if (count(array_filter([$this->option('plan'), $this->option('execute'), $this->option('reproduce')])) !== 1) {
                throw new RuntimeException('Specify exactly one of --plan, --execute, --reproduce.');
            }
            if ($this->option('plan')) {
                $result = Contract::plan();
            } else {
                $id = $this->required('request-id');
                $root = $this->required('output-root');
                if ($this->option('reproduce')) {
                    $result = app(Pipeline::class)->reproduce($root, $id);
                } else {
                    $race = $this->required('race-id');
                    if (! ctype_digit($race) || (int) $race < 1 || (string) (int) $race !== $race) {
                        throw new RuntimeException('Invalid race-id.');
                    }
                    $result = app(Pipeline::class)->execute(new Request($this->required('mode'), (int) $race,
                        $this->required('input-as-of'), $this->required('artifact'), $id, $root));
                }
            }
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function required(string $key): string
    {
        $value = $this->option($key);
        if (! is_string($value) || $value === '') {
            throw new RuntimeException('Explicit --'.$key.' is required.');
        }

        return $value;
    }
}
