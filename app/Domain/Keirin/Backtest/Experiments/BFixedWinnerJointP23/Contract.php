<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\BFixedWinnerJointP23;

use App\Domain\Keirin\Backtest\Experiments\C1Stat35P1Composition\Contract as ParentContract;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;

final class Contract
{
    public const VERSION = 'B-FIXED-WINNER-JOINT-P23-01-v1';

    public const TIE = 'B-FIXED-WINNER-JOINT-P23-TIE-v1';

    public const ROOT = '/home/shinya/neo-keirin-artifacts/b-fixed-winner-joint-p23-01';

    public const B = '/home/shinya/neo-keirin-artifacts/c1-stat35-p1-composition-01/run-20261008-fALiXmYk/result';

    public const B_SHA = 'f1c1687aed2aa6cf9540c6f351e8017c240f096177e3206af775d936e3d92264';

    public static function plan(): array
    {
        return ['experiment' => self::VERSION, 'candidate' => 'B_FIXED_WINNER_JOINT_P23', 'years' => [2024, 2025],
            'baseline' => ParentContract::CANDIDATE, 'baseline_manifest_sha256' => self::B_SHA,
            'objective' => 'Q2(b|a) * Q3(c|a,b)', 'winner' => 'SAVED_B_FIXED', 'comparison' => 'STRICT_BINARY64',
            'retain_B_if_exact_maximum' => true, 'tie_version' => self::TIE,
            'tie_fields' => ['year', 'race_id', 'winner_bike', 'second_bike', 'third_bike'],
            'underflow' => 'PRESERVE_VALID_DISTRIBUTION_ZERO_NO_LOG_SUBSTITUTION',
            'outcomes' => 'AFTER_BOTH_YEAR_SEALS_AND_COHORT_INVARIANTS', 'training_count' => 0,
            'bootstrap' => ParentContract::plan()['bootstrap'], 'gate' => 'UNCHANGED_TACTICAL_HISTORY_INCREMENTAL_GATE',
            'historical_as_of_available' => false, 'formal_adoption' => false, '2026_access' => 'FORBIDDEN'];
    }

    public static function code(): array
    {
        $code = ParentContract::code();
        foreach ([...glob(__DIR__.'/*.php'),
            app_path('Domain/Keirin/Backtest/Experiments/Stat35Trend6P1/MetricContributionSpool.php'),
            app_path('Domain/Keirin/Backtest/Experiments/Stat35Trend6P1/PairedBootstrap.php'),
            app_path('Console/Commands/Keirin/CompareBFixedWinnerJointP23Command.php')] as $path) {
            $code[substr($path, strlen(base_path()) + 1)] = Files::identity($path);
        }
        ksort($code);

        return $code;
    }
}
