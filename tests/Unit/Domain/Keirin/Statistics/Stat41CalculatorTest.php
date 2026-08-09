<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Statistics;

use App\Domain\Keirin\Statistics\Calculators\Stat41Calculator;
use App\Domain\Keirin\Statistics\DTO\Batch05RaceInputDto;
use App\Domain\Keirin\Statistics\DTO\Batch05TargetEntryDto;
use App\Domain\Keirin\Statistics\Enums\StatisticFeatureResultStatus;
use App\Domain\Keirin\Statistics\Enums\StatisticQualityStatus;
use App\Domain\Keirin\Statistics\Support\DeterministicJsonHasher;
use App\Domain\Keirin\Statistics\Support\StatisticalMath;
use DateTimeImmutable;
use InvalidArgumentException;
use Tests\TestCase;

class Stat41CalculatorTest extends TestCase
{
    private Stat41Calculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new Stat41Calculator(new StatisticalMath, new DeterministicJsonHasher);
    }

    public function test_it_calculates_complete_distribution_top_and_pair_gap_structures(): void
    {
        $result = $this->calculator->calculate($this->race([100, 90, 80, 70, 60, 50, 40]));

        $this->assertSame(StatisticFeatureResultStatus::Valid, $result->status);
        $this->assertSame(StatisticQualityStatus::Full, $result->qualityStatus);
        $this->assertSame(7, $result->features['RACE_CONTEXT']['expected_entrant_count']);
        $this->assertSame(7, $result->features['SCORE_COVERAGE']['usable_score_count']);
        $this->assertSame(1.0, $result->features['SCORE_COVERAGE']['score_coverage_ratio']);
        $this->assertSame(70.0, $result->features['SCORE_DISTRIBUTION']['mean']);
        $this->assertSame(40.0, $result->features['SCORE_DISTRIBUTION']['min']);
        $this->assertSame(100.0, $result->features['SCORE_DISTRIBUTION']['max']);
        $this->assertSame(60.0, $result->features['SCORE_DISTRIBUTION']['range']);
        $this->assertSame(400.0, $result->features['SCORE_DISTRIBUTION']['variance_pop']);
        $this->assertSame(20.0, $result->features['SCORE_DISTRIBUTION']['stddev_pop']);
        $this->assertSame(70.0, $result->features['SCORE_DISTRIBUTION']['median']);
        $this->assertSame(55.0, $result->features['SCORE_DISTRIBUTION']['q25']);
        $this->assertSame(85.0, $result->features['SCORE_DISTRIBUTION']['q75']);
        $this->assertSame(30.0, $result->features['SCORE_DISTRIBUTION']['iqr']);
        $this->assertSame(20.0, $result->features['SCORE_DISTRIBUTION']['mad']);
        $this->assertEqualsWithDelta(2 / 7, $result->features['SCORE_DISTRIBUTION']['cv_pop'], 0.0000001);
        $this->assertSame([100.0, 90.0, 80.0, 70.0], [
            $result->features['TOP_SCORE_STRUCTURE']['top1_score'], $result->features['TOP_SCORE_STRUCTURE']['top2_score'],
            $result->features['TOP_SCORE_STRUCTURE']['top3_score'], $result->features['TOP_SCORE_STRUCTURE']['top4_score'],
        ]);
        $this->assertSame(10.0, $result->features['WINNER_BOUNDARY']['rank1_vs_rank2_gap']);
        $this->assertSame(21, $result->features['PAIRWISE_SCORE_GAPS']['pair_count']);
        $this->assertSame(10.0, $result->features['PAIRWISE_SCORE_GAPS']['min_absolute_gap']);
        $this->assertEqualsWithDelta(26.6666667, $result->features['PAIRWISE_SCORE_GAPS']['mean_absolute_gap'], 0.000001);
        $this->assertSame(20.0, $result->features['PAIRWISE_SCORE_GAPS']['median_absolute_gap']);
        $this->assertSame(60.0, $result->features['PAIRWISE_SCORE_GAPS']['max_absolute_gap']);
        $this->assertNull($result->features['RACE_COMPETITIVENESS_SCORE']);
        $this->assertNull($result->features['RACE_PREDICTION_UNCERTAINTY_SCORE']);
        $this->assertNull($result->features['RACE_UPSET_STRUCTURE_SCORE']);
        $this->assertNull($result->features['PREDICTION_PROBABILITY_ENTROPY']);
        $this->assertSame('COMPLETE_SCORE_DISTRIBUTION', $result->evidence['reason']);
    }

    public function test_ties_use_race_entry_id_only_as_canonical_tie_breaker(): void
    {
        $race = $this->race([100, 100, 95, 95]);
        $result = $this->calculator->calculate($race);

        $this->assertSame(2, $result->features['SCORE_DISTRIBUTION']['distinct_score_count']);
        $this->assertSame(2, $result->features['TOP_SCORE_STRUCTURE']['top_score_tie_count']);
        $this->assertSame(0.0, $result->features['TOP_SCORE_STRUCTURE']['gap_rank1_rank2']);
        $this->assertSame(5.0, $result->features['TOP_SCORE_STRUCTURE']['gap_rank1_rank3']);
        $this->assertSame(5.0, $result->features['TOP_SCORE_STRUCTURE']['gap_rank2_rank3']);
        $this->assertSame(0.0, $result->features['TOP_SCORE_STRUCTURE']['gap_rank3_rank4']);
        $this->assertSame([1, 2, 3, 4], array_column($result->evidence['canonical_usable_scores'], 'race_entry_id'));
    }

    public function test_four_score_reference_distribution_and_all_unimplemented_outputs_are_null(): void
    {
        $result = $this->calculator->calculate($this->race([100, 95, 90, 80]));

        $this->assertSame(4, $result->features['SCORE_COVERAGE']['usable_score_count']);
        $this->assertSame(91.25, $result->features['SCORE_DISTRIBUTION']['mean']);
        $this->assertSame(20.0, $result->features['SCORE_DISTRIBUTION']['range']);
        $this->assertSame(6, $result->features['PAIRWISE_SCORE_GAPS']['pair_count']);
        $this->assertSame(5.0, $result->features['PAIRWISE_SCORE_GAPS']['min_absolute_gap']);
        $this->assertSame(10.0, $result->features['PAIRWISE_SCORE_GAPS']['median_absolute_gap']);
        $this->assertSame(20.0, $result->features['PAIRWISE_SCORE_GAPS']['max_absolute_gap']);
        foreach ([
            'RACE_COMPETITIVENESS_SCORE',
            'RACE_PREDICTION_UNCERTAINTY_SCORE',
            'RACE_UPSET_STRUCTURE_SCORE',
            'PREDICTION_PROBABILITY_ENTROPY',
            'CANDIDATE_COUNT',
        ] as $feature) {
            $this->assertNull($result->features[$feature]);
        }
    }

    public function test_partial_missing_invalid_and_bad_expected_counts_are_classified_safely(): void
    {
        $partial = $this->calculator->calculate($this->race([90, 80, null, 70, 60], available: [true, true, false, true, true]));
        $this->assertSame(StatisticFeatureResultStatus::Partial, $partial->status);
        $this->assertSame('PARTIAL_PLAYER_SCORES', $partial->evidence['reason']);

        $missing = $this->calculator->calculate($this->race([null, null, null, null, null], available: array_fill(0, 5, false)));
        $this->assertSame(StatisticFeatureResultStatus::MissingInput, $missing->status);

        $invalid = $this->calculator->calculate($this->race(['bad', -1, 0, 'x', false], available: array_fill(0, 5, false)));
        $this->assertSame(StatisticFeatureResultStatus::InvalidInput, $invalid->status);

        $one = $this->calculator->calculate($this->race([90, null, null, null, null], available: [true, false, false, false, false]));
        $this->assertSame(StatisticFeatureResultStatus::Partial, $one->status);
        $this->assertSame('PARTIAL_PLAYER_SCORES_INSUFFICIENT_FOR_COMPETITION_STRUCTURE', $one->evidence['reason']);
        $this->assertSame(0, $one->features['PAIRWISE_SCORE_GAPS']['pair_count']);
        $this->assertNull($one->features['PAIRWISE_SCORE_GAPS']['mean_absolute_gap']);
        $this->assertNull($one->features['WINNER_BOUNDARY']['rank1_vs_rank2_gap']);

        $badExpected = $this->calculator->calculate($this->race([90, 80], expected: 1));
        $this->assertSame(StatisticFeatureResultStatus::InvalidInput, $badExpected->status);
        $this->assertSame('INVALID_ENTRANT_COUNT', $badExpected->evidence['reason']);

        $entries = $this->race([90, 80])->entries;
        $entries[1] = $this->entry(2, 80, true, 3);
        $inconsistent = $this->calculator->calculate(new Batch05RaceInputDto(10, $entries));
        $this->assertSame(StatisticFeatureResultStatus::InvalidInput, $inconsistent->status);
        $this->assertSame('INCONSISTENT_ENTRANT_COUNT', $inconsistent->evidence['reason']);
    }

    public function test_no_usable_scores_leave_top_tie_count_unknown_but_keep_observed_zero_counts(): void
    {
        $missing = $this->calculator->calculate($this->race(
            [null, null, null, null, null],
            available: array_fill(0, 5, false),
        ));
        $this->assertSame(StatisticFeatureResultStatus::MissingInput, $missing->status);
        $this->assertSame(0, $missing->features['SCORE_COVERAGE']['usable_score_count']);
        $this->assertNull($missing->features['TOP_SCORE_STRUCTURE']['top1_score']);
        $this->assertNull($missing->features['TOP_SCORE_STRUCTURE']['top_score_tie_count']);
        $this->assertSame(0, $missing->features['PAIRWISE_SCORE_GAPS']['pair_count']);

        $invalid = $this->calculator->calculate($this->race(
            [0, -1, 'bad', 'x', false],
            available: array_fill(0, 5, false),
        ));
        $this->assertSame(StatisticFeatureResultStatus::InvalidInput, $invalid->status);
        $this->assertSame(0, $invalid->features['SCORE_COVERAGE']['usable_score_count']);
        $this->assertNull($invalid->features['TOP_SCORE_STRUCTURE']['top1_score']);
        $this->assertNull($invalid->features['TOP_SCORE_STRUCTURE']['top_score_tie_count']);
        $this->assertSame(0, $invalid->features['PAIRWISE_SCORE_GAPS']['pair_count']);
    }

    public function test_one_usable_score_has_one_top_score_holder(): void
    {
        $result = $this->calculator->calculate($this->race(
            [90, null, null, null, null],
            available: [true, false, false, false, false],
        ));

        $this->assertSame(StatisticFeatureResultStatus::Partial, $result->status);
        $this->assertSame(90.0, $result->features['TOP_SCORE_STRUCTURE']['top1_score']);
        $this->assertSame(1, $result->features['TOP_SCORE_STRUCTURE']['top_score_tie_count']);
        $this->assertSame(0, $result->features['PAIRWISE_SCORE_GAPS']['pair_count']);
    }

    public function test_hash_is_order_invariant_and_changes_for_each_audited_input_dimension(): void
    {
        $race = $this->race([100, 90, 80, 70, 60]);
        $base = $this->calculator->calculate($race)->inputHash;
        $this->assertSame($base, $this->calculator->calculate(new Batch05RaceInputDto(10, array_reverse($race->entries)))->inputHash);

        $variants = [];
        $entries = $race->entries;
        $entries[0] = $this->entry(1, 101, true, 5);
        $variants[] = new Batch05RaceInputDto(10, $entries);
        $entries = $race->entries;
        $entries[0] = $this->entry(1, 100, true, 5, hash: 'changed');
        $variants[] = new Batch05RaceInputDto(10, $entries);
        $entries = $race->entries;
        $entries[0] = $this->entry(1, 100, true, 6);
        $variants[] = new Batch05RaceInputDto(10, $entries);
        $entries = $race->entries;
        $entries[0] = $this->entry(1, 100, true, 5, bike: 9);
        $variants[] = new Batch05RaceInputDto(10, $entries);
        $entries = array_map(fn (Batch05TargetEntryDto $entry): Batch05TargetEntryDto => $this->entry(
            $entry->raceEntryId,
            $entry->raceScoreRaw,
            true,
            5,
            inputAsOf: '2024-01-10 10:01:00',
        ), $race->entries);
        $variants[] = new Batch05RaceInputDto(10, $entries);

        foreach ($variants as $variant) {
            $this->assertNotSame($base, $this->calculator->calculate($variant)->inputHash);
        }
    }

    public function test_race_input_rejects_empty_or_multiple_observation_times(): void
    {
        try {
            new Batch05RaceInputDto(10, []);
            $this->fail('Empty entries were accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('had no STAT-01 entries', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        new Batch05RaceInputDto(10, [
            $this->entry(1, 90, true, 2),
            $this->entry(2, 80, true, 2, inputAsOf: '2024-01-10 10:01:00'),
        ]);
    }

    /** @param list<mixed> $scores @param list<bool>|null $available */
    private function race(array $scores, ?array $available = null, ?int $expected = null): Batch05RaceInputDto
    {
        $expected ??= count($scores);
        $entries = [];
        foreach ($scores as $index => $score) {
            $entries[] = $this->entry(
                $index + 1,
                $score,
                $available[$index] ?? true,
                $expected,
            );
        }

        return new Batch05RaceInputDto(10, $entries);
    }

    private function entry(
        int $id,
        mixed $score,
        bool $available,
        mixed $expected,
        string $hash = 'hash',
        ?int $bike = null,
        string $inputAsOf = '2024-01-10 10:00:00',
    ): Batch05TargetEntryDto {
        return new Batch05TargetEntryDto(
            raceEntryId: $id,
            playerId: $id,
            bikeNumber: $bike ?? $id,
            inputAsOf: new DateTimeImmutable($inputAsOf),
            stat01InputHash: $hash.'-'.$id,
            stat01Status: 'VALID',
            stat01QualityStatus: 'FULL',
            raceScoreRaw: $score,
            raceScoreAvailable: $available,
            expectedEntrantCount: $expected,
            sourceFetchedAt: new DateTimeImmutable('2024-01-10 09:00:00'),
        );
    }
}
