<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1P23NondecreasingMarginal;

use App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder\Contract as Shared;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;

final class Contract
{
    public const VERSION = 'C1-P23-NONDECREASING-MARGINAL-01-v1';

    public const DECODER = 'C1-P23-NONDECREASING-MARGINAL-v1';

    public const TIE = 'C1-P23-NONDECREASING-MARGINAL-TIE-v1';

    public const CANDIDATE = 'C1_WINNER_FIXED_P23_NONDECREASING_MARGINAL';

    public const ROOT = '/home/shinya/neo-keirin-artifacts/c1-p23-nondecreasing-marginal-01';

    public static function plan(): array
    {
        return array_replace(Shared::plan(), ['experiment' => self::VERSION, 'candidate' => self::CANDIDATE,
            'candidate_decoder' => self::DECODER, 'primary_tie' => self::TIE,
            'objective' => 'MAX_P2_PLUS_P3_COMPONENT_NONDECREASING_DISTINCT_NON_WINNER_ORDERED_PAIR',
            'artifact_role' => 'FIXED_MODEL_DECISION_POLICY_COMPARISON',
            'selection' => ['fixed' => 'original E06 P1', 'thresholds' => 'original P2/P3 unconditional marginal probabilities',
                'objective' => 'maximum binary64 P2(b)+P3(c) over all distinct nonwinner pairs with both components >= original',
                'equal_maximum' => 'retain original pair', 'otherwise_exact_tie' => 'minimum SHA256, then numeric b,c',
                'tie_input' => 'TIE_VERSION|year|race_id|a0|b0|c0|b|c',
                'reference_order' => 'original S <= fixed-P2 marginal-P3 S <= constrained S <= E05 S',
                'compatibility_pair_tie' => 'feasible maximum pair count; original winner tie preserved separately'],
            'output_root' => self::ROOT]);
    }

    public static function code(): array
    {
        $files = Shared::code();
        unset($files['app/Console/Commands/Keirin/CompareC1MarginalP23Command.php']);
        foreach ([...glob(app_path('Domain/Keirin/Backtest/Experiments/C1P23NondecreasingMarginal/*.php')),
            app_path('Domain/Keirin/Backtest/Experiments/C1P12FixedMarginalP3/Decoder.php'),
            app_path('Domain/Keirin/Backtest/Experiments/C1P12FixedMarginalP3/Contract.php'),
            app_path('Console/Commands/Keirin/CompareC1P23NondecreasingMarginalCommand.php')] as $path) {
            $files[substr($path, strlen(base_path()) + 1)] = Files::identity($path);
        }
        ksort($files);

        return $files;
    }
}
