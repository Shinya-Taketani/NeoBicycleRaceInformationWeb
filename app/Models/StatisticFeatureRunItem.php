<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StatisticFeatureRunItem extends Model
{
    protected $fillable = [
        'feature_run_id',
        'race_id',
        'status',
        'attempt_count',
        'feature_result_count',
        'error_type',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'feature_run_id' => 'integer',
            'race_id' => 'integer',
            'attempt_count' => 'integer',
            'feature_result_count' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
