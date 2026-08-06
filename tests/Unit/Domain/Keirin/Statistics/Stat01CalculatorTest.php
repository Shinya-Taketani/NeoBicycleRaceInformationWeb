<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Statistics;

use App\Domain\Keirin\Statistics\Calculators\Stat01Calculator;
use App\Domain\Keirin\Statistics\DTO\Stat01EntryFeatureDto;
use App\Domain\Keirin\Statistics\DTO\Stat01EntryInputDto;
use App\Domain\Keirin\Statistics\DTO\Stat01RaceInputDto;
use App\Domain\Keirin\Statistics\Enums\StatisticFeatureResultStatus;
use App\Domain\Keirin\Statistics\Enums\StatisticQualityStatus;
use App\Domain\Keirin\Statistics\Support\DeterministicJsonHasher;
use DateTimeImmutable;
use Tests\TestCase;

class Stat01CalculatorTest extends TestCase
{
    private Stat01Calculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new Stat01Calculator(new DeterministicJsonHasher);
    }

    public function test_it_calculates_all_relative_features_with_competition_and_dense_ranks(): void
    {
        $result = $this->calculator->calculate($this->race(['80.00', '70.00', '70.00', '60.00']));
        $byBike = $this->byBike($result->entries);

        $this->assertSame([1, 2, 2, 4], array_map(
            fn (Stat01EntryFeatureDto $entry): ?int => $entry->features['RACE_SCORE_RANK'],
            $result->entries,
        ));
        $this->assertSame([1, 2, 2, 3], array_map(
            fn (Stat01EntryFeatureDto $entry): ?int => $entry->features['RACE_SCORE_DENSE_RANK'],
            $result->entries,
        ));
        $this->assertEqualsWithDelta(1.0, $byBike[1]->features['RACE_SCORE_STRENGTH_PERCENTILE'], 1e-12);
        $this->assertEqualsWithDelta(2 / 3, $byBike[2]->features['RACE_SCORE_STRENGTH_PERCENTILE'], 1e-12);
        $this->assertEqualsWithDelta(70.0, $byBike[1]->features['RACE_SCORE_RACE_MEAN'], 1e-12);
        $this->assertEqualsWithDelta(80.0, $byBike[1]->features['RACE_SCORE_RACE_MAX'], 1e-12);
        $this->assertEqualsWithDelta(10.0, $byBike[1]->features['RACE_SCORE_DIFF_FROM_MEAN'], 1e-12);
        $this->assertEqualsWithDelta(20.0, $byBike[4]->features['RACE_SCORE_GAP_TO_MAX'], 1e-12);
        $this->assertEqualsWithDelta(sqrt(50), $byBike[1]->features['RACE_SCORE_STDDEV_POP'], 1e-12);
        $this->assertEqualsWithDelta(sqrt(2), $byBike[1]->features['RACE_SCORE_Z'], 1e-12);
        $this->assertFalse($result->partial);
        $this->assertSame(StatisticFeatureResultStatus::Valid, $byBike[1]->status);
        $this->assertSame(StatisticQualityStatus::Full, $byBike[1]->qualityStatus);
    }

    public function test_zero_variance_and_one_valid_score_do_not_divide_by_zero(): void
    {
        $equal = $this->calculator->calculate($this->race(['70.00', '70.00']));
        foreach ($equal->entries as $entry) {
            $this->assertNull($entry->features['RACE_SCORE_Z']);
            $this->assertContains('ZERO_VARIANCE', $entry->evidence['quality_reasons']);
        }

        $single = $this->calculator->calculate($this->race(['70.00']));
        $this->assertSame(1, $single->entries[0]->features['RACE_SCORE_RANK']);
        $this->assertSame(1, $single->entries[0]->features['RACE_SCORE_DENSE_RANK']);
        $this->assertSame(1.0, $single->entries[0]->features['RACE_SCORE_STRENGTH_PERCENTILE']);
        $this->assertSame(0.0, $single->entries[0]->features['RACE_SCORE_STDDEV_POP']);
        $this->assertNull($single->entries[0]->features['RACE_SCORE_Z']);
    }

    public function test_missing_zero_and_negative_scores_are_excluded_from_relative_features(): void
    {
        $result = $this->calculator->calculate($this->race(['80.00', null, '0.00', '-1.00']));
        [$valid, $missing, $zero, $negative] = $result->entries;

        $this->assertSame(StatisticFeatureResultStatus::Partial, $valid->status);
        $this->assertSame(StatisticFeatureResultStatus::MissingInput, $missing->status);
        $this->assertSame(StatisticFeatureResultStatus::InvalidInput, $zero->status);
        $this->assertSame(StatisticFeatureResultStatus::InvalidInput, $negative->status);
        $this->assertSame(1, $valid->features['VALID_SCORE_COUNT']);
        $this->assertSame(1, $valid->features['MISSING_SCORE_COUNT']);
        $this->assertSame(2, $valid->features['INVALID_SCORE_COUNT']);
        $this->assertSame(0.25, $valid->evidence['score_coverage_rate']);
        foreach ([$missing, $zero, $negative] as $unavailable) {
            $this->assertFalse($unavailable->features['RACE_SCORE_AVAILABLE']);
            $this->assertNull($unavailable->features['RACE_SCORE_RANK']);
            $this->assertNull($unavailable->features['RACE_SCORE_RACE_MEAN']);
            $this->assertNull($unavailable->features['RACE_SCORE_Z']);
        }
    }

    public function test_all_missing_and_all_invalid_scores_remain_partial_without_relative_values(): void
    {
        foreach ([[null, null], ['0.00', '-2.00']] as $scores) {
            $result = $this->calculator->calculate($this->race($scores));
            $this->assertTrue($result->partial);
            foreach ($result->entries as $entry) {
                $this->assertNull($entry->features['RACE_SCORE_RACE_MEAN']);
                $this->assertNull($entry->features['RACE_SCORE_STDDEV_POP']);
                $this->assertNull($entry->features['RACE_SCORE_Z']);
            }
        }
    }

    public function test_unresolved_player_still_calculates_score_with_degraded_quality(): void
    {
        $entries = $this->entries(['80.00', '70.00']);
        $entries[0] = new Stat01EntryInputDto(
            id: $entries[0]->id,
            playerId: null,
            bikeNumber: $entries[0]->bikeNumber,
            grade: $entries[0]->grade,
            raceScore: $entries[0]->raceScore,
            fetchedAt: $entries[0]->fetchedAt,
        );

        $result = $this->calculator->calculate($this->raceFromEntries($entries));
        $this->assertSame(StatisticFeatureResultStatus::Partial, $result->entries[0]->status);
        $this->assertSame(StatisticQualityStatus::Degraded, $result->entries[0]->qualityStatus);
        $this->assertSame(1, $result->entries[0]->features['RACE_SCORE_RANK']);
        $this->assertContains('PLAYER_ID_UNRESOLVED', $result->entries[0]->evidence['quality_reasons']);
    }

    public function test_input_as_of_fallback_and_missing_timing_are_recorded_safely(): void
    {
        $entries = $this->entries(['80.00']);
        $fallback = $this->calculator->calculate($this->raceFromEntries(
            $entries,
            salesCloseAt: null,
            scheduledStartAt: new DateTimeImmutable('2024-01-01 12:00:00+09:00'),
        ));
        $this->assertSame('SCHEDULED_START_AT_FALLBACK', $fallback->entries[0]->evidence['input_as_of_source']);
        $this->assertTrue($fallback->entries[0]->evidence['source_fetched_after_start']);
        $this->assertSame(StatisticFeatureResultStatus::Valid, $fallback->entries[0]->status);

        $missing = $this->calculator->calculate($this->raceFromEntries(
            $entries,
            salesCloseAt: null,
            scheduledStartAt: null,
        ));
        $this->assertNull($missing->entries[0]->inputAsOf);
        $this->assertSame(StatisticFeatureResultStatus::Partial, $missing->entries[0]->status);
        $this->assertSame(StatisticQualityStatus::Degraded, $missing->entries[0]->qualityStatus);
        $this->assertSame('MISSING', $missing->entries[0]->evidence['input_as_of_source']);
    }

    public function test_entry_count_mismatch_is_partial_and_coverage_uses_expected_entrant_count(): void
    {
        $result = $this->calculator->calculate($this->raceFromEntries(
            $this->entries(['80.00', '70.00']),
            entrantCount: 5,
        ));

        $this->assertTrue($result->partial);
        $this->assertSame(StatisticFeatureResultStatus::Partial, $result->entries[0]->status);
        $this->assertSame(StatisticQualityStatus::Partial, $result->entries[0]->qualityStatus);
        $this->assertFalse($result->entries[0]->evidence['entry_count_matches']);
        $this->assertSame(0.4, $result->entries[0]->evidence['score_coverage_rate']);
    }

    public function test_input_order_does_not_change_features_or_hashes(): void
    {
        $entries = $this->entries(['80.00', '70.00', '60.00']);
        $forward = $this->calculator->calculate($this->raceFromEntries($entries));
        $reverse = $this->calculator->calculate($this->raceFromEntries(array_reverse($entries)));

        foreach ($forward->entries as $index => $entry) {
            $this->assertSame($entry->entry->id, $reverse->entries[$index]->entry->id);
            $this->assertSame($entry->features, $reverse->entries[$index]->features);
            $this->assertSame($entry->inputHash, $reverse->entries[$index]->inputHash);
        }
    }

    public function test_input_hash_is_stable_and_changes_when_an_input_changes(): void
    {
        $first = $this->calculator->calculate($this->race(['80.00']))->entries[0];
        $same = $this->calculator->calculate($this->race(['80.00']))->entries[0];
        $changed = $this->calculator->calculate($this->race(['81.00']))->entries[0];

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first->inputHash);
        $this->assertSame($first->inputHash, $same->inputHash);
        $this->assertNotSame($first->inputHash, $changed->inputHash);
    }

    public function test_canonical_hash_is_independent_of_associative_key_order(): void
    {
        $hasher = new DeterministicJsonHasher;

        $this->assertSame(
            $hasher->hash(['race' => ['id' => 1, 'score' => '80.00'], 'mode' => 'BACKFILL']),
            $hasher->hash(['mode' => 'BACKFILL', 'race' => ['score' => '80.00', 'id' => 1]]),
        );
    }

    /** @param list<?string> $scores */
    private function race(array $scores): Stat01RaceInputDto
    {
        return $this->raceFromEntries($this->entries($scores));
    }

    /** @param list<Stat01EntryInputDto> $entries */
    private function raceFromEntries(
        array $entries,
        ?DateTimeImmutable $salesCloseAt = new DateTimeImmutable('2024-01-01 11:55:00+09:00'),
        ?DateTimeImmutable $scheduledStartAt = new DateTimeImmutable('2024-01-01 12:00:00+09:00'),
        ?int $entrantCount = null,
    ): Stat01RaceInputDto {
        return new Stat01RaceInputDto(
            id: 101,
            raceDate: new DateTimeImmutable('2024-01-01'),
            raceType: 'Ａ級予選',
            entrantCount: $entrantCount ?? count($entries),
            salesCloseAt: $salesCloseAt,
            scheduledStartAt: $scheduledStartAt,
            entries: $entries,
        );
    }

    /** @param list<?string> $scores
     * @return list<Stat01EntryInputDto>
     */
    private function entries(array $scores): array
    {
        return array_map(
            fn (?string $score, int $index): Stat01EntryInputDto => new Stat01EntryInputDto(
                id: $index + 1,
                playerId: 1000 + $index,
                bikeNumber: $index + 1,
                grade: 'A1',
                raceScore: $score,
                fetchedAt: new DateTimeImmutable('2024-01-02 12:00:00+09:00'),
            ),
            $scores,
            array_keys($scores),
        );
    }

    /** @param list<Stat01EntryFeatureDto> $entries
     * @return array<int, Stat01EntryFeatureDto>
     */
    private function byBike(array $entries): array
    {
        $byBike = [];
        foreach ($entries as $entry) {
            $byBike[$entry->entry->bikeNumber] = $entry;
        }

        return $byBike;
    }
}
