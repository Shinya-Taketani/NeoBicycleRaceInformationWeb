<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e04DecisionDecoder;
use App\Domain\Keirin\Backtest\Services\Bt03e04Contract;
use PHPUnit\Framework\TestCase;

class Bt03e04DecoderTest extends TestCase
{
    public function test_coherent_position_decoder_uses_the_exact_distinct_triple_maximum(): void
    {
        $decision = (new Bt03e04DecisionDecoder)->decode($this->race());

        $this->assertSame(1, $decision['argmax_p1_bike']);
        $this->assertSame([2, 1, 3], [
            $decision['primary_position_1_bike'],
            $decision['primary_position_2_bike'],
            $decision['primary_position_3_bike'],
        ]);
        $this->assertEqualsWithDelta(1.24, $decision['primary_objective_score'], 1e-15);
        $this->assertCount(3, array_unique([
            $decision['primary_position_1_bike'],
            $decision['primary_position_2_bike'],
            $decision['primary_position_3_bike'],
        ]));
    }

    public function test_each_fixed_decoder_uses_its_own_source_or_marginal_objective(): void
    {
        $decision = (new Bt03e04DecisionDecoder)->decode($this->race());

        $this->assertSame([4, 1, 3], $decision['map_ordered_top3']);
        $this->assertSame([1, 3, 4], $decision['map_top3_set']);
        $this->assertSame([1, 3], $decision['top2_marginal_bikes']);
        $this->assertSame([1, 2, 3], $decision['expected_ndcg_top3']);
        $this->assertSame('MAP_ORDERED_TOP3', Bt03e04Contract::METRIC_DECODERS['EXACT_ORDERED_TOP3_RATE']);
        $this->assertSame('MAP_TOP3_SET', Bt03e04Contract::METRIC_DECODERS['EXACT_TOP3_SET_RATE']);
        $this->assertSame('TOP3_MARGINAL', Bt03e04Contract::METRIC_DECODERS['TOP3_COVERAGE_AT_3']);
        $this->assertSame('EXPECTED_NDCG', Bt03e04Contract::METRIC_DECODERS['NDCG_AT_3']);
    }

    public function test_exact_ties_are_deterministic_and_audited_by_race_identity(): void
    {
        $first = (new Bt03e04DecisionDecoder)->decode($this->uniformRace(91));
        $repeat = (new Bt03e04DecisionDecoder)->decode($this->uniformRace(91));
        $otherRace = (new Bt03e04DecisionDecoder)->decode($this->uniformRace(92));

        $this->assertSame($first, $repeat);
        $this->assertSame(60, $first['primary_tie_count']);
        $this->assertTrue($first['primary_technical_tiebreak_used']);
        $this->assertNotSame(
            [$first['primary_position_1_bike'], $first['primary_position_2_bike'], $first['primary_position_3_bike']],
            [$otherRace['primary_position_1_bike'], $otherRace['primary_position_2_bike'], $otherRace['primary_position_3_bike']],
        );
    }

    /** @return array<string,mixed> */
    private function race(): array
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
            'map_top3_set' => [1, 3, 4],
            'map_top3_set_probability' => 0.20,
        ];
    }

    /** @return array<string,mixed> */
    private function uniformRace(int $raceId): array
    {
        $entries = [];
        foreach (range(1, 5) as $bike) {
            $entries[] = [
                'bike' => $bike,
                'position_1_probability' => 0.2,
                'position_2_probability' => 0.2,
                'position_3_probability' => 0.2,
                'top2_probability' => 0.4,
                'top3_probability' => 0.6,
            ];
        }

        return [
            'year' => 2024,
            'race_id' => $raceId,
            'entries' => $entries,
            'map_ordered_top3' => [1, 2, 3],
            'map_ordered_probability' => 0.01,
            'map_top3_set' => [1, 2, 3],
            'map_top3_set_probability' => 0.06,
        ];
    }
}
