<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\Contracts\EffectBinBoundaryProvider;
use App\Domain\Keirin\Backtest\Experiments\C1PositionLambda\Contract;
use App\Domain\Keirin\Backtest\Experiments\C1PositionLambda\Dataset;
use App\Domain\Keirin\Backtest\Experiments\C1PositionLambda\Evaluation;
use App\Domain\Keirin\Backtest\Experiments\C1PositionLambda\Experiment;
use App\Domain\Keirin\Backtest\Experiments\C1PositionLambda\LoadedModel;
use App\Domain\Keirin\Backtest\Experiments\C1PositionLambda\ModelLoader;
use App\Domain\Keirin\Backtest\Experiments\C1PositionLambda\Sources;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\MemoryLimitedTestProcess;
use Tests\Support\Stat39C1FieldBikeFixture as Fixture;
use Tests\TestCase;

class C1PositionLambdaComparisonTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/c1-position-lambda-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        DB::shouldReceive('connection')->never();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function fixture(string $name = 'fixture', ?int $year = null): array
    {
        $bundle = Fixture::make($this->directory.'/'.$name, $year);
        $sources = new Sources(hash_file('sha256', $bundle['input'].'/manifest.json'),
            hash_file('sha256', $bundle['baseline'].'/report-export-manifest.json'),
            hash_file('sha256', $bundle['baseline'].'/frozen-experiment-contract.json'));

        return [...$bundle, 'sources' => $sources, 'source' => $sources->open($bundle['input'], $bundle['baseline'])];
    }

    public function test_projection_preserves_original_sixteen_values_types_order_anchor_and_cohort(): void
    {
        $bundle = $this->fixture();
        $raw = iterator_to_array(Artifacts::lines($bundle['source']['paths'][2024]['input']));
        $rows = iterator_to_array((new Dataset)->prediction($bundle['source'], 2024));
        foreach ($rows as $i => $race) {
            Dataset::cohort($raw[$i], $race);
            foreach ($race['entries'] as $j => $entry) {
                $old = $raw[$i]['entries'][$j];
                $this->assertSame([...$old['signals'], ...$old['history']], $entry['signals']);
                foreach (['id', 'bike', 'raw', 'anchor', 'anchor_status', 'stat01_rank'] as $key) {
                    $this->assertSame($old[$key], $entry[$key]);
                }
                $this->assertArrayNotHasKey('rank', $entry);
            }
        }
        $audit = (new Dataset)->inspect($bundle['source']);
        foreach ($audit as $counts) {
            $this->assertSame(16, $counts['feature_count']);
            $this->assertSame(25, $counts['entries']);
        }
        $this->assertContains('STAT-10', Contract::features());
        $this->assertContains('STAT-39', Contract::features());
    }

    public function test_teacher_full_original_values_are_verified_before_projection(): void
    {
        $source = $this->fixture()['source'];
        $rows = iterator_to_array(Artifacts::lines($source['paths'][2022]['teacher']));
        $rows[0]['entries'][0]['history'][1] = 999;
        $path = $this->directory.'/altered.jsonl';
        JsonlArtifact::write($path, $rows);
        $source['paths'][2022]['teacher'] = $path;
        $source['seals'][$path] = Files::identity($path);
        $this->expectExceptionMessage('teacher full fixed features');
        iterator_to_array((new Dataset)->labelled($source, 2022));
    }

    #[DataProvider('invalidRows')]
    public function test_resealed_bad_input_is_rejected(string $kind): void
    {
        $source = $this->fixture()['source'];
        $path = $source['paths'][2024]['input'];
        $rows = iterator_to_array(Artifacts::lines($path));
        match ($kind) {
            'outcome' => $rows[0]['entries'][0]['rank'] = 1,
            'order' => $rows = array_reverse($rows),
            'duplicate' => $rows[1] = $rows[0],
            'year' => $rows[0]['year'] = 2026,
            'entry' => $rows[0]['entries'][0]['id']++,
            'bike' => $rows[0]['entries'][0]['bike'] = 9,
            'extra' => $rows[] = $rows[0],
            'missing' => array_pop($rows[0]['entries']),
            'signal_order' => $rows[0]['entries'][0]['signals'] = array_reverse($rows[0]['entries'][0]['signals']),
        };
        file_put_contents($path, implode('', array_map(fn ($r) => Files::canonical($r)."\n", $rows)));
        $source['seals'][$path] = Files::identity($path);
        $this->expectException(\Throwable::class);
        iterator_to_array((new Dataset)->prediction($source, 2024));
    }

    public static function invalidRows(): array
    {
        return array_map(fn ($v) => [$v], ['outcome', 'order', 'duplicate', 'year', 'entry', 'bike', 'extra', 'missing', 'signal_order']);
    }

    public function test_full_execute_independently_reproduces_and_uses_t2223_once(): void
    {
        $bundle = $this->fixture();
        $this->app->instance(Sources::class, $bundle['sources']);
        $result = app(Experiment::class)->execute($bundle['input'], $bundle['baseline'], $this->directory.'/result');
        $this->assertSame('COMPLETED_DEVELOPMENT_COMPARISON_AWAITING_REVIEW', $result['status']);
        $repro = Files::json($this->directory.'/result/reproduction.json');
        $this->assertTrue($repro['identical']);
        $this->assertSame(0, $repro['c1_retraining_count']);
        foreach (['run-01', 'run-02'] as $run) {
            $dir = $this->directory.'/result/'.$run;
            $this->assertSame(24, Files::json($dir.'/T2223/path.json')['attempt_count']);
            $this->assertSame(0, Files::json($dir.'/inner-B/validation-audit.json')['new_fit_count']);
            $this->assertSame('T2223', Files::json($dir.'/C1_PER_POSITION_LAMBDA-fit-2024/pool-reuse.json')['pool']);
            $this->assertFileDoesNotExist($dir.'/inner-B/training.jsonl');
            $events = Files::json($dir.'/teacher-access.json');
            $this->assertSame([2024, 2025], array_column(array_values(array_filter($events, fn ($e) => $e['event'] === 'OUTER_TEACHER_RELEASED')), 'year'));
            foreach ([2024, 2025] as $year) {
                $model = Files::json($dir.'/C1_PER_POSITION_LAMBDA-fit-'.$year.'/model.json');
                $this->assertSame(Contract::features(), array_keys($model['layout']['bins']));
                $this->assertCount(3, $model['lambda_by_position']);
                $this->assertArrayNotHasKey('lambda', $model);
                $this->assertInstanceOf(LoadedModel::class, app(ModelLoader::class)->restore($model));
            }
        }
        $this->expectExceptionMessage('Output must be new');
        app(Experiment::class)->execute($bundle['input'], $bundle['baseline'], $this->directory.'/result');
    }

    public function test_real_service_temporal_teacher_dependency_is_outer_safe(): void
    {
        $runs = [];
        foreach ([null, 2024, 2025] as $i => $changed) {
            $bundle = $this->fixture('fixture-'.$i, $changed);
            $dir = Files::directory($this->directory.'/run-'.$i);
            app(Experiment::class)->run($bundle['source'], $dir);
            $runs[$i] = $dir;
        }
        foreach (['model.json', 'selection.json', 'predictions.jsonl', 'sealed.json'] as $file) {
            foreach ([1, 2] as $i) {
                $this->assertSame(Files::identity($runs[0].'/C1_PER_POSITION_LAMBDA-fit-2024/'.$file), Files::identity($runs[$i].'/C1_PER_POSITION_LAMBDA-fit-2024/'.$file));
            }
            $this->assertSame(Files::identity($runs[0].'/C1_PER_POSITION_LAMBDA-fit-2025/'.$file), Files::identity($runs[2].'/C1_PER_POSITION_LAMBDA-fit-2025/'.$file));
        }
        $this->assertNotSame(Files::identity($runs[0].'/C1_PER_POSITION_LAMBDA-fit-2025/model.json'), Files::identity($runs[1].'/C1_PER_POSITION_LAMBDA-fit-2025/model.json'));
        $this->assertNotSame(Files::json($runs[0].'/C1_PER_POSITION_LAMBDA-fit-2025/model.json')['position_coefficients'],
            Files::json($runs[1].'/C1_PER_POSITION_LAMBDA-fit-2025/model.json')['position_coefficients']);
    }

    public function test_saved_model_rejects_position_lambda_diagnostic_and_version_drift(): void
    {
        $bundle = $this->fixture();
        $dir = Files::directory($this->directory.'/run');
        app(Experiment::class)->run($bundle['source'], $dir);
        $original = Files::json($dir.'/C1_PER_POSITION_LAMBDA-fit-2024/model.json');
        foreach (['lambda', 'position', 'missing', 'version', 'selection', 'support', 'coefficient'] as $kind) {
            $model = $original;
            match ($kind) {
                'lambda' => $model['optimizer_diagnostics']['POSITION_1']['lambda'] = 0.0,
                'position' => $model['optimizer_diagnostics']['POSITION_1']['position'] = 'POSITION_2',
                'missing' => array_pop($model['lambda_by_position']),
                'version' => $model['model_version'] = 'UNKNOWN',
                'selection' => $model['selection_by_position']['POSITION_1']['selected_lambda'] = 0.0,
                'support' => $model['layout']['bins']['STAT-07'][0]['training_support']++,
                'coefficient' => $model['position_coefficients']['POSITION_1'][0] = INF,
            };
            try {
                app(ModelLoader::class)->restore($model);
                $this->fail('Tampered model accepted: '.$kind);
            } catch (RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_end_source_drift_prevents_publication(): void
    {
        $bundle = $this->fixture();
        $this->app->instance(Sources::class, $bundle['sources']);
        $provider = app(EffectBinBoundaryProvider::class);
        $this->app->instance(EffectBinBuilder::class, new class($provider, $bundle['source']['paths'][2022]['input']) extends EffectBinBuilder
        {
            private bool $mutated = false;

            public function __construct($provider, private readonly string $path)
            {
                parent::__construct($provider);
            }

            public function build(iterable $values): array
            {
                $bins = parent::build($values);
                if (! $this->mutated) {
                    file_put_contents($this->path, 'x', FILE_APPEND);
                    $this->mutated = true;
                }

                return $bins;
            }
        });
        try {
            app(Experiment::class)->execute($bundle['input'], $bundle['baseline'], $this->directory.'/failed');
            $this->fail('Drift accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('hash/size mismatch', $e->getMessage());
        }
        $this->assertFileExists($this->directory.'/failed/FAILED.json');
        $this->assertFileDoesNotExist($this->directory.'/failed/COMPLETE.json');
    }

    public function test_unknown_source_and_premature_outer_teacher_are_rejected(): void
    {
        $bundle = $this->fixture();
        try {
            (new Sources)->open($bundle['input'], $bundle['baseline']);
            $this->fail('Unknown source accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Fixed experiment paths required', $e->getMessage());
        }
        $this->expectExceptionMessage('before both prediction seals');
        iterator_to_array((new Dataset)->labelled($bundle['source'], 2024));
    }

    public function test_unknown_input_version_cannot_be_resealed_into_acceptance(): void
    {
        $bundle = $this->fixture();
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

    public function test_gate_boundaries_match_frozen_incremental_gate(): void
    {
        $new = app(Evaluation::class);
        $old = app(\App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Evaluation::class);
        foreach ([-0.00150001, -0.0015, -0.00149999, 0.0, 1e-8] as $lower) {
            foreach ([-0.00300001, -0.003, 0.0, 0.01] as $delta) {
                foreach ([false, true] as $integrity) {
                    $ci = array_fill_keys($new::PRIMARY, ['ci_lower' => $lower, 'ci_upper' => 0.1]);
                    $outer = array_fill_keys([2024, 2025], ['delta' => array_fill_keys($new::PRIMARY, $delta)]);
                    $this->assertSame($old->incrementalGate($outer, $ci, $integrity), $new->incrementalGate($outer, $ci, $integrity));
                }
            }
        }
    }

    public function test_plan_is_offline_and_2026_is_forbidden(): void
    {
        $this->artisan('keirin:c1:position-lambda plan')->assertSuccessful();
        Http::assertNothingSent();
        $this->assertSame('FORBIDDEN', Contract::restrictions()['2026_access']);
        $this->expectExceptionMessage('Forbidden dataset year');
        iterator_to_array((new Dataset)->prediction([], 2026));
    }

    public function test_streaming_in_actual_independent_128m_process_exceeds_100mib(): void
    {
        $execution = MemoryLimitedTestProcess::launch([PHP_BINARY, '-d', 'memory_limit=128M', base_path('tests/Support/c1-position-lambda-memory.php'), $this->directory], $this->directory, [], 120);
        $this->assertSame(0, $execution['exit_code'], file_get_contents($this->directory.'/stderr.log'));
        $m = Files::json($this->directory.'/measurement.json');
        $this->assertSame('128M', $m['limit']);
        $this->assertNotSame(getmypid(), $m['pid']);
        $this->assertSame(50000, $m['races']);
        $this->assertSame(250000, $m['entries']);
        $this->assertGreaterThan(100 * 1024 * 1024, $m['input_bytes']);
        $this->assertLessThan(128 * 1024 * 1024, $m['peak']);
    }

    public function test_invalid_mode_records_actual_peak_and_not_evaluated_without_io(): void
    {
        $this->artisan('keirin:c1:position-lambda invalid')
            ->expectsOutputToContain('"status":"NOT_EVALUATED","peak_memory_bytes":')->assertFailed();
        Http::assertNothingSent();
    }
}
