<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

final class Bt03e02Contract
{
    public const NAME = 'BT-03E-02-SCORING-RULE-REDESIGN';

    public const CALCULATION_VERSION = 'BT03E02-SCORING-v1';

    public const COHORT = 'OPERATIONAL';

    public const OPTIMIZER_VERSION = 'BT03E02-FISTA-GROUP-PROX-v1';

    public const CENTERING_VERSION = 'BT03E02-SUPPORT-WEIGHTED-CENTERING-v1';

    public const NORMALIZATION_VERSION = 'RACE_CENTERED_RMS_V1';

    public const SUMMATION_VERSION = 'NEUMAIER_COMPENSATED_SUM_V1';

    public const TIE_RULE_VERSION = 'BT03E02-TIE-v1';

    public const ARTIFACT_VERSION = 'BT03E02-DEVELOPMENT-ARTIFACT-v1';

    public const SPOOL_VERSION = 'BT03E02-RACE-SPOOL-v1';

    public const MAX_ITERATIONS = 200;

    public const CONVERGENCE_TOLERANCE = 1e-7;

    public const OBJECTIVE_TOLERANCE = 1e-10;

    public const INITIAL_STEP = 1.0;

    public const BACKTRACK_FACTOR = 0.5;

    public const MAX_LINE_SEARCH_STEPS = 24;

    public const RESTART_RULE = 'MONOTONE_OBJECTIVE_RESTART';

    public const BOOTSTRAP_ITERATIONS = 2000;

    public const BOOTSTRAP_SEED = 20260812;

    /** @var list<int> */
    public const DEVELOPMENT_YEARS = [2022, 2023, 2024, 2025];

    /** @var list<int> */
    public const OUTER_YEARS = [2024, 2025];

    /** @var list<string> */
    public const STAT_CODES = [
        'STAT-07', 'STAT-08', 'STAT-10', 'STAT-11', 'STAT-12', 'STAT-23',
        'STAT-24', 'STAT-26', 'STAT-31', 'STAT-32', 'STAT-39', 'STAT-42',
    ];

    /** @var list<string> */
    public const CHANNELS = ['IS_WIN', 'IS_TOP2', 'IS_TOP3'];

    /** @var list<float> */
    public const LAMBDA_GRID = [0.0, 1e-6, 1e-5, 1e-4, 1e-3, 1e-2, 1e-1, 1.0];

    /** @return list<array{IS_WIN: float, IS_TOP2: float, IS_TOP3: float, key: string}> */
    public static function alphaCandidates(array $degenerateChannels = []): array
    {
        $candidates = [];
        for ($win = 0; $win <= 20; $win++) {
            for ($top2 = 0; $top2 <= 20 - $win; $top2++) {
                $top3 = 20 - $win - $top2;
                $parts = ['IS_WIN' => $win, 'IS_TOP2' => $top2, 'IS_TOP3' => $top3];
                $valid = true;
                foreach ($degenerateChannels as $channel) {
                    if (($parts[$channel] ?? -1) !== 0) {
                        $valid = false;
                    }
                }
                if (! $valid) {
                    continue;
                }
                $candidates[] = [
                    'IS_WIN' => $win / 20,
                    'IS_TOP2' => $top2 / 20,
                    'IS_TOP3' => $top3 / 20,
                    'key' => sprintf('%02d-%02d-%02d', $win, $top2, $top3),
                ];
            }
        }

        return $candidates;
    }

    /** @return array<string, mixed> */
    public static function plan(): array
    {
        return [
            'contract' => self::NAME,
            'calculation_version' => self::CALCULATION_VERSION,
            'stat01_anchor' => 'RACE_SCORE_Z coefficient=1.0 fixed',
            'incremental_stats' => self::STAT_CODES,
            'cohort' => self::COHORT,
            'outer_folds' => [
                '2024' => 'inner 2022->2023; refit 2022-2023; outer 2024',
                '2025' => 'inner 2022->2023 + 2022-2023->2024; refit 2022-2024; outer 2025',
            ],
            'lambda_grid' => self::LAMBDA_GRID,
            'alpha_candidate_count' => count(self::alphaCandidates()),
            'optimizer_version' => self::OPTIMIZER_VERSION,
            'solver_constants' => [
                'max_iterations' => self::MAX_ITERATIONS,
                'convergence_tolerance' => self::CONVERGENCE_TOLERANCE,
                'objective_tolerance' => self::OBJECTIVE_TOLERANCE,
                'initial_step' => self::INITIAL_STEP,
                'backtrack_factor' => self::BACKTRACK_FACTOR,
                'max_line_search_steps' => self::MAX_LINE_SEARCH_STEPS,
                'restart_rule' => self::RESTART_RULE,
            ],
            'normalization' => self::NORMALIZATION_VERSION,
            'summation' => self::SUMMATION_VERSION,
            'tie_rule' => self::TIE_RULE_VERSION,
            'read_only' => true,
            'memory_limit_contract' => '128M bounded JSONL replay',
            'development_corpus' => '2022-2025',
            '2026_access' => 'FORBIDDEN',
        ];
    }

    private function __construct() {}
}
