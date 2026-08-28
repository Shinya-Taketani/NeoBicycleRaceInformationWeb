<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e03ProbabilityScorer;
use App\Domain\Keirin\Backtest\Calculators\Bt03e05DecisionDecoder;
use App\Domain\Keirin\Backtest\Calculators\Bt03e06WinnerConditionedDecoder;
use App\Domain\Keirin\Backtest\Services\Bt03e06Contract;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class Bt03e06DecoderTest extends TestCase
{
    #[DataProvider('entrantCounts')]
    public function test_conditional_distributions_and_supporting_outputs_are_fixed(int $count): void
    {
        $race = $this->race(500 + $count, $count);
        $decoder = $this->decoder();
        $decision = $decoder->decode($race);
        $conditionals = $decoder->conditionals($race['entries'], 0);
        $e05 = (new Bt03e05DecisionDecoder)->decode($race);

        $this->assertEqualsWithDelta(1.0, array_sum($conditionals['q2']), 1e-12);
        $this->assertSame(0.0, $conditionals['q2'][0]);
        foreach ($conditionals['q3_paths'] as $second => $path) {
            $this->assertEqualsWithDelta(1.0, array_sum($path), 1e-12);
            $this->assertSame(0.0, $path[0]);
            $this->assertSame(0.0, $path[$second]);
        }
        $this->assertEqualsWithDelta(1.0, array_sum($conditionals['q3_marginal']), 1e-12);
        $this->assertSame(0.0, $conditionals['q3_marginal'][0]);
        $this->assertSame($e05['primary_position_1_bike'], $decision['primary_position_1_bike']);
        foreach (['map_ordered_top3', 'map_ordered_probability', 'map_top3_set', 'map_top3_set_probability', 'top2_marginal_bikes', 'top3_marginal_bikes', 'expected_ndcg_top3'] as $field) {
            $this->assertSame($e05[$field], $decision[$field]);
        }
    }

    public function test_q2_and_q3_match_manual_softmax_and_marginalization(): void
    {
        $race = $this->race(601, 5);
        $conditionals = $this->decoder()->conditionals($race['entries'], 0);
        $u2 = array_column(array_column($race['entries'], 'utilities'), 'POSITION_2');
        $manualQ2 = $this->softmax($u2, [0]);
        foreach ($manualQ2 as $offset => $probability) {
            $this->assertEqualsWithDelta($probability, $conditionals['q2'][$offset], 1e-15);
        }

        $u3 = array_column(array_column($race['entries'], 'utilities'), 'POSITION_3');
        $manualMarginal = array_fill(0, 5, 0.0);
        foreach ([1, 2, 3, 4] as $second) {
            $path = $this->softmax($u3, [0, $second]);
            foreach ($path as $third => $probability) {
                $manualMarginal[$third] += $manualQ2[$second] * $probability;
                $this->assertEqualsWithDelta($probability, $conditionals['q3_paths'][$second][$third], 1e-15);
            }
        }
        foreach ($manualMarginal as $offset => $probability) {
            $this->assertEqualsWithDelta($probability, $conditionals['q3_marginal'][$offset], 1e-15);
        }
    }

    public function test_selected_pair_is_the_global_objective_maximum(): void
    {
        $race = $this->race(602, 7);
        $decoder = $this->decoder();
        $decision = $decoder->decode($race);
        $conditionals = $decoder->conditionals($race['entries'], 0);
        $scores = [];
        foreach (range(1, 6) as $second) {
            foreach (range(1, 6) as $third) {
                if ($second !== $third) {
                    $scores[($second + 1).'-'.($third + 1)] = $conditionals['q2'][$second] + $conditionals['q3_marginal'][$third];
                }
            }
        }

        $selected = $decision['primary_position_2_bike'].'-'.$decision['primary_position_3_bike'];
        $this->assertSame(max($scores), $scores[$selected]);
        $this->assertSame(max($scores), $decision['primary_second_third_objective_score']);
    }

    public function test_exact_winner_and_pair_ties_use_the_e05_historical_sha_rule(): void
    {
        $race = $this->race(603, 5, true);
        $decision = $this->decoder()->decode($race);
        $e05 = (new Bt03e05DecisionDecoder)->decode($race);
        $this->assertSame($e05['primary_position_1_bike'], $decision['primary_position_1_bike']);

        $winner = $decision['primary_position_1_bike'];
        $keys = [];
        foreach (range(1, 5) as $second) {
            foreach (range(1, 5) as $third) {
                if ($second !== $winner && $third !== $winner && $second !== $third) {
                    $keys["{$second}-{$third}"] = hash(
                        'sha256',
                        Bt03e06Contract::PRIMARY_TIE_RULE_VERSION."|PRIMARY_SECOND_THIRD|603|{$winner}-{$second}-{$third}",
                    );
                }
            }
        }
        asort($keys, SORT_STRING);
        $this->assertSame((string) array_key_first($keys), $decision['primary_position_2_bike'].'-'.$decision['primary_position_3_bike']);
        $this->assertSame(5, $decision['winner_tie_count']);
        $this->assertSame(12, $decision['second_third_tie_count']);
    }

    /** @return iterable<string,array{int}> */
    public static function entrantCounts(): iterable
    {
        yield 'five' => [5];
        yield 'seven' => [7];
        yield 'nine' => [9];
    }

    private function decoder(): Bt03e06WinnerConditionedDecoder
    {
        return new Bt03e06WinnerConditionedDecoder(new Bt03e03ProbabilityScorer, new CanonicalHasher);
    }

    /** @return array<string,mixed> */
    private function race(int $raceId, int $count, bool $uniform = false): array
    {
        $entries = [];
        foreach (range(1, $count) as $offset => $bike) {
            $p1 = $uniform ? 1 / $count : (0.35 - $offset * 0.025);
            $p2 = $uniform ? 1 / $count : (0.10 + $offset * 0.01);
            $p3 = $uniform ? 1 / $count : (0.09 + $offset * 0.008);
            $entries[] = [
                'bike' => $bike,
                'position_1_probability' => $p1,
                'position_2_probability' => $p2,
                'position_3_probability' => $p3,
                'top2_probability' => $p1 + $p2,
                'top3_probability' => $p1 + $p2 + $p3,
                'utilities' => [
                    'POSITION_1' => 0.0,
                    'POSITION_2' => $uniform ? 0.0 : ($offset - 2.0) / 3.0,
                    'POSITION_3' => $uniform ? 0.0 : (2.0 - $offset) / 4.0,
                ],
            ];
        }

        return [
            'year' => 2024,
            'race_id' => $raceId,
            'entries' => $entries,
            'map_ordered_top3' => [1, 2, 3],
            'map_ordered_probability' => 0.01,
            'map_top3_set' => [1, 2, 3],
            'map_top3_set_probability' => 0.05,
        ];
    }

    /** @param list<float> $utilities @param list<int> $excluded @return list<float> */
    private function softmax(array $utilities, array $excluded): array
    {
        $available = array_filter($utilities, static fn (float $_, int $offset): bool => ! in_array($offset, $excluded, true), ARRAY_FILTER_USE_BOTH);
        $maximum = max($available);
        $denominator = array_sum(array_map(static fn (float $utility): float => exp($utility - $maximum), $available));

        return array_map(
            static fn (float $utility, int $offset): float => in_array($offset, $excluded, true) ? 0.0 : exp($utility - $maximum) / $denominator,
            $utilities,
            array_keys($utilities),
        );
    }
}
