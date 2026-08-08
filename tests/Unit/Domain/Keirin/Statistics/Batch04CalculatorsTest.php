<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Statistics;

use App\Domain\Keirin\Statistics\Calculators\Stat39Calculator;
use App\Domain\Keirin\Statistics\Calculators\Stat42Calculator;
use App\Domain\Keirin\Statistics\DTO\Batch04BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch04HeadToHeadEventDto;
use App\Domain\Keirin\Statistics\DTO\Batch04PositionHistoryContextDto;
use App\Domain\Keirin\Statistics\DTO\Batch04RaceInputDto;
use App\Domain\Keirin\Statistics\DTO\Batch04TargetEntryDto;
use App\Domain\Keirin\Statistics\Enums\HistoricalResultState;
use App\Domain\Keirin\Statistics\Enums\StatisticFeatureResultStatus;
use App\Domain\Keirin\Statistics\Support\Batch04CalculatorSupport;
use App\Domain\Keirin\Statistics\Support\DeterministicJsonHasher;
use App\Domain\Keirin\Statistics\Support\StatisticalMath;
use DateTimeImmutable;
use Tests\TestCase;

class Batch04CalculatorsTest extends TestCase
{
    private Batch04CalculatorSupport $support;

    protected function setUp(): void
    {
        parent::setUp();
        $this->support = new Batch04CalculatorSupport(new DeterministicJsonHasher, new StatisticalMath);
    }

    public function test_stat39_uses_field_and_track_scopes_without_assuming_contiguous_bike_numbers(): void
    {
        $target = $this->target(10, 100, 4, 3, bikes: [1, 2, 4, 6, 7]);
        $race = new Batch04RaceInputDto(100, $target->inputAsOf, [$target]);
        $context = $this->positionContext(
            fieldBike: $this->metrics(2, wins: 1, residual: 0.5),
            baseline: $this->metrics(4, wins: 1, residual: 0.1),
            trackBike: $this->metrics(1, wins: 1, residual: 0.4),
            trackBaseline: $this->metrics(2, wins: 1, residual: 0.2),
        );

        $result = (new Stat39Calculator($this->support))->calculate($target, $race, $context, [], $this->buildOptions(), 'batch');

        $this->assertSame(StatisticFeatureResultStatus::Valid, $result->status);
        $this->assertSame(3, $result->features['TARGET_POSITION_CONTEXT']['participating_bike_order_index']);
        $this->assertSame(0.5, $result->features['TARGET_POSITION_CONTEXT']['participating_bike_order_percentile']);
        $this->assertSame(2, $result->features['FIELD_BIKE']['sample_count']);
        $this->assertSame(4, $result->features['FIELD_BASELINE']['sample_count']);
        $this->assertEqualsWithDelta(0.25, $result->features['FIELD_BIKE_DELTA']['win_rate'], 1e-12);
        $this->assertSame(0.5, $result->features['FIELD_BIKE']['mean_score_expectation_residual']);
        $this->assertNull($result->features['POSITION_BIAS_SCORE']);
    }

    public function test_stat39_field_history_controls_outer_status_and_track_or_frame_absence_does_not(): void
    {
        $target = $this->target(10, 100, 2, null);
        $race = new Batch04RaceInputDto(100, $target->inputAsOf, [$target]);
        $valid = (new Stat39Calculator($this->support))->calculate(
            $target,
            $race,
            $this->positionContext($this->metrics(1), $this->metrics(3), $this->metrics(0), $this->metrics(0)),
            [],
            $this->buildOptions(),
            'batch',
        );
        $missing = (new Stat39Calculator($this->support))->calculate(
            $target,
            $race,
            $this->positionContext($this->metrics(0), $this->metrics(3), $this->metrics(0), $this->metrics(0)),
            [],
            $this->buildOptions(),
            'batch',
        );

        $this->assertSame(StatisticFeatureResultStatus::Valid, $valid->status);
        $this->assertNull($valid->features['FIELD_FRAME']);
        $this->assertContains('MISSING_FRAME_NUMBER', $valid->evidence['quality_reasons']);
        $this->assertSame(StatisticFeatureResultStatus::NoHistory, $missing->status);
        $this->assertSame('NO_VEHICLE_HISTORY', $missing->evidence['status_reason']);
    }

