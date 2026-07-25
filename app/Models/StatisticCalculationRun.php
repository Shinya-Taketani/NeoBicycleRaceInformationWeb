<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StatisticCalculationRun extends Model
{
    protected $fillable = [
        'stat_code',
        'calculation_version',
        'status',
        'target_from',
        'target_to',
        'target_race_id',
        'parameters',
        'started_at',
        'finished_at',
        'target_race_count',
        'processed_race_count',
        'target_count',
        'success_count',
        'partial_count',
        'missing_count',
        'invalid_count',
        'error_count',
        'error_summary',
    ];

    protected function casts(): array
    {
        return [
            'target_from' => 'immutable_date',
            'target_to' => 'immutable_date',
            'parameters' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function targetRace(): BelongsTo
    {
        return $this->belongsTo(Race::class, 'target_race_id');
    }

    public function createdResults(): HasMany
    {
        return $this->hasMany(StatisticEntryResult::class, 'calculation_run_id');
    }

    public function results(): BelongsToMany
    {
        return $this->belongsToMany(
            StatisticEntryResult::class,
            'statistic_run_entry_results',
            'calculation_run_id',
            'statistic_entry_result_id',
        );
    }
}
