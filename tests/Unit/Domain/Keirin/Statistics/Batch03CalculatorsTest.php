<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Statistics;

use App\Domain\Keirin\Statistics\Calculators\Stat07Calculator;
use App\Domain\Keirin\Statistics\Calculators\Stat08Calculator;
use App\Domain\Keirin\Statistics\Calculators\Stat23Calculator;
use App\Domain\Keirin\Statistics\Calculators\Stat31Calculator;
use App\Domain\Keirin\Statistics\Calculators\Stat32Calculator;
use App\Domain\Keirin\Statistics\Calculators\Stat33Calculator;
use App\Domain\Keirin\Statistics\DTO\Batch03BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch03HistoricalRaceDto;
use App\Domain\Keirin\Statistics\DTO\Batch03TargetEntryDto;
use App\Domain\Keirin\Statistics\Enums\HistoricalResultState;
use App\Domain\Keirin\Statistics\Enums\RaceStage;
use App\Domain\Keirin\Statistics\Enums\StatisticFeatureResultStatus;
use App\Domain\Keirin\Statistics\Support\Batch03CalculatorSupport;
use App\Domain\Keirin\Statistics\Support\DeterministicJsonHasher;
use App\Domain\Keirin\Statistics\Support\RaceStageNormalizer;
use App\Domain\Keirin\Statistics\Support\StatisticalMath;
use DateTimeImmutable;
use Tests\TestCase;

class Batch03CalculatorsTest extends TestCase
{
    private Batch03CalculatorSupport $support;

    private StatisticalMath $math;

    protected function setUp(): void
    {
        parent::setUp();
        $this->math = new StatisticalMath;
        $this->support = new Batch03CalculatorSupport(new DeterministicJsonHasher, $this->math);
    }

    public function test_stat07_compares_same_track_to_all_tracks_and_excludes_future_abnormal_and_target(): void
    {
        $histories = [
            $this->history(1, '2024-01-01', track: 10, rank: 1),
            $this->history(2, '2024-01-02', track: 20, rank: 3),
            $this->history(3, '2024-01-03', track: 10, state: HistoricalResultState::Disqualified),
            $this->history(100, '2024-01-10 10:00:00', track: 10, rank: 1),
            $this->history(4, '2024-01-11', track: 10, rank: 1),
        ];

        $result = (new Stat07Calculator($this->support))->calculate($this->target(track: 10), $histories, $this->buildOptions(), 'batch');

        $this->assertSame(StatisticFeatureResultStatus::Valid, $result->status);
        $this->assertSame(1, $result->features['SAME_TRACK_ACQUIRED']['sample_count']);
        $this->assertSame(2, $result->features['ALL_TRACK_ACQUIRED']['sample_count']);
        $this->assertSame(0.5, $result->features['DELTA']['win_rate']);
        $this->assertContains('TRACK_LAYOUT_VERSION', $result->evidence['unavailable_components']);
    }

    public function test_stat07_no_same_track_history_is_not_a_zero_aptitude(): void
    {
        $result = (new Stat07Calculator($this->support))->calculate(
            $this->target(track: 10),
            [$this->history(1, '2024-01-01', track: 20, rank: 1)],
            $this->buildOptions(),
            'batch',
        );

        $this->assertSame(StatisticFeatureResultStatus::NoHistory, $result->status);
        $this->assertContains('NO_SAME_TRACK_HISTORY', $result->evidence['quality_reasons']);
    }

