<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Race extends Model
{
    protected $fillable = [
        'source',
        'external_race_id',
        'racetrack_id',
        'race_date',
        'race_number',
        'scheduled_start_at',
        'name',
        'grade',
        'race_type',
        'entrant_count',
        'result_status',
        'race_card_url',
        'result_url',
        'result_confirmed_at',
        'last_fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'race_date' => 'immutable_date',
            'scheduled_start_at' => 'immutable_datetime',
            'result_confirmed_at' => 'immutable_datetime',
            'last_fetched_at' => 'immutable_datetime',
        ];
    }
}
