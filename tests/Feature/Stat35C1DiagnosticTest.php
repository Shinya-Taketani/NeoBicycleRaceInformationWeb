<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\ModelLoader as C2Loader;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\Trainer;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Diagnostic\Builder;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Diagnostic\Contract;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Diagnostic\Reader;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Diagnostic\Sources;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Diagnostic\Transitions;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Diagnostic\Utility;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\ModelLoader as C1Loader;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\MemoryLimitedTestProcess;
use Tests\Support\Stat35C1ComparisonFixture;
use Tests\Support\Stat35C1DiagnosticFixture as Fixture;
use Tests\TestCase;

class Stat35C1DiagnosticTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/stat35-diagnostic-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        DB::shouldReceive('connection')->never();
        Http::preventStrayRequests();
        foreach ([Trainer::class,
            \App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Trainer::class] as $class) {
            $this->app->bind($class, fn () => throw new RuntimeException('Training must not be resolved.'));
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_full_diagnostic_build_and_independent_reproduction_with_no_training_prediction_or_database(): void
    {
        $b = $this->fixture();
        $before = $b['source']['seals'];
        $builder = $this->builder($b);
        $first = $builder->build($b['compare'], $b['input'], $b['baseline'], $this->directory.'/result');
        $second = $this->builder($b)->build($b['compare'], $b['input'], $b['baseline'], $this->directory.'/reproduced', $this->directory.'/result');
        $this->assertTrue($second['independent_reproduction']);
        $this->assertSame($first['manifest'], $second['manifest']);
        $this->assertSame(['races' => 8, 'entries' => 40], $first['cohort'][2024]);
        $m = Files::json($this->directory.'/result/manifest.json');
        foreach ($m['files'] as $name => $seal) {
            $this->assertSame($seal, Files::identity($this->directory.'/reproduced/'.$name));
        }
        foreach ($before as $path => $seal) {
            $this->assertSame($seal, Files::identity($path));
        }
        $v = Files::json($this->directory.'/result/verification.json');
        $this->assertTrue($v['saved_utility_exact']);
        $this->assertTrue($v['primary_contributions_and_unrounded_rates_identical']);
        $this->assertSame(0, $v['training_prediction_ci_gate_executions']);
        $examples = Files::json($this->directory.'/result/examples.json');
        $this->assertSame(2, $examples[2024][1]['B'][0]['race_change']['source_race_line']);
        $this->assertSame(3, $examples[2024][1]['C'][0]['race_change']['source_race_line']);
        Http::assertNothingSent();
    }

    public function test_known_abcd_primary_not_map_and_changed_both_wrong_are_counted_separately(): void
    {
        $b = $this->fixture(5);
        $rows = iterator_to_array((new Reader)->rows($b['source'], 2024));
        $t = app(Transitions::class);
        foreach ($rows as $i => $row) {
            $change = $t->add($row['labels'], $row['c1']['decision'], $row['c2']['decision'], $row['contributions']);
            $this->assertSame(['A', 'B', 'C', 'D', 'D'][$i], $change['positions'][1]['transition']);
            if ($i === 3 || $i === 4) {
                $this->assertSame($i === 4, $change['positions'][1]['prediction_changed']);
            }
        }
        $s = $t->finish(Files::json($b['source']['comparisons']));
        foreach ($s['years'][2024]['positions'] as $p) {
            $this->assertSame([1, 1, 1, 2], [$p['A'], $p['B'], $p['C'], $p['D']]);
            $this->assertSame(5, $p['denominator']);
            $this->assertSame(2, $p['c1_numerator']);
            $this->assertSame(2, $p['c2_numerator']);
            $this->assertSame(3, $p['prediction_changed_eligible']);
            $this->assertSame(1, $p['changed_by_transition']['D']);
        }
        $matrix = $s['years'][2024]['hit3']['matrix'];
        $this->assertSame(1, $matrix[3][3]);
        $this->assertSame(1, $matrix[3][0]);
        $this->assertSame(1, $matrix[0][3]);
        $this->assertSame(2, $matrix[0][0]);
        $this->assertSame(6, $s['years'][2024]['hit3']['c1_numerator']);
        $this->assertSame(15, $s['years'][2024]['hit3']['denominator']);
        $this->assertNotSame([1, 2, 3], $rows[0]['c1']['decision']['map_ordered_top3']);
    }

    public function test_ties_at_each_position_exclude_only_ineligible_positions_and_hit3(): void
    {
        $b = $this->fixture();
        $t = app(Transitions::class);
        foreach ((new Reader)->rows($b['source'], 2024) as $row) {
            $t->add($row['labels'], $row['c1']['decision'], $row['c2']['decision'], $row['contributions']);
        }
        $s = $t->finish(Files::json($b['source']['comparisons']))['years'][2024];
        $this->assertSame([7, 6, 6], array_column($s['positions'], 'denominator'));
        $this->assertSame(5, $s['hit3']['eligible_races']);
        $this->assertSame(3, $s['hit3']['excluded']);
        $this->assertSame(3, $s['races_with_tied_entries']);
        $this->assertSame(15, $s['hit3']['denominator']);
    }

    public function test_zero_denominator_is_null_not_improvement_zero(): void
    {
        $b = $this->fixture(1);
        $row = iterator_to_array((new Reader)->rows($b['source'], 2024))[0];
        foreach ($row['labels']['entries'] as &$e) {
            $e['rank'] = null;
            $e['status'] = 'DISQUALIFIED';
        }
        unset($e);
        $m = app(Bt03e05MetricEvaluator::class);
        $saved = [];
        foreach (['C1' => 'c1', 'C2' => 'c2'] as $name => $key) {
            $saved[$name.'-STAT01'] = $m->raceComparison($row['labels'], $row[$key]['decision']);
        }
        $t = app(Transitions::class);
        $change = $t->add($row['labels'], $row['c1']['decision'], $row['c2']['decision'], $saved);
        $aggregate = $m->emptySummary();
        $m->add($aggregate, $saved['C2-STAT01']);
        $s = $t->finish(['outer' => ['C2-C1' => [2024 => $m->finish($aggregate)]]]);
        $this->assertNull($change['positions'][1]['transition']);
        $this->assertNull($s['years'][2024]['positions'][1]['delta']);
        $this->assertSame('NOT_EVALUATED', $s['years'][2024]['hit3']['status']);
        $this->assertNull($s['year_equal_delta']['hit3']);
        $this->assertSame(['DISQUALIFIED' => 5], $s['years'][2024]['abnormal_entry_statuses']);
    }

    public function test_saved_utility_is_exact_despite_different_bins_indexes_and_missing_direct_feature(): void
    {
        $b = $this->fixture(1);
        $row = iterator_to_array((new Reader)->rows($b['source'], 2024))[0];
        $c1 = app(C1Loader::class)->restore(Fixture::model(false));
        $c2 = app(C2Loader::class)->restore(Fixture::model(true));
        $calculator = app(Utility::class);
        foreach ($row['input']['entries'] as $i => $entry) {
            $detail = $calculator->entry($entry, $row['means'][$i], $c1, $c2);
            $detail = $calculator->verifySaved($detail, $row['c1']['probabilities']['entries'][$i], $row['c2']['probabilities']['entries'][$i]);
            foreach ($detail['positions'] as $p) {
                $this->assertSame(0.0, $p['c1_saved_residual']);
                $this->assertSame(0.0, $p['c2_saved_residual']);
                $this->assertLessThanOrEqual($p['rounding_bound'], abs($p['residuals']['delta_decomposition']));
            }
            if ($i === 4) {
                $this->assertSame('NULL_INACTIVE', $detail['mean6_state']);
                $this->assertNull($detail['mean6_bin_index']);
                $this->assertSame(0.0, $detail['positions']['POSITION_1']['delta_stat35']);
                $this->assertNotSame(0.0, $detail['positions']['POSITION_1']['delta_existing']);
            }
            if ($i === 0) {
                $this->assertSame(0.0, $detail['mean6']);
                $this->assertSame('UNSUPPORTED_BIN', $detail['mean6_state']);
                $this->assertNotSame($detail['c1_parameter_indexes'], array_slice($detail['c2_parameter_indexes'], 0, 16));
            }
            if ($i === 1) {
                $this->assertSame('ACTIVE', $detail['mean6_state']);
                $this->assertTrue($detail['positions']['POSITION_2']['mean6_active_zero_coefficient']);
                $this->assertSame(2, $detail['mean6_bin_index']);
                $this->assertNotSame(2, $detail['mean6_parameter_index']);
            }
        }
        $ledger = iterator_to_array($calculator->ledger(2024, 'C2', $c2));
        $inactive = array_values(array_filter($ledger, fn ($r) => $r['feature'] === 'STAT35_MEAN6' && $r['bin_index'] === 1));
        $this->assertCount(3, $inactive);
        $this->assertNull($inactive[0]['coefficient']);
        $this->assertNull($inactive[0]['active_parameter_index']);
    }

    public function test_numeric_zero_active_coefficient_zero_and_unseen_category_are_distinct(): void
    {
        $c1 = app(C1Loader::class)->restore(Fixture::model(false));
        $a = Fixture::model(true);
        $a['layout']['bins']['STAT35_MEAN6'] = [
            ['index' => 1, 'kind' => 'CATEGORY', 'lower_bound' => null, 'upper_bound' => null, 'category_value' => '0', 'training_support' => 10],
            ['index' => 2, 'kind' => 'CATEGORY', 'lower_bound' => null, 'upper_bound' => null, 'category_value' => '1', 'training_support' => 10],
        ];
        // Same active parameter mapping, only the mean6 definition differs in this synthetic model.
        array_pop($a['layout']['smooth_edges']);
        $a['layout']['numeric_edge_count']--;
        $c2 = app(C2Loader::class)->restore($a);
        $entry = iterator_to_array(Stat35C1ComparisonFixture::races(2024, 1))[0]['entries'][0];
        $zero = app(Utility::class)->entry($entry, 0.0, $c1, $c2);
        $unseen = app(Utility::class)->entry($entry, 0.5, $c1, $c2);
        $this->assertSame('ACTIVE', $zero['mean6_state']);
        $this->assertTrue($zero['positions']['POSITION_2']['mean6_active_zero_coefficient']);
        $this->assertNotSame(0.0, $zero['positions']['POSITION_1']['delta_stat35']);
        $this->assertSame('UNSEEN_CATEGORY', $unseen['mean6_state']);
        $this->assertSame(0, $unseen['mean6_bin_index']);
        $this->assertNull($unseen['mean6_parameter_index']);
    }

    public function test_outcomes_cannot_cross_the_utility_input_boundary(): void
    {
        $entry = iterator_to_array(Stat35C1ComparisonFixture::races(2024, 1))[0]['entries'][0];
        $entry['rank'] = 1;
        $this->expectExceptionMessage('Unexpected object fields');
        app(Utility::class)->entry($entry, 0.5, app(C1Loader::class)->restore(Fixture::model(false)), app(C2Loader::class)->restore(Fixture::model(true)));
    }

    #[DataProvider('invalidRows')]
    public function test_missing_duplicate_reordered_and_outcome_fields_are_rejected(string $kind): void
    {
        $b = $this->fixture(2);
        $key = in_array($kind, ['missing', 'duplicate_race', 'duplicate_entry', 'year', 'extra_outcome'], true) ? 'input' : 'sidecar';
        $path = $b['source']['paths'][2024][$key];
        $rows = iterator_to_array(Artifacts::lines($path));
        match ($kind) {
            'missing' => array_pop($rows),
            'duplicate_race' => $rows[1] = $rows[0],
            'duplicate_entry' => $rows[1]['entries'][0]['id'] = $rows[0]['entries'][0]['id'],
            'year' => $rows[0]['year'] = 2026,
            'extra_outcome' => $rows[0]['entries'][0]['rank'] = 1,
            'race' => $rows[0]['race_id']++,
            'entry' => $rows[0]['entries'][0]['id']++,
            'bike' => $rows[0]['entries'][0]['bike'] = 9,
            'entry_order' => $rows[0]['entries'] = array_reverse($rows[0]['entries']),
            'race_order' => $rows = array_reverse($rows),
            'missing_entry' => array_pop($rows[0]['entries']),
        };
        file_put_contents($path, implode('', array_map(fn ($r) => Files::canonical($r)."\n", $rows)));
        $this->expectException(\Throwable::class);
        iterator_to_array((new Reader)->rows($b['source'], 2024));
    }

    public static function invalidRows(): array
    {
        return array_map(fn ($v) => [$v], ['missing', 'duplicate_race', 'duplicate_entry', 'year', 'extra_outcome', 'race', 'entry', 'bike', 'entry_order', 'race_order', 'missing_entry']);
    }

    public function test_non_monotonic_ids_preserve_fixed_order(): void
    {
        $b = $this->fixture(3);
        $rows = iterator_to_array((new Reader)->rows($b['source'], 2024));
        $this->assertSame([202400003, 202400002, 202400001], array_column(array_column($rows, 'input'), 'race_id'));
    }

    public function test_body_drift_fails_closed_with_evidence_and_no_complete(): void
    {
        $b = $this->fixture(1);
        file_put_contents($b['source']['paths'][2024]['sidecar'], 'x', FILE_APPEND);
        try {
            $this->builder($b)->build($b['compare'], $b['input'], $b['baseline'], $this->directory.'/failed');
            $this->fail('Must reject drift.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('hash/size mismatch', $e->getMessage());
        }
        $this->assertFileExists($this->directory.'/failed/FAILED.json');
        $this->assertFileDoesNotExist($this->directory.'/failed/COMPLETE.json');
    }

    public function test_source_end_seal_detects_drift(): void
    {
        $b = $this->fixture(1);
        file_put_contents($b['source']['paths'][2025]['teacher'], 'x', FILE_APPEND);
        $this->expectExceptionMessage('hash/size mismatch');
        Sources::verify($b['source']);
    }

    public function test_unaccepted_manifest_pin_is_rejected(): void
    {
        $b = $this->fixture(1);
        $this->expectExceptionMessage('hash/size mismatch');
        (new Sources)->open($b['compare'], $b['input'], $b['baseline']);
    }

    public function test_unknown_input_version_is_rejected_even_with_a_new_synthetic_pin(): void
    {
        $b = $this->fixture(1);
        $path = $b['input'].'/manifest.json';
        $manifest = Files::json($path);
        $manifest['contract']['version'] = 'UNKNOWN';
        file_put_contents($path, Files::canonical($manifest));
        file_put_contents($b['input'].'/COMPLETE.json', Files::canonical(Files::identity($path)));
        $sources = new Sources(Files::identity($b['compare'].'/manifest.json'), hash_file('sha256', $path));
        $this->expectExceptionMessage('input contract');
        $sources->open($b['compare'], $b['input'], $b['baseline']);
    }

    public function test_complete_mismatch_and_generated_artifact_drift_are_rejected(): void
    {
        $b = $this->fixture(1);
        file_put_contents($b['input'].'/COMPLETE.json', Files::canonical(['bytes' => 0, 'sha256' => str_repeat('0', 64)]));
        try {
            $b['sources']->open($b['compare'], $b['input'], $b['baseline']);
            $this->fail('Bad COMPLETE accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('source COMPLETE', $e->getMessage());
        }
        $dir = Files::directory($this->directory.'/publication');
        $seal = Artifacts::json($dir, 'test.json', ['synthetic' => true]);
        file_put_contents($dir.'/test.json', 'changed');
        try {
            Artifacts::publish($dir, ['files' => ['test.json' => $seal]]);
            $this->fail('Changed generated file published.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('hash/size mismatch', $e->getMessage());
        }
        $this->assertFileDoesNotExist($dir.'/COMPLETE.json');
    }

    public function test_outcome_changes_do_not_change_utility_and_wrong_saved_counts_are_rejected(): void
    {
        $b = $this->fixture(1);
        $row = iterator_to_array((new Reader)->rows($b['source'], 2024))[0];
        $calculator = app(Utility::class);
        $c1 = app(C1Loader::class)->restore(Fixture::model(false));
        $c2 = app(C2Loader::class)->restore(Fixture::model(true));
        $before = $calculator->entry($row['input']['entries'][0], $row['means'][0], $c1, $c2);
        $row['labels']['entries'][0]['rank'] = 9;
        $row['labels']['entries'][0]['status'] = 'DISQUALIFIED';
        $this->assertSame($before, $calculator->entry($row['input']['entries'][0], $row['means'][0], $c1, $c2));
        $this->expectExceptionMessage('saved Primary contribution');
        app(Transitions::class)->add($row['labels'], $row['c1']['decision'], $row['c2']['decision'], $row['contributions']);
    }

    public function test_year_equal_is_not_pooled_and_aggregate_rate_mismatch_fails(): void
    {
        $b = $this->fixture(5);
        $t = app(Transitions::class);
        $metrics = app(Bt03e05MetricEvaluator::class);
        $old = ['outer' => ['C2-C1' => []]];
        foreach ([2024, 2025] as $year) {
            $aggregate = $metrics->emptySummary();
            foreach ((new Reader)->rows($b['source'], $year) as $i => $row) {
                if ($i >= ($year === 2024 ? 3 : 2)) {
                    continue;
                }
                $t->add($row['labels'], $row['c1']['decision'], $row['c2']['decision'], $row['contributions']);
                $paired = $row['contributions']['C2-STAT01'];
                $paired['baseline'] = $row['contributions']['C1-STAT01']['candidate'];
                $metrics->add($aggregate, $paired);
            }
            $old['outer']['C2-C1'][$year] = $metrics->finish($aggregate);
        }
        $delta = $t->finish($old)['year_equal_delta']['hit3'];
        $this->assertSame(-0.25, $delta);
        $this->assertNotEqualsWithDelta(-0.2, $delta, 1e-12);
        $old['outer']['C2-C1'][2024]['candidate']['POSITION_2_ACCURACY'] += 1e-12;
        $this->expectExceptionMessage('saved unrounded aggregate rates');
        $t->finish($old);
    }

    public function test_duplicate_race_across_years_is_rejected_by_shared_disk_identity_index(): void
    {
        $b = $this->fixture(1);
        $index = Reader::identityIndex();
        $first = iterator_to_array((new Reader)->rows($b['source'], 2024, $index));
        $path = $b['source']['paths'][2025]['input'];
        $rows = iterator_to_array(Artifacts::lines($path));
        $rows[0]['race_id'] = $first[0]['input']['race_id'];
        file_put_contents($path, Files::canonical($rows[0])."\n");
        $this->expectException(\PDOException::class);
        iterator_to_array((new Reader)->rows($b['source'], 2025, $index));
    }

    public function test_unknown_model_version_is_rejected_by_existing_loader(): void
    {
        $model = Fixture::model(true);
        $model['model_version'] = 'UNKNOWN';
        $this->expectExceptionMessage('version/anchor/lambda');
        app(C2Loader::class)->restore($model);
    }

    public function test_incorrect_saved_utility_is_not_hidden_by_regrouping_tolerance(): void
    {
        $b = $this->fixture(1);
        $row = iterator_to_array((new Reader)->rows($b['source'], 2024))[0];
        $utility = app(Utility::class);
        $detail = $utility->entry($row['input']['entries'][0], $row['means'][0], app(C1Loader::class)->restore(Fixture::model(false)), app(C2Loader::class)->restore(Fixture::model(true)));
        $saved = $row['c1']['probabilities']['entries'][0];
        $saved['utilities']['POSITION_1'] += 1e-14;
        $this->expectExceptionMessage('did not match exactly');
        $utility->verifySaved($detail, $saved, $row['c2']['probabilities']['entries'][0]);
    }

    public function test_diagnostic_overwrite_and_tampered_reproduction_source_are_rejected(): void
    {
        $b = $this->fixture(1);
        $builder = $this->builder($b);
        $builder->build($b['compare'], $b['input'], $b['baseline'], $this->directory.'/result');
        try {
            $builder->build($b['compare'], $b['input'], $b['baseline'], $this->directory.'/result');
            $this->fail('Overwrite accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('must be new', $e->getMessage());
        }
        file_put_contents($this->directory.'/result/transition-summary.json', 'x', FILE_APPEND);
        $this->expectExceptionMessage('hash/size mismatch');
        $builder->build($b['compare'], $b['input'], $b['baseline'], $this->directory.'/reproduced', $this->directory.'/result');
    }

    public function test_plan_has_frozen_tolerance_and_no_sources_or_execution(): void
    {
        $this->artisan('keirin:stat35:c1-diagnostic', ['mode' => 'plan'])->assertSuccessful();
        $this->assertSame(64, Contract::plan()['rounding_ulps']);
        $this->assertFalse(Contract::plan()['training_prediction_bootstrap_gate_execution']);
    }

    public function test_streaming_build_runs_in_an_actual_independent_128m_process(): void
    {
        $execution = MemoryLimitedTestProcess::launch([PHP_BINARY, '-d', 'memory_limit=128M',
            base_path('tests/Support/stat35-c1-diagnostic-memory.php'), $this->directory], $this->directory, [], 180);
        $this->assertSame(0, $execution['exit_code'], file_get_contents($this->directory.'/stdout.log').file_get_contents($this->directory.'/stderr.log'));
        $m = Files::json($this->directory.'/measurement.json');
        $this->assertSame('128M', $m['limit']);
        $this->assertNotSame(getmypid(), $m['pid']);
        $this->assertSame(24000, $m['races']);
        $this->assertSame(120000, $m['entries']);
        $this->assertGreaterThan(100 * 1024 * 1024, $m['stream_bytes']);
        $this->assertLessThan(128 * 1024 * 1024, $m['peak']);
    }

    private function fixture(int $count = 8): array
    {
        return Fixture::make($this->directory.'/fixture', $count);
    }

    private function builder(array $bundle): Builder
    {
        $this->app->instance(Sources::class, $bundle['sources']);

        return app(Builder::class);
    }
}
