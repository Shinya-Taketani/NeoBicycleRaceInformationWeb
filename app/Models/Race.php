<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Race extends Model
{
    protected $fillable = [
        'source',
        'external_race_id',
        'race_day_id',
        'racetrack_id',
        'race_date',
        'race_number',
        'scheduled_start_at',
        'sales_close_at',
        'name',
        'grade',
        'race_type',
        'entrant_count',
        'encrypted_parameter',
        'result_available',
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
            'entrant_count' => 'integer',
            'scheduled_start_at' => 'immutable_datetime',
            'sales_close_at' => 'immutable_datetime',
            'result_available' => 'boolean',
            'result_confirmed_at' => 'immutable_datetime',
            'last_fetched_at' => 'immutable_datetime',
        ];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(RaceEntry::class);
    }
}
