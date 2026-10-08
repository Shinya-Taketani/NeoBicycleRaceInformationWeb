<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1P12FixedMarginalP3;

use App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder\Contract as Shared;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Services\Bt03e06Contract;

final class Contract
{
    public const VERSION = 'C1-P12-FIXED-MARGINAL-P3-01-v1';

    public const DECODER = 'C1-P12-FIXED-MARGINAL-P3-v1';

    public const TIE = 'C1-P12-FIXED-MARGINAL-P3-TIE-v1';

    public const CANDIDATE = 'C1_P12_FIXED_MARGINAL_P3';

    public const ROOT = '/home/shinya/neo-keirin-artifacts/c1-p12-fixed-marginal-p3-01';

    public static function plan(): array
    {
        return ['experiment' => self::VERSION, 'candidate' => self::CANDIDATE,
            'artifact_role' => 'FIXED_MODEL_DECISION_POLICY_COMPARISON',
            'model_version' => 'TACTICAL-HISTORY-SEQUENTIAL-POSITION-v2',
            'source' => Shared::plan()['source'], 'years' => [2024, 2025], 'training_count' => 0,
            'independent_decode_evaluation_runs' => 2, 'baseline_decoder' => Bt03e06Contract::DECODER_VERSION,
            'candidate_decoder' => self::DECODER, 'primary_tie' => self::TIE,
            'objective' => 'MAX_SAVED_UNCONDITIONAL_P3_EXCLUDING_ORIGINAL_P1_P2',
            'equal_maximum' => 'RETAIN_ORIGINAL_P3',
            'tie_input' => 'TIE_VERSION|year|race_id|original_P1|original_P2|candidate_bike',
            'tie_order' => 'LEXICAL_SHA256_ASC_THEN_BIKE_ASC',
            'label_release' => 'AFTER_BOTH_YEARS_DECISION_SEAL_AND_P1_P2_VERIFIED',
            'evaluator_adapter' => ['second_third_tie_count' => 'ELIGIBLE_P3_MAXIMUM_COUNT',
                'primary_decision_tied' => 'ORIGINAL_P1_TIE_OR_ELIGIBLE_P3_TIE',
                'primary_technical_tiebreak_used' => 'ORIGINAL_P1_HASH_OR_NEW_P3_HASH_SELECTION'],
            'bootstrap' => Shared::plan()['bootstrap'], 'incremental_gate' => Shared::plan()['incremental_gate'],
            'use_restrictions' => Shared::plan()['use_restrictions']];
    }

    public static function code(): array
    {
        $code = Shared::code();
        unset($code['app/Console/Commands/Keirin/CompareC1MarginalP23Command.php']);
        foreach ([...glob(__DIR__.'/*.php'), base_path('app/Console/Commands/Keirin/CompareC1P12FixedMarginalP3Command.php')] as $path) {
            $code[substr($path, strlen(base_path()) + 1)] = Files::identity($path);
        }
        ksort($code);

        return $code;
    }
}
