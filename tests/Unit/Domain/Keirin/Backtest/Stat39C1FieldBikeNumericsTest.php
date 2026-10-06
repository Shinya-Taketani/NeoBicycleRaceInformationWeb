<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e03ProbabilityScorer;
use App\Domain\Keirin\Backtest\DTO\Bt03e03FitResultDto;
use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use App\Domain\Keirin\Backtest\Experiments\Stat39C1FieldBikeComparison\Contract;
use App\Domain\Keirin\Backtest\Experiments\Stat39C1FieldBikeComparison\Layout;
use App\Domain\Keirin\Backtest\Experiments\Stat39C1FieldBikeComparison\Objective;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Layout as OldLayout;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Objective as OldObjective;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Optimizer as OldOptimizer;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use PHPUnit\Framework\TestCase;

class Stat39C1FieldBikeNumericsTest extends TestCase
{
    public function test_zero_extra_coefficients_preserve_frozen_sixteen_feature_utilities_probabilities_and_nll(): void
    {
        $bins = $this->bins(true);
        $layout = new Layout($bins, 5);
        $old = new OldLayout(array_slice($bins, 0, 16));
        $this->assertSame($old->size() + 2, $layout->size());
        $this->assertSame($old->smoothEdges(), $layout->smoothEdges());
        $v = array_fill(0, $old->size(), 0.7);
        $v[0] = 2.0;
        $v[1] = -1.0;
        $extra = [...$v, 0.0, 0.0];
        $o = new Objective;
        $frozen = new OldObjective;
        $newRace = $this->race($layout);
        $oldRace = $newRace;
        foreach ($oldRace['entries'] as &$entry) {
            array_pop($entry['bins']);
        }
        unset($entry);
        foreach (Bt03e03Contract::POSITIONS as $position) {
            $this->assertSame($frozen->loss(fn () => [$oldRace], $old, $v, $position), $o->loss(fn () => [$newRace], $layout, $extra, $position));
        }
        $oldFit = (new OldOptimizer($frozen))->fit(fn () => [$oldRace], $old, 1.0);
        $fit = new Bt03e03FitResultDto(1.0, array_map(fn ($c) => [...$c, 0.0, 0.0], $oldFit->coefficients), [], [], [], []);
        $this->assertSame((new Bt03e03ProbabilityScorer)->predict($oldRace, $oldFit), (new Bt03e03ProbabilityScorer)->predict($newRace, $fit));
    }

    public function test_seventeen_group_finite_difference_and_biased_support_constrained_prox(): void
    {
        $layout = new Layout($this->bins(true), 5);
        $race = $this->race($layout);
        $objective = new Objective;
        $v = $layout->project(array_map(fn ($i) => sin($i) * 0.1, range(0, $layout->size() - 1)));
        foreach (Bt03e03Contract::POSITIONS as $position) {
            $gradient = $objective->lossAndGradient(fn () => [$race], $layout, $v, $position)['gradient'];
            $penalty = $objective->smoothPenaltyGradient($layout, $v, 0.1);
            foreach ($v as $i => $value) {
                $left = $right = $v;
                $left[$i] -= 1e-6;
                $right[$i] += 1e-6;
                $valueAt = fn ($b) => $objective->loss(fn () => [$race], $layout, $b, $position) + $objective->smoothPenalty($layout, $b, 0.1);
                $this->assertEqualsWithDelta($gradient[$i] + $penalty[$i], ($valueAt($right) - $valueAt($left)) / 2e-6, 1e-8);
            }
        }
        $v = array_fill(0, $layout->size(), 0.0);
        $v[0] = 2.0;
        $v[1] = -1.0;
        $prox = $objective->groupProx($layout, $v, 0.4, 1.0);
        // Independent 1D constrained minimization: w=(.8,.2), beta=(t,-4t).
        $left = -3.0;
        $right = 3.0;
        $loss = fn ($t) => (($t - 2) ** 2 + (-4 * $t + 1) ** 2) / 0.8 + sqrt(17 / 2) * abs($t) / count($layout->groups());
        for ($i = 0; $i < 120; $i++) {
            $a = $left + ($right - $left) / 3;
            $b = $right - ($right - $left) / 3;
            if ($loss($a) < $loss($b)) {
                $right = $b;
            } else {
                $left = $a;
            }
        }
        $this->assertEqualsWithDelta(($left + $right) / 2, $prox[0], 1e-7);
        $this->assertEqualsWithDelta(-4 * $prox[0], $prox[1], 1e-14);
        $group = $layout->groups()[Contract::EXTRA_FEATURE];
        $v = array_fill(0, $layout->size(), 0.0);
        $v[$group[0]] = 2.0;
        $v[$group[1]] = -1.0;
        $extraProx = $objective->groupProx($layout, $v, 0.4, 1.0);
        $this->assertSame($prox[0], $extraProx[$group[0]]);
        $this->assertSame($prox[1], $extraProx[$group[1]]);
        foreach ($layout->weightedMeans($extraProx) as $mean) {
            $this->assertEqualsWithDelta(0.0, $mean, 1e-14);
        }
    }

