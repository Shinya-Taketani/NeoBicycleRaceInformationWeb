<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatisticRunFeatureSnapshotOccurrence extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'calculation_run_id',
        'stat_feature_snapshot_id',
        'race_entry_snapshot_occurrence_id',
        'race_id',
        'race_entry_id',
        'source_role',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }

    public function calculationRun(): BelongsTo
    {
        return $this->belongsTo(StatisticCalculationRun::class, 'calculation_run_id');
    }

    public function featureSnapshot(): BelongsTo
    {
        return $this->belongsTo(StatFeatureSnapshot::class, 'stat_feature_snapshot_id');
    }

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(
            RaceEntrySnapshotOccurrence::class,
            'race_entry_snapshot_occurrence_id',
        );
    }

    public function race(): BelongsTo
    {
        return $this->belongsTo(Race::class);
    }

    public function raceEntry(): BelongsTo
    {
        return $this->belongsTo(RaceEntry::class)->withTrashed();
    }
}
