<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RacePayout extends Model
{
    protected $fillable = [
        'race_id',
        'race_result_import_id',
        'bet_type_code',
        'combination',
        'payout_amount',
        'popularity',
        'sequence',
        'source_url',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return ['fetched_at' => 'immutable_datetime'];
    }
}