    public function test_stat08_uses_jst_continuous_time_and_same_hour_without_named_session_boundaries(): void
    {
        $target = $this->target(start: '2024-01-10 03:30:00+00:00', dayKind: '1');
        $result = (new Stat08Calculator($this->support))->calculate(
            $target,
            [
                $this->history(1, '2024-01-01 12:05:00+09:00', rank: 1),
                $this->history(2, '2024-01-02 13:05:00+09:00', rank: 2),
            ],
            $this->buildOptions(),
            'batch',
        );

        $this->assertSame(12, $result->features['TARGET_TIME']['hour_of_day']);
        $this->assertSame(750, $result->features['TARGET_TIME']['minute_of_day']);
        $this->assertSame(30, $result->features['TARGET_TIME']['minute_within_hour']);
        $this->assertEqualsWithDelta(sin(2 * M_PI * 750 / 1440), $result->features['TARGET_TIME']['circular_sin'], 1e-12);
        $this->assertSame(1, $result->features['SAME_HOUR_ACQUIRED']['sample_count']);
        $this->assertArrayNotHasKey('session_category', $result->features['TARGET_TIME']);
        $this->assertContains('OFFICIAL_SESSION_CATEGORY_UNAVAILABLE', $result->evidence['unavailable_components']);
    }

    public function test_stat23_uses_past_meetings_only_and_keeps_stage_out_of_features(): void
    {
        $target = $this->target(meeting: 99, day: 3, duration: 3);
        $result = (new Stat23Calculator($this->support))->calculate(
            $target,
            [
                $this->history(1, '2024-01-01', meeting: 1, day: 3, duration: 3, rank: 1),
                $this->history(2, '2024-01-02', meeting: 1, day: 2, duration: 3, rank: 2),
                $this->history(3, '2024-01-09', meeting: 99, day: 2, duration: 3, rank: 1),
            ],
            $this->buildOptions(),
            'batch',
        );

        $this->assertTrue($result->features['TARGET_MEETING_DAY']['is_final_day']);
        $this->assertEqualsWithDelta(1.0, $result->features['TARGET_MEETING_DAY']['meeting_progress'], 1e-12);
        $this->assertSame(1, $result->features['SAME_DAY_NUMBER_HISTORY']['sample_count']);
        $this->assertSame(2, $result->features['ALL_MEETING_DAY_HISTORY']['sample_count']);
        $this->assertArrayNotHasKey('stage', $result->features['TARGET_MEETING_DAY']);
    }

    public function test_stat31_keeps_zero_high_stage_experience_as_observed_zero_and_grade_raw(): void
    {
        $result = (new Stat31Calculator($this->support, $this->math))->calculate(
            $this->target(grade: 'F2'),
            [$this->history(1, '2024-01-01', stage: RaceStage::General, grade: 'F2', rank: 2)],
            $this->buildOptions(),
            'batch',
        );

        $this->assertSame(StatisticFeatureResultStatus::Valid, $result->status);
        $this->assertSame(0, $result->features['STAGE_EXPERIENCE']['semifinal_or_final_count']);
        $this->assertSame('F2', $result->features['MEETING_GRADE']['target_raw_grade']);
        $this->assertSame(['F2' => 1], $result->features['MEETING_GRADE']['observed_grade_distribution']);
        $this->assertArrayNotHasKey('grade_weight', $result->features['MEETING_GRADE']);
    }

    public function test_stat31_counts_semifinal_and_final_normal_results_without_mixing_abnormal_performance(): void
    {
        $result = (new Stat31Calculator($this->support, $this->math))->calculate(
            $this->target(),
            [
                $this->history(1, '2024-01-01', stage: RaceStage::Semifinal, rank: 1),
                $this->history(2, '2024-01-02', stage: RaceStage::Final, rank: 2, residual: 0.2),
                $this->history(3, '2024-01-03', stage: RaceStage::Final, state: HistoricalResultState::Disqualified),
            ],
            $this->buildOptions(),
            'batch',
        );

        $this->assertSame(1, $result->features['STAGE_EXPERIENCE']['semifinal_count']);
        $this->assertSame(2, $result->features['STAGE_EXPERIENCE']['final_count']);
        $this->assertSame(1, $result->features['STAGE_EXPERIENCE']['FINAL']['sample_count']);
    }

