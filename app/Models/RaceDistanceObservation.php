<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class RaceDistanceObservation extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['race_id' => 'integer', 'scraping_fetch_log_id' => 'integer', 'race_number' => 'integer',
            'race_date' => 'immutable_date', 'planned_distance_m' => 'integer', 'planned_laps' => 'integer',
            'actual_distance_m' => 'integer', 'actual_laps' => 'integer', 'raw_bytes' => 'integer',
            'scheduled_start_at' => 'immutable_datetime', 'fetched_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime', 'raw_fields' => 'array', 'entrants' => 'array', 'metadata' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('Distance observations are append-only.'));
        static::deleting(fn () => throw new RuntimeException('Distance observations are append-only.'));
    }
}
