<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RaceEntry extends Model
{
    protected $fillable = [
        'race_id',
        'player_id',
        'external_player_id',
        'bike_number',
        'frame_number',
        'grade',
        'race_score',
        'line_text',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'bike_number' => 'integer',
            'race_score' => 'decimal:2',
            'fetched_at' => 'immutable_datetime',
        ];
    }
}
