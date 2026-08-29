<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e02ParameterLayout;
use App\Domain\Keirin\Backtest\Calculators\Bt03e03ConditionalSoftmaxObjective;
use App\Domain\Keirin\Backtest\Calculators\Bt03e07DirectPositionObjective;
use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use App\Domain\Keirin\Backtest\Services\Bt03e07Contract;
use PHPUnit\Framework\TestCase;

final class Bt03e07ObjectiveTest extends TestCase
{
    public function test_direct_objectives_keep_every_entrant_and_depend_only_on_the_target_rank_uniqueness(): void
    {
        $objective = new Bt03e07DirectPositionObjective;
        $layout = $this->layout();
        $zero = array_fill(0, $layout->size(), 0.0);

        $this->assertEqualsWithDelta(log(5), $objective->raceLoss($this->race([1, 2, 3, 4, 5]), $layout, $zero, 'POSITION_2'), 1e-15);
        $this->assertEqualsWithDelta(log(5), $objective->raceLoss($this->race([1, 2, 3, 4, 5]), $layout, $zero, 'POSITION_3'), 1e-15);
        $this->assertNotNull($objective->raceLoss($this->race([1, 1, 2, 4, 5]), $layout, $zero, 'POSITION_2'));
        $this->assertNotNull($objective->raceLoss($this->race([1, 1, 2, 3, 5]), $layout, $zero, 'POSITION_3'));
        $this->assertNull($objective->raceLoss($this->race([1, 2, 2, 4, 5]), $layout, $zero, 'POSITION_2'));
        $this->assertNull($objective->raceLoss($this->race([1, 2, 3, 3, 5]), $layout, $zero, 'POSITION_3'));
    }

    public function test_direct_p2_and_p3_gradients_match_finite_difference(): void
    {
        $objective = new Bt03e07DirectPositionObjective;
        $layout = $this->layout();
        $coefficients = array_fill(0, $layout->size(), 0.0);
        $coefficients[0] = 0.2;
        $coefficients[1] = -0.1;
        $source = fn (): array => [$this->race([1, 2, 3, 4, 5])];
        foreach (Bt03e07Contract::POSITIONS as $position) {
            $analytic = $objective->lossAndGradient($source, $layout, $coefficients, $position)['gradient'];
            foreach ([0, 1, 2] as $index) {
                $plus = $minus = $coefficients;
                $plus[$index] += 1e-6;
                $minus[$index] -= 1e-6;
                $numeric = ($objective->loss($source, $layout, $plus, $position) - $objective->loss($source, $layout, $minus, $position)) / 2e-6;
                $this->assertEqualsWithDelta($numeric, $analytic[$index], 2e-7, "{$position} gradient {$index}");
            }
        }
    }

    public function test_regularization_math_is_bit_exact_with_the_frozen_e03_v2_implementation(): void
    {
        $layout = $this->layout();
        $coefficients = array_map(static fn (int $index): float => ($index % 7 - 3) / 10, range(0, $layout->size() - 1));
        $e03 = new Bt03e03ConditionalSoftmaxObjective;
        $e07 = new Bt03e07DirectPositionObjective;

        $this->assertSame($e03->smoothPenalty($layout, $coefficients, 0.1), $e07->smoothPenalty($layout, $coefficients, 0.1));
        $this->assertSame($e03->smoothPenaltyGradient($layout, $coefficients, 0.1), $e07->smoothPenaltyGradient($layout, $coefficients, 0.1));
        $this->assertSame($e03->groupPenalty($layout, $coefficients, 0.1), $e07->groupPenalty($layout, $coefficients, 0.1));
        $this->assertSame($e03->groupProx($layout, $coefficients, 0.5, 0.1), $e07->groupProx($layout, $coefficients, 0.5, 0.1));
    }

    private function layout(): Bt03e02ParameterLayout
    {
        $bins = [];
        foreach (Bt03e07Contract::STAT_CODES as $statCode) {
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
            $bins = array_fill(0, count(Bt03e07Contract::STAT_CODES), null);
            $bins[0] = $offset;
            $entries[] = ['id' => $offset + 1, 'bike' => $offset + 1, 'anchor' => 0.0, 'bins' => $bins, 'rank' => $rank, 'status' => 'FINISHED'];
        }

        return ['year' => 2023, 'race_id' => 1, 'entries' => $entries];
    }
}
