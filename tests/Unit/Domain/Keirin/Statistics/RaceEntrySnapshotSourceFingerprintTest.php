<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Statistics;

use App\Domain\Keirin\Statistics\Services\RaceEntrySnapshotSourceFingerprint;
use DateTime;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RaceEntrySnapshotSourceFingerprintTest extends TestCase
{
    public function test_null_and_datetime_immutable_timestamps_are_supported(): void
    {
        $fingerprint = new RaceEntrySnapshotSourceFingerprint;

        $this->assertSame(
            $fingerprint->calculate($this->template()),
            $fingerprint->calculate($this->template()),
        );
        $this->assertNotSame(
            $fingerprint->calculate($this->template()),
            $fingerprint->calculate($this->template(
                contextVerifiedAt: new DateTimeImmutable('2026-07-26 10:00:00+09:00'),
                sourceFetchedAt: new DateTimeImmutable('2026-07-26 10:05:00+09:00'),
            )),
        );
    }

    public function test_same_instants_in_different_timezones_have_the_same_fingerprint(): void
    {
        $fingerprint = new RaceEntrySnapshotSourceFingerprint;

        $tokyo = $fingerprint->calculate($this->template(
            contextVerifiedAt: new DateTimeImmutable('2026-07-26 10:00:00+09:00'),
            sourceFetchedAt: new DateTimeImmutable('2026-07-26 10:05:00+09:00'),
        ));
        $utc = $fingerprint->calculate($this->template(
            contextVerifiedAt: new DateTimeImmutable('2026-07-26 01:00:00+00:00'),
            sourceFetchedAt: new DateTimeImmutable('2026-07-26 01:05:00+00:00'),
        ));

        $this->assertSame($tokyo, $utc);
    }

    #[DataProvider('invalidTimestampValues')]
    public function test_invalid_context_verified_at_types_are_rejected(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be null or DateTimeImmutable');

        (new RaceEntrySnapshotSourceFingerprint)->calculate(
            $this->template(contextVerifiedAt: $value),
        );
    }

    #[DataProvider('invalidTimestampValues')]
    public function test_invalid_source_fetched_at_types_are_rejected(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be null or DateTimeImmutable');

        (new RaceEntrySnapshotSourceFingerprint)->calculate(
            $this->template(sourceFetchedAt: $value),
        );
    }

    /**
     * @return array<string,array{mixed}>
     */
    public static function invalidTimestampValues(): array
    {
        return [
            'string' => ['2026-07-26 10:00:00+09:00'],
            'mutable datetime' => [new DateTime('2026-07-26 10:00:00+09:00')],
            'integer' => [123],
            'float' => [123.45],
            'array' => [['2026-07-26 10:00:00+09:00']],
            'object' => [(object) ['at' => '2026-07-26 10:00:00+09:00']],
            'boolean' => [true],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function template(
        mixed $contextVerifiedAt = null,
        mixed $sourceFetchedAt = null,
    ): array {
        return [
            'source_role' => 'PRIMARY_INPUT',
            'scraping_fetch_log_id' => null,
            'source_page_type' => 'RACE_DETAIL',
            'source_race_context_key' => 'race:1',
            'context_match_method' => 'RACE_ENTRY_FOREIGN_KEY',
            'context_verification_status' => 'VERIFIED_EXACT',
            'historical_backfill_scope' => 'STATIC_RACE_CARD_FIELDS_ONLY',
            'contributed_fields' => ['race_score'],
            'eligible_fields' => ['race_score'],
            'source_fetched_at' => $sourceFetchedAt,
            'parser_version' => null,
            'source_url' => null,
            'raw_file_path' => null,
            'raw_sha256' => null,
            'context_verified_at' => $contextVerifiedAt,
            'context_evidence' => ['source_link_status' => 'SOURCE_LINK_MISSING'],
        ];
    }
}
