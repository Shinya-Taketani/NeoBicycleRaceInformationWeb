<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthTrendAdjustmentCalibration;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;

class Comparison
{
    protected function path(): string
    {
        return '/home/shinya/neo-keirin-artifacts/growth-adjustment-calibration-01-20260919-01/evaluations/outer-c1-score-growth-calibration-2024-2025-review-fix-01';
    }

    public function read(TemporalAccess $access, array $selected, array $transfer): array
    {
        $access->authorize(2025, 'OLD_CALIBRATION_DIAGNOSTIC_OPEN');
        $root = $this->path();
        $lock = Files::identity($root.'/LOCKED.json');
        Files::verify($root.'/manifest.json', Files::json($root.'/LOCKED.json'));
        $manifest = Files::json($root.'/manifest.json');
        $files = [$root.'/LOCKED.json' => $lock, $root.'/manifest.json' => Files::identity($root.'/manifest.json')];
        $rows = [];
        foreach (['selection.json', 'validation-2025.json', 'coefficient-curve-2024.json'] as $name) {
            $files[$root.'/'.$name] = $manifest['files'][$name];
            Files::verify($root.'/'.$name, $files[$root.'/'.$name]);
            $rows[$name] = Files::json($root.'/'.$name);
        }
        $old = $rows['selection.json']['selected'];

        return ['purpose' => 'HISTORICAL_REFERENCE_ONLY_DIFFERENT_SIGNAL_SCALES', 'files' => $files,
            'old' => ['signal' => 'SCORE_POINT_V2', 'selected' => $old,
                '2024' => $rows['coefficient-curve-2024.json']['candidates'][$old['k'] + 50], '2025' => $rows['validation-2025.json']],
            'current' => ['signal' => Contract::SIGNAL, 'selected' => $selected, '2025' => $transfer]];
    }
}
