<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Backtest\Experiments\TacticalGradeAnalysis\AnalysisService;
use App\Domain\Keirin\Backtest\Experiments\TacticalGradeAnalysis\Contract;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class TacticalGradeAnalysisCommand extends Command
{
    protected $signature = 'keirin:backtest:tactical-grade-analysis {--plan} {--execute} {--reproduce}
        {--source-root=} {--output-root=} {--analysis-id=}';

    protected $description = 'Analyze saved 2024/2025 Outer C1 positions by historical race-entry grade without inference.';

    public function handle(): int
    {
        try {
            if (count(array_filter([$this->option('plan'), $this->option('execute'), $this->option('reproduce')])) !== 1) {
                throw new RuntimeException('Specify exactly one operation.');
            }
            $result = $this->option('plan') ? Contract::plan() : ($this->option('execute')
                ? app(AnalysisService::class)->execute($this->required('output-root'), $this->required('analysis-id'), $this->required('source-root'))
                : app(AnalysisService::class)->reproduce($this->required('output-root'), $this->required('analysis-id')));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));

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
