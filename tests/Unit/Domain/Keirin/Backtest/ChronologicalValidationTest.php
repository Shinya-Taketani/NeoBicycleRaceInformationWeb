<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\ChronologicalValidation\BundleRules;
use App\Domain\Keirin\Backtest\ChronologicalValidation\FileResultSource;
use App\Domain\Keirin\Backtest\ChronologicalValidation\HistoryReader;
use App\Domain\Keirin\Backtest\ChronologicalValidation\Json;
use App\Domain\Keirin\Backtest\ChronologicalValidation\PredictionAdapter;
use App\Domain\Keirin\Backtest\ChronologicalValidation\PrimaryEvaluator;
use App\Domain\Keirin\Backtest\ChronologicalValidation\RacecardSource;
use App\Domain\Keirin\Backtest\ChronologicalValidation\ResultSource;
use App\Domain\Keirin\Backtest\ChronologicalValidation\Scope;
use App\Domain\Keirin\Backtest\ChronologicalValidation\Store;
use App\Domain\Keirin\Backtest\DTO\Bt03e03FitResultDto;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Dataset;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\HistoryAggregator;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\LoadedModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\ChronologicalFixture;

final class ChronologicalValidationTest extends TestCase
{
    #[DataProvider('knownMissing')]
    public function test_known_inability_remains_terminal_and_zero_coverage_has_null_accuracy(string $case): void
    {
        $f = new ChronologicalFixture([5]);
        $candidate = &$f->manifest['candidates'][0];
        if ($case === 'schedule') {
            $candidate['capture'] = $f->capture($candidate, time: null);
        } elseif ($case === 'evidence') {
            $candidate['capture'] = null;
        } elseif ($case === 'category') {
            $candidate['capture'] = $f->capture($candidate, type: 'Unknown');
        } else {
            $candidate['capture'] = $f->capture($candidate, score: '0');
        }
        $store = new Store($f->root.'/output');
        $out = $f->coordinator()->execute($f->manifest, $f->model(), Json::hash($f->model()->artifact), $store, $f->source($store));
        foreach (BundleRules::METHODS as $method) {
            $this->assertSame(0, $out['summary']['coverage'][$method]['coverage']);
            $this->assertNull($out['summary']['methods'][$method][PrimaryEvaluator::METRICS[0]]['rate']);
            $this->assertNotEmpty($out['summary']['no_prediction_reasons'][$method]);
        }
        foreach ($store->all() as $ref) {
            $b = $store->get($ref);
            if ($b['kind'] === 'prediction_bundle') {
                $this->assertSame('NO_PREDICTION', $b['data']['state']);
                $this->assertNotEmpty($b['data']['reasons']);
                $this->assertSame($case === 'anchor', $b['data']['input'] !== null);
                foreach (['primary', 'probabilities', 'utilities', 'decision', 'tie_audit'] as $field) {
                    $this->assertNull($b['data'][$field]);
                }
            }
        }
        $this->assertSame(1, $f->coordinator()->reproduce($store)['evaluations']);
    }

