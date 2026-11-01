<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\ChronologicalValidation\BundleRules;
use App\Domain\Keirin\Backtest\ChronologicalValidation\Json;
use App\Domain\Keirin\Backtest\ChronologicalValidation\PrimaryEvaluator;
use App\Domain\Keirin\Backtest\ChronologicalValidation\RunVerifier;
use App\Domain\Keirin\Backtest\ChronologicalValidation\Store;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\ChronologicalFixture;

final class ChronologicalStoreAndEvaluationTest extends TestCase
{
    #[DataProvider('inventoryFailures')]
    public function test_exact_inventory_corruption_and_no_repair_of_failed_evidence(string $failure): void
    {
        $f = new ChronologicalFixture([5]);
        $store = new Store($f->root.'/store');
        $data = $this->epoch();
        $data['object_types'] = [(object) [], (object) ['0' => 1, '1' => 2]];
        $ref = $store->put('epoch', 'history_epoch', $data);
        $this->assertSame($ref, $store->put('epoch', 'history_epoch', $data));
        $this->assertSame(Json::hash($data), Json::hash($store->get($ref)['data']));
        $path = $store->root.'/objects/epoch/';
        if ($failure === 'extra') {
            Json::write($path.'extra.json', '{}');
        } elseif ($failure === 'failed') {
            Json::write($path.'FAILED.json', '{}');
        } elseif ($failure === 'directory') {
            mkdir($path.'unexpected');
        } elseif ($failure === 'symlink') {
            symlink($path.'data.json', $path.'extra.json');
        } elseif ($failure === 'missing') {
            $this->assertTrue(rename($path.'data.json', $f->root.'/retained-artificial-data.json'));
        } else {
            file_put_contents($path.'data.json', "\n", FILE_APPEND);
        }
        $this->reject(fn () => $store->get($ref));
        $this->reject(fn () => $store->put('epoch', 'history_epoch', $data));
        $this->assertDirectoryExists($path);
    }

    public static function inventoryFailures(): iterable
    {
        foreach (['extra', 'failed', 'directory', 'symlink', 'missing', 'sha'] as $case) {
            yield $case => [$case];
        }
    }

    public function test_conflicts_missing_fields_forward_refs_and_protected_paths_fail_closed(): void
    {
        $f = new ChronologicalFixture([5]);
        $s = new Store($f->root.'/store');
        $ref = $s->put('epoch', 'history_epoch', $this->epoch());
        $this->reject(fn () => $s->put('epoch', 'history_epoch', [...$this->epoch(), 'logical_available_from' => '2026-01-02']));
        $this->assertSame($ref, $s->reference('epoch'));
        $this->reject(fn () => $s->put('missing-fields', 'history_epoch', ['logical_available_from' => '2026-01-01']));
        $this->reject(fn () => $s->put('cycle', 'history_epoch', [...$this->epoch(), 'parent_epoch' => ['bundle_id' => 'cycle', 'manifest_sha256' => str_repeat('1', 64)]]));
        foreach (['', '.', '..', 'relative', '/tmp', '/home/shinya', dirname(__DIR__, 5)] as $path) {
            $this->reject(fn () => new Store($path));
        }
        $this->reject(fn () => new Store($f->root));
        $this->assertFileExists($f->root.'/CREATED.json');
        $this->reject(fn () => (new RunVerifier)->verify($s));
    }

    public function test_persistent_run_lock_blocks_another_php_process_and_is_not_deleted(): void
    {
        $f = new ChronologicalFixture([5]);
        $store = new Store($f->root.'/store');
        $store->locked('run', function () use ($store): void {
            $code = 'require '.var_export(dirname(__DIR__, 5).'/vendor/autoload.php', true).';'
                .'try {$s=new \\App\\Domain\\Keirin\\Backtest\\ChronologicalValidation\\Store('.var_export($store->root, true).');'
                .'$s->locked("run",fn()=>null);exit(2);}catch(\\RuntimeException $e){echo "BLOCKED:".ini_get("memory_limit");exit(0);}';
            $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M', '-r', $code]);
            $process->setTimeout(10);
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertSame('BLOCKED:128M', $process->getOutput());
        });
        $this->assertFileExists($store->root.'/locks/run');
        $this->assertSame('reused', $store->locked('run', fn (): string => 'reused'));
    }

