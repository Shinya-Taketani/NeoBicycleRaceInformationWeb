<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e03ProbabilityScorer;
use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\Calculators\ExternalSortEffectBinBoundaryProvider;
use App\Domain\Keirin\Backtest\DTO\Bt03e03FitResultDto;
use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use App\Domain\Keirin\Backtest\Experiments\Stat17C1Comparison\Contract;
use App\Domain\Keirin\Backtest\Experiments\Stat17C1Comparison\Layout;
use App\Domain\Keirin\Backtest\Experiments\Stat17C1Comparison\LayoutBuilder;
use App\Domain\Keirin\Backtest\Experiments\Stat17C1Comparison\Objective;
use App\Domain\Keirin\Backtest\Experiments\Stat17C1Comparison\Optimizer;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Layout as OldLayout;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Objective as OldObjective;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Optimizer as OldOptimizer;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use PHPUnit\Framework\TestCase;

class Stat17C1ComparisonNumericsTest extends TestCase
{
    public function test_inactive_seventeenth_group_is_bit_exact_with_frozen_sixteen_group_numerics(): void
    {
        $bins = $this->bins(false);
        $layout = new Layout($bins);
        $old = new OldLayout(array_slice($bins, 0, 16));
        $this->assertSame($old->size(), $layout->size());
        $this->assertSame($old->groups(), $layout->groups());
        $this->assertSame($old->supportWeights(), $layout->supportWeights());
        $this->assertSame($old->smoothEdges(), $layout->smoothEdges());
        $v = array_fill(0, $layout->size(), 0.7);
        $v[0] = 2.0;
        $v[1] = -1.0;
        $this->assertSame($old->project($v), $layout->project($v));
        $o = new Objective;
        $frozen = new OldObjective;
        foreach (['smoothPenalty', 'smoothPenaltyGradient', 'groupPenalty'] as $method) {
            $this->assertSame($frozen->$method($old, $v, 1.0), $o->$method($layout, $v, 1.0));
        }
        $this->assertSame($frozen->groupProx($old, $v, 0.4, 1.0), $o->groupProx($layout, $v, 0.4, 1.0));
        $newRace = $this->race($layout);
        $oldRace = $newRace;
        foreach ($oldRace['entries'] as &$entry) {
            array_pop($entry['bins']);
        }
        unset($entry);
        foreach (Bt03e03Contract::POSITIONS as $position) {
            $this->assertSame($frozen->lossAndGradient(fn () => [$oldRace], $old, $v, $position), $o->lossAndGradient(fn () => [$newRace], $layout, $v, $position));
        }
        $fit = (new Optimizer($o))->fit(fn () => [$newRace], $layout, 1.0);
        $oldFit = (new OldOptimizer($frozen))->fit(fn () => [$oldRace], $old, 1.0);
        $this->assertSame((array) $oldFit, (array) $fit);
        $this->assertSame((new Bt03e03ProbabilityScorer)->predict($oldRace, $oldFit), (new Bt03e03ProbabilityScorer)->predict($newRace, $fit));
        foreach ($fit->diagnostics as $diag) {
            $this->assertLessThanOrEqual(1e-7, $diag['prox_gradient_mapping_max']);
            $this->assertLessThanOrEqual(1e-7, $diag['centering_residual_max']);
        }
    }

    public function test_seventeen_group_finite_difference_and_biased_support_constrained_prox(): void
    {
        $layout = new Layout($this->bins(true));
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
    }

    public function test_zero_extra_coefficients_preserve_forward_and_training_only_support_keeps_null_inactive(): void
    {
        $builder = new EffectBinBuilder(new ExternalSortEffectBinBoundaryProvider);
        $source = fn () => [['entries' => array_map(fn ($v) => ['signals' => [...array_fill(0, 16, 0), $v]], [null, 0.0, 2 / 3, 1.0])]];
        $layout = (new LayoutBuilder($builder))->build($source);
        $this->assertSame(3, array_sum(array_column($layout->canonicalBins()[Contract::EXTRA_FEATURE], 'training_support')));
        $this->assertNull($layout->assign([...array_fill(0, 16, 0), null], $builder)[16]);
        $this->assertNotNull($layout->assign([...array_fill(0, 16, 0), 0], $builder)[16]);
        $this->assertNull($layout->assign([...array_fill(0, 16, 0), 999.0], $builder)[16]);
        $oldBins = $this->bins(false);
        $old = new OldLayout(array_slice($oldBins, 0, 16));
        $new = new Layout($this->bins(true));
        $oldRace = $this->race(new Layout($oldBins));
        foreach ($oldRace['entries'] as &$entry) {
            array_pop($entry['bins']);
        }
        unset($entry);
        $fit = (new OldOptimizer(new OldObjective))->fit(fn () => [$oldRace], $old, 1.0);
        $extended = new Bt03e03FitResultDto(1.0,
            array_map(fn ($c) => [...$c, ...array_fill(0, $new->size() - $old->size(), 0.0)], $fit->coefficients), [], [], [], []);
        $this->assertSame((new Bt03e03ProbabilityScorer)->predict($oldRace, $fit), (new Bt03e03ProbabilityScorer)->predict($this->race($new), $extended));
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
