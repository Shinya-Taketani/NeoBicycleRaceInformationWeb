<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Exporter;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AgariRaceRelativePostgresGuardTest extends TestCase
{
    private const ENDPOINT = ['database' => 'neo_keirin_prediction_db', 'schema' => 'public',
        'host' => '127.0.0.1', 'port' => 5432, 'session_read_only' => 'on', 'transaction_read_only' => 'on'];

    private const SNAPSHOT = ['isolation' => 'repeatable read', 'snapshot_read_only' => 'on', 'snapshot' => '100:100:'];

    private string $output;

    protected function setUp(): void
    {
        parent::setUp();
        $this->output = sys_get_temp_dir().'/agari-pg-guard-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->output)) {
            foreach (glob($this->output.'/*') as $file) {
                unlink($file);
            }
            rmdir($this->output);
        }
        parent::tearDown();
    }

    #[DataProvider('ports')]
    public function test_pg_branch_normalizes_port_and_ignores_column_order_using_same_read_only_snapshot(int|string $port): void
    {
        $observed = array_reverse(array_replace(self::ENDPOINT, ['port' => $port]), true);
        $observed['host_raw'] = '127.0.0.1/32';
        $observed['password'] = 'SECRET_MUST_NOT_BE_PUBLISHED';
        $db = $this->connection($observed);
        $this->snapshot($db, self::SNAPSHOT);
        $query = Mockery::mock(Builder::class);
        $db->shouldReceive('table')->once()->with('races as r')->ordered()->andReturn($query);
        $query->shouldReceive('leftJoin')->times(3)->andReturnSelf();
        $query->shouldReceive('where')->once()->with('r.source', 'keirin_jp')->andReturnSelf();
        $query->shouldReceive('where')->once()->with('r.id', '>', 0)->andReturnSelf();
        $query->shouldReceive('whereBetween')->once()->with('r.race_date', ['2022-01-01', '2025-12-31'])->andReturnSelf();
        $query->shouldReceive('orderBy')->once()->with('r.id')->andReturnSelf();
        $query->shouldReceive('limit')->once()->with(200)->andReturnSelf();
        $query->shouldReceive('get')->once()->andReturn(new Collection);
        $db->shouldReceive('rollBack')->once()->ordered();

        $manifest = app(Exporter::class)->export('2022-01-01', '2025-12-31', 200, $this->output);
        $this->assertSame(self::ENDPOINT + self::SNAPSHOT, $manifest['connection']);
        $this->assertSame(0, $manifest['race_count']);
        $this->assertSame(0, $manifest['result_count']);
        $this->assertEquals($manifest, Artifacts::input($this->output));
        $this->assertStringNotContainsString('SECRET', file_get_contents($this->output.'/manifest.json'));
        $path = 'app/Domain/Keirin/Statistics/AgariRaceRelative/ConnectionGuard.php';
        $this->assertSame(hash_file('sha256', base_path($path)), $manifest['code'][$path]);
    }

    public static function ports(): array
    {
        return [[5432], ['5432']];
    }

    #[DataProvider('badEndpoints')]
    public function test_pg_preflight_rejection_is_safe_diagnostic_before_business_queries_or_publication(string $field, mixed $actual, bool $missing = false): void
    {
        $observed = array_replace(self::ENDPOINT, [$field => $actual, 'password' => 'SECRET', 'url' => 'SECRET_URL', 'username' => 'SECRET_USER']);
        if ($missing) {
            unset($observed[$field]);
        }
        $db = $this->connection($observed, false);
        $db->shouldNotReceive('beginTransaction');
        $db->shouldNotReceive('statement');
        $db->shouldNotReceive('table');
        $db->shouldNotReceive('rollBack');
        $this->assertRejected($field, self::ENDPOINT[$field], $actual, $missing);
    }

    public static function badEndpoints(): iterable
    {
        foreach (['database' => 'other_db', 'schema' => 'other_schema', 'host' => '127.0.0.2', 'port' => 5433,
            'session_read_only' => 'off', 'transaction_read_only' => 'off'] as $field => $value) {
            yield $field.' mismatch' => [$field, $value];
            yield $field.' missing' => [$field, null, true];
            yield $field.' null' => [$field, null];
        }
        foreach ([true, false, '5432junk', '5432.0', 5432.0, '', ' 5432', '99999999999999999999999999999'] as $i => $value) {
            yield 'port malformed '.$i => ['port', $value];
        }
        foreach (['session_read_only', 'transaction_read_only'] as $field) {
            yield $field.' unknown' => [$field, 'unknown'];
            yield $field.' bool' => [$field, true];
        }
    }

    #[DataProvider('badSnapshots')]
    public function test_pg_snapshot_rejection_rolls_back_before_business_queries_or_publication(string $field, mixed $actual, bool $missing = false): void
    {
        $observed = array_replace(self::SNAPSHOT, [$field => $actual, 'password' => 'SECRET']);
        if ($missing) {
            unset($observed[$field]);
        }
        $db = $this->connection(self::ENDPOINT);
        $this->snapshot($db, $observed);
        $db->shouldNotReceive('table');
        $db->shouldReceive('rollBack')->once()->ordered();
        $this->assertRejected($field, self::SNAPSHOT[$field], $actual, $missing);
    }

    public static function badSnapshots(): array
    {
        return [['isolation', 'read committed'], ['isolation', null, true], ['isolation', null],
            ['snapshot_read_only', 'off'], ['snapshot_read_only', null, true], ['snapshot_read_only', 'unknown']];
    }

    public function test_database_exception_does_not_disclose_credentials_and_still_reports_peak(): void
    {
        DB::shouldReceive('connection')->once()->andThrow(new PDOException('password=SECRET user=SECRET_USER url=SECRET_URL'));
        $this->assertSame(1, Artisan::call('keirin:stat35:race-relative:export', $this->exportOptions()));
        $output = Artisan::output();
        $this->assertStringNotContainsString('SECRET', $output);
        $this->assertStringContainsString('Database export failed', $output);
        $this->assertStringContainsString('peak_memory_bytes', $output);
        $this->assertDirectoryDoesNotExist($this->output);
    }

    private function connection(array $observed, bool $transaction = true): Connection
    {
        $db = Mockery::mock(Connection::class);
        DB::shouldReceive('connection')->once()->andReturn($db);
        $db->shouldReceive('disableQueryLog')->once();
        $db->shouldReceive('transactionLevel')->times($transaction ? 2 : 1)->andReturn(0, 1);
        $db->shouldReceive('getDriverName')->andReturn('pgsql');
        $db->shouldReceive('selectOne')->once()->with(Mockery::on(function (string $sql): bool {
            return str_contains($sql, 'host(inet_server_addr()) AS host')
                && str_contains($sql, 'inet_server_port() AS port')
                && str_contains($sql, "current_setting('default_transaction_read_only') AS session_read_only")
                && str_contains($sql, "current_setting('transaction_read_only') AS transaction_read_only");
        }))->ordered()->andReturn((object) $observed);

        return $db;
    }

    private function snapshot(Connection $db, array $observed): void
    {
        $db->shouldReceive('beginTransaction')->once()->ordered();
        $db->shouldReceive('statement')->once()->with('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY')->ordered()->andReturn(true);
        $db->shouldReceive('selectOne')->once()->with(Mockery::on(fn (string $sql) => str_contains($sql, "current_setting('transaction_isolation') AS isolation")
            && str_contains($sql, "current_setting('transaction_read_only') AS snapshot_read_only")
            && str_contains($sql, 'pg_current_snapshot()::text AS snapshot')))->ordered()->andReturn((object) $observed);
    }

    private function exportOptions(): array
    {
        return ['--from' => '2022-01-01', '--to' => '2025-12-31', '--chunk' => 200, '--output-dir' => $this->output];
    }

    private function assertRejected(string $field, mixed $expected, mixed $actual, bool $missing): void
    {
        $this->assertSame(1, Artisan::call('keirin:stat35:race-relative:export', $this->exportOptions()));
        $output = Artisan::output();
        $diagnostic = [['field' => $field, 'expected' => $expected, 'actual' => $actual,
            'actual_type' => $missing ? 'MISSING' : get_debug_type($actual)]];
        $this->assertStringContainsString('Export connection validation failed: '.json_encode($diagnostic, JSON_THROW_ON_ERROR), $output);
        $this->assertStringNotContainsString('SECRET', $output);
        $this->assertStringNotContainsString('password', $output);
        $this->assertStringNotContainsString('username', $output);
        $last = substr(trim($output), strrpos(trim($output), "\n") + 1);
        $summary = json_decode($last, true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame('FAILED', $summary['status']);
        $this->assertIsInt($summary['peak_memory_bytes']);
        $this->assertGreaterThan(0, $summary['peak_memory_bytes']);
        $this->assertDirectoryDoesNotExist($this->output);
    }
}
