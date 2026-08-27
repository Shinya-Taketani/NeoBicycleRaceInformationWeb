<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Calculators\Bt03e04MetricEvaluator;

final class Bt03e04Contract
{
    public const NAME = 'BT-03E-04-DECISION-DECODER-SEPARATION';

    public const CALCULATION_VERSION = 'BT03E04-DECODER-SEPARATION-v1';

    public const DECODER_VERSION = 'BT03E04-DECODERS-v1';

    public const TIE_RULE_VERSION = 'BT03E04-DECODER-TIE-v1';

    public const ARTIFACT_VERSION = 'BT03E04-DEVELOPMENT-ARTIFACT-v1';

    public const DECODER_MANIFEST_VERSION = 'BT03E04-DECODER-SEMANTIC-MANIFEST-v1';

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
    public const DEVELOPMENT_YEARS = [2024, 2025];

    /** @var array<string,string> */
    public const METRIC_DECODERS = [
        'WINNER_HIT_AT_1' => 'PRIMARY_COHERENT_POSITION',
        'POSITION_1_ACCURACY' => 'PRIMARY_COHERENT_POSITION',
        'POSITION_2_ACCURACY' => 'PRIMARY_COHERENT_POSITION',
        'POSITION_3_ACCURACY' => 'PRIMARY_COHERENT_POSITION',
        'POSITION_HIT_RATE_AT_3' => 'PRIMARY_COHERENT_POSITION',
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
            'tie_rule_version' => self::TIE_RULE_VERSION,
            'artifact_version' => self::ARTIFACT_VERSION,
            'decoder_manifest_version' => self::DECODER_MANIFEST_VERSION,
            'source_model_contract' => [
                'calculation_version' => Bt03e03Contract::CALCULATION_VERSION,
                'optimizer_version' => Bt03e03Contract::OPTIMIZER_VERSION,
                'iteration_semantics_version' => Bt03e03Contract::ITERATION_SEMANTICS_VERSION,
                'probability_version' => Bt03e03Contract::PROBABILITY_VERSION,
                'artifact_version' => Bt03e03Contract::ARTIFACT_VERSION,
                'prediction_manifest_version' => Bt03e03Contract::PREDICTION_MANIFEST_VERSION,
                'reproducibility' => 'VERIFIED',
                'integrity' => 'PASS',
            ],
            'metric_to_decoder' => self::METRIC_DECODERS,
            'primary_objective' => 'argmax distinct(a,b,c) [P1(a)+P2(b)+P3(c)]',
            'argmax_p1' => 'DIAGNOSTIC_ONLY',
            'expected_ndcg_score' => '7*P1+3*P2+P3',
            'tie_rule' => 'BINARY64_EXACT_EQUAL_SHA256_ASCENDING',
            'bootstrap' => [
                'iterations' => self::BOOTSTRAP_ITERATIONS,
                'seed' => self::BOOTSTRAP_SEED,
                'ci_lower_quantile' => self::BOOTSTRAP_CI_LOWER,
                'ci_upper_quantile' => self::BOOTSTRAP_CI_UPPER,
                'resampling_unit' => 'YEAR_STRATIFIED_RACE_CLUSTER',
            ],
            'acceptance_gate' => self::acceptanceGate(),
            'development_years' => self::DEVELOPMENT_YEARS,
            'read_only' => true,
            'model_fitting' => 'FORBIDDEN',
            '2026_access' => 'FORBIDDEN',
        ];
    }

    /** @return array<string,mixed> */
    public static function acceptanceGate(): array
    {
        return [
            'metrics' => Bt03e04MetricEvaluator::METRIC_CODES,
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
