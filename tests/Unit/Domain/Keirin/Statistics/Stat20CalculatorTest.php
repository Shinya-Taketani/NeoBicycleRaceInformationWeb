<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Statistics;

use App\Domain\Keirin\Statistics\Calculators\Stat20Calculator;
use App\Domain\Keirin\Statistics\DTO\Stat20RaceInputDto;
use App\Domain\Keirin\Statistics\DTO\Stat20SourceInputDto;
use App\Domain\Keirin\Statistics\Enums\StatisticFeatureResultStatus;
use App\Domain\Keirin\Statistics\Support\DeterministicJsonHasher;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\MemoryLimitedTestProcess;
use Tests\Support\Stat20Fixture as Fixture;
use UnexpectedValueException;

class Stat20CalculatorTest extends TestCase
{
    private function calculator(): Stat20Calculator
    {
        return new Stat20Calculator(new DeterministicJsonHasher);
    }

    #[DataProvider('fields')]
    public function test_five_to_nine_riders_have_separate_evidence_and_no_invented_confidence(int $size): void
    {
        $results = $this->calculator()->calculate(Fixture::race($size));
        $this->assertCount($size, $results);
        foreach ($results as $r) {
            $this->assertSame(15, $r->features['SOURCE_RESULT_COVERAGE']['counts']['VALID']);
            $this->assertSame(1.0, $r->features['SOURCE_RESULT_COVERAGE']['valid_ratio']);
            $this->assertNull($r->features['CONFIDENCE']);
            $this->assertSame(StatisticFeatureResultStatus::Partial, $r->status);
            $this->assertFalse($r->evidence['historical_as_of_available']);
            $this->assertSame('NOT_AUTHORIZED', $r->evidence['prediction_use']);
            $this->assertSame('NOT_VERIFIED', $r->features['STAT_QUALITY']['STAT-01']['official_publication_as_of']);
        }
    }

    public static function fields(): array
    {
        return array_map(fn ($n) => [$n], range(5, 9));
    }

    #[DataProvider('times')]
    public function test_fetch_timing_is_not_publication_proof(?string $time, string $expected): void
    {
        $race = Fixture::change(Fixture::race(), 'STAT-01', 1,
            ['sourceFetchedAt' => $time === null ? null : new DateTimeImmutable($time)]);
        $result = $this->calculator()->calculate($race)[0];
        $this->assertSame($expected, $result->features['STAT_QUALITY']['STAT-01']['fetch_vs_scheduled_start']);
        $this->assertSame('ENTRY_ROW_NOT_ATTRIBUTE_SPECIFIC', $result->features['STAT_QUALITY']['STAT-01']['time_scope']);
        $this->assertFalse($result->evidence['historical_as_of_available']);
    }

    public static function times(): array
    {
        return [['2024-01-02T11:00:00+09:00', 'BEFORE'], ['2024-01-02T12:00:00+09:00', 'AT'],
            ['2024-01-02T12:00:01+09:00', 'AFTER'], [null, 'UNKNOWN']];
    }

    #[DataProvider('statuses')]
    public function test_missing_partial_invalid_and_not_applicable_are_not_zero_confidence(string $status): void
    {
        $race = Fixture::change(Fixture::race(), 'STAT-10', 1, ['status' => $status]);
        $r = $this->calculator()->calculate($race)[0];
        $counts = $r->features['SOURCE_RESULT_COVERAGE']['counts'];
        $this->assertSame(1, $counts[$status]);
        $this->assertNull($r->features['CONFIDENCE']);
        $this->assertSame($status === 'NOT_APPLICABLE' ? 14 : 15, $r->features['SOURCE_RESULT_COVERAGE']['applicable_stat_count']);
        $this->assertFalse($r->features['STAT_QUALITY']['STAT-10']['history_window_audit']['DAY_WINDOWS.365']['window_complete']);
        if ($status === 'INVALID_INPUT') {
            $this->assertSame(StatisticFeatureResultStatus::InvalidInput, $r->status);
            $this->assertContains('UPSTREAM_INVALID_INPUT:STAT-10', $r->evidence['blocked_reasons']);
        }
    }

    public static function statuses(): array
    {
        return array_map(fn ($s) => [$s], ['MISSING_INPUT', 'NO_HISTORY', 'PARTIAL_HISTORY', 'PARTIAL', 'INVALID_INPUT', 'NOT_APPLICABLE']);
    }

    public function test_unselected_sources_remain_pending_and_no_raw_link_is_inferred(): void
    {
        $race = Fixture::race();
        $race = new Stat20RaceInputDto(...array_replace(get_object_vars($race), [
            'sourceRuns' => ['STAT-01' => 1], 'sources' => array_values(array_filter($race->sources, fn ($s) => $s->statCode === 'STAT-01')),
        ]));
        $r = $this->calculator()->calculate($race)[0];
        $this->assertSame(14, $r->features['SOURCE_RESULT_COVERAGE']['counts']['DATA_SOURCE_PENDING']);
        $this->assertSame('DATA_SOURCE_PENDING', $r->features['STAT_QUALITY']['STAT-01']['original_fetch_log_raw_linkage']);
        $this->assertNull($r->features['CONFIDENCE']);
    }

