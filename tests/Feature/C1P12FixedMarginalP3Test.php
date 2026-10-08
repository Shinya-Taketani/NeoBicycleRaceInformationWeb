<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05DecisionDecoder;
use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e06WinnerConditionedDecoder;
use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder\Reader;
use App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder\Sources;
use App\Domain\Keirin\Backtest\Experiments\C1P12FixedMarginalP3\Contract;
use App\Domain\Keirin\Backtest\Experiments\C1P12FixedMarginalP3\Decoder;
use App\Domain\Keirin\Backtest\Experiments\C1P12FixedMarginalP3\Evaluation;
use App\Domain\Keirin\Backtest\Experiments\C1P12FixedMarginalP3\Experiment;
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

class C1P12FixedMarginalP3Test extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/c1-fixed-p12-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        DB::shouldReceive('connection')->never();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    #[DataProvider('counts')]
    public function test_independent_selection_keeps_fixed_positions_originals_and_supporting(int $n, bool $tie, bool $reverse): void
    {
        $input = Fixture::race(n: $n);
        if ($reverse) {
            $input['entries'] = array_reverse($input['entries']);
        }
        $saved = Fixture::prediction($input, $tie);
        $bytes = Files::canonical($saved);
        $row = app(Decoder::class)->decode($input, $saved);
        $a = $saved['decision']['primary_position_1_bike'];
        $b = $saved['decision']['primary_position_2_bike'];
        $c = $saved['decision']['primary_position_3_bike'];
        $entries = array_column($saved['probabilities']['entries'], null, 'bike');
        $eligible = array_filter($entries, fn ($e) => $e['bike'] !== $a && $e['bike'] !== $b);
        $max = max(array_column($eligible, 'position_3_probability'));
        $choices = array_filter($eligible, fn ($e) => $e['position_3_probability'] === $max);
        $keys = [];
        foreach ($choices as $bike => $_) {
            $keys[$bike] = hash('sha256', Contract::TIE.'|2024|19|'.$a.'|'.$b.'|'.$bike);
        }
        asort($keys, SORT_STRING);
        $expected = $entries[$c]['position_3_probability'] === $max ? $c : array_key_first($keys);
        $this->assertSame([$a, $b, $expected], array_map(fn ($p) => $row['candidate']['primary_position_'.$p.'_bike'], [1, 2, 3]));
        $this->assertSame(3, count(array_unique([$a, $b, $expected])));
        $this->assertSame($saved['decision'], $row['baseline']);
        $this->assertSame($bytes, Files::canonical($saved));
        $this->assertSame((float) $entries[$expected]['position_3_probability'], $row['candidate']['policy']['selected_probability']);
        $this->assertSame(count($choices), $row['candidate']['second_third_tie_count']);
        $this->assertGreaterThanOrEqual(0.0, $row['model_expected_gain']);
        foreach (['map_ordered_top3', 'map_ordered_probability', 'map_top3_set', 'map_top3_set_probability',
            'top2_marginal_bikes', 'top3_marginal_bikes', 'expected_ndcg_top3'] as $key) {
            $this->assertSame($saved['decision'][$key], $row['candidate'][$key]);
        }
        foreach ($row['candidate']['decoder_tie_diagnostics'] as $key => $value) {
            $this->assertSame($saved['decision']['decoder_tie_diagnostics'][$key], $value);
        }
        foreach (['selected_q3_given_winner', 'objective_score', 'primary_second_third_objective_score', 'q3_paths'] as $key) {
            $this->assertArrayNotHasKey($key, $row['candidate']);
        }
    }

    public static function counts(): array
    {
        $cases = [];
        foreach ([5, 7, 9] as $n) {
            foreach ([false, true] as $tie) {
                foreach ([false, true] as $reverse) {
                    $cases[] = [$n, $tie, $reverse];
                }
            }
        }

        return $cases;
    }

    public function test_exact_maximum_retention_hash_tie_exclusions_and_tiny_difference(): void
    {
        $decoder = app(Decoder::class);
        $entries = array_map(fn ($bike, $score) => ['bike' => $bike, 'position_3_probability' => $score],
            [1, 2, 3, 4, 6, 7, 8], [1.0, 0.9, 0.2, 0.3, 0.3, 0.3, 0.1]);
        $retained = $decoder->select(2024, 19, $entries, 1, 2, 4);
        $this->assertSame(4, $retained['selected_bike']);
        $this->assertTrue($retained['equal_maximum_retained']);
        $this->assertFalse($retained['hash_selection']);
        $this->assertNull($retained['selected_tie_sha256']);
        $changed = $decoder->select(2024, 19, $entries, 1, 2, 3);
        $keys = [];
        foreach ([4, 6, 7] as $bike) {
            $keys[$bike] = hash('sha256', Contract::TIE.'|2024|19|1|2|'.$bike);
        }
        asort($keys, SORT_STRING);
        $this->assertSame(array_key_first($keys), $changed['selected_bike']);
        $this->assertSame(reset($keys), $changed['selected_tie_sha256']);
        $this->assertTrue($changed['hash_selection']);
        $this->assertSame(3, $changed['maximum_count']);
        $this->assertSame($changed, $decoder->select(2024, 19, array_reverse($entries), 1, 2, 3));
        $entries[4]['position_3_probability'] += 1e-14;
        $tiny = $decoder->select(2024, 19, $entries, 1, 2, 4);
        $this->assertSame(6, $tiny['selected_bike']);
        $this->assertSame(1, $tiny['maximum_count']);
        $this->assertGreaterThan(0.3, $tiny['selected_probability']);
    }

    public function test_conditioned_p3_can_differ_and_p2_is_never_taken_from_old_marginal_policy(): void
    {
        $input = Fixture::race();
        $saved = Fixture::prediction($input, utilities: [
            'POSITION_1' => [1.5, 1.8, 0.7, 0.5, -0.4, 0.9, -1.9],
            'POSITION_2' => [-1.6, 2.0, -0.6, 0.0, -1.6, -1.7, 1.9],
            'POSITION_3' => [-0.2, -0.1, 1.1, -0.9, 0.7, 1.1, 1.1],
        ]);
        $row = app(Decoder::class)->decode($input, $saved);
        $this->assertNotSame($row['baseline']['primary_position_3_bike'], $row['candidate']['primary_position_3_bike']);
        $this->assertTrue($row['candidate']['policy']['changed']);
        $this->assertGreaterThan(0.0, $row['model_expected_gain']);
        $old = app(\App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder\Decoder::class)->decode($input, $saved);
        $this->assertSame($saved['decision']['primary_position_2_bike'], $row['candidate']['primary_position_2_bike']);
        $this->assertSame(app(Bt03e05DecisionDecoder::class)->decode($saved['probabilities']), $old['candidate']);
    }

    #[DataProvider('badPredictions')]
    public function test_existing_prediction_rejections_are_preserved(string $kind): void
    {
        $input = Fixture::race();
        $saved = Fixture::prediction($input);
        match ($kind) {
            'unknown' => $saved['probabilities']['surprise'] = 0,
            'outcome' => $saved['probabilities']['entries'][0]['rank'] = 1,
            'nan' => $saved['probabilities']['entries'][0]['position_1_probability'] = NAN,
            'negative' => $saved['probabilities']['entries'][0]['position_1_probability'] = -0.1,
            'sum' => $saved['probabilities']['probability_invariants']['position_1_sum'] = 0.9,
            'entry' => $saved['probabilities']['entries'][0]['id']++,
            'bike' => $saved['probabilities']['entries'][0]['bike'] = 9,
            'duplicate' => $saved['probabilities']['entries'][1] = $saved['probabilities']['entries'][0],
            'order' => $saved['probabilities']['entries'] = array_reverse($saved['probabilities']['entries']),
            'year' => $saved['probabilities']['year'] = 2025,
            'missing' => array_pop($saved['probabilities']['entries']),
            'extra' => $saved['probabilities']['entries'][] = $saved['probabilities']['entries'][0],
            'decision' => $saved['decision']['primary_position_2_bike'] = 9,
            'origin' => $saved['decision']['prediction_origin'] = 'UNKNOWN',
            'q' => $saved['decision']['selected_q2_given_winner'] += 1e-10,
        };
        $this->expectException(\Throwable::class);
        app(Decoder::class)->decode($input, $saved);
    }

    public static function badPredictions(): array
    {
        return C1MarginalP23DecoderTest::badPredictions();
    }

    private function decisions(array $source, string $directory): array
    {
        Files::directory($directory);
        $paths = [];
        foreach ([2024, 2025] as $year) {
            $audit = [];
            $paths[$year] = $directory.'/decisions-'.$year.'.jsonl';
            JsonlArtifact::write($paths[$year], app(Experiment::class)->decisions($source, $year, $directory.'/seen-'.$year.'.sqlite', $audit));
            $this->assertSame(0, $audit['P1_changes']);
            $this->assertSame(0, $audit['P2_changes']);
        }

        return $paths;
    }

    public function test_execute_twice_rereads_sources_keeps_all_contributions_and_uses_frozen_gate(): void
    {
        $bundle = Fixture::make($this->directory.'/fixture');
        $this->app->instance(Sources::class, $bundle['sources']);
        foreach ([Optimizer::class, Trainer::class, EffectBinBuilder::class] as $class) {
            $this->app->bind($class, fn () => throw new RuntimeException('Learning forbidden.'));
        }
        $result = app(Experiment::class)->execute($bundle['input'], $bundle['baseline'], $this->directory.'/result');
        $this->assertSame(0, $result['training_count']);
        $repro = Files::json($this->directory.'/result/reproduction.json');
        $this->assertTrue($repro['identical']);
        $this->assertSame(2, $repro['independent_source_reread_runs']);
        $this->assertSame(12, $repro['semantic_file_count']);
        $comparison = Files::json($this->directory.'/result/comparison.json');
        $gate = app(\App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Evaluation::class);
        $this->assertSame($gate->incrementalGate($comparison['outer']['CANDIDATE-C1'], $comparison['intervals']['CANDIDATE-C1'], true), $result['incremental_gate']);
        foreach (['run-01', 'run-02'] as $run) {
            $events = Files::json($this->directory.'/result/'.$run.'/access-order.json');
            $this->assertSame(['DECISION_SEALED_P1_P2_VERIFIED', 'DECISION_SEALED_P1_P2_VERIFIED',
                'BOTH_YEARS_SEALS_AND_FIXED_POSITIONS_VERIFIED', 'OUTCOME_RELEASE_AUTHORIZED', 'OUTCOME_RELEASE_AUTHORIZED'], array_column($events, 'event'));
            foreach ([2024, 2025] as $year) {
                $invariant = Files::json($this->directory.'/result/'.$run.'/invariants.json')[$year];
                $this->assertSame(0, $invariant['P1_changes']);
                $this->assertSame(0, $invariant['P2_changes']);
                $this->assertSame(2, $invariant['races']);
                $records = iterator_to_array(JsonlArtifact::read($this->directory.'/result/'.$run.'/contributions-'.$year.'.jsonl'));
                foreach (Bt03e05MetricEvaluator::METRIC_CODES as $metric) {
                    foreach (['candidate', 'baseline'] as $side) {
                        $num = array_sum(array_map(fn ($r) => $r['comparisons']['CANDIDATE-C1'][$side][$metric]['numerator'], $records));
                        $this->assertSame($num, $comparison['outer']['CANDIDATE-C1'][$year][$side.'_numerators'][$metric]);
                    }
                }
                $fixed = $comparison['fixed_evaluation_invariants'][$year];
                $this->assertSame($fixed['Hit3_position_delta'], $fixed['eligible_P3_hit_delta']);
            }
        }
        $this->assertSame(['ci_lower' => 0.0, 'ci_upper' => 0.0], $comparison['intervals']['CANDIDATE-C1']['POSITION_2_ACCURACY']);
        Sources::verify($bundle['source'], true);
        Http::assertNothingSent();
        $this->expectExceptionMessage('Output must be new');
        app(Experiment::class)->execute($bundle['input'], $bundle['baseline'], $this->directory.'/result');
    }

    public function test_outcome_changes_do_not_change_candidates(): void
    {
        $runs = [];
        foreach ([null, 2024, 2025] as $i => $changed) {
            $bundle = Fixture::make($this->directory.'/fixture-'.$i, $changed);
            $runs[$i] = Files::directory($this->directory.'/run-'.$i);
            app(Experiment::class)->run($bundle['source'], $runs[$i]);
        }
        foreach ([2024, 2025] as $year) {
            foreach ([1, 2] as $i) {
                $this->assertSame(Files::identity($runs[0].'/decisions-'.$year.'.jsonl'), Files::identity($runs[$i].'/decisions-'.$year.'.jsonl'));
            }
        }
        foreach ([1, 2] as $i) {
            $this->assertNotSame(Files::json($runs[0].'/evaluation.json')['outer'], Files::json($runs[$i].'/evaluation.json')['outer']);
        }
    }

    public function test_label_release_requires_both_seals_and_zero_denominator_is_not_evaluated(): void
    {
        $bundle = Fixture::make($this->directory.'/fixture');
        try {
            iterator_to_array(app(Reader::class)->labelled($bundle['source'], 2024, []));
            $this->fail('Premature label access accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('before both prediction seals', $e->getMessage());
        }
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

    public function test_hit3_delta_uses_only_eligible_population_and_rejects_broken_fixed_contributions(): void
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
            $metrics = app(Bt03e05MetricEvaluator::class);
            $a = $metrics->raceComparison($context, $row['baseline']);
            $b = $metrics->raceComparison($context, $row['candidate']);
            $b['baseline'] = $a['candidate'];
            $audit = ['races_checked' => 0, 'Hit3_eligible_races' => 0, 'Hit3_eligible_P3_changed_races' => 0,
                'eligible_P3_C1_hits' => 0, 'eligible_P3_candidate_hits' => 0, 'Hit3_position_delta' => 0, 'eligible_P3_hit_delta' => 0];
            app(Evaluation::class)->check($row, $b, $audit);
            $this->assertSame($tie === null ? 1 : 0, $audit['Hit3_eligible_races']);
            $this->assertSame($audit['Hit3_position_delta'], $audit['eligible_P3_hit_delta']);
            $this->assertSame($tie === null ? 3.0 : 0.0, $b['candidate']['POSITION_HIT_RATE_AT_3']['denominator']);
        }
        $b['candidate']['POSITION_2_ACCURACY']['numerator'] += 1.0;
        $this->expectExceptionMessage('fixed contribution P2');
        app(Evaluation::class)->check($row, $b, $audit);
    }

    public function test_nonmonotone_ids_duplicate_missing_extra_and_source_drift_fail_closed(): void
    {
        $bundle = Fixture::make($this->directory.'/fixture');
        $audit = [];
        $rows = iterator_to_array(app(Experiment::class)->decisions($bundle['source'], 2024, $this->directory.'/seen.sqlite', $audit));
        $this->assertGreaterThan($rows[1]['race_id'], $rows[0]['race_id']);
        $path = $bundle['source']['paths'][2024]['input'];
        $inputs = iterator_to_array(Artifacts::lines($path));
        $inputs[1] = $inputs[0];
        file_put_contents($path, implode('', array_map(fn ($r) => Files::canonical($r)."\n", $inputs)));
        $this->expectException(\Throwable::class);
        iterator_to_array(app(Experiment::class)->decisions($bundle['source'], 2024, $this->directory.'/duplicate.sqlite', $audit));
    }

    #[DataProvider('badLabels')]
    public function test_resealed_invalid_labels_are_rejected_after_sealing(string $kind): void
    {
        $bundle = Fixture::make($this->directory.'/fixture');
        $source = $bundle['source'];
        $paths = $this->decisions($source, $this->directory.'/sealed');
        $path = $source['paths'][2024]['labels'];
        $rows = iterator_to_array(JsonlArtifact::read($path));
        match ($kind) {
            'id' => $rows[0]['entries'][0]['id']++,
            'bike' => $rows[0]['entries'][0]['bike'] = 9,
            'raw' => $rows[0]['entries'][0]['raw'] = 99.0,
            'year' => $rows[0]['year'] = 2025,
            'order' => $rows = array_reverse($rows),
            'duplicate' => $rows[1] = $rows[0],
            'missing' => array_pop($rows),
            'extra' => $rows[] = $rows[0],
            'rank' => $rows[0]['entries'][0]['rank'] = '1',
            'status' => $rows[0]['entries'][0]['status'] = 'UNKNOWN',
            'tie' => $rows[0]['entries'][1]['rank'] = 1,
            'outcome' => $rows[0]['entries'][0]['winner_label'] = 1,
        };
        unlink($path);
        unlink($path.'.manifest.json');
        JsonlArtifact::write($path, $rows);
        $source['outcome_seals'][$path] = Files::identity($path);
        $this->expectException(\Throwable::class);
        iterator_to_array(app(Reader::class)->labelled($source, 2024, $paths));
    }

    public static function badLabels(): array
    {
        return C1MarginalP23DecoderTest::badLabels();
    }

    public function test_frozen_gate_strict_inclusive_boundaries_and_integrity_are_unchanged(): void
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

    public function test_source_unknown_version_and_mutation_are_rejected(): void
    {
        $bundle = Fixture::make($this->directory.'/fixture');
        try {
            (new Sources)->open($bundle['input'], $bundle['baseline']);
            $this->fail('Unaccepted sources allowed.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Fixed experiment paths required', $e->getMessage());
        }
        file_put_contents($bundle['source']['paths'][2024]['prediction'], 'x', FILE_APPEND);
        $this->expectExceptionMessage('hash/size mismatch');
        Sources::verify($bundle['source'], false);
    }

    public function test_end_drift_keeps_failure_without_publication(): void
    {
        $bundle = Fixture::make($this->directory.'/fixture');
        $this->app->instance(Sources::class, $bundle['sources']);
        $process = new Process([PHP_BINARY, '-r',
            'for($i=0;$i<5000;$i++){clearstatcache();if(is_file($argv[1])){file_put_contents($argv[2]," ",FILE_APPEND);exit(0);}usleep(1000);}exit(1);',
            $this->directory.'/failed/frozen-contract.json', $bundle['source']['paths'][2024]['model']], timeout: 10);
        $process->start();
        try {
            app(Experiment::class)->execute($bundle['input'], $bundle['baseline'], $this->directory.'/failed');
            $this->fail('Drift accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('hash/size mismatch', $e->getMessage());
        } finally {
            $this->assertSame(0, $process->wait());
        }
        $this->assertFileDoesNotExist($this->directory.'/failed/COMPLETE.json');
        $failure = Files::json($this->directory.'/failed/FAILED.json');
        $this->assertSame('NOT_EVALUATED', $failure['status']);
        $this->assertNull($failure['performance']);
        $this->assertNull($failure['gate']);
    }

    #[DataProvider('forbiddenYears')]
    public function test_non_evaluation_years_are_forbidden(int $year): void
    {
        $this->expectExceptionMessage('Forbidden dataset year');
        app(Decoder::class)->select($year, 19, [], 1, 2, 3);
    }

    public static function forbiddenYears(): array
    {
        return [[2022], [2023], [2026]];
    }

    public function test_plan_is_offline_and_does_not_change_original_contract(): void
    {
        $this->artisan('keirin:c1:p12-fixed-marginal-p3 plan')->assertSuccessful();
        $this->assertSame(0, Contract::plan()['training_count']);
        $this->assertFalse(Contract::plan()['use_restrictions']['formal_adoption']);
        $this->assertSame('C1-MARGINAL-P23-DECODER-01-v1', \App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder\Contract::VERSION);
        Http::assertNothingSent();
    }

    public function test_over_one_hundred_mib_in_independent_128m_process(): void
    {
        if (! getenv('C1_FIXED_P12_MEMORY_CHILD')) {
            $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M', base_path('vendor/bin/phpunit'),
                '--do-not-cache-result', '--filter', 'test_over_one_hundred_mib_in_independent_128m_process', __FILE__], base_path(),
                MemoryLimitedTestProcess::environment(['C1_FIXED_P12_MEMORY_CHILD' => '1', 'C1_FIXED_P12_PARENT' => (string) getmypid()]), timeout: 240);
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $this->assertSame(1, preg_match('/FIXED_P12_128M_OK peak=(\d+) bytes=(\d+) races=(\d+)/', $process->getOutput(), $m));
            $this->assertLessThan(128 * 1024 * 1024, (int) $m[1]);
            $this->assertGreaterThan(100 * 1024 * 1024, (int) $m[2]);
            $this->assertSame(18000, (int) $m[3]);

            return;
        }
        $this->assertSame(128 * 1024 * 1024, ini_parse_quantity(ini_get('memory_limit')));
        $this->assertNotSame((string) getmypid(), getenv('C1_FIXED_P12_PARENT'));
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
        $source = ['paths' => [2024 => ['input' => $inputPath, 'prediction' => $predictionPath, 'model' => 'synthetic']],
            'seals' => [$inputPath => Files::identity($inputPath), 'synthetic' => ['sha256' => str_repeat('a', 64)]],
            'expected_rows' => [2024 => $count], 'expected_entries' => [2024 => 7 * $count]];
        $audit = [];
        $n = 0;
        foreach (app(Experiment::class)->decisions($source, 2024, $this->directory.'/seen.sqlite', $audit) as $row) {
            $n++;
        }
        $this->assertSame($count, $n);
        $this->assertSame(0, $audit['P1_changes']);
        $this->assertSame(0, $audit['P2_changes']);
        $this->assertGreaterThan(100 * 1024 * 1024, filesize($predictionPath));
        echo 'FIXED_P12_128M_OK peak='.memory_get_peak_usage(true).' bytes='.filesize($predictionPath).' races='.$n."\n";
    }
}
