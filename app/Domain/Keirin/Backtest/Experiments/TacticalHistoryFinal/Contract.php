<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\HistoryAggregator;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\SolverContract;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use App\Domain\Keirin\Backtest\Services\Bt03e06Contract;

final class Contract
{
    public const VERSION = 'TACTICAL-HISTORY-FINAL-01-v1';

    public const MERGE_SHA = '5071339125a5423cc37327953512634c0910cf42';

    public static function plan(): array
    {
        return [
            'artifact_version' => self::VERSION, 'source_pr' => 55, 'source_merge_sha' => self::MERGE_SHA,
            'feature_version' => HistoryAggregator::VERSION, 'optimizer_version' => SolverContract::OPTIMIZER_VERSION,
            'model_version' => SolverContract::MODEL_VERSION, 'decoder_version' => Bt03e06Contract::DECODER_VERSION,
            'primary_tie' => Bt03e06Contract::PRIMARY_TIE_RULE_VERSION, 'supporting_tie' => Bt03e06Contract::SUPPORTING_TIE_RULE_VERSION,
            'probability_version' => Bt03e03Contract::PROBABILITY_VERSION, 'anchor_coefficient' => 1.0,
            'features' => [...Bt03e03Contract::STAT_CODES, ...HistoryAggregator::FEATURES],
            'lambda_grid' => Bt03e03Contract::LAMBDA_GRID, 'fit_order' => Bt03e03Contract::FIT_EXECUTION_ORDER,
            'solver_constants' => Bt03e03Contract::plan()['solver_constants'],
            'stationarity_reference_step' => 1.0, 'stationarity_tolerance' => Bt03e03Contract::CONVERGENCE_TOLERANCE,
            'selection' => 'EXISTING_ONE_SE_SHARED_CANDIDATES_POSITION_EQUAL_YEAR_EQUAL',
            'bootstrap_iterations' => 2000, 'bootstrap_seed' => 20260812,
            'oof' => [2023 => [2022], 2024 => [2022, 2023], 2025 => [2022, 2023, 2024]],
            'training_years' => [2022, 2023, 2024, 2025], 'candidate' => 'C1',
            'alpha' => 'NOT_APPLICABLE: position-specific probabilities, no alpha mixture',
            'channel_scale' => 'NOT_APPLICABLE: no RACE_CENTERED_RMS',
            'decision_threshold' => 'NOT_APPLICABLE: frozen E06 decoder, no additional calibration',
            'scope' => 'BACKFILLED_FINAL_RESULT / DEVELOPMENT_ONLY', 'publication_time_verified' => 'UNKNOWN',
            '2026_access' => 'FORBIDDEN', 'live_adoption' => 'NOT_AUTHORIZED',
        ];
    }

    public static function parentSettings(): array
    {
        return [
            'experiment' => 'TACTICAL-HISTORY-01', 'model_version' => SolverContract::MODEL_VERSION,
            'calculation_version' => HistoryAggregator::VERSION, 'feature_order' => HistoryAggregator::FEATURES,
            'window' => '[meeting.starts_on 00:00 JST - 120 days, meeting.starts_on 00:00 JST)',
            'history_scope' => 'OBSERVED_DB_HISTORY / BACKFILLED_FINAL_RESULT / DEVELOPMENT_ONLY',
            'publication_time_verified' => 'UNKNOWN', 'c0' => 'STAT01_RACE_SCORE_Z_ANCHOR_1_PLUS_FIXED_12_STATS',
            'c1' => 'C0_PLUS_FOUR_COUNTS_ALL_THREE_POSITIONS', 'lambda_grid' => Bt03e03Contract::LAMBDA_GRID,
            'fit_order' => Bt03e03Contract::FIT_EXECUTION_ORDER, 'max_accepted_updates' => Bt03e03Contract::MAX_ITERATIONS,
            'optimizer_version' => SolverContract::OPTIMIZER_VERSION,
            'bootstrap' => ['iterations' => 2000, 'seed' => 20260812, 'quantile' => 'Type7', 'unit' => 'PAIRED_RACE_CLUSTER', 'strata' => 'YEAR', 'aggregation' => 'YEAR_EQUAL'],
            'outer_2024' => ['inner' => '2022->2023', 'refit' => [2022, 2023]],
            'outer_2025' => ['inner' => ['2022->2023', '2022+2023->2024'], 'refit' => [2022, 2023, 2024]],
            'allowed_years' => [2022, 2023, 2024, 2025], 'final_holdout_access' => 'FORBIDDEN',
            'convergence' => ['maximum_coefficient_change' => 1e-7, 'relative_objective_change' => 1e-10,
                'prox_gradient_mapping_max' => 1e-7, 'centering_residual_max' => 1e-7, 'stationarity_reference_step' => 1.0],
        ];
    }
}
