<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03ePointScorer;
use App\Domain\Keirin\Backtest\DTO\Bt03eCandidateDto;
use App\Domain\Keirin\Backtest\Services\Bt03eContract;
use Tests\TestCase;

class Bt03ePointScorerTest extends TestCase
{
    public function test_base_points_use_stored_competition_rank_with_gaps(): void
    {
        $weights = array_fill_keys(Bt03eContract::STAT_CODES, 0);
        $entries = [
            $this->entry(1, 1, 100.0, 1),
            $this->entry(2, 2, 90.0, 2),
            $this->entry(3, 3, 90.0, 2),
            $this->entry(4, 4, 80.0, 4),
        ];

        $ranked = (new Bt03ePointScorer)->rank($entries, new Bt03eCandidateDto(10, $weights));
        $scores = array_column($ranked['entries'], 'score', 'bike');

        $this->assertSame(30, $scores[1]);
        $this->assertSame(20, $scores[2]);
        $this->assertSame(20, $scores[3]);
        $this->assertSame(0, $scores[4]);
        $this->assertSame([1, 2, 3, 4], array_column($ranked['entries'], 'bike'));
        $this->assertTrue($ranked['tied']);
        $this->assertSame(2, $ranked['tied_entries']);
        $this->assertSame(0, $ranked['stat01_tie_breaks']);
    }

    public function test_bottom_tied_rank_group_is_zero(): void
    {
        $weights = array_fill_keys(Bt03eContract::STAT_CODES, 0);
        $ranked = (new Bt03ePointScorer)->rank([
            $this->entry(1, 2, 90.0, 1),
            $this->entry(2, 1, 90.0, 1),
            $this->entry(3, 3, 70.0, 3),
            $this->entry(4, 4, 70.0, 3),
        ], new Bt03eCandidateDto(10, $weights));

        $this->assertSame([20, 20, 0, 0], array_column($ranked['entries'], 'score'));
        $this->assertSame([1, 2, 3, 4], array_column($ranked['entries'], 'bike'));
    }

    public function test_all_tied_rank_one_entries_receive_zero_base_points(): void
    {
        $ranked = (new Bt03ePointScorer)->rank([
            $this->entry(1, 1, 90.0, 1),
            $this->entry(2, 2, 90.0, 1),
            $this->entry(3, 3, 90.0, 1),
        ], new Bt03eCandidateDto(40, array_fill_keys(Bt03eContract::STAT_CODES, 0)));

        $this->assertSame([0, 0, 0], array_column($ranked['entries'], 'score'));
    }

    public function test_stored_rank_is_used_instead_of_raw_lower_count(): void
    {
        $ranked = (new Bt03ePointScorer)->rank([
            $this->entry(1, 1, 100.0, 2),
            $this->entry(2, 2, 90.0, 1),
            $this->entry(3, 3, 80.0, 3),
        ], new Bt03eCandidateDto(10, array_fill_keys(Bt03eContract::STAT_CODES, 0)));

        $this->assertSame([2, 1, 3], array_column($ranked['entries'], 'bike'));
        $this->assertSame([20, 10, 0], array_column($ranked['entries'], 'score'));
    }

    public function test_baseline_has_an_explicit_raw_descending_ranking_path(): void
    {
        $ranked = (new Bt03ePointScorer)->rankBaseline([
            $this->entry(1, 1, 80.0, 1),
            $this->entry(2, 2, 100.0, 3),
            $this->entry(3, 3, 90.0, 2),
        ]);

        $this->assertSame([2, 3, 1], array_column($ranked['entries'], 'bike'));
    }

    public function test_explicit_baseline_matches_stored_stat01_rank_order_when_contracts_agree(): void
    {
        $entries = [
            $this->entry(1, 4, 80.0, 4),
            $this->entry(2, 2, 90.0, 2),
            $this->entry(3, 1, 100.0, 1),
            $this->entry(4, 3, 90.0, 2),
        ];
        $scorer = new Bt03ePointScorer;
        $baseline = $scorer->rankBaseline($entries);
        $pointBase = $scorer->rank(
            $entries,
            new Bt03eCandidateDto(10, array_fill_keys(Bt03eContract::STAT_CODES, 0)),
        );

        $this->assertSame(
            array_column($baseline['entries'], 'bike'),
            array_column($pointBase['entries'], 'bike'),
        );
    }

    public function test_missing_or_non_positive_stored_rank_fails_closed(): void
    {
        $entry = $this->entry(1, 1, 80.0, 1);
        $entry['stat01_rank'] = 0;

        $this->expectException(\RuntimeException::class);
        (new Bt03ePointScorer)->rank([$entry], new Bt03eCandidateDto(10, array_fill_keys(Bt03eContract::STAT_CODES, 0)));
    }

    /** @return array{id: int, bike: int, raw: float, stat01_rank: int, directions: list<int>, rank: ?int, status: string} */
    private function entry(int $id, int $bike, float $raw, int $stat01Rank): array
    {
        return [
            'id' => $id,
            'bike' => $bike,
            'raw' => $raw,
            'stat01_rank' => $stat01Rank,
            'directions' => array_fill(0, 12, 0),
            'rank' => $bike,
            'status' => 'FINISHED',
        ];
    }
}
