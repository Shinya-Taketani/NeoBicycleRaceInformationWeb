<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RaceResult extends Model
{
    protected $fillable = [
        'race_id',
        'race_result_import_id',
        'race_entry_id',
        'player_id',
        'bike_number',
        'rank',
        'result_status',
        'winning_technique',
        'agari_time_seconds',
        'agari_raw_text',
        'agari_status',
        'raw_result_text',
        'source_url',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return ['fetched_at' => 'immutable_datetime', 'agari_time_seconds' => 'string'];
    }
}
