<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\FeatureEligibilityEvaluator;
use App\Domain\Keirin\Backtest\Calculators\LabelCohortEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Stat01BaselinePredictionCalculator;
use App\Domain\Keirin\Backtest\DTO\FeatureInputDto;
use App\Domain\Keirin\Backtest\DTO\FoldDefinitionDto;
use App\Domain\Keirin\Backtest\DTO\LabelResultDto;
use App\Domain\Keirin\Backtest\DTO\RaceContextDto;
use App\Domain\Keirin\Backtest\Enums\BacktestCohort;
use App\Domain\Keirin\Backtest\Enums\BacktestExclusionReason;
use App\Domain\Keirin\Backtest\Services\Bt01FoldProvider;
use App\Domain\Keirin\Backtest\Services\Bt01SourceManifest;
use App\Domain\Keirin\Backtest\Services\FinalHoldoutGuard;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

class Bt01FoundationTest extends TestCase
{
    public function test_fixed_manifest_and_hash_do_not_depend_on_input_order(): void
    {
        $hasher = new CanonicalHasher;
        $manifest = new Bt01SourceManifest($hasher);
        $this->assertSame([25, 26, 1, 27], array_map(fn ($entry): int => $entry->featureRunId, $manifest->entries()));
        $this->assertSame([2022, 2023, 2024, 2025], array_map(fn ($entry): int => $entry->year, $manifest->entries()));
        $this->assertSame(64, strlen($manifest->hash()));
        $this->assertSame($manifest->hash(), (new Bt01SourceManifest($hasher, array_reverse($manifest->entries())))->hash());
    }

    public function test_fixed_folds_have_no_2026_evaluation(): void
    {
        $folds = (new Bt01FoldProvider)->folds();
        $this->assertSame(['DEV_2022', 'WF_2023', 'WF_2024', 'WF_2025'], array_map(fn ($fold): string => $fold->code, $folds));
        $this->assertSame('2025-12-31', $folds[3]->evaluationTo->format('Y-m-d'));
    }

    public function test_final_holdout_rejects_2026(): void
    {
        $this->expectException(DomainException::class);
        (new FinalHoldoutGuard)->assertAllowed(new FoldDefinitionDto(
            'FORBIDDEN', 5, null, null, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-01'),
        ));
    }

    public function test_prediction_preserves_rank1_and_top3_boundary_ties_without_entry_id_tiebreak(): void
    {
        $calculator = new Stat01BaselinePredictionCalculator(new CanonicalHasher);
        $features = [
            $this->feature(40, 4, '95', 3),
            $this->feature(10, 1, '100', 1),
            $this->feature(30, 3, '95', 3),
            $this->feature(20, 2, '100', 1),
        ];
        $predictions = $calculator->calculate($features);

        $this->assertSame([10, 20, 30, 40], array_map(fn ($prediction): int => $prediction->raceEntryId, $predictions));
        $this->assertSame([1, 1, 3, 3], array_map(fn ($prediction): int => $prediction->predictedRank, $predictions));
        $this->assertSame(2, count(array_filter($predictions, fn ($prediction): bool => $prediction->isRank1Set)));
        $this->assertSame(4, count(array_filter($predictions, fn ($prediction): bool => $prediction->isTop3Set)));
    }

    public function test_prediction_hash_is_deterministic_and_contains_no_label(): void
    {
        $calculator = new Stat01BaselinePredictionCalculator(new CanonicalHasher);
        $first = $calculator->calculate([$this->feature(1, 1, '100.0', 1)])[0];
        $second = $calculator->calculate([$this->feature(1, 1, '100.00', 1)])[0];
        $this->assertSame($first->predictionHash, $second->predictionHash);
        $this->assertSame('100.00', $first->predictionScore);
    }

    public function test_partial_and_all_invalid_feature_races_are_excluded(): void
    {
        $evaluator = new FeatureEligibilityEvaluator;
        $race = $this->race(5);
        $partial = $evaluator->evaluate($race, [$this->feature(1, 1, '100', 1)]);
        $invalidFeatures = array_map(fn (int $bike): FeatureInputDto => $this->feature($bike, $bike, '0', null, false, 'INVALID_INPUT', 'PARTIAL'), range(1, 5));
        $invalid = $evaluator->evaluate($race, $invalidFeatures);

        $this->assertFalse($partial->eligible);
        $this->assertContains(BacktestExclusionReason::FeatureResultCountMismatch, $partial->reasons);
        $this->assertFalse($invalid->eligible);
        $this->assertContains(BacktestExclusionReason::FeatureScoreUnavailable, $invalid->reasons);
        $this->assertContains(BacktestExclusionReason::FeatureScoreNonPositive, $invalid->reasons);
        $this->assertContains(BacktestExclusionReason::FeatureRankMissing, $invalid->reasons);
    }

