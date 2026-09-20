<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Backtest\Experiments\GrowthTrendAnalysis\Contract;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendAnalysis\Diagnostics;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendAnalysis\ReviewComparison;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendAnalysis\Selection;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendAnalysis\Service;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendAnalysis\Sources;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendAnalysis\Statistics;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendAnalysis\TemporalAccess;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendAnalysis\Trend;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendAnalysis\Workspace;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendScoreSource\Bundle;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendScoreSource\Code;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendScoreSource\OuterSource;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendScoreSource\Service as ScoreService;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\GrowthTrendFixture;
use Tests\TestCase;

final class GrowthTrendAnalysisTest extends TestCase
{
    use GrowthTrendFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
    }

    protected function tearDown(): void
    {
        $this->cleanupFixture();
        parent::tearDown();
    }

    private function observation(int $id, string $date, ?int $ts, ?int $score, int $meeting = 1): array
    {
        return ['entry_id' => $id, 'race_id' => $id, 'player_id' => 1, 'bike' => 1, 'date' => $date, 'ts' => $ts,
            'score' => $score, 'meeting_id' => $meeting, 'start' => $date];
    }

    private function target(): array
    {
        return $this->observation(1000, '2025-06-01', 1000, 11000, 1000);
    }

    private function history(): array
    {
        $rows = [];
        for ($i = 1; $i <= 12; $i++) {
            $date = (new \DateTimeImmutable('2025-06-01'))->modify('-'.($i * 10).' days')->format('Y-m-d');
            $rows[] = (new Trend)->representative([$this->observation($i, $date, $i, 11000 - $i * 100, $i)]);
        }

        return $rows;
    }

    public function test_same_meeting_excluded_and_distinct_meeting_duplicates_rejected(): void
    {
        $history = $this->history();
        $history[0]['meeting_id'] = 1000;
        $r = (new Trend)->calculate($this->target(), $history);
        $this->assertSame(2.0, $r['candidates']['MEETING_DELTA_LAG_1']['raw']);
        $this->expectException(RuntimeException::class);
        (new Trend)->calculate($this->target(), [...$history, $history[1]]);
    }

    #[DataProvider('boundaryMeetings')]
    public function test_target_boundary_never_falls_back_past_an_ambiguous_meeting(array $extra, array $ambiguous, float $expectedRaw): void
    {
        $trend = new Trend;
        $target = $this->observation(1000, '2025-05-31', 1000, 11000, 100);
        $history = [$trend->representative([$this->observation(1, '2025-05-20', 1, 10000, 1)])];
        foreach ($extra as [$id, $date]) {
            $history[] = $trend->representative([$this->observation($id, $date, 2, 10900, $id)]);
        }
        $result = $trend->calculate($target, $history);
        $this->assertSame($ambiguous, $result['ambiguous_meeting_ids']);
        $this->assertSame(count($ambiguous), $result['ambiguous_meeting_count']);
        $this->assertSame($ambiguous !== [], $result['target_boundary_partial_time_order']);
        if ($ambiguous !== []) {
            $this->assertCount(41, $result['candidates']);
            foreach ($result['candidates'] as $candidate) {
                $this->assertSame(['raw' => null, 'status' => 'PARTIAL_TIME_ORDER'], $candidate);
            }
            $this->assertNull($result['events']['LAST_SCORE_CHANGE_DELTA']);
        } else {
            $this->assertSame(['raw' => $expectedRaw, 'status' => 'VALID'], $result['candidates']['MEETING_DELTA_LAG_1']);
        }
    }

    public static function boundaryMeetings(): array
    {
        return [
            'same date different meeting with older fallback available' => [[[99, '2025-05-31']], [99], 0.0],
            'multiple same date meetings' => [[[99, '2025-05-31'], [98, '2025-05-31']], [98, 99], 0.0],
            'same target meeting excluded' => [[[100, '2025-05-31']], [], 10.0],
            'previous day usable' => [[[99, '2025-05-30']], [], 1.0],
            'next day excluded' => [[[99, '2025-06-01']], [], 10.0],
        ];
    }

    public function test_history_sql_preserves_same_date_distinct_meeting_but_not_future(): void
    {
        $this->dbRace(900001, '2024-06-15', 900001, 105.0);
        $this->dbRace(900002, '2024-06-16', 900002, 106.0);
        $sources = $this->sources();
        $stage = Files::directory($this->root.'/boundary-sql');
        $this->service($sources)->generate($stage, $sources);
        $count = 0;
        foreach (JsonlArtifact::read($stage.'/trend-input.jsonl') as $row) {
            if ($row['year'] !== 2024) {
                continue;
            }
            $count++;
            $this->assertTrue($row['target_boundary_partial_time_order']);
            $this->assertSame([900001], $row['ambiguous_meeting_ids']);
            $this->assertSame(1, $row['ambiguous_meeting_count']);
            foreach ($row['candidates'] as $candidate) {
                $this->assertSame(['raw' => null, 'status' => 'PARTIAL_TIME_ORDER'], $candidate);
            }
        }
        $this->assertSame(7, $count);
    }

    public function test_old_new_comparison_counts_all_candidate_state_changes_without_loading_all_targets(): void
    {
        $trend = new Trend;
        $target = $this->target();
        $old = ['year' => 2025, 'race_id' => 1000, 'entry_id' => 1000] + $trend->calculate($target, $this->history());
        $sameDate = $trend->representative([$this->observation(99, $target['date'], 1, 12000, 99)]);
        $new = ['year' => 2025, 'race_id' => 1000, 'entry_id' => 1000] + $trend->calculate($target, [...$this->history(), $sameDate]);
        JsonlArtifact::write($this->root.'/old.jsonl', [$old]);
        JsonlArtifact::write($this->root.'/new.jsonl', [$new]);
        $report = (new ReviewComparison)->trendChanges($this->root.'/old.jsonl', $this->root.'/new.jsonl');
        $year = $report['years'][2025];
        $this->assertSame(1, $year['affected_targets']);
        $this->assertSame([99], $year['ambiguous_meeting_ids']);
        foreach (['candidate_state_changes', 'candidate_raw_changes', 'old_valid_to_partial_time_order'] as $kind) {
            $this->assertCount(41, $year[$kind]);
            $this->assertSame([1], array_values(array_unique($year[$kind])));
        }
    }

    #[DataProvider('representatives')]
    public function test_representative_order_and_ambiguity(?int $a, ?int $b, ?int $scoreB, string $status, ?int $score): void
    {
        $r = (new Trend)->representative([$this->observation(1, '2023-01-01', $a, 10000), $this->observation(2, '2023-01-01', $b, $scoreB)]);
        $this->assertSame($status, $r['status']);
        $this->assertSame($score, $r['score']);
        $this->assertSame($scoreB !== null && $scoreB !== 10000, $r['intra_meeting_score_drift']);
    }

    public static function representatives(): array
    {
        return [[1, 2, 10100, 'VALID', 10000], [2, 1, 10100, 'VALID', 10100], [null, 2, 10000, 'ORDER_AMBIGUOUS_SCORE_EQUAL', 10000],
            [1, 1, 10000, 'ORDER_AMBIGUOUS_SCORE_EQUAL', 10000], [null, 1, 10100, 'PARTIAL_TIME_ORDER', null], [1, 1, 10100, 'PARTIAL_TIME_ORDER', null]];
    }

    #[DataProvider('candidates')]
    public function test_fixed_grid_known_straight_line(string $id, float $value): void
    {
        $r = (new Trend)->calculate($this->target(), $this->history());
        $this->assertSame('VALID', $r['candidates'][$id]['status']);
        $this->assertEqualsWithDelta($value, $r['candidates'][$id]['raw'], 1e-12);
        $this->assertCount(41, $r['candidates']);
    }

    public static function candidates(): array
    {
        return [['MEETING_DELTA_LAG_1', 1.0], ['MEETING_DELTA_LAG_6', 6.0], ['MEETING_OLS_SLOPE_2', 1.0],
            ['MEETING_OLS_SLOPE_12', 1.0], ['MEETING_THEIL_SEN_SLOPE_6', 1.0], ['DAY_OLS_SLOPE_30', 0.1], ['DAY_THEIL_SEN_SLOPE_120', 0.1]];
    }

    public function test_irregular_days_pair_median_and_even_odd_medians(): void
    {
        $this->assertSame(2.0, Trend::slope([-73, -42, -15, 0], [-146, -84, -30, 0], false));
        $this->assertSame(2.0, Trend::slope([-73, -42, -15, 0], [-146, -84, -30, 0], true));
        $this->assertSame(2.0, Trend::slope([0, 1, 2], [0, 1, 4], true));
        $this->assertSame(2.5, Trend::median([1, 4, 2, 3]));
        $this->assertSame(2.0, Trend::median([3, 1, 2]));
        $this->assertNull(Trend::slope([0, 0], [1, 2], true));
        $this->assertNull(Trend::slope([0, 0], [1, 2], false));
    }

    public function test_day_boundary_and_missing_are_not_zero(): void
    {
        $h = array_slice($this->history(), 0, 2);
        $h[1]['start'] = $h[1]['order_date'] = '2025-05-02';
        $r = (new Trend)->calculate($this->target(), $h);
        $this->assertSame('VALID', $r['candidates']['DAY_OLS_SLOPE_30']['status']);
        $h[1]['start'] = $h[1]['order_date'] = '2025-05-01';
        $r = (new Trend)->calculate($this->target(), $h);
        $this->assertNull($r['candidates']['DAY_OLS_SLOPE_30']['raw']);
        $this->assertSame('INSUFFICIENT_POINTS_IN_DAY_WINDOW', $r['candidates']['DAY_OLS_SLOPE_30']['status']);
        $h[0]['score'] = 11000;
        $this->assertSame(0.0, (new Trend)->calculate($this->target(), $h)['candidates']['MEETING_DELTA_LAG_1']['raw']);
        $h[0]['score'] = null;
        $this->assertSame('MISSING_PREVIOUS_SCORE', (new Trend)->calculate($this->target(), $h)['candidates']['MEETING_DELTA_LAG_1']['status']);
        $this->assertSame('LEFT_TRUNCATED_POSSIBLE', (new Trend)->calculate($this->target(), [])['candidates']['MEETING_DELTA_LAG_1']['status']);
    }

    public function test_score_change_levels_and_missing_meeting_start(): void
    {
        $h = $this->history();
        for ($i = 0; $i < 3; $i++) {
            $h[$i]['score'] = 11000;
        }
        $e = (new Trend)->calculate($this->target(), $h)['events'];
        $this->assertSame(4.0, $e['LAST_SCORE_CHANGE_DELTA']);
        $this->assertSame(3, $e['MEETINGS_SINCE_LAST_SCORE_CHANGE']);
        $this->assertSame(30, $e['DAYS_SINCE_LAST_SCORE_CHANGE']);
        $this->assertSame(4, $e['CURRENT_SCORE_LEVEL_OBSERVATION_COUNT']);
        $this->assertSame(0, $e['signed_streak']);
        $t = $this->target();
        $t['start'] = null;
        $this->assertSame('MISSING_MEETING_START', (new Trend)->calculate($t, $h)['candidates']['DAY_OLS_SLOPE_30']['status']);
    }

    public function test_first_observation_true_false_unknown_without_result(): void
    {
        $t = $this->target();
        $trend = new Trend;
        $this->assertTrue($trend->firstObservation($t, [$t]));
        $prior = $t;
        $prior['entry_id'] = 999;
        $prior['ts'] = 999;
        $this->assertFalse($trend->firstObservation($t, [$prior, $t]));
        $prior['ts'] = null;
        $this->assertNull($trend->firstObservation($t, [$prior, $t]));
    }

    #[DataProvider('forbiddenInputs')]
    public function test_result_and_future_target_rejected(string $key): void
    {
        $t = $this->target();
        if ($key === '2026') {
            $t['date'] = '2026-01-01';
        } else {
            $t[$key] = 1;
        }
        $this->expectException(RuntimeException::class);
        (new Trend)->calculate($t, []);
    }

    public static function forbiddenInputs(): array
    {
        return array_map(fn ($k) => [$k], ['rank', 'status', 'normal', 'outcome', 'winner', 'result_status', '2026']);
    }

    public function test_spearman_ties_and_conditional_weight_known_values(): void
    {
        $w = new Workspace($this->root.'/math.sqlite');
        foreach ([[1.0, 1.0], [1.0, 2.0], [2.0, 3.0], [3.0, 4.0]] as $i => [$raw, $fp]) {
            $w->db->exec('INSERT INTO entries(entry_id,year,normal,fp) VALUES('.($i + 1).',2024,1,'.$fp.')');
            $w->db->exec('INSERT INTO signals VALUES('.($i + 1).",'x',".$raw.",'VALID')");
        }
        $r = (new Statistics($w->db))->rho('s.candidate=? AND e.year=?', ['x', 2024]);
        $this->assertEqualsWithDelta(0.9486832980505138, $r['rho'], 1e-12);
        $r = Statistics::weighted([['n' => 100, 'rho' => 0.1], ['n' => 300, 'rho' => 0.5], ['n' => 99, 'rho' => 1.0], ['n' => 100, 'rho' => null]]);
        $this->assertSame(0.4, $r['rho']);
        $this->assertSame(199, $r['excluded_entries']);
    }

    private function metricsFixture(): array
    {
        $years = [];
        foreach ([2024, 2025] as $year) {
            foreach (Contract::grid() as $c) {
                $years[$year][$c['id']] = ['coverage' => 1.0, 'normal_entries' => 10000, 'overall' => ['rho' => 0.1],
                    'score_conditional' => ['rho' => 0.2], 'c1_conditional' => ['rho' => 0.3], 'fp_pos_minus_neg' => 0.1,
                    'first_observation' => ['overall' => ['rho' => 0.4], 'fp_pos_minus_neg' => 0.1]];
            }
        }

        return $years;
    }

    public function test_selection_frozen_grid_robust_score_tie_coverage_and_first_subset(): void
    {
        $years = $this->metricsFixture();
        $selected = (new Selection)->select($years);
        $this->assertSame('DAY_OLS_SLOPE_120', $selected['selected']['id']);
        $this->assertSame(0.1, $selected['selected']['robust_rho']);
        $this->assertCount(41, $selected['eligible']);
        $id = 'DAY_OLS_SLOPE_120';
        $years[2025][$id]['coverage'] = 0.79999;
        $selected = (new Selection)->select($years);
        $this->assertNotSame($id, $selected['selected']['id']);
        $years[2025][$id]['coverage'] = 0.8;
        $years[2025][$id]['first_observation']['fp_pos_minus_neg'] = 0.0;
        $selected = (new Selection)->select($years);
        $this->assertNotContains($id, array_column($selected['eligible'], 'id'));
        foreach ($years[2025] as &$m) {
            $m['normal_entries'] = 9999;
        }
        unset($m);
        $this->assertSame('NO_STABLE_GROWTH_GRANULARITY_SELECTED', (new Selection)->select($years)['status']);
    }

    public function test_unsealed_outcome_is_rejected_before_opening_nonexistent_file(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Trend must be sealed');
        iterator_to_array((new TemporalAccess)->outcomes(2024, '/nonexistent', app(OuterSource::class)));
    }

    private function sources(): array
    {
        $score = app(ScoreService::class)->capture($this->root.'/score', 'fixture', $this->root.'/outer');

        return app(Sources::class)->open($score['bundle'], $this->root.'/outer', $this->root.'/meeting');
    }

    private function service(array $sources): Service
    {
        $reader = app(Sources::class);

        return new class($reader, app(OuterSource::class), app(Bundle::class), app(Code::class), new Trend) extends Service
        {
            protected function old(Workspace $w, Statistics $s, TemporalAccess $access, array $years, array $selection): array
            {
                $access->authorize();

                return ['synthetic_reference' => true];
            }

            protected function comparison(string $stage, array $sources, array $years, array $selection, array $outputs, array $expected, TemporalAccess $access): array
            {
                $access->authorize();

                $compare = new ReviewComparison;

                return $compare->compare($years, $years, $selection, $selection)
                    + ['same_date_audit' => $compare->trendChanges($stage.'/trend-input.jsonl', $stage.'/trend-input.jsonl')];
            }
        };
    }

    public function test_review_comparison_accepts_manifest_row_count_and_still_rejects_real_source_drift(): void
    {
        $sources = $this->sources();
        $result = $this->service($sources)->execute($this->root.'/analysis', 'reference', $sources['score'], $this->root.'/outer', $this->root.'/meeting');
        $path = $result['bundle'];
        $manifest = Files::json($path.'/manifest.json');
        $reference = new class($path, Files::identity($path.'/manifest.json')['sha256']) extends ReviewComparison
        {
            public function __construct(private readonly string $path, private readonly string $hash) {}

            protected function referencePath(): string
            {
                return $this->path;
            }

            protected function referenceHash(): string
            {
                return $this->hash;
            }
        };
        $years = [];
        foreach ([2024, 2025] as $year) {
            $years[$year] = Files::json($path.'/candidate-results-'.$year.'.json');
        }
        $selection = Files::json($path.'/granularity-selection.json');
        $outputs = [];
        foreach (['local-stability.json', 'c1-confidence-diagnostics.json', 'missed-winner-diagnostics.json',
            'grade-class-diagnostics.json', 'score-change-event-diagnostics.json'] as $name) {
            $outputs[$name] = Files::json($path.'/'.$name);
        }
        $access = new TemporalAccess;
        $access->seal($path, Files::json($path.'/trend-input-seal.json'));
        $this->assertArrayHasKey('rows', $sources['files'][$sources['score'].'/score-observations.jsonl']);
        $report = $reference->report($path, $sources, $years, $selection, $outputs, $manifest['files'], $access);
        $this->assertTrue($report['source_observation_identity_same']);
        $this->assertTrue($report['all_candidate_metrics_same']);
        $h = fopen($sources['score'].'/score-observations.jsonl', 'r+b');
        fwrite($h, '[');
        fclose($h);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Score observations changed from initial run; STOP.');
        $reference->report($path, $sources, $years, $selection, $outputs, $manifest['files'], $access);
    }

    public function test_db_disabled_execute_reproduce_byte_exact_and_labels_can_be_withheld_until_seal(): void
    {
        $sources = $this->sources();
        $service = $this->service($sources);
        config(['database.default' => 'growth_disabled']);
        $result = $service->execute($this->root.'/analysis', 'fixture', $sources['score'], $this->root.'/outer', $this->root.'/meeting');
        $this->assertSame('NO_STABLE_GROWTH_GRANULARITY_SELECTED', $result['status']);
        $reproduced = $service->reproduce($this->root.'/analysis', 'fixture');
        $this->assertSame('BYTE_EXACT', $reproduced['status']);
        $outcomeFiles = [];
        foreach ($this->labels as $path) {
            array_push($outcomeFiles, $path, $path.'.manifest.json');
        }
        foreach ($outcomeFiles as $path) {
            rename($path, $path.'.withheld');
        }
        $stage = Files::directory($this->root.'/withholding');
        $result2 = $service->generate($stage, $sources, function ($stage) use ($outcomeFiles): void {
            $this->assertFileExists($stage.'/trend-input-seal.json');
            foreach ($outcomeFiles as $path) {
                $this->assertFileDoesNotExist($path);
                rename($path.'.withheld', $path);
            }
        });
        $this->assertSame(Files::identity($result['bundle'].'/trend-input.jsonl'), Files::identity($stage.'/trend-input.jsonl'));
        $this->assertSame(Files::json($result['bundle'].'/manifest.json')['files'], $result2['expected']);
        $audit = Files::json($stage.'/temporal-access-audit.json');
        $events = array_column($audit['events'], 'event');
        $this->assertGreaterThan(array_search('TREND_INPUT_SEALED', $events), array_search('2024_OUTCOME_OPEN', $events));
        $this->assertGreaterThan(array_search('TREND_INPUT_SEALED', $events), array_search('2025_OUTCOME_OPEN', $events));
        $this->assertSame(0, $audit['preseal_outcome_access']);
        $this->assertSame(0, $audit['preseal_label_hash_access']);
        foreach ([2024, 2025] as $year) {
            $this->assertGreaterThan(array_search('TREND_INPUT_SEALED', $events), array_search($year.'_OUTCOME_SOURCE_RESOLVED', $events));
            $this->assertLessThan(array_search($year.'_OUTCOME_OPEN', $events), array_search($year.'_OUTCOME_SOURCE_RESOLVED', $events));
        }
        foreach (JsonlArtifact::read($stage.'/trend-input.jsonl') as $row) {
            foreach (['rank', 'result_status', 'winner', 'normal', 'hit', 'finish_percentile'] as $key) {
                $this->assertArrayNotHasKey($key, $row);
            }
        }
        $this->assertLessThan(128 * 1024 * 1024, memory_get_peak_usage(true));
    }

    #[DataProvider('outcomeYears')]
    public function test_outcome_mutation_does_not_change_trend_hash(int $year): void
    {
        $sources = $this->sources();
        $service = $this->service($sources);
        $a = Files::directory($this->root.'/a');
        $service->generate($a, $sources);
        $path = $this->labels[$year];
        $rows = iterator_to_array(JsonlArtifact::read($path));
        foreach ($rows[0]['entries'] as &$entry) {
            $entry['rank'] = 8 - $entry['rank'];
        }
        unset($entry);
        unlink($path);
        unlink($path.'.manifest.json');
        JsonlArtifact::write($path, $rows);
        $registry = Files::json($this->root.'/outer/report-export-manifest.json');
        foreach ([$path, $path.'.manifest.json'] as $file) {
            $registry['included'][substr($file, strlen($this->root.'/outer/'))] = Files::identity($file);
        }
        unlink($this->root.'/outer/report-export-manifest.json');
        JsonlArtifact::json($this->root.'/outer/report-export-manifest.json', $registry);
        $fresh = app(Sources::class)->open($sources['score'], $this->root.'/outer', $this->root.'/meeting');
        $this->assertSame($sources, $fresh);
        $recaptured = app(ScoreService::class)->capture($this->root.'/score', 'changed-outcome', $this->root.'/outer');
        foreach (['target-universe.json', 'manifest.json', 'score-observations.jsonl'] as $name) {
            $this->assertSame(Files::identity($sources['score'].'/'.$name), Files::identity($recaptured['bundle'].'/'.$name));
        }
        $b = Files::directory($this->root.'/b');
        $service->generate($b, $fresh);
        foreach (['sources.json', 'contract.json', 'candidate-grid.json', 'code.json', 'score-source-verification.json',
            'ability-bins.json', 'trend-input.jsonl', 'trend-input.jsonl.manifest.json', 'trend-input-seal.json'] as $name) {
            $this->assertSame(Files::identity($a.'/'.$name), Files::identity($b.'/'.$name), $name);
        }
        $this->assertNotSame(Files::identity($a.'/outcome-sources.json'), Files::identity($b.'/outcome-sources.json'));
        $this->assertNotSame(Files::identity($a.'/candidate-results-'.$year.'.json'), Files::identity($b.'/candidate-results-'.$year.'.json'));
    }

    public static function outcomeYears(): array
    {
        return [[2024], [2025]];
    }

    public function test_generated_artifact_tampering_is_rejected(): void
    {
        $sources = $this->sources();
        $service = $this->service($sources);
        $r = $service->execute($this->root.'/analysis', 'tamper', $sources['score'], $this->root.'/outer', $this->root.'/meeting');
        file_put_contents($r['bundle'].'/candidate-grid.json', '{}');
        $this->expectException(RuntimeException::class);
        $service->reproduce($this->root.'/analysis', 'tamper');
    }

    public function test_fixed_source_tampering_rejected_before_trend_or_outcome(): void
    {
        $sources = $this->sources();
        file_put_contents($sources['score'].'/score-observations.jsonl', '{}');
        $stage = Files::directory($this->root.'/tampered-source');
        try {
            $this->service($sources)->generate($stage, $sources);
            $this->fail('Tampered source accepted.');
        } catch (RuntimeException) {
            $this->assertFileDoesNotExist($stage.'/trend-input.jsonl');
        }
    }

    public function test_code_drift_during_generation_is_rejected_before_publication(): void
    {
        $sources = $this->sources();
        $this->app->instance(Code::class, new class extends Code
        {
            private int $calls = 0;

            public function capture(bool $analysis = true): array
            {
                return parent::capture($analysis) + ['synthetic_drift' => ++$this->calls];
            }
        });
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('analysis code END');
        $this->service($sources)->execute($this->root.'/analysis', 'code-drift', $sources['score'], $this->root.'/outer', $this->root.'/meeting');
    }

    public function test_equal_values_are_not_split_and_bins_are_deterministic(): void
    {
        $w = new Workspace($this->root.'/bins.sqlite');
        $q = $w->db->prepare('INSERT INTO entries(entry_id,race_id,year,score,p1,margin) VALUES(?,?,2024,?,?,?)');
        for ($i = 1; $i <= 30; $i++) {
            $value = $i <= 15 ? 10 : 20;
            $q->execute([$i, $i, $value, $value / 100, $value / 1000]);
        }
        $s = new Statistics($w->db);
        $a = $s->bins();
        $this->assertSame($a, $s->bins());
        $this->assertSame(2, $a[2024]['score']['effective_bins']);
        $this->assertSame(2, $a[2024]['p1']['effective_bins']);
        $this->assertSame(2, $a[2024]['margin']['effective_bins']);
        $this->assertSame(1, (int) $w->db->query('SELECT count(DISTINCT score_bin) FROM entries WHERE score=10')->fetchColumn());
    }

    public function test_future_history_is_rejected(): void
    {
        $h = $this->history();
        $h[0]['order_date'] = '2026-01-01';
        $this->expectException(RuntimeException::class);
        (new Trend)->calculate($this->target(), $h);
    }

    public function test_sealed_input_drift_rejected_before_outcome_evaluation(): void
    {
        $sources = $this->sources();
        $stage = Files::directory($this->root.'/seal-drift');
        try {
            $this->service($sources)->generate($stage, $sources, function ($stage): void {
                file_put_contents($stage.'/candidate-grid.json', '{}');
            });
            $this->fail('Sealed input drift accepted.');
        } catch (RuntimeException) {
            $this->assertFileDoesNotExist($stage.'/candidate-results-2024.json');
            $this->assertFileDoesNotExist($stage.'/manifest.json');
        }
    }

    public function test_selected_candidate_diagnostics_match_independent_winner_gaps(): void
    {
        $w = new Workspace($this->root.'/diagnostics.sqlite');
        $entry = $w->db->prepare('INSERT INTO entries(entry_id,race_id,year,bike,score,p1,margin,predicted,first_obs,grade,class,n,margin_bin,normal,rank,fp,unique_winner) VALUES(?,?,?,?,10000,0.1,0.1,?,1,\'F2\',\'A1_A2\',7,1,1,?,?,1)');
        $signal = $w->db->prepare('INSERT INTO signals VALUES(?,?,?,\'VALID\')');
        $w->db->beginTransaction();
        for ($race = 1; $race <= 200; $race++) {
            for ($bike = 1; $bike <= 7; $bike++) {
                $rank = $bike === 1 ? 2 : ($bike === 2 ? 1 : $bike);
                $entry->execute([$race * 10 + $bike, $race, $race <= 100 ? 2024 : 2025, $bike, (int) ($bike === 1), $rank, (7 - $rank) / 6]);
                $signal->execute([$race * 10 + $bike, 'MEETING_DELTA_LAG_1', $bike]);
            }
        }
        $w->db->commit();
        $selection = ['selected' => ['id' => 'MEETING_DELTA_LAG_1', 'family' => 'MEETING_DELTA', 'grain' => 1]];
        $result = (new Diagnostics)->run(new Statistics($w->db), $selection, $this->metricsFixture(), ['distributions' => []]);
        foreach ([2024, 2025] as $year) {
            $gaps = $result['missed-winner-diagnostics.json'][$year];
            $this->assertSame(100, $gaps['n']);
            $this->assertSame(1.0, $gaps['mean']);
            $this->assertSame(1.0, $gaps['median']);
            $this->assertEquals(1, $gaps['positive_fraction']);
            $this->assertSame(0, $result['c1-confidence-diagnostics.json'][$year][1]['c1_candidate_win']['wins']);
        }
        $this->assertLessThan(128 * 1024 * 1024, memory_get_peak_usage(true));
    }

    #[DataProvider('dayFamilies')]
    public function test_missing_past_starts_are_excluded_only_from_day_candidates(string $family): void
    {
        $trend = new Trend;
        $history = array_slice($this->history(), 0, 10);
        $before = $trend->calculate($this->target(), $history);
        $history[0]['start'] = null;
        $after = $trend->calculate($this->target(), $history);
        $this->assertSame('VALID', $after['candidates'][$family.'_30']['status']);
        $this->assertEqualsWithDelta(0.1, $after['candidates'][$family.'_30']['raw'], 1e-12);
        $this->assertSame(1, $after['day_exclusions'][$family.'_30']);
        foreach (Contract::grid() as $c) {
            if (str_starts_with($c['family'], 'MEETING_')) {
                $this->assertSame($before['candidates'][$c['id']], $after['candidates'][$c['id']]);
            }
        }
        $short = $trend->calculate($this->target(), array_slice($history, 0, 2));
        $this->assertSame('INSUFFICIENT_POINTS_IN_DAY_WINDOW', $short['candidates'][$family.'_30']['status']);
        $history = $this->history();
        $history[11]['start'] = null;
        $outside = $trend->calculate($this->target(), $history);
        $this->assertSame($before['candidates'][$family.'_30'], $outside['candidates'][$family.'_30']);
        $target = $this->target();
        $target['start'] = null;
        $this->assertSame('MISSING_MEETING_START', $trend->calculate($target, $history)['candidates'][$family.'_30']['status']);
    }

    public static function dayFamilies(): array
    {
        return [['DAY_OLS_SLOPE'], ['DAY_THEIL_SEN_SLOPE']];
    }

    public function test_day_exclusions_are_auditable_per_year_and_candidate(): void
    {
        DB::table('race_meetings')->where('id', 202401)->update(['starts_on' => null]);
        $sources = $this->sources();
        $stage = Files::directory($this->root.'/null-start');
        $this->service($sources)->generate($stage, $sources);
        $audit = Files::json($stage.'/day-start-exclusions.json');
        $this->assertSame(7, $audit['source_observations_missing_start']);
        $this->assertSame(1, $audit['source_meetings_missing_start']);
        $this->assertCount(28, $audit['candidates']);
        foreach ($audit['candidates'] as $row) {
            $this->assertSame(7, $row['prior_meetings_with_unknown_start']);
            $this->assertSame(7, $row['targets_with_unknown_start_prior']);
        }
    }

    public function test_confidence_win_rate_has_normal_denominator_and_null_when_empty(): void
    {
        $w = new Workspace($this->root.'/win-denominator.sqlite');
        $q = $w->db->prepare('INSERT INTO entries(entry_id,race_id,year,predicted,margin_bin,normal,rank,grade,class) VALUES(?,?,2024,1,?,?,?,\'F2\',\'A1_A2\')');
        foreach ([[1, 1, 1, 1], [2, 1, 1, 2], [3, 1, 0, null], [4, 2, 0, null]] as [$id, $bin, $normal, $rank]) {
            $q->execute([$id, $id, $bin, $normal, $rank]);
        }
        $selection = ['selected' => ['id' => 'MEETING_DELTA_LAG_1', 'family' => 'MEETING_DELTA', 'grain' => 1]];
        $r = (new Diagnostics)->run(new Statistics($w->db), $selection, $this->metricsFixture(), ['distributions' => []]);
        $this->assertSame(['all_predicted_entries' => 3, 'normal_predicted_entries' => 2, 'wins' => 1,
            'normal_win_rate' => 0.5, 'all_prediction_denominator_rate' => 1 / 3], $r['c1-confidence-diagnostics.json'][2024][1]['c1_candidate_win']);
        $this->assertSame(0, $r['c1-confidence-diagnostics.json'][2024][2]['c1_candidate_win']['normal_predicted_entries']);
        $this->assertNull($r['c1-confidence-diagnostics.json'][2024][2]['c1_candidate_win']['normal_win_rate']);
    }

    public function test_meeting_outcome_only_manifest_change_does_not_change_preseal_identity(): void
    {
        $sources = $this->sources();
        $service = $this->service($sources);
        $a = Files::directory($this->root.'/meeting-a');
        $service->generate($a, $sources);
        $manifest = Files::json($sources['meeting'].'/manifest.json');
        $manifest['files']['outcome-only.json'] = ['bytes' => 99, 'sha256' => str_repeat('a', 64)];
        unlink($sources['meeting'].'/manifest.json');
        JsonlArtifact::json($sources['meeting'].'/manifest.json', $manifest);
        JsonlArtifact::json($sources['meeting'].'/LOCKED.json', Files::identity($sources['meeting'].'/manifest.json'));
        $fresh = app(Sources::class)->open($sources['score'], $sources['outer']['root'], $sources['meeting']);
        $this->assertSame($sources, $fresh);
        $b = Files::directory($this->root.'/meeting-b');
        $service->generate($b, $fresh);
        foreach (['sources.json', 'trend-input.jsonl', 'trend-input-seal.json'] as $name) {
            $this->assertSame(Files::identity($a.'/'.$name), Files::identity($b.'/'.$name));
        }
    }

    #[DataProvider('projectedInputs')]
    public function test_projected_input_one_byte_drift_fails_before_seal(string $kind): void
    {
        $sources = $this->sources();
        $path = match ($kind) {
            'input' => $sources['outer']['years'][2024]['input'],
            'prediction' => $sources['outer']['years'][2025]['prediction'],
            'score' => $sources['score'].'/score-observations.jsonl',
            default => $sources['meeting'].'/'.$kind,
        };
        $h = fopen($path, 'r+b');
        fwrite($h, '[');
        fclose($h);
        try {
            $this->service($sources)->execute($this->root.'/analysis', 'drift', $sources['score'], $sources['outer']['root'], $sources['meeting']);
            $this->fail('Drift accepted.');
        } catch (RuntimeException) {
            $this->assertDirectoryDoesNotExist($this->root.'/analysis/evaluations/drift');
            $this->assertSame([], glob($this->root.'/analysis/.staging/*/trend-input-seal.json'));
        }
    }

    public static function projectedInputs(): array
    {
        return [['input'], ['prediction'], ['metadata.jsonl'], ['meetings.json'], ['score']];
    }

    public function test_unsealed_outcome_identity_resolution_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Trend must be sealed');
        app(OuterSource::class)->openOutcomeSource('/does-not-exist', 2024, new TemporalAccess);
    }

    public function test_access_counters_record_and_reject_a_preseal_access_attempt(): void
    {
        foreach (['export_manifest_open', 'label_identity_resolve', 'label_file_open'] as $kind) {
            $access = new TemporalAccess;
            try {
                $access->observe($kind);
                $this->fail('Preseal access accepted.');
            } catch (RuntimeException $e) {
                $this->assertSame('Outcome access path reached before trend seal.', $e->getMessage());
                $this->assertSame(1, $access->counts()['preseal_'.$kind.'_count']);
                $this->assertSame(0, $access->counts()['postseal_'.$kind.'_count']);
            }
        }
    }

    public function test_reviewed_fixed_projection_literals_match_their_immutable_contract_hashes(): void
    {
        foreach ([[Sources::class, 'meetingFiles', 'meetingProjectionHash'], [OuterSource::class, 'fixedFiles', 'projectionHash']] as [$class, $files, $hash]) {
            $reflection = new \ReflectionClass($class);
            $object = $reflection->newInstanceWithoutConstructor();
            $projection = $reflection->getMethod($files)->invoke($object);
            if ($class === OuterSource::class) {
                $projection = ['run' => 'run-01', 'files' => $projection];
            }
            $this->assertSame($reflection->getMethod($hash)->invoke($object), hash('sha256', Files::canonical($projection)));
        }
    }

    public function test_preseal_source_needs_no_label_files_or_registered_label_identity(): void
    {
        $registryPath = $this->root.'/outer/report-export-manifest.json';
        foreach ($this->labels as $path) {
            foreach ([$path, $path.'.manifest.json'] as $file) {
                rename($file, $file.'.withheld');
            }
        }
        rename($registryPath, $registryPath.'.withheld');
        rename($this->root.'/meeting/manifest.json', $this->root.'/meeting/manifest.json.withheld');
        $sources = $this->sources();
        $stage = Files::directory($this->root.'/no-label-identity');
        $this->service($sources)->generate($stage, $sources, function ($stage) use ($registryPath): void {
            $this->assertFileExists($stage.'/trend-input-seal.json');
            $this->assertFileDoesNotExist($registryPath);
            $this->assertFileDoesNotExist($this->root.'/meeting/manifest.json');
            foreach (['sources.json', 'trend-input-seal.json'] as $name) {
                $text = file_get_contents($stage.'/'.$name);
                $this->assertStringNotContainsString('labels-', $text);
                $this->assertStringNotContainsString('report-export-manifest', $text);
                foreach ($this->labels as $path) {
                    $this->assertFileDoesNotExist($path);
                    $this->assertStringNotContainsString(hash_file('sha256', $path.'.withheld'), $text);
                }
            }
            foreach ($this->labels as $path) {
                foreach ([$path, $path.'.manifest.json'] as $file) {
                    rename($file.'.withheld', $file);
                }
            }
            rename($registryPath.'.withheld', $registryPath);
            rename($this->root.'/meeting/manifest.json.withheld', $this->root.'/meeting/manifest.json');
        });
        $this->assertFileExists($stage.'/outcome-sources.json');
        $audit = Files::json($stage.'/outcome-isolation-audit.json');
        foreach (['export_manifest_open', 'label_identity_resolve', 'label_file_open'] as $kind) {
            $this->assertSame(0, $audit['preseal_'.$kind.'_count']);
            $this->assertSame(2, $audit['postseal_'.$kind.'_count']);
        }
    }

    public function test_resealed_projected_input_cannot_replace_the_frozen_projection(): void
    {
        $path = $this->outer['years'][2024]['input'];
        file_put_contents($path, ' '.file_get_contents($path));
        $registryPath = $this->root.'/outer/report-export-manifest.json';
        $registry = Files::json($registryPath);
        $registry['included']['inputs-v2/inputs-2024.jsonl'] = Files::identity($path);
        unlink($registryPath);
        JsonlArtifact::json($registryPath, $registry);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Artifact hash/size mismatch');
        app(OuterSource::class)->openOutcomeFree($this->root.'/outer');
    }
}
