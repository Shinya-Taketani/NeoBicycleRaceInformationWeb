<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Calculators\Bt03BinEffectCalculator;
use App\Domain\Keirin\Backtest\DTO\Bt03ProductionScopeDto;

class Bt03ProductionContract
{
    public const PREDICTION_RULE_VERSION = 'BT03-NO-SCORE-BIN-EFFECT-v1';

    public const HOLDOUT_POLICY = 'BLOCK_AFTER_2025-12-31';

    public const RACE_COUNT_SEMANTICS = 'NOT_APPLICABLE_TO_BIN_EFFECT_ANALYSIS';

    public const BOOTSTRAP_ITERATIONS = 2000;

    public const BOOTSTRAP_SEED = 20260812;

    public const SCOPE_COUNT = 72;

    public const LABEL_COUNT = 3;

    public const BASE_EFFECT_COUNT = 2004;

    public const LOCK_CLASS_ID = 1112817715; // ASCII "BT03"

    public const LOCK_OBJECT_ID = 1347571524; // ASCII "PROD"

    /** @var list<string> */
    public const FOLDS = ['WF_2023', 'WF_2024', 'WF_2025'];

    /** @var list<string> */
    public const COHORTS = ['STRICT', 'OPERATIONAL'];

    /** @var list<string> */
    public const LABELS = ['IS_WIN', 'IS_TOP2', 'IS_TOP3'];

    /** @return list<Bt03ProductionScopeDto> */
    public function scopes(): array
    {
        $scopes = [];
        $ordinal = 0;
        foreach (self::FOLDS as $foldCode) {
            foreach (Bt03SourceManifest::ENTRY_STAT_CODES as $statCode) {
                foreach (self::COHORTS as $cohortCode) {
                    $scopes[] = new Bt03ProductionScopeDto(++$ordinal, $foldCode, $statCode, $cohortCode);
                }
            }
        }

        return $scopes;
    }

    public function calculationVersion(): string
    {
        return Bt03BinEffectCalculator::CALCULATION_VERSION;
    }
}
