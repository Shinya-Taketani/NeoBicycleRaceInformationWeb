<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BacktestFeatureSource extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'target_from' => 'immutable_date',
            'target_to' => 'immutable_date',
            'verified_at' => 'immutable_datetime',
        ];
    }
}
