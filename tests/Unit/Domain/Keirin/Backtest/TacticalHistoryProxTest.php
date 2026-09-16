<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use App\Domain\Keirin\Backtest\Exceptions\Bt03e03OptimizerNonConvergenceException;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Layout;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Objective;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Optimizer;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use PHPUnit\Framework\TestCase;

class TacticalHistoryProxTest extends TestCase
{
    public function test_biased_support_prox_is_the_constrained_euclidean_minimum(): void
    {
        $layout = $this->layout();
        $v = array_fill(0, $layout->size(), 0.7);
        $v[0] = 2.0;
        $v[1] = -1.0;
        $step = 0.4;
        $lambda = 1.0;
        $prox = (new Objective)->groupProx($layout, $v, $step, $lambda);
        // w=(.8,.2) makes every feasible first group (t,-4t).
        $scalarObjective = fn (float $t): float => (($t - 2.0) ** 2 + (-4 * $t + 1.0) ** 2) / (2 * $step)
            + $lambda * sqrt(17 / 2) * abs($t) / 12;
        $optimum = $this->minimum($scalarObjective);
        $this->assertEqualsWithDelta($optimum, $prox[0], 1e-7);
        $this->assertEqualsWithDelta(-4 * $optimum, $prox[1], 1e-7);
        foreach ($layout->weightedMeans($prox) as $mean) {
            $this->assertEqualsWithDelta(0.0, $mean, 1e-14);
        }
        $projected = $layout->project($v);
        $this->assertEqualsWithDelta(6 / 17, $projected[0], 1e-14);
        $this->assertEqualsWithDelta(-24 / 17, $projected[1], 1e-14);
        $normal = array_fill(0, $layout->size(), 1.0);
        $normal[0] = 0.8;
        $normal[1] = 0.2;
        foreach ((new Objective)->groupProx($layout, $normal, $step, $lambda) as $value) {
            $this->assertEqualsWithDelta(0.0, $value, 1e-14);
        }
        $this->assertSame(array_fill(0, $layout->size(), 0.0), (new Objective)->groupProx($layout, $v, 1000.0, 1.0));
    }

    public function test_fit_with_biased_support_and_missing_values_matches_independent_scalar_optima(): void
    {
        $layout = $this->layout();
        $entries = [];
        foreach ([0, 1, null, 0, 0, 0] as $offset => $index) {
            $entries[] = ['bike' => $offset + 1, 'rank' => $offset + 1, 'status' => 'FINISHED', 'anchor' => 0.0,
                'bins' => [$index, ...array_fill(0, 11, null)]];
        }
        $race = ['entries' => $entries];
        $warm = array_fill_keys(Bt03e03Contract::POSITIONS, array_fill(0, $layout->size(), 0.3));
        $fit = (new Optimizer(new Objective))->fit(fn () => [$race], $layout, 1.0, $warm);
        foreach (Bt03e03Contract::POSITIONS as $offset => $position) {
            $a = array_slice([1, -4, 0, 1, 1, 1], $offset);
            $independent = function (float $t) use ($a, $layout): float {
                $utilities = array_map(fn ($x) => $x * $t, $a);
                $max = max($utilities);
                $nll = $max + log(array_sum(array_map(fn ($x) => exp($x - $max), $utilities))) - $utilities[0];

                return $nll + 17 * $t * $t / $layout->size() + sqrt(17 / 2) * abs($t) / 12;
            };
            $t = $this->minimum($independent);
            $this->assertEqualsWithDelta($t, $fit->coefficients[$position][0], 2e-7);
            $this->assertEqualsWithDelta(-4 * $t, $fit->coefficients[$position][1], 8e-7);
            $this->assertEqualsWithDelta($independent($t), $fit->objectives[$position], 1e-12);
            $this->assertLessThanOrEqual(Bt03e03Contract::CONVERGENCE_TOLERANCE, $fit->diagnostics[$position]['prox_gradient_mapping_max']);
            $this->assertLessThanOrEqual(8 * PHP_FLOAT_EPSILON * max(1.0, $fit->objectives[$position]), $fit->diagnostics[$position]['maximum_accepted_objective_increase']);
            foreach ($layout->weightedMeans($fit->coefficients[$position]) as $mean) {
                $this->assertEqualsWithDelta(0.0, $mean, 1e-14);
            }
        }
    }

    private function layout(): Layout
    {
        $bins = [];
        foreach (Bt03e03Contract::STAT_CODES as $offset => $code) {
            $bins[$code] = [new EffectBinDto(1, 'CATEGORY', null, null, 'a', $offset === 0 ? 4 : 5)];
            if ($offset === 0) {
                $bins[$code][] = new EffectBinDto(2, 'CATEGORY', null, null, 'b', 1);
            }
        }

        return new Layout($bins);
    }

    public function test_restarts_keep_the_constrained_objective_monotone(): void
    {
        $bins = [];
        foreach (Bt03e03Contract::STAT_CODES as $code) {
            $bins[$code] = [new EffectBinDto(1, 'CATEGORY', null, null, 'a', 4), new EffectBinDto(2, 'CATEGORY', null, null, 'b', 1)];
        }
        $layout = new Layout($bins);
        $entries = [];
        foreach ([0, 1, null, 0, 0, 0] as $i => $bin) {
            $entries[] = ['bike' => $i + 1, 'rank' => $i + 1, 'status' => 'FINISHED', 'anchor' => 0.0,
                'bins' => array_map(fn ($group) => $bin === null ? null : 2 * $group + $bin, range(0, 11))];
        }
        $source = fn () => [['entries' => $entries]];
        $fit = (new Optimizer(new Objective))->fit($source, $layout, 1.0);
        foreach ($fit->diagnostics as $diagnostics) {
            $this->assertGreaterThan(0, $diagnostics['monotone_restart_count']);
            $this->assertSame($diagnostics['monotone_restart_count'], $diagnostics['restart_step_retention_count']);
            $this->assertLessThanOrEqual(8 * PHP_FLOAT_EPSILON * max(1.0, $diagnostics['final_objective']), $diagnostics['maximum_accepted_objective_increase']);
            $this->assertLessThanOrEqual(1e-7, $diagnostics['prox_gradient_mapping_max']);
        }
        try {
            (new Optimizer(new Objective))->fit($source, $layout, 0.0);
            $this->fail('Unregularized separable data must not be marked converged at the fixed budget.');
        } catch (Bt03e03OptimizerNonConvergenceException $exception) {
            $this->assertSame(200, $exception->diagnostics['accepted_update_count']);
            $this->assertSame('NUMERICALLY_NON_CONVERGED', $exception->diagnostics['status']);
            $this->assertGreaterThan(1e-7, $exception->diagnostics['prox_gradient_mapping_max']);
        }
    }

    private function minimum(callable $objective): float
    {
        $left = -3.0;
        $right = 3.0;
        for ($i = 0; $i < 120; $i++) {
            $a = $left + ($right - $left) / 3;
            $b = $right - ($right - $left) / 3;
            if ($objective($a) < $objective($b)) {
                $right = $b;
            } else {
                $left = $a;
            }
        }

        return ($left + $right) / 2;
    }
}
