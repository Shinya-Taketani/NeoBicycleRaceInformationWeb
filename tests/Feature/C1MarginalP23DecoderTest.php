<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05DecisionDecoder;
use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e06WinnerConditionedDecoder;
use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder\Contract;
use App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder\Decoder;
use App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder\Evaluation;
use App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder\Experiment;
use App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder\Reader;
use App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder\Sources;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Optimizer;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Trainer;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\C1MarginalP23Fixture as Fixture;
use Tests\Support\MemoryLimitedTestProcess;
use Tests\TestCase;

class C1MarginalP23DecoderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/c1-marginal-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        DB::shouldReceive('connection')->never();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    #[DataProvider('entrantCounts')]
    public function test_frozen_decoders_match_independent_pair_enumeration_and_keep_winner_and_supporting(int $n, bool $tie): void
    {
        $input = Fixture::race(n: $n);
        $saved = Fixture::prediction($input, $tie);
        $original = Files::canonical($saved);
        $row = app(Decoder::class)->decode($input, $saved);
        $this->assertSame($original, Files::canonical($saved));
        $this->assertSame($saved['decision']['primary_position_1_bike'], $row['candidate']['primary_position_1_bike']);
        $w = $row['candidate']['primary_position_1_bike'];
        $pairs = [];
        foreach ($saved['probabilities']['entries'] as $b) {
            foreach ($saved['probabilities']['entries'] as $c) {
                if ($b['bike'] !== $w && $c['bike'] !== $w && $b['bike'] !== $c['bike']) {
                    $pairs[] = ['score' => $b['position_2_probability'] + $c['position_3_probability'], 'b' => $b['bike'], 'c' => $c['bike'],
                        'key' => hash('sha256', 'BT03E05-DECODER-TIE-v1|PRIMARY_SECOND_THIRD|19|'.$w.'-'.$b['bike'].'-'.$c['bike'])];
                }
            }
        }
        usort($pairs, fn ($a, $b) => $a['score'] === $b['score'] ? $a['key'] <=> $b['key'] : ($a['score'] > $b['score'] ? -1 : 1));
        $this->assertSame([$w, $pairs[0]['b'], $pairs[0]['c']], array_map(fn ($p) => $row['candidate']['primary_position_'.$p.'_bike'], [1, 2, 3]));
        $this->assertGreaterThanOrEqual($row['marginal_score_old'], $row['marginal_score_new']);
        $this->assertSame(app(Bt03e05DecisionDecoder::class)->decode($saved['probabilities']), $row['candidate']);
        foreach (['map_ordered_top3', 'map_top3_set', 'expected_ndcg_top3', 'top2_marginal_bikes', 'top3_marginal_bikes'] as $key) {
            $this->assertSame($row['baseline'][$key], $row['candidate'][$key]);
        }
    }

    public static function entrantCounts(): array
    {
        return [[5, false], [7, false], [9, false], [5, true], [7, true], [9, true]];
    }

    public function test_conditioned_and_marginal_pairs_can_differ_without_epsilon_or_greedy_selection(): void
    {
        $input = Fixture::race();
        $row = app(Decoder::class)->decode($input, Fixture::prediction($input, utilities: [
            'POSITION_1' => [-0.8, -0.2, -1.5, 1.5, 0.9, -1.9, 1.3],
            'POSITION_2' => [1.5, 1.7, 1.9, -1.5, 2.0, -1.4, -1.4],
            'POSITION_3' => [1.6, 1.2, -1.6, 1.4, 1.3, -1.3, 2.0],
        ]));
        $this->assertNotSame([$row['baseline']['primary_position_2_bike'], $row['baseline']['primary_position_3_bike']],
            [$row['candidate']['primary_position_2_bike'], $row['candidate']['primary_position_3_bike']]);
        $p = Fixture::prediction($input, true)['probabilities'];
        $winner = app(Bt03e05DecisionDecoder::class)->decode($p)['primary_position_1_bike'];
        $eligible = array_keys(array_filter($p['entries'], fn ($e) => $e['bike'] !== $winner));
        $p['entries'][$eligible[0]]['position_2_probability'] += 1e-14;
        $p['entries'][$eligible[1]]['position_3_probability'] += 2e-14;
        $candidate = app(Bt03e05DecisionDecoder::class)->decode($p);
        $this->assertSame(1, $candidate['second_third_tie_count']);
    }

    #[DataProvider('badPredictions')]
    public function test_resealed_malformed_prediction_is_rejected(string $kind): void
    {
        $input = Fixture::race();
        $row = Fixture::prediction($input);
        match ($kind) {
            'unknown' => $row['probabilities']['surprise'] = 0,
            'outcome' => $row['probabilities']['entries'][0]['rank'] = 1,
            'nan' => $row['probabilities']['entries'][0]['position_1_probability'] = NAN,
            'negative' => $row['probabilities']['entries'][0]['position_1_probability'] = -0.1,
            'sum' => $row['probabilities']['probability_invariants']['position_1_sum'] = 0.9,
            'entry' => $row['probabilities']['entries'][0]['id']++,
            'bike' => $row['probabilities']['entries'][0]['bike'] = 9,
            'duplicate' => $row['probabilities']['entries'][1] = $row['probabilities']['entries'][0],
            'order' => $row['probabilities']['entries'] = array_reverse($row['probabilities']['entries']),
            'year' => $row['probabilities']['year'] = 2025,
            'missing' => array_pop($row['probabilities']['entries']),
            'extra' => $row['probabilities']['entries'][] = $row['probabilities']['entries'][0],
            'decision' => $row['decision']['primary_position_2_bike'] = 9,
            'origin' => $row['decision']['prediction_origin'] = 'UNKNOWN',
            'q' => $row['decision']['selected_q2_given_winner'] += 1e-10,
        };
        $this->expectException(\Throwable::class);
        app(Decoder::class)->decode($input, $row);
    }

    public static function badPredictions(): array
    {
        return array_map(fn ($v) => [$v], ['unknown', 'outcome', 'nan', 'negative', 'sum', 'entry', 'bike', 'duplicate', 'order', 'year', 'missing', 'extra', 'decision', 'origin', 'q']);
    }

    public function test_execute_is_independent_reproducible_without_training_or_external_access(): void
    {
        $bundle = Fixture::make($this->directory.'/fixture');
        $this->app->instance(Sources::class, $bundle['sources']);
        foreach ([Optimizer::class,
            Trainer::class,
            EffectBinBuilder::class] as $class) {
            $this->app->bind($class, fn () => throw new RuntimeException('Learning forbidden.'));
        }
        $result = app(Experiment::class)->execute($bundle['input'], $bundle['baseline'], $this->directory.'/result');
        $this->assertSame('COMPLETED_DEVELOPMENT_COMPARISON_AWAITING_REVIEW', $result['status']);
        $repro = Files::json($this->directory.'/result/reproduction.json');
        $this->assertTrue($repro['identical']);
        $this->assertSame(0, $repro['training_count']);
        $this->assertGreaterThan(0, $repro['semantic_file_count']);
        foreach (['run-01', 'run-02'] as $run) {
            $events = Files::json($this->directory.'/result/'.$run.'/access-order.json');
            $this->assertSame(['DECISION_SEALED', 'DECISION_SEALED', 'BOTH_YEARS_SEALS_VERIFIED', 'OUTCOME_RELEASE_AUTHORIZED', 'OUTCOME_RELEASE_AUTHORIZED'], array_column($events, 'event'));
            $invariants = Files::json($this->directory.'/result/'.$run.'/invariants.json');
            $this->assertSame(0, $invariants[2024]['P1_changes']);
            $this->assertSame(2, $invariants[2024]['races']);
            $evaluation = Files::json($this->directory.'/result/'.$run.'/evaluation.json');
            foreach ([2024, 2025] as $year) {
                $records = iterator_to_array(JsonlArtifact::read($this->directory.'/result/'.$run.'/contributions-'.$year.'.jsonl'));
                foreach (Bt03e05MetricEvaluator::METRIC_CODES as $metric) {
                    $sum = array_sum(array_map(fn ($r) => $r['comparisons']['CANDIDATE-C1']['candidate'][$metric]['numerator'], $records));
                    $denom = array_sum(array_map(fn ($r) => $r['comparisons']['CANDIDATE-C1']['candidate'][$metric]['denominator'], $records));
                    $this->assertSame($sum, $evaluation['outer']['CANDIDATE-C1'][$year]['candidate_numerators'][$metric]);
                    $this->assertSame($denom, $evaluation['outer']['CANDIDATE-C1'][$year]['denominators'][$metric]);
                    $this->assertSame($sum / $denom, $evaluation['outer']['CANDIDATE-C1'][$year]['candidate'][$metric]);
                }
            }
        }
        Http::assertNothingSent();
        Sources::verify($bundle['source'], true);
        $this->expectExceptionMessage('Output must be new');
        app(Experiment::class)->execute($bundle['input'], $bundle['baseline'], $this->directory.'/result');
    }

    public function test_changed_labels_do_not_change_decisions_and_affect_only_evaluation(): void
    {
        $runs = [];
        foreach ([null, 2024, 2025] as $i => $year) {
            $bundle = Fixture::make($this->directory.'/fixture-'.$i, $year);
            $runs[$i] = Files::directory($this->directory.'/run-'.$i);
            app(Experiment::class)->run($bundle['source'], $runs[$i]);
        }
        foreach ([2024, 2025] as $year) {
            foreach ([1, 2] as $i) {
                $this->assertSame(Files::identity($runs[0].'/decisions-'.$year.'.jsonl'), Files::identity($runs[$i].'/decisions-'.$year.'.jsonl'));
            }
        }
        $this->assertNotSame(Files::json($runs[0].'/evaluation.json')['outer'], Files::json($runs[1].'/evaluation.json')['outer']);
        $this->assertNotSame(Files::json($runs[0].'/evaluation.json')['outer'], Files::json($runs[2].'/evaluation.json')['outer']);
    }

    public function test_label_access_before_both_seals_is_rejected(): void
    {
        $bundle = Fixture::make($this->directory.'/fixture');
        $this->expectExceptionMessage('before both prediction seals');
        iterator_to_array(app(Reader::class)->labelled($bundle['source'], 2024, []));
    }

    #[DataProvider('invalidYears')]
    public function test_unneeded_years_are_forbidden(int $year): void
    {
        $this->expectExceptionMessage('Forbidden dataset year');
        Reader::year($year);
    }

    public static function invalidYears(): array
    {
        return [[2022], [2023], [2026]];
    }

    public function test_source_drift_fails_without_complete_or_zero_performance(): void
    {
        $bundle = Fixture::make($this->directory.'/fixture');
        $source = $bundle['source'];
        file_put_contents($source['paths'][2024]['prediction'], 'x', FILE_APPEND);
        $this->expectExceptionMessage('hash/size mismatch');
        Sources::verify($source, false);
    }

    public function test_end_drift_prevents_complete_and_keeps_failure_evidence(): void
    {
        $bundle = Fixture::make($this->directory.'/fixture');
        $this->app->instance(Sources::class, $bundle['sources']);
        $mutator = new Process([PHP_BINARY, '-r',
            'for($i=0;$i<5000;$i++){clearstatcache();if(is_file($argv[1])){file_put_contents($argv[2]," ",FILE_APPEND);exit(0);}usleep(1000);}exit(1);',
            $this->directory.'/failed/frozen-contract.json', $bundle['source']['paths'][2024]['model']], timeout: 10);
        $mutator->start();
        try {
            app(Experiment::class)->execute($bundle['input'], $bundle['baseline'], $this->directory.'/failed');
            $this->fail('END drift was accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('hash/size mismatch', $e->getMessage());
        } finally {
            $this->assertSame(0, $mutator->wait());
        }
        $this->assertFileDoesNotExist($this->directory.'/failed/COMPLETE.json');
        $failure = Files::json($this->directory.'/failed/FAILED.json');
        $this->assertSame('NOT_EVALUATED', $failure['status']);
        $this->assertNull($failure['performance']);
        $this->assertNull($failure['gate']);
    }

    private function decisions(array $source, string $directory): array
    {
        Files::directory($directory);
        $paths = [];
        foreach ([2024, 2025] as $year) {
            $audit = [];
            $paths[$year] = $directory.'/decisions-'.$year.'.jsonl';
            JsonlArtifact::write($paths[$year], app(Reader::class)->decisions($source, $year, $directory.'/seen-'.$year.'.sqlite', $audit));
        }

        return $paths;
    }

    #[DataProvider('badLabels')]
    public function test_labels_are_strictly_validated_after_release(string $kind): void
    {
        $bundle = Fixture::make($this->directory.'/fixture');
        $source = $bundle['source'];
        $paths = $this->decisions($source, $this->directory.'/sealed');
        $path = $source['paths'][2024]['labels'];
        $labels = iterator_to_array(JsonlArtifact::read($path));
        match ($kind) {
            'id' => $labels[0]['entries'][0]['id']++,
            'bike' => $labels[0]['entries'][0]['bike'] = 9,
            'raw' => $labels[0]['entries'][0]['raw'] = 99.0,
            'year' => $labels[0]['year'] = 2025,
            'order' => $labels = array_reverse($labels),
            'duplicate' => $labels[1] = $labels[0],
            'missing' => array_pop($labels),
            'extra' => $labels[] = $labels[0],
            'rank' => $labels[0]['entries'][0]['rank'] = '1',
            'status' => $labels[0]['entries'][0]['status'] = 'UNKNOWN',
            'tie' => $labels[0]['entries'][1]['rank'] = 1,
            'outcome' => $labels[0]['entries'][0]['winner_label'] = 1,
        };
        unlink($path);
        unlink($path.'.manifest.json');
        JsonlArtifact::write($path, $labels);
        $source['outcome_seals'][$path] = Files::identity($path);
        $this->expectException(\Throwable::class);
        iterator_to_array(app(Reader::class)->labelled($source, 2024, $paths));
    }

    public static function badLabels(): array
    {
        return array_map(fn ($v) => [$v], ['id', 'bike', 'raw', 'year', 'order', 'duplicate', 'missing', 'extra', 'rank', 'status', 'tie', 'outcome']);
    }

    public function test_zero_denominator_is_not_zero_percent_or_gate_fail(): void
    {
        $bundle = Fixture::make($this->directory.'/fixture');
        $source = $bundle['source'];
        $paths = $this->decisions($source, $this->directory.'/sealed');
        foreach ([2024, 2025] as $year) {
            $path = $source['paths'][$year]['labels'];
            $rows = iterator_to_array(JsonlArtifact::read($path));
            foreach ($rows as &$row) {
                foreach ($row['entries'] as &$entry) {
                    $entry['rank'] = null;
                    $entry['status'] = 'WITHDRAWN';
                }
                unset($entry);
            }
            unset($row);
            unlink($path);
            unlink($path.'.manifest.json');
            JsonlArtifact::write($path, $rows);
            $source['outcome_seals'][$path] = Files::identity($path);
        }
        $this->expectExceptionMessage('Zero evaluation denominator: NOT_EVALUATED');
        app(Evaluation::class)->evaluate($source, $paths, Files::directory($this->directory.'/evaluation'));
    }

    public function test_unknown_source_and_unknown_input_contract_are_rejected(): void
    {
        $bundle = Fixture::make($this->directory.'/fixture');
        try {
            (new Sources)->open($bundle['input'], $bundle['baseline']);
            $this->fail('Unaccepted source allowed.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Fixed experiment paths required', $e->getMessage());
        }
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

    public function test_nonmonotone_ids_duplicates_and_missing_prediction_cohort_are_verified_on_disk(): void
    {
        $bundle = Fixture::make($this->directory.'/fixture');
        $audit = [];
        $rows = iterator_to_array(app(Reader::class)->decisions($bundle['source'], 2024, $this->directory.'/seen.sqlite', $audit));
        $this->assertGreaterThan($rows[1]['race_id'], $rows[0]['race_id']);
        $this->assertSame(14, $audit['entries']);
        $path = $bundle['source']['paths'][2024]['input'];
        $input = iterator_to_array(Artifacts::lines($path));
        $input[1] = $input[0];
        file_put_contents($path, implode('', array_map(fn ($r) => Files::canonical($r)."\n", $input)));
        $this->expectException(\Throwable::class);
        iterator_to_array(app(Reader::class)->decisions($bundle['source'], 2024, $this->directory.'/duplicate.sqlite', $audit));
    }

    public function test_evaluator_retains_ties_primary_supporting_and_abnormal_denominators(): void
    {
        $input = Fixture::race();
        $row = app(Decoder::class)->decode($input, Fixture::prediction($input));
        foreach ([null, 1, 2, 3] as $tie) {
            $context = $input;
            foreach ($context['entries'] as $i => &$entry) {
                $entry['rank'] = $i + 1;
                $entry['status'] = 'FINISHED';
            }
            unset($entry);
            if ($tie !== null) {
                foreach ([$tie - 1, $tie] as $i) {
                    $context['entries'][$i]['rank'] = $tie;
                    $context['entries'][$i]['status'] = 'TIED';
                }
            }
            $metrics = app(Bt03e05MetricEvaluator::class)->raceComparison($context, $row['candidate']);
            $this->assertSame($tie === null ? 3.0 : 0.0, $metrics['candidate']['POSITION_HIT_RATE_AT_3']['denominator']);
            if ($tie !== null) {
                $this->assertSame(0.0, $metrics['candidate']['POSITION_'.$tie.'_ACCURACY']['denominator']);
            }
            $this->assertSame($metrics['candidate']['POSITION_1_ACCURACY'], $metrics['candidate']['WINNER_HIT_AT_1']);
        }
    }

    public function test_gate_boundaries_are_exact_frozen_incremental_gate(): void
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

    public function test_plan_is_offline(): void
    {
        $this->artisan('keirin:c1:marginal-p23-decoder plan')->assertSuccessful();
        $this->assertSame(0, Contract::plan()['training_count']);
        $this->assertFalse(Contract::plan()['use_restrictions']['formal_adoption']);
        Http::assertNothingSent();
    }

    public function test_over_one_hundred_mib_stream_in_independent_128m_process(): void
    {
        if (! getenv('C1_MARGINAL_MEMORY_CHILD')) {
            $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M', base_path('vendor/bin/phpunit'),
                '--do-not-cache-result', '--filter', 'test_over_one_hundred_mib_stream_in_independent_128m_process', __FILE__], base_path(),
                MemoryLimitedTestProcess::environment(['C1_MARGINAL_MEMORY_CHILD' => '1', 'C1_MARGINAL_PARENT' => (string) getmypid()]), timeout: 240);
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $this->assertStringContainsString('STREAMED_128M_OK', $process->getOutput());
            $this->assertSame(1, preg_match('/STREAMED_128M_OK (\d+) bytes=(\d+) races=(\d+)/', $process->getOutput(), $measurement));
            $this->assertLessThan(128 * 1024 * 1024, (int) $measurement[1]);
            $this->assertGreaterThan(100 * 1024 * 1024, (int) $measurement[2]);
            $this->assertSame(18000, (int) $measurement[3]);

            return;
        }
        $this->assertSame(128 * 1024 * 1024, ini_parse_quantity(ini_get('memory_limit')));
        $this->assertNotSame((string) getmypid(), getenv('C1_MARGINAL_PARENT'));
        $input = Fixture::race();
        $saved = Fixture::prediction($input);
        $inputPath = $this->directory.'/input.jsonl';
        $predictionPath = $this->directory.'/prediction.jsonl';
        $count = 18000;
        JsonlArtifact::write($inputPath, (function () use ($input, $count) {
            for ($i = 1; $i <= $count; $i++) {
                $row = $input;
                $row['race_id'] = $i;
                foreach ($row['entries'] as &$e) {
                    $e['id'] = $i * 10 + $e['bike'];
                }
                unset($e);
                yield $row;
            }
        })());
        JsonlArtifact::write($predictionPath, (function () use ($saved, $count) {
            for ($i = 1; $i <= $count; $i++) {
                $row = $saved;
                $row['probabilities']['race_id'] = $i;
                foreach ($row['probabilities']['entries'] as &$e) {
                    $e['id'] = $i * 10 + $e['bike'];
                }
                unset($e);
                $row['decision'] = app(Bt03e06WinnerConditionedDecoder::class)->decode($row['probabilities']);
                $row['decision']['reconstruction_verified'] = false;
                $row['decision']['prediction_origin'] = 'EXPERIMENTAL_REFIT';
                yield $row;
            }
        })());
        $this->assertGreaterThan(100 * 1024 * 1024, filesize($predictionPath));
        $source = ['paths' => [2024 => ['input' => $inputPath, 'prediction' => $predictionPath, 'model' => 'synthetic']],
            'seals' => [$inputPath => Files::identity($inputPath), 'synthetic' => ['sha256' => str_repeat('a', 64)]],
            'expected_rows' => [2024 => $count], 'expected_entries' => [2024 => 7 * $count]];
        $audit = [];
        $n = 0;
        foreach (app(Reader::class)->decisions($source, 2024, $this->directory.'/seen.sqlite', $audit) as $row) {
            $n++;
        }
        $this->assertSame($count, $n);
        $this->assertSame(0, $audit['P1_changes']);
        echo 'STREAMED_128M_OK '.memory_get_peak_usage(true).' bytes='.filesize($predictionPath).' races='.$n."\n";
    }
}
