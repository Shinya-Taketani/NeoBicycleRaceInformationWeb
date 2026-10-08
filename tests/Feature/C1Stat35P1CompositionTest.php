<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e06WinnerConditionedDecoder;
use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder\Reader as LabelReader;
use App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder\Sources as BaselineSources;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35P1Composition\Contract;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35P1Composition\Evaluation;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35P1Composition\Experiment;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35P1Composition\Reader;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35P1Composition\Sources;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Optimizer;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Trainer;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\C1MarginalP23Fixture as MathFixture;
use Tests\Support\C1Stat35P1CompositionFixture as Fixture;
use Tests\Support\MemoryLimitedTestProcess;
use Tests\TestCase;

class C1Stat35P1CompositionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/composition-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        DB::shouldReceive('connection')->never();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    #[DataProvider('sizes')]
    public function test_calculator_matches_frozen_scorer_exactly_with_gaps_ties_extremes(int $n, bool $tie, bool $extreme): void
    {
        $race = MathFixture::race(n: $n);
        $race['entries'] = array_reverse($race['entries']);
        $u = $extreme ? array_fill_keys(['POSITION_1', 'POSITION_2', 'POSITION_3'], array_slice([100.0, -100.0, 50.0, -50.0, 0.0, 3.0, 1.0, -3.0, -1.0], 0, $n)) : null;
        $saved = MathFixture::prediction($race, $tie, $u);
        $actual = app(Reader::class)->forward($saved['probabilities']);
        $this->assertSame($saved['probabilities'], $actual);
        foreach ($actual['probability_invariants'] as $sum) {
            $this->assertEqualsWithDelta(1.0, $sum, 1e-12);
        }
        $this->assertCount(3, array_unique($actual['map_ordered_top3']));
        $this->assertSame($saved['decision']['map_top3_set'], app(Bt03e06WinnerConditionedDecoder::class)->decode($actual)['map_top3_set']);
    }

    public static function sizes(): array
    {
        return [[5, false, false], [7, false, false], [9, false, false], [5, true, false], [7, true, false], [9, true, false], [5, false, true], [9, false, true]];
    }

    public function test_composition_column_independence_coherence_and_conditional_primary(): void
    {
        $input = MathFixture::race();
        $c1 = MathFixture::prediction($input);
        $c2 = Fixture::donor($input);
        $reader = app(Reader::class);
        $row = $reader->row($input, $c1, $c2);
        $this->assertFalse($row['winner_same']);
        $this->assertSame($c2['decision']['primary_position_1_bike'], $row['candidate']['primary_position_1_bike']);
        $this->assertNotSame($c1['decision']['q2_given_winner'], $row['candidate']['q2_given_winner']);
        $this->assertNotSame($c1['probabilities']['entries'][0]['position_2_probability'], $row['probabilities']['entries'][0]['position_2_probability']);
        $decoded = app(Bt03e06WinnerConditionedDecoder::class)->decode($row['probabilities']);
        foreach (['map_ordered_top3', 'map_top3_set', 'top2_marginal_bikes', 'top3_marginal_bikes', 'expected_ndcg_top3', 'q2_given_winner', 'q3_given_winner'] as $key) {
            $this->assertSame($decoded[$key], $row['candidate'][$key]);
        }
        $this->assertArrayNotHasKey('reconstruction_verified', $row['candidate']);
        $this->assertNull($row['model_expected_gain']);
        foreach ($row['probabilities']['entries'] as $i => $entry) {
            $this->assertSame($c2['probabilities']['entries'][$i]['position_1_probability'], $entry['position_1_probability']);
            $this->assertSame($c2['probabilities']['entries'][$i]['position_1_log_probability'], $entry['position_1_log_probability']);
            $c2['probabilities']['entries'][$i]['utilities']['POSITION_2'] += 20.0;
            $c2['probabilities']['entries'][$i]['utilities']['POSITION_3'] -= 20.0;
            $c1['probabilities']['entries'][$i]['utilities']['POSITION_1'] -= 10.0;
        }
        $this->assertSame($row['probabilities'], $reader->compose($c1['probabilities'], $c2['probabilities']));
        $same = $reader->row($input, MathFixture::prediction($input), MathFixture::prediction($input));
        $this->assertTrue($same['winner_same']);
        foreach ([1, 2, 3] as $p) {
            $this->assertSame($same['baseline']['primary_position_'.$p.'_bike'], $same['candidate']['primary_position_'.$p.'_bike']);
        }
    }

    #[DataProvider('badPredictions')]
    public function test_parent_identity_values_order_and_unapproved_fields_fail_closed(string $kind): void
    {
        $input = MathFixture::race();
        $c1 = MathFixture::prediction($input);
        $c2 = Fixture::donor($input);
        match ($kind) {
            'year' => $c2['probabilities']['year'] = 2025,
            'race' => $c2['probabilities']['race_id']++,
            'id' => $c2['probabilities']['entries'][0]['id']++,
            'bike' => $c2['probabilities']['entries'][0]['bike'] = 9,
            'raw' => $c2['probabilities']['entries'][0]['raw'] = 99.0,
            'anchor' => $c2['probabilities']['entries'][0]['anchor'] = 0.5,
            'rank' => $c2['probabilities']['entries'][0]['stat01_rank'] = 2,
            'order' => $c2['probabilities']['entries'] = array_reverse($c2['probabilities']['entries']),
            'duplicate' => $c2['probabilities']['entries'][1] = $c2['probabilities']['entries'][0],
            'missing' => array_pop($c2['probabilities']['entries']),
            'extra' => $c2['probabilities']['entries'][] = $c2['probabilities']['entries'][0],
            'nan' => $c2['probabilities']['entries'][0]['utilities']['POSITION_1'] = NAN,
            'type' => $c2['probabilities']['entries'][0]['utilities']['POSITION_1'] = '1',
            'outcome' => $c2['probabilities']['entries'][0]['status'] = 'FINISHED',
            'math' => $c2['probabilities']['entries'][0]['utilities']['POSITION_1'] += 0.001,
        };
        $this->expectException(\Throwable::class);
        app(Reader::class)->row($input, $c1, $c2);
    }

    public static function badPredictions(): array
    {
        return array_map(fn ($s) => [$s], ['year', 'race', 'id', 'bike', 'raw', 'anchor', 'rank', 'order', 'duplicate', 'missing', 'extra', 'nan', 'type', 'outcome', 'math']);
    }

    public function test_independent_execute_preserves_cohort_reproduction_and_original_gate(): void
    {
        $b = Fixture::make($this->directory.'/bundle');
        $this->app->instance(Sources::class, $b['sources']);
        foreach ([Optimizer::class, Trainer::class, EffectBinBuilder::class] as $class) {
            $this->app->bind($class, fn () => throw new RuntimeException('Learning forbidden.'));
        }
        $result = app(Experiment::class)->execute($b['input'], $b['baseline'], $b['compare'], $this->directory.'/result');
        $this->assertSame(0, $result['training_count']);
        $repro = Files::json($this->directory.'/result/reproduction.json');
        $this->assertTrue($repro['identical']);
        $this->assertSame(count($repro['files']), $repro['semantic_file_count']);
        $this->assertSame(2, $repro['independent_source_reread_runs']);
        $comparison = Files::json($this->directory.'/result/comparison.json');
        $this->assertSame(app(\App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Evaluation::class)->incrementalGate(
            $comparison['outer']['CANDIDATE-C1'], $comparison['intervals']['CANDIDATE-C1'], true), $result['incremental_gate']);
        foreach ([2024, 2025] as $year) {
            $this->assertSame(2, $comparison['invariants'][$year]['races']);
            $this->assertSame(14, $comparison['invariants'][$year]['entries']);
            $changes = $comparison['winner_groups'][$year]['winner_changed'];
            $this->assertSame(array_sum($changes['Hit3_position_deltas']), $changes['Hit3_total_delta']);
            $records = iterator_to_array(JsonlArtifact::read($this->directory.'/result/run-01/contributions-'.$year.'.jsonl'));
            foreach (Bt03e05MetricEvaluator::METRIC_CODES as $metric) {
                $sum = array_sum(array_map(fn ($r) => $r['comparisons']['CANDIDATE-C1']['candidate'][$metric]['numerator'], $records));
                $this->assertSame($sum, $comparison['outer']['CANDIDATE-C1'][$year]['candidate_numerators'][$metric]);
            }
            $events = Files::json($this->directory.'/result/run-01/access-order.json');
            $this->assertSame('BOTH_YEARS_SEALS_CONTROLS_INVARIANTS_VERIFIED', $events[2]['event']);
            $this->assertSame('OUTCOME_RELEASE_AUTHORIZED', $events[3]['event']);
        }
        BaselineSources::verify($b['source'], true);
        Http::assertNothingSent();
        $this->expectExceptionMessage('Output must be new');
        app(Experiment::class)->execute($b['input'], $b['baseline'], $b['compare'], $this->directory.'/result');
    }

    public function test_outcome_mutation_changes_only_evaluation_not_predictions_or_decisions(): void
    {
        foreach ([null, 2024, 2025] as $i => $year) {
            $b = Fixture::make($this->directory.'/bundle-'.$i, $year);
            app(Experiment::class)->run($b['source'], Files::directory($this->directory.'/run-'.$i));
        }
        foreach ([1, 2] as $i) {
            foreach ([2024, 2025] as $year) {
                foreach (['predictions', 'decisions'] as $kind) {
                    $a = iterator_to_array(JsonlArtifact::read($this->directory.'/run-0/'.$kind.'-'.$year.'.jsonl'));
                    $b = iterator_to_array(JsonlArtifact::read($this->directory.'/run-'.$i.'/'.$kind.'-'.$year.'.jsonl'));
                    foreach ($a as $j => &$row) {
                        foreach (['C1', 'C2'] as $parent) {
                            $this->assertSame($row['provenance']['parents'][$parent]['model_seal'], $b[$j]['provenance']['parents'][$parent]['model_seal']);
                            $this->assertSame($row['provenance']['parents'][$parent]['prediction_seal'], $b[$j]['provenance']['parents'][$parent]['prediction_seal']);
                        }
                        // Separate fixture roots are provenance, not changed probability/decision mathematics.
                        unset($row['provenance'], $b[$j]['provenance']);
                    }
                    unset($row);
                    $this->assertSame($a, $b);
                }
            }
            $this->assertNotSame(Files::json($this->directory.'/run-0/evaluation.json')['outer'], Files::json($this->directory.'/run-'.$i.'/evaluation.json')['outer']);
        }
    }

    public function test_labels_require_both_seals_and_zero_denominator_is_not_evaluated(): void
    {
        $b = Fixture::make($this->directory.'/bundle');
        $this->expectExceptionMessage('Labels forbidden before both prediction seals');
        iterator_to_array(app(LabelReader::class)->labelled($b['source'], 2024, []));
    }

    public function test_source_mutation_leaves_failed_evidence_and_no_complete(): void
    {
        $b = Fixture::make($this->directory.'/bundle');
        $this->app->instance(Sources::class, $b['sources']);
        $process = new Process([PHP_BINARY, '-r', 'for($i=0;$i<5000;$i++){clearstatcache();if(is_file($argv[1])){file_put_contents($argv[2]," ",FILE_APPEND);exit(0);}usleep(1000);}exit(1);',
            $this->directory.'/failed/frozen-contract.json', $b['source']['paths'][2024]['model']], timeout: 10);
        $process->start();
        try {
            app(Experiment::class)->execute($b['input'], $b['baseline'], $b['compare'], $this->directory.'/failed');
            $this->fail('Drift accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('hash/size mismatch', $e->getMessage());
        } finally {
            $this->assertSame(0, $process->wait());
        }
        $this->assertFileDoesNotExist($this->directory.'/failed/COMPLETE.json');
        $this->assertSame('NOT_EVALUATED', Files::json($this->directory.'/failed/FAILED.json')['status']);
        $this->assertNull(Files::json($this->directory.'/failed/FAILED.json')['performance']);
    }

    #[DataProvider('badSources')]
    public function test_unaccepted_source_contract_version_membership_and_seals_are_rejected(string $kind): void
    {
        $b = Fixture::make($this->directory.'/bundle');
        $path = $b['compare'].'/manifest.json';
        $m = Files::json($path);
        match ($kind) {
            'contract' => $m['contract']['model_version'] = 'UNKNOWN',
            'status' => $m['status'] = 'ADOPTED',
            'input' => $m['source']['paths'][2024]['input'] = $m['source']['paths'][2025]['input'],
            'model' => $m['source']['paths'][2024]['model'] = $m['source']['paths'][2025]['model'],
            'count' => $m['source']['expected_entries'][2024]++,
            'missing' => $m['runs']['run-01'] = [],
            'seal' => $m['runs']['run-01']['C2-fit-2024/predictions.jsonl']['sha256'] = str_repeat('0', 64),
        };
        unlink($path);
        unlink($b['compare'].'/COMPLETE.json');
        JsonlArtifact::json($path, $m);
        $seal = Files::identity($path);
        JsonlArtifact::json($b['compare'].'/COMPLETE.json', $seal);
        $this->expectException(RuntimeException::class);
        (new Sources($b['baseline_sources'], $seal))->open($b['input'], $b['baseline'], $b['compare']);
    }

    public static function badSources(): array
    {
        return [['contract'], ['status'], ['input'], ['model'], ['count'], ['missing'], ['seal']];
    }

    #[DataProvider('streamFailures')]
    public function test_missing_extra_duplicate_and_cross_year_identity_failures_are_not_dropped(string $kind): void
    {
        $b = Fixture::make($this->directory.'/bundle');
        $source = $b['source'];
        $path = $source['paths'][2024]['c2_prediction'];
        $rows = iterator_to_array(JsonlArtifact::read($path));
        match ($kind) {
            'missing' => array_pop($rows),
            'extra' => $rows[] = $rows[0],
            'duplicate' => $rows[1] = $rows[0],
            'order' => $rows = array_reverse($rows),
            'cross_year' => $rows[0]['probabilities']['year'] = 2025,
        };
        unlink($path);
        unlink($path.'.manifest.json');
        JsonlArtifact::write($path, $rows);
        $seen = Reader::identities();
        $audit = [];
        $this->expectException(\Throwable::class);
        try {
            iterator_to_array(app(Reader::class)->rows($source, 2024, $seen, $audit));
        } finally {
            $seen->rollBack();
        }
    }

    public static function streamFailures(): array
    {
        return [['missing'], ['extra'], ['duplicate'], ['order'], ['cross_year']];
    }

    public function test_zero_denominator_stays_not_evaluated(): void
    {
        $b = Fixture::make($this->directory.'/bundle');
        $source = $b['source'];
        foreach ([2024, 2025] as $year) {
            $p = $source['paths'][$year]['labels'];
            $rows = iterator_to_array(JsonlArtifact::read($p));
            foreach ($rows as &$row) {
                foreach ($row['entries'] as &$entry) {
                    $entry['rank'] = null;
                    $entry['status'] = 'DID_NOT_START';
                }
                unset($entry);
            }
            unset($row);
            unlink($p);
            unlink($p.'.manifest.json');
            JsonlArtifact::write($p, $rows);
            $source['outcome_seals'][$p] = Files::identity($p);
            $source['seals'][$p.'.manifest.json'] = Files::identity($p.'.manifest.json');
        }
        $this->expectExceptionMessage('Zero evaluation denominator: NOT_EVALUATED');
        app(Experiment::class)->run($source, Files::directory($this->directory.'/zero'));
    }

    public function test_strict_and_inclusive_gate_boundaries_remain_exact(): void
    {
        $old = app(\App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Evaluation::class);
        foreach ([-0.00150001, -0.0015, -0.00149999, 0.0, 1e-8] as $lower) {
            foreach ([-0.00300001, -0.003, 0.0, 0.01] as $delta) {
                foreach ([false, true] as $integrity) {
                    $ci = array_fill_keys(Bt03e05MetricEvaluator::METRIC_CODES, ['ci_lower' => $lower, 'ci_upper' => 0.1]);
                    $outer = array_fill_keys([2024, 2025], ['delta' => array_fill_keys(Bt03e05MetricEvaluator::METRIC_CODES, $delta),
                        'race_count' => 1, 'tie_diagnostics' => ['primary_score_tied_races' => 0, 'baseline_exact_score_tied_races' => 0, 'technical_tiebreak_races' => 0]]);
                    $new = app(Evaluation::class)->gates(['outer' => ['CANDIDATE-C1' => $outer, 'CANDIDATE-STAT01' => $outer],
                        'intervals' => ['CANDIDATE-C1' => $ci, 'CANDIDATE-STAT01' => $ci]], $integrity);
                    $this->assertSame($old->incrementalGate($outer, $ci, $integrity), $new['incremental_gate']);
                }
            }
        }
    }

    public function test_plan_and_2026_guard(): void
    {
        $this->artisan('keirin:c1:stat35-p1-composition plan')->assertSuccessful();
        $this->assertSame('C2_P1_C1_P23_COMPOSITION', Contract::plan()['candidate']);
        $this->expectExceptionMessage('Forbidden dataset year');
        app(Reader::class)->forward(MathFixture::prediction(MathFixture::race(2026))['probabilities']);
    }

    public function test_large_stream_in_independent_128m_process(): void
    {
        if (! getenv('COMPOSITION_MEMORY_CHILD')) {
            $p = new Process([PHP_BINARY, '-d', 'memory_limit=128M', base_path('vendor/bin/phpunit'), '--do-not-cache-result',
                '--filter', 'test_large_stream_in_independent_128m_process', __FILE__], base_path(),
                MemoryLimitedTestProcess::environment(['COMPOSITION_MEMORY_CHILD' => '1', 'COMPOSITION_PARENT' => (string) getmypid()]), timeout: 300);
            $p->run();
            $this->assertSame(0, $p->getExitCode(), $p->getOutput().$p->getErrorOutput());
            $this->assertSame(1, preg_match('/COMPOSITION_128M_OK peak=(\d+) bytes=(\d+) races=(\d+)/', $p->getOutput(), $m));
            $this->assertLessThan(128 * 1024 * 1024, (int) $m[1]);
            $this->assertGreaterThan(100 * 1024 * 1024, (int) $m[2]);
            $this->assertSame(18000, (int) $m[3]);

            return;
        }
        $this->assertSame(128 * 1024 * 1024, ini_parse_quantity(ini_get('memory_limit')));
        $this->assertNotSame((string) getmypid(), getenv('COMPOSITION_PARENT'));
        $races = static function () {
            for ($i = 1; $i <= 18000; $i++) {
                yield MathFixture::race(id: $i);
            }
        };
        $input = $this->directory.'/input.jsonl';
        $saved = $this->directory.'/saved.jsonl';
        JsonlArtifact::write($input, $races());
        JsonlArtifact::write($saved, (static function () use ($races) {
            foreach ($races() as $r) {
                yield MathFixture::prediction($r);
            }
        })());
        $source = ['paths' => [2024 => ['input' => $input, 'prediction' => $saved, 'c2_prediction' => $saved, 'model' => 'C1', 'c2_model' => 'C2']],
            'seals' => [$input => Files::identity($input), $saved => Files::identity($saved), 'C1' => ['sha256' => str_repeat('a', 64)], 'C2' => ['sha256' => str_repeat('b', 64)]],
            'expected_rows' => [2024 => 18000], 'expected_entries' => [2024 => 126000]];
        $seen = Reader::identities();
        $audit = [];
        $n = 0;
        foreach (app(Reader::class)->rows($source, 2024, $seen, $audit) as $row) {
            $n++;
        }
        $seen->rollBack();
        $this->assertSame(18000, $n);
        $this->assertSame(0, $audit['P1_changes']);
        echo 'COMPOSITION_128M_OK peak='.memory_get_peak_usage(true).' bytes='.filesize($saved).' races='.$n."\n";
    }
}
