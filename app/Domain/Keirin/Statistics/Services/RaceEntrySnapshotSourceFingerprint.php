<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Services;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;

final class RaceEntrySnapshotSourceFingerprint
{
    /**
     * @param  array<string,mixed>  $source
     *
     * @throws JsonException
     */
    public function calculate(
        array $source,
    ): string {
        return hash('sha256', json_encode([
            'source_role' => $source['source_role'],
            'scraping_fetch_log_id' => $source['scraping_fetch_log_id'],
            'source_page_type' => $source['source_page_type'],
            'source_race_context_key' => $source['source_race_context_key'],
            'context_match_method' => $source['context_match_method'],
            'context_verification_status' => $source['context_verification_status'],
            'historical_backfill_scope' => $source['historical_backfill_scope'],
            'contributed_fields' => $this->normalizedStringList($source['contributed_fields']),
            'eligible_fields' => $this->normalizedStringList($source['eligible_fields']),
            'source_link_missing' => $source['scraping_fetch_log_id'] === null,
            'source_fetched_at' => $this->canonicalTimestamp($source['source_fetched_at']),
            'parser_version' => $source['parser_version'],
            'source_url' => $source['source_url'],
            'raw_file_path' => $source['raw_file_path'],
            'raw_sha256' => $source['raw_sha256'],
            'context_verified_at' => $this->canonicalTimestamp($source['context_verified_at']),
            'context_evidence' => $this->canonicalValue($source['context_evidence']),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return list<string>
     */
    private function normalizedStringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $normalized = array_values(array_unique(array_filter(
            $values,
            static fn (mixed $value): bool => is_string($value),
        )));
        sort($normalized, SORT_STRING);

        return $normalized;
    }

    private function canonicalTimestamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! $value instanceof DateTimeImmutable) {
            throw new InvalidArgumentException(
                'Source state timestamp must be null or DateTimeImmutable.',
            );
        }

        return $value
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');
    }

    private function canonicalValue(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalValue($item), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => $this->canonicalValue($item), $value);
    }
}
