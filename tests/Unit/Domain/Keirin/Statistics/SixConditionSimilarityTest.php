<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Statistics;

use App\Domain\Keirin\Statistics\Calculators\SimilarConditionsCalculator;
use App\Domain\Keirin\Statistics\DTO\Batch02BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch02TargetEntryDto;
use App\Domain\Keirin\Statistics\DTO\HistoricalRaceDto;
use App\Domain\Keirin\Statistics\DTO\SimilarityConditionsDto;
use App\Domain\Keirin\Statistics\Enums\HistoricalResultState as State;
use App\Domain\Keirin\Statistics\Support\AcquiredHistorySupport;
use App\Domain\Keirin\Statistics\Support\DeterministicJsonHasher;
use App\Domain\Keirin\Statistics\Support\SixConditionSimilarity;
use DateTimeImmutable as Time;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SixConditionSimilarityTest extends TestCase
{
    public function test_fixed_relative_distances_and_circular_jst_minutes(): void
    {
        $a = $this->conditions(99, 999, ['field_size' => 7, 'day_number' => 1, 'start_minute_jst' => 1439]);
        $b = $this->conditions(1, 10, ['field_size' => 9, 'day_number' => 2, 'start_minute_jst' => 1]);
        $out = (new SixConditionSimilarity)->compare($a, $b);
        $this->assertEqualsWithDelta(2 / 9, $out['conditions']['field_size']['distance'], 1e-12);
        $this->assertSame(0.5, $out['conditions']['day_number']['distance']);
        $this->assertEqualsWithDelta(2 / 720, $out['conditions']['start_minute_jst']['distance'], 1e-12);
        $c = (new SixConditionSimilarity)->compare($this->conditions(99, 999, ['day_number' => 5]), $this->conditions(1, 10, ['day_number' => 6]));
        $this->assertEqualsWithDelta(1 / 6, $c['conditions']['day_number']['distance'], 1e-12);
        $this->assertSame(6, $out['comparable_conditions']);
    }

    public function test_two_known_conditions_keep_missing_distinct_and_reduce_the_weight(): void
    {
        $fields = ['field_size' => null, 'race_stage' => 'FINAL', 'meeting_grade' => 'G3', 'entry_grade' => null, 'day_number' => null, 'start_minute_jst' => null];
        $out = (new SixConditionSimilarity)->compare($this->conditions(99, 999, $fields), $this->conditions(1, 10, $fields));
        $this->assertSame(2, $out['comparable_conditions']);
        $this->assertEqualsWithDelta(1.0, $out['similarity'], 1e-12);
        $this->assertEqualsWithDelta(1 / 3, $out['six_condition_coverage'], 1e-12);
        $this->assertEqualsWithDelta(1 / 3, $out['weight'], 1e-12);
        $this->assertNull($out['conditions']['entry_grade']['similarity']);
    }

    public function test_unknown_other_invalid_numbers_are_not_matches(): void
    {
        $fields = ['field_size' => 0, 'race_stage' => 'OTHER', 'meeting_grade' => 'UNKNOWN', 'entry_grade' => 'UNRECOGNIZED', 'day_number' => -1, 'start_minute_jst' => 1440];
        $out = (new SixConditionSimilarity)->compare($this->conditions(99, 999, $fields), $this->conditions(1, 10, $fields));
        $this->assertSame(0, $out['comparable_conditions']);
        $this->assertNull($out['similarity']);
        $this->assertNull($out['weight']);
        $this->assertNull($out['conditions']['race_stage']['distance']);
        $this->assertContains('INSUFFICIENT_COMPARABLE_CONDITIONS', $out['reasons']);
    }

    public function test_experience_includes_started_disqualification_but_performance_does_not(): void
    {
        $events = [$this->event(1, State::NormalFinish, 1), $this->event(2, State::Disqualified),
            $this->event(3, State::OtherDnf), $this->event(4, State::DidNotStart), $this->event(5, State::UnknownAbnormal)];
        $out = $this->calculate($events);
        $experience = $out['STAT-27']->features;
        $performance = $out['STAT-16']->features;
        $this->assertSame(3, $experience['eligible_started_history_count']);
        $this->assertEqualsWithDelta(3.0, $experience['weighted_experience'], 1e-12);
        $this->assertEqualsWithDelta(3.0, $experience['effective_experience'], 1e-12);
        $this->assertSame(1, $performance['performance_history_count']);
        $this->assertEqualsWithDelta(1.0, $performance['rank_rates_among_normal_finishes'][1], 1e-12);
        $this->assertSame(1, $out['STAT-27']->evidence['exclusions']['DID_NOT_START_OR_WITHDRAWN']);
        $this->assertSame(1, $out['STAT-27']->evidence['exclusions']['PARTICIPATION_NOT_VERIFIED']);
    }

    public function test_same_meeting_and_meeting_cutoff_are_excluded_and_future_input_is_audited(): void
    {
        $events = [$this->event(1, State::NormalFinish, 1, '2024-12-01', 1),
            $this->event(2, State::NormalFinish, 1, '2025-01-04T01:00:00+09:00'),
            $this->event(3, State::NormalFinish, 1, '2025-01-05')];
        $out = $this->calculate($events)['STAT-27'];
        $this->assertNull($out->features['weighted_experience']);
        $this->assertSame('NO_ELIGIBLE_STARTED_HISTORY', $out->features['history_state']);
        $this->assertSame(1, $out->evidence['exclusions']['SAME_MEETING']);
        $this->assertSame(1, $out->evidence['exclusions']['AT_OR_AFTER_MEETING_CUTOFF']);
        $this->assertSame('AT_OR_AFTER_INPUT_AS_OF', $out->evidence['excluded_history_references'][0]['reason']);
    }

    public function test_zero_weight_and_insufficient_conditions_are_not_the_same_as_no_history(): void
    {
        $only = ['field_size' => null, 'race_stage' => 'FINAL', 'meeting_grade' => 'G3', 'entry_grade' => null, 'day_number' => null, 'start_minute_jst' => null];
        $different = array_replace($only, ['race_stage' => 'GENERAL', 'meeting_grade' => 'F2']);
        $rows = [$this->event(1, State::NormalFinish, 1)];
        $out = $this->calculate($rows, $only, $different)['STAT-27']->features;
        $this->assertEqualsWithDelta(0, $out['weighted_experience'], 1e-12);
        $this->assertNull($out['effective_experience']);
        $this->assertSame('COMPARABLE_ZERO_WEIGHT', $out['history_state']);
        $insufficient = $this->calculate($rows, array_replace($only, ['meeting_grade' => null]), $different)['STAT-27']->features;
        $this->assertNull($insufficient['weighted_experience']);
        $this->assertSame('CONDITIONS_NOT_COMPARABLE', $insufficient['history_state']);
        $this->assertSame('NO_REFERENCED_HISTORY', $this->calculate([])['STAT-27']->features['history_state']);
    }

    public function test_condition_source_identity_mismatch_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->calculate([$this->event(1, State::NormalFinish, 1)], wrongIdentity: true);
    }

    public function test_effective_experience_uses_squared_weights_not_simple_reference_count(): void
    {
        $partial = ['field_size' => null, 'race_stage' => 'FINAL', 'meeting_grade' => 'G3', 'entry_grade' => null, 'day_number' => null, 'start_minute_jst' => null];
        $out = $this->calculate([$this->event(1, State::NormalFinish, 1), $this->event(2, State::OtherDnf)],
            historyValues: [1 => [], 2 => $partial]);
        $this->assertEqualsWithDelta(4 / 3, $out['STAT-27']->features['weighted_experience'], 1e-12);
        $this->assertEqualsWithDelta(1.6, $out['STAT-27']->features['effective_experience'], 1e-12);
        $this->assertEqualsWithDelta(2 / 3, $out['STAT-27']->features['mean_six_condition_coverage'], 1e-12);
        $this->assertSame(1, $out['STAT-16']->features['performance_history_count']);
    }

    private function calculate(array $events, array $targetValues = [], array $historyValues = [], bool $wrongIdentity = false): array
    {
        $hasher = new DeterministicJsonHasher;
        $target = new Batch02TargetEntryDto(99, 999, 7, 1, new Time('2025-01-04T12:00:00+09:00'), new Time('2025-01-04T12:05:00+09:00'), str_repeat('a', 64), 1);
        $conditions = [999 => $this->conditions(99, 999, $targetValues, 1)];
        foreach ($events as $event) {
            $conditions[$event->raceEntryId] = $this->conditions($wrongIdentity ? 77 : $event->raceId, $event->raceEntryId,
                $historyValues[$event->raceId] ?? $historyValues, $event->raceMeetingId);
        }

        return (new SimilarConditionsCalculator(new AcquiredHistorySupport($hasher), new SixConditionSimilarity, $hasher))->calculate($target, $events,
            new Batch02BuildOptionsDto(1, new Time('2024-01-01'), null, null, 99, 1, false), $conditions);
    }

    private function conditions(int $race, int $entry, array $values = [], ?int $meeting = 2): SimilarityConditionsDto
    {
        return new SimilarityConditionsDto(array_replace(['field_size' => 7, 'race_stage' => 'FINAL', 'meeting_grade' => 'G3',
            'entry_grade' => 'S1', 'day_number' => 1, 'start_minute_jst' => 720], $values), [],
            ['race_id' => $race, 'entry_id' => $entry, 'meeting_id' => $meeting, 'meeting_start' => $race === 99 ? '2025-01-04' : '2024-12-01'], []);
    }

    private function event(int $id, State $state, ?int $rank = null, string $at = '2024-12-01', ?int $meeting = 2): HistoricalRaceDto
    {
        // Artificial formula boundaries only. Actual acceptance uses sealed production-read-only snapshots.
        return new HistoricalRaceDto(7, $id, $id * 10, new Time($at), $meeting, 1, 7, $state, false, $rank, '90.00', null, null,
            str_repeat('a', 64), new Time('2026-07-01'), new Time('2026-07-01'));
    }
}