    public function test_stat31_excludes_not_started_entries_from_stage_experience_but_counts_started_abnormal_results(): void
    {
        $result = (new Stat31Calculator($this->support, $this->math))->calculate(
            $this->target(),
            [
                $this->history(1, '2024-01-01', stage: RaceStage::Final, rank: 1),
                $this->history(2, '2024-01-02', stage: RaceStage::Final, state: HistoricalResultState::Disqualified),
                $this->history(3, '2024-01-03', stage: RaceStage::Final, state: HistoricalResultState::DidNotStart),
                $this->history(4, '2024-01-04', stage: RaceStage::Semifinal, state: HistoricalResultState::Withdrawn),
            ],
            $this->buildOptions(),
            'batch',
        );

        $this->assertSame(2, $result->features['STAGE_EXPERIENCE']['final_count']);
        $this->assertSame(0, $result->features['STAGE_EXPERIENCE']['semifinal_count']);
        $this->assertSame(2, $result->features['STAGE_EXPERIENCE']['semifinal_or_final_count']);
        $this->assertSame(1, $result->features['STAGE_EXPERIENCE']['FINAL']['sample_count']);
    }

    public function test_stat32_compares_same_stage_and_all_stage_and_distinguishes_other_from_unknown(): void
    {
        $calculator = new Stat32Calculator($this->support);
        $histories = [
            $this->history(1, '2024-01-01', stage: RaceStage::Final, rank: 1),
            $this->history(2, '2024-01-02', stage: RaceStage::General, rank: 3),
        ];
        $valid = $calculator->calculate($this->target(stage: RaceStage::Final), $histories, $this->buildOptions(), 'batch');
        $other = $calculator->calculate($this->target(stage: RaceStage::Other), [$this->history(3, '2024-01-03', stage: RaceStage::Other, rank: 2)], $this->buildOptions(), 'batch');
        $unknown = $calculator->calculate($this->target(stage: RaceStage::Unknown), $histories, $this->buildOptions(), 'batch');

        $this->assertSame(1, $valid->features['SAME_STAGE_ACQUIRED']['sample_count']);
        $this->assertSame(2, $valid->features['ALL_STAGE_ACQUIRED']['sample_count']);
        $this->assertSame(StatisticFeatureResultStatus::Valid, $other->status);
        $this->assertContains('CURRENT_STAGE_OTHER', $other->evidence['quality_reasons']);
        $this->assertSame(StatisticFeatureResultStatus::MissingInput, $unknown->status);
    }

    public function test_stat33_meeting_first_start_is_not_applicable(): void
    {
        $result = (new Stat33Calculator($this->support, $this->math))->calculate($this->target(meeting: 99), [], $this->buildOptions(), 'batch');

        $this->assertSame(StatisticFeatureResultStatus::NotApplicable, $result->status);
        $this->assertSame('FULL', $result->qualityStatus->value);
        $this->assertSame('MEETING_FIRST_START', $result->evidence['status_reason']);
    }

    public function test_stat33_abnormal_previous_result_is_partial(): void
    {
        $result = (new Stat33Calculator($this->support, $this->math))->calculate(
            $this->target(meeting: 99, stage: RaceStage::Final),
            [$this->history(1, '2024-01-09', meeting: 99, stage: RaceStage::Semifinal, state: HistoricalResultState::Disqualified)],
            $this->buildOptions(),
            'batch',
        );

        $this->assertSame(StatisticFeatureResultStatus::Partial, $result->status);
        $this->assertContains('ABNORMAL_PREVIOUS_RESULT', $result->evidence['quality_reasons']);
        $this->assertNull($result->features['MATCHING_TRANSITION_HISTORY']);
        $this->assertNull($result->features['PREVIOUS_EXACT_RANK_HISTORY']);
    }