    public function test_final_index_cannot_hide_missing_result_evaluation_or_next_epoch(): void
    {
        $f = new ChronologicalFixture([5]);
        $s = new Store($f->root.'/store');
        $this->reject(fn () => $f->coordinator(fn (string $stage) => $stage === 'AFTER_RECEIPT' ? throw new RuntimeException('Artificial interruption') : null)
            ->execute($f->manifest, $f->model(), Json::hash($f->model()->artifact), $s, $f->source($s)));
        $header = $s->get($s->reference('run'))['data'];
        $s->put('artificial-premature-index', 'validation_run', [...$header, 'event' => 'RUN_INDEX', 'header' => $s->reference('run'), 'days' => ['2026-01-01' => $s->reference('d20260101-predictions')]]);
        $this->reject(fn () => (new RunVerifier)->verify($s));
        $this->assertFileExists($s->root.'/objects/d20260101-opened/LOCKED');
    }

    #[DataProvider('bindingFailures')]
    public function test_terminal_reference_and_receipt_failures_are_rejected_without_changing_originals(string $failure): void
    {
        $f = new ChronologicalFixture([5]);
        $store = new Store($f->root.'/store');
        $f->coordinator()->execute($f->manifest, $f->model(), Json::hash($f->model()->artifact), $store, $f->source($store));
        $ref = $store->reference('d20260101-predictions');
        $lock = $store->get($ref)['data'];
        $key = array_key_first($lock['predictions']);
        if (in_array($failure, ['race', 'null_input', 'probability'], true)) {
            $row = $store->get($lock['predictions'][$key][BundleRules::METHODS[0]])['data'];
            if ($failure === 'race') {
                $row['external_race_key'] = 'another-race';
            } elseif ($failure === 'null_input') {
                $row['input'] = null;
            } else {
                $row['probabilities']['entries'][0]['position_1_probability'] = 1.1;
            }
            $this->reject(fn () => $store->put('bad-prediction', 'prediction_bundle', $row));
        } else {
            if ($failure === 'null_ref') {
                $lock['predictions'][$key][BundleRules::METHODS[0]] = null;
            } elseif ($failure === 'method') {
                $lock['predictions'][$key][BundleRules::METHODS[0]] = $lock['predictions'][$key][BundleRules::METHODS[1]];
            } elseif ($failure === 'locked_at') {
                $lock['locked_at'] = null;
            } else {
                $lock['state'] = 'RESULTS_OPENED';
                $lock['day_lock'] = null;
                $lock['opened_at'] = null;
            }
            $this->reject(fn () => $store->put('bad-lock', 'day_manifest', $lock));
        }
        $this->assertSame($ref, $store->reference('d20260101-predictions'));
        $this->assertSame('VERIFIED', (new RunVerifier)->verify($store)['status']);
    }

    public static function bindingFailures(): iterable
    {
        foreach (['race', 'null_input', 'probability', 'null_ref', 'method', 'locked_at', 'receipt'] as $case) {
            yield $case => [$case];
        }
    }

    public function test_resealed_evaluation_cannot_replace_saved_primary_metrics(): void
    {
        $f = new ChronologicalFixture([5]);
        $s = new Store($f->root.'/store');
        $f->coordinator()->execute($f->manifest, $f->model(), Json::hash($f->model()->artifact), $s, $f->source($s));
        $path = glob($s->root.'/objects/*-evaluation')[0];
        $ref = $s->reference(basename($path));
        $evaluation = $s->get($ref)['data'];
        $evaluation['metrics'][BundleRules::METHODS[0]][PrimaryEvaluator::METRICS[0]]['rate'] = 0.25;
        $this->reject(fn () => $s->put('artificial-wrong-evaluation', 'evaluation_bundle', $evaluation));
        $this->assertSame($ref, $s->reference($ref['bundle_id']));
    }

