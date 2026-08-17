<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Support;

use App\Domain\Keirin\Backtest\Calculators\Bt03BinEffectCalculator;
use InvalidArgumentException;

class Bt03EffectHasher
{
    /** @var list<string> */
    private const REQUIRED_KEYS = [
        'source_bt02_run_id', 'source_bt02_run_uuid', 'source_fold_id', 'source_signal_spec_id',
        'source_baseline_model_hash', 'source_incremental_model_hash', 'source_boundaries_hash',
        'source_backtest_effect_bin_id', 'cohort_code', 'label_code', 'bin_index', 'bin_origin',
        'bin_kind', 'lower_bound', 'upper_bound', 'category_value', 'training_sample_count',
        'evaluation_status', 'evaluation_sample_count', 'evaluation_race_count', 'positive_count',
        'observed_rate', 'observed_rate_ci_lower', 'observed_rate_ci_upper',
        'baseline_mean_probability', 'incremental_mean_probability',
        'baseline_residual_mean', 'baseline_residual_ci_lower', 'baseline_residual_ci_upper',
        'incremental_residual_mean', 'incremental_residual_ci_lower', 'incremental_residual_ci_upper',
        'probability_shift_mean', 'probability_shift_ci_lower', 'probability_shift_ci_upper',
        'log_loss_delta', 'log_loss_delta_ci_lower', 'log_loss_delta_ci_upper',
        'brier_delta', 'brier_delta_ci_lower', 'brier_delta_ci_upper',
        'bootstrap_iterations', 'bootstrap_seed', 'calculation_version',
    ];

    public function __construct(private readonly Bt02ModelArtifactHasher $hasher) {}

    /** @param array<string, mixed> $artifact */
    public function hash(array $artifact): string
    {
        $keys = array_keys($artifact);
        sort($keys);
        $expected = self::REQUIRED_KEYS;
        sort($expected);
        if ($keys !== $expected || $artifact['calculation_version'] !== Bt03BinEffectCalculator::CALCULATION_VERSION) {
            throw new InvalidArgumentException('BT-03 effect hash artifact contract was invalid.');
        }

        return $this->hasher->hash($artifact);
    }
}
