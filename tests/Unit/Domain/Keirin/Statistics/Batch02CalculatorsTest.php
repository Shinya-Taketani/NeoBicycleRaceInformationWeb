<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Statistics;

use App\Domain\Keirin\Statistics\Calculators\Stat10Calculator;
use App\Domain\Keirin\Statistics\Calculators\Stat11Calculator;
use App\Domain\Keirin\Statistics\Calculators\Stat12Calculator;
use App\Domain\Keirin\Statistics\Calculators\Stat24Calculator;
use App\Domain\Keirin\Statistics\Calculators\Stat26Calculator;
use App\Domain\Keirin\Statistics\DTO\Batch02BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch02TargetEntryDto;
use App\Domain\Keirin\Statistics\DTO\HistoricalRaceDto;
use App\Domain\Keirin\Statistics\Enums\HistoricalResultState;
use App\Domain\Keirin\Statistics\Enums\StatisticFeatureResultStatus;
use App\Domain\Keirin\Statistics\Support\Batch02CalculatorSupport;
use App\Domain\Keirin\Statistics\Support\DeterministicJsonHasher;
use App\Domain\Keirin\Statistics\Support\StatisticalMath;
use DateTimeImmutable;
use Tests\TestCase;

class Batch02CalculatorsTest extends TestCase
{
    private Batch02CalculatorSupport $support;

    private StatisticalMath $math;

    protected function setUp(): void
    {
        parent::setUp();
        $this->support = new Batch02CalculatorSupport(new DeterministicJsonHasher);
        $this->math = new StatisticalMath;
    }

    public function test_stat10_uses_only_pre_meeting_normal_finishes_and_keeps_in_meeting_separate(): void
    {
        $histories = [
            $this->history(1, '2024-01-01 12:00:00', HistoricalResultState::NormalFinish, 1, 1.0, 0.2),
            $this->history(2, '2024-01-02 12:00:00', HistoricalResultState::Disqualified, null, null, null),
            $this->history(3, '2024-01-03 12:00:00', HistoricalResultState::NormalFinish, 4, 0.5, -0.1),
            $this->history(4, '2024-01-04 12:00:00', HistoricalResultState::NormalFinish, 2, 0.8, 0.1, meetingId: 99),
        ];

        $result = (new Stat10Calculator($this->support, $this->math))->calculate($this->target(), $histories, $this->buildOptions(), 'batch');
        $window = $result->features['PRE_MEETING']['COUNT_WINDOWS']['3'];

        $this->assertSame(2, $window['sample_count']);
        $this->assertSame(1, $window['win_count']);
        $this->assertSame(0.5, $window['win_rate']);
        $this->assertEqualsWithDelta(0.75, $window['mean_finish_strength_percentile'], 1e-12);
        $this->assertSame(1, $result->features['IN_MEETING']['sample_count']);
        $this->assertSame(StatisticFeatureResultStatus::PartialHistory, $result->status);
        $this->assertContains('IN_MEETING_RESULT_CONFIRMATION_NOT_RECONSTRUCTED', $result->evidence['quality_reasons']);
    }

    public function test_stat11_excludes_did_not_start_from_denominator_and_tracks_abnormal_recency(): void
    {
        $histories = [
            $this->history(1, '2024-01-01', HistoricalResultState::NormalFinish, 1),
            $this->history(2, '2024-01-02', HistoricalResultState::DidNotStart),
            $this->history(3, '2024-01-03', HistoricalResultState::FallDnf),
            $this->history(4, '2024-01-04', HistoricalResultState::OtherDnf),
        ];

        $result = (new Stat11Calculator($this->support))->calculate($this->target(), $histories, $this->buildOptions(), 'batch');
        $all = $result->features['ACQUIRED_HISTORY'];

        $this->assertSame(3, $all['started_race_count']);
        $this->assertSame(1, $all['did_not_start_count']);
        $this->assertSame(2, $all['abnormal_count']);
        $this->assertEqualsWithDelta(2 / 3, $all['abnormal_rate'], 1e-12);
        $this->assertSame(2, $result->features['SUMMARY']['current_abnormal_streak']);
        $this->assertSame(0, $result->features['SUMMARY']['started_races_since_last_abnormal']);
    }

    public function test_stat11_zero_started_denominator_has_null_rates_not_zero(): void
    {
        $result = (new Stat11Calculator($this->support))->calculate(
            $this->target(),
            [$this->history(1, '2024-01-01', HistoricalResultState::DidNotStart)],
            $this->buildOptions(),
            'batch',
        );

        $this->assertSame(0, $result->features['ACQUIRED_HISTORY']['abnormal_count']);
        $this->assertNull($result->features['ACQUIRED_HISTORY']['abnormal_rate']);
    }

    public function test_stat12_calculates_started_gaps_and_ignores_did_not_start(): void
    {
        $histories = [
            $this->history(1, '2024-01-01 12:00:00', HistoricalResultState::NormalFinish, 1),
            $this->history(2, '2024-01-03 12:00:00', HistoricalResultState::DidNotStart),
            $this->history(3, '2024-01-05 12:00:00', HistoricalResultState::Disqualified),
            $this->history(4, '2024-01-09 12:00:00', HistoricalResultState::NormalFinish, 2),
        ];
        $target = $this->target('2024-01-13 12:00:00');

        $result = (new Stat12Calculator($this->support, $this->math))->calculate($target, $histories, $this->buildOptions(), 'batch');
        $gaps = $result->features['HISTORICAL_PRE_MEETING_GAPS'];

        $this->assertSame(2, $gaps['historical_gap_sample_count']);
        $this->assertSame(4.0, $gaps['mean_gap_days']);
        $this->assertSame(4.0, $gaps['q25_gap_days']);
        $this->assertSame(4.0, $gaps['q75_gap_days']);
        $this->assertSame(4.0, $result->features['SUMMARY']['pre_meeting_gap_days']);
        $this->assertSame('NORMAL_FINISH', $result->features['SUMMARY']['previous_started_result_state']);
    }

