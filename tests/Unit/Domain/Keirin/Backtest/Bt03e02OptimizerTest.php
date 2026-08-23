<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e02FistaOptimizer;
use App\Domain\Keirin\Backtest\Calculators\Bt03e02PairwiseObjective;
use App\Domain\Keirin\Backtest\Calculators\Bt03e02ParameterLayout;
use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use App\Domain\Keirin\Backtest\Services\Bt03e02Contract;
use PHPUnit\Framework\TestCase;

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
