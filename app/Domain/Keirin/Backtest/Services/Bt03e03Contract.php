<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

final class Bt03e03Contract
{
    public const NAME = 'BT-03E-03-POSITION-SPECIFIC-PROBABILITY';

    public const CALCULATION_VERSION = 'BT03E03-POSITION-PROBABILITY-v1';

    public const OPTIMIZER_VERSION = 'BT03E03-FISTA-POSITION-SOFTMAX-v1';

    public const PROBABILITY_VERSION = 'BT03E03-SEQUENTIAL-MARGINAL-v1';

    public const TIE_RULE_VERSION = 'BT03E03-ORDERED-TOP3-TIE-v1';

    public const CALIBRATION_DIAGNOSTIC_VERSION = 'BT03E03-CALIBRATION-10BIN-v1';

    public const ARTIFACT_VERSION = 'BT03E03-DEVELOPMENT-ARTIFACT-v1';

    public const PREDICTION_MANIFEST_VERSION = 'BT03E03-PREDICTION-SEMANTIC-MANIFEST-v1';

    public const COHORT = 'OPERATIONAL';

    public const MAX_ITERATIONS = 200;

    public const CONVERGENCE_TOLERANCE = 1e-7;

    public const OBJECTIVE_TOLERANCE = 1e-10;

    public const INITIAL_STEP = 1.0;

    public const BACKTRACK_FACTOR = 0.5;

    public const MAX_LINE_SEARCH_STEPS = 24;

    public const RESTART_RULE = 'MONOTONE_OBJECTIVE_RESTART';

    public const BOOTSTRAP_ITERATIONS = 2000;

    public const BOOTSTRAP_SEED = 20260812;

    public const BOOTSTRAP_CI_LOWER = 0.025;

    public const BOOTSTRAP_CI_UPPER = 0.975;

    public const PROBABILITY_TOLERANCE = 1e-12;

    public const NON_INFERIORITY_CI_LOWER_THRESHOLD = -0.0015;

    public const SUPERIORITY_CI_LOWER_THRESHOLD = 0.0;

    public const SUPERIORITY_POSITION_CI_POSITIVE_MIN_COUNT = 1;

    public const SUPERIORITY_PRIMARY_POSITIVE_MIN_COUNT = 3;

    public const TEMPORAL_STABILITY_DELTA_THRESHOLD = -0.0030;

    public const TECHNICAL_TIE_RATE_MAX = 0.001;

    public const SUPPORTING_MIN_NON_NEGATIVE_COUNT = 4;

    public const SUPPORTING_NON_NEGATIVE_THRESHOLD = 0.0;

    public const SUPPORTING_MIN_ALLOWED_DELTA = -0.0020;

    public const POSITION_REDESIGN_WIN_MIN_EXCLUSIVE = 0.0;

    public const POSITION_REDESIGN_P2_MIN_INCLUSIVE = 0.0;

    public const POSITION_REDESIGN_P3_MIN_EXCLUSIVE = 0.0;

    public const POSITION_REDESIGN_HIT3_MIN_EXCLUSIVE = 0.0;

    public const WIN_PRESERVATION_MIN_INCLUSIVE = 0.0;

    /** @var list<int> */
    public const DEVELOPMENT_YEARS = [2022, 2023, 2024, 2025];

    /** @var list<int> */
    public const OUTER_YEARS = [2024, 2025];

    /** @var list<string> */
    public const POSITIONS = ['POSITION_1', 'POSITION_2', 'POSITION_3'];

    /** @var list<string> */
    public const STAT_CODES = [
        'STAT-07', 'STAT-08', 'STAT-10', 'STAT-11', 'STAT-12', 'STAT-23',
        'STAT-24', 'STAT-26', 'STAT-31', 'STAT-32', 'STAT-39', 'STAT-42',
    ];

    /** @var list<float> */
    public const LAMBDA_GRID = [0.0, 1e-6, 1e-5, 1e-4, 1e-3, 1e-2, 1e-1, 1.0];

    /** @var list<float> */
    public const FIT_EXECUTION_ORDER = [1.0, 1e-1, 1e-2, 1e-3, 1e-4, 1e-5, 1e-6, 0.0];

