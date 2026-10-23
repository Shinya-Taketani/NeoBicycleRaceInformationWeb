<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class RaceRosterSnapshot extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return ['race_id' => 'integer', 'race_number' => 'integer', 'scraping_fetch_log_id' => 'integer',
            'entrant_count' => 'integer', 'raw_bytes' => 'integer', 'race_date' => 'immutable_date',
            'fetched_at' => 'immutable_datetime', 'fetch_log_saved_at' => 'immutable_datetime',
            'scheduled_start_at' => 'immutable_datetime', 'official_publication_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime', 'entries' => 'array', 'schedule_raw' => 'array',
            'historical_as_of_available' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('Roster snapshots are append-only.'));
        static::deleting(fn () => throw new RuntimeException('Roster snapshots are append-only.'));
    }
}
