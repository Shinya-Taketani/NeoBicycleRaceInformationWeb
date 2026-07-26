<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Services;

use App\Models\Race;
use App\Models\RaceEntry;
use App\Models\RaceEntrySnapshot;
use App\Models\RaceEntrySnapshotSource;
use App\Models\RaceEntrySnapshotSourceHead;
use App\Models\ScrapingFetchLog;
use InvalidArgumentException;

final class RaceEntrySnapshotSourceFactory
{
    private const FETCH_EVIDENCE_FIELDS = [
        'source_fetched_at',
        'parser_version',
        'source_url',
        'raw_file_path',
        'raw_sha256',
    ];

    private const CLASSIFICATION_OVERRIDE_FIELDS = [
        'source_role',
        'contributed_fields',
        'source_page_type',
        'source_race_context_key',
        'context_match_method',
        'context_verification_status',
        'historical_backfill_scope',
        'eligible_fields',
        'context_verified_at',
        'context_evidence',
    ];

    public function __construct(
        private readonly RaceEntrySnapshotSourceFingerprint $fingerprint,
    ) {}

    /**
     * @param  array<string,mixed>  $template
     */
    public function fingerprint(array $template): string
    {
        return $this->fingerprint->calculate($this->normalizeTemplate($template));
    }

    /**
     * @param  array<string,mixed>  $template
     */
    public function findOrCreate(
        RaceEntrySnapshot $snapshot,
        Race $race,
        RaceEntry $entry,
        array $template,
    ): RaceEntrySnapshotSource {
        $template = $this->normalizeTemplate($template);
        $fingerprint = $this->fingerprint($template);
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
                'source_fetched_at' => $template['source_fetched_at'],
                'parser_version' => $template['parser_version'],
                'source_url' => $template['source_url'],
                'raw_file_path' => $template['raw_file_path'],
                'raw_sha256' => $template['raw_sha256'],
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
        array $overrides = [],
    ): RaceEntrySnapshotSource {
        $this->assertAllowedOverrides($overrides, self::CLASSIFICATION_OVERRIDE_FIELDS);

        return $this->createFromExisting(
            $base,
            array_replace([
                ...$this->templateFromSource($base),
                'scraping_fetch_log_id' => $fetchLog->id,
                'source_fetched_at' => $fetchLog->fetched_at,
                'parser_version' => $fetchLog->parser_version,
                'source_url' => $fetchLog->request_url,
                'raw_file_path' => $fetchLog->raw_file_path,
                'raw_sha256' => $fetchLog->sha256,
            ], $overrides),
        );
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    public function appendFromExisting(
        RaceEntrySnapshotSource $base,
        array $overrides,
    ): RaceEntrySnapshotSource {
        $this->assertAllowedOverrides(
            $overrides,
            [...self::CLASSIFICATION_OVERRIDE_FIELDS, 'scraping_fetch_log_id'],
        );
        if (array_key_exists('scraping_fetch_log_id', $overrides)
            && $overrides['scraping_fetch_log_id'] !== null
            && (int) $overrides['scraping_fetch_log_id'] !== (int) $base->scraping_fetch_log_id) {
            throw new InvalidArgumentException(
                'A source state may only attach a new Fetch Log through appendWithFetchLog().',
            );
        }

        return $this->createFromExisting(
            $base,
            array_replace($this->templateFromSource($base), $overrides),
        );
    }

    /**
     * @param  array<string,mixed>  $template
     */
    private function createFromExisting(
        RaceEntrySnapshotSource $base,
        array $template,
    ): RaceEntrySnapshotSource {
        return $this->findOrCreate(
            $base->snapshot()->firstOrFail(),
            $base->race()->firstOrFail(),
            $base->raceEntry()->firstOrFail(),
            $template,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function templateFromSource(RaceEntrySnapshotSource $base): array
    {
        return [
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
            'source_fetched_at' => $base->source_fetched_at,
            'parser_version' => $base->parser_version,
            'source_url' => $base->source_url,
            'raw_file_path' => $base->raw_file_path,
            'raw_sha256' => $base->raw_sha256,
            'context_evidence' => $base->context_evidence,
        ];
    }

    /**
     * @param  array<string,mixed>  $template
     * @return array<string,mixed>
     */
    private function normalizeTemplate(array $template): array
    {
        $required = [
            'source_role',
            'scraping_fetch_log_id',
            'contributed_fields',
            'source_page_type',
            'source_race_context_key',
            'context_match_method',
            'context_verification_status',
            'historical_backfill_scope',
            'eligible_fields',
            'context_verified_at',
            ...self::FETCH_EVIDENCE_FIELDS,
            'context_evidence',
        ];
        foreach ($required as $field) {
            if (! array_key_exists($field, $template)) {
                throw new InvalidArgumentException("Source state template field {$field} was missing.");
            }
        }

        if ($template['scraping_fetch_log_id'] === null) {
            foreach (self::FETCH_EVIDENCE_FIELDS as $field) {
                $template[$field] = null;
            }
        } elseif ((! is_int($template['scraping_fetch_log_id'])
                && (! is_string($template['scraping_fetch_log_id'])
                    || ! ctype_digit($template['scraping_fetch_log_id'])))
            || (int) $template['scraping_fetch_log_id'] < 1) {
            throw new InvalidArgumentException('Source state Fetch Log ID was invalid.');
        }

        return $template;
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @param  list<string>  $allowed
     */
    private function assertAllowedOverrides(array $overrides, array $allowed): void
    {
        $unsupported = array_values(array_diff(array_keys($overrides), $allowed));
        if ($unsupported !== []) {
            throw new InvalidArgumentException(
                'Source state override fields were not allowed: '.implode(', ', $unsupported).'.',
            );
        }
    }
}
