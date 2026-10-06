<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\Contracts\EffectBinBoundaryProvider;
use App\Domain\Keirin\Backtest\Experiments\Stat39C1FieldBikeComparison\Contract;
use App\Domain\Keirin\Backtest\Experiments\Stat39C1FieldBikeComparison\Dataset;
use App\Domain\Keirin\Backtest\Experiments\Stat39C1FieldBikeComparison\Evaluation;
use App\Domain\Keirin\Backtest\Experiments\Stat39C1FieldBikeComparison\Experiment;
use App\Domain\Keirin\Backtest\Experiments\Stat39C1FieldBikeComparison\LayoutBuilder;
use App\Domain\Keirin\Backtest\Experiments\Stat39C1FieldBikeComparison\ModelLoader;
use App\Domain\Keirin\Backtest\Experiments\Stat39C1FieldBikeComparison\Optimizer;
use App\Domain\Keirin\Backtest\Experiments\Stat39C1FieldBikeComparison\Sources;
use App\Domain\Keirin\Backtest\Experiments\Stat39C1FieldBikeComparison\Trainer;
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

class Stat39C1FieldBikeComparisonTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/stat39-field-bike-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        DB::shouldReceive('connection')->never();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_projection_audit_preserves_all_original_sixteen_values_and_order(): void
    {
        $bundle = Fixture::make($this->directory.'/fixture');
        $dataset = new Dataset;
        $raw = iterator_to_array(Artifacts::lines($bundle['source']['paths'][2024]['input']));
        $projected = iterator_to_array($dataset->prediction($bundle['source'], 2024));
        $this->assertGreaterThan($projected[1]['race_id'], $projected[0]['race_id']);
        foreach ($projected as $i => $race) {
            Dataset::cohort($raw[$i], $race);
            foreach ($race['entries'] as $j => $entry) {
                $old = $raw[$i]['entries'][$j];
                $this->assertSame([...$old['signals'], ...$old['history'], 'N5_B'.$old['bike']], $entry['signals']);
                foreach (['id', 'bike', 'raw', 'anchor', 'anchor_status', 'stat01_rank'] as $key) {
                    $this->assertSame($old[$key], $entry[$key]);
                }
                $this->assertArrayNotHasKey('rank', $entry);
                $this->assertArrayNotHasKey('status', $entry);
            }
        }
        $report = $dataset->inspect($bundle['source'], $this->directory);
        foreach ($report as $year => $summary) {
            $this->assertSame(5, $summary['races']);
            $this->assertSame(25, $summary['entries']);
            $this->assertSame(5, $summary['observed_category_count']);
            $this->assertSame([5 => ['races' => 5, 'entries' => 25]], $summary['by_entry_count']);
            $audit = iterator_to_array(Artifacts::lines($this->directory.'/derived-audit/projection-'.$year.'.jsonl'));
            $this->assertCount(25, $audit);
            $this->assertSame('N5_B1', $audit[0]['category']);
        }
        $this->assertCount(5, iterator_to_array($dataset->labelled($bundle['source'], 2022)));
        $this->expectExceptionMessage('before both prediction seals');
        iterator_to_array($dataset->labelled($bundle['source'], 2024));
    }

    public function test_teacher_full_original_values_are_verified_before_projection(): void
    {
        $bundle = Fixture::make($this->directory.'/fixture');
        $source = $bundle['source'];
        $rows = iterator_to_array(Artifacts::lines($source['paths'][2022]['teacher']));
        $rows[0]['entries'][0]['signals'][2] = 999;
        $path = $this->directory.'/altered-teacher.jsonl';
        JsonlArtifact::write($path, $rows);
        $source['paths'][2022]['teacher'] = $path;
        $source['seals'][$path] = Files::identity($path);
        $this->expectExceptionMessage('teacher full fixed features before addition');
        iterator_to_array((new Dataset)->labelled($source, 2022));
    }

    #[DataProvider('badModels')]
    public function test_saved_model_rejects_category_type_support_order_and_version_tampering(string $kind): void
    {
        $bundle = Fixture::make($this->directory.'/fixture');
        $dataset = new Dataset;
        $bins = app(EffectBinBuilder::class);
        $raw = fn () => $dataset->labelled($bundle['source'], 2022);
        $layout = app(LayoutBuilder::class)->build($raw);
        $fit = app(Optimizer::class)->fit(fn () => $dataset->binned($raw, $layout, $bins), $layout, 1.0);
        $model = app(Trainer::class)->model($layout, $fit);
        $extra = &$model['layout']['bins'][Contract::EXTRA_FEATURE];
        match ($kind) {
            'kind' => $extra[0]['kind'] = 'NUMERIC_RANGE',
            'category' => $extra[0]['category_value'] = 'N05_B1',
            'support0' => $extra[0]['training_support'] = 0,
            'support_sum' => $extra[0]['training_support']++,
            'duplicate' => $extra[1]['category_value'] = $extra[0]['category_value'],
            'order' => [$extra[0]['category_value'], $extra[1]['category_value']] = [$extra[1]['category_value'], $extra[0]['category_value']],
            'version' => $model['model_version'] = 'UNKNOWN',
            'missing_stat10' => $model['layout']['bins'] = array_diff_key($model['layout']['bins'], ['STAT-10' => true]),
            'use' => $model['use_restrictions']['formal_adoption'] = true,
        };
        $this->expectException(\Exception::class);
        app(ModelLoader::class)->restore($model);
    }

    public static function badModels(): array
    {
        return array_map(fn ($v) => [$v], ['kind', 'category', 'support0', 'support_sum', 'duplicate', 'order', 'version', 'missing_stat10', 'use']);
    }

    public function test_execute_restored_model_independent_runs_and_baseline_forward_without_retraining(): void
    {
        $bundle = Fixture::make($this->directory.'/fixture');
        $this->app->instance(Sources::class, $bundle['sources']);
        $result = app(Experiment::class)->execute($bundle['input'], $bundle['baseline'], $this->directory.'/result');
        $this->assertSame('COMPLETED_DEVELOPMENT_COMPARISON_AWAITING_REVIEW', $result['status']);
        $this->assertFileExists($this->directory.'/result/COMPLETE.json');
        $reproduction = Files::json($this->directory.'/result/reproduction.json');
        $this->assertTrue($reproduction['identical']);
        $this->assertSame(0, $reproduction['c1_retraining_count']);
        $this->assertTrue(Files::json($this->directory.'/result/baseline-forward.json')['identical']);
        $this->assertArrayHasKey('C1_PLUS_FIELD_BIKE-fit-2024/prediction-category-audit.json', $reproduction['semantic_files']);
        $this->assertArrayHasKey('C1_PLUS_FIELD_BIKE-inner-A/validation-category-audit.json', $reproduction['semantic_files']);
        $released = [];
        foreach (Files::json($this->directory.'/result/run-01/teacher-access.json') as $event) {
            if ($event['event'] === 'OUTER_TEACHER_RELEASED') {
                $released[] = $event['year'];
            } elseif ($event['year'] >= 2024) {
                $this->assertContains($event['year'], $released);
            }
        }
        $this->assertSame([2024, 2025], $released);
        $this->expectExceptionMessage('Output must be new');
        app(Experiment::class)->execute($bundle['input'], $bundle['baseline'], $this->directory.'/result');
    }

    public function test_temporal_teacher_changes_use_real_service_and_only_released_past_affects_later_model(): void
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
                $this->assertSame(Files::identity($runs[0].'/C1_PLUS_FIELD_BIKE-fit-2024/'.$name), Files::identity($runs[$index].'/C1_PLUS_FIELD_BIKE-fit-2024/'.$name));
            }
            $this->assertSame(Files::identity($runs[0].'/C1_PLUS_FIELD_BIKE-fit-2025/'.$name), Files::identity($runs[2].'/C1_PLUS_FIELD_BIKE-fit-2025/'.$name));
        }
        $this->assertNotSame(Files::identity($runs[0].'/C1_PLUS_FIELD_BIKE-fit-2025/model.json'), Files::identity($runs[1].'/C1_PLUS_FIELD_BIKE-fit-2025/model.json'));
    }

    public function test_target_outcomes_do_not_change_category_projection(): void
    {
        $first = Fixture::make($this->directory.'/first');
        $changed = Fixture::make($this->directory.'/changed', 2024);
        $this->assertNotSame(Files::identity($first['source']['paths'][2024]['teacher']), Files::identity($changed['source']['paths'][2024]['teacher']));
        foreach ([2024, 2025] as $year) {
            $this->assertSame(iterator_to_array((new Dataset)->prediction($first['source'], $year)), iterator_to_array((new Dataset)->prediction($changed['source'], $year)));
        }
    }

    public function test_fixed_source_drift_and_unknown_paths_fail_closed(): void
    {
        $bundle = Fixture::make($this->directory.'/fixture');
        try {
            (new Sources)->open($bundle['input'], $bundle['baseline']);
            $this->fail('Unaccepted source accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Fixed experiment paths required', $e->getMessage());
        }
        file_put_contents($bundle['source']['paths'][2024]['input'], 'x', FILE_APPEND);
        $this->expectExceptionMessage('hash/size mismatch');
        $bundle['sources']->open($bundle['input'], $bundle['baseline']);
    }

    #[DataProvider('invalidRows')]
    public function test_resealed_input_rejects_misalignment_and_outcomes(string $kind): void
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

    public function test_unknown_input_version_rejected_even_with_synthetic_reseal(): void
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

    public function test_end_source_drift_prevents_publication_and_preserves_failure(): void
    {
        $bundle = Fixture::make($this->directory.'/fixture');
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
                    file_put_contents($this->path, ' ', FILE_APPEND);
                    $this->mutated = true;
                }

                return $bins;
            }
        });
        try {
            app(Experiment::class)->execute($bundle['input'], $bundle['baseline'], $this->directory.'/failed');
            $this->fail('End drift accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('hash/size mismatch', $e->getMessage());
        }
        $this->assertFileExists($this->directory.'/failed/FAILED.json');
        $this->assertFileDoesNotExist($this->directory.'/failed/COMPLETE.json');
        $this->assertSame('NOT_EVALUATED', Files::json($this->directory.'/failed/FAILED.json')['status']);
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

    public function test_plan_has_no_db_http_or_training_and_forbids_2026(): void
    {
        $this->artisan('keirin:stat39:c1-field-bike plan')->assertSuccessful();
        Http::assertNothingSent();
        $this->assertSame('FORBIDDEN', Contract::restrictions()['2026_access']);
        $this->expectExceptionMessage('Forbidden dataset year');
        iterator_to_array((new Dataset)->prediction([], 2026));
    }

    public function test_streaming_independent_128m_process_exceeds_100mib(): void
    {
        $execution = MemoryLimitedTestProcess::launch([PHP_BINARY, '-d', 'memory_limit=128M', base_path('tests/Support/stat39-c1-field-bike-memory.php'), $this->directory], $this->directory, [], 120);
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