    public function test_stat39_hash_changes_with_target_and_raw_history_hash_but_is_deterministic(): void
    {
        $calculator = new Stat39Calculator($this->support);
        $target = $this->target(10, 100, 2, 1);
        $race = new Batch04RaceInputDto(100, $target->inputAsOf, [$target]);
        $first = $calculator->calculate($target, $race, $this->positionContext(historyHash: 'a'), [], $this->buildOptions(), 'batch');
        $repeat = $calculator->calculate($target, $race, $this->positionContext(historyHash: 'a'), [], $this->buildOptions(), 'batch');
        $historyChanged = $calculator->calculate($target, $race, $this->positionContext(historyHash: 'b'), [], $this->buildOptions(), 'batch');
        $changedTargets = [
            $this->target(10, 100, 3, 1),
            $this->target(10, 100, 2, 2),
            $this->target(10, 100, 2, 1, bikes: [1, 2, 3, 4, 5, 6]),
            $this->target(10, 100, 2, 1, racetrack: 2),
            $this->target(10, 100, 2, 1, stat01Hash: str_repeat('f', 64)),
        ];

        $this->assertSame($first->inputHash, $repeat->inputHash);
        $this->assertNotSame($first->inputHash, $historyChanged->inputHash);
        foreach ($changedTargets as $changedTarget) {
            $targetChanged = $calculator->calculate($changedTarget, new Batch04RaceInputDto(100, $changedTarget->inputAsOf, [$changedTarget]), $this->positionContext(historyHash: 'a'), [], $this->buildOptions(), 'batch');
            $this->assertNotSame($first->inputHash, $targetChanged->inputHash);
        }
    }

    public function test_stat42_canonical_pair_is_directional_and_relative_residual_is_reproducible(): void
    {
        $a = $this->target(10, 100, 1, 1, player: 10);
        $b = $this->target(25, 100, 2, 2, player: 25);
        $race = new Batch04RaceInputDto(100, $a->inputAsOf, [$a, $b]);
        $event = $this->event(
            10,
            25,
            firstRank: 1,
            secondRank: 3,
            firstFinish: 0.5,
            secondFinish: 0.75,
            firstScore: 0.75,
            secondScore: 0.25,
        );
        $calculator = new Stat42Calculator($this->support);
        $aResult = $calculator->calculate($a, $race, $this->emptyPosition(), ['10:25' => [$event]], $this->buildOptions(), 'batch');
        $bResult = $calculator->calculate($b, $race, $this->emptyPosition(), ['10:25' => [$event]], $this->buildOptions(), 'batch');
        $aPair = $aResult->features['HEAD_TO_HEAD_BY_COENTRANT'][0];
        $bPair = $bResult->features['HEAD_TO_HEAD_BY_COENTRANT'][0];

        $this->assertSame('10:25', $aPair['pair_key']);
        $this->assertSame('10:25', $bPair['pair_key']);
        $this->assertSame(1, $aPair['DIRECT_HISTORY']['subject_ahead_count']);
        $this->assertSame(1, $bPair['DIRECT_HISTORY']['opponent_ahead_count']);
        $this->assertSame(2.0, $aPair['DIRECT_HISTORY']['mean_relative_rank_difference']);
        $this->assertSame(-2.0, $bPair['DIRECT_HISTORY']['mean_relative_rank_difference']);
        $this->assertEqualsWithDelta(-0.75, $aPair['DIRECT_HISTORY']['mean_relative_expectation_residual'], 1e-12);
        $this->assertEqualsWithDelta(0.75, $bPair['DIRECT_HISTORY']['mean_relative_expectation_residual'], 1e-12);
    }

    public function test_stat42_tie_abnormal_and_dns_have_distinct_denominators(): void
    {
        $a = $this->target(10, 100, 1, 1, player: 10);
        $b = $this->target(25, 100, 2, 2, player: 25);
        $race = new Batch04RaceInputDto(100, $a->inputAsOf, [$a, $b]);
        $events = [
            $this->event(10, 25, firstRank: 2, secondRank: 2),
            $this->event(10, 25, raceId: 2, firstState: HistoricalResultState::NormalFinish, secondState: HistoricalResultState::Disqualified),
            $this->event(10, 25, raceId: 3, firstState: HistoricalResultState::NormalFinish, secondState: HistoricalResultState::DidNotStart),
        ];

        $result = (new Stat42Calculator($this->support))->calculate($a, $race, $this->emptyPosition(), ['10:25' => $events], $this->buildOptions(), 'batch');
        $history = $result->features['HEAD_TO_HEAD_BY_COENTRANT'][0]['DIRECT_HISTORY'];

        $this->assertSame(2, $history['direct_meeting_count']);
        $this->assertSame(1, $history['normal_direct_meeting_count']);
        $this->assertSame(1, $history['abnormal_direct_meeting_count']);
        $this->assertSame(1, $history['tied_count']);
        $this->assertEqualsWithDelta(1.0, $history['tied_rate'], 1e-12);
    }

