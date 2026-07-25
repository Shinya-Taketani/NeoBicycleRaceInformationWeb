<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RaceEntrySnapshotSource extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'race_entry_snapshot_id',
        'scraping_fetch_log_id',
        'source_role',
        'source_identity_key',
        'contributed_fields',
        'source_page_type',
        'source_race_context_key',
        'context_match_method',
        'context_verification_status',
        'historical_backfill_scope',
        'eligible_fields',
        'source_reference_at',
        'context_verified_at',
        'context_evidence',
    ];

    protected function casts(): array
    {
        return [
            'contributed_fields' => 'array',
            'eligible_fields' => 'array',
            'source_reference_at' => 'immutable_datetime',
            'context_verified_at' => 'immutable_datetime',
            'context_evidence' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(RaceEntrySnapshot::class, 'race_entry_snapshot_id');
    }

    public function fetchLog(): BelongsTo
    {
        return $this->belongsTo(ScrapingFetchLog::class, 'scraping_fetch_log_id');
    }
}
