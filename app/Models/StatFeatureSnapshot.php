<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StatFeatureSnapshot extends Model
{
    protected $fillable = [
        'scope_type',
        'race_id',
        'race_entry_id',
        'player_id',
        'opponent_race_entry_id',
        'opponent_player_id',
        'stat_code',
        'input_as_of',
        'input_as_of_policy',
        'input_snapshot_type',
        'input_hash',
        'calculation_version',
        'status',
        'data_quality_status',
        'history_start_at',
        'history_end_at',
        'sample_count',
        'coverage_rate',
        'source_max_fetched_at',
        'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'input_as_of' => 'immutable_datetime',
            'history_start_at' => 'immutable_datetime',
            'history_end_at' => 'immutable_datetime',
            'coverage_rate' => 'decimal:6',
            'source_max_fetched_at' => 'immutable_datetime',
            'calculated_at' => 'immutable_datetime',
        ];
    }

    public function race(): BelongsTo
    {
        return $this->belongsTo(Race::class);
    }

    public function raceEntry(): BelongsTo
    {
        return $this->belongsTo(RaceEntry::class)->withTrashed();
    }

    public function values(): HasMany
    {
        return $this->hasMany(StatFeatureValue::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(StatFeatureSource::class);
    }

    public function runs(): BelongsToMany
    {
        return $this->belongsToMany(
            StatisticCalculationRun::class,
            'statistic_run_feature_snapshots',
            'stat_feature_snapshot_id',
            'calculation_run_id',
        );
    }
}