    public function test_duplicate_entry_bike_and_after_start_are_excluded(): void
    {
        $features = array_map(fn (int $bike): FeatureInputDto => $this->feature($bike, $bike, '80', $bike), range(1, 5));
        $features[1] = $this->feature(1, 1, '79', 2, inputAt: '2024-01-01 12:01:00');
        $decision = (new FeatureEligibilityEvaluator)->evaluate($this->race(5), $features);

        $this->assertContains(BacktestExclusionReason::FeatureDuplicateEntry, $decision->reasons);
        $this->assertContains(BacktestExclusionReason::FeatureDuplicateBike, $decision->reasons);
        $this->assertContains(BacktestExclusionReason::FeatureInputAsOfAfterStart, $decision->reasons);
    }

    public function test_operational_keeps_abnormal_results_while_normal_finish_excludes_them(): void
    {
        $evaluator = new LabelCohortEvaluator;
        $predictions = (new Stat01BaselinePredictionCalculator(new CanonicalHasher))->calculate(array_map(fn (int $bike): FeatureInputDto => $this->feature($bike, $bike, (string) (100 - $bike), $bike), range(1, 5)));
        $labels = [
            new LabelResultDto(1, 1, 1, 'FINISHED'),
            new LabelResultDto(1, 2, 2, 'FINISHED'),
            new LabelResultDto(1, 3, 3, 'FINISHED'),
            new LabelResultDto(1, 4, 4, 'FINISHED'),
            new LabelResultDto(1, 5, null, 'DISQUALIFIED'),
        ];

        $this->assertTrue($evaluator->evaluate(BacktestCohort::Operational, $this->race(5), $predictions, $labels)->included);
        $normal = $evaluator->evaluate(BacktestCohort::NormalFinish, $this->race(5), $predictions, $labels);
        $this->assertFalse($normal->included);
        $this->assertSame([BacktestExclusionReason::LabelAbnormalResultPresent], $normal->reasons);
    }

    public function test_tied_winners_are_a_set_and_not_split(): void
    {
        $predictions = (new Stat01BaselinePredictionCalculator(new CanonicalHasher))->calculate([
            $this->feature(1, 1, '100', 1), $this->feature(2, 2, '100', 1),
        ]);
        $labels = [new LabelResultDto(1, 1, 1, 'TIED'), new LabelResultDto(1, 2, 1, 'TIED')];
        $decision = (new LabelCohortEvaluator)->evaluate(BacktestCohort::NormalFinish, $this->race(2), $predictions, $labels);
        $this->assertTrue($decision->included);
        $this->assertTrue($decision->rank1Hit);
        $this->assertSame(2, $decision->rank1SetSize);
    }

    public function test_cancelled_count_mismatch_missing_winner_and_finished_rank_missing_are_explicit(): void
    {
        $evaluator = new LabelCohortEvaluator;
        $predictions = (new Stat01BaselinePredictionCalculator(new CanonicalHasher))->calculate([$this->feature(1, 1, '100', 1)]);
        $race = new RaceContextDto(1, new DateTimeImmutable('2024-01-01'), new DateTimeImmutable('2024-01-01 12:00'), null, 2, 'CANCELLED');
        $decision = $evaluator->evaluate(BacktestCohort::Operational, $race, $predictions, [new LabelResultDto(1, 1, null, 'FINISHED')]);

        $this->assertContains(BacktestExclusionReason::LabelRaceNotConfirmed, $decision->reasons);
        $this->assertContains(BacktestExclusionReason::LabelResultCountMismatch, $decision->reasons);
        $this->assertContains(BacktestExclusionReason::LabelNoWinner, $decision->reasons);
        $this->assertContains(BacktestExclusionReason::LabelFinishedRankMissing, $decision->reasons);
    }

    private function race(int $entrantCount): RaceContextDto
    {
        return new RaceContextDto(1, new DateTimeImmutable('2024-01-01'), new DateTimeImmutable('2024-01-01 12:00:00'), new DateTimeImmutable('2024-01-01 11:55:00'), $entrantCount, 'CONFIRMED');
    }

    private function feature(
        int $entryId,
        int $bike,
        string $score,
        ?int $rank,
        bool $available = true,
        string $status = 'VALID',
        string $quality = 'FULL',
        string $inputAt = '2024-01-01 11:55:00',
    ): FeatureInputDto {
        return new FeatureInputDto(
            id: 1000 + $entryId,
            featureRunId: 1,
            raceId: 1,
            raceEntryId: $entryId,
            playerId: 100 + $entryId,
            bikeNumber: $bike,
            status: $status,
            qualityStatus: $quality,
            inputAsOf: new DateTimeImmutable($inputAt),
            inputHash: hash('sha256', (string) $entryId),
            raceScoreRaw: $score,
            raceScoreAvailable: $available,
            raceScoreRank: $rank,
        );
    }
}