    public function test_stat33_uses_previous_result_confirmed_before_input_cutoff(): void
    {
        $result = (new Stat33Calculator($this->support, $this->math))->calculate(
            $this->target(meeting: 99, stage: RaceStage::Final),
            [
                $this->history(1, '2024-01-01', meeting: 1, stage: RaceStage::Semifinal, rank: 3, confirmedAt: '2024-01-01 12:30:00+09:00'),
                $this->history(2, '2024-01-02', meeting: 1, stage: RaceStage::Final, rank: 1, confirmedAt: '2024-01-02 12:30:00+09:00'),
                $this->history(3, '2024-01-09', meeting: 99, stage: RaceStage::Semifinal, rank: 3, confirmedAt: '2024-01-09 12:30:00+09:00'),
            ],
            $this->buildOptions(),
            'batch',
        );

        $this->assertSame(StatisticFeatureResultStatus::Valid, $result->status);
        $this->assertTrue($result->evidence['previous_result_confirmation_reconstructed']);
        $this->assertTrue($result->evidence['previous_result_available_as_of_input']);
        $this->assertSame('2024-01-09T12:30:00.000000+09:00', $result->evidence['previous_result_confirmed_at']);
        $this->assertSame(1, $result->features['MATCHING_TRANSITION_HISTORY']['transition_sample_count']);
    }

    public function test_stat33_rejects_previous_result_confirmed_after_input_cutoff_without_calling_it_first_start(): void
    {
        $result = (new Stat33Calculator($this->support, $this->math))->calculate(
            $this->target(meeting: 99, stage: RaceStage::Final),
            [$this->history(1, '2024-01-10 10:00:00+09:00', meeting: 99, stage: RaceStage::Semifinal, rank: 3, confirmedAt: '2024-01-10 12:30:00+09:00')],
            $this->buildOptions(),
            'batch',
        );

        $this->assertSame(StatisticFeatureResultStatus::Partial, $result->status);
        $this->assertContains('PREVIOUS_RESULT_NOT_CONFIRMED_AS_OF_INPUT', $result->evidence['quality_reasons']);
        $this->assertTrue($result->evidence['previous_result_confirmation_reconstructed']);
        $this->assertFalse($result->evidence['previous_result_available_as_of_input']);
        $this->assertNull($result->features['MATCHING_TRANSITION_HISTORY']);
        $this->assertNull($result->features['PREVIOUS_EXACT_RANK_HISTORY']);
        $this->assertNull($result->evidence['status_reason']);
    }

    public function test_stat33_degrades_but_uses_backfilled_result_when_confirmation_time_is_unknown(): void
    {
        $result = (new Stat33Calculator($this->support, $this->math))->calculate(
            $this->target(meeting: 99, stage: RaceStage::Final),
            [
                $this->history(1, '2024-01-01', meeting: 1, stage: RaceStage::Semifinal, rank: 3),
                $this->history(2, '2024-01-02', meeting: 1, stage: RaceStage::Final, rank: 1),
                $this->history(3, '2024-01-09', meeting: 99, stage: RaceStage::Semifinal, rank: 3),
            ],
            $this->buildOptions(),
            'batch',
        );

        $this->assertSame(StatisticFeatureResultStatus::Valid, $result->status);
        $this->assertSame('DEGRADED', $result->qualityStatus->value);
        $this->assertContains('IN_MEETING_RESULT_CONFIRMATION_NOT_RECONSTRUCTED', $result->evidence['quality_reasons']);
        $this->assertFalse($result->evidence['previous_result_confirmation_reconstructed']);
        $this->assertNull($result->evidence['previous_result_available_as_of_input']);
        $this->assertSame(2, $result->evidence['unreconstructed_result_confirmation_count']);
        $this->assertSame(1, $result->features['MATCHING_TRANSITION_HISTORY']['transition_sample_count']);
    }

