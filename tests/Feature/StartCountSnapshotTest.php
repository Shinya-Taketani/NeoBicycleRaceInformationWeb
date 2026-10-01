<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Audit\Stat35DataReadiness\RawReader;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Scraping\Exceptions\ParserException;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use App\Domain\Keirin\Statistics\StartCountSnapshot\Builder;
use App\Domain\Keirin\Statistics\StartCountSnapshot\Bundle;
use App\Domain\Keirin\Statistics\StartCountSnapshot\Contract;
use App\Domain\Keirin\Statistics\StartCountSnapshot\Exporter;
use App\Domain\Keirin\Statistics\StartCountSnapshot\Inspector;
use App\Domain\Keirin\Statistics\StartCountSnapshot\MetadataSource;
use App\Domain\Keirin\Statistics\StartCountSnapshot\Parser;
use App\Domain\Keirin\Statistics\StartObservation\Ledger;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Mockery;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\MemoryLimitedTestProcess;
use Tests\Support\StartCountFixture as F;
use Tests\Support\StartObservationFixture;
use Tests\TestCase;

class StartCountSnapshotTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/start-count-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public static function values(): array
    {
        return [
            [0, 'NUMERIC', 0], ['0', 'NUMERIC', 0], [15, 'NUMERIC', 15], ['003', 'NUMERIC', 3],
            [9999, 'NUMERIC', 9999], [null, 'NULL', null], ['', 'EMPTY_STRING', null], ['－', 'MISSING_SYMBOL', null],
            [-1, 'INVALID_FORMAT', null], ['-1', 'INVALID_FORMAT', null], [1.5, 'INVALID_FORMAT', null],
            [1.0, 'INVALID_FORMAT', null], ['1e2', 'INVALID_FORMAT', null], ['1.0', 'INVALID_FORMAT', null],
            [true, 'INVALID_FORMAT', null], [[], 'INVALID_FORMAT', null], [new \stdClass, 'INVALID_FORMAT', null],
            ['99999999999999999999999999', 'OUT_OF_RANGE', null], [10000, 'OUT_OF_RANGE', null],
            ['１２', 'INVALID_FORMAT', null], [' 1', 'INVALID_FORMAT', null], ['-', 'INVALID_FORMAT', null],
        ];
    }

    #[DataProvider('values')]
    public function test_typed_counts_without_aliases(mixed $value, string $status, ?int $number): void
    {
        $page = F::page();
        $page['PJ0315']['sensyuTypeInfo'][0]['stTori'] = $value;
        $row = (new Parser)->parse(F::html($page), F::target())['rows'][0];
        $this->assertSame($status, $row['field']['status']);
        $this->assertEquals($value, $row['field']['raw']);
        $this->assertSame(get_debug_type($value), $row['field']['type']);
        $this->assertSame($number, $row['displayed_start_count']);
        $this->assertSame('000001', $row['observed_external_id']);
    }

    public function test_missing_null_empty_object_and_key_order_remain_distinct(): void
    {
        $p = new Parser;
        $hashes = [];
        foreach (['MISSING', null, '', [], (object) []] as $value) {
            $page = F::page();
            if ($value === 'MISSING') {
                unset($page['PJ0315']['sensyuTypeInfo'][0]['stTori']);
            } else {
                $page['PJ0315']['sensyuTypeInfo'][0]['stTori'] = $value;
            }
            $a = $p->parse(F::html($page), F::target());
            $page['PJ0315']['sensyuTypeInfo'][0] = array_reverse($page['PJ0315']['sensyuTypeInfo'][0], true);
            $b = $p->parse(F::html($page), F::target());
            $this->assertSame($a['rows'][0]['value_signature'], $b['rows'][0]['value_signature']);
            $hashes[] = $a['rows'][0]['value_signature'];
        }
        $this->assertCount(5, array_unique($hashes));
    }

    public function test_three_way_identity_faults_do_not_destroy_unaffected_rows(): void
    {
        $page = F::page();
        $page['PJ0315']['sensyuTypeInfo'][0]['sensyuRegistNo'] = '123456';
        $page['PC0201']['C0201data']['C0201racedtl']['C0201sensyu'][1]['numPlayer'] = '999999';
        $rows = (new Parser)->parse(F::html($page), F::target())['rows'];
        $this->assertContains('LEDGER_IDENTITY_MISMATCH', $rows[0]['identity_issues']);
        $this->assertContains('PC0201_PJ0315_IDENTITY_MISMATCH', $rows[1]['identity_issues']);
        $this->assertNull($rows[0]['displayed_start_count']);
        $this->assertNull($rows[1]['displayed_start_count']);
        $this->assertSame(3, $rows[2]['displayed_start_count']);
        $page['PC0201']['C0201data']['selKaisai'] = '20240102';
        $rows = (new Parser)->parse(F::html($page), F::target())['rows'];
        $this->assertSame([null, null, null, null, null], array_column($rows, 'displayed_start_count'));
    }

    public function test_duplicate_extra_missing_invalid_entries_and_unknown_schema_are_audited(): void
    {
        $page = F::page();
        $page['PJ0315']['sensyuTypeInfo'][1] = $page['PJ0315']['sensyuTypeInfo'][0];
        $page['PJ0315']['sensyuTypeInfo'][2]['syaban'] = '9';
        $page['PJ0315']['sensyuTypeInfo'][3]['syaban'] = '10';
        $out = (new Parser)->parse(F::html($page), F::target());
        $this->assertContains('DUPLICATE_BIKE', $out['rows'][0]['identity_issues']);
        $this->assertContains('DUPLICATE_EXTERNAL_ID', $out['rows'][1]['identity_issues']);
        $this->assertContains('EXTRA_OR_INVALID_ENTRY', $out['rows'][2]['identity_issues']);
        $this->assertContains('INVALID_BIKE', $out['rows'][3]['identity_issues']);
        $this->assertSame([2, 3, 4], $out['missing_bikes']);
        $this->assertSame(5, $out['rows'][4]['displayed_start_count']);
        $page['PJ0315'] = (object) [];
        $out = (new Parser)->parse(F::html($page), F::target());
        $this->assertContains('UNSUPPORTED_SCHEMA', $out['issues']);
        $this->assertSame([], $out['rows']);
    }

    public function test_outcomes_do_not_affect_values_or_selection(): void
    {
        $a = (new Parser)->parse(F::html(), F::target());
        $page = F::page();
        $page['PJ0326'] = ['rank' => 'DO_NOT_USE', 'payout' => 99999];
        $page['PJ0315']['sensyuTypeInfo'][0]['rank'] = 9;
        $this->assertSame($a, (new Parser)->parse(F::html($page), F::target()));
        $this->assertFalse(Contract::plan()['historical_as_of_available']);
        $this->assertNull(Contract::plan()['aggregation_period']);
        $this->assertSame('NOT_AUTHORIZED', Contract::plan()['prediction_use']);
    }

    public function test_offline_build_reproduce_all_versions_and_no_candidates(): void
    {
        $ledger = F::bundle($this->root);
        DB::shouldReceive('connection')->never();
        $builder = new Builder($ledger, new Parser, new RawReader);
        $first = $builder->build($this->root.'/source', $this->root.'/build');
        $second = $builder->build($this->root.'/source', $this->root.'/reproduce', $this->root.'/build');
        $this->assertSame($first['manifest'], $second['manifest']);
        $this->assertTrue($second['independent_reproduction']);
        $year = $first['years'][2024];
        $this->assertSame(10, $year['snapshot_rows']);
        $this->assertSame(5, $year['unique_matched_entries']);
        $this->assertSame(0, $year['entries_with_multiple_value_versions']);
        $this->assertSame(2, $year['zero']);
        foreach (Artifacts::lines($this->root.'/build/snapshots-2024.jsonl') as $row) {
            $this->assertNull($row['aggregation_period']);
            $this->assertFalse($row['historical_as_of_available']);
            $this->assertSame('NOT_AUTHORIZED', $row['prediction_use']);
        }
        Http::assertNothingSent();
        $empty = $this->root.'/empty';
        mkdir($empty);
        $ledger = F::bundle($empty, versions: 0);
        $result = (new Builder($ledger, new Parser, new RawReader))->build($empty.'/source', $empty.'/build');
        $this->assertSame(1, $result['years'][2024]['no_candidate_races']);
        $this->assertSame(0, $result['years'][2024]['snapshot_rows']);
    }

    public function test_real_value_and_type_changes_make_separate_semantic_versions(): void
    {
        $p = new Parser;
        $page = F::page();
        $signatures = [];
        foreach ([0, '0', 1] as $value) {
            $page['PJ0315']['sensyuTypeInfo'][0]['stTori'] = $value;
            $signatures[] = $p->parse(F::html($page), F::target())['rows'][0]['value_signature'];
        }
        $this->assertCount(3, array_unique($signatures));
    }

    public function test_raw_drift_prevents_publication(): void
    {
        $ledger = F::bundle($this->root);
        file_put_contents($this->root.'/detail.html', str_replace('"stTori":0', '"stTori":1', F::html()));
        try {
            (new Builder($ledger, new Parser, new RawReader))->build($this->root.'/source', $this->root.'/build');
            $this->fail('Raw drift accepted.');
        } catch (RuntimeException $e) {
            $this->assertFileDoesNotExist($this->root.'/build/COMPLETE.json');
            $this->assertFileExists($this->root.'/build/failure.json');
        }
    }

    public function test_overwrite_and_unbound_source_are_rejected(): void
    {
        $ledger = F::bundle($this->root);
        $builder = new Builder($ledger, new Parser, new RawReader);
        mkdir($this->root.'/build');
        try {
            $builder->build($this->root.'/source', $this->root.'/build');
            $this->fail('Overwrite accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('new output', $e->getMessage());
        }
        $rows = iterator_to_array(Artifacts::lines($this->root.'/source/sources.jsonl'));
        $rows[0]['request_parameters']['encp'] = 'unbound';
        file_put_contents($this->root.'/source/sources.jsonl', implode('', array_map(fn ($r) => Files::canonical($r)."\n", $rows)));
        $this->reseal('sources.jsonl');
        $this->expectExceptionMessage('Unbound PJ0315 request');
        $builder->build($this->root.'/source', $this->root.'/bad');
    }

    public function test_2026_is_rejected_before_database_and_raw_access(): void
    {
        $target = F::target();
        $target['race']['race_date'] = '2026-01-01';
        try {
            Bundle::target($target + ['imports' => []]);
            $this->fail('2026 accepted');
        } catch (RuntimeException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->expectException(RuntimeException::class);
        (new RawReader)->read(['race_date' => '2026-01-01', 'absolute_path' => '/DO_NOT_READ']);
    }

    public function test_sql_scope_is_only_approved_metadata_and_all_versions(): void
    {
        $sql = implode("\n", MetadataSource::queries());
        $this->assertStringNotContainsString('SELECT *', $sql);
        foreach (['race_results', 'race_entries', 'race_payouts', 'players', 'MAX(', 'FOR UPDATE', 'LIMIT 1'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $sql);
        }
        $this->assertStringContainsString('r.id=s.race_id AND r.race_date=s.race_date', $sql);
        $this->assertStringContainsString("f.request_parameters->>'disp'='PJ0315'", $sql);
        $this->assertStringContainsString("f.request_parameters->>'encp'=k.encp", $sql);
    }

    public function test_read_only_failure_closes_connection_before_business_selects(): void
    {
        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('exec')->times(3)->andReturn(0);
        $statement = Mockery::mock(\PDOStatement::class);
        $statement->shouldReceive('fetch')->once()->with(PDO::FETCH_ASSOC)->andReturn([
            'database' => 'neo_keirin_prediction_db', 'schema' => 'public', 'host' => '127.0.0.1', 'port' => 5432,
            'session_read_only' => 'off', 'transaction_read_only' => 'off',
        ]);
        $pdo->shouldReceive('query')->once()->with(Mockery::on(fn ($sql) => str_starts_with($sql, 'SELECT current_database()') && str_contains($sql, 'host(inet_server_addr()) AS host')))->andReturn($statement);
        $pdo->shouldReceive('prepare')->never();
        $pdo->shouldReceive('inTransaction')->once()->andReturn(false);
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('disableQueryLog')->once();
        $connection->shouldReceive('setReconnector')->once()->with(Mockery::on(function ($callback) {
            try {
                $callback();
            } catch (RuntimeException $e) {
                return $e->getMessage() === 'Metadata reconnect forbidden.';
            }

            return false;
        }));
        $connection->shouldReceive('getPdo')->once()->andReturn($pdo);
        $connection->shouldReceive('disconnect')->once();
        DB::shouldReceive('build')->once()->andReturn($connection);
        $this->expectExceptionMessage('Export connection validation failed');
        (new MetadataSource)->capture('[]', function ($name, $rows) {
            $this->assertSame('connection-precheck.jsonl', $name);
            $this->assertSame('off', $rows[0]['session_read_only']);
        });
    }

    public function test_plan_needs_no_database_http_or_raw(): void
    {
        $this->artisan('keirin:stat36:start-count')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_large_ledger_build_in_independent_128m_process(): void
    {
        $result = MemoryLimitedTestProcess::launch([PHP_BINARY, '-d', 'memory_limit=128M',
            base_path('tests/Support/start-count-memory.php'), $this->root], $this->root, timeout: 180);
        $this->assertSame(0, $result['exit_code'], file_get_contents($this->root.'/stderr.log'));
        $measurement = Files::json($this->root.'/measurement.json');
        $this->assertSame('128M', $measurement['limit']);
        $this->assertGreaterThan(100 * 1024 * 1024, $measurement['ledger_bytes']);
        $this->assertSame(15000, $measurement['rows']);
    }

    public function test_read_only_snapshot_cursors_and_disconnect_on_success(): void
    {
        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('exec')->times(7)->andReturn(0);
        $endpoint = Mockery::mock(\PDOStatement::class);
        $endpoint->shouldReceive('fetch')->once()->with(PDO::FETCH_ASSOC)->andReturn([
            'database' => 'neo_keirin_prediction_db', 'schema' => 'public', 'host' => '127.0.0.1', 'port' => 5432,
            'session_read_only' => 'on', 'transaction_read_only' => 'on',
        ]);
        $snapshot = Mockery::mock(\PDOStatement::class);
        $snapshot->shouldReceive('fetch')->once()->with(PDO::FETCH_ASSOC)->andReturn([
            'isolation' => 'repeatable read', 'snapshot_read_only' => 'on', 'snapshot' => '1:2:',
        ]);
        $page = Mockery::mock(\PDOStatement::class);
        $page->shouldReceive('fetchAll')->times(3)->with(PDO::FETCH_ASSOC)->andReturn([]);
        $pdo->shouldReceive('query')->once()->with(Mockery::on(fn ($s) => str_starts_with($s, 'SELECT current_database()') && str_contains($s, 'host(inet_server_addr()) AS host')))->andReturn($endpoint);
        $pdo->shouldReceive('query')->once()->with(Mockery::on(fn ($s) => str_starts_with($s, "SELECT current_setting('transaction_isolation')")))->andReturn($snapshot);
        $pdo->shouldReceive('query')->times(3)->with('FETCH FORWARD 100 FROM start_count_metadata')->andReturn($page);
        $cursor = Mockery::mock(\PDOStatement::class);
        $cursor->shouldReceive('execute')->times(3)->with(['[]'])->andReturn(true);
        foreach (MetadataSource::queries() as $sql) {
            $pdo->shouldReceive('prepare')->once()->with('DECLARE start_count_metadata NO SCROLL CURSOR FOR '.$sql)->andReturn($cursor);
        }
        $pdo->shouldReceive('inTransaction')->once()->andReturn(true);
        $pdo->shouldReceive('rollBack')->once()->andReturn(true);
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('disableQueryLog')->once();
        $connection->shouldReceive('setReconnector')->once();
        $connection->shouldReceive('getPdo')->once()->andReturn($pdo);
        $connection->shouldReceive('disconnect')->once();
        DB::shouldReceive('build')->once()->andReturn($connection);
        $names = [];
        $audit = (new MetadataSource)->capture('[]', function ($name, $rows) use (&$names) {
            $names[] = $name;
            if ($name === 'connection-precheck.jsonl') {
                $this->assertSame('on', $rows[0]['session_read_only']);
            } else {
                $this->assertSame([], iterator_to_array($rows));
            }
        });
        $this->assertSame(['connection-precheck.jsonl', ...array_keys(MetadataSource::queries())], $names);
        $this->assertSame('repeatable read', $audit['snapshot']['isolation']);
        $this->assertSame(0, $audit['business_writes']);
    }

    public function test_export_rejects_2026_before_opening_metadata_connection(): void
    {
        StartObservationFixture::bundle($this->root.'/ledger', versions: 0);
        $path = $this->root.'/ledger/database-inventory.jsonl';
        $row = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $row['race']['race_date'] = '2026-01-01';
        unlink($path);
        unlink($path.'.manifest.json');
        JsonlArtifact::write($path, [$row]);
        $pin = StartObservationFixture::seal($this->root.'/ledger');
        $source = Mockery::mock(MetadataSource::class);
        $source->shouldNotReceive('capture');
        $this->expectException(RuntimeException::class);
        (new Exporter(new Ledger($pin), $source))->export($this->root.'/ledger', $this->root.'/export');
    }

    public function test_small_inspection_uses_first_fetch_only_without_losing_target(): void
    {
        $ledger = F::bundle($this->root);
        $r = (new Inspector($ledger, new RawReader, new Parser))
            ->inspect($this->root.'/source', $this->root.'/inspect');
        $this->assertSame(1, $r['samples']);
        $this->assertSame(0, $r['missing']);
        $sample = Files::json($this->root.'/inspect/samples.json')['races'][10];
        $this->assertSame(1, $sample['fetch_log_id']);
        $this->assertContains('S', $sample['headers']);
        $this->assertSame(0, $sample['parsed']['rows'][0]['displayed_start_count']);
    }

    public function test_invalid_json_is_fatal_and_not_a_missing_count(): void
    {
        $this->expectException(ParserException::class);
        (new Parser)->parse(str_replace('"stTori":0', '"stTori":broken', F::html()), F::target());
    }

    private function reseal(string $name): void
    {
        $path = $this->root.'/source';
        $m = Files::json($path.'/manifest.json');
        $m['files'][$name] = Files::identity($path.'/'.$name);
        file_put_contents($path.'/manifest.json', Files::canonical($m)."\n");
        file_put_contents($path.'/COMPLETE.json', Files::canonical(Files::identity($path.'/manifest.json'))."\n");
    }
}
