<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e02ParameterLayout;
use App\Domain\Keirin\Backtest\Calculators\Bt03e03ConditionalSoftmaxObjective;
use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use PHPUnit\Framework\TestCase;

class Bt03e03ObjectiveTest extends TestCase
{
    public function test_stage_objectives_teacher_force_only_unique_previous_positions(): void
    {
        $objective = new Bt03e03ConditionalSoftmaxObjective;
        $layout = $this->layout();
        $zero = array_fill(0, $layout->size(), 0.0);
        $race = $this->race([1, 2, 3, 4, 5]);

        $this->assertEqualsWithDelta(log(5), $objective->raceLoss($race, $layout, $zero, 'POSITION_1'), 1e-15);
        $this->assertEqualsWithDelta(log(4), $objective->raceLoss($race, $layout, $zero, 'POSITION_2'), 1e-15);
        $this->assertEqualsWithDelta(log(3), $objective->raceLoss($race, $layout, $zero, 'POSITION_3'), 1e-15);

        $rank1Tie = $this->race([1, 1, 3, 4, 5]);
        $this->assertNull($objective->raceLoss($rank1Tie, $layout, $zero, 'POSITION_1'));
        $this->assertNull($objective->raceLoss($rank1Tie, $layout, $zero, 'POSITION_2'));
        $this->assertNull($objective->raceLoss($rank1Tie, $layout, $zero, 'POSITION_3'));

        $rank2Tie = $this->race([1, 2, 2, 4, 5]);
        $this->assertNotNull($objective->raceLoss($rank2Tie, $layout, $zero, 'POSITION_1'));
        $this->assertNull($objective->raceLoss($rank2Tie, $layout, $zero, 'POSITION_2'));
        $this->assertNull($objective->raceLoss($rank2Tie, $layout, $zero, 'POSITION_3'));
    }

    public function test_position_objective_rewards_the_actual_direct_position(): void
    {
        $objective = new Bt03e03ConditionalSoftmaxObjective;
        $layout = $this->layout();
        $race = $this->race([1, 2, 3, 4, 5]);

        foreach (Bt03e03Contract::POSITIONS as $offset => $position) {
            $favoursActual = array_fill(0, $layout->size(), 0.0);
            $favoursActual[$offset] = 3.0;
            $favoursWrong = array_fill(0, $layout->size(), 0.0);
            $favoursWrong[4] = 3.0;
            $this->assertLessThan(
                $objective->raceLoss($race, $layout, $favoursWrong, $position),
                $objective->raceLoss($race, $layout, $favoursActual, $position),
                $position,
            );
        }
    }

    public function test_conditional_softmax_gradient_matches_finite_difference(): void
    {
        $objective = new Bt03e03ConditionalSoftmaxObjective;
        $layout = $this->layout();
        $coefficients = array_fill(0, $layout->size(), 0.0);
        $coefficients[0] = 0.2;
        $coefficients[1] = -0.1;
        $source = fn (): array => [$this->race([1, 2, 3, 4, 5])];
        $analytic = $objective->lossAndGradient($source, $layout, $coefficients, 'POSITION_3')['gradient'];
        $epsilon = 1e-6;

        foreach ([0, 1, 2] as $index) {
            $plus = $minus = $coefficients;
            $plus[$index] += $epsilon;
            $minus[$index] -= $epsilon;
            $numeric = (
                $objective->loss($source, $layout, $plus, 'POSITION_3')
                - $objective->loss($source, $layout, $minus, 'POSITION_3')
            ) / (2 * $epsilon);
            $this->assertEqualsWithDelta($numeric, $analytic[$index], 2e-7, "gradient {$index}");
        }
    }

    private function layout(): Bt03e02ParameterLayout
    {
        $bins = [];
        foreach (Bt03e03Contract::STAT_CODES as $statCode) {
            foreach (range(1, 5) as $index) {
                $bins[$statCode][] = new EffectBinDto($index, 'CATEGORY', null, null, (string) $index, 1);
            }
        }

        return new Bt03e02ParameterLayout($bins);
    }

    /** @param list<int> $ranks @return array<string,mixed> */
    private function race(array $ranks): array
    {
        $entries = [];
        foreach ($ranks as $offset => $rank) {
            $bins = array_fill(0, count(Bt03e03Contract::STAT_CODES), null);
            $bins[0] = $offset;
            $entries[] = [
                'id' => $offset + 1,
                'bike' => $offset + 1,
                'raw' => 100.0 - $offset,
                'stat01_rank' => $offset + 1,
                'anchor' => 0.0,
                'bins' => $bins,
                'rank' => $rank,
                'status' => $ranks === [1, 1, 3, 4, 5] && $rank === 1 ? 'TIED' : 'FINISHED',
            ];
        }

        return ['year' => 2023, 'race_id' => 1, 'entries' => $entries];
    }
}
