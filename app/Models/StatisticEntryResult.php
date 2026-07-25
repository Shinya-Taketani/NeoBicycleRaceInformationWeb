<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class StatisticEntryResult extends Model
{
    protected $fillable = [
        'calculation_run_id',
        'stat_code',
        'calculation_version',
        'race_id',
        'race_entry_id',
        'player_id',
        'bike_number',
        'race_score',
        'valid_score_count',
        'missing_score_count',
        'invalid_score_count',
        'entrant_count',
        'score_rank',
        'dense_rank',
        'strength_percentile',
        'race_average_score',
        'race_max_score',
        'difference_from_average',
        'difference_from_max',
        'race_standard_deviation',
        'z_score',
        'quality_status',
        'acquisition_mode',
        'input_snapshot',
        'input_hash',
        'source',
        'source_fetched_at',
        'raw_points',
        'confidence',
        'effective_points',
        'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'race_score' => 'decimal:2',
            'strength_percentile' => 'decimal:8',
            'race_average_score' => 'decimal:4',
            'race_max_score' => 'decimal:2',
            'difference_from_average' => 'decimal:4',
            'difference_from_max' => 'decimal:4',
            'race_standard_deviation' => 'decimal:6',
            'z_score' => 'decimal:8',
            'input_snapshot' => 'array',
            'source_fetched_at' => 'immutable_datetime',
            'raw_points' => 'decimal:4',
            'confidence' => 'decimal:8',
            'effective_points' => 'decimal:4',
            'calculated_at' => 'immutable_datetime',
        ];
    }

    public function calculationRun(): BelongsTo
    {
        return $this->belongsTo(StatisticCalculationRun::class);
    }

    public function race(): BelongsTo
    {
        return $this->belongsTo(Race::class);
    }

    public function raceEntry(): BelongsTo
    {
        return $this->belongsTo(RaceEntry::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function runs(): BelongsToMany
    {
        return $this->belongsToMany(
            StatisticCalculationRun::class,
            'statistic_run_entry_results',
            'statistic_entry_result_id',
            'calculation_run_id',
        );
    }
}
