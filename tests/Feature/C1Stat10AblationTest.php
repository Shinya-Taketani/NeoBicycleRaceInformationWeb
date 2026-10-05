<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\Contracts\EffectBinBoundaryProvider;
use App\Domain\Keirin\Backtest\Experiments\C1Stat10Ablation\Contract;
use App\Domain\Keirin\Backtest\Experiments\C1Stat10Ablation\Dataset;
use App\Domain\Keirin\Backtest\Experiments\C1Stat10Ablation\Evaluation;
use App\Domain\Keirin\Backtest\Experiments\C1Stat10Ablation\Experiment;
use App\Domain\Keirin\Backtest\Experiments\C1Stat10Ablation\FeatureProjector;
use App\Domain\Keirin\Backtest\Experiments\C1Stat10Ablation\LayoutBuilder;
use App\Domain\Keirin\Backtest\Experiments\C1Stat10Ablation\ModelLoader;
use App\Domain\Keirin\Backtest\Experiments\C1Stat10Ablation\Optimizer;
use App\Domain\Keirin\Backtest\Experiments\C1Stat10Ablation\Sources;
use App\Domain\Keirin\Backtest\Experiments\C1Stat10Ablation\Trainer;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\C1Stat10AblationFixture as Fixture;
use Tests\Support\MemoryLimitedTestProcess;
use Tests\TestCase;

