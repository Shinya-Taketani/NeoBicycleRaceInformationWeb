<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

final class Bt03e08Contract
{
    public const NAME = 'BT-03E-08-P1-Q2-FROZEN-WINNER-CONDITIONED-DIRECT-P3';

    public const CALCULATION_VERSION = 'BT03E08-WINNER-CONDITIONED-DIRECT-P3-v1';

    public const OBJECTIVE_VERSION = 'BT03E08-WINNER-CONDITIONED-P3-SOFTMAX-v1';

    public const OPTIMIZER_VERSION = 'BT03E08-FISTA-P3-v1';

    public const LAMBDA_SELECTION_VERSION = 'BT03E08-ONE-SE-P3-v1';

    public const PROBABILITY_VERSION = 'BT03E08-WINNER-CONDITIONED-P3-PROBABILITY-v1';

    public const DECODER_VERSION = 'BT03E08-P1-Q2-FROZEN-DIRECT-P3-v1';

    public const ARTIFACT_VERSION = 'BT03E08-DEVELOPMENT-ARTIFACT-v1';

    public const PREDICTION_MANIFEST_VERSION = 'BT03E08-PREDICTION-SEMANTIC-MANIFEST-v1';

    public const PRIMARY_TIE_RULE_VERSION = 'BT03E05-DECODER-TIE-v1';

    public const SUPPORTING_TIE_RULE_VERSION = 'BT03E04-DECODER-TIE-v1';

    public const SOURCE_CONTRACT_NAME = 'BT-03E-03-POSITION-SPECIFIC-PROBABILITY';

    public const SOURCE_CALCULATION_VERSION = 'BT03E03-POSITION-PROBABILITY-v2';

    public const SOURCE_OPTIMIZER_VERSION = 'BT03E03-FISTA-POSITION-SOFTMAX-v2';

    public const SOURCE_ITERATION_SEMANTICS_VERSION = 'BT03E03-ACCEPTED-UPDATE-BUDGET-v1';

    public const SOURCE_PROBABILITY_VERSION = 'BT03E03-SEQUENTIAL-MARGINAL-v1';

    public const SOURCE_TIE_RULE_VERSION = 'BT03E03-ORDERED-TOP3-TIE-v1';

    public const SOURCE_ARTIFACT_VERSION = 'BT03E03-DEVELOPMENT-ARTIFACT-v2';

    public const SOURCE_PREDICTION_MANIFEST_VERSION = 'BT03E03-PREDICTION-SEMANTIC-MANIFEST-v1';

    public const MAX_ITERATIONS = 200;

    public const CONVERGENCE_TOLERANCE = 1e-7;

    public const OBJECTIVE_TOLERANCE = 1e-10;

    public const INITIAL_STEP = 1.0;

    public const BACKTRACK_FACTOR = 0.5;

    public const MAX_LINE_SEARCH_STEPS = 24;

    public const RESTART_RULE = 'MONOTONE_OBJECTIVE_RESTART_SAME_UPDATE_RETRY-v2';

    public const PROBABILITY_TOLERANCE = 1e-12;

    public const BOOTSTRAP_ITERATIONS = 2000;

    public const BOOTSTRAP_SEED = 20260812;

    public const BOOTSTRAP_CI_LOWER = 0.025;

