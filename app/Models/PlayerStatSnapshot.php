<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlayerStatSnapshot extends Model
{
    protected $fillable = [
        'player_id',
        'basis_date',
        'source_hash',
        'race_score',
        'win_rate',
        'quinella_rate',
        'trio_rate',
        'back_count',
        'home_count',
        'start_count',
        'source_url',
        'first_fetched_at',
        'last_fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'basis_date' => 'immutable_date',
            'race_score' => 'decimal:2',
            'win_rate' => 'decimal:2',
            'quinella_rate' => 'decimal:2',
            'trio_rate' => 'decimal:2',
            'first_fetched_at' => 'immutable_datetime',
            'last_fetched_at' => 'immutable_datetime',
        ];
    }
}
