<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RaceDay extends Model
{
    protected $fillable = [
        'race_meeting_id',
        'external_race_day_id',
        'race_date',
        'day_number',
        'race_list_url',
        'result_list_url',
        'last_fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'race_date' => 'immutable_date',
            'last_fetched_at' => 'immutable_datetime',
        ];
    }
}