    public function test_stat42_distinguishes_no_history_abnormal_only_and_partial_opponent_coverage(): void
    {
        $a = $this->target(10, 100, 1, 1, player: 10);
        $b = $this->target(25, 100, 2, 2, player: 25);
        $c = $this->target(30, 100, 3, 3, player: 30);
        $race = new Batch04RaceInputDto(100, $a->inputAsOf, [$a, $b, $c]);
        $calculator = new Stat42Calculator($this->support);
        $none = $calculator->calculate($a, $race, $this->emptyPosition(), [], $this->buildOptions(), 'batch');
        $abnormal = $calculator->calculate($a, $race, $this->emptyPosition(), [
            '10:25' => [$this->event(10, 25, secondState: HistoricalResultState::Disqualified)],
        ], $this->buildOptions(), 'batch');
        $partialCoverage = $calculator->calculate($a, $race, $this->emptyPosition(), [
            '10:25' => [$this->event(10, 25, firstRank: 1, secondRank: 2)],
        ], $this->buildOptions(), 'batch');

        $this->assertSame(StatisticFeatureResultStatus::NoHistory, $none->status);
        $this->assertSame('NO_HEAD_TO_HEAD_HISTORY', $none->evidence['status_reason']);
        $this->assertSame(StatisticFeatureResultStatus::Partial, $abnormal->status);
        $this->assertSame('NO_NORMAL_HEAD_TO_HEAD', $abnormal->evidence['status_reason']);
        $this->assertSame(StatisticFeatureResultStatus::Valid, $partialCoverage->status);
        $this->assertSame(1, $partialCoverage->features['HEAD_TO_HEAD_SUMMARY']['opponents_with_direct_history_count']);
        $this->assertSame(1, $partialCoverage->features['HEAD_TO_HEAD_SUMMARY']['opponents_without_direct_history_count']);
    }

    public function test_stat42_does_not_apply_transitivity_and_tracks_same_race_pair_dependency(): void
    {
        $a = $this->target(10, 100, 1, 1, player: 10);
        $b = $this->target(25, 100, 2, 2, player: 25);
        $c = $this->target(30, 100, 3, 3, player: 30);
        $race = new Batch04RaceInputDto(100, $a->inputAsOf, [$a, $b, $c]);
        $calculator = new Stat42Calculator($this->support);
        $transitive = $calculator->calculate($a, $race, $this->emptyPosition(), [
            '10:25' => [$this->event(10, 25)],
            '25:30' => [$this->event(25, 30)],
        ], $this->buildOptions(), 'batch');
        $dependent = $calculator->calculate($a, $race, $this->emptyPosition(), [
            '10:25' => [$this->event(10, 25, raceId: 50)],
            '10:30' => [$this->event(10, 30, raceId: 50)],
        ], $this->buildOptions(), 'batch');

        $this->assertSame(0, $transitive->features['HEAD_TO_HEAD_BY_COENTRANT'][1]['DIRECT_HISTORY']['direct_meeting_count']);
        $this->assertSame('NOT_APPLIED', $transitive->evidence['transitivity']);
        $this->assertSame(2, $dependent->features['HEAD_TO_HEAD_SUMMARY']['sum_pair_direct_meeting_count']);
        $this->assertSame(1, $dependent->features['HEAD_TO_HEAD_SUMMARY']['unique_direct_source_race_count']);
    }

    public function test_stat42_filters_future_and_target_race_but_not_late_app_confirmation(): void
    {
        $a = $this->target(10, 100, 1, 1, player: 10);
        $b = $this->target(25, 100, 2, 2, player: 25);
        $race = new Batch04RaceInputDto(100, $a->inputAsOf, [$a, $b]);
        $events = [
            $this->event(10, 25, raceId: 1, confirmedAt: '2025-01-01'),
            $this->event(10, 25, raceId: 100),
            $this->event(10, 25, raceId: 3, scheduledAt: '2024-01-11'),
        ];

        $result = (new Stat42Calculator($this->support))->calculate($a, $race, $this->emptyPosition(), ['10:25' => $events], $this->buildOptions(), 'batch');
        $history = $result->features['HEAD_TO_HEAD_BY_COENTRANT'][0]['DIRECT_HISTORY'];

        $this->assertSame(1, $history['direct_meeting_count']);
        $this->assertSame(1, $history['app_observed_after_input_event_count']);
    }

