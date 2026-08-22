<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03eCoordinateDescentOptimizer;
use App\Domain\Keirin\Backtest\Calculators\Bt03ePointScorer;
use App\Domain\Keirin\Backtest\Calculators\Bt03eRaceMetricEvaluator;
use Tests\TestCase;

class Bt03eCoordinateDescentOptimizerTest extends TestCase
{
    public function test_optimizer_is_deterministic_and_uses_only_the_supplied_2023_races(): void
    {
        $optimizer = $this->optimizer();
        $training = [$this->race([2, 1, 3, 4, 5], true)];
        $first = $optimizer->optimize(fn () => $training);

        $changed2024 = [$this->race([5, 4, 3, 2, 1], false)];
        (new Bt03eRaceMetricEvaluator(new Bt03ePointScorer))->evaluate($changed2024, $first['candidate']);
        $second = $optimizer->optimize(fn () => $training);

        $this->assertSame($first['candidate']->key(), $second['candidate']->key());
        $this->assertSame($first['metrics']->metrics, $second['metrics']->metrics);
        $this->assertGreaterThan(0, $first['candidate']->weights['STAT-07']);
    }

    public function test_changing_2023_outcomes_can_change_the_chosen_weights(): void
    {
        $optimizer = $this->optimizer();
        $incrementalWins = $optimizer->optimize(fn () => [$this->race([2, 1, 3, 4, 5], true)]);
        $baselineWins = $optimizer->optimize(fn () => [$this->race([1, 2, 3, 4, 5], true)]);

        $this->assertNotSame($incrementalWins['candidate']->key(), $baselineWins['candidate']->key());
        $this->assertSame(0, $baselineWins['candidate']->weights['STAT-07']);
    }

    private function optimizer(): Bt03eCoordinateDescentOptimizer
    {
        return new Bt03eCoordinateDescentOptimizer(new Bt03eRaceMetricEvaluator(new Bt03ePointScorer));
    }

    /** @param list<int> $ranks @return array{race_id: int, entries: list<array{id: int, bike: int, raw: float, directions: list<int>, rank: ?int, status: string}>} */
    private function race(array $ranks, bool $signal): array
    {
        $entries = [];
        foreach ($ranks as $offset => $rank) {
            $directions = array_fill(0, 12, 0);
            if ($signal && $offset === 0) {
                $directions[0] = -1;
            } elseif ($signal && $offset === 1) {
                $directions[0] = 1;
            }
            $entries[] = [
                'id' => $offset + 1,
                'bike' => $offset + 1,
                'raw' => 100.0 - $offset,
                'directions' => $directions,
                'rank' => $rank,
                'status' => 'FINISHED',
            ];
        }

        return ['race_id' => 1, 'entries' => $entries];
    }
}
