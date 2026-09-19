<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Backtest\Experiments\GrowthTrendAnalysis\Contract;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendAnalysis\Service;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class GrowthTrendAnalysisCommand extends Command
{
    protected $signature = 'keirin:backtest:growth-trend-analysis {--plan} {--execute} {--reproduce} {--score-source=} {--outer-root=} {--meeting-bundle=} {--output-root=} {--analysis-id=}';

    protected $description = 'Analyze sealed score-observation trends without database access or model fitting.';

    public function handle(): int
    {
        try {
            if (count(array_filter([$this->option('plan'), $this->option('execute'), $this->option('reproduce')])) !== 1) {
                throw new RuntimeException('Exactly one operation is required.');
            }
            $result = $this->option('plan') ? Contract::plan() : ($this->option('execute') ? app(Service::class)->execute($this->required('output-root'), $this->required('analysis-id'), $this->required('score-source'), $this->required('outer-root'), $this->required('meeting-bundle'))
                : app(Service::class)->reproduce($this->required('output-root'), $this->required('analysis-id')));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

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
            throw new RuntimeException('Explicit --'.$key.' required.');
        }

        return $value;
    }
}
