<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\Stat35C1Diagnostic;

use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\Contract as Comparison;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;

final class Contract
{
    public const VERSION = 'STAT-35-C1-DIAGNOSTIC-01-v1';

    public const ROOT = '/home/shinya/neo-keirin-artifacts/stat35-c1-diagnostic-01';

    public const COMPARE = '/home/shinya/neo-keirin-artifacts/stat35-c1-compare-01/run-20260929-215427-3b1776ba/result';

    public const COMPARE_SEAL = ['bytes' => 138599, 'sha256' => '8fc40000c93bbc604caac37cf21e60519f26f28c4e77cf9284fa31403f5487c8'];

    public const YEARS = [2024, 2025];

    public const METRICS = ['POSITION_1_ACCURACY', 'POSITION_2_ACCURACY', 'POSITION_3_ACCURACY', 'POSITION_HIT_RATE_AT_3'];

    // Frozen before reading real rows. Only regrouped differences use this bound.
    public const ROUNDING_ULPS = 64;

    public static function plan(): array
    {
        return ['version' => self::VERSION, 'purpose' => 'POST_HOC_DESCRIPTIVE_DIAGNOSTIC', 'years' => self::YEARS,
            'sources' => ['comparison' => self::COMPARE_SEAL, 'input_manifest_sha256' => Comparison::INPUT_SHA,
                'baseline' => 'SAVED_RUN_01_C1_OUTER_ONLY'],
            'prediction' => 'SAVED_PRIMARY_POSITION_1_2_3_ONLY', 'summation' => 'NEUMAIER_COMPENSATED_SUM_V1_ANCHOR_THEN_FEATURE_ORDER',
            'saved_utility_check' => 'EXACT_BINARY64',
            'regrouped_difference_tolerance' => '64 * PHP_FLOAT_EPSILON * max(1, abs(anchor) + sum(abs(C1 contributions)) + sum(abs(C2 contributions)))',
            'rounding_ulps' => self::ROUNDING_ULPS, 'epsilon' => PHP_FLOAT_EPSILON,
            'transitions' => ['A' => 'BOTH_CORRECT', 'B' => 'C1_ONLY_CORRECT', 'C' => 'C2_ONLY_CORRECT', 'D' => 'BOTH_WRONG'],
            'ineligible' => 'EXCLUDED_NOT_D', 'zero_denominator' => 'NULL_NOT_EVALUATED',
            'hit3' => 'UNIQUE_OFFICIAL_1_2_3_POSITION_MATCHES_OVER_3_ELIGIBLE_RACES',
            'year_aggregation' => 'YEAR_EQUAL_RATE_DIFFERENCE', 'examples' => 'FIRST_THREE_B_C_PER_YEAR_POSITION_IN_INPUT_ORDER',
            'utility_units' => 'INTERNAL_UTILITY_NOT_PROBABILITY_OR_CAUSAL_EFFECT',
            'incremental_gate' => 'NOT_PASSED_UNCHANGED', 'adoption' => 'KEEP_C1_C2_NOT_ADOPTED',
            'historical_as_of_available' => false, 'holdout_2026' => 'CLOSED',
            'training_prediction_bootstrap_gate_execution' => false, 'application_db_http' => 'FORBIDDEN'];
    }

    public static function code(): array
    {
        $paths = glob(__DIR__.'/*.php');
        $dependencies = [
            'Experiments/Stat35C1Comparison/Contract.php', 'Experiments/Stat35C1Comparison/ModelLoader.php',
            'Experiments/Stat35C1Comparison/LoadedModel.php', 'Experiments/Stat35C1Comparison/Layout.php',
            'Experiments/TacticalHistoryFinal/ModelLoader.php', 'Experiments/TacticalHistoryFinal/LoadedModel.php',
            'Experiments/TacticalHistoryFinal/Contract.php', 'Experiments/TacticalHistoryFinal/Files.php',
            'Experiments/TacticalHistory/Layout.php', 'Experiments/TacticalHistory/HistoryAggregator.php',
            'Experiments/TacticalHistory/SolverContract.php', 'Experiments/TacticalHistory/JsonlArtifact.php',
            'Calculators/Bt03e03CompensatedSum.php', 'Calculators/Bt03e02CompensatedSum.php',
            'Calculators/EffectBinBuilder.php', 'Calculators/Bt03e05MetricEvaluator.php',
            'Calculators/Bt03e03ProbabilityScorer.php', 'DTO/EffectBinDto.php', 'DTO/Bt03e03FitResultDto.php',
            'Services/Bt03e02Contract.php', 'Services/Bt03e03Contract.php', 'Services/Bt03e06Contract.php',
        ];
        foreach ($dependencies as $file) {
            $paths[] = dirname(__DIR__, 2).'/'.$file;
        }
        foreach (['app/Domain/Keirin/Statistics/AgariC1Input/Validator.php', 'app/Domain/Keirin/Statistics/AgariC1Input/Contract.php',
            'app/Domain/Keirin/Statistics/AgariRaceRelative/Artifacts.php', 'app/Console/Commands/Keirin/DiagnoseStat35C1Command.php', 'composer.lock'] as $file) {
            $paths[] = base_path($file);
        }
        sort($paths);
        $result = [];
        foreach ($paths as $path) {
            $result[str_replace(base_path().'/', '', $path)] = Files::identity($path);
        }

        return $result;
    }
}
