<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariC1Context\Contract as Context;
use App\Domain\Keirin\Statistics\AgariC1Context\ReadOnlySession;
use App\Domain\Keirin\Statistics\AgariC1Input\Contract as C1;
use App\Domain\Keirin\Statistics\AgariC1Input\Stream;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use App\Domain\Keirin\Statistics\TacticalInputReadiness\Attributes;
use App\Domain\Keirin\Statistics\TacticalInputReadiness\Auditor;
use App\Domain\Keirin\Statistics\TacticalInputReadiness\Builder;
use App\Domain\Keirin\Statistics\TacticalInputReadiness\Contract;
use App\Domain\Keirin\Statistics\TacticalInputReadiness\Universe;
use Illuminate\Database\QueryException;
use Illuminate\Database\SQLiteConnection;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\CompositionResultTemporaryDirectory;

final class TacticalInputReadinessTest extends TestCase
{
    private CompositionResultTemporaryDirectory $temporary;

    protected function setUp(): void
    {
        parent::setUp();
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->detectEnvironment(static fn () => 'testing');
        $this->temporary = CompositionResultTemporaryDirectory::create();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            $this->temporary->retire($this->temporary->path(), 'TEST_COMPLETE');
        }
    }

    #[DataProvider('styles')]
    public function test_values_and_unknown_timing_are_preserved(mixed $style, ?string $normalized, string $state): void
    {
        $t = self::target(1);
        $r = self::record($t);
        $r['riding_style'] = $style;
        $row = Attributes::classify($t, $r);
        $this->assertSame($style, $row['riding_style']['raw']);
        $this->assertSame($normalized, $row['riding_style']['normalized']);
        $this->assertSame($state, $row['riding_style']['value_status']);
        $this->assertSame('UNKNOWN_SOURCE_TIMING', $row['riding_style']['timing_status']);
        $this->assertNull($row['line']['role']);
        $this->assertFalse($row['historical_as_of_available']);
        $this->assertSame('NOT_AUTHORIZED', $row['prediction_use']);
    }

    public static function styles(): array
    {
        return [['逃', '逃', 'VALUE'], ['追', '追', 'VALUE'], ['両', '両', 'VALUE'],
            ['unknown', null, 'VALUE'], [null, null, 'NULL'], ['', null, 'EMPTY_STRING'], [0, null, 'UNSUPPORTED_FORMAT']];
    }

    public function test_generic_pre_and_post_start_fetches_do_not_prove_field_timing_or_line_roles(): void
    {
        $t = self::target(1);
        foreach ([null, '2024-01-01T09:00:00+09:00', '2024-01-02T09:00:00+09:00'] as $at) {
            foreach ([null, '', 'explicit:1-2-3', 'estimated:1-2-3'] as $line) {
                $r = self::record($t);
                $r['fetched_at'] = $at;
                $r['line_text'] = $line;
                $result = Attributes::classify($t, $r);
                $this->assertSame($at, $result['generic_fetched_at']);
                $this->assertSame($line, $result['line']['raw']);
                $this->assertNull($result['line']['role']);
                $this->assertNull($result['riding_style']['observed_at']);
                $this->assertSame('UNKNOWN_SOURCE_TIMING', $result['line']['timing_status']);
            }
        }
    }

    public function test_wrong_player_never_exposes_normalized_candidate(): void
    {
        $t = self::target(1);
        $r = self::record($t);
        $r['external_player_id'] = '999999';
        $result = Attributes::classify($t, $r);
        $this->assertSame('IDENTITY_MISMATCH', $result['identity']);
        $this->assertNull($result['riding_style']['normalized']);
    }

    public function test_shuffled_records_join_by_identity_and_reproduce_without_original_sources(): void
    {
        $snapshot = $this->snapshot();
        $builder = new Builder;
        $a = $builder->build($snapshot, $this->temporary->path().'/first');
        $b = $builder->build($snapshot, $this->temporary->path().'/second', $this->temporary->path().'/first');
        $this->assertSame(5, $a['years'][2024]['matched']);
        $this->assertSame(0, $a['years'][2024]['timing_verified']);
        $this->assertSame('IDENTICAL', $b['reproduction']);
        $this->assertSame(Contract::published($this->temporary->path().'/first', 'CANDIDATE')['files'],
            Contract::published($this->temporary->path().'/second', 'CANDIDATE')['files']);
    }

    public function test_sealed_fixed_sources_are_joined_without_using_history_values(): void
    {
        $pins = $this->fixedSources();
        $output = $this->temporary->path().'/prepared';
        mkdir($output, 0700);
        $result = (new Universe($pins))->prepare($output);
        $this->assertSame(['races' => 1, 'entries' => 5], $result['years'][2024]);
        $rows = iterator_to_array(Artifacts::lines($output.'/targets.jsonl'));
        $this->assertSame('100001', $rows[0]['entries'][0]['external_player_id']);
        $this->assertArrayNotHasKey('history', $rows[0]['entries'][0]);
        $this->assertArrayNotHasKey('rank', $rows[0]['entries'][0]);
    }

    public function test_mapping_from_a_different_sealed_extraction_is_rejected(): void
    {
        $pins = $this->fixedSources(true);
        $output = $this->temporary->path().'/prepared';
        mkdir($output, 0700);
        $this->expectException(RuntimeException::class);
        (new Universe($pins))->prepare($output);
    }

    private function fixedSources(bool $wrongExtraction = false): array
    {
        $root = $this->temporary->path();
        foreach (['c1', 'targets', 'mapping'] as $name) {
            mkdir($root.'/'.$name, 0700);
        }
        $targets = array_map(fn ($bike) => array_diff_key(self::target($bike, 1, $bike), ['external_player_id' => true]), range(1, 5));
        $stream = new Stream($root.'/targets/targets.jsonl');
        $stream->row(['year' => 2024, 'race_id' => 1, 'entries' => $targets]);
        $targetFile = $stream->finish();
        $files = $years = $races = $entries = [];
        foreach (Contract::YEARS as $year) {
            $s = new Stream($root.'/c1/c1-'.$year.'.jsonl');
            if ($year === 2024) {
                $s->row(['year' => 2024, 'race_id' => 1, 'entries' => array_map(fn ($bike) => [
                    'id' => $bike, 'bike' => $bike, 'raw' => 80.0, 'stat01_rank' => 1, 'anchor' => 0.0,
                    'anchor_status' => 'ZERO_VARIANCE', 'signals' => array_fill(0, 12, null),
                    'history' => [null, null, null, null], 'history_status' => 'NO_HISTORY'], range(1, 5))]);
            }
            $files['c1-'.$year.'.jsonl'] = $s->finish();
            $races[$year] = $year === 2024 ? 1 : 0;
            $entries[$year] = $year === 2024 ? 5 : 0;
            $years[$year] = ['races' => $races[$year], 'entries' => $entries[$year], 'non_result_sha256' => $files['c1-'.$year.'.jsonl']['sha256']];
        }
        $origin = ['bytes' => 1, 'sha256' => hash('sha256', 'synthetic-origin')];
        Artifacts::publish($root.'/c1', ['contract' => ['version' => C1::VERSION, 'years' => Contract::YEARS],
            'status' => 'INPUTS_PREPARED', 'source' => ['expected_rows' => $races, 'expected_targets' => $entries,
                'c1' => $root.'/origin', 'seals' => [$root.'/origin/manifest.json' => $origin]], 'files' => $files]);
        Artifacts::publish($root.'/targets', ['version' => Context::VERSION, 'kind' => 'EXTRACTION',
            'source' => ['manifest' => $origin, 'years' => $years], 'files' => ['targets.jsonl' => $targetFile]]);
        $s = new Stream($root.'/mapping/mapping-audit.jsonl');
        foreach (array_reverse($targets) as $t) {
            $s->row(['year' => 2024, 'entry_id' => $t['entry_id'], 'target' => $t,
                'context' => ['external_player_id' => (string) (100000 + $t['bike'])], 'checks' => ['identity_matched' => true]]);
        }
        $mappingFiles = ['mapping-audit.jsonl' => $s->finish(), 'provenance.json' => Artifacts::json($root.'/mapping', 'provenance.json', [
            'source' => ['manifest' => $origin, 'years' => $years], 'extraction_manifest' => $wrongExtraction ? $origin : Files::identity($root.'/targets/manifest.json')])];
        Artifacts::publish($root.'/mapping', ['version' => Context::VERSION, 'kind' => 'MAPPING', 'files' => $mappingFiles]);
        $pins = [];
        foreach (['c1', 'targets', 'mapping'] as $name) {
            $pins[$name] = ['path' => $root.'/'.$name, 'seal' => Files::identity($root.'/'.$name.'/manifest.json')];
        }

        return $pins;
    }

    #[DataProvider('invalidSnapshots')]
    public function test_invalid_snapshots_never_publish_complete(string $case): void
    {
        $snapshot = $this->snapshot($case);
        $out = $this->temporary->path().'/failed';
        try {
            (new Builder)->build($snapshot, $out);
            $this->fail('Invalid snapshot accepted: '.$case);
        } catch (\Throwable $e) {
            $this->assertNotSame('', $e->getMessage());
            $this->assertFileDoesNotExist($out.'/COMPLETE.json');
        }
    }

    public static function invalidSnapshots(): array
    {
        return array_map(fn ($c) => [$c], ['duplicate_bike', 'wrong_player', 'wrong_year', '2026', 'result_column',
            'timing_self_assertion', 'source_drift', 'missing_record', 'extra_record', 'duplicate_record']);
    }

    public function test_existing_output_and_source_overlap_are_rejected_without_overwrite(): void
    {
        $snapshot = $this->snapshot();
        $before = Files::identity($snapshot.'/manifest.json');
        try {
            (new Builder)->build($snapshot, $snapshot);
            $this->fail('Source overwritten.');
        } catch (RuntimeException) {
            $this->assertSame($before, Files::identity($snapshot.'/manifest.json'));
        }
        $out = $this->temporary->path().'/existing';
        mkdir($out, 0700);
        $this->expectException(RuntimeException::class);
        (new Builder)->build($snapshot, $out);
    }

    public function test_query_limits_exact_pairs_dates_and_only_racecard_fields(): void
    {
        [$sql, $bindings] = Auditor::query([self::target(1)]);
        $this->assertSame([1, 1, '2024-01-01'], $bindings);
        $this->assertStringContainsString("DATE '2025-12-31'", $sql);
        $this->assertStringContainsString('r.id=t.race_id AND r.race_date=t.race_date', $sql);
        foreach (['race_results', 'race_payouts', 'players', '2026', 'rank', 'result_status'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $sql);
        }
    }

    public function test_reused_read_only_session_rejects_writes_and_rolls_back(): void
    {
        $db = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', ['driver' => 'sqlite', 'database' => ':memory:']);
        $db->statement('CREATE TABLE synthetic(id INTEGER)');
        $called = false;
        $settings = (new ReadOnlySession)->transaction($db, function ($connection, $settings) use (&$called): void {
            $this->assertTrue($settings['read_only']);
            $this->assertSame(1, $connection->transactionLevel());
            try {
                $connection->insert('INSERT INTO synthetic VALUES (1)');
                $this->fail('Read-only transaction accepted a write.');
            } catch (QueryException) {
                $called = true;
            }
        });
        $this->assertTrue($called);
        $this->assertTrue($settings['read_only']);
        $this->assertSame(0, $db->transactionLevel());
        $this->assertSame(0, $db->selectOne('SELECT COUNT(*) AS n FROM synthetic')->n);
        $db->disconnect();
    }

    public function test_independent_128m_process_streams_over_100mib_without_shared_peak_dependency(): void
    {
        $root = $this->temporary->path();
        $script = <<<'PHP'
require $argv[1].'/vendor/autoload.php'; require $argv[1].'/bootstrap/app.php';
$f = Tests\Feature\TacticalInputReadinessTest::class;
$snapshot=$f::largeSnapshot($argv[2]);
if(filesize($snapshot.'/records.jsonl')<=100*1024*1024){throw new RuntimeException('Fixture too small');}
$r=(new App\Domain\Keirin\Statistics\TacticalInputReadiness\Builder)->build($snapshot,$argv[2].'/output');
echo json_encode(['limit'=>ini_get('memory_limit'),'entries'=>$r['years'][2024]['entries'],'peak'=>memory_get_peak_usage(true)],JSON_THROW_ON_ERROR);
PHP;
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M', '-r', $script, dirname(__DIR__, 2), $root]);
        $process->setTimeout(180);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('128M', $result['limit']);
        $this->assertSame(30000, $result['entries']);
    }

    public function test_source_changed_after_join_but_before_publication_is_rejected(): void
    {
        $root = $this->temporary->path();
        $snapshot = self::largeSnapshot($root);
        $out = $root.'/drift-output';
        $script = <<<'PHP'
require $argv[1].'/vendor/autoload.php'; require $argv[1].'/bootstrap/app.php';
(new App\Domain\Keirin\Statistics\TacticalInputReadiness\Builder)->build($argv[2],$argv[3]);
PHP;
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M', '-r', $script, dirname(__DIR__, 2), $snapshot, $out]);
        $process->setTimeout(30);
        $process->start();
        $changed = false;
        $deadline = microtime(true) + 20;
        while ($process->isRunning() && microtime(true) < $deadline) {
            if (is_file($out.'/tactical-input-2024.jsonl.partial')) {
                $h = fopen($snapshot.'/records.jsonl', 'ab');
                $this->assertNotFalse($h);
                $this->assertSame(3, fwrite($h, "{}\n"));
                fclose($h);
                $changed = true;
                break;
            }
            usleep(1000);
        }
        $process->wait();
        $this->assertTrue($changed, 'The synthetic source must change during generation.');
        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('Artifact hash/size mismatch', $process->getErrorOutput());
        $this->assertFileDoesNotExist($out.'/COMPLETE.json');
        $this->assertFileExists($out.'/FAILED.json');
    }

    public static function largeSnapshot(string $root): string
    {
        return self::makeSnapshot($root, '', 6000, str_repeat('x', 4096));
    }

    private function snapshot(string $invalid = ''): string
    {
        return self::makeSnapshot($this->temporary->path(), $invalid);
    }

    private static function makeSnapshot(string $root, string $invalid, int $races = 1, string $style = '逃'): string
    {
        $path = $root.'/snapshot';
        mkdir($path, 0700);
        $targets = new Stream($path.'/targets.jsonl');
        $records = new Stream($path.'/records.jsonl');
        for ($race = 1; $race <= $races; $race++) {
            $entries = [];
            for ($bike = 1; $bike <= 5; $bike++) {
                $t = self::target(($race - 1) * 5 + $bike, $race, $bike);
                if ($invalid === 'duplicate_bike' && $bike === 5) {
                    $t['bike'] = 1;
                }
                if (in_array($invalid, ['wrong_year', '2026'], true)) {
                    $t['race_date'] = ($invalid === '2026' ? '2026' : '2023').'-01-01';
                }
                $entries[] = $t;
            }
            $targets->row(['year' => $invalid === '2026' ? 2026 : 2024, 'race_id' => $race, 'entries' => $entries]);
            foreach (array_reverse($entries) as $t) {
                $r = self::record($t);
                $r['riding_style'] = $style;
                if ($invalid === 'wrong_player') {
                    $r['external_player_id'] = '999999';
                }
                if ($invalid === 'result_column') {
                    $r['rank'] = 1;
                }
                if ($invalid === 'timing_self_assertion') {
                    $r['riding_style_observed_at'] = '2024-01-01T09:00:00+09:00';
                }
                if ($invalid !== 'missing_record' || $t['bike'] !== 5) {
                    $records->row(['target_entry_id' => $t['entry_id'], 'record' => $r]);
                }
                if ($invalid === 'duplicate_record' && $t['bike'] === 5) {
                    $records->row(['target_entry_id' => $t['entry_id'], 'record' => $r]);
                }
            }
        }
        if ($invalid === 'extra_record') {
            $records->row(['target_entry_id' => 9999999, 'record' => self::record(self::target(9999999))]);
        }
        $files = ['targets.jsonl' => $targets->finish(), 'records.jsonl' => $records->finish(),
            'queries.jsonl' => Artifacts::json($path, 'queries.jsonl', ['synthetic' => true]),
            'source-audit.json' => Artifacts::json($path, 'source-audit.json', ['synthetic' => true])];
        $years = array_fill_keys(Contract::YEARS, ['races' => 0, 'entries' => 0]);
        $years[2024] = ['races' => $races, 'entries' => $races * 5];
        Artifacts::publish($path, ['version' => Contract::VERSION, 'kind' => 'AUDIT', 'historical_as_of_available' => false,
            'prediction_use' => 'NOT_AUTHORIZED', 'years' => $years, 'files' => $files]);
        if ($invalid === 'source_drift') {
            $h = fopen($path.'/records.jsonl', 'ab');
            fwrite($h, "{}\n");
            fclose($h);
        }

        return $path;
    }

    private static function target(int $id, int $race = 1, int $bike = 1): array
    {
        return ['race_id' => $race, 'entry_id' => $id, 'bike' => $bike, 'race_date' => '2024-01-01',
            'meeting_id' => 1, 'meeting_start' => '2024-01-01', 'meeting_end' => '2024-01-03',
            'player_id' => $id, 'feature_input_hash' => 'synthetic', 'external_player_id' => sprintf('%06d', $id)];
    }

    private static function record(array $t): array
    {
        return ['entry_id' => $t['entry_id'], 'race_id' => $t['race_id'], 'bike' => $t['bike'], 'player_id' => $t['player_id'],
            'external_player_id' => $t['external_player_id'], 'race_date' => $t['race_date'],
            'scheduled_start_at' => '2024-01-01T10:00:00+09:00', 'riding_style' => '逃', 'line_text' => null,
            'fetched_at' => '2024-01-02T10:00:00+09:00'];
    }
}
