<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Statistics;

use App\Domain\Keirin\Statistics\Calculators\Stat09Calculator;
use App\Domain\Keirin\Statistics\Calculators\Stat44Calculator;
use App\Domain\Keirin\Statistics\DTO\Batch02BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch02TargetEntryDto;
use App\Domain\Keirin\Statistics\DTO\HistoricalRaceDto;
use App\Domain\Keirin\Statistics\Enums\HistoricalResultState as State;
use App\Domain\Keirin\Statistics\Support\AcquiredHistorySupport;
use App\Domain\Keirin\Statistics\Support\DeterministicJsonHasher;
use DateTimeImmutable as Time;
use PHPUnit\Framework\TestCase;

final class AcquiredCareerCalculatorsTest extends TestCase
{
    public function test_acquired_career_is_not_lifetime_and_does_not_count_abnormal_as_normal_rank(): void
    {
        $calculator = new Stat09Calculator(new AcquiredHistorySupport(new DeterministicJsonHasher));
        $out = $calculator->calculate($this->target(), [$this->event(1, '2024-02-01', '90.00', 1),
            $this->event(2, '2024-06-01', '95.00', null)], $this->options());
        $this->assertSame('PARTIAL_CAREER', $out->features['career_completeness']);
        $this->assertSame(2, $out->features['ACQUIRED_PERIOD']['started_count']);
        $this->assertSame(1, $out->features['ACQUIRED_PERIOD']['excluded_abnormal_or_missing_rank']);
        $this->assertSame(1, $out->features['YEARLY'][2024]['rank_1_count']);
        $this->assertNull($out->features['lifetime_total']);
        $this->assertNull($out->features['lifetime_championships']);
    }

    public function test_longitudinal_profile_excludes_future_peak_and_keeps_missing_career_age(): void
    {
        $calculator = new Stat44Calculator(new AcquiredHistorySupport(new DeterministicJsonHasher));
        $events = [$this->event(1, '2024-02-01', '90.00', 1), $this->event(2, '2024-05-01', '85.00', 3),
            $this->event(3, '2025-02-01', '150.00', 1)];
        $out = $calculator->calculate($this->target(), $events, $this->options(), new Time('1990-01-01'));
        $this->assertSame(35, $out->features['age_years']);
        $this->assertSame(90.0, $out->features['past_observed_peak_score']);
        $this->assertSame(-5.0, $out->features['last_score_minus_past_peak']);
        $this->assertSame(85.0, $out->features['QUARTERLY_ACQUIRED_HISTORY']['2024-Q2']['mean_score']);
        $this->assertNull($out->features['career_age_years']);
        $this->assertNull($out->features['career_phase']);
        $this->assertSame($out->inputHash, $calculator->calculate($this->target(), array_reverse($events), $this->options(), new Time('1990-01-01'))->inputHash);
    }

    public function test_no_history_is_not_a_zero_career_or_zero_score(): void
    {
        $support = new AcquiredHistorySupport(new DeterministicJsonHasher);
        $a = (new Stat09Calculator($support))->calculate($this->target(), [], $this->options());
        $b = (new Stat44Calculator($support))->calculate($this->target(), [], $this->options(), null);
        $this->assertNull($a->features['ACQUIRED_PERIOD']['rank_1_count']);
        $this->assertNull($a->features['ACQUIRED_PERIOD']['win_rate_among_normal_finishes']);
        $this->assertNull($b->features['past_observed_peak_score']);
        $this->assertNull($b->features['age_years']);
    }

    public function test_verified_debut_is_separate_from_birth_and_changes_the_input_seal(): void
    {
        $calculator = new Stat44Calculator(new AcquiredHistorySupport(new DeterministicJsonHasher));
        $without = $calculator->calculate($this->target(), [], $this->options(), new Time('1990-01-01'));
        $with = $calculator->calculate($this->target(), [], $this->options(), new Time('1990-01-01'),
            new Time('2013-07-02'), ['fetch_log_id' => 1, 'sha256' => str_repeat('a', 64)]);
        $this->assertSame(11, $with->features['career_age_years']);
        $this->assertSame('2013-07-02', $with->features['debut_date']);
        $this->assertGreaterThan(4000, $with->features['days_since_debut']);
        $this->assertNotSame($with->inputHash, $without->inputHash);
    }

    private function target(): Batch02TargetEntryDto
    {
        return new Batch02TargetEntryDto(99, 999, 7, 1, new Time('2025-01-04'), new Time('2025-01-04'), str_repeat('a', 64), 1);
    }

    private function options(): Batch02BuildOptionsDto
    {
        return new Batch02BuildOptionsDto(1, new Time('2024-01-01'), null, null, 99, 1, false);
    }

    private function event(int $id, string $at, string $score, ?int $rank): HistoricalRaceDto
    {
        // Synthetic arithmetic boundaries only; real acceptance uses separately sealed DB snapshots.
        return new HistoricalRaceDto(7, $id, $id * 10, new Time($at), 2, 1, 7,
            $rank === null ? State::Disqualified : State::NormalFinish, false, $rank, $score, null, 0.2, str_repeat('b', 64), new Time($at), new Time($at));
    }
}
