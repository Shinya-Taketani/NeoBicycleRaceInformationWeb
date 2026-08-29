<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05DecisionDecoder;
use App\Domain\Keirin\Backtest\Calculators\Bt03e07DirectPositionScorer;
use App\Domain\Keirin\Backtest\Calculators\Bt03e07P1FrozenDecisionDecoder;
use App\Domain\Keirin\Backtest\DTO\Bt03e07FitResultDto;
use App\Domain\Keirin\Backtest\Services\Bt03e07Contract;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Bt03e07ProbabilityDecoderTest extends TestCase
{
    #[DataProvider('entrantCounts')]
    public function test_direct_distributions_are_full_field_softmax_and_sum_to_one(int $count): void
    {
        $scorer = new Bt03e07DirectPositionScorer(new CanonicalHasher);
        $prediction = $scorer->predict($this->binnedRace($count), $this->fit(0));

        $this->assertCount($count, $prediction['entries']);
        $this->assertEqualsWithDelta(1.0, $prediction['probability_invariants']['direct_position_2_sum'], 1e-12);
        $this->assertEqualsWithDelta(1.0, $prediction['probability_invariants']['direct_position_3_sum'], 1e-12);
        foreach ($prediction['entries'] as $entry) {
            $this->assertEqualsWithDelta(1.0 / $count, $entry['direct_position_2_probability'], 1e-15);
            $this->assertEqualsWithDelta(1.0 / $count, $entry['direct_position_3_probability'], 1e-15);
        }
    }

    public function test_decoder_freezes_source_p1_and_uses_only_d2_plus_d3_for_the_ordered_pair(): void
    {
        $scorer = new Bt03e07DirectPositionScorer(new CanonicalHasher);
        $direct = $scorer->predict($this->binnedRace(5), $this->fit(5));
        $source = $this->sourceRace(5);
        $decision = (new Bt03e07P1FrozenDecisionDecoder)->decode($source, $direct);

        $this->assertSame(0.55, $decision['source_p1']);
        $this->assertSame(1, $decision['primary_position_1_bike']);
        $this->assertTrue($decision['p1_freeze_verified']);
        $byBike = [];
        foreach ($direct['entries'] as $entry) {
            $byBike[$entry['bike']] = $entry;
        }
        $expected = null;
        $best = -INF;
        foreach (array_keys($byBike) as $second) {
            foreach (array_keys($byBike) as $third) {
                if ($second === 1 || $third === 1 || $second === $third) {
                    continue;
                }
                $score = $byBike[$second]['direct_position_2_probability'] + $byBike[$third]['direct_position_3_probability'];
                if ($score > $best) {
                    $best = $score;
                    $expected = [$second, $third];
                }
            }
        }
        $this->assertSame($expected, [$decision['primary_position_2_bike'], $decision['primary_position_3_bike']]);
        $this->assertSame($byBike[$decision['primary_position_2_bike']]['direct_position_2_probability'], $decision['selected_d2']);
        $this->assertSame($byBike[$decision['primary_position_3_bike']]['direct_position_3_probability'], $decision['selected_d3']);
        $this->assertGreaterThan(0.0, $byBike[1]['direct_position_2_probability']);
        $this->assertGreaterThan(0.0, $byBike[1]['direct_position_3_probability']);
    }

    public function test_exact_p1_and_pair_ties_use_the_frozen_e05_sha_rule(): void
    {
        $source = $this->sourceRace(5, true);
        $direct = (new Bt03e07DirectPositionScorer(new CanonicalHasher))->predict($this->binnedRace(5), $this->fit(0));
        $decision = (new Bt03e07P1FrozenDecisionDecoder)->decode($source, $direct);

        $winnerKeys = [];
        foreach ([1, 2] as $bike) {
            $winnerKeys[$bike] = hash('sha256', Bt03e07Contract::PRIMARY_TIE_RULE_VERSION.'|PRIMARY_WINNER_P1|77|'.$bike);
        }
        asort($winnerKeys, SORT_STRING);
        $this->assertSame((int) array_key_first($winnerKeys), $decision['primary_position_1_bike']);
        $this->assertGreaterThan(1, $decision['second_third_tie_count']);
        $this->assertTrue($decision['primary_technical_tiebreak_used']);
    }

    #[DataProvider('winnerCases')]
    public function test_winner_and_all_supporting_outputs_are_identical_to_e05(int $count, bool $tie): void
    {
        $source = $this->sourceRace($count, $tie);
        $historical = (new Bt03e05DecisionDecoder)->decode($source);
        $direct = (new Bt03e07DirectPositionScorer(new CanonicalHasher))->predict($this->binnedRace($count), $this->fit(0));
        $candidate = (new Bt03e07P1FrozenDecisionDecoder)->decode($source, $direct);

        $this->assertSame($historical['primary_position_1_bike'], $candidate['primary_position_1_bike']);
        foreach (['map_ordered_top3', 'map_ordered_probability', 'map_top3_set', 'map_top3_set_probability', 'top2_marginal_bikes', 'top3_marginal_bikes', 'expected_ndcg_top3'] as $field) {
            $this->assertSame($historical[$field], $candidate[$field], $field);
        }
    }

    /** @return array<string,array{int}> */
    public static function entrantCounts(): array
    {
        return ['five' => [5], 'seven' => [7], 'nine' => [9]];
    }

    /** @return array<string,array{int,bool}> */
    public static function winnerCases(): array
    {
        return [
            'five normal' => [5, false], 'five tie' => [5, true],
            'seven normal' => [7, false], 'seven tie' => [7, true],
            'nine normal' => [9, false], 'nine tie' => [9, true],
        ];
    }

    /** @return array<string,mixed> */
    private function binnedRace(int $count): array
    {
        $entries = [];
        foreach (range(1, $count) as $bike) {
            $bins = array_fill(0, count(Bt03e07Contract::STAT_CODES), null);
            $bins[0] = $bike - 1;
            $entries[] = ['id' => $bike, 'bike' => $bike, 'anchor' => 0.0, 'bins' => $bins];
        }

        return ['year' => 2024, 'race_id' => 77, 'entries' => $entries];
    }

    private function fit(int $size): Bt03e07FitResultDto
    {
        $p2 = $p3 = array_fill(0, max(9, $size), 0.0);
        if ($size > 0) {
            $p2[1] = 2.0;
            $p3[2] = 3.0;
        }

        return new Bt03e07FitResultDto(0.0, ['POSITION_2' => $p2, 'POSITION_3' => $p3], [], [], [], [], []);
    }

    /** @return array<string,mixed> */
    private function sourceRace(int $count, bool $p1Tie = false): array
    {
        $entries = [];
        foreach (range(1, $count) as $bike) {
            $p1 = $p1Tie
                ? ($bike <= 2 ? 0.3 : 0.4 / ($count - 2))
                : ($bike === 1 ? 0.55 : 0.45 / ($count - 1));
            $p2 = 1.0 / $count;
            $p3 = 1.0 / $count;
            $entries[] = ['bike' => $bike, 'position_1_probability' => $p1, 'position_2_probability' => $p2, 'position_3_probability' => $p3, 'top2_probability' => $p1 + $p2, 'top3_probability' => $p1 + $p2 + $p3];
        }

        return ['year' => 2024, 'race_id' => 77, 'entries' => $entries, 'map_ordered_top3' => [1, 2, 3], 'map_ordered_probability' => 0.1, 'map_top3_set' => [1, 2, 3], 'map_top3_set_probability' => 0.2];
    }
}
