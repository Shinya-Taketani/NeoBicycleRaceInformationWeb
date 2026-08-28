<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

final class Bt03e06Contract
{
    public const NAME = 'BT-03E-06-WINNER-CONDITIONED-SEQUENTIAL-DECODER';

    public const CALCULATION_VERSION = 'BT03E06-WINNER-CONDITIONED-DECODER-v1';

    public const DECODER_VERSION = 'BT03E06-WINNER-CONDITIONED-SEQUENTIAL-v1';

    public const FORWARD_RECONSTRUCTION_VERSION = 'BT03E06-E03-FORWARD-RECONSTRUCTION-v1';

    public const RECONSTRUCTION_MANIFEST_VERSION = 'BT03E06-E03-RECONSTRUCTION-MANIFEST-v1';

    public const ARTIFACT_VERSION = 'BT03E06-DEVELOPMENT-ARTIFACT-v1';

    public const DECODER_MANIFEST_VERSION = 'BT03E06-DECODER-SEMANTIC-MANIFEST-v1';

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

    public const SOURCE_REPRODUCIBILITY_STATUS = 'VERIFIED';

    public const SOURCE_INTEGRITY_STATUS = 'PASS';

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
    public const DEVELOPMENT_YEARS = [2024, 2025];

    /** @var list<string> */
    public const POSITIONS = ['POSITION_1', 'POSITION_2', 'POSITION_3'];

    /** @var list<string> */
    public const STAT_CODES = [
        'STAT-07', 'STAT-08', 'STAT-10', 'STAT-11', 'STAT-12', 'STAT-23',
        'STAT-24', 'STAT-26', 'STAT-31', 'STAT-32', 'STAT-39', 'STAT-42',
    ];

    /** @var array<string,string> */
    public const METRIC_DECODERS = [
        'WINNER_HIT_AT_1' => 'WINNER_CONDITIONED_SEQUENTIAL',
        'POSITION_1_ACCURACY' => 'WINNER_CONDITIONED_SEQUENTIAL',
        'POSITION_2_ACCURACY' => 'WINNER_CONDITIONED_SEQUENTIAL',
        'POSITION_3_ACCURACY' => 'WINNER_CONDITIONED_SEQUENTIAL',
        'POSITION_HIT_RATE_AT_3' => 'WINNER_CONDITIONED_SEQUENTIAL',
        'EXACT_ORDERED_TOP3_RATE' => 'MAP_ORDERED_TOP3',
        'EXACT_TOP3_SET_RATE' => 'MAP_TOP3_SET',
        'TOP3_COVERAGE_AT_3' => 'TOP3_MARGINAL',
        'EXACT_TOP2_SET_RATE' => 'TOP2_MARGINAL',
        'TOP2_COVERAGE_AT_2' => 'TOP2_MARGINAL',
        'NDCG_AT_3' => 'EXPECTED_NDCG',
    ];

    /** @return array<string,mixed> */
    public static function plan(): array
    {
        return [
            'contract' => self::NAME,
            'calculation_version' => self::CALCULATION_VERSION,
            'decoder_version' => self::DECODER_VERSION,
            'forward_reconstruction_version' => self::FORWARD_RECONSTRUCTION_VERSION,
            'reconstruction_manifest_version' => self::RECONSTRUCTION_MANIFEST_VERSION,
            'artifact_version' => self::ARTIFACT_VERSION,
            'decoder_manifest_version' => self::DECODER_MANIFEST_VERSION,
            'primary_tie_rule_version' => self::PRIMARY_TIE_RULE_VERSION,
            'supporting_tie_rule_version' => self::SUPPORTING_TIE_RULE_VERSION,
            'source_model_contract' => [
                'contract' => self::SOURCE_CONTRACT_NAME,
                'calculation_version' => self::SOURCE_CALCULATION_VERSION,
                'optimizer_version' => self::SOURCE_OPTIMIZER_VERSION,
                'iteration_semantics_version' => self::SOURCE_ITERATION_SEMANTICS_VERSION,
                'probability_version' => self::SOURCE_PROBABILITY_VERSION,
                'tie_rule_version' => self::SOURCE_TIE_RULE_VERSION,
                'artifact_version' => self::SOURCE_ARTIFACT_VERSION,
                'prediction_manifest_version' => self::SOURCE_PREDICTION_MANIFEST_VERSION,
                'reproducibility' => self::SOURCE_REPRODUCIBILITY_STATUS,
                'integrity' => self::SOURCE_INTEGRITY_STATUS,
            ],
            'metric_to_decoder' => self::METRIC_DECODERS,
            'winner_rule' => 'a = argmax_i P1(i)',
            'q2_formula' => 'Q2(i|a) = softmax(U2 over i != a)',
            'q3_path_formula' => 'Q3(c|a,b) = softmax(U3 over c != a,b)',
            'q3_marginal_formula' => 'Q3(c|a) = sum_b Q2(b|a) * Q3(c|a,b)',
            'pair_objective' => 'argmax distinct(b,c), b,c!=a [Q2(b|a)+Q3(c|a)]',
            'expected_ndcg_score' => '7*P1+3*P2+P3',
            'bootstrap' => [
                'iterations' => self::BOOTSTRAP_ITERATIONS,
                'seed' => self::BOOTSTRAP_SEED,
                'ci_lower_quantile' => self::BOOTSTRAP_CI_LOWER,
                'ci_upper_quantile' => self::BOOTSTRAP_CI_UPPER,
                'resampling_unit' => 'YEAR_STRATIFIED_RACE_CLUSTER',
            ],
            'acceptance_gate' => self::acceptanceGate(),
            'development_years' => self::DEVELOPMENT_YEARS,
            'model_fitting' => 'FORBIDDEN',
            'bin_generation' => 'FORBIDDEN',
            'candidate_search' => 'FORBIDDEN',
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
            'win_preservation' => ['each_outer_year_winner_delta_gte' => self::WIN_PRESERVATION_MIN_INCLUSIVE],
        ];
    }

    private function __construct() {}
}