    public function test_stat33_distinguishes_eligible_zero_matching_history_from_ineligible_previous_result(): void
    {
        $result = (new Stat33Calculator($this->support, $this->math))->calculate(
            $this->target(meeting: 99, stage: RaceStage::Final),
            [$this->history(1, '2024-01-09', meeting: 99, stage: RaceStage::Semifinal, rank: 3, confirmedAt: '2024-01-09 12:30:00+09:00')],
            $this->buildOptions(),
            'batch',
        );

        $this->assertSame(StatisticFeatureResultStatus::NoHistory, $result->status);
        $this->assertContains('NO_OBSERVED_TRANSITION_HISTORY', $result->evidence['quality_reasons']);
        $this->assertSame(0, $result->features['MATCHING_TRANSITION_HISTORY']['transition_sample_count']);
        $this->assertSame(0, $result->features['PREVIOUS_EXACT_RANK_HISTORY']['transition_sample_count']);
    }

    public function test_stat33_excludes_past_transition_confirmed_after_target_input(): void
    {
        $result = (new Stat33Calculator($this->support, $this->math))->calculate(
            $this->target(meeting: 99, stage: RaceStage::Final),
            [
                $this->history(1, '2024-01-01', meeting: 1, stage: RaceStage::Semifinal, rank: 3, confirmedAt: '2024-01-01 12:30:00+09:00'),
                $this->history(2, '2024-01-02', meeting: 1, stage: RaceStage::Final, rank: 1, confirmedAt: '2024-01-10 12:30:00+09:00'),
                $this->history(3, '2024-01-09', meeting: 99, stage: RaceStage::Semifinal, rank: 3, confirmedAt: '2024-01-09 12:30:00+09:00'),
            ],
            $this->buildOptions(),
            'batch',
        );

        $this->assertSame(StatisticFeatureResultStatus::NoHistory, $result->status);
        $this->assertSame(0, $result->features['MATCHING_TRANSITION_HISTORY']['transition_sample_count']);
        $this->assertSame(1, $result->evidence['excluded_unconfirmed_as_of_input_transition_count']);
    }

    public function test_stat33_unknown_previous_stage_is_missing_input_without_transition_features(): void
    {
        $result = (new Stat33Calculator($this->support, $this->math))->calculate(
            $this->target(meeting: 99, stage: RaceStage::Final),
            [$this->history(1, '2024-01-09', meeting: 99, stage: RaceStage::Unknown, rank: 3, confirmedAt: '2024-01-09 12:30:00+09:00')],
            $this->buildOptions(),
            'batch',
        );

        $this->assertSame(StatisticFeatureResultStatus::MissingInput, $result->status);
        $this->assertContains('UNKNOWN_PREVIOUS_STAGE', $result->evidence['quality_reasons']);
        $this->assertNull($result->features['MATCHING_TRANSITION_HISTORY']);
        $this->assertNull($result->features['PREVIOUS_EXACT_RANK_HISTORY']);
    }

    public function test_stat33_uses_adjacent_past_meeting_transition_and_excludes_future_current_results(): void
    {
        $histories = [
            $this->history(1, '2024-01-01', meeting: 1, day: 2, stage: RaceStage::Semifinal, rank: 3),
            $this->history(2, '2024-01-02', meeting: 1, day: 3, stage: RaceStage::Final, rank: 1),
            $this->history(3, '2024-01-09', meeting: 99, day: 2, stage: RaceStage::Semifinal, rank: 3),
            $this->history(4, '2024-01-11', meeting: 99, day: 4, stage: RaceStage::Final, rank: 1),
        ];
        $result = (new Stat33Calculator($this->support, $this->math))->calculate(
            $this->target(meeting: 99, day: 3, stage: RaceStage::Final),
            $histories,
            $this->buildOptions(),
            'batch',
        );

        $this->assertSame(StatisticFeatureResultStatus::Valid, $result->status);
        $this->assertSame(3, $result->features['CURRENT_MEETING_CONTEXT']['previous_rank']);
        $this->assertSame(1, $result->features['MATCHING_TRANSITION_HISTORY']['transition_sample_count']);
        $this->assertSame(2.0, $result->features['MATCHING_TRANSITION_HISTORY']['mean_rank_change']);
        $this->assertContains('ADVANCEMENT_RULE', $result->evidence['unavailable_components']);
        $this->assertArrayNotHasKey('advanced', $result->features);
        $this->assertSame(3, $result->features['CURRENT_MEETING_CONTEXT']['current_day_number']);
    }

