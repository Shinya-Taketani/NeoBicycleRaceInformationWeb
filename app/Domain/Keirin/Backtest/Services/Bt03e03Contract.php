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

    public const COHORT = 'OPERATIONAL';

    public const MAX_ITERATIONS = 200;

    public const CONVERGENCE_TOLERANCE = 1e-7;

    public const OBJECTIVE_TOLERANCE = 1e-10;

    public const INITIAL_STEP = 1.0;

    public const BACKTRACK_FACTOR = 0.5;

    public const MAX_LINE_SEARCH_STEPS = 24;

    public const BOOTSTRAP_ITERATIONS = 2000;

    public const BOOTSTRAP_SEED = 20260812;

    public const PROBABILITY_TOLERANCE = 1e-12;

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
            'lambda_selection' => 'SHARED_ONE_SE_POSITION_EQUAL_YEAR_EQUAL',
            'alpha_combination' => 'FORBIDDEN',
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
