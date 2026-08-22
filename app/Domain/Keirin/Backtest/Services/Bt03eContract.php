<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

final class Bt03eContract
{
    public const SOURCE_RUN_ID = 6;

    public const SOURCE_FOLD = 'WF_2023';

    public const COHORT = 'OPERATIONAL';

    public const EFFECT_MANIFEST_HASH = '1bcf2eb3ff4d7857e16622d5d719f6034764dd1785f4dbd7ceafbb63069c88cb';

    public const TRAINING_YEAR = 2023;

    public const EVALUATION_YEAR = 2024;

    /** @var list<string> */
    public const STAT_CODES = [
        'STAT-07', 'STAT-08', 'STAT-10', 'STAT-11', 'STAT-12', 'STAT-23',
        'STAT-24', 'STAT-26', 'STAT-31', 'STAT-32', 'STAT-39', 'STAT-42',
    ];

    /** @var list<string> */
    public const LABELS = ['IS_WIN', 'IS_TOP2', 'IS_TOP3'];

    /** @var list<int> */
    public const WEIGHT_GRID = [0, 5, 10, 20, 30, 40];

    /** @var list<int> */
    public const BASE_STEP_GRID = [0, 5, 10, 20, 30, 40];

    public const SPOOL_FORMAT = 'BT03E-RACE-SPOOL-v1';

    private function __construct() {}
}
