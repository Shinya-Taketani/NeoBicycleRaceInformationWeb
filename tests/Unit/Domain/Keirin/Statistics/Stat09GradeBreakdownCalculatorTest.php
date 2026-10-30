<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Statistics;

use App\Domain\Keirin\Statistics\Calculators\Stat09Calculator;
use App\Domain\Keirin\Statistics\Calculators\Stat09GradeBreakdownCalculator;
use App\Domain\Keirin\Statistics\DTO\Batch02BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch02TargetEntryDto;
use App\Domain\Keirin\Statistics\DTO\HistoricalRaceDto;
use App\Domain\Keirin\Statistics\DTO\Stat09GradeContextDto;
use App\Domain\Keirin\Statistics\Enums\HistoricalResultState as State;
use App\Domain\Keirin\Statistics\Support\AcquiredHistorySupport;
use App\Domain\Keirin\Statistics\Support\DeterministicJsonHasher;
use DateTimeImmutable as Time;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class Stat09GradeBreakdownCalculatorTest extends TestCase
{
    public function test_two_independent_axes_keep_legacy_counts_and_explicit_unknown_denominators(): void
    {
        $events = [$this->event(1, State::NormalFinish, 1), $this->event(2, State::NormalFinish, 2, tied: true),
            $this->event(3, State::Disqualified), $this->event(4, State::FallDnf), $this->event(5, State::OtherDnf),
            $this->event(6, State::DidNotStart), $this->event(7, State::Withdrawn), $this->event(8, State::NormalFinish),
            $this->event(9, State::UnknownAbnormal)];
        $grades = [];
        foreach ($events as $e) {
            $grades[$e->raceEntryId] = $this->grade($e, $e->raceId <= 3 ? 'S1' : ($e->raceId <= 6 ? 'A1' : 'UNKNOWN'),
                $e->raceId % 2 ? 'F1' : 'G3');
        }
        $old = $this->legacy()->calculate($this->target(), $events, $this->options());
        $new = $this->calculator()->calculate($this->target(), $events, $this->options(), [], $grades);
        $base = $new->features;
        unset($base['ENTRY_GRADE_BREAKDOWN'], $base['MEETING_GRADE_BREAKDOWN']);
        $this->assertSame($old->features, $base);
        $this->assertSame($old->status, $new->status);
        $this->assertSame($old->qualityStatus, $new->qualityStatus);
        $this->assertSame(7, $base['ACQUIRED_PERIOD']['started_count']);
        $this->assertSame(2, $base['ACQUIRED_PERIOD']['normal_rank_count']);
        $this->assertSame(0.5, $base['ACQUIRED_PERIOD']['win_rate_among_normal_finishes']);
        $s1 = $new->features['ENTRY_GRADE_BREAKDOWN']['S1'];
        $this->assertSame(3, $s1['observed_rows']);
        $this->assertSame(3, $s1['started_count']);
        $this->assertSame(2, $s1['normal_rank_count']);
        $this->assertSame(1, $s1['rank_1_count']);
        $this->assertSame(1, $s1['rank_2_count']);
        $this->assertNull($new->features['ENTRY_GRADE_BREAKDOWN']['UNKNOWN']['win_rate_among_normal_finishes']);
        foreach (['ENTRY_GRADE_BREAKDOWN', 'MEETING_GRADE_BREAKDOWN'] as $axis) {
            foreach (['observed_rows', 'started_count', 'normal_rank_count', 'rank_1_count', 'rank_2_count', 'rank_3_count', 'excluded_abnormal_or_missing_rank'] as $key) {
                $this->assertSame($base['ACQUIRED_PERIOD'][$key], array_sum(array_column($new->features[$axis], $key)));
            }
        }
        $this->assertSame(3, $new->evidence['grade_breakdown']['coverage']['entry_grade']['unknown_count']);
        $this->assertSame($new->inputHash, $this->calculator()->calculate($this->target(), array_reverse($events), $this->options(), [], array_reverse($grades, true))->inputHash);
        $this->assertSame('NOT_AUTHORIZED', $new->evidence['prediction_use']);
        $this->assertFalse($new->evidence['historical_as_of_available']);
    }

    public function test_no_history_keeps_empty_axes_and_existing_null_zero_contract(): void
    {
        $out = $this->calculator()->calculate($this->target(), [], $this->options(), [], []);
        $this->assertSame([], $out->features['ENTRY_GRADE_BREAKDOWN']);
        $this->assertSame([], $out->features['MEETING_GRADE_BREAKDOWN']);
        $this->assertSame(0, $out->features['ACQUIRED_PERIOD']['observed_rows']);
        $this->assertNull($out->features['ACQUIRED_PERIOD']['rank_1_count']);
        $this->assertSame('NO_HISTORY', $out->status->value);
        $this->assertNull($out->evidence['source_max_fetched_at']);
    }

    public function test_multiple_unknown_reasons_are_not_reported_as_distinct_history_rows(): void
    {
        $e = $this->event(1, State::NormalFinish, 1);
        $c = $this->grade($e, 'UNKNOWN', 'UNKNOWN');
        $c = new Stat09GradeContextDto(...array_replace(get_object_vars($c), ['reasons' => ['entry_grade' => ['ENTRY_GRADE_UNKNOWN_OR_UNSUPPORTED'],
            'meeting_grade' => ['MEETING_PERIOD_UNVERIFIED', 'MEETING_TRACK_MISMATCH_OR_MISSING']]]));
        $out = $this->calculator()->calculate($this->target(), [$e], $this->options(), [], [10 => $c]);
        $coverage = $out->evidence['grade_breakdown']['coverage']['meeting_grade'];
        $this->assertSame(1, $coverage['unknown_count']);
        $this->assertSame(2, array_sum($coverage['unknown_reason_counts']));
    }

    public function test_excluded_context_is_not_used_but_accepted_context_changes_hash_even_when_same_normalized_grade(): void
    {
        $e = $this->event(1, State::NormalFinish, 1);
        $future = $this->event(2, State::NormalFinish, 1, at: '2025-01-05T00:00:00+09:00');
        $before = $this->event(3, State::NormalFinish, 1, at: '2023-12-31T23:59:59+09:00');
        $self = new HistoricalRaceDto(...array_replace(get_object_vars($e), ['raceId' => 99, 'raceEntryId' => 990]));
        $c = $this->grade($e, 'S1', 'G1');
        $a = $this->calculator()->calculate($this->target(), [$future, $e, $before, $self], $this->options(), [], [10 => $c]);
        $g = new Stat09GradeContextDto(...array_replace(get_object_vars($c), ['raw' => ['entry_grade' => 'S級1班']]));
        $b = $this->calculator()->calculate($this->target(), [$future, $e, $before, $self], $this->options(), [], [10 => $g, 20 => 'unused-invalid-context']);
        $this->assertSame(1, $a->features['ACQUIRED_PERIOD']['observed_rows']);
        $this->assertSame($a->features, $b->features);
        $this->assertNotSame($a->inputHash, $b->inputHash);
        $changedFuture = $this->grade($future, 'A2', 'F2');
        $same = $this->calculator()->calculate($this->target(), [$self, $before, $e, $future], $this->options(), [], [10 => $c, 20 => $changedFuture]);
        $this->assertSame($a->inputHash, $same->inputHash);
    }

    #[DataProvider('sourceTimes')]
    public function test_new_source_times_use_instants_keep_original_and_include_latest_fetch(string $field): void
    {
        $e = $this->event(1, State::NormalFinish, 1);
        $c = $this->grade($e, 'S1', 'G1');
        $outputs = [];
        foreach (['2026-07-01T12:00:00.123456+09:00', '2026-07-01T03:00:00.123456Z'] as $at) {
            $g = new Stat09GradeContextDto(...array_replace(get_object_vars($c), ['sources' => array_replace($c->sources, [$field => $at])]));
            $outputs[] = $this->calculator()->calculate($this->target(), [$e], $this->options(), [], [10 => $g]);
            $this->assertSame($at, end($outputs)->evidence['grade_breakdown']['references'][0]['sources'][$field]);
        }
        $this->assertSame($outputs[0]->inputHash, $outputs[1]->inputHash);
        $this->assertSame($outputs[0]->features, $outputs[1]->features);
        $this->assertSame('2026-07-01T12:00:00.123456+09:00', $outputs[0]->evidence['source_max_fetched_at']);
        $g = new Stat09GradeContextDto(...array_replace(get_object_vars($c), ['sources' => array_replace($c->sources, [$field => '2026-07-01T03:00:00.123457Z'])]));
        $this->assertNotSame($outputs[0]->inputHash, $this->calculator()->calculate($this->target(), [$e], $this->options(), [], [10 => $g])->inputHash);
    }

    public static function sourceTimes(): array
    {
        return array_map(fn ($s) => [$s], ['entry_fetched_at', 'race_last_fetched_at', 'day_last_fetched_at', 'meeting_last_fetched_at']);
    }

    public function test_missing_null_and_empty_timestamps_are_distinct_and_invalid_is_rejected(): void
    {
        $e = $this->event(1, State::NormalFinish, 1);
        $c = $this->grade($e, 'A1', 'F1');
        $hashes = [];
        foreach ([[], ['entry_fetched_at' => null], ['entry_fetched_at' => '']] as $times) {
            $g = new Stat09GradeContextDto(...array_replace(get_object_vars($c), ['sources' => $times + $c->sources]));
            $hashes[] = $this->calculator()->calculate($this->target(), [$e], $this->options(), [], [10 => $g])->inputHash;
        }
        $this->assertCount(3, array_unique($hashes));
        $g = new Stat09GradeContextDto(...array_replace(get_object_vars($c), ['sources' => ['entry_fetched_at' => 'not-a-timestamp'] + $c->sources]));
        $this->expectException(UnexpectedValueException::class);
        $this->calculator()->calculate($this->target(), [$e], $this->options(), [], [10 => $g]);
    }

    #[DataProvider('badContexts')]
    public function test_context_mismatch_is_not_converted_to_unknown(array $changes): void
    {
        $e = $this->event(1, State::NormalFinish, 1);
        $c = $this->grade($e, 'A1', 'F1');
        $bad = new Stat09GradeContextDto(...array_replace(get_object_vars($c), $changes));
        $this->expectException(UnexpectedValueException::class);
        $this->calculator()->calculate($this->target(), [$e], $this->options(), [], [10 => $bad]);
    }

    public static function badContexts(): array
    {
        return [[['raceEntryId' => 999]], [['raceId' => 999]], [['playerId' => 999]], [['sources' => []]], [['entryGrade' => 'L1']]];
    }

    public function test_duplicate_history_and_other_player_are_rejected(): void
    {
        $e = $this->event(1, State::NormalFinish, 1);
        foreach ([[$e, $e], [new HistoricalRaceDto(...array_replace(get_object_vars($e), ['playerId' => 99]))]] as $bad) {
            try {
                $this->calculator()->calculate($this->target(), $bad, $this->options(), [], []);
                $this->fail('Invalid history accepted.');
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_jst_year_boundary_is_unchanged_in_the_new_profile(): void
    {
        $e = $this->event(1, State::NormalFinish, 1, at: '2024-12-31T23:40:00Z');
        $out = $this->calculator()->calculate($this->target(), [$e], $this->options(), [], [10 => $this->grade($e, 'A1', 'F1')]);
        $this->assertSame([2025], array_keys($out->features['YEARLY']));
    }

    private function legacy(): Stat09Calculator
    {
        return new Stat09Calculator(new AcquiredHistorySupport(new DeterministicJsonHasher));
    }

    private function calculator(): Stat09GradeBreakdownCalculator
    {
        $hash = new DeterministicJsonHasher;

        return new Stat09GradeBreakdownCalculator($this->legacy(), new AcquiredHistorySupport($hash), $hash);
    }

    private function target(): Batch02TargetEntryDto
    {
        return new Batch02TargetEntryDto(99, 999, 7, 1, new Time('2025-01-04T00:00:00+09:00'), new Time('2025-01-04T12:00:00+09:00'), str_repeat('a', 64), 1);
    }

    private function options(): Batch02BuildOptionsDto
    {
        return new Batch02BuildOptionsDto(1, new Time('2024-01-01T00:00:00+09:00'), null, null, 99, 1, false);
    }

    private function event(int $id, State $state, ?int $rank = null, bool $tied = false, ?string $at = null): HistoricalRaceDto
    {
        return new HistoricalRaceDto(7, $id, $id * 10, new Time($at ?? '2024-06-'.sprintf('%02d', $id).'T12:00:00+09:00'),
            2, 1, 7, $state, $tied, $rank, '90.00', null, 0.2, str_repeat('b', 64), new Time('2024-01-01T00:00:00+09:00'), new Time('2024-01-01T00:00:00+09:00'));
    }

    private function grade(HistoricalRaceDto $e, string $entry, string $meeting): Stat09GradeContextDto
    {
        // Synthetic arithmetic metadata only. Actual acceptance uses the sealed PR128 SQLite.
        return new Stat09GradeContextDto($e->raceEntryId, $e->raceId, $e->playerId, $entry, $meeting, ['entry_grade' => $entry, 'meeting_grade' => $meeting],
            ['projection_contract' => 'STAT09-SAVED-HISTORY-GRADES-v1', 'normalization_contract' => 'STAT09-HISTORY-GRADE-NORMALIZATION-v1',
                'entry_id' => $e->raceEntryId, 'race_id' => $e->raceId, 'player_id' => $e->playerId],
            ['entry_grade' => $entry === 'UNKNOWN' ? ['ENTRY_GRADE_UNKNOWN_OR_UNSUPPORTED'] : [],
                'meeting_grade' => $meeting === 'UNKNOWN' ? ['MEETING_GRADE_UNKNOWN_OR_MISSING'] : []]);
    }
}
