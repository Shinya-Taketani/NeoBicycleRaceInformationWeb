<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e03ProbabilityScorer;
use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e06WinnerConditionedDecoder;
use App\Domain\Keirin\Backtest\Calculators\DeterministicRandom;
use App\Domain\Keirin\Backtest\DTO\Bt03e03FitResultDto;
use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use App\Domain\Keirin\Backtest\Exceptions\Bt03e03OptimizerNonConvergenceException;
use App\Domain\Keirin\Backtest\Experiments\C1PositionLambda\Contract;
use App\Domain\Keirin\Backtest\Experiments\C1PositionLambda\Fit;
use App\Domain\Keirin\Backtest\Experiments\C1PositionLambda\LossSpool;
use App\Domain\Keirin\Backtest\Experiments\C1PositionLambda\Optimizer;
use App\Domain\Keirin\Backtest\Experiments\C1PositionLambda\ProbabilityScorer;
use App\Domain\Keirin\Backtest\Experiments\C1PositionLambda\Selector;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Layout;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Objective;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Optimizer as OldOptimizer;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class C1PositionLambdaNumericsTest extends TestCase
{
    public function test_single_position_math_is_exactly_the_frozen_solver_with_same_initial_vector(): void
    {
        $layout = $this->layout();
        $source = fn () => [$this->race(7)];
        $old = new OldOptimizer(new Objective);
        $new = new Optimizer(new Objective);
        $initial = $layout->project([1.0, -2.0, ...array_fill(0, $layout->size() - 2, 0.0)]);
        foreach (Bt03e03Contract::POSITIONS as $p) {
            foreach ([1.0, 0.1] as $lambda) {
                $oldResult = $newResult = null;
                try {
                    $oldResult = (new \ReflectionMethod($old, 'fitPosition'))->invoke($old, $source, $layout, $lambda, $p, $initial);
                } catch (Bt03e03OptimizerNonConvergenceException $e) {
                    $oldResult = $e->diagnostics;
                }
                try {
                    $newResult = $new->fitPosition($source, $layout, $lambda, $p, $initial);
                } catch (Bt03e03OptimizerNonConvergenceException $e) {
                    $newResult = $e->diagnostics;
                }
                $this->assertSame($oldResult, $newResult);
            }
        }
    }

    #[DataProvider('scorerCases')]
    public function test_same_coefficients_preserve_all_probability_bits(int $n, bool $extreme): void
    {
        $layout = $this->layout();
        $race = $this->race($n);
        foreach ($race['entries'] as $i => &$entry) {
            $entry['anchor'] = $extreme ? ($i - 3) * 500.0 : 0.0;
        }
        unset($entry);
        $coefficients = [];
        foreach (Bt03e03Contract::POSITIONS as $i => $p) {
            $coefficients[$p] = $layout->project([($i + 1) * 0.3, -0.1, ...array_fill(0, $layout->size() - 2, 0.0)]);
        }
        $old = new Bt03e03FitResultDto(1.0, $coefficients, [], [], [], []);
        $new = new Fit(array_combine(Bt03e03Contract::POSITIONS, [1.0, 0.1, 0.01]), $coefficients, [], [], [], [], []);
        $expected = (new Bt03e03ProbabilityScorer)->predict($race, $old);
        $this->assertSame($expected, (new ProbabilityScorer)->predict($race, $new));
        foreach (['position_1_probability', 'position_2_probability', 'position_3_probability'] as $p) {
            $this->assertEqualsWithDelta(1.0, array_sum(array_column($expected['entries'], $p)), 1e-12);
        }
    }

    public static function scorerCases(): array
    {
        return [[5, false], [7, false], [9, false], [5, true], [7, true], [9, true]];
    }

    public function test_exact_ties_keep_decoder_decisions_and_metric_values_and_duplicate_bikes_fail(): void
    {
        $race = $this->race(7);
        $coefs = array_fill_keys(Bt03e03Contract::POSITIONS, array_fill(0, $this->layout()->size(), 0.0));
        $fit = new Fit(array_fill_keys(Bt03e03Contract::POSITIONS, 1.0), $coefs, [], [], [], [], []);
        $expected = (new Bt03e03ProbabilityScorer)->predict($race, new Bt03e03FitResultDto(1.0, $coefs, [], [], [], []));
        $actual = (new ProbabilityScorer)->predict($race, $fit);
        $decoder = new Bt03e06WinnerConditionedDecoder(new Bt03e03ProbabilityScorer, new CanonicalHasher);
        $this->assertSame($decoder->decode($expected), $decoder->decode($actual));
        $metrics = new Bt03e05MetricEvaluator;
        $this->assertSame($metrics->raceComparison($race, $decoder->decode($expected)), $metrics->raceComparison($race, $decoder->decode($actual)));
        $race['entries'][1]['bike'] = 1;
        $this->expectExceptionMessage('bike numbers');
        (new ProbabilityScorer)->predict($race, $fit);
    }

    public function test_bootstrap_zero_eligible_draw_is_not_redrawn(): void
    {
        $spool = $this->spool('POSITION_1', [[1 => 1.0], [1 => null]], ['1']);
        $this->expectExceptionMessage('eligible denominator was zero');
        (new Selector)->select([2023 => $spool], 'POSITION_1');
    }

    public function test_selector_is_position_independent_and_uses_year_equal_common_candidates(): void
    {
        $spools = [];
        foreach (Bt03e03Contract::POSITIONS as $i => $p) {
            $rows = [];
            foreach (Bt03e03Contract::LAMBDA_GRID as $j => $lambda) {
                $rows[LossSpool::key($lambda)] = (float) abs($j - ($i + 2));
            }
            $spools[$p] = $this->spool($p, [$rows, $rows]);
            $selection = (new Selector)->select([2023 => $spools[$p]], $p);
            $this->assertSame(Bt03e03Contract::LAMBDA_GRID[$i + 2], $selection['selected_lambda']);
            Selector::validate($selection, $p);
        }
        $first = (new Selector)->select([2023 => $spools['POSITION_1']], 'POSITION_1');
        $other = $this->spool('POSITION_2', [[0 => 99.0, '1' => 0.0]], ['0', '1']);
        (new Selector)->select([2023 => $other], 'POSITION_2');
        $this->assertSame($first, (new Selector)->select([2023 => $spools['POSITION_1']], 'POSITION_1'));
        $a = $this->spool('POSITION_1', [[0 => 0.0, '0.10000000000000001' => 0.1, 1 => 8.0]], ['0', '0.10000000000000001', '1']);
        $b = $this->spool('POSITION_1', array_fill(0, 7, [0 => 10.0, 1 => 2.0]), ['0', '1']);
        $choice = (new Selector)->select([2023 => $a, 2024 => $b], 'POSITION_1');
        $this->assertSame(['0', '1'], $choice['eligible_lambda_keys']);
        $this->assertSame([0 => 5.0, 1 => 5.0], $choice['point_losses']);
        $this->assertSame(0.0, $choice['lambda_best']);
        $this->assertSame(1.0, $choice['selected_lambda']);
    }

    public function test_one_se_exact_tie_inclusive_boundary_and_maximum_lambda(): void
    {
        $keys = ['0', '0.10000000000000001', '1'];
        $se = [0 => 0.5, '0.10000000000000001' => 0.0, 1 => 0.0];
        $this->assertSame(['selected_lambda' => 1.0, 'lambda_best' => 0.0, 'one_se_threshold' => 1.5],
            Selector::choose($keys, [0 => 1.0, '0.10000000000000001' => 1.0, 1 => 1.5], $se));
        $this->assertSame(0.1, Selector::choose($keys, [0 => 1.0, '0.10000000000000001' => 1.0, 1 => 1.500000000000001], $se)['selected_lambda']);
    }

    public function test_bootstrap_sd_matches_independent_weighted_null_reference_without_sqrt_iterations(): void
    {
        $values = array_map(fn ($i) => $i === 0 ? null : (float) ($i % 7), range(0, 99));
        $spool = $this->spool('POSITION_3', array_map(fn ($v) => [1 => $v], $values), ['1']);
        $actual = (new Selector)->select([2023 => $spool], 'POSITION_3');
        $random = new DeterministicRandom(20260812);
        $samples = [];
        for ($i = 0; $i < 2000; $i++) {
            $sum = 0.0;
            $count = 0;
            $weights = array_fill(0, 100, 0);
            for ($draw = 0; $draw < 100; $draw++) {
                $weights[$random->integer(100)]++;
            }
            foreach ($values as $index => $v) {
                if ($v !== null) {
                    $sum += $weights[$index] * $v;
                    $count += $weights[$index];
                }
            }
            $samples[] = $sum / $count;
        }
        $mean = array_sum($samples) / 2000;
        $sd = sqrt(array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $samples)) / 1999);
        $this->assertEqualsWithDelta($sd, $actual['standard_errors'][1], 1e-14);
        $this->assertGreaterThan(0.1, $actual['standard_errors'][1]);
        $this->assertSame(99, $actual['denominator_audit'][2023]['eligible']);
        $this->assertSame(1, $actual['denominator_audit'][2023]['excluded']);
    }

    #[DataProvider('badLosses')]
    public function test_bad_masks_nonfinite_and_zero_denominators_fail_closed(string $kind): void
    {
        $this->expectException(RuntimeException::class);
        match ($kind) {
            'empty' => new LossSpool(sys_get_temp_dir().'/loss-'.bin2hex(random_bytes(8)), 'POSITION_1', []),
            'mask' => $this->spool('POSITION_1', [[0 => null, 1 => 0.0]], ['0', '1']),
            'finite' => $this->spool('POSITION_1', [[1 => INF]], ['1']),
            'negative' => $this->spool('POSITION_1', [[1 => -1.0]], ['1']),
            'zero' => (new Selector)->select([2023 => $this->spool('POSITION_1', [[1 => null]], ['1'])], 'POSITION_1'),
            'intersection' => (new Selector)->select([2023 => $this->spool('POSITION_1', [[0 => 1.0]], ['0']), 2024 => $this->spool('POSITION_1', [[1 => 1.0]], ['1'])], 'POSITION_1'),
        };
    }

    public static function badLosses(): array
    {
        return array_map(fn ($v) => [$v], ['empty', 'mask', 'finite', 'negative', 'zero', 'intersection']);
    }

    private function spool(string $position, array $rows, ?array $available = null): LossSpool
    {
        $spool = new LossSpool(sys_get_temp_dir().'/c1-loss-'.bin2hex(random_bytes(8)), $position, $available ?? array_map(LossSpool::key(...), Bt03e03Contract::LAMBDA_GRID));
        foreach ($rows as $row) {
            $spool->append($row);
        }
        $spool->seal();

        return $spool;
    }

    private function layout(): Layout
    {
        $bins = [];
        foreach (Contract::features() as $i => $code) {
            $bins[$code] = [new EffectBinDto(1, 'CATEGORY', null, null, 'a', $i === 0 ? 4 : 5)];
            if ($i === 0) {
                $bins[$code][] = new EffectBinDto(2, 'CATEGORY', null, null, 'b', 1);
            }
        }

        return new Layout($bins);
    }

    private function race(int $n): array
    {
        $entries = [];
        for ($i = 0; $i < $n; $i++) {
            $entries[] = ['id' => 100 + $i, 'bike' => $i + 1, 'raw' => 100.0, 'stat01_rank' => 1, 'anchor' => 0.0,
                'rank' => $i + 1, 'status' => 'FINISHED', 'bins' => [$i === 0 ? 1 : 0, ...array_fill(0, 15, null)]];
        }

        return ['year' => 2023, 'race_id' => 1, 'entries' => $entries];
    }
}
