<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e03MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e04DecisionDecoder;
use App\Domain\Keirin\Backtest\Calculators\Bt03e04MetricEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class Bt03e04MetricsTest extends TestCase
{
    public function test_metric_to_decoder_mapping_is_applied_without_argmax_p1_gate_leakage(): void
    {
        $source = $this->source();
        $decision = (new Bt03e04DecisionDecoder)->decode($source);
        $comparison = (new Bt03e04MetricEvaluator)->raceComparison($this->context(), $decision);

        $this->assertSame(0.0, $comparison['candidate']['WINNER_HIT_AT_1']['numerator']);
        $this->assertSame(1.0, $comparison['diagnostics']['ARGMAX_P1_WINNER_HIT_AT_1']);
        $this->assertSame(0.0, $comparison['candidate']['EXACT_ORDERED_TOP3_RATE']['numerator']);
        $this->assertSame(1.0, $comparison['candidate']['EXACT_TOP3_SET_RATE']['numerator']);
        $this->assertSame(1.0, $comparison['candidate']['TOP2_COVERAGE_AT_2']['numerator']);
        $this->assertSame(1.0, $comparison['candidate']['NDCG_AT_3']['numerator']);
    }

    #[DataProvider('rankProvider')]
    public function test_stat01_baseline_matches_e03_for_normal_and_dead_heat(array $ranks): void
    {
        $context = $this->context($ranks);
        $decision = (new Bt03e04DecisionDecoder)->decode($this->source());
        $e04 = (new Bt03e04MetricEvaluator)->raceComparison($context, $decision);
        $e03Race = $context;
        foreach ($e03Race['entries'] as &$entry) {
            $entry['predicted_position'] = $entry['bike'];
            $entry['map_tie_diagnostics'] = [];
        }
        unset($entry);
        $e03 = (new Bt03e03MetricEvaluator)->raceComparison($e03Race);

        $this->assertSame($e03['baseline'], $e04['baseline']);
        $this->assertSame($e03['ordered_eligible'], $e04['ordered_eligible']);
    }

    /** @return iterable<string,array{list<int>}> */
    public static function rankProvider(): iterable
    {
        yield 'normal' => [[1, 2, 3, 4, 5]];
        yield 'tie first' => [[1, 1, 3, 4, 5]];
        yield 'tie second' => [[1, 2, 2, 4, 5]];
        yield 'tie third' => [[1, 2, 3, 3, 5]];
    }

    /** @return array<string,mixed> */
    private function source(): array
    {
        $probabilities = [
            1 => [0.40, 0.59, 0.01],
            2 => [0.35, 0.01, 0.34],
            3 => [0.20, 0.20, 0.30],
            4 => [0.04, 0.10, 0.20],
            5 => [0.01, 0.10, 0.15],
        ];
        $entries = [];
        foreach ($probabilities as $bike => [$p1, $p2, $p3]) {
            $entries[] = [
                'bike' => $bike,
                'position_1_probability' => $p1,
                'position_2_probability' => $p2,
                'position_3_probability' => $p3,
                'top2_probability' => $p1 + $p2,
                'top3_probability' => $p1 + $p2 + $p3,
            ];
        }

        return [
            'year' => 2024,
            'race_id' => 10,
            'entries' => $entries,
            'map_ordered_top3' => [4, 1, 3],
            'map_ordered_probability' => 0.09,
            'map_top3_set' => [1, 2, 3],
            'map_top3_set_probability' => 0.20,
        ];
    }

    /** @param list<int> $ranks @return array<string,mixed> */
    private function context(array $ranks = [1, 2, 3, 4, 5]): array
    {
        $entries = [];
        foreach ($ranks as $offset => $rank) {
            $entries[] = [
                'id' => $offset + 1,
                'bike' => $offset + 1,
                'raw' => 100.0 - $offset,
                'stat01_rank' => $offset + 1,
                'rank' => $rank,
                'status' => count(array_keys($ranks, $rank, true)) > 1 ? 'TIED' : 'FINISHED',
            ];
        }

        return ['year' => 2024, 'race_id' => 10, 'entries' => $entries];
    }
}
