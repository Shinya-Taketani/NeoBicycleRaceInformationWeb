<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e03ProbabilityScorer;
use App\Domain\Keirin\Backtest\Calculators\Bt03e06WinnerConditionedDecoder;
use App\Domain\Keirin\Backtest\Calculators\Bt03e08P1Q2FrozenDecoder;
use App\Domain\Keirin\Backtest\Calculators\Bt03e08WinnerConditionedP3Scorer;
use App\Domain\Keirin\Backtest\DTO\Bt03e08FitResultDto;
use App\Domain\Keirin\Backtest\Services\Bt03e08Contract;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use PHPUnit\Framework\TestCase;

final class Bt03e08ProbabilityDecoderTest extends TestCase
{
    public function test_p3_masks_winner_in_softmax_denominator_and_not_after_full_field_softmax(): void
    {
        $scorer = $this->p3Scorer();
        $fit = $this->fit([10.0, 0.0, 0.0, 0.0, 0.0]);
        $prediction = $scorer->predict($this->binnedRace(), $fit, 1);
        $values = array_column($prediction['entries'], 'r3_probability', 'bike');
        $this->assertSame(0.0, $values[1]);
        $this->assertEqualsWithDelta(1.0, array_sum($values), 1e-12);
        $this->assertSame(0.25, $values[2]);
        $fullFieldThenMask = exp(0.0) / (exp(10.0) + 4 * exp(0.0));
        $this->assertNotEqualsWithDelta($fullFieldThenMask, $values[2], 1e-12);
    }

    public function test_p1_q2_and_supporting_outputs_are_frozen_to_e06_and_equal_r3_reproduces_e06_decision(): void
    {
        $source = $this->sourceRace();
        $e06 = $this->sourceDecoder();
        $frozen = $e06->decode($source);
        $winner = $frozen['primary_position_1_bike'];
        $conditionals = $e06->conditionals($source['entries'], $winner - 1);
        $p3 = ['year' => 2024, 'race_id' => 77, 'winner_bike' => $winner, 'entries' => []];
        foreach ($source['entries'] as $offset => $entry) {
            $p3['entries'][] = ['id' => $offset + 1, 'bike' => $entry['bike'], 'r3_probability' => $conditionals['q3_marginal'][$offset]];
        }
        $candidate = (new Bt03e08P1Q2FrozenDecoder($e06, new CanonicalHasher))->decode($source, $p3);
        $this->assertSame($frozen['primary_position_1_bike'], $candidate['primary_position_1_bike']);
        $this->assertSame($frozen['primary_position_2_bike'], $candidate['primary_position_2_bike']);
        $this->assertSame($frozen['primary_position_3_bike'], $candidate['primary_position_3_bike']);
        $this->assertSame($frozen['winner_p1'], $candidate['source_p1']);
        $this->assertSame($frozen['selected_q2_given_winner'], $candidate['selected_q2']);
        foreach (['map_ordered_top3', 'map_ordered_probability', 'map_top3_set', 'map_top3_set_probability', 'top2_marginal_bikes', 'top3_marginal_bikes', 'expected_ndcg_top3'] as $field) {
            $this->assertSame($frozen[$field], $candidate[$field]);
        }
        $this->assertTrue($candidate['p1_freeze_verified']);
        $this->assertTrue($candidate['q2_freeze_verified']);
    }

    public function test_exact_pair_tie_uses_frozen_e05_sha_rule(): void
    {
        $source = $this->sourceRace(true);
        $e06 = $this->sourceDecoder();
        $frozen = $e06->decode($source);
        $winner = $frozen['primary_position_1_bike'];
        $p3 = ['year' => 2024, 'race_id' => 77, 'winner_bike' => $winner, 'entries' => []];
        foreach ($source['entries'] as $entry) {
            $p3['entries'][] = ['id' => $entry['bike'], 'bike' => $entry['bike'], 'r3_probability' => $entry['bike'] === $winner ? 0.0 : 0.25];
        }
        $candidate = (new Bt03e08P1Q2FrozenDecoder($e06, new CanonicalHasher))->decode($source, $p3);
        $keys = [];
        foreach (range(1, 5) as $second) {
            foreach (range(1, 5) as $third) {
                if ($second !== $winner && $third !== $winner && $second !== $third) {
                    $identity = $winner.'-'.$second.'-'.$third;
                    $keys[$second.'-'.$third] = hash('sha256', Bt03e08Contract::PRIMARY_TIE_RULE_VERSION.'|PRIMARY_SECOND_THIRD|77|'.$identity);
                }
            }
        }
        asort($keys, SORT_STRING);
        $expected = array_map('intval', explode('-', (string) array_key_first($keys)));
        $this->assertSame($expected, [$candidate['primary_position_2_bike'], $candidate['primary_position_3_bike']]);
        $this->assertSame(count($keys), $candidate['second_third_tie_count']);
    }

    private function p3Scorer(): Bt03e08WinnerConditionedP3Scorer
    {
        return new Bt03e08WinnerConditionedP3Scorer(new Bt03e03ProbabilityScorer, new CanonicalHasher);
    }

    private function sourceDecoder(): Bt03e06WinnerConditionedDecoder
    {
        return new Bt03e06WinnerConditionedDecoder(new Bt03e03ProbabilityScorer, new CanonicalHasher);
    }

    /** @param list<float> $coefficients */
    private function fit(array $coefficients): Bt03e08FitResultDto
    {
        return new Bt03e08FitResultDto(0.0, $coefficients, 0.0, 1, 1, 0, []);
    }

    /** @return array<string,mixed> */
    private function binnedRace(): array
    {
        $entries = [];
        foreach (range(1, 5) as $bike) {
            $bins = array_fill(0, count(Bt03e08Contract::STAT_CODES), null);
            $bins[0] = $bike - 1;
            $entries[] = ['id' => $bike, 'bike' => $bike, 'anchor' => 0.0, 'bins' => $bins];
        }

        return ['year' => 2024, 'race_id' => 77, 'entries' => $entries];
    }

    /** @return array<string,mixed> */
    private function sourceRace(bool $ties = false): array
    {
        $entries = [];
        foreach (range(1, 5) as $bike) {
            $p1 = $ties ? 0.2 : ($bike === 1 ? 0.6 : 0.1);
            $entries[] = ['bike' => $bike, 'position_1_probability' => $p1, 'position_2_probability' => 0.2, 'position_3_probability' => 0.2, 'top2_probability' => $p1 + 0.2, 'top3_probability' => $p1 + 0.4, 'utilities' => ['POSITION_1' => $bike === 1 ? 2.0 : 0.0, 'POSITION_2' => 0.0, 'POSITION_3' => 0.0]];
        }

        return ['year' => 2024, 'race_id' => 77, 'entries' => $entries, 'map_ordered_top3' => [1, 2, 3], 'map_ordered_probability' => 0.1, 'map_top3_set' => [1, 2, 3], 'map_top3_set_probability' => 0.2];
    }
}
