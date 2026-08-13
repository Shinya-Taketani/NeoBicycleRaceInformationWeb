<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BacktestModel extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'training_from' => 'immutable_date',
            'training_to' => 'immutable_date',
            'inner_fit_from' => 'immutable_date',
            'inner_fit_to' => 'immutable_date',
            'inner_validation_from' => 'immutable_date',
            'inner_validation_to' => 'immutable_date',
            'feature_names' => 'array',
            'scaler_mean' => 'array',
            'scaler_sd' => 'array',
            'lambda_candidates' => 'array',
            'coefficients' => 'array',
            'selected_lambda' => 'float',
            'intercept' => 'float',
            'final_objective' => 'float',
        ];
    }
}
