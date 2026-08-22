<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03ePointScorer;
use App\Domain\Keirin\Backtest\DTO\Bt03eCandidateDto;
use App\Domain\Keirin\Backtest\Services\Bt03eContract;
use Tests\TestCase;

class Bt03ePointScorerTest extends TestCase
{
    public function test_base_rank_signed_points_missing_zero_and_tie_rules(): void
    {
        $weights = array_fill_keys(Bt03eContract::STAT_CODES, 0);
        $weights['STAT-07'] = 10;
        $weights['STAT-08'] = 5;
        $entries = [
            $this->entry(1, 1, 90.0, [2, ...array_fill(0, 11, 0)]),
            $this->entry(2, 2, 80.0, [-1, 1, ...array_fill(0, 10, 0)]),
            $this->entry(3, 3, 80.0, array_fill(0, 12, 0)),
            $this->entry(4, 4, 70.0, array_fill(0, 12, 0)),
        ];

        $ranked = (new Bt03ePointScorer)->rank($entries, new Bt03eCandidateDto(5, $weights));
        $scores = array_column($ranked['entries'], 'score', 'bike');

        $this->assertSame(35, $scores[1]);
        $this->assertSame(0, $scores[2]);
        $this->assertSame(5, $scores[3]);
        $this->assertSame(0, $scores[4]);
        $this->assertSame([1, 3, 2, 4], array_column($ranked['entries'], 'bike'));
        $this->assertTrue($ranked['tied']);
        $this->assertSame(2, $ranked['tied_entries']);
        $this->assertSame(1, $ranked['stat01_tie_breaks']);
    }

    public function test_equal_stat01_raw_scores_receive_equal_base_points_and_bottom_is_zero(): void
    {
        $weights = array_fill_keys(Bt03eContract::STAT_CODES, 0);
        $ranked = (new Bt03ePointScorer)->rank([
            $this->entry(1, 2, 90.0, array_fill(0, 12, 0)),
            $this->entry(2, 1, 90.0, array_fill(0, 12, 0)),
            $this->entry(3, 3, 70.0, array_fill(0, 12, 0)),
            $this->entry(4, 4, 70.0, array_fill(0, 12, 0)),
        ], new Bt03eCandidateDto(10, $weights));

        $this->assertSame([20, 20, 0, 0], array_column($ranked['entries'], 'score'));
        $this->assertSame([1, 2, 3, 4], array_column($ranked['entries'], 'bike'));
    }

    /** @param list<int> $directions @return array{id: int, bike: int, raw: float, directions: list<int>, rank: ?int, status: string} */
    private function entry(int $id, int $bike, float $raw, array $directions): array
    {
        return ['id' => $id, 'bike' => $bike, 'raw' => $raw, 'directions' => $directions, 'rank' => $bike, 'status' => 'FINISHED'];
    }
}
