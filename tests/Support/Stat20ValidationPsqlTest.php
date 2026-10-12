<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

// Run explicitly in the isolated test environment; never use Laravel DB configuration.
class Stat20ValidationPsqlTest extends TestCase
{
    private const BIN = '/usr/lib/postgresql/18/bin/';

    private const TABLES = ['statistic_feature_runs', 'races', 'race_entries', 'statistic_feature_results', 'statistic_feature_run_items'];

    private static ?CompositionResultTemporaryDirectory $temporary = null;

    private static bool $started = false;

    private static bool $failed = false;

    private static int $sequence = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$temporary = CompositionResultTemporaryDirectory::create('/tmp');
        $root = self::$temporary->path();
        try {
            foreach (['data', 'socket'] as $name) {
                if (! mkdir($root.'/'.$name, 0700)) {
                    throw new RuntimeException('Cannot create recorded synthetic PostgreSQL directory.');
                }
            }
            self::requireSuccess(self::execute([self::BIN.'initdb', '-D', $root.'/data',
                '-U', 'stat20_synthetic', '--auth=trust', '--no-locale', '--encoding=UTF8', '--no-clean', '--no-sync'], 'initdb'));
            self::$started = true;
            self::requireSuccess(self::execute([self::BIN.'pg_ctl', '-D', $root.'/data', '-l', $root.'/server.log',
                '-o', "-h '' -k {$root}/socket -p 55432 -c unix_socket_permissions=0700 -c shared_buffers=8MB -c max_connections=10",
                '-w', '-t', '10', 'start'], 'start'));
            $fixture = file_get_contents(dirname(__DIR__).'/Fixtures/Keirin/Statistics/stat20-validation-synthetic.sql');
            if ($fixture === false) {
                throw new RuntimeException('Synthetic SQL fixture unavailable.');
            }
            self::requireSuccess(self::execute(self::psql(), 'fixture-base',
                "CREATE SCHEMA fixture_base; SET search_path=fixture_base,pg_catalog;\n".$fixture));
        } catch (Throwable $e) {
            self::$failed = true;
            self::retireCluster();
            throw $e;
        }
    }

    protected function tearDown(): void
    {
        try {
            self::$failed = self::$failed || ! $this->status()->isSuccess();
        } finally {
            parent::tearDown();
        }
    }

    public static function tearDownAfterClass(): void
    {
        try {
            self::retireCluster();
        } finally {
            parent::tearDownAfterClass();
        }
    }

    #[DataProvider('runs')]
    public function test_psql_validates_structure_without_rejecting_unverified_quality(string $change, ?string $runId, bool $valid): void
    {
        $schema = self::scenario($change);
        $before = self::snapshot($schema, 'before');
        $command = [...self::psql(), '--set=stat20_validation_ok=true',
            '--file='.dirname(__DIR__, 2).'/docs/sql/validate_stat20.sql'];
        if ($runId !== null) {
            $command[] = '--set=stat20_run_id='.$runId;
        }
        $p = self::execute($command, $schema.'-validate', schema: $schema);
        if ($valid) {
            $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput().$p->getOutput());
            $this->assertSame('', $p->getErrorOutput());
            $this->assertStringContainsString('DEGRADED', $p->getOutput());
            $this->assertStringContainsString('DATA_SOURCE_PENDING', $p->getOutput());
        } else {
            $this->assertNotSame(0, $p->getExitCode(), 'Invalid run exited successfully: '.$this->dataName());
            $this->assertStringContainsString('ERROR', $p->getErrorOutput());
        }
        $this->assertSame($before, self::snapshot($schema, 'after'), 'Validation must not change synthetic inputs.');
    }

    public function test_second_validation_in_the_same_psql_session_does_not_reuse_the_success_variable(): void
    {
        $schema = self::scenario('');
        $before = self::snapshot($schema, 'before');
        $file = dirname(__DIR__, 2).'/docs/sql/validate_stat20.sql';
        $p = self::execute(self::psql(), $schema.'-validate', "\\set stat20_run_id 2\n\\i {$file}\n\\set stat20_run_id 999\n\\i {$file}\n", $schema);
        $this->assertSame(3, $p->getExitCode(), $p->getErrorOutput());
        $this->assertStringContainsString('DEGRADED', $p->getOutput());
        $this->assertStringContainsString('ERROR', $p->getErrorOutput());
        $this->assertSame($before, self::snapshot($schema, 'after'));
    }

    public static function runs(): array
    {
        return [
            'success' => ["UPDATE statistic_feature_runs SET status='SUCCEEDED' WHERE id=2;", '2', true],
            'partial-with-pending-quality' => ['', '2', true],
            'bounded-date-range' => ["UPDATE statistic_feature_runs SET target_race_id=NULL,target_from='2024-01-02',target_to='2024-01-02' WHERE id=2;", '2', true],
            'missing-input-and-unresolved-player' => ["UPDATE statistic_feature_results SET status='MISSING_INPUT',player_id=NULL WHERE race_entry_id=1; UPDATE race_entries SET player_id=NULL WHERE id=1;", '2', true],
            'unknown-input-time' => ["UPDATE statistic_feature_results SET status='MISSING_INPUT',input_as_of=NULL;", '2', true],
            'partial-root' => ["UPDATE statistic_feature_runs SET status='PARTIALLY_SUCCEEDED' WHERE id=1;", '2', true],
            'no-history' => ["UPDATE statistic_feature_results SET status='NO_HISTORY' WHERE id=101;", '2', true],
            'invalid-quality-is-not-structural-failure' => ["UPDATE statistic_feature_results SET status='INVALID_INPUT' WHERE id=101;", '2', true],
            'missing-id' => ['', null, false],
            'absent-id-with-stale-ok-variable' => ['', '999', false],
            'wrong-stat' => ["UPDATE statistic_feature_runs SET stat_code='STAT-10' WHERE id=2;", '2', false],
            'wrong-version' => ["UPDATE statistic_feature_runs SET calculation_version='unsupported' WHERE id=2;", '2', false],
            'running' => ["UPDATE statistic_feature_runs SET status='RUNNING' WHERE id=2;", '2', false],
            'failed' => ["UPDATE statistic_feature_runs SET status='FAILED' WHERE id=2;", '2', false],
            'unfinished' => ['UPDATE statistic_feature_runs SET finished_at=NULL WHERE id=2;', '2', false],
            'race-count' => ['UPDATE statistic_feature_runs SET processed_race_count=0 WHERE id=2;', '2', false],
            'error-count' => ['UPDATE statistic_feature_runs SET error_count=1 WHERE id=2;', '2', false],
            'recorded-entry-count' => ['UPDATE statistic_feature_runs SET target_entry_count=8 WHERE id=2;', '2', false],
            'missing-result' => ['DELETE FROM statistic_feature_results WHERE id=101;', '2', false],
            'missing-item' => ['DELETE FROM statistic_feature_run_items;', '2', false],
            'unfinished-item' => ['UPDATE statistic_feature_run_items SET finished_at=NULL;', '2', false],
            'failed-item' => ["UPDATE statistic_feature_run_items SET status='FAILED';", '2', false],
            'running-item' => ["UPDATE statistic_feature_run_items SET status='RUNNING';", '2', false],
            'wrong-item-result-count' => ['UPDATE statistic_feature_run_items SET feature_result_count=6;', '2', false],
            'unrelated-item-race' => ['UPDATE statistic_feature_run_items SET race_id=20;', '2', false],
            'wrong-result-stat' => ["UPDATE statistic_feature_results SET stat_code='STAT-10' WHERE id=101;", '2', false],
            'wrong-result-version' => ["UPDATE statistic_feature_results SET calculation_version='unsupported' WHERE id=101;", '2', false],
            'wrong-subject' => ["UPDATE statistic_feature_results SET subject_type='RACE' WHERE id=101;", '2', false],
            'wrong-subject-key' => ["UPDATE statistic_feature_results SET subject_key='wrong' WHERE id=101;", '2', false],
            'null-entry-id' => ['UPDATE statistic_feature_results SET race_entry_id=NULL WHERE id=101;', '2', false],
            'wrong-entry-race' => ['UPDATE race_entries SET race_id=20 WHERE id=1;', '2', false],
            'wrong-result-player' => ['UPDATE statistic_feature_results SET player_id=900 WHERE id=101;', '2', false],
            'wrong-result-bike' => ['UPDATE statistic_feature_results SET bike_number=9 WHERE id=101;', '2', false],
            'missing-race' => ['DELETE FROM races WHERE id=10;', '2', false],
            'wrong-result-race' => ['UPDATE statistic_feature_results SET race_id=20 WHERE id=101;', '2', false],
            'incomplete-root-run' => ['UPDATE statistic_feature_runs SET target_entry_count=14 WHERE id=1;', '2', false],
            'source-lineage-mismatch' => ["UPDATE statistic_feature_results SET evidence=evidence || '{\"stat01_run_id\":999}' WHERE id=101;", '2', false],
            'source-hash-mismatch' => ["UPDATE statistic_feature_results SET evidence=evidence || '{\"stat01_input_hash\":\"wrong\"}' WHERE id=101;", '2', false],
            'duplicate-item' => ['INSERT INTO statistic_feature_run_items SELECT 2,feature_run_id,race_id,status,feature_result_count,finished_at FROM statistic_feature_run_items;', '2', false],
            'duplicate-result-with-matching-counts' => ["INSERT INTO statistic_feature_results SELECT (jsonb_populate_record(NULL::statistic_feature_results,to_jsonb(r)||'{\"id\":999,\"subject_key\":\"duplicate\"}')).* FROM statistic_feature_results r WHERE id=101; UPDATE statistic_feature_runs SET target_entry_count=8 WHERE id=2; UPDATE statistic_feature_run_items SET feature_result_count=8;", '2', false],
            'conflicting-target' => ['UPDATE statistic_feature_runs SET target_race_id=20 WHERE id=2;', '2', false],
            'invented-confidence' => ['UPDATE statistic_feature_results SET confidence=0.5 WHERE id=101;', '2', false],
            'invalid-output-hash' => ["UPDATE statistic_feature_results SET input_hash='invalid' WHERE id=101;", '2', false],
        ];
    }

    private static function scenario(string $change): string
    {
        $schema = 'case_'.++self::$sequence;
        $sql = "CREATE SCHEMA {$schema}; SET search_path={$schema},pg_catalog;\n";
        foreach (self::TABLES as $table) {
            $sql .= "CREATE TABLE {$table} (LIKE fixture_base.{$table} INCLUDING ALL); INSERT INTO {$table} SELECT * FROM fixture_base.{$table};\n";
        }
        self::requireSuccess(self::execute(self::psql(), $schema.'-setup', $sql.$change));

        return $schema;
    }

    private static function snapshot(string $schema, string $suffix): string
    {
        $sql = "SHOW transaction_read_only;\n";
        foreach (self::TABLES as $table) {
            $sql .= "SELECT md5(COALESCE(jsonb_agg(to_jsonb(t) ORDER BY id)::text,'')) FROM {$table} t;\n";
        }
        $p = self::execute([...self::psql(), '-At'], $schema.'-'.$suffix, $sql, $schema);
        self::requireSuccess($p);
        if (! str_starts_with($p->getOutput(), "on\n")) {
            throw new RuntimeException('Synthetic validation connection must be read only.');
        }

        return $p->getOutput();
    }

    private static function psql(): array
    {
        return [self::BIN.'psql', '-X', '-w', '--host='.self::$temporary->path().'/socket',
            '--port=55432', '--username=stat20_synthetic', '--dbname=postgres', '--set=ON_ERROR_STOP=1'];
    }

    private static function execute(array $command, string $label, ?string $sql = null, ?string $schema = null): Process
    {
        $root = self::$temporary->path();
        self::$temporary->assertRecordedPath($root);
        $options = $schema === null ? '' : '-c default_transaction_read_only=on -c search_path='.$schema.',pg_catalog -c statement_timeout=10s';
        $p = new Process($command, dirname(__DIR__, 2), MemoryLimitedTestProcess::environment([
            'PGOPTIONS' => $options, 'PGPASSFILE' => $root.'/absent-passfile',
            'PGSERVICEFILE' => $root.'/absent-servicefile', 'LC_ALL' => 'C',
        ]), timeout: 30);
        if ($sql !== null) {
            $p->setInput($sql);
        }
        $p->run();
        foreach (['stdout' => $p->getOutput(), 'stderr' => $p->getErrorOutput(),
            'execution' => json_encode(['command' => $command, 'exit_code' => $p->getExitCode(),
                'input_sql_sha256' => $sql === null ? null : hash('sha256', $sql),
                'schema' => $schema, 'read_only' => $schema !== null], JSON_THROW_ON_ERROR)."\n"] as $kind => $text) {
            $stream = fopen($root.'/'.$label.'.'.$kind.'.log', 'xb');
            if ($stream === false || fwrite($stream, $text) !== strlen($text)) {
                throw new RuntimeException('Cannot preserve synthetic PostgreSQL execution evidence.');
            }
            fclose($stream);
        }

        return $p;
    }

    private static function requireSuccess(Process $process): void
    {
        if ($process->getExitCode() !== 0) {
            throw new RuntimeException($process->getErrorOutput().$process->getOutput());
        }
    }

    private static function retireCluster(): void
    {
        if (self::$temporary === null) {
            return;
        }
        $root = self::$temporary->path();
        self::$temporary->assertRecordedPath($root);
        if (self::$started) {
            self::requireSuccess(self::execute([self::BIN.'pg_ctl', '-D', $root.'/data', '-w', '-t', '10', '-m', 'fast', 'stop'], 'stop'));
            self::$started = false;
        }
        self::$temporary->retire($root, self::$failed ? 'TEST_FAILURE' : 'TEST_COMPLETE');
        self::$temporary = null;
    }
}
