<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e02FistaOptimizer;
use App\Domain\Keirin\Backtest\Calculators\Bt03e02PairwiseObjective;
use App\Domain\Keirin\Backtest\Calculators\Bt03e02ParameterLayout;
use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use App\Domain\Keirin\Backtest\Enums\Bt03e02CandidateStatus;
use App\Domain\Keirin\Backtest\Exceptions\Bt03e02OptimizerNonConvergenceException;
use App\Domain\Keirin\Backtest\Services\Bt03e02Contract;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class Bt03e02OptimizerTest extends TestCase
{
    public function test_fista_converges_on_a_known_separable_signal_and_is_deterministic(): void
    {
        $layout = $this->layout();
        $source = fn (): array => array_map(fn (int $raceId): array => $this->race($raceId), range(1, 12));
        $objective = new Bt03e02PairwiseObjective;
        $optimizer = new Bt03e02FistaOptimizer($objective);
        $zero = array_fill(0, $layout->size(), 0.0);

        $before = $objective->loss($source, $layout, $zero, 'IS_TOP2');
        $first = $optimizer->fit($source, $layout, 0.1);
        $second = $optimizer->fit($source, $layout, 0.1);
        $after = $objective->loss($source, $layout, $first->coefficients['IS_TOP2'], 'IS_TOP2');

        $this->assertLessThan($before, $after);
        $this->assertSame($first->coefficients, $second->coefficients);
        $this->assertSame($first->iterations, $second->iterations);
        $this->assertGreaterThan($first->coefficients['IS_TOP2'][0], $first->coefficients['IS_TOP2'][1]);
        foreach ($first->coefficients as $coefficients) {
            foreach ($layout->weightedMeans($coefficients) as $mean) {
                $this->assertEqualsWithDelta(0.0, $mean, 2e-15);
            }
        }
    }

    public function test_stat31_can_represent_a_non_monotonic_shape(): void
    {
        $layout = $this->layout(3);
        $stat31Start = 8 * 3;
        $coefficients = array_fill(0, $layout->size(), 0.0);
        $coefficients[$stat31Start] = -1.0;
        $coefficients[$stat31Start + 1] = 2.0;
        $coefficients[$stat31Start + 2] = -1.0;

        $projected = $layout->project($coefficients);

        $this->assertGreaterThan($projected[$stat31Start], $projected[$stat31Start + 1]);
        $this->assertGreaterThan($projected[$stat31Start + 2], $projected[$stat31Start + 1]);
        $this->assertEqualsWithDelta($projected[$stat31Start], $projected[$stat31Start + 2], 1e-15);
    }

    public function test_regularization_path_is_strong_to_weak_and_warm_starts_from_the_previous_converged_candidate(): void
    {
        $path = $this->optimizer()->fitPath($this->source(), $this->layout());

        $this->assertSame([1.0, 0.1, 0.01, 0.001, 0.0001, 0.00001, 0.000001, 0.0], $path['fit_order']);
        $this->assertSame([0.0, 1e-6, 1e-5, 1e-4, 1e-3, 1e-2, 1e-1, 1.0], Bt03e02Contract::LAMBDA_GRID);
        $canonicalKeys = [];
        foreach (Bt03e02Contract::LAMBDA_GRID as $lambda) {
            $canonicalKeys[sprintf('%.17g', $lambda)] = true;
        }
        $this->assertSame(array_keys($canonicalKeys), array_keys($path['candidate_statuses']));

        $previousConverged = null;
        foreach ($path['fit_order'] as $lambda) {
            $candidate = $path['candidate_statuses'][sprintf('%.17g', $lambda)];
            $this->assertSame($previousConverged, $candidate['warm_start_from_lambda']);
            if ($candidate['status'] === Bt03e02CandidateStatus::Converged->value) {
                $previousConverged = $lambda;
            }
        }

        $strong = $this->optimizer()->fit($this->source(), $this->layout(), 1.0);
        $explicitWarm = $this->optimizer()->fit($this->source(), $this->layout(), 0.1, $strong->coefficients);
        $this->assertSame($strong->coefficients, $path['fits'][1]->coefficients);
        $this->assertSame($explicitWarm->coefficients, $path['fits']['0.10000000000000001']->coefficients);
    }

    public function test_separable_unregularized_candidate_is_audited_without_aborting_the_grid(): void
    {
        $path = $this->optimizer()->fitPath($this->source(), $this->layout());
        $unregularized = $path['candidate_statuses']['0'];

        $this->assertSame(Bt03e02CandidateStatus::NumericallyNonConverged->value, $unregularized['status']);
        $this->assertSame(Bt03e02CandidateStatus::Converged->value, $path['candidate_statuses']['0.10000000000000001']['status']);
        $this->assertArrayNotHasKey('0', $path['fits']);
        $this->assertArrayHasKey('0.10000000000000001', $path['fits']);
        $diagnostics = $unregularized['channels']['IS_WIN'];
        $this->assertSame(Bt03e02Contract::MAX_ITERATIONS, $diagnostics['iteration']);
        foreach ([
            'lambda', 'channel', 'iteration', 'max_iterations', 'final_objective', 'previous_objective',
            'relative_objective_change', 'maximum_coefficient_change', 'current_step',
            'coefficient_l2_norm', 'maximum_absolute_coefficient', 'eligible_race_count',
            'excluded_race_count', 'line_search_steps_last_iteration',
        ] as $key) {
            $this->assertArrayHasKey($key, $diagnostics);
            if (is_float($diagnostics[$key])) {
                $this->assertTrue(is_finite($diagnostics[$key]), $key);
            }
        }
    }

    public function test_regularization_path_status_coefficients_iterations_and_diagnostics_are_deterministic(): void
    {
        $first = $this->optimizer()->fitPath($this->source(), $this->layout());
        $second = $this->optimizer()->fitPath($this->source(), $this->layout());

        $this->assertSame($first['candidate_statuses'], $second['candidate_statuses']);
        $this->assertSame(
            array_map(static fn ($fit): array => $fit->coefficients, $first['fits']),
            array_map(static fn ($fit): array => $fit->coefficients, $second['fits']),
        );
        $this->assertSame(
            array_map(static fn ($fit): array => $fit->iterations, $first['fits']),
            array_map(static fn ($fit): array => $fit->iterations, $second['fits']),
        );
    }

    public function test_selected_outer_lambda_non_convergence_is_not_replaced_by_another_candidate(): void
    {
        $this->expectException(Bt03e02OptimizerNonConvergenceException::class);

        $this->optimizer()->fit($this->source(), $this->layout(), 0.0);
    }

    public function test_invalid_training_input_is_not_downgraded_to_candidate_non_convergence(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no pairwise-eligible races');

        $this->optimizer()->fitPath(fn (): array => [], $this->layout());
    }

    private function optimizer(): Bt03e02FistaOptimizer
    {
        return new Bt03e02FistaOptimizer(new Bt03e02PairwiseObjective);
    }

    /** @return callable():array<int,array<string,mixed>> */
    private function source(): callable
    {
        return fn (): array => array_map(fn (int $raceId): array => $this->race($raceId), range(1, 12));
    }

    private function layout(int $binCount = 2): Bt03e02ParameterLayout
    {
        $bins = [];
        foreach (Bt03e02Contract::STAT_CODES as $statCode) {
            for ($bin = 1; $bin <= $binCount; $bin++) {
                $bins[$statCode][] = new EffectBinDto($bin, 'CATEGORY', null, null, (string) ($bin - 1), 1);
            }
        }

        return new Bt03e02ParameterLayout($bins);
    }

    /** @return array<string, mixed> */
    private function race(int $raceId): array
    {
        $entries = [];
        for ($rank = 1; $rank <= 5; $rank++) {
            $bins = array_fill(0, 12, null);
            $bins[0] = $rank <= 2 ? 1 : 0;
            $entries[] = [
                'id' => $raceId * 10 + $rank,
                'bike' => $rank,
                'raw' => 100.0,
                'stat01_rank' => $rank,
                'anchor' => 0.0,
                'bins' => $bins,
                'labels' => [$rank === 1, $rank <= 2, $rank <= 3],
                'rank' => $rank,
                'status' => 'FINISHED',
            ];
        }

        return ['year' => 2023, 'race_id' => $raceId, 'entries' => $entries];
    }
}
