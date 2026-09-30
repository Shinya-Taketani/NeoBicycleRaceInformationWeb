<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\Dataset;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\Evaluation;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\Experiment;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\Sources;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\MemoryLimitedTestProcess;
use Tests\Support\Stat35C1ComparisonFixture as Fixture;
use Tests\TestCase;

class Stat35C1ComparisonTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/stat35-compare-'.bin2hex(random_bytes(8));
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

    public function test_execute_independent_training_reproduction_and_fail_closed_overwrite(): void
    {
        $bundle = Fixture::make($this->directory.'/fixture');
        $this->app->instance(Sources::class, $bundle['sources']);
        $result = app(Experiment::class)->execute($bundle['input'], $bundle['baseline'], $this->directory.'/result');
        $this->assertSame('COMPLETED_NOT_ADOPTED', $result['status']);
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
                $this->assertSame(Files::identity($runs[0].'/C2-fit-2024/'.$name), Files::identity($runs[$index].'/C2-fit-2024/'.$name));
            }
            $this->assertSame(Files::identity($runs[0].'/C2-fit-2025/'.$name), Files::identity($runs[2].'/C2-fit-2025/'.$name));
        }
        $this->assertNotSame(Files::identity($runs[0].'/C2-fit-2025/model.json'), Files::identity($runs[1].'/C2-fit-2025/model.json'));
    }

    #[DataProvider('invalidRows')]
    public function test_invalid_alignment_and_outcome_fields_fail_even_with_resealed_synthetic_stream(string $kind): void
    {
        $bundle = Fixture::make($this->directory.'/fixture');
        $source = $bundle['source'];
        $path = $source['paths'][2024][$kind === 'sidecar' ? 'sidecar' : 'input'];
        $rows = iterator_to_array(Artifacts::lines($path));
        match ($kind) {
            'sidecar' => $rows[0]['entries'][0]['id']++,
            'outcome' => $rows[0]['entries'][0]['rank'] = 1,
            'order' => $rows = array_reverse($rows),
            'count' => array_pop($rows),
            'duplicate' => $rows[1] = $rows[0],
            'year' => $rows[0]['year'] = 2026,
        };
        file_put_contents($path, implode('', array_map(fn ($r) => Files::canonical($r)."\n", $rows)));
        $source['seals'][$path] = Files::identity($path);
        $this->expectException(\Throwable::class);
        iterator_to_array((new Dataset)->prediction($source, 2024));
    }

    public static function invalidRows(): array
    {
        return array_map(fn ($v) => [$v], ['sidecar', 'outcome', 'order', 'count', 'duplicate', 'year']);
    }

    public function test_source_drift_is_rejected_without_republishing_or_opening_teachers(): void
    {
        $bundle = Fixture::make($this->directory.'/fixture');
        file_put_contents($bundle['source']['paths'][2024]['sidecar'], 'x', FILE_APPEND);
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
        $this->expectExceptionMessage('Unaccepted fixed source');
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
        $this->assertFileExists($dir.'/C2-fit-2024/sealed.json');
        $this->assertFileDoesNotExist($dir.'/COMPLETE.json');
        $this->assertSame('NOT_EVALUATED', Files::json($dir.'/FAILED.json')['status']);
        $this->assertFileDoesNotExist($dir.'/C2-fit-2025/model.json');
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
            base_path('tests/Support/stat35-c1-comparison-memory.php'), $this->directory], $this->directory, [], 120);
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
