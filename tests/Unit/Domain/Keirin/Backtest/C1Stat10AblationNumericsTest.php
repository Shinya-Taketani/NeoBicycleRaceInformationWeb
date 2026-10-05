<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e03ProbabilityScorer;
use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\Calculators\ExternalSortEffectBinBoundaryProvider;
use App\Domain\Keirin\Backtest\DTO\Bt03e03FitResultDto;
use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use App\Domain\Keirin\Backtest\Experiments\C1Stat10Ablation\Contract;
use App\Domain\Keirin\Backtest\Experiments\C1Stat10Ablation\Layout;
use App\Domain\Keirin\Backtest\Experiments\C1Stat10Ablation\LayoutBuilder;
use App\Domain\Keirin\Backtest\Experiments\C1Stat10Ablation\Objective;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Layout as FullLayout;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Objective as FullObjective;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use PHPUnit\Framework\TestCase;

class C1Stat10AblationNumericsTest extends TestCase
{
    private function fullBins(): array
    {
        $bins = [];
        foreach (Contract::baselineFeatures() as $i => $name) {
            $bins[$name] = [new EffectBinDto(1, 'NUMERIC_RANGE', null, 0.0, null, 4),
                new EffectBinDto(2, 'NUMERIC_RANGE', 0.0, null, null, 1)];
        }

        return $bins;
    }

    private function race(object $layout, array $names): array
    {
        $builder = new EffectBinBuilder(new ExternalSortEffectBinBoundaryProvider);
        $entries = [];
        for ($i = 0; $i < 5; $i++) {
            $entries[] = ['id' => $i + 1, 'bike' => $i + 1, 'raw' => 100.0, 'stat01_rank' => 1,
                'anchor' => $i * 0.1, 'rank' => $i + 1, 'status' => 'FINISHED',
                'bins' => $layout->assign(array_map(fn ($n) => $i === 4 ? null : ($i % 2 === 0 ? -1.0 : 1.0), $names), $builder)];
        }

        return ['year' => 2023, 'race_id' => 1, 'entries' => $entries];
    }

    public function test_full_zero_stat10_and_retained_representation_preserve_probabilities_nll_gradients(): void
    {
        $bins = $this->fullBins();
        $full = new FullLayout($bins);
        unset($bins['STAT-10']);
        $retained = new Layout($bins);
        $fullCoefficients = $smallCoefficients = [];
        foreach ($full->groups() as $name => $indexes) {
            foreach ($indexes as $j => $index) {
                $value = $name === 'STAT-10' ? 0.0 : ($j === 0 ? 0.03 : -0.12);
                $fullCoefficients[] = $value;
                if ($name !== 'STAT-10') {
                    $smallCoefficients[] = $value;
                }
            }
        }
        $largeRace = $this->race($full, Contract::baselineFeatures());
        $smallRace = $this->race($retained, Contract::features());
        $largeFit = new Bt03e03FitResultDto(1.0, array_fill_keys(Bt03e03Contract::POSITIONS, $fullCoefficients), [], [], [], []);
        $smallFit = new Bt03e03FitResultDto(1.0, array_fill_keys(Bt03e03Contract::POSITIONS, $smallCoefficients), [], [], [], []);
        $this->assertSame((new Bt03e03ProbabilityScorer)->predict($largeRace, $largeFit), (new Bt03e03ProbabilityScorer)->predict($smallRace, $smallFit));
        foreach (Bt03e03Contract::POSITIONS as $position) {
            $a = (new FullObjective)->lossAndGradient(fn () => [$largeRace], $full, $fullCoefficients, $position);
            $b = (new Objective)->lossAndGradient(fn () => [$smallRace], $retained, $smallCoefficients, $position);
            $this->assertSame($a['loss'], $b['loss']);
            $mapped = [];
            foreach ($full->groups() as $name => $indexes) {
                if ($name !== 'STAT-10') {
                    foreach ($indexes as $index) {
                        $mapped[] = $a['gradient'][$index];
                    }
                }
            }
            $this->assertSame($mapped, $b['gradient']);
        }
        $this->assertSame(30, $retained->size());
        $this->assertCount(15, $retained->groups());
        $this->assertCount(15, $retained->smoothEdges());
        $utility = fn ($e, $v) => $e['anchor'] + array_sum(array_map(fn ($i) => $i === null ? 0.0 : $v[$i], $e['bins']));
        foreach ($largeRace['entries'] as $i => $entry) {
            $this->assertSame($utility($entry, $fullCoefficients), $utility($smallRace['entries'][$i], $smallCoefficients));
        }
    }