    public function test_result_confirmation_time_changes_history_and_input_hash_but_not_target_hash(): void
    {
        $calculator = new Stat32Calculator($this->support);
        $firstHistory = [$this->history(1, '2024-01-01', rank: 1, confirmedAt: '2024-01-01 13:00:00+09:00')];
        $changedHistory = [$this->history(1, '2024-01-01', rank: 1, confirmedAt: '2024-01-01 14:00:00+09:00')];
        $first = $calculator->calculate($this->target(), $firstHistory, $this->buildOptions(), 'batch');
        $changed = $calculator->calculate($this->target(), $changedHistory, $this->buildOptions(), 'batch');

        $this->assertSame($first->evidence['target_context_hash'], $changed->evidence['target_context_hash']);
        $this->assertNotSame($first->evidence['history_input_hash'], $changed->evidence['history_input_hash']);
        $this->assertNotSame($first->inputHash, $changed->inputHash);
    }

    public function test_hashes_are_order_independent_and_target_context_changes_with_stage_inputs(): void
    {
        $calculator = new Stat32Calculator($this->support);
        $histories = [
            $this->history(1, '2024-01-01', stage: RaceStage::Final, rank: 1),
            $this->history(2, '2024-01-02', stage: RaceStage::General, rank: 2),
        ];
        $forward = $calculator->calculate($this->target(stage: RaceStage::Final), $histories, $this->buildOptions(), 'batch');
        $reverse = $calculator->calculate($this->target(stage: RaceStage::Final), array_reverse($histories), $this->buildOptions(), 'batch');
        $changed = $calculator->calculate($this->target(stage: RaceStage::Semifinal), $histories, $this->buildOptions(), 'batch');

        $this->assertSame($forward->evidence['history_input_hash'], $reverse->evidence['history_input_hash']);
        $this->assertSame($forward->inputHash, $reverse->inputHash);
        $this->assertNotSame($forward->evidence['target_context_hash'], $changed->evidence['target_context_hash']);
        $this->assertNotSame($forward->inputHash, $changed->inputHash);
    }

    public function test_every_audited_target_attribute_is_part_of_the_target_context_hash(): void
    {
        $calculator = new Stat32Calculator($this->support);
        $history = [$this->history(1, '2024-01-01', stage: RaceStage::Final, rank: 1)];
        $baseTarget = $this->target(stage: RaceStage::Final, rawType: 'Ａ級決勝');
        $base = $calculator->calculate($baseTarget, $history, $this->buildOptions(), 'batch');
        $variants = [
            $this->target(track: 11, stage: RaceStage::Final, rawType: 'Ａ級決勝'),
            $this->target(meeting: 100, stage: RaceStage::Final, rawType: 'Ａ級決勝'),
            $this->target(day: 3, stage: RaceStage::Final, rawType: 'Ａ級決勝'),
            $this->target(duration: 4, stage: RaceStage::Final, rawType: 'Ａ級決勝'),
            $this->target(grade: 'G3', stage: RaceStage::Final, rawType: 'Ａ級決勝'),
            $this->target(dayKind: '2', stage: RaceStage::Final, rawType: 'Ａ級決勝'),
            $this->target(stage: RaceStage::Final, start: '2024-01-10 12:05:00+09:00', rawType: 'Ａ級決勝'),
            $this->target(stage: RaceStage::Final, rawType: 'Ｓ級決勝'),
            $this->target(stage: RaceStage::Final, rawType: 'Ａ級決勝', rawName: 'different-raw-name'),
            $this->target(stage: RaceStage::Semifinal, rawType: 'Ａ級決勝'),
            $this->target(stage: RaceStage::Final, rawType: 'Ａ級決勝', stat01InputHash: str_repeat('b', 64)),
        ];
        foreach ($variants as $variant) {
            $result = $calculator->calculate($variant, $history, $this->buildOptions(), 'batch');
            $this->assertNotSame($base->evidence['target_context_hash'], $result->evidence['target_context_hash']);
        }
        $this->assertSame(RaceStageNormalizer::VERSION, $base->evidence['stage_normalizer_version']);
    }

