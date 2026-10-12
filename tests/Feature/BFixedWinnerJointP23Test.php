<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e06WinnerConditionedDecoder;
use App\Domain\Keirin\Backtest\Experiments\BFixedWinnerJointP23\Contract;
use App\Domain\Keirin\Backtest\Experiments\BFixedWinnerJointP23\Decoder;
use App\Domain\Keirin\Backtest\Experiments\BFixedWinnerJointP23\Evaluation;
use App\Domain\Keirin\Backtest\Experiments\BFixedWinnerJointP23\Experiment;
use App\Domain\Keirin\Backtest\Experiments\BFixedWinnerJointP23\Reader;
use App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder\Sources;
use App\Domain\Keirin\Backtest\Experiments\Stat35Trend6P1\MetricContributionSpool;
use App\Domain\Keirin\Backtest\Experiments\Stat35Trend6P1\PairedBootstrap;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\BFixedWinnerJointP23Fixture as Fixture;
use Tests\Support\BFixedWinnerJointP23TestProcess;
use Tests\Support\C1MarginalP23Fixture;
use Tests\Support\CompositionResultTemporaryDirectory;
use Tests\TestCase;

class BFixedWinnerJointP23Test extends TestCase
{
    private CompositionResultTemporaryDirectory $temporary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporary = BFixedWinnerJointP23TestProcess::temporary();
    }

    protected function tearDown(): void
    {
        try {
            JsonlArtifact::json($this->temporary->path().'/environment.json', BFixedWinnerJointP23TestProcess::environment($this->temporary->path()));
            $this->temporary->retire($this->temporary->path(), $this->status()->isSuccess() ? 'TEST_COMPLETE' : 'TEST_FAILURE');
        } finally {
            parent::tearDown();
        }
    }

    #[DataProvider('populations')]
    public function test_exact_oracle_winner_joint_and_supporting_invariants(int $n): void
    {
        $input = C1MarginalP23Fixture::race(n: $n);
        $saved = C1MarginalP23Fixture::prediction($input);
        $p = $saved['probabilities'];
        $b = $saved['decision'];
        $c = app(Decoder::class)->select($p, $b);
        $this->assertSame($b['primary_position_1_bike'], $c['primary_position_1_bike']);
        $bikes = array_column($p['entries'], 'bike');
        $q = app(Bt03e06WinnerConditionedDecoder::class)->conditionals($p['entries'], array_search($c['primary_position_1_bike'], $bikes, true));
        $max = -1.0;
        foreach ($bikes as $i => $bike) {
            foreach ($bikes as $j => $third) {
                if (count(array_unique([$c['primary_position_1_bike'], $bike, $third])) === 3) {
                    $max = max($max, $q['q2'][$i] * $q['q3_paths'][$i][$j]);
                }
            }
        }
        $this->assertSame($max, $c['joint_score']);
        $this->assertGreaterThanOrEqual($c['B_joint_score'], $c['joint_score']);
        $this->assertCount(3, array_unique([$c['primary_position_1_bike'], $c['primary_position_2_bike'], $c['primary_position_3_bike']]));
        foreach (['map_ordered_top3', 'map_ordered_probability', 'map_top3_set', 'map_top3_set_probability', 'top2_marginal_bikes', 'top3_marginal_bikes', 'expected_ndcg_top3'] as $key) {
            $this->assertSame($b[$key], $c[$key]);
        }
        $this->assertEqualsWithDelta(1.0, array_sum($q['q2']), 1e-12);
        foreach ($q['q3_paths'] as $path) {
            $this->assertEqualsWithDelta(1.0, array_sum($path), 1e-12);
        }
    }

    public static function populations(): array
    {
        return [[5], [7], [9]];
    }

    public function test_exact_maximum_keeps_b_and_hash_selects_only_other_equal_maxima(): void
    {
        $input = C1MarginalP23Fixture::race(n: 5);
        $saved = C1MarginalP23Fixture::prediction($input, tie: true);
        $c = app(Decoder::class)->select($saved['probabilities'], $saved['decision']);
        $this->assertSame($saved['decision']['primary_position_2_bike'], $c['primary_position_2_bike']);
        $this->assertSame($saved['decision']['primary_position_3_bike'], $c['primary_position_3_bike']);
        $this->assertTrue($c['decoder_tie_diagnostics']['PRIMARY_SECOND_THIRD']['B_retained']);
        $this->assertFalse($c['decoder_tie_diagnostics']['PRIMARY_SECOND_THIRD']['sha_tiebreak_used']);
        $u = ['POSITION_1' => [5.0, 0.0, 0.0, 0.0, 0.0], 'POSITION_2' => [0.0, 2.0, 2.0, -2.0, -2.0], 'POSITION_3' => array_fill(0, 5, 0.0)];
        $saved = C1MarginalP23Fixture::prediction($input, utilities: $u);
        $b = $saved['decision'];
        $b['primary_position_2_bike'] = 4;
        $b['primary_position_3_bike'] = 6;
        $c = app(Decoder::class)->select($saved['probabilities'], $b);
        $keys = [];
        foreach ([2, 3] as $second) {
            foreach ([2, 3, 4, 6] as $third) {
                if ($second !== $third) {
                    $keys[hash('sha256', Contract::TIE.'|2024|19|1|'.$second.'|'.$third)] = [$second, $third];
                }
            }
        }
        ksort($keys);
        $this->assertSame(reset($keys), [$c['primary_position_2_bike'], $c['primary_position_3_bike']]);
        $this->assertTrue($c['decoder_tie_diagnostics']['PRIMARY_SECOND_THIRD']['sha_tiebreak_used']);
        $this->assertSame(6, $c['second_third_tie_count']);
    }

    public function test_valid_underflow_zero_is_not_clipped_or_replaced_by_log_score(): void
    {
        $input = C1MarginalP23Fixture::race(n: 5);
        $saved = C1MarginalP23Fixture::prediction($input, utilities: [
            'POSITION_1' => [0.0, -1000.0, -1100.0, -1200.0, -1300.0],
            'POSITION_2' => [0.0, -1000.0, 0.0, -1100.0, -1200.0], 'POSITION_3' => [0.0, 0.0, -1000.0, -1100.0, -1200.0]]);
        $c = app(Decoder::class)->select($saved['probabilities'], $saved['decision']);
        $this->assertGreaterThan(0, $c['joint_zero_score_pairs']);
        $this->assertSame(1.0, $c['joint_score']);
        $this->assertSame(1, $c['primary_position_1_bike']);
    }

    public function test_sub_epsilon_strict_increase_is_not_collapsed_into_a_tie(): void
    {
        $input = C1MarginalP23Fixture::race(n: 5);
        $saved = C1MarginalP23Fixture::prediction($input, utilities: [
            'POSITION_1' => [2.0, 0.0, 0.0, 0.0, 0.0], 'POSITION_2' => [0.0, 1e-14, 0.0, 0.0, 0.0], 'POSITION_3' => array_fill(0, 5, 0.0)]);
        $b = $saved['decision'];
        $b['primary_position_2_bike'] = 3;
        $b['primary_position_3_bike'] = 4;
        $c = app(Decoder::class)->select($saved['probabilities'], $b);
        $this->assertSame(2, $c['primary_position_2_bike']);
        $this->assertGreaterThan($c['B_joint_score'], $c['joint_score']);
        $this->assertLessThan(1e-12, $c['joint_score'] - $c['B_joint_score']);
        $this->assertFalse($c['decoder_tie_diagnostics']['PRIMARY_SECOND_THIRD']['B_retained']);
    }

    #[DataProvider('invalidRows')]
    public function test_invalid_probability_identity_year_and_result_fields_fail_closed(string $kind): void
    {
        $input = C1MarginalP23Fixture::race();
        $row = Fixture::row($input);
        match ($kind) {
            'year' => $input['year'] = 2026,
            'bike' => $row['probabilities']['entries'][0]['bike'] = 9,
            'entry' => $row['probabilities']['entries'][0]['id']++,
            'order' => $row['probabilities']['entries'] = array_reverse($row['probabilities']['entries']),
            'duplicate' => $input['entries'][1]['id'] = $input['entries'][0]['id'],
            'duplicate_bike' => $input['entries'][1]['bike'] = $input['entries'][0]['bike'],
            'p' => $row['probabilities']['entries'][0]['position_1_probability'] = -1.0,
            'unknown' => $row['probabilities']['entries'][0]['utilities']['POSITION_2'] = null,
            'inf' => $row['probabilities']['entries'][0]['utilities']['POSITION_3'] = INF,
            'outcome' => $input['entries'][0]['rank'] = 1,
            'saved_winner' => $row['candidate']['primary_position_1_bike'] = 9,
            'distribution' => $row['probabilities']['probability_invariants']['ordered_joint_sum'] = 0.5,
        };
        $this->expectException(\Throwable::class);
        app(Decoder::class)->decode($input, $row);
    }

    public static function invalidRows(): array
    {
        return array_map(fn ($k) => [$k], ['year', 'bike', 'entry', 'order', 'duplicate', 'duplicate_bike', 'p', 'unknown', 'inf', 'outcome', 'saved_winner', 'distribution']);
    }

    public function test_saved_bundle_two_independent_runs_teacher_order_and_outcome_isolation(): void
    {
        if (BFixedWinnerJointP23TestProcess::delegate($this, $this->temporary)) {
            return;
        }
        $bundle = Fixture::make($this->temporary->path().'/source');
        $experiment = new Experiment($bundle['joint_sources'], app(Reader::class), app(Evaluation::class));
        $out = $this->temporary->path().'/result';
        $result = $experiment->execute($out, $bundle['joint_paths']);
        $this->assertTrue($result['winner_preservation']);
        $this->assertTrue($result['joint_probability_invariant']);
        $this->assertTrue(Files::json($out.'/reproduction.json')['identical']);
        $events = Files::json($out.'/run-01/access-order.json');
        $this->assertSame(['CANDIDATES_SEALED_VERIFIED', 'CANDIDATES_SEALED_VERIFIED', 'BOTH_YEARS_SEALS_COHORT_INVARIANTS_VERIFIED',
            'OUTCOME_RELEASE_AUTHORIZED', 'OUTCOME_RELEASE_AUTHORIZED'], array_column($events, 'event'));
        $other = Fixture::make($this->temporary->path().'/changed', 2024);
        $first = JsonlArtifact::read($bundle['joint_source']['paths'][2024]['b_predictions']);
        $second = JsonlArtifact::read($other['joint_source']['paths'][2024]['b_predictions']);
        $this->assertSame(array_column(iterator_to_array($first), 'candidate'), array_column(iterator_to_array($second), 'candidate'));
        $decisions = [];
        foreach ([$bundle, $other] as $i => $s) {
            $seen = Reader::identities($this->temporary->path().'/isolation-'.$i.'.sqlite');
            $audit = [];
            $decisions[] = iterator_to_array(app(Reader::class)->rows($s['joint_source'], 2024, $seen, $audit));
        }
        $this->assertSame($decisions[0][0]['C'], $decisions[1][0]['C']);
        $this->assertSame(0, Files::json($out.'/run-01/invariants.json')[2024]['P1_changes']);
        $this->assertFileExists($out.'/run-01/C-B-2024.bin');
        $manifest = Files::json($out.'/manifest.json');
        $this->assertArrayHasKey('run-01/C-B-2024.bin', $manifest['files']);
        $this->assertArrayHasKey('run-01/identities.sqlite', $manifest['files']);
    }

    public function test_labels_rejected_before_both_years_seals(): void
    {
        $this->expectExceptionMessage('before both prediction seals');
        iterator_to_array(app(Reader::class)->labelled([], 2024, []));
    }

    #[DataProvider('tampering')]
    public function test_source_manifest_and_parent_drift_rejected(string $kind): void
    {
        if (BFixedWinnerJointP23TestProcess::delegate($this, $this->temporary)) {
            return;
        }
        $b = Fixture::make($this->temporary->path().'/source');
        $path = match ($kind) {
            'manifest' => $b['b'].'/manifest.json', 'prediction' => $b['joint_source']['paths'][2024]['b_predictions'],
            'input' => $b['joint_source']['paths'][2025]['input'], 'model' => $b['joint_source']['paths'][2024]['c2_model'],
        };
        file_put_contents($path, ' ', FILE_APPEND);
        $this->expectException(\RuntimeException::class);
        $b['joint_sources']->open(...$b['joint_paths']);
    }

    public static function tampering(): array
    {
        return [['manifest'], ['prediction'], ['input'], ['model']];
    }

    public function test_global_race_reappearance_is_rejected_even_when_ids_are_nonmonotonic(): void
    {
        if (BFixedWinnerJointP23TestProcess::delegate($this, $this->temporary)) {
            return;
        }
        $bundle = Fixture::make($this->temporary->path().'/source');
        $reader = app(Reader::class);
        $seen = Reader::identities($this->temporary->path().'/seen.sqlite');
        $audit = [];
        $rows = iterator_to_array($reader->rows($bundle['joint_source'], 2024, $seen, $audit));
        $this->assertCount(2, $rows);
        $this->assertGreaterThan($rows[1]['race_id'], $rows[0]['race_id']);
        $this->expectException(\PDOException::class);
        iterator_to_array($reader->rows($bundle['joint_source'], 2024, $seen, $audit));
    }

    public function test_source_end_drift_blocks_teacher_release(): void
    {
        if (BFixedWinnerJointP23TestProcess::delegate($this, $this->temporary)) {
            return;
        }
        $bundle = Fixture::make($this->temporary->path().'/source');
        $source = $bundle['joint_source'];
        // Non-outcome model metadata drift is discovered before opening either teacher.
        file_put_contents($source['paths'][2025]['c2_model'], ' ', FILE_APPEND);
        $directory = Files::directory($this->temporary->path().'/unpublished');
        try {
            app(Experiment::class)->run($source, $directory);
            $this->fail('End source drift accepted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('hash/size mismatch', $e->getMessage());
        }
        $this->assertFileDoesNotExist($directory.'/access-order.json');
        $this->assertFileDoesNotExist($directory.'/evaluation.json');
        $this->assertFileDoesNotExist($directory.'/COMPLETE.json');
    }

    public function test_synthetic_source_guard_rejects_outside_paths_and_non_testing_environment(): void
    {
        if (BFixedWinnerJointP23TestProcess::delegate($this, $this->temporary)) {
            return;
        }
        $before = scandir($this->temporary->path());
        try {
            BFixedWinnerJointP23TestProcess::run(self::class.'::test_source_end_drift_blocks_teacher_release', clone $this->temporary);
            $this->fail('Unregistered process owner accepted.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Recorded test-process identity changed or owner is unregistered.', $error->getMessage());
        }
        $this->assertSame($before, scandir($this->temporary->path()));
        $bundle = Fixture::make($this->temporary->path().'/source');
        $outside = CompositionResultTemporaryDirectory::create('/home/shinya/Desktop');
        try {
            $sources = new Sources(str_repeat('0', 64));
            try {
                $sources->open($outside->path(), $outside->path());
                $this->fail('Outside synthetic path accepted.');
            } catch (\RuntimeException $error) {
                $this->assertSame('Synthetic pins are testing-only.', $error->getMessage());
            }
            $this->app->detectEnvironment(fn () => 'production');
            $this->expectExceptionMessage('Synthetic pins are testing-only.');
            $sources->open($bundle['input'], $bundle['baseline']);
        } finally {
            $outside->retire($outside->path(), 'TEST_COMPLETE');
        }
    }

    public function test_command_plan_is_outcome_free_and_training_free(): void
    {
        $this->artisan('keirin:b:fixed-winner-joint-p23 plan')->assertExitCode(0);
        $this->assertSame(0, Contract::plan()['training_count']);
        $this->assertFalse(Contract::plan()['formal_adoption']);
        $this->assertSame('FORBIDDEN', Contract::plan()['2026_access']);
    }

    #[DataProvider('excludedOutcomes')]
    public function test_primary_exact_and_hit3_exclusions_keep_metric_contract(string $kind): void
    {
        $input = C1MarginalP23Fixture::race(n: 5);
        $row = Fixture::row($input);
        $context = $input;
        foreach ($context['entries'] as $i => &$entry) {
            $entry['rank'] = $i + 1;
            $entry['status'] = 'FINISHED';
        }
        unset($entry);
        if ($kind === 'tie') {
            $context['entries'][1]['rank'] = 1;
            $context['entries'][0]['status'] = $context['entries'][1]['status'] = 'TIED';
        } else {
            $context['entries'][1]['status'] = $kind;
            $context['entries'][1]['rank'] = null;
        }
        $metric = app(Bt03e05MetricEvaluator::class)->raceComparison($context, app(Decoder::class)->decode($input, $row));
        $this->assertSame(0.0, $metric['candidate']['POSITION_HIT_RATE_AT_3']['denominator']);
        $this->assertSame(0.0, Evaluation::primary($metric)['candidate']['EXACT_ORDERED_TOP3_RATE']['denominator']);
    }

    public static function excludedOutcomes(): array
    {
        return [['tie'], ['DISQUALIFIED'], ['DID_NOT_FINISH'], ['DID_NOT_START']];
    }

    public function test_bootstrap_numeric_source_is_frozen_and_spools_are_retained(): void
    {
        $original = file_get_contents(app_path('Domain/Keirin/Backtest/Calculators/Bt03e05PairedBootstrap.php'));
        $retained = file_get_contents(app_path('Domain/Keirin/Backtest/Experiments/Stat35Trend6P1/PairedBootstrap.php'));
        $retained = str_replace(['namespace App\\Domain\\Keirin\\Backtest\\Experiments\\Stat35Trend6P1;',
            'use App\\Domain\\Keirin\\Backtest\\Calculators\\Bt03e05MetricEvaluator;\n',
            'use App\\Domain\\Keirin\\Backtest\\Calculators\\DeterministicRandom;\n',
            'use App\\Domain\\Keirin\\Backtest\\Calculators\\Type7Quantile;\n'],
            ['namespace App\\Domain\\Keirin\\Backtest\\Calculators;', '', '', ''], $retained);
        // Normalize only namespace/import/type spelling; numerical method must remain exact.
        $method = fn ($s) => substr($s, strpos($s, 'public function evaluate'));
        $this->assertSame($method($original), str_replace('MetricContributionSpool', 'Bt03e05MetricContributionSpool', $method($retained)));
        $years = [];
        foreach ([2024, 2025] as $year) {
            $path = $this->temporary->path().'/'.$year.'.bin';
            $years[$year] = new MetricContributionSpool($path);
            $input = C1MarginalP23Fixture::race($year);
            foreach ($input['entries'] as $i => &$e) {
                $e['rank'] = $i + 1;
                $e['status'] = 'FINISHED';
            }
            unset($e);
            $comparison = app(Bt03e05MetricEvaluator::class)->raceComparison($input, C1MarginalP23Fixture::prediction(C1MarginalP23Fixture::race($year))['decision']);
            $years[$year]->append($comparison);
            $years[$year]->seal();
        }
        $bootstrap = app(PairedBootstrap::class);
        $this->assertSame($bootstrap->evaluate($years), $bootstrap->evaluate($years));
        unset($years);
        $this->assertFileExists($this->temporary->path().'/2024.bin');
    }

    public function test_independent_128m_stream_is_over_one_hundred_mib(): void
    {
        $p = new Process([PHP_BINARY, '-d', 'memory_limit=128M', base_path('tests/Support/b-fixed-winner-joint-p23-memory.php'), $this->temporary->path()], base_path());
        $p->setTimeout(240);
        $p->run();
        $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput().$p->getOutput());
        $r = Files::json($this->temporary->path().'/measurement.json');
        $this->assertSame('128M', $r['limit']);
        $this->assertNotSame(getmypid(), $r['pid']);
        $this->assertGreaterThan(100 * 1024 * 1024, $r['input_bytes']);
        $this->assertSame(18000, $r['races']);
        $this->assertSame(126000, $r['entries']);
        $this->assertSame(0, $r['P1_changes']);
        $this->assertLessThan(128 * 1024 * 1024, $r['peak']);
    }
}
