<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03ePointScorer;
use App\Domain\Keirin\Backtest\Calculators\Bt03eRaceMetricEvaluator;
use App\Domain\Keirin\Backtest\DTO\Bt03eCandidateDto;
use App\Domain\Keirin\Backtest\Services\Bt03eContract;
use Tests\TestCase;

class Bt03eRaceMetricEvaluatorTest extends TestCase
{
    public function test_all_ordered_set_coverage_and_ndcg_metrics_are_exact_for_a_perfect_ranking(): void
    {
        $summary = $this->evaluator()->evaluate([$this->race([1, 2, 3, 4, 5])], $this->baseline());

        foreach (Bt03eRaceMetricEvaluator::METRIC_CODES as $metric) {
            $this->assertSame(1.0, $summary->metrics[$metric], $metric);
        }
        $this->assertSame(1, $summary->orderedEligibleRaceCount);
        $this->assertSame(0, $summary->orderedExcludedRaceCount);
    }

    public function test_position_metrics_and_set_coverage_have_the_documented_denominators(): void
    {
        $summary = $this->evaluator()->evaluate([$this->race([2, 1, 3, 4, 5])], $this->baseline());

        $this->assertSame(0.0, $summary->metrics['WINNER_HIT_AT_1']);
        $this->assertSame(0.0, $summary->metrics['POSITION_1_ACCURACY']);
        $this->assertSame(0.0, $summary->metrics['POSITION_2_ACCURACY']);
        $this->assertSame(1.0, $summary->metrics['POSITION_3_ACCURACY']);
        $this->assertEqualsWithDelta(1 / 3, $summary->metrics['POSITION_HIT_RATE_AT_3'], 1e-12);
        $this->assertSame(0.0, $summary->metrics['EXACT_ORDERED_TOP3_RATE']);
        $this->assertSame(1.0, $summary->metrics['EXACT_TOP3_SET_RATE']);
        $this->assertSame(1.0, $summary->metrics['TOP3_COVERAGE_AT_3']);
        $this->assertSame(1.0, $summary->metrics['EXACT_TOP2_SET_RATE']);
        $this->assertSame(1.0, $summary->metrics['TOP2_COVERAGE_AT_2']);
        $this->assertGreaterThan(0.8, $summary->metrics['NDCG_AT_3']);
        $this->assertLessThan(1.0, $summary->metrics['NDCG_AT_3']);
    }

    public function test_dead_heat_is_excluded_from_ordered_metrics_but_retained_for_set_metrics(): void
    {
        $race = $this->race([1, 1, 3, 4, 5]);
        $summary = $this->evaluator()->evaluate([$race], $this->baseline());

        $this->assertSame(0, $summary->orderedEligibleRaceCount);
        $this->assertSame(1, $summary->orderedExcludedRaceCount);
        $this->assertSame(['NON_UNIQUE_OR_MISSING_OFFICIAL_TOP3' => 1], $summary->orderedExclusionReasons);
        $this->assertSame(1.0, $summary->metrics['EXACT_TOP3_SET_RATE']);
        $this->assertSame(1.0, $summary->metrics['TOP3_COVERAGE_AT_3']);
    }

    private function evaluator(): Bt03eRaceMetricEvaluator
    {
        return new Bt03eRaceMetricEvaluator(new Bt03ePointScorer);
    }

    private function baseline(): Bt03eCandidateDto
    {
        return new Bt03eCandidateDto(1, array_fill_keys(Bt03eContract::STAT_CODES, 0));
    }

    /** @param list<int> $ranks @return array{race_id: int, entries: list<array{id: int, bike: int, raw: float, directions: list<int>, rank: ?int, status: string}>} */
    private function race(array $ranks): array
    {
        $entries = [];
        foreach ($ranks as $offset => $rank) {
            $entries[] = [
                'id' => $offset + 1,
                'bike' => $offset + 1,
                'raw' => 100.0 - $offset,
                'directions' => array_fill(0, 12, 0),
                'rank' => $rank,
                'status' => count(array_keys($ranks, $rank, true)) > 1 ? 'TIED' : 'FINISHED',
            ];
        }

        return ['race_id' => 1, 'entries' => $entries];
    }
}
