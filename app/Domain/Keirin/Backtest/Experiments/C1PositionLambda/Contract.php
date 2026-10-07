<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1PositionLambda;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\HistoryAggregator;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\SolverContract;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;

final class Contract
{
    public const VERSION = 'C1-POSITION-LAMBDA-01-v1';

    public const MODEL_VERSION = 'C1-POSITION-LAMBDA-SEQUENTIAL-POSITION-v1';

    public const SELECTOR_VERSION = 'C1-PER-POSITION-ONE-SE-v1';

    public const PATH_VERSION = 'C1-PER-POSITION-CONVERGENCE-PATH-v1';

    public const CANDIDATE = 'C1_PER_POSITION_LAMBDA';

    public const INPUT = '/home/shinya/neo-keirin-artifacts/stat35-c1-input-02/run-20260930-054458-8739da4b/result';

    public const BASELINE = '/home/shinya/neo-keirin-artifacts/tactical-history-01-review-fix-20260916-01';

    public const ROOT = '/home/shinya/neo-keirin-artifacts/c1-position-lambda-01';

    public const INPUT_SHA = '7f4356b93f90203dc727dc95d6215a8d8ce3f44869c4441c3981087ff1099c26';

    public const EXPORT_SHA = '4268b801b74b77cfb3a94b64832ff483e9eb5e3221ee8e75ceb467e5a5cf92e6';

    public const BASELINE_CONTRACT_SHA = '5db3b57b08fb8b91dbe6c057b459e518252c454b0a4ff60531c2d671b7ce506a';

    public static function features(): array
    {
        return [...Bt03e03Contract::STAT_CODES, ...HistoryAggregator::FEATURES];
    }

    public static function restrictions(): array
    {
        return ['artifact_role' => 'LIMITED_DEVELOPMENT_EXPERIMENT_ONLY', 'historical_as_of_available' => false,
            'formal_adoption' => false, 'live_use_authorized' => false, '2026_access' => 'FORBIDDEN', 'points' => null];
    }

    public static function plan(): array
    {
        return ['version' => self::VERSION, 'model_version' => self::MODEL_VERSION, 'selector_version' => self::SELECTOR_VERSION,
            'path_version' => self::PATH_VERSION, 'candidate' => self::CANDIDATE, 'baseline' => 'SAVED_RUN_01_C1_OUTER',
            'features' => self::features(), 'anchor_coefficient' => 1.0, 'optimizer_version' => SolverContract::OPTIMIZER_VERSION,
            'numeric_reference' => Bt03e03Contract::plan(), 'single_position_numeric_update' => 'UNCHANGED_TACTICAL_HISTORY_V2',
            'selection_unit' => 'PER_POSITION_ONE_SE_YEAR_EQUAL', 'candidate_eligibility' => 'PER_POSITION_INTERSECTION_OF_INNER_FOLDS',
            'warm_start' => 'LAST_CONVERGED_IN_SAME_POOL_POSITION_ONLY', 'lambda_grid' => Bt03e03Contract::LAMBDA_GRID,
            'fit_order' => Bt03e03Contract::FIT_EXECUTION_ORDER, 'selection_best_tie' => 'FIRST_ASCENDING_GRID',
            'one_se_rule' => 'LARGEST_LAMBDA_LOSS_LE_BEST_PLUS_SAMPLE_SD', 'bootstrap_null_denominator' => 'WEIGHTED_ELIGIBLE_FAIL_CLOSED_IF_ZERO',
            'pools' => ['T22' => [2022], 'T2223' => [2022, 2023], 'T2224' => [2022, 2023, 2024]],
            'pool_reuse' => 'T2223_ONCE_PER_RUN_OUTER2024_AND_INNER_B', 'cross_run_fit_cache' => false,
            'maximum_position_lambda_trials_per_run' => 72, 'independent_runs' => 2,
            'outer_years' => [2024, 2025], 'null_policy' => 'INACTIVE_NO_COHORT_REMOVAL', 'original_values_types_order_unchanged' => true,
            'label_release' => 'AFTER_PREDICTION_SEAL_RELOAD_AND_ORDERED_COHORT_VERIFICATION',
            'bootstrap' => ['iterations' => 2000, 'seed' => 20260812, 'quantile' => 'TYPE7', 'unit' => 'YEAR_STRATIFIED_PAIRED_RACE', 'aggregation' => 'YEAR_EQUAL'],
            'incremental_gate' => ['ni_ci_lower_exclusive' => -0.0015, 'hit3_ci_lower_exclusive' => 0.0,
                'year_hit3_inclusive' => 0.0, 'year_primary_inclusive' => -0.003, 'independent_reproduction_required' => true],
            'c1_retraining' => false, 'authorized_input_manifest_sha256' => self::INPUT_SHA, 'use_restrictions' => self::restrictions()];
    }

    public static function code(): array
    {
        $paths = glob(__DIR__.'/*.php');
        foreach (['Calculators', 'DTO', 'Support', 'Services', 'Contracts', 'Enums', 'Exceptions'] as $dir) {
            array_push($paths, ...glob(dirname(__DIR__, 2).'/'.$dir.'/*.php'));
        }
        foreach (['TacticalHistory', 'TacticalHistoryFinal'] as $dir) {
            array_push($paths, ...glob(dirname(__DIR__).'/'.$dir.'/*.php'));
        }
        array_push($paths, ...glob(base_path('app/Domain/Keirin/Statistics/AgariC1Input/*.php')));
        $paths[] = base_path('app/Domain/Keirin/Statistics/AgariRaceRelative/Artifacts.php');
        $paths[] = base_path('app/Console/Commands/Keirin/CompareC1PositionLambdaCommand.php');
        $paths[] = base_path('composer.lock');
        sort($paths);
        $code = [];
        foreach ($paths as $path) {
            $code[str_replace(base_path().'/', '', $path)] = Files::identity($path);
        }

        return $code;
    }
}