    public function test_fifteen_feature_gradient_penalty_and_constrained_prox_use_actual_structure(): void
    {
        $bins = $this->fullBins();
        unset($bins['STAT-10']);
        $layout = new Layout($bins);
        $race = $this->race($layout, Contract::features());
        $o = new Objective;
        $v = $layout->project(array_map(fn ($i) => sin($i) * 0.1, range(0, 29)));
        $lambda = 0.1;
        $l2 = array_sum(array_map(fn ($b) => $b * $b, $v)) / 30;
        $smooth = array_sum(array_map(fn ($edge) => ($v[$edge[1]] - $v[$edge[0]]) ** 2, $layout->smoothEdges())) / 15;
        $group = array_sum(array_map(fn ($indices) => sqrt(array_sum(array_map(fn ($i) => $v[$i] ** 2, $indices)) / count($indices)), $layout->groups())) / 15;
        $this->assertEqualsWithDelta($lambda * ($l2 + $smooth), $o->smoothPenalty($layout, $v, $lambda), 1e-15);
        $this->assertEqualsWithDelta($lambda * $group, $o->groupPenalty($layout, $v, $lambda), 1e-15);
        foreach (Bt03e03Contract::POSITIONS as $position) {
            $g = $o->lossAndGradient(fn () => [$race], $layout, $v, $position)['gradient'];
            $p = $o->smoothPenaltyGradient($layout, $v, $lambda);
            foreach ($v as $i => $value) {
                $left = $right = $v;
                $left[$i] -= 1e-6;
                $right[$i] += 1e-6;
                $f = fn ($b) => $o->loss(fn () => [$race], $layout, $b, $position) + $o->smoothPenalty($layout, $b, $lambda);
                $this->assertEqualsWithDelta($g[$i] + $p[$i], ($f($right) - $f($left)) / 2e-6, 1e-8);
            }
        }
        $v = array_fill(0, 30, 0.0);
        $v[0] = 2.0;
        $v[1] = -1.0;
        $prox = $o->groupProx($layout, $v, 0.4, 1.0);
        $left = -3.0;
        $right = 3.0;
        $f = fn ($t) => (($t - 2) ** 2 + (-4 * $t + 1) ** 2) / 0.8 + sqrt(17 / 2) * abs($t) / 15;
        for ($i = 0; $i < 120; $i++) {
            $a = $left + ($right - $left) / 3;
            $b = $right - ($right - $left) / 3;
            if ($f($a) < $f($b)) {
                $right = $b;
            } else {
                $left = $a;
            }
        }
        $this->assertEqualsWithDelta(($left + $right) / 2, $prox[0], 1e-7);
        $this->assertEqualsWithDelta(-4 * $prox[0], $prox[1], 1e-14);
    }

    public function test_training_local_support_and_missing_history_are_not_dummy_groups(): void
    {
        $builder = new EffectBinBuilder(new ExternalSortEffectBinBoundaryProvider);
        $layout = (new LayoutBuilder($builder))->build(fn () => [['entries' => array_map(fn ($v) => ['signals' => [...array_fill(0, 11, 0), $v, null, null, null]], [null, 0, 1, 2])]]);
        $this->assertCount(12, $layout->groups());
        $this->assertSame(3, array_sum(array_column($layout->canonicalBins()[Contract::features()[11]], 'training_support')));
        $this->assertNull($layout->assign([...array_fill(0, 11, 0), null, null, null, null], $builder)[11]);
        $this->assertNotNull($layout->assign([...array_fill(0, 11, 0), 0, null, null, null], $builder)[11]);
        $this->assertNull($layout->assign([...array_fill(0, 11, 0), 999, null, null, null], $builder)[11]);
        $this->assertArrayNotHasKey('STAT-10', $layout->canonicalBins());
    }
}
