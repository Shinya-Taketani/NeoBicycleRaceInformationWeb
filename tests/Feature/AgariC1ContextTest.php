<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariC1Context\Builder;
use App\Domain\Keirin\Statistics\AgariC1Context\Contract;
use App\Domain\Keirin\Statistics\AgariC1Context\Extractor;
use App\Domain\Keirin\Statistics\AgariC1Context\Matcher;
use App\Domain\Keirin\Statistics\AgariC1Context\ReadOnlySession;
use App\Domain\Keirin\Statistics\AgariC1Context\Targets;
use App\Domain\Keirin\Statistics\AgariC1Input\Sources;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\AgariC1ContextFixture as F;
use Tests\Support\MemoryLimitedTestProcess;
use Tests\TestCase;

final class AgariC1ContextTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/agari-context-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        DB::shouldReceive('connection')->never();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
        parent::tearDown();
    }

    public function test_explicit_authorization_plan_offline_build_reproduction_and_not_auto_reviewed(): void
    {
        $property = new \ReflectionProperty(Sources::class, 'reviewedContextPins');
        $before = $property->getValue(new Sources);
        $this->assertSame([['bytes' => 248, 'sha256' => '7800bc94ed7a1d89e6bf1aee5a3bbd22d3d979dea01d511d4133214a08981268']], $before);
        $this->artisan('keirin:stat35:c1-context', ['mode' => 'plan'])->assertSuccessful();
        $this->artisan('keirin:stat35:c1-context', ['mode' => 'extract', '--output-dir' => $this->root.'/unauthorized'])->assertFailed();
        $this->assertDirectoryDoesNotExist($this->root.'/unauthorized');
        $pin = F::source($this->root.'/c1');
        $db = F::database();
        DB::shouldReceive('build')->once()->andReturn($db);
        $report = (new Extractor(new Targets($pin), new ReadOnlySession))->extract($this->root.'/c1', $this->root.'/extract', true, 3);
        $this->assertNull($db->getRawPdo());
        $this->assertSame(20, $report['entries']);
        $this->app->instance(Builder::class, new Builder($pin));
        $this->artisan('keirin:stat35:c1-context', ['mode' => 'build', '--extraction-dir' => $this->root.'/extract', '--output-dir' => $this->root.'/out'])->assertSuccessful();
        $this->artisan('keirin:stat35:c1-context', ['mode' => 'reproduce', '--extraction-dir' => $this->root.'/extract', '--original-dir' => $this->root.'/out', '--output-dir' => $this->root.'/repro'])->assertSuccessful();
        $this->assertSame(Contract::published($this->root.'/out', 'MAPPING'), Contract::published($this->root.'/repro', 'MAPPING'));
        $s = Files::json($this->root.'/out/summary.json');
        $this->assertSame(20, $s['total']['candidates']);
        $this->assertSame(0, $s['total']['held']);
        $this->assertSame('REVIEW_PENDING', $s['status']);
        $this->assertFalse($s['automatic_acceptance']);
        $this->assertFalse($s['historical_as_of_available']);
        $rows = iterator_to_array(Artifacts::lines($this->root.'/out/candidate/entry-context.jsonl'));
        $this->assertSame('000001', $rows[0]['external_player_id']);
        $this->assertNull($rows[0]['observed_at']);
        $this->assertSame('race_entries:1000011;races:100001;race_days:100001;race_meetings:100001', $rows[0]['source_record_id']);
        $this->assertSame(20, count(iterator_to_array(Artifacts::lines($this->root.'/out/mapping-audit.jsonl'))));
        $this->assertTrue(Files::json($this->root.'/repro/reproduction.json')['identical']);
        $this->assertSame($before, $property->getValue(new Sources));
        $this->assertSame($before, $property->getValue($this->app->make(Sources::class)));
        $this->assertSame(Sources::REVIEWED_CONTEXT_PINS, $before);
        $this->assertNotContains(Files::identity($this->root.'/out/candidate/manifest.json'), $before);
    }

    #[DataProvider('individualFailures')]
    public function test_individual_metadata_failures_hold_only_the_affected_entry(string $group, string $field, mixed $value, string $reason): void
    {
        [$race, $raw] = F::race();
        if ($group === 'target') {
            $race['entries'][0][$field] = $value;
        } elseif ($field === '*') {
            $raw[0][$group] = $value;
        } else {
            $raw[0][$group][$field] = $value;
        }
        $m = Matcher::race($race, $raw);
        $this->assertFalse($m[0]['candidate']);
        $this->assertContains($reason, $m[0]['reasons']);
        foreach (array_slice($m, 1) as $normal) {
            $this->assertTrue($normal['candidate']);
            $this->assertSame([], $normal['reasons']);
        }
    }

    public static function individualFailures(): iterable
    {
        yield ['entry', 'external_player_id', null, 'MISSING_EXTERNAL_PLAYER_ID'];
        yield ['entry', 'external_player_id', '12345', 'INVALID_EXTERNAL_PLAYER_ID'];
        yield ['entry', 'id', 999, 'ENTRY_ASSOCIATION_MISMATCH'];
        yield ['entry', 'race_id', 999, 'ENTRY_ASSOCIATION_MISMATCH'];
        yield ['entry', 'bike_number', 9, 'ENTRY_ASSOCIATION_MISMATCH'];
        yield ['race', 'id', 999, 'ENTRY_ASSOCIATION_MISMATCH'];
        yield ['entry', 'player_id', 999, 'PLAYER_ID_MISMATCH'];
        yield ['entry', 'player_id', null, 'MISSING_SAVED_PLAYER_ID'];
        yield ['target', 'player_id', null, 'MISSING_FIXED_PLAYER_ID'];
        yield ['race', 'race_date', '2024-08-02', 'RACE_DATE_MISMATCH'];
        yield ['meeting', 'id', 999, 'INVALID_MEETING_CONTEXT'];
        yield ['meeting', 'starts_on', '2024-07-31', 'MEETING_TARGET_MISMATCH'];
        yield ['meeting', 'ends_on', '2024-08-04', 'MEETING_TARGET_MISMATCH'];
        yield ['meeting', 'starts_on', null, 'INVALID_MEETING_CONTEXT'];
        yield ['race', 'race_type', 'UNKNOWN', 'UNKNOWN_RACE_CLASS'];
        yield ['race', 'source', 'other', 'SOURCE_MISMATCH'];
        yield ['entry', '*', null, 'DB_ENTRY_OR_RACE_MISSING_OR_OUT_OF_SCOPE'];
        yield ['day', '*', null, 'MISSING_RACE_DAY'];
        yield ['meeting', '*', null, 'MISSING_MEETING'];
    }

    public function test_duplicate_external_requires_valid_association_and_real_class_and_meeting_conflicts_block(): void
    {
        [$race, $raw] = F::race();
        $raw[0]['entry']['external_player_id'] = $raw[1]['entry']['external_player_id'];
        $m = Matcher::race($race, $raw);
        foreach ([0, 1] as $i) {
            $this->assertSame(['DUPLICATE_EXTERNAL_PLAYER_ID'], $m[$i]['reasons']);
        }
        $this->assertTrue($m[2]['candidate']);
        $raw[0]['entry']['race_id'] = 999;
        $m = Matcher::race($race, $raw);
        $this->assertNotContains('DUPLICATE_EXTERNAL_PLAYER_ID', $m[0]['reasons']);
        $this->assertTrue($m[1]['candidate']);
        [$race, $raw] = F::race();
        $raw[0]['race']['race_type'] = 'A級チャレンジ予選';
        foreach (Matcher::race($race, $raw) as $m) {
            $this->assertSame(['CONFLICTING_RACE_CONTEXT'], $m['reasons']);
            $this->assertFalse($m['candidate']);
        }
        [$race, $raw] = F::race();
        $race['entries'][0]['meeting_start'] = $raw[0]['meeting']['starts_on'] = '2024-07-31';
        foreach (Matcher::race($race, $raw) as $m) {
            $this->assertSame(['CONFLICTING_RACE_CONTEXT'], $m['reasons']);
        }
    }

    public function test_key_order_and_timing_do_not_turn_reconstruction_into_historical_evidence(): void
    {
        [$race, $raw] = F::race();
        $raw[0]['entry']['fetched_at'] = '2024-07-01 01:00:00+00';
        $a = Matcher::race($race, $raw);
        foreach ($raw as &$r) {
            foreach (Contract::COLUMNS as $alias => $_) {
                $r[$alias] = array_reverse($r[$alias], true);
            }
            $r = array_reverse($r, true);
        }
        unset($r);
        $this->assertSame($a, Matcher::race($race, $raw));
        $this->assertSame('2024-07-01 01:00:00+00', $a[0]['timing']['entry_fetched_at']);
        $this->assertFalse($a[0]['timing']['field_observation_time_verified']);
        $this->assertNull($a[0]['context']['observed_at']);
    }

    public function test_scoped_sql_retains_missing_joins_non_monotonic_order_and_reads_only_allowed_fields(): void
    {
        $pin = F::source($this->root.'/c1', 2);
        $db = F::database(2);
        $db->delete('DELETE FROM race_entries WHERE id=1000021');
        $db->delete('DELETE FROM race_days WHERE id=200002');
        $queries = [];
        $dispatcher = new Dispatcher;
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $db->setEventDispatcher($dispatcher);
        DB::shouldReceive('build')->once()->andReturn($db);
        (new Extractor(new Targets($pin), new ReadOnlySession))->extract($this->root.'/c1', $this->root.'/extract', true, 3);
        $raw = iterator_to_array(Artifacts::lines($this->root.'/extract/db-records.jsonl'));
        $this->assertSame(1000021, $raw[0]['entry_id']);
        $this->assertNull($raw[0]['entry']);
        $this->assertSame(1000011, $raw[5]['entry_id']);
        $this->assertNull($raw[10]['day']);
        $this->assertCount(40, $raw);
        $this->assertCount(40, array_unique(array_column($raw, 'entry_id')));
        $sqls = iterator_to_array(Artifacts::lines($this->root.'/extract/queries.jsonl'));
        $this->assertCount(14, $sqls);
        foreach ($sqls as $q) {
            $this->assertContains($q['sql'], $queries);
            $this->assertLessThanOrEqual(3, $q['count']);
            foreach (['2022-01-01', '2025-12-31', 'keirin_jp'] as $guard) {
                $this->assertContains($guard, $q['bindings']);
            }
            $this->assertDoesNotMatchRegularExpression('/\b(race_results|race_result_imports|race_payouts|players|rank|grade|labels|status|SELECT\s+\*)\b/i', $q['sql']);
            preg_match('/SELECT (.*?)\s+FROM/s', $q['sql'], $select);
            $expected = ['t.entry_id AS target_entry_id'];
            foreach (Contract::COLUMNS as $alias => $fields) {
                foreach ($fields as $field) {
                    $expected[] = $alias.'.'.$field.' AS '.$alias.'_'.$field;
                }
            }
            $this->assertSame(implode(', ', $expected), $select[1]);
        }
        $s = (new Builder($pin))->build($this->root.'/extract', $this->root.'/out');
        $this->assertSame(40, $s['total']['entries']);
        $this->assertSame(34, $s['total']['candidates']);
        $this->assertSame(6, $s['total']['held']);
        $this->assertSame(5, $s['years'][2023]['reasons']['MISSING_RACE_DAY']);
        $this->assertSame(1, $s['years'][2022]['reasons']['DB_ENTRY_OR_RACE_MISSING_OR_OUT_OF_SCOPE']);
    }

    public function test_duplicate_join_fails_preserving_partial_evidence_without_retry_or_complete(): void
    {
        $pin = F::source($this->root.'/c1');
        $db = F::database(primaryKeys: false);
        $db->insert('INSERT INTO race_days SELECT id,race_meeting_id,race_date FROM race_days WHERE id=200001');
        DB::shouldReceive('build')->once()->andReturn($db);
        try {
            (new Extractor(new Targets($pin), new ReadOnlySession))->extract($this->root.'/c1', $this->root.'/extract', true, 5);
            $this->fail('Duplicate join accepted.');
        } catch (RuntimeException $e) {
            $this->assertSame('Unexpected or duplicate database join.', $e->getMessage());
        }
        $this->assertNull($db->getRawPdo());
        $this->assertFileDoesNotExist($this->root.'/extract/COMPLETE.json');
        $this->assertFileExists($this->root.'/extract/db-records.jsonl.partial');
        $this->assertSame(5, Files::json($this->root.'/extract/FAILED.json')['completed_entries']);
    }

    public function test_read_only_test_database_rejects_writes_and_transaction_is_released(): void
    {
        $db = F::database();
        try {
            (new ReadOnlySession)->transaction($db, function (Connection $db): void {
                $db->delete('DELETE FROM race_entries');
            });
            $this->fail('Read only write allowed.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('readonly', $e->getMessage());
        }
        $this->assertSame(0, $db->transactionLevel());
        $this->assertSame(20, $db->table('race_entries')->count());
    }

    public function test_unconfirmed_read_only_endpoint_never_reaches_business_select(): void
    {
        $db = Mockery::mock(Connection::class);
        $db->shouldReceive('transactionLevel')->once()->andReturn(0);
        $db->shouldReceive('getDriverName')->twice()->andReturn('pgsql');
        $db->shouldReceive('statement')->with('SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY')->once()->andReturnTrue();
        $db->shouldReceive('statement')->with("SET lock_timeout = '5s'")->once()->andReturnTrue();
        $db->shouldReceive('statement')->with("SET statement_timeout = '5min'")->once()->andReturnTrue();
        $db->shouldReceive('selectOne')->once()->andReturn((object) ['database' => 'neo_keirin_prediction_db', 'schema' => 'public',
            'host' => '127.0.0.1', 'port' => 5432, 'session_read_only' => 'off', 'transaction_read_only' => 'off']);
        $db->shouldNotReceive('beginTransaction');
        $this->expectExceptionMessage('Export connection validation failed');
        (new ReadOnlySession)->transaction($db, fn () => $this->fail('Business callback was called.'));
    }

    public function test_unknown_year_is_rejected_before_database_connection(): void
    {
        $pin = F::source($this->root.'/c1', mutate: function (array &$race, array &$target): void {
            if ($race['year'] === 2025) {
                $target['entries'][0]['race_date'] = '2026-08-01';
            }
        });
        DB::shouldReceive('build')->never();
        $this->expectExceptionMessage('Invalid or unauthorized fixed target');
        (new Extractor(new Targets($pin), new ReadOnlySession))->extract($this->root.'/c1', $this->root.'/extract', true);
    }

    public function test_repeatable_read_and_snapshot_are_checked_before_work_and_rollback_on_failure(): void
    {
        $db = Mockery::mock(Connection::class);
        $db->shouldReceive('transactionLevel')->andReturn(0, 1);
        $db->shouldReceive('getDriverName')->twice()->andReturn('pgsql');
        foreach (['SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY', "SET lock_timeout = '5s'", "SET statement_timeout = '5min'"] as $sql) {
            $db->shouldReceive('statement')->with($sql)->once()->ordered()->andReturnTrue();
        }
        $db->shouldReceive('selectOne')->once()->ordered()->andReturn((object) ['database' => 'neo_keirin_prediction_db', 'schema' => 'public',
            'host' => '127.0.0.1', 'port' => '5432', 'session_read_only' => 'on', 'transaction_read_only' => 'on']);
        $db->shouldReceive('beginTransaction')->once()->ordered();
        $db->shouldReceive('statement')->with('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY')->once()->ordered()->andReturnTrue();
        $db->shouldReceive('selectOne')->once()->ordered()->andReturn((object) ['isolation' => 'repeatable read', 'snapshot_read_only' => 'on', 'snapshot' => '12:12:']);
        $db->shouldReceive('rollBack')->once()->ordered();
        $this->expectExceptionMessage('synthetic interrupted work');
        (new ReadOnlySession)->transaction($db, function (Connection $actual, array $settings) use ($db): void {
            $this->assertSame($db, $actual);
            $this->assertSame('12:12:', $settings['snapshot']);
            $this->assertSame('repeatable read', $settings['isolation']);
            throw new RuntimeException('synthetic interrupted work');
        });
    }

    #[DataProvider('sourceFailures')]
    public function test_bad_fixed_source_fails_before_any_database_access(string $kind): void
    {
        $pin = F::source($this->root.'/c1', mutate: function (array &$race, array &$target) use ($kind): void {
            match ($kind) {
                'bike' => $target['entries'][0]['bike'] = 9,
                'duplicate' => $race['entries'][1]['id'] = $race['entries'][0]['id'],
                'outcome' => $race['actual_rank'] = 1,
                default => null,
            };
        });
        if ($kind === 'seal') {
            file_put_contents($this->root.'/c1/history-2022.jsonl', "{}\n", FILE_APPEND);
        }
        DB::shouldReceive('build')->never();
        $this->expectException(RuntimeException::class);
        (new Extractor(new Targets($pin), new ReadOnlySession))->extract($this->root.'/c1', $this->root.'/extract', true);
    }

    public static function sourceFailures(): iterable
    {
        foreach (['bike', 'duplicate', 'outcome', 'seal'] as $kind) {
            yield [$kind];
        }
    }

    public function test_duplicate_and_race_conflict_counts_are_nonexclusive_but_held_is_unique(): void
    {
        $pin = F::source($this->root.'/c1');
        $db = F::database();
        $db->update("UPDATE race_entries SET external_player_id='000002' WHERE id=1000011");
        DB::shouldReceive('build')->once()->andReturn($db);
        (new Extractor(new Targets($pin), new ReadOnlySession))->extract($this->root.'/c1', $this->root.'/extract', true);
        // A sealed synthetic extraction with inconsistent copies of the same race tests conflict auditing.
        $rows = iterator_to_array(Artifacts::lines($this->root.'/extract/db-records.jsonl'));
        $rows[0]['race']['race_type'] = 'A級チャレンジ予選';
        file_put_contents($this->root.'/extract/db-records.jsonl', implode('', array_map(fn ($r) => Files::canonical($r)."\n", $rows)));
        $m = Files::json($this->root.'/extract/manifest.json');
        $m['files']['db-records.jsonl'] = Files::identity($this->root.'/extract/db-records.jsonl');
        file_put_contents($this->root.'/extract/manifest.json', Files::canonical($m)."\n");
        file_put_contents($this->root.'/extract/COMPLETE.json', Files::canonical(Files::identity($this->root.'/extract/manifest.json'))."\n");
        $s = (new Builder($pin))->build($this->root.'/extract', $this->root.'/out');
        $this->assertSame(5, $s['total']['held']);
        $this->assertSame(15, $s['total']['candidates']);
        $this->assertSame(['CONFLICTING_RACE_CONTEXT' => 5, 'DUPLICATE_EXTERNAL_PLAYER_ID' => 2], $s['years'][2022]['reasons']);
        $this->assertSame($s['years'][2022]['reasons'], $s['total']['reasons']);
        $audit = iterator_to_array(Artifacts::lines($this->root.'/out/mapping-audit.jsonl'));
        $this->assertCount(5, array_filter($audit, fn ($r) => ! $r['candidate']));
    }

    public function test_training_outcome_and_aggregate_are_not_identity_inputs(): void
    {
        $a = F::source($this->root.'/a');
        $b = F::source($this->root.'/b', mutate: function (array &$race): void {
            if ($race['year'] <= 2023) {
                foreach ($race['entries'] as &$entry) {
                    $entry['labels'] = [0, 0, 0];
                    $entry['rank'] = null;
                    $entry['status'] = 'DISQUALIFIED';
                }
            }
        });
        mkdir($this->root.'/ta');
        mkdir($this->root.'/tb');
        $sa = (new Targets($a))->prepare($this->root.'/a', $this->root.'/ta');
        $sb = (new Targets($b))->prepare($this->root.'/b', $this->root.'/tb');
        $this->assertNotSame($a, $b);
        $this->assertSame($sa['years'], $sb['years']);
        $this->assertSame($sa['target_seal'], $sb['target_seal']);
    }

    #[DataProvider('corruptions')]
    public function test_modified_unknown_or_overwritten_artifacts_are_rejected(string $kind): void
    {
        $pin = F::source($this->root.'/c1');
        DB::shouldReceive('build')->once()->andReturn(F::database());
        $extractor = new Extractor(new Targets($pin), new ReadOnlySession);
        $extractor->extract($this->root.'/c1', $this->root.'/extract', true);
        $builder = new Builder($pin);
        if ($kind === 'overwrite') {
            $builder->build($this->root.'/extract', $this->root.'/out');
        } elseif ($kind === 'modified') {
            file_put_contents($this->root.'/extract/db-records.jsonl', "{}\n", FILE_APPEND);
        } else {
            $m = Files::json($this->root.'/extract/manifest.json');
            $m['version'] = 'UNKNOWN';
            file_put_contents($this->root.'/extract/manifest.json', Files::canonical($m)."\n");
            file_put_contents($this->root.'/extract/COMPLETE.json', Files::canonical(Files::identity($this->root.'/extract/manifest.json'))."\n");
        }
        $this->expectException(RuntimeException::class);
        $builder->build($this->root.'/extract', $this->root.'/out');
    }

    public static function corruptions(): iterable
    {
        yield ['overwrite'];
        yield ['modified'];
        yield ['unknown'];
    }

    public function test_streamed_extraction_and_offline_reproduction_in_independent_128m_process(): void
    {
        if (MemoryLimitedTestProcess::delegate(__METHOD__)) {
            return;
        }
        $pin = F::source($this->root.'/c1', 2000);
        DB::shouldReceive('build')->once()->andReturn(F::database(2000));
        (new Extractor(new Targets($pin), new ReadOnlySession))->extract($this->root.'/c1', $this->root.'/extract', true);
        $builder = new Builder($pin);
        $s = $builder->build($this->root.'/extract', $this->root.'/out');
        $builder->build($this->root.'/extract', $this->root.'/repro', $this->root.'/out');
        $this->assertSame(40000, $s['total']['candidates']);
        $this->assertSame(8000, $s['total']['races']);
        $this->assertSame(Contract::published($this->root.'/out', 'MAPPING'), Contract::published($this->root.'/repro', 'MAPPING'));
        MemoryLimitedTestProcess::record(__METHOD__, memory_get_peak_usage(true));
    }
}
