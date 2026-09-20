<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Backtest\Experiments\GrowthTrendAdjustmentCalibration\Contract;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendAdjustmentCalibration\Service;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class GrowthTrendAdjustmentCalibrationCommand extends Command
{
    protected $signature = 'keirin:backtest:growth-trend-adjustment-calibration {--plan} {--execute} {--reproduce}
        {--outer-root=} {--trend-bundle=} {--output-root=} {--analysis-id=}';

    protected $description = 'Calibrate the fixed meeting-delta growth signal; no training or database.';

    public function handle(): int
    {
        try {
            if (count(array_filter([$this->option('plan'), $this->option('execute'), $this->option('reproduce')])) !== 1) {
                throw new RuntimeException('Specify exactly one operation.');
            }
            $result = $this->option('plan') ? Contract::plan() : ($this->option('execute')
                ? app(Service::class)->execute($this->required('output-root'), $this->required('analysis-id'), $this->required('outer-root'), $this->required('trend-bundle'))
                : app(Service::class)->reproduce($this->required('output-root'), $this->required('analysis-id')));
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
            throw new RuntimeException('Explicit --'.$name.' required.');
        }

        return $value;
    }
}
