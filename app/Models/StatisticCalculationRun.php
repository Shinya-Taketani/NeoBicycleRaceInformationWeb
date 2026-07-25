<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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

    public function featureSnapshots(): BelongsToMany
    {
        return $this->belongsToMany(
            StatFeatureSnapshot::class,
            'statistic_run_feature_snapshots',
            'calculation_run_id',
            'stat_feature_snapshot_id',
        );
    }
}