    public function test_stat24_calculates_population_variability_and_excludes_abnormal_results(): void
    {
        $histories = [
            $this->history(1, '2024-01-01', HistoricalResultState::NormalFinish, 1, 1.0, 0.5),
            $this->history(2, '2024-01-02', HistoricalResultState::FallDnf),
            $this->history(3, '2024-01-03', HistoricalResultState::NormalFinish, 4, 0.0, -0.5),
            $this->history(4, '2024-01-04', HistoricalResultState::NormalFinish, 2, 0.5, 0.0),
        ];

        $result = (new Stat24Calculator($this->support, $this->math))->calculate($this->target(), $histories, $this->buildOptions(), 'batch');
        $window = $result->features['PRE_MEETING']['COUNT_WINDOWS']['3'];

        $this->assertSame(3, $window['sample_count']);
        $this->assertEqualsWithDelta(sqrt(1 / 6), $window['finish_percentile_stddev_pop'], 1e-12);
        $this->assertSame(0.5, $window['finish_percentile_iqr']);
        $this->assertSame(1, $window['upside_count']);
        $this->assertSame(1, $window['downside_count']);
        $this->assertSame(2, $window['top3_switch_count']);
    }

    public function test_stat26_calculates_schedule_density_without_fatigue_or_travel_inference(): void
    {
        $histories = [
            $this->history(1, '2024-01-01 10:00:00', HistoricalResultState::NormalFinish, racetrackId: 1),
            $this->history(2, '2024-01-01 15:00:00', HistoricalResultState::NormalFinish, racetrackId: 1),
            $this->history(3, '2024-01-02 12:00:00', HistoricalResultState::DidNotStart, racetrackId: 2),
            $this->history(4, '2024-01-03 12:00:00', HistoricalResultState::FallDnf, racetrackId: 2),
        ];
        $target = $this->target('2024-01-08 00:00:00');

        $result = (new Stat26Calculator($this->support))->calculate($target, $histories, $this->buildOptions(), 'batch');
        $window = $result->features['DAY_WINDOWS']['7'];

        $this->assertSame(3, $window['started_race_count']);
        $this->assertSame(2, $window['active_day_count']);
        $this->assertSame(5, $window['inactive_calendar_day_count']);
        $this->assertSame(1, $window['track_change_count']);
        $this->assertSame(1, $window['max_consecutive_active_days']);
        $this->assertSame(['TRAVEL_DISTANCE', 'ROLE_LOAD'], $result->evidence['unavailable_components']);
        $this->assertArrayNotHasKey('fatigue', $result->features);
    }

    public function test_no_history_and_unresolved_player_have_distinct_statuses_and_hash_is_order_independent(): void
    {
        $calculator = new Stat10Calculator($this->support, $this->math);
        $none = $calculator->calculate($this->target(), [], $this->buildOptions(), 'batch');
        $missing = $calculator->calculate($this->target(playerId: null), [], $this->buildOptions(), 'batch');
        $histories = [
            $this->history(1, '2024-01-01', HistoricalResultState::NormalFinish, 1, 1.0, 0.2),
            $this->history(2, '2024-01-02', HistoricalResultState::NormalFinish, 2, 0.8, 0.1),
        ];
        $forward = $calculator->calculate($this->target(), $histories, $this->buildOptions(), 'batch');
        $reverse = $calculator->calculate($this->target(), array_reverse($histories), $this->buildOptions(), 'batch');

        $this->assertSame(StatisticFeatureResultStatus::NoHistory, $none->status);
        $this->assertSame(StatisticFeatureResultStatus::MissingInput, $missing->status);
        $this->assertSame($forward->inputHash, $reverse->inputHash);
        $this->assertSame($forward->evidence['history_input_hash'], $reverse->evidence['history_input_hash']);
    }

    private function buildOptions(): Batch02BuildOptionsDto
    {
        return new Batch02BuildOptionsDto(1, new DateTimeImmutable('2023-01-01'), null, null, 100, 200, true);
    }

    private function target(string $at = '2024-12-31 12:00:00', ?int $playerId = 10): Batch02TargetEntryDto
    {
        return new Batch02TargetEntryDto(100, 1000, $playerId, 1, new DateTimeImmutable($at), str_repeat('a', 64), 99);
    }

    private function history(
        int $id,
        string $at,
        HistoricalResultState $state,
        ?int $rank = null,
        ?float $percentile = null,
        ?float $residual = null,
        int $meetingId = 1,
        ?int $racetrackId = 1,
    ): HistoricalRaceDto {
        return new HistoricalRaceDto(
            playerId: 10,
            raceId: $id,
            raceEntryId: 100 + $id,
            scheduledStartAt: new DateTimeImmutable($at),
            raceMeetingId: $meetingId,
            racetrackId: $racetrackId,
            entrantCount: 5,
            resultState: $state,
            tied: false,
            rank: $rank,
            raceScore: '80.00',
            finishStrengthPercentile: $percentile,
            scoreExpectationResidual: $residual,
            historicalScoreContextHash: str_repeat((string) ($id % 10), 64),
            raceEntryFetchedAt: new DateTimeImmutable($at.' +1 hour'),
            raceResultFetchedAt: new DateTimeImmutable($at.' +2 hours'),
        );
    }
}
