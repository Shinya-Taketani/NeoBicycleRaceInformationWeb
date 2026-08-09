<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class BacktestPrediction extends Model
{
    private const IMMUTABLE = [
        'race_id', 'race_entry_id', 'player_id', 'bike_number', 'feature_run_id',
        'feature_result_id', 'source_input_hash', 'prediction_rule_version',
        'prediction_score', 'predicted_rank', 'is_rank1_set', 'is_top3_set', 'prediction_hash',
    ];

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (self $prediction): void {
            if ($prediction->getOriginal('locked_at') !== null && $prediction->isDirty(self::IMMUTABLE)) {
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
