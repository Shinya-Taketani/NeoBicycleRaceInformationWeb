<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Experiments\GrowthAdjustmentCalibration\Metrics;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendAdjustmentCalibration\Adjustment;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendAdjustmentCalibration\Code;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendAdjustmentCalibration\Comparison;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendAdjustmentCalibration\Contract;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendAdjustmentCalibration\Service;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendAdjustmentCalibration\Signal;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendAdjustmentCalibration\Sources;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendAdjustmentCalibration\Store;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendAdjustmentCalibration\TemporalAccess;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendAdjustmentCalibration\Workspace;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendScoreSource\OuterSource;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Predictor;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\ResultStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\GrowthTrendCalibrationFixture;
use Tests\TestCase;

class GrowthTrendAdjustmentCalibrationTest extends TestCase
{
    use GrowthTrendCalibrationFixture;

    private string $directory;

    private array $outcomeSeals = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/trend-calibration-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        config(['tactical_prediction_pipeline.artifact_base' => $this->directory, 'database.default' => 'growth_disabled']);
        DB::shouldReceive('connection')->never();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_plan_and_fixed_grid_are_outcome_free(): void
    {
        $this->artisan('keirin:backtest:growth-trend-adjustment-calibration', ['--plan' => true])->assertSuccessful();
        $grid = Contract::grid();
        $this->assertSame(range(-50, 50), array_column($grid, 'k'));
        foreach ($grid as $c) {
            $this->assertSame($c['k'] / 100, $c['w']);
            $this->assertSame(exp($c['w']), $c['plus1_vs_zero_odds']);
            $this->assertSame(exp(-$c['w']), $c['minus1_vs_zero_odds']);
            $this->assertSame(exp(2 * $c['w']), $c['plus1_vs_minus1_odds']);
        }
        $this->assertSame('MEETING_DELTA_LAG_1', Contract::plan()['signal']);
        $this->assertSame('POST_SELECTION_DEVELOPMENT_TRANSFER_DIAGNOSTIC', Contract::plan()['year_roles'][2025]);
    }

    public function test_projection_is_single_signal_and_bounded_at_full_cohort(): void
    {
        $before = memory_get_usage(true);
        $row = $this->trend()[0];
        $n = 0;
        foreach (Signal::projection((function () use ($row) {
            for ($i = 1; $i <= 356209; $i++) {
                yield array_replace($row, ['entry_id' => $i]);
            }
        })()) as $p) {
            $n++;
            if ($n === 1) {
                $this->assertArrayNotHasKey('candidates', $p);
                $this->assertArrayNotHasKey('unneeded_outcome_diagnostic', $p);
                $this->assertSame(-3.0, $p['raw']);
            }
        }
        $this->assertSame(356209, $n);
        $this->assertLessThan(4 * 1024 * 1024, memory_get_usage(true) - $before);
    }

    #[DataProvider('normalizations')]
    public function test_normalization_missing_zero_clipping_and_anchor(?float $raw, string $status, ?float $expected): void
    {
        [$row, $model] = $this->prepared();
        $row['growth'][0]['raw'] = $raw;
        $row['growth'][0]['status'] = $status;
        $row['growth'][0]['boundary_ambiguous'] = $status === 'PARTIAL_TIME_ORDER';
        $this->assertSame($expected, Signal::normalized($row['growth'][0], 3.0));
        $a = app(Adjustment::class);
        $this->assertSame($row['race'], $a->apply($row['race'], $row['growth'], 0, 3.0));
        $this->assertSame(app(Predictor::class)->predict($row['race'], $model->fit), $a->predict($row, 0, 3.0, $model->fit));
        foreach ([-25, 25] as $k) {
            $result = $a->apply($row['race'], $row['growth'], $k, 3.0);
            $this->assertSame($expected === null || $expected === 0.0 ? 0.0 : ($k / 100) * $expected, $result['entries'][0]['anchor']);
        }
    }

    public static function normalizations(): array
    {
        return [[9.0, 'VALID', 1.0], [-9.0, 'VALID', -1.0], [0.0, 'VALID', 0.0], [1.5, 'VALID', 0.5],
            [null, 'MISSING_PREVIOUS_SCORE', null], [null, 'PARTIAL_TIME_ORDER', null], [null, 'LEFT_TRUNCATED_POSSIBLE', null]];
    }