    public function test_actual_m_g_and_edges_are_used_in_regularization_without_category_smoothness(): void
    {
        $bins = $this->bins(true);
        $bins['STAT-07'] = [new EffectBinDto(1, 'NUMERIC_RANGE', null, 0.0, null, 4), new EffectBinDto(2, 'NUMERIC_RANGE', 0.0, null, null, 1)];
        $layout = new Layout($bins, 5);
        $this->assertSame(19, $layout->size());
        $this->assertCount(17, $layout->groups());
        $this->assertSame([[0, 1]], $layout->smoothEdges());
        $v = array_fill(0, $layout->size(), 0.5);
        $v[0] = 2.0;
        $v[1] = -1.0;
        $o = new Objective;
        $this->assertEqualsWithDelta(array_sum(array_map(fn ($x) => $x * $x, $v)) / 19 + 9.0, $o->smoothPenalty($layout, $v, 1.0), 1e-14);
        $sum = 0.0;
        foreach ($layout->groups() as $indexes) {
            $sum += sqrt(array_sum(array_map(fn ($i) => $v[$i] ** 2, $indexes)) / count($indexes));
        }
        $this->assertEqualsWithDelta($sum / 17, $o->groupPenalty($layout, $v, 1.0), 1e-14);
    }

    private function bins(bool $extra): array
    {
        $bins = [];
        foreach (Contract::features() as $i => $code) {
            $bins[$code] = [new EffectBinDto(1, 'CATEGORY', null, null, 'a', $i === 0 ? 4 : 5)];
            if ($i === 0 || $i === 16) {
                $bins[$code][] = new EffectBinDto(2, 'CATEGORY', null, null, 'b', 1);
            }
        }
        if (! $extra) {
            $bins[Contract::EXTRA_FEATURE] = [];
        } else {
            $bins[Contract::EXTRA_FEATURE] = [new EffectBinDto(1, 'CATEGORY', null, null, 'N5_B1', 4), new EffectBinDto(2, 'CATEGORY', null, null, 'N5_B2', 1)];
        }

        return $bins;
    }

    private function race(Layout $layout): array
    {
        $entries = [];
        foreach ([0, 1, null, 0, 0, 0] as $i => $bin) {
            $entries[] = ['id' => 100 + $i, 'bike' => $i + 1, 'raw' => 100.0, 'stat01_rank' => 1, 'anchor' => 0.0,
                'rank' => $i + 1, 'status' => 'FINISHED', 'bins' => [$bin, ...array_fill(0, 15, null),
                    isset($layout->groups()[Contract::EXTRA_FEATURE]) && $bin !== null ? $layout->groups()[Contract::EXTRA_FEATURE][$bin] : null]];
        }

        return ['year' => 2023, 'race_id' => 1, 'entries' => $entries];
    }
}