    public function test_stat42_hash_is_order_invariant_and_tracks_current_context_and_historical_inputs(): void
    {
        $a = $this->target(10, 100, 1, 1, player: 10);
        $b = $this->target(25, 100, 2, 2, player: 25);
        $events = [
            $this->event(10, 25, raceId: 1, scheduledAt: '2024-01-01'),
            $this->event(10, 25, raceId: 2, firstRank: 2, secondRank: 3, scheduledAt: '2024-01-02'),
        ];
        $calculator = new Stat42Calculator($this->support);
        $base = $calculator->calculate($a, new Batch04RaceInputDto(100, $a->inputAsOf, [$a, $b]), $this->emptyPosition(), ['10:25' => $events], $this->buildOptions(), 'batch');
        $reversed = $calculator->calculate($a, new Batch04RaceInputDto(100, $a->inputAsOf, [$a, $b]), $this->emptyPosition(), ['10:25' => array_reverse($events)], $this->buildOptions(), 'batch');

        $historicalChanges = [
            $this->event(10, 25, raceId: 2, firstRank: 3, secondRank: 2, scheduledAt: '2024-01-02'),
            $this->event(10, 25, raceId: 2, firstRawScore: '81.00', scheduledAt: '2024-01-02'),
            $this->event(10, 25, raceId: 2, secondState: HistoricalResultState::Disqualified, scheduledAt: '2024-01-02'),
            $this->event(10, 25, raceId: 2, firstBike: 3, firstFrame: 2, scheduledAt: '2024-01-02'),
        ];
        $this->assertSame($base->inputHash, $reversed->inputHash);
        foreach ($historicalChanges as $changedEvent) {
            $changed = $calculator->calculate($a, new Batch04RaceInputDto(100, $a->inputAsOf, [$a, $b]), $this->emptyPosition(), ['10:25' => [$events[0], $changedEvent]], $this->buildOptions(), 'batch');
            $this->assertNotSame($base->inputHash, $changed->inputHash);
        }

        $changedCurrentEntries = [
            $this->target(25, 100, 2, 2, player: 30),
            $this->target(25, 100, 3, 2, player: 25),
            $this->target(25, 100, 2, 2, player: 25, stat01Hash: str_repeat('e', 64)),
        ];
        foreach ($changedCurrentEntries as $changedOpponent) {
            $pairKey = $changedOpponent->playerId === 30 ? '10:30' : '10:25';
            $pairEvents = $changedOpponent->playerId === 30
                ? [$this->event(10, 30)]
                : $events;
            $changed = $calculator->calculate($a, new Batch04RaceInputDto(100, $a->inputAsOf, [$a, $changedOpponent]), $this->emptyPosition(), [$pairKey => $pairEvents], $this->buildOptions(), 'batch');
            $this->assertNotSame($base->inputHash, $changed->inputHash);
        }
    }

    private function buildOptions(): Batch04BuildOptionsDto
    {
        return new Batch04BuildOptionsDto(1, new DateTimeImmutable('2023-01-01'), null, null, 100, 200, true);
    }

    /** @param list<int> $bikes */
    private function target(
        int $entryId,
        int $raceId,
        ?int $bike,
        ?int $frame,
        ?int $player = 10,
        array $bikes = [1, 2, 3, 4, 5, 6, 7],
        ?int $racetrack = 1,
        ?string $stat01Hash = null,
    ): Batch04TargetEntryDto {
        return new Batch04TargetEntryDto(
            raceId: $raceId,
            raceEntryId: $entryId,
            playerId: $player,
            bikeNumber: $bike,
            frameNumber: $frame,
            inputAsOf: new DateTimeImmutable('2024-01-10 11:55:00+09:00'),
            scheduledStartAt: new DateTimeImmutable('2024-01-10 12:00:00+09:00'),
            stat01InputHash: $stat01Hash ?? str_repeat((string) ($entryId % 10), 64),
            stat01RaceScore: 80.0,
            stat01Rank: 1,
            stat01StrengthPercentile: 1.0,
            declaredEntrantCount: count($bikes),
            actualEntryCount: count($bikes),
            racetrackId: $racetrack,
            participatingBikeNumbers: $bikes,
        );
    }