    /** @return array<string,mixed> */
    public static function plan(): array
    {
        return [
            'contract' => self::NAME,
            'calculation_version' => self::CALCULATION_VERSION,
            'optimizer_version' => self::OPTIMIZER_VERSION,
            'probability_version' => self::PROBABILITY_VERSION,
            'tie_rule_version' => self::TIE_RULE_VERSION,
            'calibration_version' => self::CALIBRATION_DIAGNOSTIC_VERSION,
            'cohort' => self::COHORT,
            'positions' => self::POSITIONS,
            'stat01_anchor' => 'RACE_SCORE_Z coefficient=1.0 fixed for every position',
            'incremental_stats' => self::STAT_CODES,
            'objective' => 'RACE_BALANCED_SEQUENTIAL_CONDITIONAL_CATEGORICAL_NLL',
            'probability' => 'EXACT_POSITION_MARGINALIZATION',
            'ranking' => 'MAP_ORDERED_TOP3',
            'lambda_grid' => self::LAMBDA_GRID,
            'fit_execution_order' => self::FIT_EXECUTION_ORDER,
            'lambda_selection' => 'SHARED_ONE_SE_POSITION_EQUAL_YEAR_EQUAL',
            'alpha_combination' => 'FORBIDDEN',
            'solver_constants' => [
                'max_iterations' => self::MAX_ITERATIONS,
                'convergence_tolerance' => self::CONVERGENCE_TOLERANCE,
                'objective_tolerance' => self::OBJECTIVE_TOLERANCE,
                'initial_step' => self::INITIAL_STEP,
                'backtrack_factor' => self::BACKTRACK_FACTOR,
                'max_line_search_steps' => self::MAX_LINE_SEARCH_STEPS,
                'restart_rule' => self::RESTART_RULE,
            ],
            'bootstrap' => [
                'iterations' => self::BOOTSTRAP_ITERATIONS,
                'seed' => self::BOOTSTRAP_SEED,
                'ci_lower_quantile' => self::BOOTSTRAP_CI_LOWER,
                'ci_upper_quantile' => self::BOOTSTRAP_CI_UPPER,
                'resampling_unit' => 'YEAR_STRATIFIED_RACE_CLUSTER',
            ],
            'probability_tolerance' => self::PROBABILITY_TOLERANCE,
            'prediction_manifest_version' => self::PREDICTION_MANIFEST_VERSION,
            'acceptance_gate' => [
                'non_inferiority' => [
                    'primary_ci_lower_gt' => self::NON_INFERIORITY_CI_LOWER_THRESHOLD,
                ],
                'superiority' => [
                    'hit3_ci_lower_gt' => self::SUPERIORITY_CI_LOWER_THRESHOLD,
                    'one_of_win_p2_p3_ci_lower_gt' => self::SUPERIORITY_CI_LOWER_THRESHOLD,
                    'one_of_win_p2_p3_positive_min_count' => self::SUPERIORITY_POSITION_CI_POSITIVE_MIN_COUNT,
                    'primary_year_equal_positive_min_count' => self::SUPERIORITY_PRIMARY_POSITIVE_MIN_COUNT,
                ],
                'temporal_stability' => [
                    'each_outer_primary_delta_gte' => self::TEMPORAL_STABILITY_DELTA_THRESHOLD,
                ],
                'supporting' => [
                    'non_negative_min_count' => self::SUPPORTING_MIN_NON_NEGATIVE_COUNT,
                    'non_negative_threshold' => self::SUPPORTING_NON_NEGATIVE_THRESHOLD,
                    'none_below' => self::SUPPORTING_MIN_ALLOWED_DELTA,
                ],
                'tie_quality' => [
                    'technical_tiebreak_rate_lte' => self::TECHNICAL_TIE_RATE_MAX,
                    'candidate_tie_rate_lte_baseline' => true,
                ],
                'position_redesign' => [
                    'winner_year_equal_gt' => self::POSITION_REDESIGN_WIN_MIN_EXCLUSIVE,
                    'p2_year_equal_gte' => self::POSITION_REDESIGN_P2_MIN_INCLUSIVE,
                    'p3_year_equal_gt' => self::POSITION_REDESIGN_P3_MIN_EXCLUSIVE,
                    'hit3_year_equal_gt' => self::POSITION_REDESIGN_HIT3_MIN_EXCLUSIVE,
                ],
                'win_preservation' => [
                    'each_outer_year_winner_delta_gte' => self::WIN_PRESERVATION_MIN_INCLUSIVE,
                ],
            ],
            'outer_folds' => [
                '2024' => 'inner 2022->2023; refit 2022-2023; outer 2024',
                '2025' => 'inner 2022->2023 + 2022-2023->2024; refit 2022-2024; outer 2025',
            ],
            'read_only' => true,
            'memory_limit_contract' => '128M bounded JSONL replay and per-race exact marginalization',
            'development_corpus' => '2022-2025',
            '2026_access' => 'FORBIDDEN',
        ];
    }

    private function __construct() {}
}
