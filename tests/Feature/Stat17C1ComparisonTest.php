<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\Contracts\EffectBinBoundaryProvider;
use App\Domain\Keirin\Backtest\Experiments\Stat17C1Comparison\Contract;
use App\Domain\Keirin\Backtest\Experiments\Stat17C1Comparison\Dataset;
use App\Domain\Keirin\Backtest\Experiments\Stat17C1Comparison\Evaluation;
use App\Domain\Keirin\Backtest\Experiments\Stat17C1Comparison\Experiment;
use App\Domain\Keirin\Backtest\Experiments\Stat17C1Comparison\ModelLoader;
use App\Domain\Keirin\Backtest\Experiments\Stat17C1Comparison\Sources;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\MemoryLimitedTestProcess;
use Tests\Support\Stat17C1ComparisonFixture as Fixture;
use Tests\TestCase;

class Stat17C1ComparisonTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/stat17-compare-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        DB::shouldReceive('connection')->never();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_fixed_sources_prediction_nulls_and_teacher_guard(): void
    {
        $bundle = Fixture::make($this->directory.'/fixture');
        $dataset = new Dataset;
        $rows = iterator_to_array($dataset->prediction($bundle['source'], 2024));
        $this->assertCount(5, $rows);
        $this->assertCount(5, $rows[0]['entries']);
        $this->assertSame(0.0, $rows[0]['entries'][0]['signals'][16]);
        $this->assertNull($rows[0]['entries'][4]['signals'][16]);
        $this->assertCount(17, $rows[0]['entries'][0]['signals']);
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
                $this->assertSame([...$raw[$i]['entries'][$j]['signals'], ...$raw[$i]['entries'][$j]['history']], array_slice($entry['signals'], 0, 16));
                $this->assertSame(['id', 'bike', 'raw', 'stat01_rank', 'anchor', 'anchor_status', 'signals'], array_keys($entry));
            }
        }
        $report = $dataset->inspect($bundle['source'], $this->directory);
        foreach ($report as $year => $summary) {
            foreach (['races' => 5, 'entries' => 25, 'numeric' => 15, 'null' => 10, 'zero' => 5,
                'N_zero' => 5, 'source_unavailable' => 5, 'clamped' => 0, 'minimum' => 0.0, 'maximum' => 1.0] as $key => $value) {
                $this->assertSame($value, $summary[$key]);
            }
            $audit = iterator_to_array(Artifacts::lines($this->directory.'/derived-audit/diversity-'.$year.'.jsonl'));
            $this->assertCount(25, $audit);
            $this->assertSame('NO_TOP2_METHOD_OBSERVATIONS', $audit[3]['state']);
            $this->assertSame('NO_HISTORY', $audit[4]['state']);
            $this->assertSame([8, 0, 0, 0], $audit[0]['history']);
            $this->assertSame(0.0, $audit[0]['value']);
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
        $this->assertSame(['HIST_TOP2_METHOD_DIVERSITY_120D_PRE_MEETING'], array_slice(Contract::features(), 16));
    }

    public function test_target_outcome_changes_do_not_change_diversity_or_prediction_inputs(): void
    {
        $first = Fixture::make($this->directory.'/first');
        $changed = Fixture::make($this->directory.'/changed', 2024);
        $this->assertNotSame(Files::identity($first['source']['paths'][2024]['teacher']), Files::identity($changed['source']['paths'][2024]['teacher']));
        foreach ([2024, 2025] as $year) {
            $this->assertSame(iterator_to_array((new Dataset)->prediction($first['source'], $year)),
                iterator_to_array((new Dataset)->prediction($changed['source'], $year)));
        }
    }

    public function test_all_diversity_null_is_not_an_evaluated_extra_feature(): void
    {
        $bundle = Fixture::make($this->directory.'/fixture', allUnavailable: true);
        $this->app->instance(Sources::class, $bundle['sources']);
        try {
            app(Experiment::class)->execute($bundle['input'], $bundle['baseline'], $this->directory.'/result');
            $this->fail('All-NULL hypothesis cannot be evaluated.');
        } catch (RuntimeException $e) {
            $this->assertSame('All diversity inputs were NULL: NOT_EVALUATED.', $e->getMessage());
        }
        $this->assertSame('NOT_EVALUATED', Files::json($this->directory.'/result/FAILED.json')['status']);
        $this->assertFileDoesNotExist($this->directory.'/result/COMPLETE.json');
        $this->assertFileDoesNotExist($this->directory.'/result/run-01');
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
        $model = Files::json($this->directory.'/result/run-01/C1_PLUS_METHOD_DIVERSITY-fit-2024/model.json');
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
                $this->assertSame(Files::identity($runs[0].'/C1_PLUS_METHOD_DIVERSITY-fit-2024/'.$name), Files::identity($runs[$index].'/C1_PLUS_METHOD_DIVERSITY-fit-2024/'.$name));
            }
            $this->assertSame(Files::identity($runs[0].'/C1_PLUS_METHOD_DIVERSITY-fit-2025/'.$name), Files::identity($runs[2].'/C1_PLUS_METHOD_DIVERSITY-fit-2025/'.$name));
        }
        $this->assertNotSame(Files::identity($runs[0].'/C1_PLUS_METHOD_DIVERSITY-fit-2025/model.json'), Files::identity($runs[1].'/C1_PLUS_METHOD_DIVERSITY-fit-2025/model.json'));
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

    public function test_plan_uses_the_fixed_diversity_experiment_and_no_model_training(): void
    {
        $this->artisan('keirin:stat17:c1-compare plan')->assertSuccessful();
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
        $this->assertFileExists($dir.'/C1_PLUS_METHOD_DIVERSITY-fit-2024/sealed.json');
        $this->assertFileDoesNotExist($dir.'/COMPLETE.json');
        $this->assertSame('NOT_EVALUATED', Files::json($dir.'/FAILED.json')['status']);
        $this->assertFileDoesNotExist($dir.'/C1_PLUS_METHOD_DIVERSITY-fit-2025/model.json');
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
            base_path('tests/Support/stat17-c1-comparison-memory.php'), $this->directory], $this->directory, [], 120);
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
