<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RaceMeeting extends Model
{
    protected $fillable = [
        'source',
        'external_meeting_id',
        'racetrack_id',
        'meeting_name',
        'grade',
        'starts_on',
        'ends_on',
        'duration_days',
        'race_list_url',
        'encrypted_parameter',
        'day_kind',
        'last_fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'last_fetched_at' => 'immutable_datetime',
        ];
    }
}
