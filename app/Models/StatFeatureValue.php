<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatFeatureValue extends Model
{
    protected $fillable = [
        'stat_feature_snapshot_id',
        'feature_code',
        'value_type',
        'feature_value_integer',
        'feature_value_numeric',
        'feature_value_text',
        'feature_value_boolean',
        'feature_value_json',
        'numerator',
        'denominator',
        'sample_count',
        'window_type',
        'window_value',
        'unit_code',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'feature_value_boolean' => 'boolean',
            'feature_value_json' => 'array',
        ];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(StatFeatureSnapshot::class, 'stat_feature_snapshot_id');
    }
}
