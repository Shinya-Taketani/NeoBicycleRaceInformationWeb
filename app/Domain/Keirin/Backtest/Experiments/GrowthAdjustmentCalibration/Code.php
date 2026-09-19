<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthAdjustmentCalibration;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;

class Code
{
    public function capture(): array
    {
        $paths = glob(__DIR__.'/*.php');
        foreach (['TacticalHistory', 'TacticalHistoryFinal', 'GrowthPointAnalysisV2'] as $folder) {
            array_push($paths, ...glob(dirname(__DIR__).'/'.$folder.'/*.php'));
        }
        foreach (['Calculators/Bt03e03ProbabilityScorer', 'Calculators/Bt03e03CompensatedSum', 'Calculators/Bt03e02CompensatedSum',
            'Calculators/Bt03e06WinnerConditionedDecoder', 'Calculators/Bt03e05MetricEvaluator', 'Calculators/EffectBinBuilder',
            'DTO/EffectBinDto', 'DTO/Bt03e03FitResultDto', 'Services/Bt03e02Contract', 'Services/Bt03e03Contract',
            'Services/Bt03e06Contract', 'Support/CanonicalHasher', 'Experiments/TacticalPredictionResult/ResultStore',
            'Experiments/TacticalPredictionPipeline/ArtifactStore'] as $name) {
            $paths[] = app_path('Domain/Keirin/Backtest/'.$name.'.php');
        }
        $paths[] = app_path('Console/Commands/Keirin/GrowthAdjustmentCalibrationCommand.php');
        $files = [];
        foreach (array_unique($paths) as $path) {
            $files[substr($path, strlen(base_path()) + 1)] = Files::identity($path);
        }
        ksort($files, SORT_STRING);

        return ['php' => PHP_VERSION, 'files' => $files];
    }
}
