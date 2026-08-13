<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BacktestSignalSpec extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'operational_allowed_quality_reasons' => 'array',
            'parameters' => 'array',
        ];
    }
}
