<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Statistics;

use App\Domain\Keirin\Statistics\Calculators\Stat01Calculator;
use App\Domain\Keirin\Statistics\DTO\Stat01EntryInputDto;
use App\Domain\Keirin\Statistics\DTO\Stat01EntryResultDto;
use App\Domain\Keirin\Statistics\DTO\Stat01RaceInputDto;
use App\Domain\Keirin\Statistics\DTO\StatFeatureValueDto;
use App\Domain\Keirin\Statistics\Enums\RaceScoreValidationStatus;
use App\Domain\Keirin\Statistics\Enums\StatDataQualityStatus;
use App\Domain\Keirin\Statistics\Enums\StatFeatureStatus;
use App\Domain\Keirin\Statistics\Enums\StatInputAsOfPolicy;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class Stat01CalculatorTest extends TestCase
{
    #[DataProvider('entrantCounts')]
    public function test_it_calculates_supported_race_sizes(int $entrantCount): void
    {
        $scores = array_map(static fn (int $index): float => 110.0 - $index, range(0, $entrantCount - 1));
        $results = $this->calculator()->calculate($this->race($scores))->results;

        $this->assertCount($entrantCount, $results);
        $this->assertSame(range(1, $entrantCount), array_map(
            fn (Stat01EntryResultDto $result): int => (int) $this->feature($result, 'RACE_SCORE_RANK')->value,
            $results,
        ));
        $this->assertSame(1.0, $this->feature($results[0], 'RACE_SCORE_RANK_PERCENTILE')->value);
        $this->assertSame(0.0, $this->feature($results[$entrantCount - 1], 'RACE_SCORE_RANK_PERCENTILE')->value);
    }

    /** @return list<array{int}> */
    public static function entrantCounts(): array
    {
        return [[5], [7], [9]];
    }

    public function test_competition_rank_dense_rank_and_percentile_handle_ties(): void
    {
        $results = $this->byBike($this->calculator()->calculate($this->race([100.0, 95.0, 95.0, 90.0]))->results);

        $this->assertSame([1, 2, 2, 4], array_values(array_map(fn ($result): int => (int) $this->feature($result, 'RACE_SCORE_RANK')->value, $results)));
        $this->assertSame([1, 2, 2, 3], array_values(array_map(fn ($result): int => (int) $this->feature($result, 'RACE_SCORE_DENSE_RANK')->value, $results)));
        $this->assertEqualsWithDelta(2 / 3, $this->feature($results[2], 'RACE_SCORE_RANK_PERCENTILE')->value, 0.00000001);
        $this->assertEqualsWithDelta(0.0, $this->feature($results[4], 'RACE_SCORE_RANK_PERCENTILE')->value, 0.00000001);
    }

    public function test_partial_missing_and_non_positive_inputs_are_not_imputed(): void
    {
        $results = $this->byBike($this->calculator()->calculate(
            $this->race([100.0, null, 0.0, 90.0, 80.0]),
        )->results);

        $this->assertSame(StatFeatureStatus::Degraded, $results[1]->status);
        $this->assertSame(StatDataQualityStatus::Partial, $results[1]->dataQualityStatus);
        $this->assertSame(StatFeatureStatus::MissingInput, $results[2]->status);
        $this->assertFalse($this->feature($results[2], 'RACE_SCORE_AVAILABLE')->value);
        $this->assertSame(StatFeatureStatus::InvalidInput, $results[3]->status);
        $this->assertFalse($this->feature($results[3], 'RACE_SCORE_AVAILABLE')->value);
        $this->assertCount(1, $results[2]->features);
        $this->assertCount(1, $results[3]->features);
    }

    public function test_all_missing_inputs_and_missing_input_as_of_are_blocked(): void
    {
        $missing = $this->calculator()->calculate($this->race([null, null, null, null, null]))->results;
        foreach ($missing as $result) {
            $this->assertSame(StatFeatureStatus::Blocked, $result->status);
            $this->assertSame(StatDataQualityStatus::Blocked, $result->dataQualityStatus);
        }

        $withoutAsOf = $this->race([100.0, 95.0, 90.0, 85.0, 80.0], inputAsOf: null);
        foreach ($this->calculator()->calculate($withoutAsOf)->results as $result) {
            $this->assertSame(StatFeatureStatus::Blocked, $result->status);
            $this->assertCount(1, $result->features);
        }
    }

    public function test_all_tied_and_single_entry_races_omit_z_score_without_division_by_zero(): void
    {
        $tied = $this->calculator()->calculate($this->race(array_fill(0, 5, 95.0)))->results;
        foreach ($tied as $result) {
            $this->assertSame(1, $this->feature($result, 'RACE_SCORE_RANK')->value);
            $this->assertSame(1.0, $this->feature($result, 'RACE_SCORE_RANK_PERCENTILE')->value);
            $this->assertSame(0.0, $this->feature($result, 'RACE_SCORE_STDDEV_POP')->value);
            $this->assertNull($this->optionalFeature($result, 'RACE_SCORE_Z'));
        }

        $single = $this->calculator()->calculate($this->race([95.0]))->results[0];
        $this->assertSame(1.0, $this->feature($single, 'RACE_SCORE_RANK_PERCENTILE')->value);
        $this->assertNull($this->optionalFeature($single, 'RACE_SCORE_Z'));
    }

    public function test_mean_maximum_positive_gap_population_deviation_and_z_score_are_calculated(): void
    {
        $results = $this->byBike($this->calculator()->calculate($this->race([100.0, 90.0, 80.0]))->results);

        $this->assertEqualsWithDelta(90.0, $this->feature($results[1], 'RACE_SCORE_MEAN')->value, 0.000001);
        $this->assertEqualsWithDelta(100.0, $this->feature($results[3], 'RACE_SCORE_MAX')->value, 0.000001);
        $this->assertEqualsWithDelta(-10.0, $this->feature($results[3], 'RACE_SCORE_DIFF_FROM_MEAN')->value, 0.000001);
        $this->assertEqualsWithDelta(20.0, $this->feature($results[3], 'RACE_SCORE_GAP_TO_MAX')->value, 0.000001);
        $this->assertEqualsWithDelta(sqrt(200 / 3), $this->feature($results[1], 'RACE_SCORE_STDDEV_POP')->value, 0.000001);
        $this->assertEqualsWithDelta(sqrt(1.5), $this->feature($results[1], 'RACE_SCORE_Z')->value, 0.000001);
        $this->assertEqualsWithDelta(-sqrt(1.5), $this->feature($results[3], 'RACE_SCORE_Z')->value, 0.000001);
    }

    public function test_historical_snapshot_type_is_separate_from_data_quality(): void
    {
        $valid = $this->calculator()->calculate($this->race([100.0, 95.0, 90.0, 85.0, 80.0]))->results[0];
        $degraded = $this->calculator()->calculate($this->race(
            [100.0, 95.0, 90.0, 85.0, 80.0],
            sourceLinkMissing: true,
        ))->results[0];

        $this->assertSame('HISTORICAL_RACE_CARD_BACKFILL', $valid->inputSnapshotType);
        $this->assertSame(StatDataQualityStatus::Valid, $valid->dataQualityStatus);
        $this->assertSame(StatDataQualityStatus::Degraded, $degraded->dataQualityStatus);
    }

    public function test_ineligible_player_profile_input_is_leakage_risk(): void
    {
        $original = $this->race([100.0, 95.0, 90.0, 85.0, 80.0]);
        $entries = $original->entries;
        $first = $entries[0];
        $entries[0] = new Stat01EntryInputDto(
            raceEntryId: $first->raceEntryId,
            raceEntrySnapshotId: $first->raceEntrySnapshotId,
            sourceStateId: $first->sourceStateId,
            playerId: $first->playerId,
            bikeNumber: $first->bikeNumber,
            raceScore: $first->raceScore,
            validationStatus: $first->validationStatus,
            snapshotHash: $first->snapshotHash,
            sourceFingerprint: $first->sourceFingerprint,
            inputSnapshotType: $first->inputSnapshotType,
            sourceLinkMissing: false,
            raceScoreEligible: false,
            fetchedAt: $first->fetchedAt,
        );
        $race = new Stat01RaceInputDto(
            $original->raceId,
            $original->source,
            $original->inputAsOf,
            $original->inputAsOfPolicy,
            $entries,
        );

        $result = $this->calculator()->calculate($race)->results[0];

        $this->assertSame(StatFeatureStatus::LeakageRisk, $result->status);
        $this->assertSame(StatDataQualityStatus::LeakageRisk, $result->dataQualityStatus);
        $this->assertFalse($this->feature($result, 'RACE_SCORE_AVAILABLE')->value);
    }

    public function test_input_order_does_not_change_hash_results_or_mapping(): void
    {
        $race = $this->race([100.0, 95.0, 90.0, 85.0, 80.0]);
        $reversed = new Stat01RaceInputDto(
            $race->raceId,
            $race->source,
            $race->inputAsOf,
            $race->inputAsOfPolicy,
            array_reverse($race->entries),
        );

        $first = $this->calculator()->calculate($race);
        $second = $this->calculator()->calculate($reversed);

        $this->assertSame($first->inputHash, $second->inputHash);
        $this->assertEquals($first->results, $second->results);
        foreach ($first->results as $index => $result) {
            $this->assertSame(1001 + $index, $result->raceEntryId);
            $this->assertSame(2001 + $index, $result->playerId);
            $this->assertSame(1 + $index, $result->bikeNumber);
        }
    }

    private function calculator(): Stat01Calculator
    {
        return new Stat01Calculator;
    }

    /**
     * @param  list<?float>  $scores
     */
    private function race(
        array $scores,
        ?DateTimeImmutable $inputAsOf = new DateTimeImmutable('2024-01-01 11:55:00 Asia/Tokyo'),
        bool $sourceLinkMissing = false,
    ): Stat01RaceInputDto {
        return new Stat01RaceInputDto(
            raceId: 99,
            source: 'keirin_jp',
            inputAsOf: $inputAsOf,
            inputAsOfPolicy: $inputAsOf === null ? StatInputAsOfPolicy::Unavailable : StatInputAsOfPolicy::SalesClose,
            entries: array_map(
                static function (?float $score, int $index) use ($sourceLinkMissing): Stat01EntryInputDto {
                    $status = match (true) {
                        $score === null => RaceScoreValidationStatus::Missing,
                        $score <= 0.0 => RaceScoreValidationStatus::NonPositive,
                        default => RaceScoreValidationStatus::Valid,
                    };

                    return new Stat01EntryInputDto(
                        raceEntryId: 1001 + $index,
                        raceEntrySnapshotId: 3001 + $index,
                        sourceStateId: 4001 + $index,
                        playerId: 2001 + $index,
                        bikeNumber: 1 + $index,
                        raceScore: $status === RaceScoreValidationStatus::Valid ? $score : null,
                        validationStatus: $status,
                        snapshotHash: hash('sha256', "{$index}:".($score ?? 'null')),
                        sourceFingerprint: hash('sha256', "source:{$index}"),
                        inputSnapshotType: 'HISTORICAL_RACE_CARD_BACKFILL',
                        sourceLinkMissing: $sourceLinkMissing,
                        raceScoreEligible: true,
                        fetchedAt: new DateTimeImmutable('2026-07-24 12:00:00 Asia/Tokyo'),
                    );
                },
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

    private function feature(Stat01EntryResultDto $result, string $code): StatFeatureValueDto
    {
        return $this->optionalFeature($result, $code)
            ?? throw new \RuntimeException("Feature {$code} was missing.");
    }

    private function optionalFeature(Stat01EntryResultDto $result, string $code): ?StatFeatureValueDto
    {
        foreach ($result->features as $feature) {
            if ($feature->featureCode === $code) {
                return $feature;
            }
        }

        return null;
    }
}