    public const BOOTSTRAP_CI_UPPER = 0.975;

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
            'objective_version' => self::OBJECTIVE_VERSION,
            'optimizer_version' => self::OPTIMIZER_VERSION,
            'lambda_selection_version' => self::LAMBDA_SELECTION_VERSION,
            'probability_version' => self::PROBABILITY_VERSION,
            'decoder_version' => self::DECODER_VERSION,
            'source_model_contract' => [
                'contract' => self::SOURCE_CONTRACT_NAME,
                'calculation_version' => self::SOURCE_CALCULATION_VERSION,
                'optimizer_version' => self::SOURCE_OPTIMIZER_VERSION,
                'iteration_semantics_version' => self::SOURCE_ITERATION_SEMANTICS_VERSION,
                'probability_version' => self::SOURCE_PROBABILITY_VERSION,
                'tie_rule_version' => self::SOURCE_TIE_RULE_VERSION,
                'artifact_version' => self::SOURCE_ARTIFACT_VERSION,
                'prediction_manifest_version' => self::SOURCE_PREDICTION_MANIFEST_VERSION,
            ],
            'p1_freeze_rule' => 'Use source E03 P1 binary64 values bit-exact; never fit, normalize, calibrate, or recompute P1.',
            'q2_freeze_rule' => 'Use source E03 POSITION_2 utility and E06 winner-conditioned softmax bit-exact; never fit or calibrate Q2.',
            'p3_training_candidate_set' => 'ALL_ENTRANTS_EXCEPT_ACTUAL_UNIQUE_RANK1; ACTUAL_RANK2_REMAINS',
            'p3_prediction_formula' => 'R3(i|a)=softmax(V3 over every entrant except frozen P1 winner a)',
            'pair_objective' => 'argmax distinct(b,c), b,c!=winner [Q2(b|a)+R3(c|a)]',
            'primary_tie_rule_version' => self::PRIMARY_TIE_RULE_VERSION,
            'supporting_tie_rule_version' => self::SUPPORTING_TIE_RULE_VERSION,
            'stat01_anchor' => 'RACE_SCORE_Z coefficient=1.0 fixed for P3',
            'incremental_stats' => self::STAT_CODES,
            'lambda_grid' => self::LAMBDA_GRID,
            'fit_execution_order' => self::FIT_EXECUTION_ORDER,
            'lambda_selection' => 'ONE_SE_P3_ONLY_YEAR_EQUAL',
            'solver_constants' => [
                'max_iterations' => self::MAX_ITERATIONS,
                'max_iterations_semantics' => 'ACCEPTED_PARAMETER_UPDATES',
                'convergence_tolerance' => self::CONVERGENCE_TOLERANCE,
                'objective_tolerance' => self::OBJECTIVE_TOLERANCE,
                'initial_step' => self::INITIAL_STEP,
                'backtrack_factor' => self::BACKTRACK_FACTOR,
                'max_line_search_steps' => self::MAX_LINE_SEARCH_STEPS,
                'restart_rule' => self::RESTART_RULE,
            ],
            'outer_folds' => [
                '2024' => 'inner 2022->2023; refit 2022-2023; predict and seal 2024 before opening 2024 outcomes',
                '2025' => 'inner 2022->2023 + 2022-2023->2024 equal-year; refit 2022-2024; predict and seal 2025 before opening 2025 outcomes',
            ],
            'bootstrap' => ['iterations' => self::BOOTSTRAP_ITERATIONS, 'seed' => self::BOOTSTRAP_SEED, 'ci_lower_quantile' => self::BOOTSTRAP_CI_LOWER, 'ci_upper_quantile' => self::BOOTSTRAP_CI_UPPER, 'type' => 7, 'unit' => 'YEAR_STRATIFIED_PAIRED_RACE_CLUSTER'],
            'acceptance_gate' => self::acceptanceGate(),
            'development_years' => self::DEVELOPMENT_YEARS,
            '2026_access' => 'FORBIDDEN',
            'read_only' => true,
        ];
    }

    /** @return array<string,mixed> */
    public static function acceptanceGate(): array
    {
        return [
            'non_inferiority' => ['primary_ci_lower_gt' => self::NON_INFERIORITY_CI_LOWER_THRESHOLD],
            'superiority' => [
                'hit3_ci_lower_gt' => self::SUPERIORITY_CI_LOWER_THRESHOLD,
                'one_of_win_p2_p3_ci_lower_gt' => self::SUPERIORITY_CI_LOWER_THRESHOLD,
                'one_of_win_p2_p3_positive_min_count' => self::SUPERIORITY_POSITION_CI_POSITIVE_MIN_COUNT,
                'primary_year_equal_positive_min_count' => self::SUPERIORITY_PRIMARY_POSITIVE_MIN_COUNT,
            ],
            'temporal_stability' => ['each_outer_primary_delta_gte' => self::TEMPORAL_STABILITY_DELTA_THRESHOLD],
            'supporting' => ['non_negative_min_count' => self::SUPPORTING_MIN_NON_NEGATIVE_COUNT, 'non_negative_threshold' => self::SUPPORTING_NON_NEGATIVE_THRESHOLD, 'none_below' => self::SUPPORTING_MIN_ALLOWED_DELTA],
            'tie_quality' => ['technical_tiebreak_rate_lte' => self::TECHNICAL_TIE_RATE_MAX, 'candidate_tie_rate_lte_baseline' => true],
            'position_redesign' => ['winner_year_equal_gt' => self::POSITION_REDESIGN_WIN_MIN_EXCLUSIVE, 'p2_year_equal_gte' => self::POSITION_REDESIGN_P2_MIN_INCLUSIVE, 'p3_year_equal_gt' => self::POSITION_REDESIGN_P3_MIN_EXCLUSIVE, 'hit3_year_equal_gt' => self::POSITION_REDESIGN_HIT3_MIN_EXCLUSIVE],
            'win_preservation' => ['each_outer_year_winner_delta_gte' => self::WIN_PRESERVATION_MIN_INCLUSIVE],
        ];
    }

    private function __construct() {}
}
