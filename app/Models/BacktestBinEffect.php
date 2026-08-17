<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BacktestBinEffect extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'lower_bound' => 'float',
            'upper_bound' => 'float',
            'observed_rate' => 'float',
            'observed_rate_ci_lower' => 'float',
            'observed_rate_ci_upper' => 'float',
            'baseline_mean_probability' => 'float',
            'incremental_mean_probability' => 'float',
            'baseline_residual_mean' => 'float',
            'baseline_residual_ci_lower' => 'float',
            'baseline_residual_ci_upper' => 'float',
            'incremental_residual_mean' => 'float',
            'incremental_residual_ci_lower' => 'float',
            'incremental_residual_ci_upper' => 'float',
            'probability_shift_mean' => 'float',
            'probability_shift_ci_lower' => 'float',
            'probability_shift_ci_upper' => 'float',
            'log_loss_delta' => 'float',
            'log_loss_delta_ci_lower' => 'float',
            'log_loss_delta_ci_upper' => 'float',
            'brier_delta' => 'float',
            'brier_delta_ci_lower' => 'float',
            'brier_delta_ci_upper' => 'float',
            'calculated_at' => 'immutable_datetime',
        ];
    }
}
