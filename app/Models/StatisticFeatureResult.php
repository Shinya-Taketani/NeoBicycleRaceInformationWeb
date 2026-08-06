<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StatisticFeatureResult extends Model
{
    protected $fillable = [
        'feature_run_id',
        'stat_code',
        'calculation_version',
        'subject_type',
        'subject_key',
        'race_id',
        'race_entry_id',
        'player_id',
        'opponent_player_id',
        'bike_number',
        'status',
        'quality_status',
        'acquisition_mode',
        'input_as_of',
        'source_fetched_at',
        'features',
        'evidence',
        'input_hash',
        'raw_points',
        'confidence',
        'effective_points',
        'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'feature_run_id' => 'integer',
            'race_id' => 'integer',
            'race_entry_id' => 'integer',
            'player_id' => 'integer',
            'opponent_player_id' => 'integer',
            'bike_number' => 'integer',
            'input_as_of' => 'immutable_datetime',
            'source_fetched_at' => 'immutable_datetime',
            'features' => 'array',
            'evidence' => 'array',
            'calculated_at' => 'immutable_datetime',
        ];
    }
}
