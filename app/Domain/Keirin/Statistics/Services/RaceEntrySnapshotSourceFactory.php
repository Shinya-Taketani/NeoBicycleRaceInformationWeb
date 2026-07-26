<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Services;

use App\Models\Race;
use App\Models\RaceEntry;
use App\Models\RaceEntrySnapshot;
use App\Models\RaceEntrySnapshotSource;
use App\Models\RaceEntrySnapshotSourceHead;
use App\Models\ScrapingFetchLog;
use DateTimeImmutable;

final class RaceEntrySnapshotSourceFactory
{
    public function __construct(
        private readonly RaceEntrySnapshotSourceFingerprint $fingerprint,
    ) {}

    /**
     * @param  array<string,mixed>  $template
     */
    public function fingerprint(
        array $template,
        bool $sourceLinkMissing,
        bool $raceScoreEligible,
    ): string {
        return $this->fingerprint->calculate(
            $template,
            $sourceLinkMissing,
            $raceScoreEligible,
        );
    }

    /**
     * @param  array<string,mixed>  $template
     */
    public function findOrCreate(
        RaceEntrySnapshot $snapshot,
        Race $race,
        RaceEntry $entry,
        array $template,
        ?DateTimeImmutable $sourceReferenceAt,
        bool $sourceLinkMissing,
        bool $raceScoreEligible,
    ): RaceEntrySnapshotSource {
        $fingerprint = $this->fingerprint(
            $template,
            $sourceLinkMissing,
            $raceScoreEligible,
        );
        $identityKey = "race-entry-source:{$snapshot->id}:{$fingerprint}";

        $source = RaceEntrySnapshotSource::query()->firstOrCreate(
            [
                'race_entry_snapshot_id' => $snapshot->id,
                'source_role' => $template['source_role'],
                'source_fingerprint' => $fingerprint,
            ],
            [
                'race_id' => $race->id,
                'race_entry_id' => $entry->id,
                'scraping_fetch_log_id' => $template['scraping_fetch_log_id'],
                'source_identity_key' => $identityKey,
                'contributed_fields' => $template['contributed_fields'],
                'source_page_type' => $template['source_page_type'],
                'source_race_context_key' => $template['source_race_context_key'],
                'context_match_method' => $template['context_match_method'],
                'context_verification_status' => $template['context_verification_status'],
                'historical_backfill_scope' => $template['historical_backfill_scope'],
                'eligible_fields' => $template['eligible_fields'],
                'source_reference_at' => $sourceReferenceAt,
                'context_verified_at' => $template['context_verified_at'],
                'context_evidence' => $template['context_evidence'],
            ],
        );

        RaceEntrySnapshotSourceHead::query()->updateOrCreate(
            ['race_entry_snapshot_id' => $snapshot->id],
            [
                'race_entry_snapshot_source_id' => $source->id,
                'race_id' => $race->id,
                'race_entry_id' => $entry->id,
            ],
        );

        return $source;
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    public function appendWithFetchLog(
        RaceEntrySnapshotSource $base,
        ScrapingFetchLog $fetchLog,
        ?DateTimeImmutable $sourceReferenceAt,
        bool $raceScoreEligible,
        array $overrides = [],
    ): RaceEntrySnapshotSource {
        return $this->appendFromExisting(
            $base,
            array_replace([
                'scraping_fetch_log_id' => $fetchLog->id,
                'source_fetched_at' => $fetchLog->fetched_at,
                'parser_version' => $fetchLog->parser_version,
                'source_url' => $fetchLog->request_url,
                'raw_file_path' => $fetchLog->raw_file_path,
                'raw_sha256' => $fetchLog->sha256,
            ], $overrides),
            $sourceReferenceAt,
            false,
            $raceScoreEligible,
        );
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    public function appendFromExisting(
        RaceEntrySnapshotSource $base,
        array $overrides,
        ?DateTimeImmutable $sourceReferenceAt,
        bool $sourceLinkMissing,
        bool $raceScoreEligible,
    ): RaceEntrySnapshotSource {
        $template = array_replace([
            'source_role' => $base->source_role,
            'scraping_fetch_log_id' => $base->scraping_fetch_log_id,
            'contributed_fields' => $base->contributed_fields ?? [],
            'source_page_type' => $base->source_page_type,
            'source_race_context_key' => $base->source_race_context_key,
            'context_match_method' => $base->context_match_method,
            'context_verification_status' => $base->context_verification_status,
            'historical_backfill_scope' => $base->historical_backfill_scope,
            'eligible_fields' => $base->eligible_fields ?? [],
            'context_verified_at' => $base->context_verified_at,
            'source_fetched_at' => $base->fetchLog?->fetched_at,
            'parser_version' => $base->fetchLog?->parser_version,
            'source_url' => $base->fetchLog?->request_url,
            'raw_file_path' => $base->fetchLog?->raw_file_path,
            'raw_sha256' => $base->fetchLog?->sha256,
            'context_evidence' => $base->context_evidence,
        ], $overrides);

        return $this->findOrCreate(
            $base->snapshot()->firstOrFail(),
            $base->race()->firstOrFail(),
            $base->raceEntry()->firstOrFail(),
            $template,
            $sourceReferenceAt,
            $sourceLinkMissing,
            $raceScoreEligible,
        );
    }
}
