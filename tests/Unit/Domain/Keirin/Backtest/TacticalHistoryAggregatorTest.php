<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\HistoryAggregator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TacticalHistoryAggregatorTest extends TestCase
{
    public function test_window_is_jst_inclusive_start_exclusive_end_and_omits_same_meeting(): void
    {
        $target = $this->target();
        $rows = [$this->row(), $this->row(['race_id' => 2, 'race_date' => '2024-02-10', 'scheduled_start_at' => '2024-02-10T14:59:59Z']),
            $this->row(['race_id' => 3, 'race_date' => '2024-06-10', 'scheduled_start_at' => '2024-06-09T15:00:00Z']),
            $this->row(['race_id' => 4, 'meeting_id' => 100]), $this->row(['race_id' => 100])];
        $result = (new HistoryAggregator)->aggregate($target, $rows);
        self::assertSame([1, 0, 0, 0], $result['values']);
        self::assertSame('2024-02-11T00:00:00+09:00', $result['window_start']);
        self::assertSame('UNKNOWN', $result['publication_time_verified']);
    }

    public function test_all_four_methods_top_two_and_ties_count_once(): void
    {
        $rows = [];
        foreach (["\u{9003}\u{3052}", "\u{6372}\u{308a}", "\u{5dee}\u{3057}", "\u{30de}\u{30fc}\u{30af}"] as $i => $method) {
            $rows[] = $this->row(['race_id' => $i + 1, 'winning_technique' => $method, 'rank' => 2, 'result_status' => 'TIED']);
        }
        $rows[] = $rows[0];
        self::assertSame([1, 1, 1, 1], (new HistoryAggregator)->aggregate($this->target(), $rows)['values']);
    }

    #[DataProvider('missingCases')]
    public function test_missing_and_invalid_are_not_observed_zero(array $changes, string $status): void
    {
        $result = (new HistoryAggregator)->aggregate($this->target(), [$this->row($changes)]);
        self::assertSame($status, $result['status']);
        self::assertSame([null, null, null, null], $result['values']);
    }

    public static function missingCases(): iterable
    {
        yield 'unknown method' => [['winning_technique' => 'unknown'], 'MISSING_METHOD_HISTORY'];
        yield 'null method' => [['winning_technique' => null], 'MISSING_METHOD_HISTORY'];
        yield 'mark first' => [['winning_technique' => "\u{30de}\u{30fc}\u{30af}"], 'INVALID_HISTORY'];
        yield 'missing result' => [['result_id' => null], 'PARTIAL_HISTORY'];
        yield 'no time' => [['scheduled_start_at' => null], 'PARTIAL_HISTORY'];
        yield 'later known correction' => [['correction_effective_at' => '2024-06-11T00:00:00+09:00'], 'PARTIAL_HISTORY'];
    }

    public function test_zero_no_history_and_left_truncation_are_distinct(): void
    {
        $aggregator = new HistoryAggregator;
        $zero = $aggregator->aggregate($this->target(), [$this->row(['rank' => 3, 'winning_technique' => null])]);
        self::assertSame('AVAILABLE', $zero['status']);
        self::assertSame([0, 0, 0, 0], $zero['values']);
        self::assertSame('NO_HISTORY', $aggregator->aggregate($this->target(), [])['status']);
        self::assertSame('LEFT_TRUNCATED', $aggregator->aggregate(array_replace($this->target(), ['race_date' => '2022-01-01', 'meeting_start' => '2022-01-01']), [])['status']);
        self::assertSame('NO_HISTORY', $aggregator->aggregate($this->target(), [$this->row(['race_status' => 'CANCELLED'])])['status']);
    }

    public function test_future_same_meeting_other_players_and_categories_cannot_change_target(): void
    {
        $aggregator = new HistoryAggregator;
        $base = [$this->row()];
        $changed = [...$base, $this->row(['race_id' => 2, 'race_date' => '2026-01-01']), $this->row(['race_id' => 3, 'male_category' => false]),
            $this->row(['race_id' => 4, 'player_id' => 2]), $this->row(['race_id' => 5, 'meeting_id' => 100, 'winning_technique' => 'bad'])];
        self::assertSame($aggregator->aggregate($this->target(), $base), $aggregator->aggregate($this->target(), $changed));
        self::assertNotSame($aggregator->aggregate($this->target(), $base)['values'], $aggregator->aggregate($this->target(), [$this->row(['winning_technique' => "\u{6372}\u{308a}"])])['values']);
    }

    public function test_null_result_player_uses_entry_but_disagreement_fails_closed(): void
    {
        self::assertSame([1, 0, 0, 0], (new HistoryAggregator)->aggregate($this->target(), [$this->row()])['values']);
        $this->expectException(RuntimeException::class);
        (new HistoryAggregator)->aggregate($this->target(), [$this->row(['result_player_id' => 999])]);
    }

    public function test_conflicting_duplicates_fail_closed(): void
    {
        $this->expectException(RuntimeException::class);
        (new HistoryAggregator)->aggregate($this->target(), [$this->row(), $this->row(['rank' => 2])]);
    }

    private function target(): array
    {
        return ['race_id' => 100, 'meeting_id' => 100, 'player_id' => 1, 'race_date' => '2024-06-10', 'meeting_start' => '2024-06-10', 'meeting_end' => '2024-06-12', 'input_as_of' => '2024-06-10T10:00:00+09:00'];
    }

    private function row(array $changes = []): array
    {
        return array_replace(['race_id' => 1, 'meeting_id' => 1, 'player_id' => 1, 'entry_id' => 1, 'bike' => 1, 'male_category' => true,
            'race_date' => '2024-02-11', 'scheduled_start_at' => '2024-02-10T15:00:00Z', 'result_player_id' => null, 'result_entry_id' => null,
            'race_status' => 'CONFIRMED', 'result_id' => 1, 'result_status' => 'FINISHED', 'rank' => 1, 'winning_technique' => "\u{9003}\u{3052}"], $changes);
    }
}
