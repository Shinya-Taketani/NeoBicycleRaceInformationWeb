<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Statistics;

use App\Domain\Keirin\Statistics\Calculators\Stat01Calculator;
use App\Domain\Keirin\Statistics\DTO\Stat01EntryInputDto;
use App\Domain\Keirin\Statistics\DTO\Stat01EntryResultDto;
use App\Domain\Keirin\Statistics\DTO\Stat01RaceInputDto;
use App\Domain\Keirin\Statistics\Enums\StatisticAcquisitionMode;
use App\Domain\Keirin\Statistics\Enums\StatisticQualityStatus;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class Stat01CalculatorTest extends TestCase
{
    #[DataProvider('entrantCounts')]
    public function test_it_calculates_supported_race_sizes(int $entrantCount): void
    {
        $scores = array_map(
            static fn (int $index): string => number_format(110 - $index, 2, '.', ''),
            range(0, $entrantCount - 1),
        );

        $calculation = $this->calculator()->calculate($this->race($scores));

        $this->assertCount($entrantCount, $calculation->results);
        $this->assertSame(range(1, $entrantCount), array_column($calculation->results, 'scoreRank'));
        $this->assertSame(1.0, $calculation->results[0]->strengthPercentile);
        $this->assertSame(0.0, $calculation->results[$entrantCount - 1]->strengthPercentile);
        foreach ($calculation->results as $result) {
            $this->assertSame($entrantCount, $result->validScoreCount);
            $this->assertSame(StatisticQualityStatus::HistoricalSnapshot, $result->qualityStatus);
        }
    }

    /**
     * @return list<array{int}>
     */
    public static function entrantCounts(): array
    {
        return [[5], [7], [9]];
    }

    public function test_it_uses_competition_rank_dense_rank_and_documented_percentile_for_ties(): void
    {
        $results = $this->byBike($this->calculator()->calculate(
            $this->race(['100.00', '95.00', '95.00', '90.00']),
        )->results);

        $this->assertSame([1, 2, 2, 4], array_values(array_map(
            static fn (Stat01EntryResultDto $result): ?int => $result->scoreRank,
            $results,
        )));
        $this->assertSame([1, 2, 2, 3], array_values(array_map(
            static fn (Stat01EntryResultDto $result): ?int => $result->denseRank,
            $results,
        )));
        $this->assertEqualsWithDelta(1.0, $results[1]->strengthPercentile, 0.00000001);
        $this->assertEqualsWithDelta(2 / 3, $results[2]->strengthPercentile, 0.00000001);
        $this->assertEqualsWithDelta(2 / 3, $results[3]->strengthPercentile, 0.00000001);
        $this->assertEqualsWithDelta(0.0, $results[4]->strengthPercentile, 0.00000001);
    }

    public function test_partial_missing_and_invalid_inputs_are_not_imputed(): void
    {
        $results = $this->byBike($this->calculator()->calculate(
            $this->race(['100.00', null, '0.00', '90.00', '80.00']),
        )->results);

        $this->assertSame(3, $results[1]->validScoreCount);
        $this->assertSame(1, $results[1]->missingScoreCount);
        $this->assertSame(1, $results[1]->invalidScoreCount);
        $this->assertSame(StatisticQualityStatus::Partial, $results[1]->qualityStatus);
        $this->assertSame(StatisticQualityStatus::MissingInput, $results[2]->qualityStatus);
        $this->assertNull($results[2]->raceScore);
        $this->assertNull($results[2]->scoreRank);
        $this->assertSame(StatisticQualityStatus::InvalidInput, $results[3]->qualityStatus);
        $this->assertNull($results[3]->raceScore);
        $this->assertNull($results[3]->scoreRank);
        $this->assertEqualsWithDelta(90.0, $results[4]->raceAverageScore, 0.000001);
    }

    public function test_all_missing_inputs_are_blocked_without_zero_substitution(): void
    {
        $results = $this->calculator()->calculate($this->race([null, null, null, null, null]))->results;

        foreach ($results as $result) {
            $this->assertSame(StatisticQualityStatus::Blocked, $result->qualityStatus);
            $this->assertSame(0, $result->validScoreCount);
            $this->assertSame(5, $result->missingScoreCount);
            $this->assertNull($result->raceAverageScore);
            $this->assertNull($result->raceMaxScore);
            $this->assertNull($result->zScore);
        }
    }

    public function test_all_tied_and_single_entry_races_do_not_divide_by_zero(): void
    {
        $tied = $this->calculator()->calculate($this->race(array_fill(0, 5, '95.00')))->results;
        foreach ($tied as $result) {
            $this->assertSame(1, $result->scoreRank);
            $this->assertSame(1, $result->denseRank);
            $this->assertSame(1.0, $result->strengthPercentile);
            $this->assertSame(0.0, $result->raceStandardDeviation);
            $this->assertNull($result->zScore);
        }

        $single = $this->calculator()->calculate($this->race(['95.00']))->results[0];
        $this->assertSame(1.0, $single->strengthPercentile);
        $this->assertSame(0.0, $single->raceStandardDeviation);
        $this->assertNull($single->zScore);
    }

    public function test_average_maximum_differences_population_deviation_and_z_score_are_calculated(): void
    {
        $results = $this->byBike($this->calculator()->calculate(
            $this->race(['100.00', '90.00', '80.00']),
        )->results);

        $this->assertEqualsWithDelta(90.0, $results[1]->raceAverageScore, 0.000001);
        $this->assertEqualsWithDelta(100.0, $results[1]->raceMaxScore, 0.000001);
        $this->assertEqualsWithDelta(10.0, $results[1]->differenceFromAverage, 0.000001);
        $this->assertEqualsWithDelta(0.0, $results[1]->differenceFromMax, 0.000001);
        $this->assertEqualsWithDelta(-10.0, $results[3]->differenceFromAverage, 0.000001);
        $this->assertEqualsWithDelta(-20.0, $results[3]->differenceFromMax, 0.000001);
        $this->assertEqualsWithDelta(sqrt(200 / 3), $results[1]->raceStandardDeviation, 0.000001);
        $this->assertEqualsWithDelta(sqrt(1.5), $results[1]->zScore, 0.000001);
        $this->assertEqualsWithDelta(0.0, $results[2]->zScore, 0.000001);
        $this->assertEqualsWithDelta(-sqrt(1.5), $results[3]->zScore, 0.000001);
    }

    public function test_input_order_does_not_change_hash_results_or_entry_player_bike_mapping(): void
    {
        $race = $this->race(['100.00', '95.00', '90.00', '85.00', '80.00']);
        $reversed = new Stat01RaceInputDto(
            raceId: $race->raceId,
            source: $race->source,
            raceDate: $race->raceDate,
            scheduledStartAt: $race->scheduledStartAt,
            entries: array_reverse($race->entries),
        );

        $first = $this->calculator()->calculate($race);
        $second = $this->calculator()->calculate($reversed);

        $this->assertSame($first->inputHash, $second->inputHash);
        $this->assertEquals($first->results, $second->results);
        foreach ($first->results as $index => $result) {
            $expected = $index + 1;
            $this->assertSame(1000 + $expected, $result->raceEntryId);
            $this->assertSame(2000 + $expected, $result->playerId);
            $this->assertSame($expected, $result->bikeNumber);
        }
    }

    private function calculator(): Stat01Calculator
    {
        return new Stat01Calculator;
    }

    /**
     * @param  list<?string>  $scores
     */
    private function race(array $scores): Stat01RaceInputDto
    {
        return new Stat01RaceInputDto(
            raceId: 99,
            source: 'keirin_jp',
            raceDate: '2024-01-01',
            scheduledStartAt: new DateTimeImmutable('2024-01-01 12:00:00 Asia/Tokyo'),
            entries: array_map(
                static fn (?string $score, int $index): Stat01EntryInputDto => new Stat01EntryInputDto(
                    raceEntryId: 1001 + $index,
                    playerId: 2001 + $index,
                    bikeNumber: 1 + $index,
                    raceScore: $score,
                    fetchedAt: new DateTimeImmutable('2026-07-24 12:00:00 Asia/Tokyo'),
                    acquisitionMode: StatisticAcquisitionMode::HistoricalRaceCard,
                ),
                $scores,
                array_keys($scores),
            ),
        );
    }

    /**
     * @param  list<Stat01EntryResultDto>  $results
     * @return array<int,Stat01EntryResultDto>
     */
    private function byBike(array $results): array
    {
        $indexed = [];
        foreach ($results as $result) {
            $indexed[$result->bikeNumber] = $result;
        }

        return $indexed;
    }
}
