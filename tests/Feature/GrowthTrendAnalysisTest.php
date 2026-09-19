<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Backtest\Experiments\GrowthTrendAnalysis\Contract;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendAnalysis\Diagnostics;
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
        iterator_to_array((new TemporalAccess)->outcomes(2024, '/nonexistent', []));
    }

    private function sources(): array
    {
        $score = app(ScoreService::class)->capture($this->root.'/score', 'fixture', $this->root.'/outer');
        $files = $this->outer['files'];
        foreach (['metadata.jsonl', 'metadata.jsonl.manifest.json', 'meetings.json'] as $name) {
            $files[$this->root.'/meeting/'.$name] = Files::identity($this->root.'/meeting/'.$name);
        }
        foreach (Files::json($score['bundle'].'/manifest.json')['files'] as $name => $seal) {
            $files[$score['bundle'].'/'.$name] = $seal;
        }

        return ['outer' => $this->outer, 'files' => $files, 'score' => $score['bundle'], 'meeting' => $this->root.'/meeting'];
    }

    private function service(array $sources): Service
    {
        $reader = new class($sources) extends Sources
        {
            public function __construct(private readonly array $fixture) {}

            public function open(string $scorePath, string $outerRoot, string $meetingPath): array
            {
                return $this->fixture;
            }
        };

        return new class($reader, app(OuterSource::class), app(Bundle::class), app(Code::class), new Trend) extends Service
        {
            protected function old(Workspace $w, Statistics $s, TemporalAccess $access, array $years, array $selection): array
            {
                $access->authorize();

                return ['synthetic_reference' => true];
            }
        };
    }

    public function test_db_disabled_execute_reproduce_byte_exact_and_labels_can_be_withheld_until_seal(): void
    {
        $sources = $this->sources();
        $service = $this->service($sources);
        config(['database.default' => 'growth_disabled']);
        $result = $service->execute($this->root.'/analysis', 'fixture', '', '', '');
        $this->assertSame('NO_STABLE_GROWTH_GRANULARITY_SELECTED', $result['status']);
        $reproduced = $service->reproduce($this->root.'/analysis', 'fixture');
        $this->assertSame('BYTE_EXACT', $reproduced['status']);
        foreach ($sources['outer']['deferred'] as $path => $seal) {
            rename($path, $path.'.withheld');
        }
        $stage = Files::directory($this->root.'/withholding');
        $result2 = $service->generate($stage, $sources, function ($stage) use ($sources): void {
            $this->assertFileExists($stage.'/trend-input-seal.json');
            foreach ($sources['outer']['deferred'] as $path => $seal) {
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
        $path = $sources['outer']['years'][$year]['labels'];
        $rows = iterator_to_array(JsonlArtifact::read($path));
        foreach ($rows[0]['entries'] as &$entry) {
            $entry['rank'] = 8 - $entry['rank'];
        }
        unset($entry);
        unlink($path);
        unlink($path.'.manifest.json');
        JsonlArtifact::write($path, $rows);
        foreach ([$path, $path.'.manifest.json'] as $file) {
            $sources['outer']['deferred'][$file] = Files::identity($file);
        }
        $b = Files::directory($this->root.'/b');
        $service->generate($b, $sources);
        $this->assertSame(Files::identity($a.'/trend-input.jsonl'), Files::identity($b.'/trend-input.jsonl'));
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
        $r = $service->execute($this->root.'/analysis', 'tamper', '', '', '');
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
        $this->service($sources)->execute($this->root.'/analysis', 'code-drift', '', '', '');
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
}
