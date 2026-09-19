<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use App\Domain\Keirin\Backtest\Experiments\GrowthAdjustmentCalibration\Adjustment;
use App\Domain\Keirin\Backtest\Experiments\GrowthAdjustmentCalibration\Code;
use App\Domain\Keirin\Backtest\Experiments\GrowthAdjustmentCalibration\Contract;
use App\Domain\Keirin\Backtest\Experiments\GrowthAdjustmentCalibration\Engine;
use App\Domain\Keirin\Backtest\Experiments\GrowthAdjustmentCalibration\Metrics;
use App\Domain\Keirin\Backtest\Experiments\GrowthAdjustmentCalibration\Projection;
use App\Domain\Keirin\Backtest\Experiments\GrowthAdjustmentCalibration\Service;
use App\Domain\Keirin\Backtest\Experiments\GrowthAdjustmentCalibration\Sources;
use App\Domain\Keirin\Backtest\Experiments\GrowthAdjustmentCalibration\Store;
use App\Domain\Keirin\Backtest\Experiments\GrowthAdjustmentCalibration\Workspace;
use App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysisV2\Store as GrowthStore;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\HistoryAggregator;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Layout;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Predictor;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\SolverContract;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Contract as FinalContract;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\ModelLoader;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline\ArtifactStore;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\ResultStore;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class GrowthAdjustmentCalibrationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/growth-calibration-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        config(['tactical_prediction_pipeline.artifact_base' => $this->directory, 'database.default' => 'disabled']);
        DB::shouldReceive('connection')->never();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_plan_never_reads_sources_and_grid_is_exact_integer_indexed(): void
    {
        $this->artisan('keirin:backtest:growth-adjustment-calibration --plan')->assertExitCode(0);
        $grid = Contract::grid();
        $this->assertCount(101, $grid);
        $this->assertSame(range(-50, 50), array_column($grid, 'k'));
        $this->assertSame(-0.5, $grid[0]['w']);
        $this->assertEquals(0.0, $grid[50]['w']);
        $this->assertSame(0.5, $grid[100]['w']);
        foreach ($grid as $row) {
            $this->assertSame(exp($row['w']), $row['per_point_odds_multiplier']);
        }
    }

    #[DataProvider('adjustments')]
    public function test_adjustment_preserves_zero_missing_and_same_meeting_for_every_weight(int $k): void
    {
        [$race, $growth] = $this->prepared();
        $actual = app(Adjustment::class)->apply($race, $growth, $k);
        foreach ([-3, -1, 0, 3, null] as $i => $point) {
            $expected = $k === 0 || $point === null || $point === 0 ? $race['entries'][$i]['anchor'] : $race['entries'][$i]['anchor'] + ($k / 100) * $point;
            $this->assertSame($expected, $actual['entries'][$i]['anchor']);
            unset($actual['entries'][$i]['anchor'], $race['entries'][$i]['anchor']);
        }
        $this->assertSame($race, $actual);
    }

    public static function adjustments(): iterable
    {
        foreach (range(-50, 50) as $k) {
            yield 'k='.$k => [$k];
        }
    }

    public function test_zero_replay_preserves_probabilities_primary_and_frozen_pair_tie(): void
    {
        [$race, $growth, $model] = $this->prepared();
        $expected = app(Predictor::class)->predict($race, $model->fit);
        $this->assertSame($expected, app(Adjustment::class)->predict($race, $growth, 0, $model->fit));
        $winner = $expected['decision']['primary_position_1_bike'];
        $pairs = [];
        foreach (range(1, 5) as $second) {
            foreach (range(1, 5) as $third) {
                if (count(array_unique([$winner, $second, $third])) === 3) {
                    $pairs[hash('sha256', 'BT03E05-DECODER-TIE-v1|PRIMARY_SECOND_THIRD|'.$race['race_id'].'|'.$winner.'-'.$second.'-'.$third)] = [$winner, $second, $third];
                }
            }
        }
        ksort($pairs);
        $this->assertSame(reset($pairs), Adjustment::primary($expected));
    }

    public function test_target_outcomes_and_other_growth_signals_never_enter_prediction(): void
    {
        [$race, , $model] = $this->prepared();
        $details = $this->details($race);
        $before = iterator_to_array(app(Projection::class)->growth($details));
        foreach ($details as &$row) {
            $row['target_rank'] = 99;
            $row['normal'] = false;
            $row['unique_winner'] = true;
            $row['signals']['PERFORMANCE'] = ['raw' => 999, 'point' => 3];
            $row['signals']['COMPOSITE'] = ['raw' => -999, 'point' => -3];
        }
        unset($row);
        $after = iterator_to_array(app(Projection::class)->growth($details));
        $this->assertSame($before, $after);
        $this->assertSame(app(Adjustment::class)->predict($race, $before, 20, $model->fit), app(Adjustment::class)->predict($race, $after, 20, $model->fit));
        $this->assertSame(['year', 'race_id', 'entry_id', 'player_id', 'score_raw', 'score_point', 'score_status', 'same_meeting_previous'], array_keys($after[0]));
    }

    #[DataProvider('invalidProjection')]
    public function test_invalid_or_outcome_bearing_projection_is_rejected(string $field, mixed $value): void
    {
        [, $growth] = $this->prepared();
        $row = $growth[0];
        $row[$field] = $value;
        $this->expectException(RuntimeException::class);
        Projection::validate($row);
    }

    public static function invalidProjection(): array
    {
        return [['year', 2026], ['rank', 1], ['score_point', 3], ['score_point', null], ['score_status', 'UNKNOWN'], ['same_meeting_previous', true], ['entry_id', 0]];
    }

    public function test_selection_ties_boundary_negative_and_no_improvement(): void
    {
        $curve = $this->curve();
        $this->assertSame(0, Contract::select($curve)['selected']['k']);
        foreach ([-10, 10] as $k) {
            $curve['candidates'][$k + 50]['metrics'][Contract::METRICS[3]]['rate'] = 0.6;
            $curve['candidates'][$k + 50]['metrics'][Contract::METRICS[3]]['delta'] = 0.1;
        }
        $this->assertSame(-10, Contract::select($curve)['selected']['k']);
        $curve['candidates'][0]['metrics'][Contract::METRICS[3]]['rate'] = 0.7;
        $selected = Contract::select($curve);
        $this->assertSame(-50, $selected['selected']['k']);
        $this->assertSame('BOUNDARY_SELECTED', $selected['boundary_status']);
        $curve['candidates'][0]['metrics'][Contract::METRICS[0]]['delta'] = -0.00300001;
        $this->assertSame(-10, Contract::select($curve)['selected']['k']);
        $curve['candidates'][40]['metrics'][Contract::METRICS[0]]['delta'] = -0.003;
        $this->assertSame(-10, Contract::select($curve)['selected']['k']);
    }

    public function test_selection_rejects_2025_and_validation_is_strict_on_hit3(): void
    {
        $row = $this->curve()['candidates'][51];
        $this->assertSame('NOT_REPLICATED', Contract::validation($row));
        $row['metrics'][Contract::METRICS[3]]['delta'] = 0.001;
        $this->assertSame('DIRECTIONALLY_REPLICATED_DEVELOPMENT_ONLY', Contract::validation($row));
        $row['k'] = 0;
        $this->assertSame('NO_INCREMENTAL_ADJUSTMENT_SELECTED', Contract::validation($row));
        $this->expectException(RuntimeException::class);
        Contract::select(array_replace($this->curve(), ['year' => 2025]));
    }

    public function test_2025_grid_refuses_to_read_anything_without_selection_seal(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Selection must be sealed');
        app(Engine::class)->curve(2025, '/absent', [], app(ModelLoader::class)->restore($this->model()), []);
    }

    public function test_synthetic_execute_reproduce_is_exact_without_database(): void
    {
        $source = $this->fixture('first');
        $this->bindSources($source);
        $root = $this->root('result');
        $receipt = app(Service::class)->execute($root, 'test', '', '');
        $this->assertSame('CALIBRATION_LOCKED', $receipt['status']);
        $path = $root.'/evaluations/test';
        $before = app(Store::class)->verify($path);
        $reproduction = app(Service::class)->reproduce($root, 'test');
        $this->assertSame('REPRODUCED', $reproduction['status']);
        $this->assertSame($before, app(Store::class)->verify($path));
        $this->assertLessThan(128 * 1024 * 1024, $reproduction['peak_bytes']);
        $audit = Files::json($path.'/baseline-reproduction.json');
        $diag = Files::json($path.'/diagnostics.json');
        foreach (Contract::YEARS as $year) {
            $this->assertSame(1, $audit[$year]['exact_predictions']);
            $this->assertSame(1, $diag['years'][$year]['missing']);
            $this->assertSame(1, $diag['years'][$year]['same_zero']);
            $curve = Files::json($path.'/coefficient-curve-'.$year.'.json');
            foreach (Contract::METRICS as $metric) {
                $this->assertSame($audit[$year]['metrics'][$metric]['numerator'], $curve['candidates'][50]['metrics'][$metric]['numerator']);
                $this->assertSame($audit[$year]['metrics'][$metric]['denominator'], $curve['candidates'][50]['metrics'][$metric]['denominator']);
            }
        }
        foreach (JsonlArtifact::read($path.'/selected-weight-details.jsonl') as $row) {
            $this->assertSame('GROWTH_MISSING_NO_ADJUSTMENT', $row['entries'][4]['status']);
            $this->assertSame($row['entries'][4]['original_anchor'], $row['entries'][4]['adjusted_anchor']);
        }
    }

    public function test_2025_outcome_change_cannot_change_2024_selection_or_prediction_hashes(): void
    {
        $selections = $curves = [];
        foreach ([false, true] as $changed) {
            $source = $this->fixture('sources-'.(int) $changed, $changed);
            $this->bindSources($source);
            $root = $this->root('output-'.(int) $changed);
            app(Service::class)->execute($root, 'test', '', '');
            $selections[] = Files::json($root.'/evaluations/test/selection.json');
            $curves[] = Files::json($root.'/evaluations/test/coefficient-curve-2025.json');
        }
        $this->assertSame($selections[0]['selected'], $selections[1]['selected']);
        $this->assertSame($selections[0]['curve_2024'], $selections[1]['curve_2024']);
        $this->assertSame(array_column($curves[0]['candidates'], 'primary_semantic_sha256'), array_column($curves[1]['candidates'], 'primary_semantic_sha256'));
        $this->assertNotSame(array_column($curves[0]['candidates'], 'metrics'), array_column($curves[1]['candidates'], 'metrics'));
    }

    #[DataProvider('driftKinds')]
    public function test_drift_fails_closed_before_publication(string $kind): void
    {
        $source = $this->fixture('source');
        $this->bindSources($source);
        $root = $this->root('result');
        if ($kind === 'source-start') {
            file_put_contents($source['growth'], ' ', FILE_APPEND);
        } elseif ($kind === 'source-end') {
            $sourceMock = \Mockery::mock(Sources::class)->makePartial();
            $sourceMock->shouldReceive('open')->once()->andReturn($source);
            $sourceMock->shouldReceive('verify')->once()->ordered();
            $sourceMock->shouldReceive('verify')->once()->ordered()->andThrow(new RuntimeException('source-end drift'));
            app()->instance(Sources::class, $sourceMock);
        } elseif ($kind === 'code') {
            $code = \Mockery::mock(Code::class);
            $code->shouldReceive('capture')->once()->ordered()->andReturn(['version' => 'start']);
            $code->shouldReceive('capture')->once()->ordered()->andReturn(['version' => 'drift']);
            app()->instance(Code::class, $code);
        } else {
            $writer = new class(app(ArtifactStore::class)) extends ResultStore
            {
                public function writeJson(string $directory, string $name, array $data): array
                {
                    $seal = parent::writeJson($directory, $name, $data);
                    if ($name === 'source-end.json') {
                        file_put_contents($directory.'/selection.json', " \n", FILE_APPEND);
                    }

                    return $seal;
                }
            };
            app()->instance(ResultStore::class, $writer);
        }
        try {
            app(Service::class)->execute($root, 'test', '', '');
            $this->fail('Drift published.');
        } catch (RuntimeException $e) {
            $this->assertDirectoryDoesNotExist($root.'/evaluations/test');
            $this->assertNotSame('', $e->getMessage());
        }
    }

    public static function driftKinds(): array
    {
        return [['source-start'], ['source-end'], ['code'], ['generated']];
    }

    public function test_published_tamper_and_overwrite_are_rejected(): void
    {
        $source = $this->fixture('source');
        $this->bindSources($source);
        $root = $this->root('result');
        app(Service::class)->execute($root, 'test', '', '');
        try {
            app(Service::class)->execute($root, 'test', '', '');
            $this->fail('Overwrote bundle.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot be overwritten', $e->getMessage());
        }
        file_put_contents($root.'/evaluations/test/selection.json', ' ', FILE_APPEND);
        $this->expectException(RuntimeException::class);
        app(Service::class)->reproduce($root, 'test');
    }

    public function test_cohort_duplicate_is_rejected_in_disk_workspace(): void
    {
        $source = $this->fixture('source');
        JsonlArtifact::write($this->directory.'/growth.jsonl', app(Projection::class)->growth(JsonlArtifact::read($source['growth'])));
        $workspace = new Workspace($this->directory.'/join.sqlite');
        $workspace->load($this->directory.'/growth.jsonl', $source['cohort']);
        [$race] = $this->prepared(2024);
        $workspace->join($race);
        $this->expectException(\PDOException::class);
        $workspace->join($race);
    }

    public function test_projection_stream_is_bounded_at_356209_entries(): void
    {
        $race = $this->race(2024);
        $row = $this->details($race)[0];
        $before = memory_get_usage(true);
        $rows = (function () use ($row) {
            for ($i = 1; $i <= 356209; $i++) {
                yield array_replace($row, ['entry_id' => $i]);
            }
        })();
        $count = 0;
        foreach (app(Projection::class)->growth($rows) as $projected) {
            $count++;
        }
        $this->assertSame(356209, $count);
        $this->assertLessThan(4 * 1024 * 1024, memory_get_usage(true) - $before);
    }

    public function test_bad_baseline_stops_before_any_curve_or_selection(): void
    {
        $source = $this->fixture('source');
        $path = $source['years'][2024]['prediction'];
        $rows = iterator_to_array(JsonlArtifact::read($path));
        $rows[0]['decision']['primary_position_1_bike'] = 99;
        unlink($path);
        unlink($path.'.manifest.json');
        JsonlArtifact::write($path, $rows);
        foreach ([$path, $path.'.manifest.json'] as $p) {
            $source['files'][$p] = Files::identity($p);
        }
        $this->bindSources($source);
        $root = $this->root('result');
        try {
            app(Service::class)->execute($root, 'test', '', '');
            $this->fail('Invalid baseline accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('w=0 full probabilities/decision', $e->getMessage());
            $this->assertSame([], glob($root.'/.staging/*/coefficient-curve-2024.json'));
            $this->assertSame([], glob($root.'/.staging/*/selection.json'));
            $this->assertDirectoryDoesNotExist($root.'/evaluations/test');
        }
    }

    public function test_frozen_tie_denominators_and_pooled_counts_are_not_averaged(): void
    {
        [$race, $growth, $model] = $this->prepared();
        $prediction = app(Adjustment::class)->predict($race, $growth, 0, $model->fit);
        $labels = $race;
        foreach ($labels['entries'] as $i => &$entry) {
            $entry['rank'] = $i < 2 ? 1 : $i + 1;
            $entry['status'] = $i < 2 ? 'TIED' : 'FINISHED';
        }
        unset($entry);
        $metrics = app(Metrics::class)->contribution(Metrics::context($labels, $race), $prediction);
        $this->assertSame(0.0, $metrics['POSITION_HIT_RATE_AT_3']['denominator']);
        $this->assertSame(0.0, $metrics['POSITION_1_ACCURACY']['denominator']);
        $this->assertSame(1.0, $metrics['POSITION_3_ACCURACY']['denominator']);
        $this->assertNull(Metrics::finish($metrics, $metrics)['POSITION_HIT_RATE_AT_3']['delta']);
    }

    private function root(string $name): string
    {
        return Files::directory($this->directory.'/'.$name);
    }

    private function bindSources(array $source): void
    {
        app()->instance(Sources::class, new class(app(GrowthStore::class), $source) extends Sources
        {
            public function __construct(GrowthStore $growth, private array $fixture)
            {
                parent::__construct($growth);
            }

            public function open(string $outerRoot, string $growthBundle): array
            {
                return $this->fixture;
            }
        });
    }

    private function fixture(string $name, bool $change2025 = false): array
    {
        $root = $this->root($name);
        $source = ['files' => [], 'years' => [], 'growth' => $root.'/growth.jsonl', 'cohort' => $root.'/cohort.jsonl',
            'counts' => [2024 => 1, 2025 => 1], 'entries' => [2024 => 5, 2025 => 5], 'missing' => [2024 => 1, 2025 => 1], 'same' => [2024 => 1, 2025 => 1]];
        $details = $cohort = [];
        foreach (Contract::YEARS as $year) {
            [$race, , $model] = $this->prepared($year);
            $raw = $this->race($year);
            $prediction = app(Predictor::class)->predict($race, $model->fit);
            $labels = $raw;
            foreach ($labels['entries'] as $i => &$entry) {
                $entry['rank'] = $year === 2025 && $change2025 ? 5 - $i : $i + 1;
                $entry['status'] = 'FINISHED';
            }
            unset($entry);
            $comparison = app(Bt03e05MetricEvaluator::class)->raceComparison(Metrics::context($labels, $race), $prediction['decision']);
            $rows = ['input' => $raw, 'prediction' => $prediction, 'labels' => $labels,
                'contributions' => ['year' => $year, 'race_id' => $race['race_id'], 'C1-STAT01' => $comparison]];
            foreach ($rows as $kind => $row) {
                $path = $root.'/'.$year.'-'.$kind.'.jsonl';
                JsonlArtifact::write($path, [$row]);
                $source['years'][$year][$kind] = $path;
                foreach ([$path, $path.'.manifest.json'] as $p) {
                    $source['files'][$p] = Files::identity($p);
                }
            }
            $path = $root.'/'.$year.'-model.json';
            JsonlArtifact::json($path, $model->artifact);
            $source['years'][$year]['model'] = $path;
            $source['files'][$path] = Files::identity($path);
            array_push($details, ...$this->details($race));
            $cohort[] = ['context' => ['year' => $year, 'race_id' => $race['race_id']], 'grade' => 'F2', 'class' => 'A1_A2',
                'targets' => array_map(fn ($e) => ['id' => $e['id'], 'bike' => $e['bike'], 'player_id' => $e['id']], $race['entries'])];
        }
        foreach (['growth' => $details, 'cohort' => $cohort] as $kind => $rows) {
            JsonlArtifact::write($source[$kind], $rows);
            foreach ([$source[$kind], $source[$kind].'.manifest.json'] as $p) {
                $source['files'][$p] = Files::identity($p);
            }
        }

        return $source;
    }

    private function race(int $year): array
    {
        $entries = [];
        foreach (range(1, 5) as $bike) {
            $entries[] = ['id' => $year * 10 + $bike, 'bike' => $bike, 'raw' => 100.0, 'stat01_rank' => 1, 'anchor' => 0.0,
                'anchor_status' => 'ZERO_VARIANCE', 'signals' => array_fill(0, 12, 0), 'history' => [0, 0, 0, 0], 'history_status' => 'AVAILABLE'];
        }

        return ['year' => $year, 'race_id' => $year, 'entries' => $entries];
    }

    private function prepared(int $year = 2024): array
    {
        $model = app(ModelLoader::class)->restore($this->model());
        $race = $this->race($year);
        foreach ($race['entries'] as &$entry) {
            $entry['bins'] = $model->layout->assign([...$entry['signals'], ...$entry['history']], app(EffectBinBuilder::class));
            unset($entry['signals'], $entry['history'], $entry['history_status']);
        }
        unset($entry);

        return [$race, iterator_to_array(app(Projection::class)->growth($this->details($race))), $model];
    }

    private function details(array $race): array
    {
        $rows = [];
        foreach ([-3, -1, 0, 3, null] as $i => $point) {
            $rows[] = ['year' => $race['year'], 'race_id' => $race['race_id'], 'entry_id' => $race['entries'][$i]['id'], 'player_id' => $race['entries'][$i]['id'],
                'same' => $point === 0 ? 'PREVIOUS_SAME_MEETING' : 'PREVIOUS_OTHER_MEETING',
                'signals' => ['SCORE' => ['raw' => $point === null ? null : (float) $point, 'point' => $point, 'status' => $point === null ? 'MISSING_PREVIOUS_SCORE' : 'VALID']]];
        }

        return $rows;
    }

    private function curve(): array
    {
        return ['year' => 2024, 'candidates' => array_map(fn ($row) => $row + ['metrics' => array_fill_keys(Contract::METRICS, ['rate' => 0.5, 'delta' => 0.0])], Contract::grid())];
    }

    private function model(): array
    {
        // An independently constructed synthetic saved model. No fitting/selection is run.
        $bins = [];
        foreach (FinalContract::plan()['features'] as $feature) {
            $bins[$feature] = [new EffectBinDto(1, 'NUMERIC_RANGE', null, 0.0, null, 5), new EffectBinDto(2, 'NUMERIC_RANGE', 0.0, null, null, 5)];
        }
        $layout = new Layout($bins);
        $coefficients = array_fill(0, $layout->size(), 0.0);
        $diagnostics = [];
        foreach (Bt03e03Contract::POSITIONS as $position) {
            $diagnostics[$position] = ['optimizer_version' => SolverContract::OPTIMIZER_VERSION, 'status' => 'CONVERGED',
                'lambda' => 0.1, 'position' => $position, 'iteration' => 1, 'accepted_update_count' => 1, 'max_iterations' => 200,
                'optimizer_attempt_count' => 1, 'eligible_race_count' => 1, 'excluded_race_count' => 0,
                'final_objective' => 1.0, 'previous_objective' => 1.0, 'relative_objective_change' => 0.0, 'maximum_coefficient_change' => 0.0,
                'current_step' => 1.0, 'prox_gradient_mapping_max' => 0.0, 'stationarity_reference_step' => 1.0, 'centering_residual_max' => 0.0];
        }

        return ['experiment' => HistoryAggregator::VERSION, 'optimizer_version' => SolverContract::OPTIMIZER_VERSION,
            'model_version' => SolverContract::MODEL_VERSION, 'objective_reference_version' => Bt03e03Contract::OPTIMIZER_VERSION,
            'stat01_anchor_coefficient' => 1.0, 'lambda' => 0.1,
            'layout' => ['feature_count' => $layout->featureCount(), 'active_parameter_count_M' => $layout->size(),
                'active_group_count_G' => count($layout->groups()), 'numeric_edge_count' => count($layout->smoothEdges()),
                'groups' => $layout->groups(), 'support_weights' => $layout->supportWeights(), 'smooth_edges' => $layout->smoothEdges(), 'bins' => $layout->canonicalBins()],
            'position_coefficients' => array_fill_keys(Bt03e03Contract::POSITIONS, $coefficients),
            'weighted_center_means' => array_fill_keys(Bt03e03Contract::POSITIONS, $layout->weightedMeans($coefficients)),
            'objectives' => array_fill_keys(Bt03e03Contract::POSITIONS, 1.0), 'iterations' => array_fill_keys(Bt03e03Contract::POSITIONS, 1),
            'eligible_races' => array_fill_keys(Bt03e03Contract::POSITIONS, 1), 'excluded_races' => array_fill_keys(Bt03e03Contract::POSITIONS, 0), 'optimizer_diagnostics' => $diagnostics];
    }
}