class C1Stat10AblationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/c1-stat10-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        DB::shouldReceive('connection')->never();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_fixed_sources_only_stat10_removed_despite_history_nulls_and_teacher_guard(): void
    {
        $bundle = Fixture::make($this->directory.'/fixture');
        $dataset = new Dataset;
        $rows = iterator_to_array($dataset->prediction($bundle['source'], 2024));
        $this->assertCount(5, $rows);
        $this->assertCount(5, $rows[0]['entries']);
        $this->assertSame([8, 0, 0, 0], array_slice($rows[0]['entries'][0]['signals'], 11));
        $this->assertSame([null, null, null, null], array_slice($rows[0]['entries'][4]['signals'], 11));
        $this->assertCount(15, $rows[0]['entries'][0]['signals']);
        $this->assertArrayNotHasKey('rank', $rows[0]['entries'][0]);
        $this->assertCount(5, iterator_to_array($dataset->labelled($bundle['source'], 2022)));
        $this->expectExceptionMessage('before both prediction seals');
        iterator_to_array($dataset->labelled($bundle['source'], 2024));
    }

    public function test_original_features_identity_types_order_and_audit_are_preserved_without_extra_sources(): void
    {
        $bundle = Fixture::make($this->directory.'/fixture');
        $dataset = new Dataset;
        $before = Files::identity($bundle['source']['paths'][2024]['input']);
        $raw = iterator_to_array(Artifacts::lines($bundle['source']['paths'][2024]['input']));
        $rows = iterator_to_array($dataset->prediction($bundle['source'], 2024));
        $this->assertGreaterThan($rows[1]['race_id'], $rows[0]['race_id']);
        foreach ($rows as $i => $race) {
            Dataset::cohort($raw[$i], $race);
            foreach ($race['entries'] as $j => $entry) {
                $this->assertSame(FeatureProjector::vector([...$raw[$i]['entries'][$j]['signals'], ...$raw[$i]['entries'][$j]['history']]), $entry['signals']);
                $this->assertSame(['id', 'bike', 'raw', 'stat01_rank', 'anchor', 'anchor_status', 'signals'], array_keys($entry));
            }
        }
        $report = $dataset->inspect($bundle['source'], $this->directory);
        foreach ($report as $year => $summary) {
            foreach (['races' => 5, 'entries' => 25, 'full_feature_count' => 16, 'retained_feature_count' => 15,
                'excluded_features' => ['STAT-10'], 'stat10_zero' => 25, 'stat10_null' => 0] as $key => $value) {
                $this->assertSame($value, $summary[$key]);
            }
            $audit = iterator_to_array(Artifacts::lines($this->directory.'/derived-audit/projection-'.$year.'.jsonl'));
            $this->assertCount(25, $audit);
            $this->assertSame('STAT-10', $audit[0]['excluded_feature']);
            $this->assertSame(0, $audit[0]['excluded_value']);
            $this->assertSame(['int' => 25], $summary['stat10_types']);
        }
        foreach ($bundle['source']['paths'] as $paths) {
            $this->assertSame(['input', 'original', 'teacher'], array_slice(array_keys($paths), 0, 3));
            $this->assertArrayNotHasKey('sidecar', $paths);
        }
        foreach (array_keys($bundle['source']['seals']) as $path) {
            $this->assertStringNotContainsString('candidate', $path);
            $this->assertStringNotContainsString('stat35.jsonl', $path);
        }
        $this->assertSame($before, Files::identity($bundle['source']['paths'][2024]['input']));
        $this->assertNotContains('STAT-10', Contract::features());
    }

    public function test_target_outcome_changes_do_not_change_projection_or_prediction_inputs(): void
    {
        $first = Fixture::make($this->directory.'/first');
        $changed = Fixture::make($this->directory.'/changed', 2024);
        $this->assertNotSame(Files::identity($first['source']['paths'][2024]['teacher']), Files::identity($changed['source']['paths'][2024]['teacher']));
        foreach ([2024, 2025] as $year) {
            $this->assertSame(iterator_to_array((new Dataset)->prediction($first['source'], $year)),
                iterator_to_array((new Dataset)->prediction($changed['source'], $year)));
        }
    }

    #[DataProvider('badModels')]
    public function test_saved_fifteen_feature_model_rejects_wrong_projection_versions_and_restrictions(string $kind): void
    {
        $bundle = Fixture::make($this->directory.'/fixture');
        $dataset = new Dataset;
        $bins = app(EffectBinBuilder::class);
        $layout = app(LayoutBuilder::class)->build(fn () => $dataset->labelled($bundle['source'], 2022));
        $fit = app(Optimizer::class)->fit(fn () => $dataset->binned(fn () => $dataset->labelled($bundle['source'], 2022), $layout, $bins), $layout, 1.0);
        $model = app(Trainer::class)->model($layout, $fit);
        match ($kind) {
            'sixteen' => $model['layout']['bins']['STAT-10'] = [],
            'seventeen' => $model['layout']['bins']['EXTRA'] = [],
            'order' => $model['layout']['bins'] = array_reverse($model['layout']['bins'], true),
            'excluded' => $model['feature_projection']['excluded_features'] = ['STAT-11'],
            'mapping' => $model['feature_projection']['full_to_retained_mapping']['STAT-10']['retained_index'] = 2,
            'version' => $model['model_version'] = 'UNKNOWN',
            'use' => $model['use_restrictions']['formal_adoption'] = true,
        };
        $this->expectException(RuntimeException::class);
        app(ModelLoader::class)->restore($model);
    }

    public static function badModels(): array
    {
        return array_map(fn ($v) => [$v], ['sixteen', 'seventeen', 'order', 'excluded', 'mapping', 'version', 'use']);
    }

    public function test_teacher_stat10_mismatch_is_rejected_before_projection(): void
    {
        $bundle = Fixture::make($this->directory.'/fixture');
        $source = $bundle['source'];
        $teacher = $this->directory.'/altered-teacher.jsonl';
        $rows = iterator_to_array(Artifacts::lines($source['paths'][2022]['teacher']));
        $rows[0]['entries'][0]['signals'][2] = 999;
        file_put_contents($teacher, implode('', array_map(fn ($r) => Files::canonical($r)."\n", $rows)));
        $source['paths'][2022]['teacher'] = $teacher;
        $source['seals'][$teacher] = Files::identity($teacher);
        $this->expectExceptionMessage('teacher full fixed features before removal');
        iterator_to_array((new Dataset)->labelled($source, 2022));
    }

    public function test_stat10_changes_do_not_reach_retained_layout_model_or_predictions(): void
    {
        $models = $predictions = [];
        foreach ([false, true] as $i => $changed) {
            $bundle = Fixture::make($this->directory.'/fixture-'.$i, changedStat10: $changed);
            $dataset = new Dataset;
            $this->assertCount(5, iterator_to_array($dataset->prediction($bundle['source'], 2024)));
            $bins = app(EffectBinBuilder::class);
            $layout = app(LayoutBuilder::class)->build(fn () => $dataset->labelled($bundle['source'], 2022));
            $fit = app(Optimizer::class)->fit(fn () => $dataset->binned(fn () => $dataset->labelled($bundle['source'], 2022), $layout, $bins), $layout, 1.0);
            $models[$i] = app(Trainer::class)->model($layout, $fit);
            $loaded = app(ModelLoader::class)->restore($models[$i]);
            $predictions[$i] = iterator_to_array(app(ModelLoader::class)->predictions(fn () => $dataset->prediction($bundle['source'], 2024), $loaded));
        }
        $this->assertSame($models[0], $models[1]);
        $this->assertSame($predictions[0], $predictions[1]);
    }

    public function test_execute_independent_training_reproduction_and_fail_closed_overwrite(): void
    {
        $bundle = Fixture::make($this->directory.'/fixture');
        $this->app->instance(Sources::class, $bundle['sources']);
        $result = app(Experiment::class)->execute($bundle['input'], $bundle['baseline'], $this->directory.'/result');
        $this->assertSame('COMPLETED_DEVELOPMENT_COMPARISON_AWAITING_REVIEW', $result['status']);
        $this->assertFileExists($this->directory.'/result/COMPLETE.json');
        $report = Files::json($this->directory.'/result/reproduction.json');
        $this->assertTrue($report['identical']);
        $this->assertSame(0, $report['c1_retraining_count']);
        $events = Files::json($this->directory.'/result/run-01/teacher-access.json');
        $released = [];
        foreach ($events as $event) {
            if ($event['event'] === 'OUTER_TEACHER_RELEASED') {
                $released[] = $event['year'];
            } elseif ($event['year'] >= 2024) {
                $this->assertContains($event['year'], $released);
            }
        }
        $this->assertSame([2024, 2025], $released);
        $model = Files::json($this->directory.'/result/run-01/C1_MINUS_STAT10-fit-2024/model.json');
        $this->assertSame(Contract::MODEL_VERSION, $model['model_version']);
        $model['experiment'] = 'UNKNOWN';
        try {
            app(ModelLoader::class)->restore($model);
            $this->fail('Unknown saved model version accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('version/anchor/lambda', $e->getMessage());
        }
        $this->expectExceptionMessage('Output must be new');
        app(Experiment::class)->execute($bundle['input'], $bundle['baseline'], $this->directory.'/result');
    }

    public function test_temporal_teacher_changes_use_real_training_service_and_only_released_past(): void
    {
        $runs = [];
        foreach ([null, 2024, 2025] as $index => $changed) {
            $bundle = Fixture::make($this->directory.'/fixture-'.$index, $changed);
            $dir = Files::directory($this->directory.'/run-'.$index);
            app(Experiment::class)->run($bundle['source'], $dir);
            $runs[$index] = $dir;
        }
        foreach (['model.json', 'predictions.jsonl', 'selection.json'] as $name) {
            foreach ([1, 2] as $index) {
                $this->assertSame(Files::identity($runs[0].'/C1_MINUS_STAT10-fit-2024/'.$name), Files::identity($runs[$index].'/C1_MINUS_STAT10-fit-2024/'.$name));
            }
            $this->assertSame(Files::identity($runs[0].'/C1_MINUS_STAT10-fit-2025/'.$name), Files::identity($runs[2].'/C1_MINUS_STAT10-fit-2025/'.$name));
        }
        $this->assertNotSame(Files::identity($runs[0].'/C1_MINUS_STAT10-fit-2025/model.json'), Files::identity($runs[1].'/C1_MINUS_STAT10-fit-2025/model.json'));
    }

    #[DataProvider('invalidRows')]
    public function test_invalid_alignment_and_outcome_fields_fail_even_with_resealed_synthetic_stream(string $kind): void
    {
        $bundle = Fixture::make($this->directory.'/fixture');
        $source = $bundle['source'];
        $path = $source['paths'][2024]['input'];
        $rows = iterator_to_array(Artifacts::lines($path));
        match ($kind) {
            'outcome' => $rows[0]['entries'][0]['rank'] = 1,
            'order' => $rows = array_reverse($rows),
            'count' => array_pop($rows),
            'duplicate' => $rows[1] = $rows[0],
            'year' => $rows[0]['year'] = 2026,
            'entry' => $rows[0]['entries'][0]['id']++,
            'bike' => $rows[0]['entries'][0]['bike'] = 9,
            'extra' => $rows[] = $rows[0],
            'missing' => array_pop($rows[0]['entries']),
        };
        file_put_contents($path, implode('', array_map(fn ($r) => Files::canonical($r)."\n", $rows)));
        $source['seals'][$path] = Files::identity($path);
        $this->expectException(\Throwable::class);
        iterator_to_array((new Dataset)->prediction($source, 2024));
    }

    public static function invalidRows(): array
    {
        return array_map(fn ($v) => [$v], ['outcome', 'order', 'count', 'duplicate', 'year', 'entry', 'bike', 'extra', 'missing']);
    }

    public function test_plan_uses_fixed_ablation_and_no_model_training(): void
    {
        $this->artisan('keirin:c1:stat10-ablation plan')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_end_source_drift_after_training_starts_prevents_publication(): void
    {
        $bundle = Fixture::make($this->directory.'/fixture');
        $this->app->instance(Sources::class, $bundle['sources']);
        $path = $bundle['source']['paths'][2022]['input'];
        $provider = app(EffectBinBoundaryProvider::class);
        $this->app->instance(EffectBinBuilder::class,
            new class($provider, $path) extends EffectBinBuilder
            {
                private bool $mutated = false;

                public function __construct($provider, private readonly string $path)
                {
                    parent::__construct($provider);
                }

                public function build(iterable $trainingValues): array
                {
                    $bins = parent::build($trainingValues);
                    if (! $this->mutated) {
                        file_put_contents($this->path, ' ', FILE_APPEND);
                        $this->mutated = true;
                    }

                    return $bins;
                }
            });
        try {
            app(Experiment::class)->execute($bundle['input'], $bundle['baseline'], $this->directory.'/failed');
            $this->fail('End source drift must not publish.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('hash/size mismatch', $e->getMessage());
        }
        $this->assertFileExists($this->directory.'/failed/FAILED.json');
        $this->assertFileDoesNotExist($this->directory.'/failed/COMPLETE.json');
    }

    public function test_source_drift_is_rejected_without_republishing_or_opening_teachers(): void
    {
        $bundle = Fixture::make($this->directory.'/fixture');
        file_put_contents($bundle['source']['paths'][2024]['input'], 'x', FILE_APPEND);
        $this->expectExceptionMessage('hash/size mismatch');
        $bundle['sources']->open($bundle['input'], $bundle['baseline']);
    }

    public function test_unaccepted_pin_and_forbidden_year_are_rejected(): void
    {
        $bundle = Fixture::make($this->directory.'/fixture');
        try {
            iterator_to_array((new Dataset)->prediction($bundle['source'], 2026));
            $this->fail('2026 must fail.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Forbidden', $e->getMessage());
        }
        $this->expectExceptionMessage('Fixed experiment paths required');
        (new Sources)->open($bundle['input'], $bundle['baseline']);
    }

    public function test_teacher_alignment_failure_retains_diagnostics_and_does_not_publish(): void
    {
        $bundle = Fixture::make($this->directory.'/fixture');
        $source = $bundle['source'];
        $path = $source['paths'][2024]['teacher'];
        $rows = iterator_to_array(Artifacts::lines($path));
        $rows[0]['entries'][0]['id']++;
        file_put_contents($path, implode('', array_map(fn ($r) => Files::canonical($r)."\n", $rows)));
        $source['seals'][$path] = Files::identity($path);
        $dir = Files::directory($this->directory.'/failed');
        try {
            app(Experiment::class)->run($source, $dir);
            $this->fail('Incorrect teacher identity accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ordered year/race/entry/bike', $e->getMessage());
        }
        $this->assertFileExists($dir.'/FAILED.json');
        $this->assertFileExists($dir.'/C1_MINUS_STAT10-fit-2024/sealed.json');
        $this->assertFileDoesNotExist($dir.'/COMPLETE.json');
        $this->assertSame('NOT_EVALUATED', Files::json($dir.'/FAILED.json')['status']);
        $this->assertFileDoesNotExist($dir.'/C1_MINUS_STAT10-fit-2025/model.json');
    }

    public function test_unknown_input_version_rejected_despite_a_new_synthetic_pin(): void
    {
        $bundle = Fixture::make($this->directory.'/fixture');
        $path = $bundle['input'].'/manifest.json';
        $manifest = Files::json($path);
        $manifest['contract']['version'] = 'UNKNOWN';
        file_put_contents($path, Files::canonical($manifest));
        file_put_contents($bundle['input'].'/COMPLETE.json', Files::canonical(Files::identity($path)));
        $sources = new Sources(hash_file('sha256', $path), hash_file('sha256', $bundle['baseline'].'/report-export-manifest.json'),
            hash_file('sha256', $bundle['baseline'].'/frozen-experiment-contract.json'));
        $this->expectExceptionMessage('fixed input contract');
        $sources->open($bundle['input'], $bundle['baseline']);
    }

    public function test_actual_incremental_gate_boundaries_match_frozen_gate(): void
    {
        $new = app(Evaluation::class);
        $old = app(\App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Evaluation::class);
        foreach ([-0.00150001, -0.0015, -0.00149999, 0.0, 0.00000001] as $lower) {
            foreach ([-0.00300001, -0.003, 0.0, 0.01] as $delta) {
                foreach ([false, true] as $integrity) {
                    $intervals = array_fill_keys($new::PRIMARY, ['ci_lower' => $lower, 'ci_upper' => 0.1]);
                    $outer = array_fill_keys([2024, 2025], ['delta' => array_fill_keys($new::PRIMARY, $delta)]);
                    $this->assertSame($old->incrementalGate($outer, $intervals, $integrity), $new->incrementalGate($outer, $intervals, $integrity));
                }
            }
        }
    }

    public function test_streaming_reader_runs_in_an_independent_128m_process(): void
    {
        $execution = MemoryLimitedTestProcess::launch([PHP_BINARY, '-d', 'memory_limit=128M',
            base_path('tests/Support/c1-stat10-ablation-memory.php'), $this->directory], $this->directory, [], 120);
        $this->assertSame(0, $execution['exit_code'], file_get_contents($this->directory.'/stderr.log'));
        $measurement = Files::json($this->directory.'/measurement.json');
        $this->assertSame('128M', $measurement['limit']);
        $this->assertNotSame(getmypid(), $measurement['pid']);
        $this->assertSame(50000, $measurement['races']);
        $this->assertSame(250000, $measurement['entries']);
        $this->assertGreaterThan(100 * 1024 * 1024, $measurement['input_bytes']);
        $this->assertLessThan(128 * 1024 * 1024, $measurement['peak']);
    }
}
