<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RaceEntrySnapshot extends Model
{
    protected $fillable = [
        'race_entry_id',
        'race_id',
        'player_id',
        'external_player_id',
        'bike_number',
        'frame_number',
        'grade',
        'race_score_raw_text',
        'race_score',
        'race_score_validation_status',
        'race_score_anomaly_status',
        'snapshot_type',
        'input_snapshot_type',
        'snapshot_hash',
        'first_observed_at',
        'last_observed_at',
        'is_complete',
        'parser_version',
    ];

    protected function casts(): array
    {
        return [
            'race_score' => 'decimal:4',
            'first_observed_at' => 'immutable_datetime',
            'last_observed_at' => 'immutable_datetime',
            'is_complete' => 'boolean',
        ];
    }

    public function raceEntry(): BelongsTo
    {
        return $this->belongsTo(RaceEntry::class)->withTrashed();
    }

    public function race(): BelongsTo
    {
        return $this->belongsTo(Race::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(RaceEntrySnapshotSource::class);
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(RaceEntrySnapshotOccurrence::class);
    }

    public function currentOccurrence(): HasOne
    {
        return $this->hasOne(RaceEntrySnapshotOccurrence::class)
            ->where('is_current', true);
    }
}
