<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BacktestSignalMetric extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'baseline_value' => 'float',
            'incremental_value' => 'float',
            'delta_value' => 'float',
            'ci_lower' => 'float',
            'ci_upper' => 'float',
            'metadata' => 'array',
            'calculated_at' => 'immutable_datetime',
        ];
    }
}
