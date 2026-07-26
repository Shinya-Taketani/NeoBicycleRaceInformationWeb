<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class RaceEntrySnapshotSource extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'race_entry_snapshot_id',
        'race_id',
        'race_entry_id',
        'scraping_fetch_log_id',
        'source_role',
        'source_identity_key',
        'source_fingerprint',
        'contributed_fields',
        'source_page_type',
        'source_race_context_key',
        'context_match_method',
        'context_verification_status',
        'historical_backfill_scope',
        'eligible_fields',
        'source_fetched_at',
        'parser_version',
        'source_url',
        'raw_file_path',
        'raw_sha256',
        'context_verified_at',
        'context_evidence',
    ];

    private const IMMUTABLE_AUDIT_FIELDS = [
        'race_entry_snapshot_id',
        'race_id',
        'race_entry_id',
        'scraping_fetch_log_id',
        'source_role',
        'source_identity_key',
        'source_fingerprint',
        'contributed_fields',
        'source_page_type',
        'source_race_context_key',
        'context_match_method',
        'context_verification_status',
        'historical_backfill_scope',
        'eligible_fields',
        'source_fetched_at',
        'parser_version',
        'source_url',
        'raw_file_path',
        'raw_sha256',
        'context_verified_at',
        'context_evidence',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $source): void {
            foreach (self::IMMUTABLE_AUDIT_FIELDS as $field) {
                if ($source->isDirty($field)) {
                    throw new LogicException(
                        "Race entry snapshot source {$source->id} is append-only; {$field} cannot be updated.",
                    );
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'contributed_fields' => 'array',
            'eligible_fields' => 'array',
            'source_fetched_at' => 'immutable_datetime',
            'context_verified_at' => 'immutable_datetime',
            'context_evidence' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(RaceEntrySnapshot::class, 'race_entry_snapshot_id');
    }

    public function race(): BelongsTo
    {
        return $this->belongsTo(Race::class);
    }

    public function raceEntry(): BelongsTo
    {
        return $this->belongsTo(RaceEntry::class)->withTrashed();
    }

    public function fetchLog(): BelongsTo
    {
        return $this->belongsTo(ScrapingFetchLog::class, 'scraping_fetch_log_id');
    }
}
