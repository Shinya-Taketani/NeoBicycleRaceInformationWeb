<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1Stat35P1Composition;

use App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder\Contract as Shared;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Diagnostic\Contract as Diagnostic;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use App\Domain\Keirin\Backtest\Services\Bt03e06Contract;

final class Contract
{
    public const VERSION = 'C1-STAT35-P1-COMPOSITION-01-v1';

    public const CALCULATION = 'C1-STAT35-P1-COMPOSITION-PROBABILITY-v1';

    public const CANDIDATE = 'C2_P1_C1_P23_COMPOSITION';

    public const ROOT = '/home/shinya/neo-keirin-artifacts/c1-stat35-p1-composition-01';

    public static function plan(): array
    {
        return ['experiment' => self::VERSION, 'candidate' => self::CANDIDATE,
            'calculation_version' => self::CALCULATION, 'artifact_role' => 'FROZEN_POSITION_MODEL_COMPOSITION',
            'position_sources' => ['POSITION_1' => 'SAVED_RUN_01_C2_OUTER', 'POSITION_2' => 'SAVED_RUN_01_C1_OUTER', 'POSITION_3' => 'SAVED_RUN_01_C1_OUTER'],
            'model_versions' => ['C1' => 'TACTICAL-HISTORY-SEQUENTIAL-POSITION-v2', 'C2' => 'STAT35-C2-SEQUENTIAL-POSITION-v1'],
            'source' => ['input_manifest' => Shared::INPUT_SHA, 'export_manifest' => Shared::EXPORT_SHA,
                'baseline_contract' => Shared::BASELINE_CONTRACT_SHA, 'compare_manifest' => Diagnostic::COMPARE_SEAL],
            'years' => [2024, 2025], 'training_count' => 0, 'independent_forward_evaluation_runs' => 2,
            'probability_reference' => Bt03e03Contract::PROBABILITY_TOLERANCE,
            'probability_tie' => Bt03e03Contract::TIE_RULE_VERSION, 'decoder' => Bt03e06Contract::DECODER_VERSION,
            'primary_tie' => Bt03e06Contract::PRIMARY_TIE_RULE_VERSION, 'supporting_tie' => Bt03e06Contract::SUPPORTING_TIE_RULE_VERSION,
            'controls' => 'ALL_C1_AND_ALL_C2_EXACT_MATHEMATICAL_FORWARD',
            'label_release' => 'AFTER_BOTH_YEARS_PREDICTION_AND_DECISION_SEALS_AND_INVARIANTS',
            'model_expected_gain' => 'NOT_APPLICABLE', 'bootstrap' => Shared::plan()['bootstrap'],
            'incremental_gate' => 'UNCHANGED_TACTICAL_HISTORY_INCREMENTAL_GATE', 'use_restrictions' => Shared::plan()['use_restrictions']];
    }

    public static function code(): array
    {
        $files = Shared::code();
        unset($files['app/Console/Commands/Keirin/CompareC1MarginalP23Command.php']);
        foreach ([...glob(__DIR__.'/*.php'),
            app_path('Domain/Keirin/Backtest/Experiments/Stat35C1Comparison/Contract.php'),
            app_path('Domain/Keirin/Backtest/Experiments/Stat35C1Diagnostic/Contract.php'),
            app_path('Console/Commands/Keirin/CompareC1Stat35P1CompositionCommand.php')] as $path) {
            $files[substr($path, strlen(base_path()) + 1)] = Files::identity($path);
        }
        ksort($files);

        return $files;
    }
}
