<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\Stat01C1ScoreGapComparison;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\HistoryAggregator;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\SolverContract;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;

final class Contract
{
    public const VERSION = 'STAT-01-C1-SCORE-GAP-01-v1';

    public const MODEL_VERSION = 'STAT01-C1-SCORE-GAP-SEQUENTIAL-POSITION-v1';

    public const EXTRA_FEATURE = 'RACE_SCORE_CENTERED_RAW';

    public const INPUT = '/home/shinya/neo-keirin-artifacts/stat35-c1-input-02/run-20260930-054458-8739da4b/result';

    public const BASELINE = '/home/shinya/neo-keirin-artifacts/tactical-history-01-review-fix-20260916-01';

    public const ROOT = '/home/shinya/neo-keirin-artifacts/stat01-c1-score-gap-01';

    public const INPUT_SHA = '7f4356b93f90203dc727dc95d6215a8d8ce3f44869c4441c3981087ff1099c26';

    public const EXPORT_SHA = '4268b801b74b77cfb3a94b64832ff483e9eb5e3221ee8e75ceb467e5a5cf92e6';

    public const BASELINE_CONTRACT_SHA = '5db3b57b08fb8b91dbe6c057b459e518252c454b0a4ff60531c2d671b7ce506a';

    public static function restrictions(): array
    {
        return ['artifact_role' => 'LIMITED_DEVELOPMENT_EXPERIMENT_ONLY', 'historical_as_of_available' => false,
            'formal_adoption' => false, 'live_use_authorized' => false, '2026_access' => 'FORBIDDEN', 'points' => null];
    }

    public static function features(): array
    {
        return [...Bt03e03Contract::STAT_CODES, ...HistoryAggregator::FEATURES, self::EXTRA_FEATURE];
    }

    public static function plan(): array
    {
        return ['version' => self::VERSION, 'model_version' => self::MODEL_VERSION, 'candidate' => 'C1_PLUS_SCORE_GAP', 'baseline' => 'SAVED_RUN_01_C1_OUTER',
            'features' => self::features(), 'anchor_coefficient' => 1.0, 'optimizer_version' => SolverContract::OPTIMIZER_VERSION,
            'numeric_reference' => Bt03e03Contract::plan(), 'historical_as_of_available' => false,
            'formal_adoption' => false, 'live_use_authorized' => false, '2026_access' => 'FORBIDDEN',
            'derivation' => ['formula' => 'gap[i]=(float)entries[i].raw-array_sum(scores)/count(entries)',
                'arithmetic' => 'BINARY64_SAVED_ENTRY_ORDER_ARRAY_SUM_NO_DISPLAY_ROUNDING',
                'population' => 'ALL_FIXED_C1_ENTRANTS_NO_OUTCOME_OR_HISTORY_FILTER',
                'validation' => 'FROZEN_C1_VALIDATOR_THEN_FINITE_SUM_MEAN_GAP',
                'negative_is_valid' => true, 'zero_variance' => 'ALL_VALID_ZERO',
                'negative_zero' => 'NORMALIZE_TO_POSITIVE_ZERO_ONLY',
                'valid_zero_is_not_null' => true, 'audit_is_not_feature' => true],
            'history_basis' => 'HISTORICAL_EVENT_RECONSTRUCTION / BACKFILLED_FINAL_RESULT / DEVELOPMENT_ONLY',
            'points' => null, 'authorization' => 'FIXED_C1_RAW_CENTERED_GAP_LIMITED_DEVELOPMENT_COMPARISON_ONLY',
            'authorized_input_manifest_sha256' => self::INPUT_SHA,
            'null_policy' => 'RAW_MANDATORY_NO_GAP_NULL_NO_COHORT_REMOVAL', 'outer_years' => [2024, 2025],
            'inner_A' => ['train' => [2022], 'validation' => 2023], 'inner_B' => ['train' => [2022, 2023], 'validation' => 2024],
            'label_release' => 'AFTER_BOTH_PREDICTION_SEALS_AND_ORDERED_COHORT_VERIFICATION',
            'bootstrap' => ['iterations' => 2000, 'seed' => 20260812, 'quantile' => 'TYPE7', 'unit' => 'YEAR_STRATIFIED_PAIRED_RACE', 'aggregation' => 'YEAR_EQUAL'],
            'incremental_gate' => ['ni_ci_lower_exclusive' => -0.0015, 'hit3_ci_lower_exclusive' => 0.0,
                'year_hit3_inclusive' => 0.0, 'year_primary_inclusive' => -0.003, 'independent_reproduction_required' => true],
            'c1_retraining' => false, 'final_holdout_access' => 'FORBIDDEN', 'automatic_adoption' => false];
    }

    public static function code(): array
    {
        $paths = glob(__DIR__.'/*.php');
        foreach (['Calculators', 'DTO', 'Support', 'Services', 'Contracts', 'Enums', 'Exceptions'] as $dir) {
            // Include the complete frozen dependency modules, not only the new adapters.
            foreach (glob(dirname(__DIR__, 2).'/'.$dir.'/*.php') as $path) {
                $paths[] = $path;
            }
        }
        foreach (['TacticalHistory', 'TacticalHistoryFinal'] as $dir) {
            array_push($paths, ...glob(dirname(__DIR__).'/'.$dir.'/*.php'));
        }
        array_push($paths, ...glob(base_path('app/Domain/Keirin/Statistics/AgariC1Input/*.php')));
        $paths[] = base_path('app/Domain/Keirin/Statistics/AgariRaceRelative/Artifacts.php');
        $paths[] = base_path('app/Console/Commands/Keirin/CompareStat01C1ScoreGapCommand.php');
        $paths[] = base_path('composer.lock');
        sort($paths);
        $code = [];
        foreach ($paths as $path) {
            $code[str_replace(base_path().'/', '', $path)] = Files::identity($path);
        }

        return $code;
    }
}
