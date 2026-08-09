<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BacktestFold extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'train_from' => 'immutable_date',
            'train_to' => 'immutable_date',
            'evaluation_from' => 'immutable_date',
            'evaluation_to' => 'immutable_date',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
