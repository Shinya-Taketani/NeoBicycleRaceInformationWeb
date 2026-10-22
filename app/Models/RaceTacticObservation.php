<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class RaceTacticObservation extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['race_id' => 'integer', 'race_entry_id' => 'integer', 'player_id' => 'integer',
            'scraping_fetch_log_id' => 'integer', 'race_number' => 'integer', 'bike_number' => 'integer',
            'race_date' => 'immutable_date', 'escape_count' => 'integer', 'sprint_count' => 'integer',
            'overtake_count' => 'integer', 'mark_count' => 'integer', 'back_count' => 'integer', 'home_count' => 'integer',
            'start_count' => 'integer', 'raw_bytes' => 'integer', 'fetched_at' => 'immutable_datetime',
            'fetch_log_saved_at' => 'immutable_datetime', 'scheduled_start_at' => 'immutable_datetime',
            'official_publication_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime',
            'raw_fields' => 'array', 'metadata' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('Tactic observations are append-only.'));
        static::deleting(fn () => throw new RuntimeException('Tactic observations are append-only.'));
    }
}