    public function test_primary_common_denominator_hand_examples_and_unequal_daily_counts(): void
    {
        $e = new PrimaryEvaluator;
        $rows = [];
        foreach ([[1, 2, 3, 4, 5], [1, 3, 2, 4, 5], [4, 2, 3, 1, 5]] as $ranks) {
            $rows[] = $e->evaluate($this->predictions(), $this->officialResult($ranks));
        }
        $sum = $e->combine($rows);
        foreach (BundleRules::METHODS as $method) {
            $this->assertSame([1, 2, 2, 2, 2], array_column($sum['methods'][$method], 'numerator'));
            foreach ($sum['methods'][$method] as $m) {
                $this->assertSame(3, $m['denominator']);
            }
            $this->assertSame(1 / 3, $sum['methods'][$method][PrimaryEvaluator::METRICS[0]]['rate']);
        }
        $this->assertSame(3, $sum['E_pair']);
        $oneDay = $e->combine([$rows[0]]);
        $otherDay = $e->combine([$rows[1], $rows[2]]);
        $this->assertNotSame(($oneDay['methods'][BundleRules::METHODS[0]][PrimaryEvaluator::METRICS[0]]['rate'] + $otherDay['methods'][BundleRules::METHODS[0]][PrimaryEvaluator::METRICS[0]]['rate']) / 2,
            $sum['methods'][BundleRules::METHODS[0]][PrimaryEvaluator::METRICS[0]]['rate']);
    }

    public function test_no_targets_has_null_rates_with_zero_denominator_reasons(): void
    {
        $summary = (new PrimaryEvaluator)->combine([]);
        foreach (BundleRules::METHODS as $method) {
            $this->assertNull($summary['coverage'][$method]['coverage']);
            foreach ($summary['methods'][$method] as $metric) {
                $this->assertSame(0, $metric['denominator']);
                $this->assertNull($metric['rate']);
                $this->assertSame('UNEVALUABLE_ZERO_DENOMINATOR', $metric['reason']);
            }
        }
    }

    #[DataProvider('resultCases')]
    public function test_ties_abnormal_cancellation_unknown_inability_and_partial_pair_have_explicit_denominators(string $case, bool $eligible, bool $final): void
    {
        $e = new PrimaryEvaluator;
        $result = $this->officialResult([1, 2, 3, 4, 5]);
        $p = $this->predictions();
        if ($case === 'top_tie') {
            $result['entries'][0]['status'] = $result['entries'][1]['status'] = 'TIED';
            $result['entries'][1]['rank'] = 1;
        } elseif ($case === 'lower_tie') {
            $result['entries'][3]['status'] = $result['entries'][4]['status'] = 'TIED';
            $result['entries'][4]['rank'] = 4;
        } elseif ($case === 'abnormal') {
            $result['entries'][4]['status'] = 'CRASHED';
            $result['entries'][4]['rank'] = null;
        } elseif ($case === 'cancelled') {
            $result['official_status'] = 'CANCELLED';
            $result['entries'] = [];
        } elseif ($case === 'identity') {
            $result['identity_audit']['verified'] = false;
        } elseif ($case === 'provisional') {
            $result['official_status'] = 'PROVISIONAL';
        } elseif ($case === 'missing') {
            $result = null;
        } else {
            $p[BundleRules::METHODS[1]] = ['state' => 'NO_PREDICTION', 'primary' => null, 'reasons' => BundleRules::reasons(['MISSING_SCHEDULE'])];
        }
        $c = $e->evaluate($p, $result);
        $this->assertSame((int) $eligible, $c['methods'][BundleRules::METHODS[0]][PrimaryEvaluator::METRICS[0]]['denominator']);
        $this->assertSame($final, $c['is_final']);
        $this->assertSame($case === 'one_method' ? 0 : (int) $eligible, $c['E_pair']);
        if ($case === 'one_method') {
            $this->assertSame(1, $c['coverage'][BundleRules::METHODS[1]]['N']);
            $this->assertNull($c['methods'][BundleRules::METHODS[1]][PrimaryEvaluator::METRICS[0]]['rate']);
        }
    }

