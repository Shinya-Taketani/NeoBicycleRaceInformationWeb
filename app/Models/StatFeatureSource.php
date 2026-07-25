<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StatFeatureSource extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'stat_feature_snapshot_id',
        'race_entry_snapshot_id',
        'scraping_fetch_log_id',
        'source_role',
        'source_identity_key',
        'source_type',
        'source_url',
        'raw_file_path',
        'raw_sha256',
        'source_fetched_at',
        'source_reference_at',
        'parser_version',
        'source_timing_status',
    ];

    protected function casts(): array
    {
        return [
            'source_fetched_at' => 'immutable_datetime',
            'source_reference_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