    private function buildOptions(): Batch03BuildOptionsDto
    {
        return new Batch03BuildOptionsDto(1, new DateTimeImmutable('2023-01-01'), null, null, 100, 200, true);
    }

    private function target(
        ?int $track = 10,
        int $meeting = 99,
        int $day = 2,
        int $duration = 3,
        ?string $grade = 'F1',
        ?string $dayKind = '1',
        RaceStage $stage = RaceStage::General,
        ?string $start = '2024-01-10 12:00:00+09:00',
        ?string $rawType = null,
        ?string $rawName = '先固',
        ?string $stat01InputHash = null,
    ): Batch03TargetEntryDto {
        return new Batch03TargetEntryDto(
            raceId: 100,
            raceEntryId: 1000,
            playerId: 10,
            bikeNumber: 1,
            inputAsOf: new DateTimeImmutable('2024-01-10 11:55:00+09:00'),
            scheduledStartAt: $start !== null ? new DateTimeImmutable($start) : null,
            stat01InputHash: $stat01InputHash ?? str_repeat('a', 64),
            racetrackId: $track,
            raceDayId: 50,
            raceMeetingId: $meeting,
            dayNumber: $day,
            meetingDurationDays: $duration,
            meetingGrade: $grade,
            meetingDayKind: $dayKind,
            rawRaceType: $rawType ?? ($stage === RaceStage::Final ? 'Ａ級決勝' : 'Ａ級一般'),
            rawRaceName: $rawName,
            entrantCount: 7,
            normalizedStage: $stage,
        );
    }

    private function history(
        int $id,
        string $at,
        ?int $track = 10,
        int $meeting = 1,
        int $day = 1,
        int $duration = 3,
        ?string $grade = 'F1',
        RaceStage $stage = RaceStage::General,
        HistoricalResultState $state = HistoricalResultState::NormalFinish,
        ?int $rank = null,
        ?float $residual = 0.0,
        ?string $confirmedAt = null,
    ): Batch03HistoricalRaceDto {
        $date = new DateTimeImmutable($at);

        return new Batch03HistoricalRaceDto(
            playerId: 10,
            raceId: $id,
            raceEntryId: 100 + $id,
            scheduledStartAt: $date,
            racetrackId: $track,
            raceMeetingId: $meeting,
            dayNumber: $day,
            meetingDurationDays: $duration,
            meetingGrade: $grade,
            meetingDayKind: '1',
            rawRaceType: 'Ａ級一般',
            rawRaceName: '先固',
            normalizedStage: $stage,
            entrantCount: 7,
            resultState: $state,
            tied: false,
            rank: $rank,
            raceScore: '80.00',
            finishStrengthPercentile: $rank !== null ? (7 - $rank) / 6 : null,
            scoreExpectationResidual: $residual,
            historicalScoreContextHash: str_repeat((string) ($id % 10), 64),
            raceScoreMean: 75.0,
            raceScoreMax: 85.0,
            raceScoreStddevPop: 3.0,
            subjectScorePercentile: 0.5,
            raceEntryFetchedAt: $date->modify('+1 hour'),
            raceResultFetchedAt: $date->modify('+2 hours'),
            resultConfirmedAt: $confirmedAt !== null ? new DateTimeImmutable($confirmedAt) : null,
        );
    }
}
