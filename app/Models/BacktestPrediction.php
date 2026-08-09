<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class BacktestPrediction extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (self $prediction): void {
            if ($prediction->getOriginal('locked_at') !== null) {
                throw new LogicException('Locked backtest predictions are immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'prediction_score' => 'decimal:2',
            'is_rank1_set' => 'boolean',
            'is_top3_set' => 'boolean',
            'locked_at' => 'immutable_datetime',
        ];
    }
}
