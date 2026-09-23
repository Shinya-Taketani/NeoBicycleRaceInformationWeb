<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Builder;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Exporter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\AgariRaceRelativeFixture as Fixture;
use Tests\TestCase;

class AgariRaceRelativeCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/agari-relative-test-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
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

    public function test_offline_build_is_byte_exact_no_database_or_http_and_includes_2022_2023_unknown(): void
    {
        $rows = [Fixture::race(1, '2022-01-01'), Fixture::race(2, '2023-01-01')];
        $rows[1]['context']['meeting_grade_raw'] = null;
        $rows[1]['context']['race_grade_raw'] = null;
        $rows[1]['context']['meeting_race_grade_raw_values'] = [null];
        Fixture::input($this->root.'/input', $rows);
        DB::shouldReceive('connection')->never();
        foreach (['result', 'reproduced'] as $name) {
            $this->artisan('keirin:stat35:race-relative:build', ['--input-dir' => $this->root.'/input',
                '--master-version' => 'v2', '--output-dir' => $this->root.'/'.$name])->assertSuccessful();
        }
        foreach (['details.jsonl', 'summary.json', 'summary.csv', 'manifest.json', 'COMPLETE.json'] as $file) {
            $this->assertSame(file_get_contents($this->root.'/result/'.$file), file_get_contents($this->root.'/reproduced/'.$file));
        }
        $summary = Files::json($this->root.'/result/summary.json');
        $this->assertSame(2, $summary['totals']['races']);
        $this->assertSame(14, $summary['totals']['relative_rows']);
        $this->assertSame(0, $summary['totals']['speed_rows']);
        $groups = array_column($summary['groups'], 'dimensions');
        $this->assertContains(['YEAR_GRADE', 2022, 'F2'], $groups);
        $this->assertContains(['YEAR_GRADE', 2023, 'UNKNOWN'], $groups);
        Http::assertNothingSent();
    }

    public function test_export_complete_race_chunks_only_current_import_no_missing_race_loss_or_writes(): void
    {
        $this->schema();
        $a = Fixture::race(1, '2022-01-01');
        $b = Fixture::race(2, '2023-01-01', array_fill(0, 9, '13'));
        $c = Fixture::race(3, '2024-01-01', []);
        $d = Fixture::race(4, '2025-01-01', []);
        $d['race_status'] = 'CANCELLED';
        foreach ([$a, $b, $c, $d, Fixture::race(5, '2026-01-01')] as $row) {
            $this->insert($row);
        }
        // A second historical import/observation exists but is never joined to current results.
        $old = $a;
        foreach ($old['results'] as &$entry) {
            $entry['import']['id'] = 99;
            $entry['observation']['id'] += 1000;
            $entry['observation']['race_result_import_id'] = 99;
        }
        unset($entry);
        DB::table('race_result_imports')->insert($old['results'][0]['import']);
        foreach ($old['results'] as $entry) {
            $observation = $entry['observation'];
            $observation['metadata'] = json_encode($observation['metadata'], JSON_THROW_ON_ERROR);
            DB::table('race_result_agari_observations')->insert($observation);
        }
        $before = $this->databaseHash();
        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });
        $this->artisan('keirin:stat35:race-relative:export', ['--from' => '2022-01-01', '--to' => '2025-12-31',
            '--chunk' => 1, '--output-dir' => $this->root.'/input'])->assertSuccessful();
        $this->assertSame($before, $this->databaseHash());
        $manifest = Artifacts::input($this->root.'/input');
        $this->assertSame(4, $manifest['race_count']);
        $this->assertSame(16, $manifest['result_count']);
        $captured = iterator_to_array(Artifacts::lines($this->root.'/input/races.jsonl'));
        $this->assertSame([1, 2, 3, 4], array_column($captured, 'race_id'));
        $this->assertSame([7, 9, 0, 0], array_map(fn ($r) => count($r['results']), $captured));
        $this->assertSame([1], array_values(array_unique(array_column($captured[0]['results'], 'race_result_import_id'))));
        foreach ($sql as $query) {
            $this->assertDoesNotMatchRegularExpression('/\b(insert|update|delete)\b/i', $query);
            $this->assertDoesNotMatchRegularExpression('/raw_result_text|race_payouts|players|scraping_fetch_logs|batch_runs/i', $query);
            if (str_contains($query, '"races" as "r"')) {
                $this->assertStringContainsString('"r"."race_date" between', $query);
                $this->assertStringContainsString('"r"."source" =', $query);
            }
        }
        $summary = app(Builder::class)->build($this->root.'/input', 'v2', $this->root.'/result');
        $this->assertSame(4, $summary['totals']['races']);
        $this->assertSame(2, $summary['totals']['unusable_races']);
        $this->assertSame(2, $summary['race_exclusion_reason_counts_nonexclusive']['NO_CURRENT_RESULTS']);
        $this->assertSame(1, $summary['race_exclusion_reason_counts_nonexclusive']['CANCELLED']);
    }

    public function test_meeting_mixed_grades_across_chunks_are_unknown_for_both_races(): void
    {
        $this->schema();
        $a = Fixture::race(1);
        $b = Fixture::race(2);
        $b['context']['meeting_id'] = 1;
        $b['context']['race_grade_raw'] = 'GP';
        $this->insert($a);
        $this->insert($b);
        app(Exporter::class)->export('2022-01-01', '2025-12-31', 1, $this->root.'/input');
        app(Builder::class)->build($this->root.'/input', 'v2', $this->root.'/result');
        foreach (Artifacts::lines($this->root.'/result/details.jsonl') as $row) {
            $this->assertSame('UNKNOWN', $row['classification']['meeting_grade']);
            $this->assertSame('CONFLICTING_OR_MIXED_GRADES', $row['classification']['meeting_grade_reason']);
        }
    }

    #[DataProvider('corruptions')]
    public function test_corrupt_schema_duplicate_holdout_or_hash_refused_without_publication(string $case): void
    {
        $rows = [Fixture::race()];
        if ($case === 'duplicate') {
            $rows[] = $rows[0];
        } elseif ($case === 'holdout') {
            $rows[0]['race_date'] = '2026-01-01';
        } elseif ($case === 'schema') {
            $rows[0]['schema'] = 'unknown';
        }
        Fixture::input($this->root.'/input', $rows);
        if ($case === 'hash') {
            file_put_contents($this->root.'/input/races.jsonl', '{}'."\n");
        } elseif ($case === 'json') {
            file_put_contents($this->root.'/input/races.jsonl', '{broken'."\n");
            $manifest = Files::json($this->root.'/input/manifest.json');
            $manifest['files']['races.jsonl'] = Files::identity($this->root.'/input/races.jsonl');
            file_put_contents($this->root.'/input/manifest.json', Files::canonical($manifest));
            file_put_contents($this->root.'/input/COMPLETE.json', Files::canonical(Files::identity($this->root.'/input/manifest.json')));
        }
        $this->artisan('keirin:stat35:race-relative:build', ['--input-dir' => $this->root.'/input',
            '--master-version' => 'v2', '--output-dir' => $this->root.'/result'])->assertFailed();
        $this->assertFileDoesNotExist($this->root.'/result/COMPLETE.json');
    }

    public static function corruptions(): array
    {
        return [['duplicate'], ['holdout'], ['schema'], ['hash'], ['json']];
    }

    public function test_existing_output_is_not_changed_and_2026_export_does_not_connect(): void
    {
        Fixture::input($this->root.'/input', [Fixture::race()]);
        $before = Files::identity($this->root.'/input/races.jsonl');
        $this->artisan('keirin:stat35:race-relative:build', ['--input-dir' => $this->root.'/input',
            '--master-version' => 'v2', '--output-dir' => $this->root.'/input'])->assertFailed();
        $this->assertSame($before, Files::identity($this->root.'/input/races.jsonl'));
        DB::shouldReceive('connection')->never();
        $this->artisan('keirin:stat35:race-relative:export', ['--from' => '2026-01-01', '--to' => '2026-01-01',
            '--output-dir' => $this->root.'/holdout'])->assertFailed();
        $this->assertDirectoryDoesNotExist($this->root.'/holdout');
    }

    public function test_generation_time_seal_rejects_changed_output(): void
    {
        Artifacts::create($this->root.'/out');
        $seal = Artifacts::json($this->root.'/out', 'data.json', ['a' => 1]);
        file_put_contents($this->root.'/out/data.json', '{"a":2}'."\n");
        $this->expectException(RuntimeException::class);
        try {
            Artifacts::publish($this->root.'/out', ['files' => ['data.json' => $seal]]);
        } finally {
            $this->assertFileDoesNotExist($this->root.'/out/COMPLETE.json');
        }
    }

    private function schema(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $fixture = Fixture::race();
        $tables = [
            'races' => ['id' => 1, 'source' => 'keirin_jp', 'race_date' => '2023-01-01', 'race_number' => 1,
                'result_status' => 'CONFIRMED', 'race_type' => 'A級予選', 'entrant_count' => 7,
                'grade' => 'F2', 'race_day_id' => 1, 'racetrack_id' => 1],
            'race_days' => ['id' => 1, 'race_meeting_id' => 1, 'race_date' => '2023-01-01'],
            'race_meetings' => ['id' => 1, 'grade' => 'F2', 'starts_on' => '2023-01-01', 'ends_on' => '2023-01-01', 'racetrack_id' => 1],
            'racetracks' => ['id' => 1, 'external_track_id' => '11'],
            'race_results' => array_diff_key($fixture['results'][0], ['import' => 1, 'observation' => 1]),
            'race_result_imports' => $fixture['results'][0]['import'],
            'race_result_agari_observations' => $fixture['results'][0]['observation'],
        ];
        foreach ($tables as $name => $columns) {
            Schema::create($name, function (Blueprint $table) use ($columns): void {
                foreach ($columns as $key => $value) {
                    $integer = is_int($value) || in_array($key, ['race_entry_id', 'player_id'], true);
                    $column = $integer ? $table->integer($key) : $table->text($key);
                    $column->nullable();
                }
            });
        }
        DB::table('racetracks')->insert(['id' => 1, 'external_track_id' => '11']);
    }

    private function insert(array $race): void
    {
        $c = $race['context'];
        DB::table('races')->insert(['id' => $race['race_id'], 'source' => 'keirin_jp', 'race_date' => $race['race_date'],
            'race_number' => 1, 'result_status' => $race['race_status'], 'race_type' => $race['race_type'],
            'entrant_count' => count($race['results']), 'grade' => $c['race_grade_raw'], 'race_day_id' => $c['race_day_id'], 'racetrack_id' => 1]);
        DB::table('race_days')->insert(['id' => $c['race_day_id'], 'race_meeting_id' => $c['meeting_id'], 'race_date' => $race['race_date']]);
        if (! DB::table('race_meetings')->where('id', $c['meeting_id'])->exists()) {
            DB::table('race_meetings')->insert(['id' => $c['meeting_id'], 'grade' => $c['meeting_grade_raw'],
                'starts_on' => $c['starts_on'], 'ends_on' => $c['ends_on'], 'racetrack_id' => 1]);
        }
        if ($race['results'] === []) {
            return;
        }
        DB::table('race_result_imports')->insert($race['results'][0]['import']);
        foreach ($race['results'] as $entry) {
            DB::table('race_results')->insert(array_diff_key($entry, ['import' => 1, 'observation' => 1]));
            $observation = $entry['observation'];
            $observation['metadata'] = json_encode($observation['metadata'], JSON_THROW_ON_ERROR);
            DB::table('race_result_agari_observations')->insert($observation);
        }
    }

    private function databaseHash(): string
    {
        $hash = hash_init('sha256');
        foreach (['races', 'race_results', 'race_result_imports', 'race_result_agari_observations'] as $table) {
            hash_update($hash, json_encode(DB::table($table)->orderBy('id')->get(), JSON_THROW_ON_ERROR));
        }

        return hash_final($hash);
    }
}
