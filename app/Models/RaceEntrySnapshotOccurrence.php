<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RaceEntrySnapshotOccurrence extends Model
{
    protected $fillable = [
        'race_entry_id',
        'race_entry_snapshot_id',
        'effective_from',
        'effective_to',
        'is_current',
        'state_observed_at',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
            'is_current' => 'boolean',
            'state_observed_at' => 'immutable_datetime',
        ];
    }

    public function raceEntry(): BelongsTo
    {
        return $this->belongsTo(RaceEntry::class)->withTrashed();
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(RaceEntrySnapshot::class, 'race_entry_snapshot_id');
    }

    public function runFeatureSnapshotOccurrences(): HasMany
    {
        return $this->hasMany(StatisticRunFeatureSnapshotOccurrence::class);
    }
}
