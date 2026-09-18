<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysisV2\Contract;
use App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysisV2\Service;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class GrowthPointAnalysisV2Command extends Command
{
    protected $signature = 'keirin:backtest:growth-point-analysis-v2 {--plan} {--execute} {--reproduce}
        {--source-v1=} {--output-root=} {--analysis-id=}';

    protected $description = 'Sign-preserving growth diagnostics from sealed v1 snapshots; no database or model inference.';

    public function handle(): int
    {
        try {
            if (count(array_filter([$this->option('plan'), $this->option('execute'), $this->option('reproduce')])) !== 1) {
                throw new RuntimeException('Specify exactly one operation.');
            }
            $result = $this->option('plan') ? Contract::plan() : ($this->option('execute')
                ? app(Service::class)->execute($this->required('output-root'), $this->required('analysis-id'), $this->required('source-v1'))
                : app(Service::class)->reproduce($this->required('output-root'), $this->required('analysis-id')));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));

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