    private function positionContext(
        ?array $fieldBike = null,
        ?array $baseline = null,
        ?array $trackBike = null,
        ?array $trackBaseline = null,
        string $historyHash = 'a',
    ): Batch04PositionHistoryContextDto {
        $fieldBike ??= $this->metrics(1);
        $baseline ??= $this->metrics(1);
        $trackBike ??= $this->metrics(1);
        $trackBaseline ??= $this->metrics(1);

        return new Batch04PositionHistoryContextDto([
            'FIELD_BIKE' => $fieldBike,
            'FIELD_BASELINE' => $baseline,
            'TRACK_FIELD_BIKE' => $trackBike,
            'TRACK_FIELD_BASELINE' => $trackBaseline,
            'FIELD_FRAME' => null,
            'TRACK_FIELD_FRAME' => null,
        ], hash('sha256', $historyHash), ['source_max_fetched_at' => null]);
    }

    private function emptyPosition(): Batch04PositionHistoryContextDto
    {
        return $this->positionContext();
    }

    /** @return array<string, mixed> */
    private function metrics(int $count, int $wins = 0, ?float $residual = null): array
    {
        return [
            'history_count' => $count,
            'history_hash' => $count > 0 ? str_repeat('a', 64) : null,
            'sample_count' => $count,
            'win_count' => $wins,
            'win_rate' => $count > 0 ? $wins / $count : null,
            'top2_count' => $wins,
            'top2_rate' => $count > 0 ? $wins / $count : null,
            'top3_count' => $wins,
            'top3_rate' => $count > 0 ? $wins / $count : null,
            'mean_rank' => $count > 0 ? 2.0 : null,
            'mean_finish_strength_percentile' => $count > 0 ? 0.5 : null,
            'residual_sample_count' => $residual !== null ? $count : 0,
            'mean_score_expectation_residual' => $residual,
            'source_max_fetched_at' => null,
        ];
    }

    private function event(
        int $firstPlayer,
        int $secondPlayer,
        int $raceId = 1,
        HistoricalResultState $firstState = HistoricalResultState::NormalFinish,
        HistoricalResultState $secondState = HistoricalResultState::NormalFinish,
        ?int $firstRank = 1,
        ?int $secondRank = 2,
        ?float $firstFinish = 1.0,
        ?float $secondFinish = 0.5,
        ?float $firstScore = 0.75,
        ?float $secondScore = 0.25,
        string $scheduledAt = '2024-01-01',
        ?string $confirmedAt = '2024-01-01 13:00:00',
        string $firstRawScore = '80.00',
        string $secondRawScore = '75.00',
        int $firstBike = 1,
        int $secondBike = 2,
        ?int $firstFrame = null,
        ?int $secondFrame = null,
    ): Batch04HeadToHeadEventDto {
        return new Batch04HeadToHeadEventDto(
            raceId: $raceId,
            scheduledStartAt: new DateTimeImmutable($scheduledAt),
            entrantCount: 7,
            racetrackId: 1,
            firstPlayerId: $firstPlayer,
            secondPlayerId: $secondPlayer,
            firstRaceEntryId: $raceId * 10 + 1,
            secondRaceEntryId: $raceId * 10 + 2,
            firstBikeNumber: $firstBike,
            secondBikeNumber: $secondBike,
            firstFrameNumber: $firstFrame,
            secondFrameNumber: $secondFrame,
            firstResultState: $firstState,
            secondResultState: $secondState,
            firstTied: $firstRank !== null && $firstRank === $secondRank,
            secondTied: $firstRank !== null && $firstRank === $secondRank,
            firstRank: $firstRank,
            secondRank: $secondRank,
            firstFinishPercentile: $firstFinish,
            secondFinishPercentile: $secondFinish,
            firstRaceScore: $firstRawScore,
            secondRaceScore: $secondRawScore,
            firstScorePercentile: $firstScore,
            secondScorePercentile: $secondScore,
            historicalScoreContextHash: str_repeat('c', 64),
            firstRaceEntryFetchedAt: new DateTimeImmutable('2024-01-01 10:00:00'),
            secondRaceEntryFetchedAt: new DateTimeImmutable('2024-01-01 10:00:00'),
            firstRaceResultFetchedAt: new DateTimeImmutable('2024-01-01 13:00:00'),
            secondRaceResultFetchedAt: new DateTimeImmutable('2024-01-01 13:00:00'),
            resultConfirmedAt: $confirmedAt !== null ? new DateTimeImmutable($confirmedAt) : null,
        );
    }
}
