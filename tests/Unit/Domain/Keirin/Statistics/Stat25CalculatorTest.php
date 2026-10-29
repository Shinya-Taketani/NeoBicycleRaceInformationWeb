<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Statistics;

use App\Domain\Keirin\Statistics\Calculators\Stat25Calculator;
use App\Domain\Keirin\Statistics\DTO\Batch02BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch02TargetEntryDto;
use App\Domain\Keirin\Statistics\DTO\HistoricalRaceDto;
use App\Domain\Keirin\Statistics\Enums\HistoricalResultState as State;
use App\Domain\Keirin\Statistics\Enums\StatisticFeatureResultStatus;
use App\Domain\Keirin\Statistics\Support\Batch02CalculatorSupport;
use App\Domain\Keirin\Statistics\Support\DeterministicJsonHasher;
use DateTimeImmutable as Time;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class Stat25CalculatorTest extends TestCase
{
    public function test_synthetic_boundary_counts_disqualification_not_all_abnormal_results(): void
    {
        $out = $this->calculate([$this->event(1, '2024-01-05', State::Disqualified),
            $this->event(2, '2024-05-02', State::FallDnf), $this->event(3, '2024-06-02', State::Disqualified),
            $this->event(4, '2024-07-02', State::DidNotStart), $this->event(5, '2024-08-02', State::NormalFinish)]);
        $f = $out->features;
        $this->assertSame(4, $f['PAST_YEAR']['started_race_count']);
        $this->assertSame(2, $f['PAST_YEAR']['disqualification_count']);
        $this->assertSame(0.5, $f['PAST_YEAR']['disqualification_rate']);
        $this->assertSame(1, $f['RECENCY']['started_races_since_last_disqualification']);
        $this->assertTrue($f['RECENCY']['repeated_disqualification_observed']);
        $this->assertFalse($f['LAST_10_STARTED']['window_complete']);
        $this->assertSame('NOT_AUTHORIZED', $f['prediction_use']);
    }

    public function test_zero_unknown_and_no_history_remain_distinct(): void
    {
        $zero = $this->calculate([$this->event(1, '2024-02-01', State::NormalFinish)])->features;
        $none = $this->calculate([]);
        $unknown = $this->calculate([$this->event(2, '2024-02-01', State::UnknownAbnormal)])->features;
        $dns = $this->calculate([$this->event(3, '2024-02-01', State::DidNotStart)])->features;
        $this->assertSame(0, $zero['ACQUIRED_HISTORY']['disqualification_count']);
        $this->assertSame(0.0, $zero['ACQUIRED_HISTORY']['disqualification_rate']);
        $this->assertNull($none->features['ACQUIRED_HISTORY']['disqualification_count']);
        $this->assertSame(StatisticFeatureResultStatus::NoHistory, $none->status);
        $this->assertNull($unknown['ACQUIRED_HISTORY']['disqualification_count']);
        $this->assertNull($unknown['ACQUIRED_HISTORY']['disqualification_rate']);
        $this->assertSame(0, $dns['ACQUIRED_HISTORY']['disqualification_count']);
        $this->assertNull($dns['ACQUIRED_HISTORY']['disqualification_rate']);
    }

    public function test_target_future_and_before_coverage_are_excluded_without_changing_reproduction(): void
    {
        $one = $this->event(1, '2024-06-01', State::Disqualified);
        $out = $this->calculate([$one]);
        $with = $this->calculate([$this->event(99, '2024-07-01', State::Disqualified), $one,
            $this->event(2, '2025-01-05', State::Disqualified), $this->event(3, '2023-12-31', State::Disqualified)]);
        $this->assertSame($out->inputHash, $with->inputHash);
        $this->assertSame($out->features, $with->features);
    }

    public function test_duplicate_history_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $one = $this->event(1, '2024-06-01', State::Disqualified);
        $this->calculate([$one, $one]);
    }

    public function test_unknown_later_status_does_not_establish_last_disqualification_recency(): void
    {
        $f = $this->calculate([$this->event(1, '2024-01-05', State::Disqualified),
            $this->event(2, '2024-05-02', State::UnknownAbnormal)])->features;
        $this->assertNotNull($f['RECENCY']['last_known_disqualification_at']);
        $this->assertNull($f['RECENCY']['last_disqualification_at']);
        $this->assertNull($f['RECENCY']['started_races_since_last_disqualification']);
        $this->assertSame('RESULT_STATE_UNKNOWN', $f['RECENCY']['recency_status']);
    }

    public function test_other_player_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculate([$this->event(1, '2024-06-01', State::NormalFinish, 8)]);
    }

    public function test_last_ten_uses_actual_starts_and_calendar_year_is_strict(): void
    {
        $events = [$this->event(30, '2024-01-03', State::Disqualified)];
        for ($i = 1; $i <= 11; $i++) {
            $events[] = $this->event($i, '2024-12-'.sprintf('%02d', $i), $i === 1 ? State::Disqualified : State::NormalFinish);
        }
        $events[] = $this->event(31, '2024-12-12', State::Withdrawn);
        $out = $this->calculate(array_reverse($events))->features;
        $this->assertTrue($out['LAST_10_STARTED']['window_complete']);
        $this->assertSame(0, $out['LAST_10_STARTED']['disqualification_count']);
        $this->assertSame(1, $out['PAST_YEAR']['disqualification_count']);
        $this->assertSame(2, $out['ACQUIRED_HISTORY']['disqualification_count']);
        $this->assertSame(10, $out['RECENCY']['started_races_since_last_disqualification']);
    }

    public function test_artificial_2026_target_is_rejected_without_accessing_real_data(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculate([], '2026-01-04T00:00:00+09:00');
    }

    private function calculate(array $events, string $at = '2025-01-04T00:00:00+09:00')
    {
        $hash = new DeterministicJsonHasher;

        return (new Stat25Calculator(new Batch02CalculatorSupport($hash), $hash))->calculate(
            new Batch02TargetEntryDto(99, 999, 7, 1, new Time($at), new Time($at), str_repeat('a', 64), 1), $events,
            new Batch02BuildOptionsDto(1, new Time('2024-01-01T00:00:00+09:00'), null, null, 99, 1, false));
    }

    private function event(int $id, string $at, State $state, int $player = 7): HistoricalRaceDto
    {
        return new HistoricalRaceDto($player, $id, $id * 10, new Time($at.'T12:00:00+09:00'), 2, 1, 7,
            $state, false, $state === State::NormalFinish ? 1 : null, '80.00', null, null, str_repeat('b', 64),
            new Time('2026-07-01T00:00:00+09:00'), new Time('2026-07-01T00:00:00+09:00'));
    }
}
