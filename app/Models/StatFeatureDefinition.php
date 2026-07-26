<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StatFeatureDefinition extends Model
{
    protected $fillable = [
        'stat_code',
        'feature_code',
        'feature_name',
        'value_type',
        'unit_code',
        'description',
        'is_active',
        'definition_version',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
