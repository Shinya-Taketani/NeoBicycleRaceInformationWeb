<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StatisticFeatureRun extends Model
{
    protected $fillable = [
        'run_uuid',
        'stat_code',
        'calculation_version',
        'mode',
        'status',
        'history_from',
        'target_from',
        'target_to',
        'target_race_id',
        'input_as_of_policy',
        'parameters',
        'target_race_count',
        'processed_race_count',
        'target_entry_count',
        'success_count',
        'partial_count',
        'missing_count',
        'invalid_count',
        'error_count',
        'error_summary',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'history_from' => 'immutable_date',
            'target_from' => 'immutable_date',
            'target_to' => 'immutable_date',
            'target_race_id' => 'integer',
            'parameters' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(StatisticFeatureRunItem::class, 'feature_run_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(StatisticFeatureResult::class, 'feature_run_id');
    }
}