    public static function knownMissing(): iterable
    {
        foreach (['schedule', 'evidence', 'category', 'anchor'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('technicalFailures')]
    public function test_technical_failures_never_read_results_and_retain_failed_evidence(string $case): void
    {
        $f = new ChronologicalFixture([5]);
        if ($case === 'hash') {
            $f->manifest['candidates'][0]['capture']['raw']['sha256'] = str_repeat('0', 64);
        } elseif ($case === 'identity') {
            $f->manifest['candidates'][0]['entries'][0]['external_player_id'] = '99999';
        } else {
            $f->manifest['candidates'][0]['capture']['private_ref'] = $f->root.'/unknown.html';
            Json::write($f->root.'/unknown.html', '<html>Unknown source structure</html>');
            $f->manifest['candidates'][0]['capture']['raw'] = Json::seal($f->root.'/unknown.html');
        }
        $store = new Store($f->root.'/output');
        $source = $f->source($store);
        $this->reject(fn () => $f->coordinator()->execute($f->manifest, $f->model(), Json::hash($f->model()->artifact), $store, $source));
        $this->assertSame([], $source->calls);
        $candidate = $store->get($store->reference('d20260101-candidates'))['data'];
        $this->assertSame(1, $candidate['counts']['U']);
        $this->assertCount(1, $candidate['technical_unprocessed']);
        $this->assertNotEmpty(glob($store->root.'/stages/failure-*/FAILED.json'));
    }

    public static function technicalFailures(): iterable
    {
        foreach (['hash', 'identity', 'schema'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('interruptions')]
    public function test_restart_reuses_terminals_and_never_repredicts_after_opening(string $stage): void
    {
        $f = new ChronologicalFixture([5]);
        $store = new Store($f->root.'/output');
        $source = $f->source($store);
        $stop = fn (string $at) => $at === $stage ? throw new RuntimeException('Synthetic interruption') : null;
        $this->reject(fn () => $f->coordinator($stop)->execute($f->manifest, $f->model(), 'synthetic-fixed-model', $store, $source));
        $predictions = [];
        foreach (glob($store->root.'/objects/*-p[01]') as $path) {
            $predictions[] = $store->reference(basename($path));
        }
        $this->assertCount(2, $predictions);
        $model = $f->model();
        $unusable = new LoadedModel($model->layout,
            new Bt03e03FitResultDto(0.1, [], [], [], [], []), []);
        $result = $f->coordinator()->execute($f->manifest, $unusable, 'synthetic-fixed-model', $store, $source);
        $this->assertSame('OFFLINE_CONNECTION_COMPLETED', $result['status']);
        foreach ($predictions as $ref) {
            $this->assertSame($ref, $store->reference($ref['bundle_id']));
        }
        $f->manifest['run_id'] = 'another-request';
        $this->reject(fn () => $f->coordinator()->execute($f->manifest, $model, 'synthetic-fixed-model', $store, $source));
        $this->assertSame(1, $f->coordinator()->reproduce($store)['evaluations']);
    }

    public static function interruptions(): iterable
    {
        foreach (['BEFORE_LOCK', 'AFTER_RECEIPT', 'BEFORE_EVALUATION'] as $case) {
            yield $case => [$case];
        }
    }

    public function test_current_or_future_result_changes_do_not_change_first_day_input_or_predictions(): void
    {
        $f = new ChronologicalFixture([5, 7, 9, 5]);
        $one = new Store($f->root.'/one');
        $two = new Store($f->root.'/two');
        $c = $f->coordinator();
        $hash = Json::hash($f->model()->artifact);
        $c->execute($f->manifest, $f->model(), $hash, $one, $f->source($one));
        $c->execute($f->manifest, $f->model(), $hash, $two, $f->source($two, static function (array $row): array {
            foreach ($row['entries'] as &$e) {
                $e['rank'] = count($row['entries']) + 1 - $e['rank'];
            }

            return $row;
        }));
        foreach ($one->all() as $ref) {
            $kind = $one->get($ref)['kind'];
            if (str_starts_with($ref['bundle_id'], 'd20260101-') && in_array($kind, ['input_bundle', 'prediction_bundle'], true)) {
                $this->assertSame($ref, $two->reference($ref['bundle_id']));
            }
        }
    }

    #[DataProvider('dates')]
    public function test_event_release_and_strict_cutoff_boundaries(string $eventDay, string $release, string $at, bool $accepted): void
    {
        $f = new ChronologicalFixture([5]);
        $reader = $f->container->make(HistoryReader::class);
        $this->assertSame($accepted, $reader->eligible(['race' => ['race_date' => $eventDay, 'scheduled_start_at' => $at], 'logical_available_from' => $release, 'verified' => true], '2026-01-02', '2026-01-02T12:00:00+09:00'));
    }

    public static function dates(): iterable
    {
        yield ['2026-01-01', '2026-01-02', '2026-01-01T12:00:00+09:00', true];
        yield ['2026-01-02', '2026-01-02', '2026-01-02T01:00:00+09:00', false];
        yield ['2026-01-03', '2026-01-02', '2026-01-03T12:00:00+09:00', false];
        yield ['2026-01-01', '2026-01-03', '2026-01-01T12:00:00+09:00', false];
        yield ['2026-01-01', '2026-01-02', '2026-01-02T12:00:00+09:00', false];
    }

    #[DataProvider('categories')]
    public function test_supported_and_excluded_categories_never_use_entrant_count_as_gender(string $type, bool $excluded, bool $unknown): void
    {
        $f = new ChronologicalFixture([7]);
        $candidate = $f->manifest['candidates'][0];
        $candidate['capture'] = $f->capture($candidate, type: $type);
        $p = $f->container->make(RacecardSource::class)->project($candidate, new Scope(...$f->manifest['scope']));
        $this->assertSame($excluded, $p['exclusion'] !== null);
        $this->assertSame($unknown, in_array('CATEGORY_UNDETERMINED', $p['reasons'], true));
    }

    public static function categories(): iterable
    {
        yield ["L\u{7d1a}\u{4e88}\u{9078}", true, false];
        yield ['Unknown', false, true];
        yield ["A\u{7d1a}\u{4e88}\u{9078}", false, false];
        yield ["S\u{7d1a}\u{4e88}\u{9078}", false, false];
    }

    public function test_no_hidden_refit_rebin_db_or_http_dependency_and_real_scope_is_rejected(): void
    {
        $this->reject(fn () => new Scope('REAL_EXECUTION', '2026-01-01', '2026-01-02'));
        $this->reject(fn () => new Scope('LEGACY_COMPATIBILITY', '2026-01-01', '2026-01-02'));
        $this->assertSame(Json::hash((object) ['b' => 1, 'a' => [2, 1]]), Json::hash((object) ['a' => [2, 1], 'b' => 1]));
        $this->assertNotSame(Json::hash([2, 1]), Json::hash([1, 2]));
        $this->assertNotSame(Json::hash(null), Json::hash([]));
        $this->assertNotSame(Json::hash((object) []), Json::hash([]));
    }

    public function test_full_score_context_population_pair_and_legacy_year_guard_are_preserved(): void
    {
        $f = new ChronologicalFixture([5]);
        $store = new Store($f->root.'/output');
        $p = $f->container->make(RacecardSource::class)->project($f->manifest['candidates'][0], new Scope(...$f->manifest['scope']));
        $reader = $f->container->make(HistoryReader::class);
        $epoch = ['sources' => $f->manifest['history']['sources'], 'results' => []];
        $history = $reader->project($p, $epoch, $store, new Scope(...$f->manifest['scope']));
        $this->assertCount(60, $history['batch02'][1]);
        $this->assertCount(60, $history['pairs']['1:2']);
        $this->assertSame(100, $history['positions'][1001]->groups['FIELD_BASELINE']['sample_count']);
        $first = $history['batch02'][1][0];
        $this->assertSame(1.0, $history['batch03'][1][0]->subjectScorePercentile);
        $this->assertSame($first->finishStrengthPercentile - 1.0, $first->scoreExpectationResidual);
        $this->assertNotNull($history['pairs']['1:2'][0]->firstScorePercentile);
        $this->assertNotNull($history['pairs']['1:2'][0]->secondScorePercentile);
        $target = ['race_id' => 100, 'meeting_id' => 999, 'player_id' => 1, 'race_date' => '2026-01-01', 'meeting_start' => '2026-01-01', 'meeting_end' => '2026-01-03', 'input_as_of' => $p['input_as_of']];
        $aggregator = new HistoryAggregator;
        $this->reject(fn () => $aggregator->aggregate($target, $history['c1'][1]));
        $baseline = $aggregator->aggregateChronological($target, $history['c1'][1], new Scope(...$f->manifest['scope']));
        $sameMeeting = [...$history['c1'][1][0], 'race_id' => 9090, 'meeting_id' => 999];
        $outside = [...$history['c1'][1][0], 'race_id' => 9091, 'race_date' => '2025-01-01', 'scheduled_start_at' => '2025-01-01T12:00:00+09:00'];
        $this->assertSame($baseline, $aggregator->aggregateChronological($target, [...$history['c1'][1], $sameMeeting, $outside], new Scope(...$f->manifest['scope'])));
        JsonlArtifact::write($f->root.'/forbidden-model-year.jsonl', [['year' => 2026, 'race_id' => 100, 'entries' => []]]);
        $this->reject(fn () => iterator_to_array((new Dataset)->raw([$f->root.'/forbidden-model-year.jsonl'], true, true)));
    }

    public function test_valid_zero_anchors_and_outcome_contaminated_model_input(): void
    {
        $f = new ChronologicalFixture([5]);
        $f->manifest['candidates'][0]['capture'] = $f->capture($f->manifest['candidates'][0], score: '90');
        $store = new Store($f->root.'/output');
        $f->coordinator()->execute($f->manifest, $f->model(), Json::hash($f->model()->artifact), $store, $f->source($store));
        $input = null;
        foreach ($store->all() as $ref) {
            $b = $store->get($ref);
            if ($b['kind'] === 'input_bundle') {
                $input = $b['data']['model_input'];
            }
        }
        $this->assertNotNull($input);
        foreach ($input['entries'] as $e) {
            $this->assertSame(0.0, $e['anchor']);
            $this->assertSame('ZERO_VARIANCE', $e['anchor_status']);
        }
        $adapter = $f->container->make(PredictionAdapter::class);
        $input['entries'][0]['rank'] = 1;
        $this->reject(fn () => $adapter->predict($input, $f->model(), new Scope(...$f->manifest['scope'])));
    }

    public function test_input_source_end_drift_blocks_result_read_and_keeps_terminals(): void
    {
        $f = new ChronologicalFixture([5]);
        $store = new Store($f->root.'/output');
        $source = $f->source($store);
        $path = $f->manifest['candidates'][0]['capture']['private_ref'];
        $hook = static function (string $stage) use ($path): void {
            if ($stage === 'BEFORE_LOCK') {
                file_put_contents($path, 'changed-artificial-raw', FILE_APPEND);
            }
        };
        $this->reject(fn () => $f->coordinator($hook)->execute($f->manifest, $f->model(), Json::hash($f->model()->artifact), $store, $source));
        $this->assertSame([], $source->calls);
        $this->assertFalse($store->has('d20260101-predictions'));
        $this->assertCount(2, glob($store->root.'/objects/*-p[01]'));
        $this->assertNotEmpty(glob($store->root.'/stages/failure-*/FAILED.json'));
    }

    public function test_real_file_result_source_cannot_be_opened_with_an_unverified_receipt(): void
    {
        $f = new ChronologicalFixture([5]);
        $store = new Store($f->root.'/output');
        $source = new FileResultSource('/does-not-exist-and-must-not-be-inspected', new Scope(...$f->manifest['scope']), $store);
        $this->reject(fn () => iterator_to_array($source->read('2026-01-01', ['bundle_id' => 'unverified-receipt', 'manifest_sha256' => str_repeat('0', 64)])));
        $this->assertSame([], $store->all());
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

    public function test_two_days_real_calculators_fixed_predictor_day_barrier_and_saved_evaluation(): void
    {
        $f = new ChronologicalFixture;
        $store = new Store($f->root.'/output');
        $spy = new class($f, $store) implements ResultSource
        {
            public array $calls = [];

            public function __construct(private ChronologicalFixture $fixture, private Store $store) {}

            public function read(string $day, array $receipt): iterable
            {
                TestCase::assertSame('RESULTS_OPENED', $this->store->get($receipt)['data']['state']);
                $this->calls[] = $day;
                foreach ($this->fixture->results[$day] as $r) {
                    yield [...$r, 'source_seal' => ['bytes' => strlen(Json::encode($r)), 'sha256' => Json::hash($r)]];
                }
            }
        };
        $coordinator = $f->coordinator();
        $result = $coordinator->execute($f->manifest, $f->model(), Json::hash($f->model()->artifact), $store, $spy);
        $this->assertSame('OFFLINE_CONNECTION_COMPLETED', $result['status']);
        $this->assertSame(['2026-01-01', '2026-01-02'], $spy->calls);
        $this->assertSame(0, $result['fit_count']);
        $this->assertSame(5, $result['summary']['coverage'][BundleRules::METHODS[0]]['T']);
        $observed = $connectedSignals = [];
        foreach ($store->all() as $ref) {
            $b = $store->get($ref);
            $observed[$b['kind']] = true;
            if ($b['kind'] === 'input_bundle') {
                foreach ($b['data']['stat_sources'] as $audit) {
                    $this->assertCount(13, $audit);
                    $this->assertNull($audit['STAT-01']['source_run_id']);
                    foreach ($audit as $stat => $row) {
                        if (($row['operational_eligible'] ?? false) === true) {
                            $connectedSignals[$stat] = true;
                        }
                    }
                }
                foreach ($b['data']['model_input']['entries'] as $e) {
                    $this->assertCount(12, $e['signals']);
                    $this->assertCount(4, $e['history']);
                    $this->assertNotEmpty(array_filter($e['signals'], fn ($v): bool => $v !== null));
                }
            }
        }
        $this->assertCount(7, $observed);
        $this->assertCount(12, $connectedSignals);
        $this->assertSame(5, $coordinator->reproduce($store)['evaluations']);
        $this->assertSame(0, $coordinator->reproduce($store)['inference_count']);
    }
}