    public static function resultCases(): iterable
    {
        yield ['top_tie', false, true];
        yield ['lower_tie', true, true];
        yield ['abnormal', true, true];
        yield ['cancelled', false, true];
        yield ['identity', false, false];
        yield ['provisional', false, false];
        yield ['missing', false, false];
        yield ['one_method', true, true];
    }

    public function test_correction_appends_new_result_evaluation_and_forward_epoch_without_replacing_prior_predictions(): void
    {
        $f = new ChronologicalFixture([5, 5, 5, 5]);
        $store = new Store($f->root.'/store');
        $modelHash = Json::hash($f->model()->artifact);
        $this->reject(fn () => $f->coordinator(fn (string $stage) => $stage === 'AFTER_DAY' ? throw new RuntimeException('Stop after day one') : null)
            ->execute($f->manifest, $f->model(), $modelHash, $store, $f->source($store)));
        $predictionLock = $store->reference('d20260101-predictions');
        $old = glob($store->root.'/objects/*-result-v1')[0];
        $oldRef = $store->reference(basename($old));
        $parent = $store->get($oldRef)['data'];
        $candidate = array_values(array_filter($f->results['2026-01-01'], fn (array $r): bool => $r['external_race_key'] === $parent['external_race_key']))[0];
        $candidate['result_version'] = 2;
        $candidate['official_status'] = 'CORRECTED';
        $candidate['source_id'] = 'synthetic-corrected';
        $candidate['entries'][0]['rank'] = 2;
        $candidate['entries'][1]['rank'] = 1;
        $candidate['source_seal'] = ['bytes' => strlen(Json::encode($candidate)), 'sha256' => Json::hash($candidate)];
        $new = $f->coordinator()->correct($store, $oldRef, $candidate, $store->reference('d20260101-next-epoch'), '2026-01-02');
        $this->assertSame($oldRef, $store->reference($oldRef['bundle_id']));
        $this->assertSame($predictionLock, $store->reference('d20260101-predictions'));
        $this->assertSame($oldRef, $store->get($new['result'])['data']['parent_result']);
        $f->coordinator()->execute($f->manifest, $f->model(), $modelHash, $store, $f->source($store));
        $this->assertSame($new['epoch'], $store->get($store->reference('d20260102-candidates'))['data']['history_epoch']);
        $this->assertSame(5, $f->coordinator()->reproduce($store)['evaluations']);
        $this->reject(fn () => $f->coordinator()->correct($store, $oldRef, $candidate, $store->reference('d20260101-next-epoch'), '2026-01-02'));
    }

    private function predictions(): array
    {
        return array_fill_keys(BundleRules::METHODS, ['state' => 'PREDICTED', 'primary' => [1, 2, 3], 'reasons' => []]);
    }

    private function officialResult(array $ranks): array
    {
        return ['identity_audit' => ['verified' => true], 'official_status' => 'CONFIRMED',
            'entries' => array_map(fn (int $rank, int $i): array => ['bike' => $i + 1, 'rank' => $rank, 'status' => 'FINISHED'], $ranks, array_keys($ranks))];
    }

    private function epoch(): array
    {
        return ['epoch_id' => 'epoch', 'parent_epoch' => null, 'logical_available_from' => '2026-01-01', 'history_event_from' => '2022-01-01',
            'history_event_end_exclusive' => '2026-01-01T00:00:00+09:00', 'sources' => [], 'results' => [], 'inputs' => [], 'release_order' => [], 'actual_capture_order' => [],
            'publication_times' => [], 'publication_status' => 'UNKNOWN', 'exclusions' => [], 'created_at' => '2026-11-01T10:00:00+09:00'];
    }

    private function reject(callable $work): void
    {
        try {
            $work();
            $this->fail('Expected explicit rejection.');
        } catch (RuntimeException $error) {
            $this->assertNotSame('', $error->getMessage());
        }
    }
}
