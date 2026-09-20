<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthTrendScoreSource;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;

class Code
{
    public function capture(bool $analysis = true): array
    {
        $paths = glob(__DIR__.'/*.php');
        if ($analysis) {
            array_push($paths, ...glob(dirname(__DIR__).'/GrowthTrendAnalysis/*.php'));
        }
        foreach (['TacticalHistory/JsonlArtifact', 'TacticalHistoryFinal/Files', 'TacticalPredictionResult/ResultStore',
            'TacticalPredictionPipeline/ArtifactStore', 'TacticalPredictionPipeline/ReadOnlySession', 'GrowthPointAnalysis/Signals',
            'GrowthPointAnalysis/Contract', 'GrowthPointAnalysis/Analysis', 'TacticalGradeAnalysis/Aggregator', 'TacticalMeetingGradeAnalysis/Classification'] as $name) {
            $paths[] = dirname(__DIR__).'/'.$name.'.php';
        }
        foreach ($analysis ? ['GrowthTrendScoreSourceCommand', 'GrowthTrendAnalysisCommand'] : ['GrowthTrendScoreSourceCommand'] as $command) {
            $paths[] = app_path('Console/Commands/Keirin/'.$command.'.php');
        }
        $files = [];
        foreach ($paths as $path) {
            $files[substr($path, strlen(base_path()) + 1)] = Files::identity($path);
        }
        ksort($files);

        return ['php' => PHP_VERSION, 'files' => $files];
    }
}
