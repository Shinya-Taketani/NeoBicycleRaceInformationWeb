<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e02ParameterLayout;
use App\Domain\Keirin\Backtest\Calculators\Bt03e03ProbabilityScorer;
use App\Domain\Keirin\Backtest\DTO\Bt03e03FitResultDto;
use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use PHPUnit\Framework\TestCase;

class Bt03e03ProbabilityTest extends TestCase
{
    public function test_stable_softmax_known_case_and_uniform_case(): void
    {
        $scorer = new Bt03e03ProbabilityScorer;
        $known = array_map('exp', $scorer->conditionalLogProbabilities([0.0, log(2.0)], []));
        $uniform = array_map('exp', $scorer->conditionalLogProbabilities([1000.0, 1000.0, 1000.0], []));

        $this->assertEqualsWithDelta(1 / 3, $known[0], 1e-15);
        $this->assertEqualsWithDelta(2 / 3, $known[1], 1e-15);
        foreach ($uniform as $probability) {
            $this->assertEqualsWithDelta(1 / 3, $probability, 3e-14);
        }
    }

    public function test_uniform_utilities_produce_exact_position_marginals_and_six_permutation_set_sum(): void
    {
        $prediction = $this->scorer()->predict($this->race(5), $this->fit(5));

        foreach ($prediction['entries'] as $entry) {
            $this->assertEqualsWithDelta(0.2, $entry['position_1_probability'], 1e-14);
            $this->assertEqualsWithDelta(0.2, $entry['position_2_probability'], 1e-14);
            $this->assertEqualsWithDelta(0.2, $entry['position_3_probability'], 1e-14);
            $this->assertEqualsWithDelta(0.4, $entry['top2_probability'], 1e-14);
            $this->assertEqualsWithDelta(0.6, $entry['top3_probability'], 1e-14);
        }
        $this->assertEqualsWithDelta(6 / (5 * 4 * 3), $prediction['map_top3_set_probability'], 1e-14);
    }

    public function test_probability_invariants_hold_for_five_seven_and_nine_car_races(): void
    {
        foreach ([5, 7, 9] as $entrantCount) {
            $prediction = $this->scorer()->predict(
                $this->race($entrantCount),
                $this->fit($entrantCount, $this->positionCoefficients($entrantCount)),
            );

            foreach ($prediction['probability_invariants'] as $sum) {
                $this->assertEqualsWithDelta(1.0, $sum, Bt03e03Contract::PROBABILITY_TOLERANCE);
            }
            foreach ($prediction['entries'] as $entry) {
                $this->assertEqualsWithDelta(
                    $entry['position_1_probability'] + $entry['position_2_probability'],
                    $entry['top2_probability'],
                    1e-15,
                );
                $this->assertEqualsWithDelta(
                    $entry['top2_probability'] + $entry['position_3_probability'],
                    $entry['top3_probability'],
                    1e-15,
                );
                $this->assertLessThanOrEqual(1.0 + Bt03e03Contract::PROBABILITY_TOLERANCE, $entry['top3_probability']);
            }
        }
    }

    public function test_extreme_utilities_remain_finite_and_normalized(): void
    {
        $coefficients = $this->positionCoefficients(5);
        foreach ($coefficients as &$position) {
            foreach ($position as $index => $_) {
                $position[$index] = $index === 0 ? 1000.0 : -1000.0;
            }
        }
        unset($position);

        $prediction = $this->scorer()->predict($this->race(5), $this->fit(5, $coefficients));

        foreach ($prediction['entries'] as $entry) {
            foreach (['position_1_probability', 'position_2_probability', 'position_3_probability', 'top2_probability', 'top3_probability'] as $key) {
                $this->assertTrue(is_finite($entry[$key]), $key);
            }
        }
        foreach ($prediction['probability_invariants'] as $sum) {
            $this->assertEqualsWithDelta(1.0, $sum, 1e-12);
        }
    }