    public function test_scale_type7_uses_only_2024_and_rejects_zero(): void
    {
        $w = new Workspace($this->directory.'/scale.sqlite');
        $q = $w->db->prepare('INSERT INTO signals(id,year,raw) VALUES(?,?,?)');
        foreach ([1, 2, 3, 4] as $i => $v) {
            $q->execute([$i + 1, 2024, $v]);
        }
        $q->execute([5, 2025, 999999]);
        $this->assertSame(3.9699999999999998, $w->scaling()['abs_raw_p99']);
        $first = $w->scaling();
        $w->db->exec('UPDATE signals SET raw=-100000000 WHERE year=2025');
        $this->assertSame($first, $w->scaling());
        $w->db->exec('UPDATE signals SET raw=0 WHERE year=2024');
        $this->expectException(RuntimeException::class);
        $w->scaling();
    }

    public function test_selection_boundaries_maximum_and_both_tie_breakers(): void
    {
        $curve = $this->curve();
        $this->assertSame(0, Contract::select($curve)['selected']['k']);
        foreach ([-8, -3, 3, 8] as $k) {
            $curve['candidates'][$k + 50]['metrics'][Contract::METRICS[3]] = ['rate' => 0.6, 'delta' => 0.1];
        }
        $this->assertSame(-3, Contract::select($curve)['selected']['k']);
        $curve['candidates'][47]['metrics'][Contract::METRICS[0]]['delta'] = -0.003;
        $this->assertSame(-3, Contract::select($curve)['selected']['k']);
        $curve['candidates'][47]['metrics'][Contract::METRICS[0]]['delta'] = -0.0030000001;
        $this->assertSame(3, Contract::select($curve)['selected']['k']);
        $curve['candidates'][100]['metrics'][Contract::METRICS[3]] = ['rate' => 0.7, 'delta' => 0.2];
        $this->assertSame('BOUNDARY_SELECTED', Contract::select($curve)['boundary_status']);
        $curve['year'] = 2025;
        $this->expectException(RuntimeException::class);
        Contract::select($curve);
    }