    #[DataProvider('invalidSources')]
    public function test_identity_hash_version_run_and_cutoff_mixing_fail_closed(array $change): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->calculator()->calculate(Fixture::change(Fixture::race(), 'STAT-10', 1, $change));
    }

    public static function invalidSources(): array
    {
        return [[['playerId' => 900]], [['bikeNumber' => 9]], [['raceId' => 50]], [['raceEntryId' => 9999]],
            [['runId' => 999]], [['inputHash' => 'bad']], [['calculationVersion' => 'unaccepted']],
            [['status' => 'UNKNOWN']], [['qualityStatus' => 'UNKNOWN']],
            [['inputAsOf' => new DateTimeImmutable('2025-01-02T11:55:00+09:00')]]];
    }

    public function test_duplicate_rows_and_snapshot_bikes_are_rejected(): void
    {
        $race = Fixture::race();
        $this->expectException(UnexpectedValueException::class);
        $this->calculator()->calculate(new Stat20RaceInputDto(...array_replace(get_object_vars($race), [
            'sources' => [...$race->sources, $race->sources[0]],
        ])));
    }

    public function test_same_or_future_history_time_is_blocked_not_filled_with_zero(): void
    {
        $race = Fixture::race();
        $source = array_values(array_filter($race->sources, fn ($s) => $s->statCode === 'STAT-10'))[0];
        $race = Fixture::change($race, 'STAT-10', 1, ['evidence' => array_replace($source->evidence,
            ['history_max_at' => '2024-01-02T11:55:00+09:00'])]);
        $r = $this->calculator()->calculate($race)[0];
        $this->assertSame(StatisticFeatureResultStatus::InvalidInput, $r->status);
        $this->assertContains('HISTORY_NOT_STRICTLY_BEFORE_INPUT:STAT-10', $r->evidence['blocked_reasons']);
        $this->assertNull($r->features['CONFIDENCE']);
    }

    public function test_participant_snapshot_order_is_not_a_new_input_and_true_change_is_rejected(): void
    {
        $race = Fixture::race();
        $source = array_values(array_filter($race->sources, fn ($s) => $s->statCode === 'STAT-41'))[0];
        $reordered = Fixture::change($race, 'STAT-41', 1, ['evidence' => array_replace($source->evidence,
            ['participant_snapshot' => array_reverse($source->evidence['participant_snapshot'])])]);
        $this->assertSame(array_column($this->calculator()->calculate($race), 'inputHash'),
            array_column($this->calculator()->calculate($reordered), 'inputHash'));
        $participants = $source->evidence['participant_snapshot'];
        $participants[0]['stat01_input_hash'] = str_repeat('e', 64);
        $this->expectException(UnexpectedValueException::class);
        $this->calculator()->calculate(Fixture::change($race, 'STAT-41', 1, ['evidence' => array_replace($source->evidence,
            ['participant_snapshot' => $participants])]));
    }

    public function test_duplicate_snapshot_bikes_are_rejected_even_when_current_identity_matches(): void
    {
        $race = Fixture::change(Fixture::race(), 'STAT-01', 2, ['bikeNumber' => 1]);
        $identities = $race->entryIdentities;
        $identities[422]['bike_number'] = 1;
        $this->expectException(UnexpectedValueException::class);
        $this->calculator()->calculate(new Stat20RaceInputDto(...array_replace(get_object_vars($race), ['entryIdentities' => $identities])));
    }

    public function test_missing_identity_or_input_time_remains_missing_not_confident(): void
    {
        $race = Fixture::race();
        $sources = array_values(array_filter($race->sources, fn ($s) => $s->statCode === 'STAT-01'));
        $sources = array_map(fn ($s) => new Stat20SourceInputDto(...array_replace(get_object_vars($s),
            ['playerId' => null, 'inputAsOf' => null, 'evidence' => ['quality_reasons' => ['UNKNOWN_INPUT_TIME']]])), $sources);
        $identities = array_map(fn ($i) => array_replace($i, ['player_id' => null]), $race->entryIdentities);
        $race = new Stat20RaceInputDto(...array_replace(get_object_vars($race),
            ['sourceRuns' => ['STAT-01' => 1], 'sources' => $sources, 'entryIdentities' => $identities, 'scheduledStartAt' => null]));
        foreach ($this->calculator()->calculate($race) as $result) {
            $this->assertSame(StatisticFeatureResultStatus::MissingInput, $result->status);
            $this->assertSame(0, $result->features['RACE_ENTRY_COVERAGE']['resolved_player_count']);
            $this->assertSame('UNKNOWN', $result->features['STAT_QUALITY']['STAT-01']['fetch_vs_input_as_of']);
            $this->assertSame('UNKNOWN', $result->features['STAT_QUALITY']['STAT-01']['fetch_vs_scheduled_start']);
            $this->assertSame('MISSING_INPUT', $result->features['REQUIRED_INPUT_AUDIT']['player_id']);
            $this->assertSame('UNKNOWN', $result->features['REQUIRED_INPUT_AUDIT']['input_as_of']);
            $this->assertNull($result->features['CONFIDENCE']);
        }
    }

    public function test_invalid_declared_field_size_is_blocked_without_dropping_entries(): void
    {
        $race = Fixture::race();
        $source = $race->sources[0];
        $race = Fixture::change($race, 'STAT-01', 1, ['features' => array_replace($source->features, ['ENTRANT_COUNT' => 6])]);
        $results = $this->calculator()->calculate($race);
        $this->assertCount(7, $results);
        foreach ($results as $result) {
            $this->assertSame(StatisticFeatureResultStatus::InvalidInput, $result->status);
            $this->assertContains('ENTRY_COUNT_MISMATCH_OR_INVALID', $result->evidence['blocked_reasons']);
        }
    }

    #[DataProvider('invalidFieldValues')]
    public function test_one_invalid_field_size_type_is_not_coerced_into_the_valid_snapshot(mixed $value): void
    {
        $race = Fixture::race();
        $source = $race->sources[1];
        $race = Fixture::change($race, 'STAT-01', 2, ['features' => array_replace($source->features, ['ENTRANT_COUNT' => $value])]);
        $results = $this->calculator()->calculate($race);
        $this->assertCount(7, $results);
        foreach ($results as $result) {
            $this->assertSame(StatisticFeatureResultStatus::InvalidInput, $result->status);
            $this->assertContains('ENTRY_COUNT_MISMATCH_OR_INVALID', $result->evidence['blocked_reasons']);
        }
    }

    public static function invalidFieldValues(): array
    {
        return [['7'], [7.0], [null], [0], [10]];
    }

    public function test_order_independent_reproduction_and_other_subject_change_invalidate_all_hashes(): void
    {
        $race = Fixture::race();
        $first = $this->calculator()->calculate($race);
        $reordered = new Stat20RaceInputDto(...array_replace(get_object_vars($race), [
            'sources' => array_reverse($race->sources), 'entryIdentities' => array_reverse($race->entryIdentities, true),
            'sourceRuns' => array_reverse($race->sourceRuns, true),
        ]));
        $again = $this->calculator()->calculate($reordered);
        $this->assertSame(array_column($first, 'inputHash'), array_column($again, 'inputHash'));
        $this->assertSame(array_column($first, 'features'), array_column($again, 'features'));
        $changed = $this->calculator()->calculate(Fixture::change($race, 'STAT-10', 2, ['features' => ['CHANGED_INPUT' => 1]]));
        foreach ($first as $i => $r) {
            $this->assertNotSame($r->inputHash, $changed[$i]->inputHash);
        }
    }

    public function test_2026_synthetic_target_is_rejected_without_real_data(): void
    {
        $race = Fixture::race();
        $this->expectException(UnexpectedValueException::class);
        $this->calculator()->calculate(new Stat20RaceInputDto(...array_replace(get_object_vars($race), [
            'raceDate' => new DateTimeImmutable('2026-01-01'),
        ])));
    }

    public function test_streaming_quality_audit_in_independent_128m_php(): void
    {
        $p = new Process([PHP_BINARY, '-d', 'memory_limit=128M', dirname(__DIR__, 4).'/Support/stat20-memory.php'],
            dirname(__DIR__, 5), MemoryLimitedTestProcess::environment(), timeout: 120);
        $p->run();
        $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput().$p->getOutput());
        $r = json_decode($p->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('128M', $r['memory_limit']);
        $this->assertSame(6000, $r['races']);
        $this->assertSame(42000, $r['entries']);
        $this->assertGreaterThan(100 * 1024 * 1024, $r['input_bytes']);
        $this->assertLessThan(128 * 1024 * 1024, $r['peak_memory_bytes']);
    }

    public function test_postgresql_validation_sql_is_read_only_explicit_and_separates_unverified_quality(): void
    {
        $sql = file_get_contents(dirname(__DIR__, 5).'/docs/sql/validate_stat20.sql');
        $this->assertIsString($sql);
        foreach (['BEGIN READ ONLY', 'ROLLBACK', '\\if :{?stat20_run_id}', '\\quit 1',
            'STAT-20-existing-db-v1', 'duplicate_subjects', 'invented_points_or_confidence',
            'official_publication', 'raw_linkage', 'unfinished_items', 'NOT_AUTHORIZED',
            'blocked_reason', 'missing_or_conflicting_current_entry'] as $required) {
            $this->assertStringContainsString($required, $sql);
        }
        $this->assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE|ALTER|CREATE|TRUNCATE)\b/i', $sql);
        $this->assertStringNotContainsString('ORDER BY started_at DESC', $sql);
    }
}
