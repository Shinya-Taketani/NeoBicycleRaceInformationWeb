<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1Stat10Ablation;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\HistoryAggregator;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\SolverContract;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;

final class Contract
{
    public const VERSION = 'C1-STAT10-ABLATION-01-v1';

    public const MODEL_VERSION = 'C1-MINUS-STAT10-SEQUENTIAL-POSITION-v1';

    public const BASELINE_STATS = ['STAT-07', 'STAT-08', 'STAT-10', 'STAT-11', 'STAT-12', 'STAT-23', 'STAT-24', 'STAT-26', 'STAT-31', 'STAT-32', 'STAT-39', 'STAT-42'];

    public const EXCLUDED = ['STAT-10'];

    public const INPUT = '/home/shinya/neo-keirin-artifacts/stat35-c1-input-02/run-20260930-054458-8739da4b/result';

    public const BASELINE = '/home/shinya/neo-keirin-artifacts/tactical-history-01-review-fix-20260916-01';

    public const ROOT = '/home/shinya/neo-keirin-artifacts/c1-stat10-ablation-01';

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
        return array_values(array_diff(self::baselineFeatures(), self::EXCLUDED));
    }

    public static function baselineFeatures(): array
    {
        Files::same(self::BASELINE_STATS, Bt03e03Contract::STAT_CODES, 'full feature names/order');

        return [...self::BASELINE_STATS, ...HistoryAggregator::FEATURES];
    }

    public static function projection(): array
    {
        $mapping = [];
        foreach (self::baselineFeatures() as $full => $name) {
            $retained = array_search($name, self::features(), true);
            $mapping[$name] = ['full_index' => $full, 'retained_index' => $retained === false ? null : $retained];
        }

        return ['baseline_features' => self::baselineFeatures(), 'candidate_features' => self::features(),
            'excluded_features' => self::EXCLUDED, 'full_to_retained_mapping' => $mapping];
    }

    public static function plan(): array
    {
        return ['version' => self::VERSION, 'model_version' => self::MODEL_VERSION, 'candidate' => 'C1_MINUS_STAT10', 'baseline' => 'SAVED_RUN_01_C1_OUTER',
            'use_restrictions' => self::restrictions(),
            'features' => self::features(), ...self::projection(), 'baseline_retraining' => false,
            'anchor_coefficient' => 1.0, 'optimizer_version' => SolverContract::OPTIMIZER_VERSION,
            'numeric_reference' => Bt03e03Contract::plan(), 'historical_as_of_available' => false,
            'formal_adoption' => false, 'live_use_authorized' => false, '2026_access' => 'FORBIDDEN',
            'derivation' => ['method' => 'VALIDATE_FULL_16_THEN_NAMED_STAT10_ONLY_REMOVAL',
                'population' => 'ALL_FIXED_C1_ENTRANTS_NO_OUTCOME_OR_HISTORY_FILTER',
                'valid_zero_is_not_null' => true, 'audit_is_not_feature' => true],
            'history_basis' => 'HISTORICAL_EVENT_RECONSTRUCTION / BACKFILLED_FINAL_RESULT / DEVELOPMENT_ONLY',
            'points' => null, 'authorization' => 'FIXED_C1_STAT10_ONLY_ABLATION_LIMITED_DEVELOPMENT_COMPARISON_ONLY',
            'authorized_input_manifest_sha256' => self::INPUT_SHA,
            'null_policy' => 'PRESERVE_ALL_RETAINED_VALUES_TYPES_NULL_ZERO_AND_COHORT', 'outer_years' => [2024, 2025],
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
        $paths[] = base_path('app/Console/Commands/Keirin/CompareC1Stat10AblationCommand.php');
        $paths[] = base_path('composer.lock');
        sort($paths);
        $code = [];
        foreach ($paths as $path) {
            $code[str_replace(base_path().'/', '', $path)] = Files::identity($path);
        }

        return $code;
    }
}
