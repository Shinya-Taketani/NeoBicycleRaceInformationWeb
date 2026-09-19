<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Backtest\Experiments\GrowthAdjustmentCalibration\Contract;
use App\Domain\Keirin\Backtest\Experiments\GrowthAdjustmentCalibration\Service;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class GrowthAdjustmentCalibrationCommand extends Command
{
    protected $signature = 'keirin:backtest:growth-adjustment-calibration {--plan} {--execute} {--reproduce}
        {--outer-root=} {--growth-bundle=} {--output-root=} {--analysis-id=}';

    protected $description = 'Calibrate a fixed SCORE growth utility adjustment against immutable Outer C1; no fitting or database.';

    public function handle(): int
    {
        try {
            if (count(array_filter([$this->option('plan'), $this->option('execute'), $this->option('reproduce')])) !== 1) {
                throw new RuntimeException('Specify exactly one operation.');
            }
            $result = $this->option('plan') ? Contract::plan() : ($this->option('execute')
                ? app(Service::class)->execute($this->required('output-root'), $this->required('analysis-id'), $this->required('outer-root'), $this->required('growth-bundle'))
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
            throw new RuntimeException('Explicit --'.$key.' required.');
        }

        return $value;
    }
}
