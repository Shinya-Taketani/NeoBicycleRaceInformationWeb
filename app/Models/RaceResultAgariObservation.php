<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RaceResultAgariObservation extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['fetched_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime',
            'agari_time_seconds' => 'string', 'metadata' => 'array'];
    }
}