    #[DataProvider('accessYears')]
    public function test_temporal_access_refuses_identity_resolution_without_required_seal(int $year, bool $scaling): void
    {
        $access = new TemporalAccess;
        if ($scaling) {
            $this->seal($access, 'signal-scaling');
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($scaling ? 'selection must be sealed' : 'signal-scaling must be sealed');
        app(Sources::class)->outcome('/physically-absent', $year, $access);
    }

    public static function accessYears(): array
    {
        return [[2024, false], [2025, false], [2025, true]];
    }

    public function test_temporal_seal_tamper_cannot_unlock_outcomes(): void
    {
        $a = new TemporalAccess;
        $this->seal($a, 'signal-scaling');
        $this->seal($a, 'selection');
        file_put_contents($this->directory.'/selection.json', ' ', FILE_APPEND);
        $this->expectException(RuntimeException::class);
        $a->authorize(2025, 'OPEN');
    }

    public function test_execute_withholds_2025_until_seal_then_reproduces_all_bytes(): void
    {
        $s = $this->fixture();
        $outcomes = $this->outcomePaths($s['outer_root'], 2025);
        foreach ($outcomes as $p) {
            rename($p, $p.'.withheld');
        }
        $this->bind($s, function (int $year, TemporalAccess $a) use ($outcomes): void {
            if ($year === 2024) {
                foreach ($outcomes as $p) {
                    $this->assertFileDoesNotExist($p);
                }
            }
            if ($year === 2025) {
                $events = $a->artifact()['events'];
                $this->assertContains('SELECTION_SEALED', array_column($events, 'event'));
                foreach ($outcomes as $p) {
                    rename($p.'.withheld', $p);
                }
            }
        });
        $root = Files::directory($this->directory.'/output');
        $r = app(Service::class)->execute($root, 'fixture', '', '');
        $this->assertSame('CALIBRATION_LOCKED', $r['status']);
        $this->assertSame('NONE', $r['database']);
        $this->assertSame(0, $r['2026_access']);
        $this->assertLessThan(128 * 1024 * 1024, $r['peak_bytes']);
        $bundle = $r['path'];
        $before = app(Store::class)->verify($bundle);
        $this->bind($s);
        $again = app(Service::class)->reproduce($root, 'fixture');
        $this->assertSame('REPRODUCED', $again['status']);
        $this->assertSame($before, app(Store::class)->verify($again['path']));
        $this->assertSame($before, app(Store::class)->verify($bundle));
        $transfer = Files::json($bundle.'/transfer-2025.json');
        $curve = Files::json($bundle.'/coefficient-curve-2025.json');
        $this->assertSame($transfer['selected'], $curve['candidates'][$transfer['selected']['k'] + 50]);
        foreach ([2024, 2025] as $year) {
            $b = Files::json($bundle.'/baseline-'.$year.'.json');
            $c = Files::json($bundle.'/coefficient-curve-'.$year.'.json')['candidates'][50];
            $this->assertSame($b['rates'], $c['metrics']);
            $this->assertSame(0, $c['changes']['any_primary']);
        }
        $state = Files::json($bundle.'/growth-state-diagnostics.json');
        $this->assertSame(1, $state[2024]['MISSING']['entries']);
        $this->assertSame(1, $state[2024]['ZERO']['entries']);
        $this->assertFileDoesNotExist($s['outer_root'].'/report-export-manifest.json');
        $this->assertSame($this->outcomeSeals, Files::json($bundle.'/fixed-outcome-seals.json'));
        $trust = Files::json($bundle.'/outcome-source-trust-audit.json');
        $this->assertSame(Sources::OUTCOME_TRUST_ANCHOR, $trust['trust_anchor_type']);
        $this->assertFalse($trust['report_export_manifest_used']);
        $this->assertTrue($trust['2024_verified_after_scaling_seal']);
        $this->assertTrue($trust['2025_verified_after_selection_seal']);
        $this->assertSame($this->outcomeSeals, Files::json($bundle.'/source-end.json')['fixed_outcome_seals']);
        $events = Files::json($bundle.'/temporal-access-audit.json')['events'];
        $sequence = [];
        foreach ($events as $event) {
            $sequence[$event['year'].':'.$event['event']] ??= $event['sequence'];
        }
        $this->assertLessThan($sequence['2024:OUTCOME_IDENTITY_RESOLVE'], $sequence[':SCALING_SEALED']);
        $this->assertLessThan($sequence['2025:OUTCOME_IDENTITY_RESOLVE'], $sequence[':SELECTION_SEALED']);
    }

    #[DataProvider('outcomeKinds')]
    public function test_2025_outcome_mutation_keeps_all_preselection_bytes_identical(string $kind): void
    {
        $s = $this->fixture();
        $this->bind($s);
        $root = Files::directory($this->directory.'/output');
        $one = app(Service::class)->execute($root, 'one', '', '')['path'];
        $path = $s['outer_root'].'/'.$this->outcomeSeals[2025][$kind]['relative'];
        $rows = $this->alternateOutcomeRows(2025, $kind);
        unlink($path);
        unlink($path.'.manifest.json');
        JsonlArtifact::write($path, $rows);
        Files::verify($path, Files::json($path.'.manifest.json'));
        try {
            app(Service::class)->execute($root, 'two', '', '');
            $this->fail('Self-signed 2025 outcomes published.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Artifact hash/size mismatch', $e->getMessage());
        }
        $this->assertDirectoryDoesNotExist($root.'/evaluations/two');
        $two = dirname(glob($root.'/.staging/two-*/failure.json')[0]);
        $this->assertFileDoesNotExist($two.'/baseline-2025.json');
        foreach (['sources.json', 'signal-projection.jsonl', 'signal-scaling.json', 'signal-scaling-seal.json',
            'prediction-input-2024.jsonl', 'prediction-input-2025.jsonl', 'baseline-2024.json',
            'coefficient-curve-2024.json', 'coefficient-curve-2024.csv', 'selection.json', 'selection-seal.json'] as $name) {
            $this->assertSame(Files::identity($one.'/'.$name), Files::identity($two.'/'.$name), $name);
        }
    }

    public static function outcomeKinds(): array
    {
        return [['labels'], ['contributions']];
    }

    #[DataProvider('outcomeMutations')]
    public function test_fixed_outcome_tampering_fails_at_year_boundary_without_publication(int $year, string $kind, string $mutation): void
    {
        $s = $this->fixture();
        $path = $s['outer_root'].'/'.$this->outcomeSeals[$year][$kind]['relative'];
        $before = Files::identity($path);
        if ($mutation === 'body_and_sidecar' || $mutation === 'rows') {
            $rows = iterator_to_array(JsonlArtifact::read($path));
            if ($mutation === 'rows') {
                $rows[] = $rows[0];
            } else {
                $rows = $this->alternateOutcomeRows($year, $kind);
            }
            unlink($path);
            unlink($path.'.manifest.json');
            JsonlArtifact::write($path, $rows);
            Files::verify($path, Files::json($path.'.manifest.json'));
            $this->assertNotSame($before, Files::identity($path));
        } else {
            $target = $mutation === 'body_only' ? $path : $path.'.manifest.json';
            $body = file_get_contents($target);
            $body[0] = $body[0] === '{' ? '[' : '{';
            file_put_contents($target, $body);
            $this->assertSame(strlen($body), Files::identity($target)['bytes']);
        }
        $this->bind($s);
        $root = Files::directory($this->directory.'/output');
        try {
            app(Service::class)->execute($root, 'tampered', '', '');
            $this->fail('Unreviewed outcome accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Artifact hash/size mismatch: '.$path.($mutation === 'body_only' ? '' : '.manifest.json'), $e->getMessage());
        }
        $this->assertDirectoryDoesNotExist($root.'/evaluations/tampered');
        $stages = glob($root.'/.staging/tampered-*/failure.json');
        $this->assertCount(1, $stages);
        $stage = dirname($stages[0]);
        $this->assertFileExists($stage.'/signal-scaling-seal.json');
        if ($year === 2024) {
            $this->assertFileDoesNotExist($stage.'/coefficient-curve-2024.json');
            $this->assertFileDoesNotExist($stage.'/selection.json');
        } else {
            $this->assertFileExists($stage.'/baseline-2024.json');
            $this->assertFileExists($stage.'/coefficient-curve-2024.json');
            $this->assertFileExists($stage.'/selection-seal.json');
            $this->assertFileDoesNotExist($stage.'/baseline-2025.json');
        }
    }

    public static function outcomeMutations(): array
    {
        $cases = [];
        foreach ([2024, 2025] as $year) {
            foreach (['labels', 'contributions'] as $kind) {
                foreach (['body_and_sidecar', 'body_only', 'sidecar_only', 'rows'] as $mutation) {
                    $cases[$year.'-'.$kind.'-'.$mutation] = [$year, $kind, $mutation];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('sidecarFields')]
    public function test_verified_sidecar_still_must_match_every_fixed_body_field(string $field): void
    {
        $s = $this->fixture();
        $path = $s['outer_root'].'/'.$this->outcomeSeals[2024]['labels']['relative'].'.manifest.json';
        $sidecar = Files::json($path);
        $sidecar[$field] = $field === 'sha256' ? str_repeat('0', 64) : $sidecar[$field] + 1;
        file_put_contents($path, Files::canonical($sidecar)."\n");
        // Pin this synthetic sidecar independently, leaving the reviewed body seal unchanged.
        $this->outcomeSeals[2024]['labels']['sidecar'] = Files::identity($path);
        $this->bind($s);
        $a = new TemporalAccess;
        $this->seal($a, 'signal-scaling');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Semantic mismatch: reviewed outcome sidecar');
        app(Sources::class)->outcome($s['outer_root'], 2024, $a);
    }

    public static function sidecarFields(): array
    {
        return [['rows'], ['bytes'], ['sha256']];
    }

    #[DataProvider('tamperKinds')]
    public function test_source_code_and_generated_drift_refused_before_publication(string $kind): void
    {
        $s = $this->fixture();
        $root = Files::directory($this->directory.'/output');
        $this->bind($s, function (int $year, TemporalAccess $a) use ($kind, $s, $root): void {
            if ($year !== 2025) {
                return;
            }
            if ($kind === 'source') {
                file_put_contents($s['trend'].'.manifest.json', ' ', FILE_APPEND);
            }
            if ($kind === 'generated') {
                file_put_contents(glob($root.'/.staging/*/prediction-input-2024.jsonl')[0], ' ', FILE_APPEND);
            }
        });
        if ($kind === 'code') {
            app()->instance(Code::class, new class extends Code
            {
                private int $calls = 0;

                public function capture(): array
                {
                    return ['fixture_revision' => ++$this->calls];
                }
            });
        }
        try {
            app(Service::class)->execute($root, 'bad', '', '');
            $this->fail('Tampered source published.');
        } catch (\Throwable $e) {
            $this->assertMatchesRegularExpression('/mismatch|integrity|Syntax error/', $e->getMessage());
        }
        $this->assertDirectoryDoesNotExist($root.'/evaluations/bad');
        $this->assertCount(1, glob($root.'/.staging/*/failure.json'));
    }

    public static function tamperKinds(): array
    {
        return [['source'], ['code'], ['generated']];
    }

    public function test_duplicate_entries_and_cohort_rejected(): void
    {
        $s = $this->fixture();
        JsonlArtifact::write($this->directory.'/projection.jsonl', Signal::projection(JsonlArtifact::read($s['trend'])));
        $w = new Workspace($this->directory.'/duplicate.sqlite');
        $w->load($this->directory.'/projection.jsonl', $s['meeting']);
        [$row] = $this->prepared();
        $w->join($row['race'], 0.0);
        $this->expectException(\PDOException::class);
        $w->join($row['race'], 0.0);
    }

    public function test_2026_rejected_before_artifact_or_outcome_access(): void
    {
        foreach (['row', 'outcome', 'id', 'source'] as $kind) {
            try {
                match ($kind) {
                    'row' => iterator_to_array(Signal::projection($this->trend(2026))),
                    'outcome' => app(Sources::class)->outcome('/absent', 2026, new TemporalAccess),
                    'id' => app(Service::class)->execute('/absent', 'analysis-2026', '', ''),
                    'source' => app(Sources::class)->verify(['years' => [2026 => []], 'files' => []]),
                };
                $this->fail('2026 accepted: '.$kind);
            } catch (RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_unknown_signal_status_and_outcome_fields_rejected(): void
    {
        [$row] = $this->prepared();
        $row['race']['entries'][0]['rank'] = 1;
        $this->expectException(RuntimeException::class);
        app(Adjustment::class)->apply($row['race'], $row['growth'], 1, 3.0);
    }

    #[DataProvider('badSignals')]
    public function test_inconsistent_or_nonfinite_signal_rejected(array $changes): void
    {
        [$row] = $this->prepared();
        $this->expectException(RuntimeException::class);
        Signal::validate(array_replace($row['growth'][0], $changes));
    }

    public static function badSignals(): array
    {
        return [[['signal_id' => 'SCORE_POINT_V2']], [['status' => 'UNKNOWN']], [['status' => 'MISSING_PREVIOUS_SCORE']],
            [['raw' => null]], [['raw' => INF]], [['raw' => NAN]], [['bike' => 10]], [['boundary_ambiguous' => true]],
            [['rank' => 1]], [['player_id' => 0]]];
    }

    public function test_transfer_strict_hit3_and_inclusive_position_boundaries(): void
    {
        $row = $this->curve()['candidates'][51];
        $this->assertSame('NOT_TRANSFERRED_POST_SELECTION_DEVELOPMENT_REPLAY', Contract::transfer($row));
        $row['metrics'][Contract::METRICS[3]]['delta'] = 0.000001;
        $row['metrics'][Contract::METRICS[0]]['delta'] = -0.003;
        $this->assertSame('DIRECTIONALLY_CONSISTENT_POST_SELECTION_DEVELOPMENT_REPLAY', Contract::transfer($row));
        $row['metrics'][Contract::METRICS[0]]['delta'] = -0.0030001;
        $this->assertSame('NOT_TRANSFERRED_POST_SELECTION_DEVELOPMENT_REPLAY', Contract::transfer($row));
        $row['k'] = 0;
        $this->assertSame('NO_INCREMENTAL_ADJUSTMENT_SELECTED', Contract::transfer($row));
    }

    public function test_path_year_guard_does_not_mistake_generation_dates_for_holdout(): void
    {
        Contract::path('/artifacts/calibration-20260920-01/2025-input.jsonl');
        foreach (['/artifacts/2026/input.jsonl', '/artifacts/inputs-2026.jsonl', '/artifacts/model_2026.json'] as $path) {
            try {
                Contract::path($path);
                $this->fail('2026 path accepted.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('2026', $e->getMessage());
            }
        }
    }

    public function test_missing_scale_and_invalid_scale_are_not_fallbacks(): void
    {
        $w = new Workspace($this->directory.'/empty.sqlite');
        try {
            $w->scaling();
            $this->fail('Empty scale accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('SCALE_P99', $e->getMessage());
        }
        [$row] = $this->prepared();
        foreach ([0.0, -1.0, INF, NAN] as $scale) {
            try {
                Signal::normalized($row['growth'][0], $scale);
                $this->fail('Invalid scale accepted.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('SCALE_P99', $e->getMessage());
            }
        }
    }

    public function test_preflight_refuses_wrong_fixed_prediction_before_scaling_or_selection(): void
    {
        $s = $this->fixture();
        $p = $s['years'][2025]['prediction'];
        $rows = iterator_to_array(JsonlArtifact::read($p));
        $rows[0]['decision']['primary_position_1_bike'] = 99;
        unlink($p);
        unlink($p.'.manifest.json');
        JsonlArtifact::write($p, $rows);
        foreach ([$p, $p.'.manifest.json'] as $path) {
            $s['files'][$path] = Files::identity($path);
        }
        $this->bind($s);
        $root = Files::directory($this->directory.'/output');
        try {
            app(Service::class)->execute($root, 'bad', '', '');
            $this->fail('Bad prediction accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('w=0 full probability/decision', $e->getMessage());
        }
        $this->assertSame([], glob($root.'/.staging/*/signal-scaling.json'));
        $this->assertSame([], glob($root.'/.staging/*/selection.json'));
    }

    public function test_inventory_cannot_publish_missing_artifacts_and_locked_bundle_is_immutable(): void
    {
        $s = $this->fixture();
        $this->bind($s);
        $root = Files::directory($this->directory.'/output');
        $r = app(Service::class)->execute($root, 'fixture', '', '');
        $before = app(Store::class)->verify($r['path']);
        try {
            app(Service::class)->execute($root, 'fixture', '', '');
            $this->fail('Overwrote bundle.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot be overwritten', $e->getMessage());
        }
        $this->assertSame($before, app(Store::class)->verify($r['path']));
        file_put_contents($r['path'].'/selection-seal.json', ' ', FILE_APPEND);
        $this->expectException(RuntimeException::class);
        app(Service::class)->reproduce($root, 'fixture');
    }

    public function test_dead_heat_denominators_unchanged(): void
    {
        [$row,$model] = $this->prepared();
        $labels = $row['race'];
        foreach ($labels['entries'] as $i => &$entry) {
            $entry['rank'] = $i < 2 ? 1 : $i + 1;
            $entry['status'] = $i < 2 ? 'TIED' : 'FINISHED';
        }
        unset($entry);
        $p = app(Adjustment::class)->predict($row, 0, 3.0, $model->fit);
        $m = app(Metrics::class)->contribution(Metrics::context($labels, $row['race']), $p);
        $this->assertSame(0.0, $m[Contract::METRICS[0]]['denominator']);
        $this->assertSame(0.0, $m[Contract::METRICS[3]]['denominator']);
        $this->assertSame(1.0, $m[Contract::METRICS[2]]['denominator']);
    }

    public function test_old_calibration_is_verified_after_selection_and_never_changed(): void
    {
        $root = Files::directory($this->directory.'/old-reference');
        $writer = app(ResultStore::class);
        $candidate = $this->curve()['candidates'][53];
        $expected = [];
        foreach (['selection.json' => ['selected' => $candidate], 'validation-2025.json' => ['selected' => $candidate, 'status' => 'NOT_REPLICATED'],
            'coefficient-curve-2024.json' => $this->curve()] as $name => $data) {
            $expected += $writer->writeJson($root, $name, $data);
        }
        $manifest = $writer->writeJson($root, 'manifest.json', ['files' => $expected]);
        $writer->writeJson($root, 'LOCKED.json', $manifest['manifest.json']);
        $reader = new class($root) extends Comparison
        {
            public function __construct(private string $root) {}

            protected function path(): string
            {
                return $this->root;
            }
        };
        $access = new TemporalAccess;
        $this->seal($access, 'signal-scaling');
        try {
            $reader->read($access, $candidate, []);
            $this->fail('Old outcomes accessed before selection.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('selection must be sealed', $e->getMessage());
        }
        $this->seal($access, 'selection');
        $result = $reader->read($access, $candidate, []);
        $this->assertSame('SCORE_POINT_V2', $result['old']['signal']);
        foreach ($result['files'] as $path => $seal) {
            $this->assertSame(['bytes' => $seal['bytes'], 'sha256' => $seal['sha256']], Files::identity($path));
        }
        file_put_contents($root.'/selection.json', ' ', FILE_APPEND);
        $this->expectException(RuntimeException::class);
        $reader->read($access, $candidate, []);
    }

    #[DataProvider('magnitudeBoundaries')]
    public function test_exact_magnitude_bin_boundaries(?float $g, string $bin): void
    {
        $this->assertSame($bin, Signal::magnitude($g));
    }

    public static function magnitudeBoundaries(): array
    {
        return [[-1.0, '[-1,-0.75)'], [-0.75, '[-0.75,-0.5)'], [-0.5, '[-0.5,-0.25)'], [-0.25, '[-0.25,0)'],
            [0.0, 'ZERO'], [0.25, '(0,0.25]'], [0.5, '(0.25,0.5]'], [0.75, '(0.5,0.75]'], [1.0, '(0.75,1]'], [null, 'MISSING']];
    }

    private function seal(TemporalAccess $access, string $kind): void
    {
        $w = app(ResultStore::class);
        $expected = $w->writeJson($this->directory, $kind.'.json', ['fixture' => true]);
        $expected += $w->writeJson($this->directory, $kind.'-seal.json', $expected[$kind.'.json']);
        $access->seal($kind, $this->directory, $expected);
    }

    private function curve(): array
    {
        return ['year' => 2024, 'candidates' => array_map(fn ($r) => $r + ['metrics' => array_fill_keys(Contract::METRICS, ['rate' => 0.5, 'delta' => 0.0])], Contract::grid())];
    }

    private function bind(array $source, ?\Closure $beforeOutcome = null): void
    {
        app()->instance(Sources::class, new class(app(OuterSource::class), $source, $beforeOutcome, $this->outcomeSeals) extends Sources
        {
            public function __construct(OuterSource $outer, private array $fixture, private ?\Closure $hook, private array $seals)
            {
                parent::__construct($outer);
            }

            protected function outcomeFiles(): array
            {
                return $this->seals;
            }

            public function open(string $outerRoot, string $trend): array
            {
                return $this->fixture;
            }

            public function outcome(string $root, int $year, TemporalAccess $access): array
            {
                $access->authorize($year, 'TEST_PHASE_BOUNDARY');
                if ($this->hook) {
                    ($this->hook)($year, $access);
                }

                return parent::outcome($root, $year, $access);
            }
        });
        app()->instance(Comparison::class, new class extends Comparison
        {
            public function read(TemporalAccess $access, array $selected, array $transfer): array
            {
                $access->authorize(2025, 'OLD_CALIBRATION_DIAGNOSTIC_OPEN');

                return ['files' => [], 'fixture' => 'NO_REAL_OLD_OUTCOME_ACCESS'];
            }
        });
    }

    private function fixture(): array
    {
        $root = Files::directory($this->directory.'/source');
        foreach (['run-01', 'comparison-run-01', 'meeting'] as $dir) {
            Files::directory($root.'/'.$dir);
        }
        $s = ['files' => [], 'years' => [], 'outer_root' => $root, 'trend' => $root.'/trend.jsonl', 'meeting' => $root.'/meeting',
            'counts' => [2024 => 1, 2025 => 1], 'entries' => [2024 => 5, 2025 => 5]];
        $trend = $metadata = $meetings = [];
        foreach ([2024, 2025] as $year) {
            [$row,$model] = $this->prepared($year);
            foreach (['input' => $this->raw($year), 'prediction' => app(Predictor::class)->predict($row['race'], $model->fit)] as $kind => $data) {
                $path = $s['years'][$year][$kind] = $root.'/'.$year.'-'.$kind.'.jsonl';
                JsonlArtifact::write($path, [$data]);
                foreach ([$path, $path.'.manifest.json'] as $p) {
                    $s['files'][$p] = Files::identity($p);
                }
            }
            $path = $s['years'][$year]['model'] = $root.'/'.$year.'-model.json';
            JsonlArtifact::json($path, $model->artifact);
            $s['files'][$path] = Files::identity($path);
            $this->writeOutcomes($root, $year, false);
            foreach (['labels' => 'run-01/labels-', 'contributions' => 'comparison-run-01/contributions-'] as $kind => $prefix) {
                $relative = $prefix.$year.'.jsonl';
                $this->outcomeSeals[$year][$kind] = ['relative' => $relative,
                    'body' => Files::json($root.'/'.$relative.'.manifest.json'),
                    'sidecar' => Files::identity($root.'/'.$relative.'.manifest.json')];
            }
            array_push($trend, ...$this->trend($year));
            $metadata[] = ['year' => $year, 'race_id' => $year, 'race_date' => $year.'-06-01', 'entrant_count' => 5, 'meeting_id' => $year, 'race_type_raw' => 'A級予選'];
            $meetings[$year] = ['grade' => 'F2'];
        }
        JsonlArtifact::write($s['trend'], $trend);
        JsonlArtifact::write($s['meeting'].'/metadata.jsonl', $metadata);
        JsonlArtifact::json($s['meeting'].'/meetings.json', $meetings);
        foreach ([$s['trend'], $s['trend'].'.manifest.json', $s['meeting'].'/metadata.jsonl', $s['meeting'].'/metadata.jsonl.manifest.json', $s['meeting'].'/meetings.json'] as $p) {
            $s['files'][$p] = Files::identity($p);
        }

        return $s;
    }

    private function outcomePaths(string $root, int $year): array
    {
        return [$root.'/run-01/labels-'.$year.'.jsonl', $root.'/run-01/labels-'.$year.'.jsonl.manifest.json',
            $root.'/comparison-run-01/contributions-'.$year.'.jsonl', $root.'/comparison-run-01/contributions-'.$year.'.jsonl.manifest.json'];
    }

    private function alternateOutcomeRows(int $year, string $kind): array
    {
        $labels = $this->raw($year);
        foreach ($labels['entries'] as $i => &$entry) {
            $entry['rank'] = 5 - $i;
            $entry['status'] = 'FINISHED';
        }
        unset($entry);
        if ($kind === 'labels') {
            return [$labels];
        }
        [$row, $model] = $this->prepared($year);
        $prediction = app(Predictor::class)->predict($row['race'], $model->fit);
        $comparison = app(Bt03e05MetricEvaluator::class)->raceComparison(Metrics::context($labels, $row['race']), $prediction['decision']);

        return [['year' => $year, 'race_id' => $year, 'C1-STAT01' => $comparison]];
    }

    private function writeOutcomes(string $root, int $year, bool $reverse): void
    {
        foreach ($this->outcomePaths($root, $year) as $p) {
            if (is_file($p)) {
                unlink($p);
            }
        }
        [$row,$model] = $this->prepared($year);
        $labels = $this->raw($year);
        foreach ($labels['entries'] as $i => &$entry) {
            $entry['rank'] = $reverse ? 5 - $i : $i + 1;
            $entry['status'] = 'FINISHED';
        }
        unset($entry);
        $p = app(Predictor::class)->predict($row['race'], $model->fit);
        $comparison = app(Bt03e05MetricEvaluator::class)->raceComparison(Metrics::context($labels, $row['race']), $p['decision']);
        JsonlArtifact::write($root.'/run-01/labels-'.$year.'.jsonl', [$labels]);
        JsonlArtifact::write($root.'/comparison-run-01/contributions-'.$year.'.jsonl', [['year' => $year, 'race_id' => $year, 'C1-STAT01' => $comparison]]);
    }
}