    public function test_map_ordered_top3_matches_known_position_utilities(): void
    {
        $coefficients = $this->positionCoefficients(5);
        $coefficients['POSITION_1'][0] = 8.0;
        $coefficients['POSITION_2'][1] = 8.0;
        $coefficients['POSITION_3'][2] = 8.0;

        $prediction = $this->scorer()->predict($this->race(5), $this->fit(5, $coefficients));

        $this->assertSame([1, 2, 3], $prediction['map_ordered_top3']);
        $this->assertSame([1, 2, 3], array_column(array_slice($this->ranked($prediction['entries']), 0, 3), 'bike'));
    }

    public function test_map_tie_is_deterministic_and_audited(): void
    {
        $first = $this->scorer()->predict($this->race(5, 91), $this->fit(5));
        $second = $this->scorer()->predict($this->race(5, 91), $this->fit(5));

        $this->assertSame($first['map_ordered_top3'], $second['map_ordered_top3']);
        $this->assertSame(1, $first['map_tie_diagnostics']['ordered_probability_tied_race']);
        $this->assertTrue($first['map_tie_diagnostics']['technical_tiebreak_used']);
    }

    private function scorer(): Bt03e03ProbabilityScorer
    {
        return new Bt03e03ProbabilityScorer;
    }

    /** @return array<string,mixed> */
    private function race(int $entrantCount, int $raceId = 1): array
    {
        $entries = [];
        foreach (range(1, $entrantCount) as $bike) {
            $bins = array_fill(0, count(Bt03e03Contract::STAT_CODES), null);
            $bins[0] = $bike - 1;
            $entries[] = [
                'id' => $raceId * 10 + $bike,
                'bike' => $bike,
                'raw' => 100.0 - $bike,
                'stat01_rank' => $bike,
                'anchor' => 0.0,
                'bins' => $bins,
                'rank' => $bike,
                'status' => 'FINISHED',
            ];
        }

        return ['year' => 2024, 'race_id' => $raceId, 'entries' => $entries];
    }

    /** @param array<string,list<float>>|null $coefficients */
    private function fit(int $entrantCount, ?array $coefficients = null): Bt03e03FitResultDto
    {
        $coefficients ??= array_fill_keys(Bt03e03Contract::POSITIONS, array_fill(0, $this->layout($entrantCount)->size(), 0.0));

        return new Bt03e03FitResultDto(
            0.1,
            $coefficients,
            array_fill_keys(Bt03e03Contract::POSITIONS, 0.0),
            array_fill_keys(Bt03e03Contract::POSITIONS, 1),
            array_fill_keys(Bt03e03Contract::POSITIONS, 1),
            array_fill_keys(Bt03e03Contract::POSITIONS, 0),
        );
    }

    /** @return array<string,list<float>> */
    private function positionCoefficients(int $entrantCount): array
    {
        $coefficients = array_fill_keys(Bt03e03Contract::POSITIONS, array_fill(0, $this->layout($entrantCount)->size(), 0.0));
        foreach ($coefficients as &$position) {
            foreach (range(0, $entrantCount - 1) as $index) {
                $position[$index] = ($entrantCount - $index) / 10;
            }
        }
        unset($position);

        return $coefficients;
    }

    private function layout(int $entrantCount): Bt03e02ParameterLayout
    {
        $bins = [];
        foreach (Bt03e03Contract::STAT_CODES as $statCode) {
            foreach (range(1, $entrantCount) as $index) {
                $bins[$statCode][] = new EffectBinDto($index, 'CATEGORY', null, null, (string) $index, 1);
            }
        }

        return new Bt03e02ParameterLayout($bins);
    }

    /** @param list<array<string,mixed>> $entries @return list<array<string,mixed>> */
    private function ranked(array $entries): array
    {
        usort($entries, static fn (array $left, array $right): int => $left['predicted_position'] <=> $right['predicted_position']);

        return $entries;
    }
}
