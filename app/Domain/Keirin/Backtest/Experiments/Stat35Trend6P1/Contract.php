<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\Stat35Trend6P1;

use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\Contract as Previous;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;

final class Contract
{
    public const VERSION = 'STAT35-TREND6-P1-INCREMENTAL-01-v1';

    public const MODEL_VERSION = 'STAT35-C3-TREND6-SEQUENTIAL-POSITION-v1';

    public const EXTRA_FEATURES = ['STAT35_MEAN6', 'STAT35_TREND6'];

    public const INPUT = Previous::INPUT;

    public const BASELINE = Previous::BASELINE;

    public const COMPOSITION = '/home/shinya/neo-keirin-artifacts/c1-stat35-p1-composition-01/run-20261008-fALiXmYk/result';

    public const COMPOSITION_SHA = 'f1c1687aed2aa6cf9540c6f351e8017c240f096177e3206af775d936e3d92264';

    public const ROOT = '/home/shinya/neo-keirin-artifacts/stat35-trend6-p1-incremental-01';

    public static function features(): array
    {
        return [...Previous::features(), 'STAT35_TREND6'];
    }

    public static function plan(): array
    {
        return array_replace(Previous::plan(), ['version' => self::VERSION, 'model_version' => self::MODEL_VERSION,
            'candidate' => 'C3_TREND6_P1_C1_P23_COMPOSITION', 'baseline' => 'SAVED_PR92_RUN01_OUTER_COMPOSITION',
            'features' => self::features(), 'authorization' => 'LIMITED_DEVELOPMENT_EXPERIMENT_ONLY',
            'trend' => 'EXACT_RECENT3_MINUS_PREVIOUS3_SIX_OBSERVED_MEETINGS',
            'position_sources' => ['POSITION_1' => 'C3', 'POSITION_2' => 'C1', 'POSITION_3' => 'C1'],
            'formal_adoption' => false]);
    }

    public static function code(): array
    {
        $code = Previous::code();
        $paths = glob(__DIR__.'/*.php');
        foreach (['AgariPlayerHistory', 'AgariC1Input'] as $module) {
            array_push($paths, ...glob(base_path('app/Domain/Keirin/Statistics/'.$module.'/*.php')));
        }
        foreach (['C1Stat35P1Composition', 'C1MarginalP23Decoder'] as $module) {
            array_push($paths, ...glob(dirname(__DIR__).'/'.$module.'/*.php'));
        }
        $paths[] = base_path('app/Console/Commands/Keirin/CompareStat35Trend6P1Command.php');
        foreach ($paths as $path) {
            $code[str_replace(base_path().'/', '', $path)] = Files::identity($path);
        }
        ksort($code);

        return $code;
    }
}
