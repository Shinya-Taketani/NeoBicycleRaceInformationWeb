<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysis\Analysis as V1Analysis;
use App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysis\Contract as V1Contract;
use App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysis\Signals;
use App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysis\Store as V1Store;
use App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysis\Workspace;
use App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysisV2\Analysis;
use App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysisV2\Contract;
use App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysisV2\Service;
use App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysisV2\Sources;
use App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysisV2\Store;
use App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysisV2\Thresholds;
use App\Domain\Keirin\Backtest\Experiments\TacticalGradeAnalysis\Aggregator;
use App\Domain\Keirin\Backtest\Experiments\TacticalGradeAnalysis\AnalysisStore;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline\ArtifactStore;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\ResultStore;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class GrowthPointAnalysisV2Test extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        DB::shouldReceive('connection')->never();
        $this->dir = Files::directory(sys_get_temp_dir().'/growth-v2-test-'.bin2hex(random_bytes(8)));
        config(['tactical_prediction_pipeline.artifact_base' => $this->dir]);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    #[DataProvider('boundaries')]
    public function test_sign_preserving_boundaries(?float $raw, ?int $point): void
    {
        $actual = Contract::point($raw, $this->thresholds());
        $this->assertSame($point, $actual['point']);
        $this->assertSame($raw === null ? 'MISSING_RAW' : 'VALID', $actual['point_status']);
    }

    public static function boundaries(): array
    {
        return [[null, null], [-0.0, 0], [0.0, 0], [-0.01, -1], [-1.0, -2], [-1.99, -2], [-2.0, -3], [-3.0, -3],
            [0.01, 1], [1.0, 2], [1.99, 2], [2.0, 3], [3.0, 3]];
    }

    #[DataProvider('signs')]
    public function test_tied_boundaries_no_epsilon_or_zero_remapping(int $sign): void
    {
        $q = $this->thresholds();
        $q['negative']['values'] = $q['positive']['values'] = [1.0, 1.0];
        $this->assertSame($sign, Contract::point($sign * 0.9, $q)['point']);
        $this->assertSame($sign * 3, Contract::point((float) $sign, $q)['point']);
        $this->assertSame(0, Contract::point(0.0, $q)['point']);
    }

    public static function signs(): array
    {
        return [[-1], [1]];
    }

    #[DataProvider('smallSides')]
    public function test_small_side_fail_visible_but_zero_is_always_zero(int $sign, int $n): void
    {
        $q = $this->thresholds();
        $q[$sign < 0 ? 'negative' : 'positive'] = ['n' => $n, 'values' => null];
        $this->assertSame(['point' => null, 'point_status' => 'INSUFFICIENT_SIGN_TRAINING'], Contract::point((float) $sign, $q));
        $this->assertSame(0, Contract::point(0, $q)['point']);
        $this->assertSame('MISSING_RAW', Contract::point(null, $q)['point_status']);
    }

    public static function smallSides(): array
    {
        return [[-1, 0], [-1, 1], [-1, 2], [1, 0], [1, 1], [1, 2]];
    }

    public function test_type7_sign_distribution_uses_only_prior_years_and_excludes_zero(): void
    {
        $w = new Workspace($this->dir.'/q.sqlite');
        $q = $w->db->prepare('INSERT INTO training VALUES(?,?,?)');
        foreach ([-3, -2, -1, 0, 0, 0, 1, 2, 3] as $raw) {
            $q->execute([2022, 'SCORE', $raw]);
        }
        $q->execute([2023, 'SCORE', 0]);
        $q->execute([2024, 'SCORE', 9000]);
        $q->execute([2025, 'SCORE', 900000]);
        $before = (new Thresholds)->calculate($w);
        $this->assertSame(4, $before[2024]['SCORE']['zero_n']);
        $this->assertSame(3, $before[2024]['SCORE']['negative']['n']);
        $this->assertSame([1 + 2 * Contract::QUANTILES[0], 2 + (2 * Contract::QUANTILES[1] - 1)], $before[2024]['SCORE']['positive']['values']);
        $this->assertSame($before[2024]['SCORE']['negative'], $before[2024]['SCORE']['positive']);
        $w->db->exec('UPDATE training SET raw=-99999 WHERE year=2025');
        $this->assertSame($before, (new Thresholds)->calculate($w));
        $w->db->exec('UPDATE training SET raw=-8000 WHERE year=2024');
        $this->assertSame($before[2024], (new Thresholds)->calculate($w)[2024]);
        $this->assertNotSame($before[2025], (new Thresholds)->calculate($w)[2025]);
        $q->execute([2022, 'PERFORMANCE', 1]);
        $q->execute([2023, 'PERFORMANCE', 2]);
        $this->assertNull((new Thresholds)->calculate($w)[2024]['PERFORMANCE']['positive']['values']);
    }

    public function test_mass_zero_is_not_negative_and_bounded_memory(): void
    {
        $w = new Workspace($this->dir.'/mass.sqlite');
        $q = $w->db->prepare("INSERT INTO training VALUES(2023,'SCORE',?)");
        $w->db->beginTransaction();
        for ($i = 0; $i < 110000; $i++) {
            $q->execute([0]);
        }
        foreach ([-3, -2, -1, 1, 2, 3] as $raw) {
            $q->execute([$raw]);
        }
        $w->db->commit();
        $thresholds = (new Thresholds)->calculate($w);
        $this->assertSame(110000, $thresholds[2024]['SCORE']['zero_n']);
        $this->assertSame(0, Contract::point(0.0, $thresholds[2024]['SCORE'])['point']);
        $this->assertLessThan(128 * 1024 * 1024, memory_get_peak_usage(true));
    }

    public function test_v1_v2_full_rows_raw_correlations_and_db_disabled_reproduction(): void
    {
        [$root, $source] = $this->fixture();
        $before = app(V1Store::class)->verify($source);
        $execute = app(Service::class)->execute($root, 'synthetic', $source);
        $reproduce = app(Service::class)->reproduce($root, 'synthetic');
        $this->assertSame('NONE', $execute['database']);
        $this->assertSame('NONE', $reproduce['database']);
        $this->assertSame($execute['details'], $reproduce['details']);
        $this->assertSame($execute['summary'], $reproduce['summary']);
        $this->assertSame(10, $execute['raw_verification']['entries']);
        $this->assertSame(10, $execute['raw_verification']['input_exact_matches']);
        $this->assertSame(0, $execute['raw_verification']['raw_mismatches']);
        foreach ($execute['raw_verification']['same_meeting_score'] as $same) {
            $this->assertSame($same['entries'], $same['raw_zero']);
            $this->assertSame($same['entries'], $same['point_zero']);
        }
        $this->assertSame($before, app(V1Store::class)->verify($source));
        $comparison = Files::json($execute['path'].'/comparison-v1-v2.json');
        $this->assertTrue($comparison['raw_spearman_exactly_equal_all_strata']);
        foreach (Files::json($execute['path'].'/point-transition.json') as $transition) {
            $this->assertSame(0, array_sum($transition['sign_violations']));
        }
    }

    public function test_target_outcome_change_does_not_change_raw_or_point(): void
    {
        [$root1,$source1] = $this->fixture('a');
        [$root2,$source2] = $this->fixture('b', true);
        $a = app(Service::class)->execute($root1, 'outcomes', $source1);
        $b = app(Service::class)->execute($root2, 'outcomes', $source2);
        $rows1 = iterator_to_array(JsonlArtifact::read($a['path'].'/growth-details-v2.jsonl'));
        $rows2 = iterator_to_array(JsonlArtifact::read($b['path'].'/growth-details-v2.jsonl'));
        $this->assertSame(array_column($rows1, 'signals'), array_column($rows2, 'signals'));
        $this->assertNotSame(array_column($rows1, 'rank'), array_column($rows2, 'rank'));
        $this->assertSame(Files::json($a['path'].'/thresholds-v2.json'), Files::json($b['path'].'/thresholds-v2.json'));
    }

    #[DataProvider('tamperCases')]
    public function test_tampering_is_rejected_without_publication(string $kind): void
    {
        [$root,$source] = $this->fixture();
        if ($kind === 'source') {
            file_put_contents($source.'/growth-details.jsonl', 'X', FILE_APPEND);
        } elseif ($kind === 'source_end') {
            $this->app->bind(Sources::class, fn () => new class(app(V1Store::class)) extends Sources
            {
                private int $calls = 0;

                public function verify(array $source): void
                {
                    if (++$this->calls === 2) {
                        file_put_contents($source['path'].'/history.jsonl', 'X', FILE_APPEND);
                    }
                    parent::verify($source);
                }
            });
        } else {
            $this->app->bind(Store::class, fn () => new class(app(ResultStore::class)) extends Store
            {
                public function publish(string $stage, string $destination, array $expected, array $summary): array
                {
                    file_put_contents($stage.'/summary-v2.json', "\n", FILE_APPEND);

                    return parent::publish($stage, $destination, $expected, $summary);
                }
            });
        }
        try {
            app(Service::class)->execute($root, 'tamper', $source);
            $this->fail('Tamper was not rejected.');
        } catch (RuntimeException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertDirectoryDoesNotExist($root.'/evaluations/tamper');
    }

    public static function tamperCases(): array
    {
        return [['source'], ['source_end'], ['generated']];
    }

    public function test_v2_published_tamper_and_duplicate_execute_rejected(): void
    {
        [$root,$source] = $this->fixture();
        $r = app(Service::class)->execute($root, 'locked', $source);
        try {
            app(Service::class)->execute($root, 'locked', $source);
            $this->fail('Overwrite accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('use reproduce', $e->getMessage());
        }
        file_put_contents($r['path'].'/point-transition.json', 'X', FILE_APPEND);
        $this->expectException(RuntimeException::class);
        app(Service::class)->reproduce($root, 'locked');
    }

    public function test_plan_never_opens_source_or_database(): void
    {
        $this->mock(Sources::class)->shouldNotReceive('open');
        $this->artisan('keirin:backtest:growth-point-analysis-v2', ['--plan' => true])->assertExitCode(0);
    }

    public function test_mean_finish_monotonicity_does_not_change_v1_classifier(): void
    {
        $cells = [['point' => -1, 'normal' => 100, 'win_rate' => .1, 'top3_rate' => .3, 'mean_fp' => .5],
            ['point' => 0, 'normal' => 100, 'win_rate' => .2, 'top3_rate' => .4, 'mean_fp' => .4],
            ['point' => 1, 'normal' => 100, 'win_rate' => .3, 'top3_rate' => .5, 'mean_fp' => .6]];
        $rho = ['rho' => .1, 'n' => 1000, 'players' => 30, 'races' => 100];
        $this->assertSame('POSITIVE_MONOTONIC_TENDENCY', app(V1Analysis::class)->classify($cells, $rho, $rho)['label']);
        $extra = app(Analysis::class)->monotonicity($cells);
        $this->assertSame(1, $extra['increasing_violations']['mean_fp']);
        $this->assertSame(0, $extra['increasing_violations']['win_rate']);
    }

    private function thresholds(): array
    {
        return ['negative' => ['n' => 3, 'values' => [1.0, 2.0]], 'positive' => ['n' => 3, 'values' => [1.0, 2.0]]];
    }

    private function fixture(string $name = 'fixture', bool $changeOutcome = false): array
    {
        $parent = Files::directory($this->dir.'/'.$name);
        $stage = Files::directory($parent.'/stage');
        $root = Files::directory($parent.'/v2');
        $source = $parent.'/v1';
        $writer = app(ResultStore::class);
        $history = $cohort = [];
        foreach ([1 => '2022-01-01', 2 => '2022-02-01', 3 => '2022-03-01', 4 => '2023-01-01', 5 => '2023-02-01', 6 => '2023-03-01', 7 => '2024-01-01', 8 => '2025-01-01'] as $id => $date) {
            $entries = [];
            for ($i = 1; $i <= 5; $i++) {
                $score = 90 - $i + ($id >= 6 ? 0 : ($id % 3 - 1));
                $rank = ($i + $id) % 5 + 1;
                if ($id === 8 && $changeOutcome) {
                    $rank = 6 - $rank;
                }
                $entries[] = ['id' => $id * 10 + $i, 'player_id' => $i, 'bike' => $i, 'race_score' => (string) $score, 'rank' => $rank, 'status' => 'FINISHED',
                    'result_entry_id' => $id * 10 + $i, 'result_player_id' => $i];
            }
            $history[] = ['race_id' => $id, 'year' => (int) substr($date, 0, 4), 'date' => $date, 'scheduled_start_at' => $date.' 12:00:00+09:00',
                'n' => 5, 'race_status' => 'CONFIRMED', 'meeting_id' => 1, 'day_date' => $date, 'starts_on' => '2022-01-01', 'ends_on' => '2025-12-31', 'entries' => $entries];
            if ($id >= 7) {
                $cohort[] = ['context' => ['year' => (int) substr($date, 0, 4), 'race_id' => $id, 'entries' => array_map(fn ($e) => array_intersect_key($e, array_flip(['id', 'bike', 'rank', 'status'])), $entries)],
                    'targets' => array_map(fn ($e) => array_intersect_key($e, array_flip(['id', 'bike', 'player_id'])), $entries),
                    'decision' => ['primary_position_1_bike' => 1], 'date' => $date, 'grade' => 'F2', 'class' => 'A1_A2'];
            }
        }
        $code = [];
        foreach (glob(app_path('Domain/Keirin/Backtest/Experiments/GrowthPointAnalysis/*.php')) as $path) {
            $code[substr($path, strlen(base_path()) + 1)] = Files::identity($path);
        }
        foreach ([ResultStore::class, Files::class, JsonlArtifact::class, AnalysisStore::class, Aggregator::class,
            ArtifactStore::class] as $class) {
            $path = (new \ReflectionClass($class))->getFileName();
            $code[substr($path, strlen(base_path()) + 1)] = Files::identity($path);
        }
        $expected = $writer->writeJson($stage, 'contract.json', V1Contract::plan());
        $expected += $writer->writeJson($stage, 'sources.json', ['synthetic' => true]);
        $expected += $writer->writeJson($stage, 'code.json', ['php' => PHP_VERSION, 'files' => $code]);
        $expected += $writer->writeJsonl($stage, 'cohort.jsonl', $cohort);
        $expected += $writer->writeJsonl($stage, 'history.jsonl', $history);
        $expected += $writer->writeJson($stage, 'history-start.json', ['synthetic' => true]);
        $w = new Workspace($parent.'/workspace.sqlite');
        $w->cohort($cohort);
        $w->history($history);
        $w->training(new Signals);
        $thresholds = $w->thresholds();
        $expected += $writer->writeJson($stage, 'thresholds.json', $thresholds);
        $expected += $writer->writeJsonl($stage, 'growth-input.jsonl', $w->inputs());
        $expected += $writer->writeJsonl($stage, 'growth-details.jsonl', app(V1Analysis::class)->details(JsonlArtifact::read($stage.'/growth-input.jsonl'), $thresholds, $w));
        $r = app(V1Analysis::class)->aggregate($w);
        $expected += $writer->writeJson($stage, 'summary.json', $r['summary']);
        $expected += app(AnalysisStore::class)->writeCsv($stage, $r['summary']);
        $expected += $writer->writeJson($stage, 'correlations.json', $r['correlations']);
        $expected += $writer->writeJson($stage, 'classification.json', $r['classification']);
        $expected += $writer->writeJson($stage, 'c1-diagnostics.json', $r['c1_diagnostics']);
        $expected += $writer->writeJson($stage, 'source-end.json', ['synthetic' => true]);
        app(V1Store::class)->publish($stage, $source, $expected, $r['summary']);

        return [$root, $source];
    }
}
