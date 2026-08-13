<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BacktestEffectBin extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'lower_bound' => 'float',
            'upper_bound' => 'float',
            'metadata' => 'array',
        ];
    }
}
