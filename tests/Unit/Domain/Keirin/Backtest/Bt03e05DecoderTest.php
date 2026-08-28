<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05DecisionDecoder;
use App\Domain\Keirin\Backtest\Services\Bt03e05Contract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class Bt03e05DecoderTest extends TestCase
{
    public function test_winner_is_preserved_when_the_coherent_objective_would_replace_it(): void
    {
        $decision = (new Bt03e05DecisionDecoder)->decode($this->race());

        $this->assertSame([1, 3, 2], [
            $decision['primary_position_1_bike'],
            $decision['primary_position_2_bike'],
            $decision['primary_position_3_bike'],
        ]);
        $this->assertSame(0.4, $decision['primary_position_1_probability']);
        $this->assertSame(0.2, $decision['primary_position_2_probability']);
        $this->assertSame(0.34, $decision['primary_position_3_probability']);
        $this->assertEqualsWithDelta(0.54, $decision['primary_second_third_objective_score'], 1e-15);
        $scores = [];
        foreach ($this->race()['entries'] as $second) {
            foreach ($this->race()['entries'] as $third) {
                if ($second['bike'] !== 1 && $third['bike'] !== 1 && $second['bike'] !== $third['bike']) {
                    $scores[] = $second['position_2_probability'] + $third['position_3_probability'];
                }
            }
        }
        $this->assertSame(max($scores), $decision['primary_second_third_objective_score']);
        $this->assertCount(3, array_unique([
            $decision['primary_position_1_bike'],
            $decision['primary_position_2_bike'],
            $decision['primary_position_3_bike'],
        ]));
    }

    public function test_each_fixed_decoder_uses_its_own_source_or_marginal_objective(): void
    {
        $decision = (new Bt03e05DecisionDecoder)->decode($this->race());

        $this->assertSame([4, 1, 3], $decision['map_ordered_top3']);
        $this->assertSame([1, 3, 4], $decision['map_top3_set']);
        $this->assertSame([1, 3], $decision['top2_marginal_bikes']);
        $this->assertSame([1, 2, 3], $decision['expected_ndcg_top3']);
        $this->assertSame('MAP_ORDERED_TOP3', Bt03e05Contract::METRIC_DECODERS['EXACT_ORDERED_TOP3_RATE']);
        $this->assertSame('MAP_TOP3_SET', Bt03e05Contract::METRIC_DECODERS['EXACT_TOP3_SET_RATE']);
        $this->assertSame('TOP3_MARGINAL', Bt03e05Contract::METRIC_DECODERS['TOP3_COVERAGE_AT_3']);
        $this->assertSame('EXPECTED_NDCG', Bt03e05Contract::METRIC_DECODERS['NDCG_AT_3']);
    }

    public function test_exact_ties_are_deterministic_and_audited_by_race_identity(): void
    {
        $first = (new Bt03e05DecisionDecoder)->decode($this->uniformRace(91));
        $repeat = (new Bt03e05DecisionDecoder)->decode($this->uniformRace(91));
        $otherRace = (new Bt03e05DecisionDecoder)->decode($this->uniformRace(92));

        $this->assertSame($first, $repeat);
        $this->assertSame(5, $first['winner_tie_count']);
        $this->assertSame(12, $first['second_third_tie_count']);
        $this->assertTrue($first['primary_decision_tied']);
        $this->assertTrue($first['primary_technical_tiebreak_used']);
        $this->assertNotSame(
            [$first['primary_position_1_bike'], $first['primary_position_2_bike'], $first['primary_position_3_bike']],
            [$otherRace['primary_position_1_bike'], $otherRace['primary_position_2_bike'], $otherRace['primary_position_3_bike']],
        );
    }

    public function test_p1_tie_is_resolved_without_using_second_third_probabilities(): void
    {
        $race = $this->race();
        $race['race_id'] = 44;
        $race['entries'][1]['position_1_probability'] = $race['entries'][0]['position_1_probability'];
        $race['entries'][0]['position_2_probability'] = 0.0;
        $race['entries'][0]['position_3_probability'] = 0.0;
        $race['entries'][1]['position_2_probability'] = 1.0;
        $race['entries'][1]['position_3_probability'] = 1.0;

        $decision = (new Bt03e05DecisionDecoder)->decode($race);
        $keys = [];
        foreach ([1, 2] as $bike) {
            $keys[$bike] = hash('sha256', Bt03e05Contract::TIE_RULE_VERSION.'|PRIMARY_WINNER_P1|44|'.$bike);
        }
        asort($keys, SORT_STRING);

        $this->assertSame((int) array_key_first($keys), $decision['primary_position_1_bike']);
        $this->assertSame(2, $decision['winner_tie_count']);
    }

    public function test_second_third_exact_tie_uses_its_dedicated_sha_key(): void
    {
        $race = $this->race();
        $race['race_id'] = 45;
        foreach ($race['entries'] as &$entry) {
            if ($entry['bike'] !== 1) {
                $entry['position_2_probability'] = 0.2;
                $entry['position_3_probability'] = 0.2;
            }
        }
        unset($entry);

        $decision = (new Bt03e05DecisionDecoder)->decode($race);
        $keys = [];
        foreach ([2, 3, 4, 5] as $second) {
            foreach ([2, 3, 4, 5] as $third) {
                if ($second !== $third) {
                    $keys["{$second}-{$third}"] = hash(
                        'sha256',
                        Bt03e05Contract::TIE_RULE_VERSION."|PRIMARY_SECOND_THIRD|45|1-{$second}-{$third}",
                    );
                }
            }
        }
        asort($keys, SORT_STRING);
        $expected = array_map('intval', explode('-', (string) array_key_first($keys)));

        $this->assertSame([1, ...$expected], [
            $decision['primary_position_1_bike'],
            $decision['primary_position_2_bike'],
            $decision['primary_position_3_bike'],
        ]);
        $this->assertSame(12, $decision['second_third_tie_count']);
    }

    #[DataProvider('entrantCountProvider')]
    public function test_supported_entrant_counts_produce_three_distinct_positions(int $entrantCount): void
    {
        $decision = (new Bt03e05DecisionDecoder)->decode($this->uniformRace(100 + $entrantCount, $entrantCount));

        $this->assertCount(3, array_unique([
            $decision['primary_position_1_bike'],
            $decision['primary_position_2_bike'],
            $decision['primary_position_3_bike'],
        ]));
    }

    /** @return iterable<string,array{int}> */
    public static function entrantCountProvider(): iterable
    {
        yield 'five' => [5];
        yield 'seven' => [7];
        yield 'nine' => [9];
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
    private function uniformRace(int $raceId, int $entrantCount = 5): array
    {
        $entries = [];
        foreach (range(1, $entrantCount) as $bike) {
            $entries[] = [
                'bike' => $bike,
                'position_1_probability' => 1 / $entrantCount,
                'position_2_probability' => 1 / $entrantCount,
                'position_3_probability' => 1 / $entrantCount,
                'top2_probability' => 2 / $entrantCount,
                'top3_probability' => 3 / $entrantCount,
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
