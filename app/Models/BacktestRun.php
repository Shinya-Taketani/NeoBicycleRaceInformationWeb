<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BacktestRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
