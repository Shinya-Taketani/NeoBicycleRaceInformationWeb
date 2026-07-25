<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RaceEntry extends Model
{
    protected $fillable = [
        'race_id',
        'player_id',
        'external_player_id',
        'player_name',
        'bike_number',
        'frame_number',
        'grade',
        'race_score',
        'line_text',
        'prefecture',
        'riding_style',
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

    public function race(): BelongsTo
    {
        return $this->belongsTo(Race::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
