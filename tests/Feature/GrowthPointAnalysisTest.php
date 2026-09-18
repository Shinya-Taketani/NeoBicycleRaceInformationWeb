<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysis\Analysis;
use App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysis\Contract;
use App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysis\HistorySource;
use App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysis\Service;
use App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysis\Signals;
use App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysis\Sources;
use App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysis\Store;
use App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysis\Workspace;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline\ReadOnlySession;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\ResultStore;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class GrowthPointAnalysisTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = Files::directory(sys_get_temp_dir().'/growth-test-'.bin2hex(random_bytes(8)));
        config(['tactical_prediction_pipeline.artifact_base' => $this->dir]);
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    public function test_time_not_id_and_cancelled_null_time_does_not_poison_history(): void
    {
        $target = $this->target();
        $previous = $this->previous();
        $cancelled = array_replace($previous[0], ['id' => 7, 'race_id' => 7, 'date' => '2024-01-09', 'ts' => null, 'started' => 0]);
        $future = array_replace($previous[0], ['id' => 8, 'race_id' => 8, 'date' => '2024-02-01', 'ts' => strtotime('2024-02-01')]);
        $s = new Signals;
        $a = $s->calculate($target, [$previous[1], $cancelled, $future, $previous[0]]);
        $this->assertSame(999, $a['prev1']['id']);
        $this->assertSame(1000, $a['prev2']['id']);
        $this->assertSame(1.25, $a['SCORE']['raw']);
        $this->assertSame(0.25, $a['PERFORMANCE']['raw']);
        $this->assertTrue($a['same_meeting_previous']);
        $future['residual'] = 500;
        $this->assertSame($a, $s->calculate($target, [$future, ...$previous]));
        $previous[0]['residual'] = 0.75;
        $b = $s->calculate($target, $previous);
        $this->assertSame($a['SCORE'], $b['SCORE']);
        $this->assertSame(1.0, $b['PERFORMANCE']['raw']);
        $target['score'] += 25;
        $this->assertSame(1.5, $s->calculate($target, $previous)['SCORE']['raw']);
        $target['meeting_id'] = 9;
        $this->assertFalse($s->calculate($target, $previous)['same_meeting_previous']);
    }

    #[DataProvider('missingCases')]
    public function test_missing_abnormal_ambiguous_and_zero_are_distinct(string $change, string $score, string $performance): void
    {
        $target = $this->target();
        $p = $this->previous();
        switch ($change) {
            case 'none': $p = [];
                break;
            case 'one': array_pop($p);
                break;
            case 'target_score': $target['score'] = null;
                break;
            case 'previous_score': $p[0]['score'] = null;
                break;
            case 'target_time': $target['ts'] = null;
                break;
            case 'previous_time': $p[0]['ts'] = null;
                break;
            case 'unknown_start': $p[0]['started'] = null;
                break;
            case 'abnormal': $p[0]['residual'] = null;
                break;
            case 'zero': $target['score'] = $p[0]['score'];
                break;
            case 'tie_time': $p[1]['date'] = $p[0]['date'];
                $p[1]['ts'] = $p[0]['ts'];
                break;
        }
        $actual = (new Signals)->calculate($target, $p);
        $this->assertSame($score, $actual['SCORE']['status']);
        $this->assertSame($performance, $actual['PERFORMANCE']['status']);
        if ($change === 'zero') {
            $this->assertSame(0.0, $actual['SCORE']['raw']);
        }
    }

    public static function missingCases(): array
    {
        return [['none', 'NO_PREVIOUS_RACE', 'NO_PREVIOUS_RACE'], ['one', 'VALID', 'NO_SECOND_PREVIOUS_RACE'],
            ['target_score', 'MISSING_TARGET_SCORE', 'VALID'], ['previous_score', 'MISSING_PREVIOUS_SCORE', 'VALID'],
            ['target_time', 'PARTIAL_HISTORY', 'PARTIAL_HISTORY'], ['previous_time', 'PARTIAL_HISTORY', 'PARTIAL_HISTORY'],
            ['unknown_start', 'PARTIAL_HISTORY', 'PARTIAL_HISTORY'], ['abnormal', 'VALID', 'MISSING_OR_ABNORMAL_PREVIOUS_RESIDUAL'],
            ['zero', 'VALID', 'VALID'], ['tie_time', 'PARTIAL_HISTORY', 'PARTIAL_HISTORY']];
    }

    #[DataProvider('invalidSignals')]
    public function test_invalid_inputs_rejected_before_query(string $case): void
    {
        $target = $this->target();
        $p = $this->previous();
        if ($case === 'outcome') {
            $target['rank'] = 1;
        }
        if ($case === 'player') {
            $p[0]['player_id'] = 2;
        }
        if ($case === 'duplicate') {
            $p[] = $p[0];
        }
        if ($case === '2026') {
            $target['year'] = 2026;
        }
        DB::shouldReceive('connection')->never();
        $this->expectException(RuntimeException::class);
        (new Signals)->calculate($target, $p);
    }

    public static function invalidSignals(): array
    {
        return [['outcome'], ['player'], ['duplicate'], ['2026']];
    }

    #[DataProvider('boundaries')]
    public function test_frozen_point_boundaries(?float $raw, ?int $expected): void
    {
        $this->assertSame($expected, Contract::point($raw, [-3, -2, -1, 1, 2, 3]));
    }

    public static function boundaries(): array
    {
        return [[-4.0, -3], [-3.0, -3], [-2.0, -2], [-1.0, -1], [0.0, 0], [1.0, 1], [2.0, 2], [3.0, 3], [4.0, 3], [null, null]];
    }

    public function test_tied_thresholds_decimal_precision_and_training_years(): void
    {
        $this->assertSame(-3, Contract::point(0, array_fill(0, 6, 0)));
        $this->assertSame(3, Contract::point(0.01, array_fill(0, 6, 0)));
        $this->assertSame(Signals::score('90.1'), Signals::score('90.10'));
        $this->assertNull(Signals::score(null));
        $this->assertNull(Signals::score('0.00'));
        $w = new Workspace($this->dir.'/threshold.sqlite');
        $w->db->exec("INSERT INTO training VALUES (2022,'SCORE',0),(2023,'SCORE',10),(2024,'SCORE',100),(2025,'SCORE',10000)");
        $before = $w->thresholds();
        $this->assertSame([1.0, 2.5, 4.0, 6.0, 7.5, 9.0], $before[2024]['SCORE']['values']);
        $w->db->exec('UPDATE training SET raw=999999 WHERE year=2025');
        $this->assertSame($before, $w->thresholds());
        $w->db->exec('UPDATE training SET raw=999 WHERE year=2024');
        $this->assertSame($before[2024], $w->thresholds()[2024]);
        $this->assertNotSame($before[2025], $w->thresholds()[2025]);
    }

    public function test_formal_residual_and_started_abnormal_selection(): void
    {
        $w = new Workspace($this->dir.'/residual.sqlite');
        $rows = [$this->race(900, '2023-12-01'), $this->race(80, '2024-01-01'), $this->race(70, '2024-01-05')];
        $rows[0]['entries'][0]['race_score'] = '85';
        $rows[1]['entries'][0]['status'] = 'CRASHED';
        $rows[1]['entries'][0]['rank'] = null;
        $rows[2]['scheduled_start_at'] = null;
        $rows[2]['race_status'] = 'CANCELLED';
        $w->history($rows);
        $entry = $w->db->query('SELECT * FROM history WHERE id=9001')->fetch();
        $this->assertSame(0.5, $entry['residual']); // score rank 3, official rank 1, five entrants.
        $p = $w->previous($this->target());
        $this->assertSame([80, 900], array_column($p, 'race_id'));
        $this->assertNull($p[0]['residual']);
        $this->assertSame(1, $p[0]['started']);
        $this->assertSame('MISSING_OR_ABNORMAL_PREVIOUS_RESIDUAL', (new Signals)->calculate($this->target(), $p)['PERFORMANCE']['status']);
    }

    public function test_outcome_independence_spearman_average_ties_and_all_entry_aggregation(): void
    {
        $w = new Workspace($this->dir.'/aggregate.sqlite');
        $a = new Analysis(new Signals);
        $inputs = [];
        foreach ([1, 2, 3, 4] as $i) {
            $target = array_replace($this->target(), ['id' => $i, 'race_id' => $i, 'player_id' => 1, 'score' => [9000, 9000, 9100, 9200][$i - 1]]);
            $inputs[] = ['target' => $target, 'previous' => $this->previous(), 'grade' => 'UNKNOWN', 'class' => 'UNKNOWN',
                'outcome' => ['rank' => $i, 'status' => 'FINISHED'], 'predicted' => true, 'unique_winner' => true];
        }
        $q = [2024 => ['SCORE' => ['values' => [-2, -1, 0, 1, 2, 3]], 'PERFORMANCE' => ['values' => [-2, -1, 0, 1, 2, 3]]]];
        $details = iterator_to_array($a->details($inputs, $q, $w));
        $computed = $a->aggregate($w);
        $this->assertSame(4, $computed['summary']['year_totals'][0]['entries']);
        $rho = $a->spearman($w->db, 'year=? AND signal=?', [2024, 'SCORE'], 'raw');
        $this->assertEqualsWithDelta(-sqrt(0.9), $rho['rho'], 1e-14);
        $w2 = new Workspace($this->dir.'/outcome.sqlite');
        foreach ($inputs as &$input) {
            $input['outcome']['rank'] = 5;
        }
        unset($input);
        $other = iterator_to_array($a->details($inputs, $q, $w2));
        $this->assertSame(array_column($details, 'signals'), array_column($other, 'signals'));
        $this->assertNull($a->spearman($w2->db, 'year=? AND signal=?', [2024, 'SCORE'], 'raw')['rho']);
    }

    public function test_diagnostic_classification_is_not_a_gate_and_has_fixed_boundaries(): void
    {
        $a = new Analysis(new Signals);
        $cells = [];
        for ($i = -1; $i <= 1; $i++) {
            $cells[] = ['point' => $i, 'normal' => 40, 'win_rate' => 0.2 + $i / 10, 'top3_rate' => 0.5 + $i / 10];
        }
        $rho = ['n' => 1000, 'players' => 30, 'races' => 100, 'rho' => 0.03];
        $this->assertSame('POSITIVE_MONOTONIC_TENDENCY', $a->classify($cells, $rho, $rho)['label']);
        $reverse = array_reverse($cells);
        $rho['rho'] = -0.03;
        $this->assertSame('NEGATIVE_MONOTONIC_TENDENCY', $a->classify($reverse, $rho, $rho)['label']);
        $this->assertSame('NON_MONOTONIC', $a->classify($cells, $rho, $rho)['label']);
        $rho['n'] = 999;
        $this->assertSame('INSUFFICIENT_SAMPLE', $a->classify($cells, $rho, $rho)['label']);
    }

    #[DataProvider('duplicateKinds')]
    public function test_duplicate_and_player_identity_fail_closed(string $kind): void
    {
        $w = new Workspace($this->dir.'/invalid.sqlite');
        $race = $this->race(3, '2024-01-10');
        if ($kind === 'race') {
            $rows = [$race, $race];
        } elseif ($kind === 'entry') {
            $race['entries'][1]['id'] = $race['entries'][0]['id'];
            $rows = [$race];
        } elseif ($kind === 'player') {
            $race['entries'][1]['player_id'] = 1;
            $rows = [$race];
        } else {
            $race['entries'][0]['result_player_id'] = 99;
            $rows = [$race];
        }
        $this->expectException(\Exception::class);
        $w->history($rows);
    }

    public static function duplicateKinds(): array
    {
        return [['race'], ['entry'], ['player'], ['result_identity']];
    }

    public function test_plan_without_database(): void
    {
        DB::shouldReceive('connection')->never();
        $this->artisan('keirin:backtest:growth-point-analysis', ['--plan' => true])->assertExitCode(0);
    }

    public function test_read_only_capture_execute_and_db_disabled_reproduce(): void
    {
        [$root, $source] = $this->integration();
        $receipt = app(Service::class)->execute($root, 'synthetic', $source);
        $this->assertSame('GROWTH_ANALYSIS_LOCKED', $receipt['status']);
        $this->assertSame([5, 5], array_column($receipt['year_totals'], 'entries'));
        $path = $receipt['path'];
        $this->assertTrue(Files::json($path.'/history-start.json')['settings']['query_only']);
        $this->assertSame('UNCHANGED', Files::json($path.'/source-end.json')['status']);
        DB::shouldReceive('connection')->never();
        $reproduced = app(Service::class)->reproduce($root, 'synthetic');
        $this->assertSame('REPRODUCED', $reproduced['status']);
        $this->assertSame($receipt['details'], $reproduced['details']);
        $this->assertSame($receipt['summary'], $reproduced['summary']);
        $this->assertSame('NONE', $reproduced['database']);
        file_put_contents($path.'/growth-details.jsonl', 'X', FILE_APPEND);
        $this->expectException(RuntimeException::class);
        app(Store::class)->verify($path);
    }

    #[DataProvider('driftKinds')]
    public function test_end_drift_or_generated_tamper_never_publishes(string $kind): void
    {
        [$root, $source] = $this->integration();
        if ($kind === 'generated') {
            $this->app->bind(Store::class, fn () => new class(app(ResultStore::class)) extends Store
            {
                public function publish(string $stage, string $destination, array $expected, array $summary): array
                {
                    file_put_contents($stage.'/summary.json', "\n", FILE_APPEND);

                    return parent::publish($stage, $destination, $expected, $summary);
                }
            });
        } else {
            $this->app->bind(HistorySource::class, fn () => new class(app(ReadOnlySession::class), app(ResultStore::class), $kind, $source) extends HistorySource
            {
                public function __construct($session, $writer, private string $kind, private string $source)
                {
                    parent::__construct($session, $writer);
                }

                public function verify(Workspace $workspace, array $expected): array
                {
                    if ($this->kind === 'db') {
                        DB::table('race_entries')->where('id', 11)->update(['race_score' => '99']);
                    } else {
                        file_put_contents($this->source.'/input.jsonl', 'X', FILE_APPEND);
                    }

                    return parent::verify($workspace, $expected);
                }
            });
        }
        try {
            app(Service::class)->execute($root, 'drift', $source);
            $this->fail('Expected drift rejection.');
        } catch (RuntimeException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertDirectoryDoesNotExist($root.'/evaluations/drift');
        $this->assertCount(1, glob($root.'/.staging/*/failure.json'));
    }

    public static function driftKinds(): array
    {
        return [['db'], ['source'], ['generated']];
    }

    public function test_bounded_disk_workspace_stream_over_one_hundred_thousand_entries(): void
    {
        $w = new Workspace($this->dir.'/bounded.sqlite');
        $rows = (function (): \Generator {
            for ($i = 1; $i <= 22000; $i++) {
                yield $this->race($i, '2023-12-01');
            }
        })();
        $w->history($rows);
        $this->assertSame(110000, (int) $w->db->query('SELECT count(*) FROM history')->fetchColumn());
        $this->assertLessThan(128 * 1024 * 1024, memory_get_peak_usage(true));
    }

    public function test_history_sql_bounds_readonly_and_rejects_holdout_before_lookup(): void
    {
        $this->integration();
        // Synthetic out-of-scope sentinel, never a production holdout access.
        DB::table('races')->insert(['id' => 99, 'race_day_id' => 4, 'race_date' => '2026-01-01', 'entrant_count' => 5,
            'scheduled_start_at' => '2026-01-01 12:00:00+09:00', 'result_status' => 'CONFIRMED', 'race_type' => 'Ａ級予選']);
        DB::table('race_entries')->insert(['id' => 991, 'race_id' => 99, 'player_id' => 1, 'bike_number' => 1, 'race_score' => '999']);
        $w = new Workspace($this->dir.'/sql.sqlite');
        $w->db->exec("INSERT INTO targets VALUES(31,3,1,1); INSERT INTO cohort VALUES(3,2024,'{}')");
        $stage = Files::directory($this->dir.'/capture');
        DB::enableQueryLog();
        $capture = app(HistorySource::class)->capture($w, $stage);
        $this->assertSame(4, $capture['digest']['races']);
        foreach (DB::getQueryLog() as $query) {
            if (str_contains($query['query'], '"races"')) {
                $this->assertStringContainsString('"r"."race_date" between ? and ?', $query['query']);
                $this->assertContains('2022-01-01', $query['bindings']);
                $this->assertContains('2025-12-31', $query['bindings']);
            }
        }
        DB::disableQueryLog();
        $this->assertSame(0, (int) DB::selectOne('PRAGMA query_only')->query_only);
        $target = $this->target();
        $target['year'] = 2026;
        $this->expectException(RuntimeException::class);
        $w->previous($target);
    }

    public function test_same_meeting_zero_tied_finish_missing_and_abnormal_denominators(): void
    {
        $w = new Workspace($this->dir.'/ties.sqlite');
        $r = $this->race(20, '2024-01-10');
        $r['entries'][0]['status'] = $r['entries'][1]['status'] = 'TIED';
        $r['entries'][1]['rank'] = 1;
        $r['entries'][2]['status'] = 'DISQUALIFIED';
        $r['entries'][2]['rank'] = null;
        $r['entries'][3]['race_score'] = null;
        $w->history([$r]);
        $this->assertSame(3, (int) $w->db->query('SELECT count(*) FROM history WHERE started=1 AND normal=1')->fetchColumn() - 1);
        $this->assertSame(5, (int) $w->db->query('SELECT count(*) FROM history WHERE residual IS NULL')->fetchColumn());
        $inputs = [];
        foreach ($w->db->query('SELECT * FROM history ORDER BY id') as $row) {
            $p = $this->previous();
            foreach ($p as &$previous) {
                $previous['player_id'] = $row['player_id'];
            }
            unset($previous);
            $inputs[] = ['target' => Workspace::target($row), 'previous' => $p, 'grade' => 'F2', 'class' => 'A_CHALLENGE',
                'outcome' => ['rank' => $row['rank'], 'status' => $row['status']], 'predicted' => $row['bike'] === 1, 'unique_winner' => false];
        }
        $a = new Analysis(new Signals);
        $thresholds = [2024 => ['SCORE' => ['values' => array_fill(0, 6, 0)], 'PERFORMANCE' => ['values' => array_fill(0, 6, 0)]]];
        $details = iterator_to_array($a->details($inputs, $thresholds, $w));
        $this->assertNull($details[3]['signals']['SCORE']['point']);
        $summary = $a->aggregate($w);
        $this->assertSame(4, $summary['summary']['year_totals'][0]['normal']);
        $this->assertSame(1, $summary['summary']['year_totals'][0]['abnormal']);
        $this->assertSame([], $summary['c1_diagnostics'][0]['distributions']['actual_unique_winner']);
        $cells = array_filter($summary['summary']['cells'], fn ($c) => $c['dimension'] === 'year' && $c['year'] === 2024 && $c['signal'] === 'SCORE');
        $this->assertSame(2, array_sum(array_column($cells, 'win')));
        $this->assertSame(1, array_sum(array_column($cells, 'missing')));
    }

    private function target(): array
    {
        return ['id' => 1, 'race_id' => 1, 'player_id' => 1, 'year' => 2024, 'date' => '2024-01-10',
            'ts' => strtotime('2024-01-10 12:00:00+09:00'), 'meeting_id' => 1, 'score' => 9125, 'bike' => 1, 'n' => 5];
    }

    private function previous(): array
    {
        return [array_replace($this->target(), ['id' => 999, 'race_id' => 999, 'date' => '2024-01-09', 'ts' => strtotime('2024-01-09 12:00:00+09:00'), 'score' => 9000, 'residual' => 0.0, 'started' => 1]),
            array_replace($this->target(), ['id' => 1000, 'race_id' => 1000, 'date' => '2024-01-08', 'ts' => strtotime('2024-01-08 12:00:00+09:00'), 'residual' => -0.25, 'started' => 1])];
    }

    private function race(int $id, string $date): array
    {
        $entries = [];
        for ($i = 1; $i <= 5; $i++) {
            $entries[] = ['id' => $id * 10 + $i, 'player_id' => $i, 'bike' => $i, 'race_score' => (string) (92 - $i * 2),
                'rank' => $i, 'status' => 'FINISHED', 'result_id' => $id * 10 + $i, 'result_entry_id' => $id * 10 + $i,
                'result_player_id' => $i, 'entry_fetched_at' => null, 'result_fetched_at' => null];
        }

        return ['race_id' => $id, 'year' => (int) substr($date, 0, 4), 'date' => $date, 'scheduled_start_at' => $date.' 12:00:00+09:00',
            'n' => 5, 'race_status' => 'CONFIRMED', 'meeting_id' => 1, 'day_date' => $date, 'starts_on' => '2022-01-01', 'ends_on' => '2025-12-31', 'entries' => $entries];
    }

    private function integration(): array
    {
        DB::unprepared('CREATE TABLE race_meetings(id INTEGER PRIMARY KEY,starts_on TEXT,ends_on TEXT);
            CREATE TABLE race_days(id INTEGER PRIMARY KEY,race_meeting_id INTEGER,race_date TEXT);
            CREATE TABLE races(id INTEGER PRIMARY KEY,race_day_id INTEGER,race_date TEXT,scheduled_start_at TEXT,entrant_count INTEGER,result_status TEXT,race_type TEXT);
            CREATE TABLE race_entries(id INTEGER PRIMARY KEY,race_id INTEGER,player_id INTEGER,bike_number INTEGER,race_score TEXT,fetched_at TEXT);
            CREATE TABLE race_results(id INTEGER PRIMARY KEY,race_id INTEGER,race_entry_id INTEGER,player_id INTEGER,bike_number INTEGER,rank INTEGER,result_status TEXT,fetched_at TEXT)');
        DB::table('race_meetings')->insert(['id' => 1, 'starts_on' => '2022-01-01', 'ends_on' => '2025-12-31']);
        $rows = [];
        $metadata = [];
        foreach ([1 => '2022-01-01', 2 => '2023-01-01', 3 => '2024-01-01', 4 => '2025-01-01'] as $id => $date) {
            $r = $this->race($id, $date);
            DB::table('race_days')->insert(['id' => $id, 'race_meeting_id' => 1, 'race_date' => $date]);
            DB::table('races')->insert(['id' => $id, 'race_day_id' => $id, 'race_date' => $date, 'scheduled_start_at' => $r['scheduled_start_at'],
                'entrant_count' => 5, 'result_status' => 'CONFIRMED', 'race_type' => 'Ａ級予選']);
            foreach ($r['entries'] as $e) {
                DB::table('race_entries')->insert(['id' => $e['id'], 'race_id' => $id, 'player_id' => $e['player_id'], 'bike_number' => $e['bike'], 'race_score' => $e['race_score']]);
                DB::table('race_results')->insert(['id' => $e['id'], 'race_id' => $id, 'race_entry_id' => $e['id'], 'player_id' => $e['player_id'], 'bike_number' => $e['bike'], 'rank' => $e['rank'], 'result_status' => $e['status']]);
            }
            if ($id >= 3) {
                $rows[] = ['context' => ['year' => $r['year'], 'race_id' => $id, 'entries' => array_map(fn ($e) => array_intersect_key($e, array_flip(['id', 'bike', 'rank', 'status'])), $r['entries'])],
                    'targets' => array_map(fn ($e) => array_intersect_key($e, array_flip(['id', 'bike', 'player_id'])), $r['entries']),
                    'race_date' => $date, 'decision' => ['primary_position_1_bike' => 2]];
                $metadata[] = ['year' => $r['year'], 'race_id' => $id, 'race_date' => $date, 'grade' => 'F2', 'race_class' => 'A1_A2'];
            }
        }
        $source = Files::directory($this->dir.'/source');
        $root = Files::directory($this->dir.'/output');
        JsonlArtifact::write($source.'/input.jsonl', $rows);
        JsonlArtifact::write($source.'/meetings.jsonl', $metadata);
        $this->app->bind(Sources::class, fn () => new class(app(\App\Domain\Keirin\Backtest\Experiments\TacticalMeetingGradeAnalysis\Store::class)) extends Sources
        {
            public function open(string $bundle): array
            {
                $files = [];
                foreach (glob($bundle.'/*') as $file) {
                    $files[$file] = Files::identity($file);
                }

                return ['input' => $bundle.'/input.jsonl', 'meeting_details' => $bundle.'/meetings.jsonl', 'files' => $files, 'expected_counts' => [2024 => 1, 2025 => 1]];
            }
        });

        return [$root, $source];
    }
}
