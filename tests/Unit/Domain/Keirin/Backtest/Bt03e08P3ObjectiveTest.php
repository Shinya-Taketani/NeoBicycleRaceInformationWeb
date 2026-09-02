<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e02ParameterLayout;
use App\Domain\Keirin\Backtest\Calculators\Bt03e03ConditionalSoftmaxObjective;
use App\Domain\Keirin\Backtest\Calculators\Bt03e08WinnerConditionedP3Objective;
use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use App\Domain\Keirin\Backtest\Services\Bt03e08Contract;
use PHPUnit\Framework\TestCase;

final class Bt03e08P3ObjectiveTest extends TestCase
{
    public function test_unique_first_and_third_are_eligible_even_when_rank_two_is_tied(): void
    {
        $objective = new Bt03e08WinnerConditionedP3Objective;
        $layout = $this->layout();
        $zero = array_fill(0, $layout->size(), 0.0);
        $this->assertEqualsWithDelta(log(4), $objective->raceLoss($this->race([1, 2, 2, 3, 5]), $layout, $zero), 1e-15);
        $this->assertNull($objective->raceLoss($this->race([1, 1, 2, 3, 5]), $layout, $zero));
        $this->assertNull($objective->raceLoss($this->race([1, 2, 3, 3, 5]), $layout, $zero));
    }

    public function test_winner_is_excluded_but_actual_second_remains_in_denominator(): void
    {
        $objective = new Bt03e08WinnerConditionedP3Objective;
        $layout = $this->layout();
        $coefficients = array_fill(0, $layout->size(), 0.0);
        $coefficients[0] = 1000.0;
        $coefficients[1] = 2.0;
        $loss = $objective->raceLoss($this->race([1, 2, 3, 4, 5]), $layout, $coefficients);
        $this->assertNotNull($loss);
        $this->assertTrue(is_finite($loss));
        $withoutRank2 = log(exp(0.0) + exp(0.0) + exp(0.0));
        $this->assertNotEqualsWithDelta($withoutRank2, $loss, 1e-6);
    }

    public function test_gradient_matches_finite_difference_and_regularization_is_frozen(): void
    {
        $objective = new Bt03e08WinnerConditionedP3Objective;
        $layout = $this->layout();
        $coefficients = array_fill(0, $layout->size(), 0.0);
        $coefficients[0] = 0.2;
        $coefficients[1] = -0.1;
        $source = fn (): array => [$this->race([1, 2, 3, 4, 5])];
        $analytic = $objective->lossAndGradient($source, $layout, $coefficients)['gradient'];
        foreach ([0, 1, 2] as $index) {
            $plus = $minus = $coefficients;
            $plus[$index] += 1e-6;
            $minus[$index] -= 1e-6;
            $numeric = ($objective->loss($source, $layout, $plus) - $objective->loss($source, $layout, $minus)) / 2e-6;
            $this->assertEqualsWithDelta($numeric, $analytic[$index], 2e-7);
        }
        $e03 = new Bt03e03ConditionalSoftmaxObjective;
        $this->assertSame($e03->smoothPenalty($layout, $coefficients, 0.1), $objective->smoothPenalty($layout, $coefficients, 0.1));
        $this->assertSame($e03->smoothPenaltyGradient($layout, $coefficients, 0.1), $objective->smoothPenaltyGradient($layout, $coefficients, 0.1));
        $this->assertSame($e03->groupPenalty($layout, $coefficients, 0.1), $objective->groupPenalty($layout, $coefficients, 0.1));
        $this->assertSame($e03->groupProx($layout, $coefficients, 0.5, 0.1), $objective->groupProx($layout, $coefficients, 0.5, 0.1));
    }

    private function layout(): Bt03e02ParameterLayout
    {
        $bins = [];
        foreach (Bt03e08Contract::STAT_CODES as $statCode) {
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
            $bins = array_fill(0, count(Bt03e08Contract::STAT_CODES), null);
            $bins[0] = $offset;
            $entries[] = ['id' => $offset + 1, 'bike' => $offset + 1, 'anchor' => 0.0, 'bins' => $bins, 'rank' => $rank, 'status' => 'FINISHED'];
        }

        return ['year' => 2023, 'race_id' => 1, 'entries' => $entries];
    }
}
