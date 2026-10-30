<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Statistics;

use App\Domain\Keirin\Statistics\Calculators\SimilarConditionsCalculator;
use App\Domain\Keirin\Statistics\Calculators\Stat09Calculator;
use App\Domain\Keirin\Statistics\Calculators\Stat25Calculator;
use App\Domain\Keirin\Statistics\Calculators\Stat44Calculator;
use App\Domain\Keirin\Statistics\DTO\Batch02BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch02TargetEntryDto;
use App\Domain\Keirin\Statistics\DTO\HistoricalRaceDto;
use App\Domain\Keirin\Statistics\DTO\SimilarityConditionsDto;
use App\Domain\Keirin\Statistics\Enums\HistoricalResultState as State;
use App\Domain\Keirin\Statistics\Support\AcquiredHistorySupport;
use App\Domain\Keirin\Statistics\Support\Batch02CalculatorSupport;
use App\Domain\Keirin\Statistics\Support\DeterministicJsonHasher;
use App\Domain\Keirin\Statistics\Support\SixConditionSimilarity;
use DateTimeImmutable as Time;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AcquiredHistoryCalendarTest extends TestCase
{
    public function test_year_boundary_groups_equivalent_instants_in_jst_2025(): void
    {
        $results = [];
        foreach (['2024-12-31T23:40:00+00:00', '2025-01-01T08:40:00+09:00'] as $at) {
            $history = [$this->event(1, $at)];
            $support = new AcquiredHistorySupport(new DeterministicJsonHasher);
            $results[] = [
                (new Stat09Calculator($support))->calculate($this->target(), $history, $this->options()),
                (new Stat44Calculator($support))->calculate($this->target(), $history, $this->options(), null),
            ];
        }
        $this->record('year-boundary', ['expected_year' => 2025, 'expected_quarter' => '2025-Q1', 'actual' => $results]);
        foreach ($results as [$career, $longitudinal]) {
            $this->assertSame([2025], array_keys($career->features['YEARLY']));
            $this->assertSame(['2025-Q1'], array_keys($longitudinal->features['QUARTERLY_ACQUIRED_HISTORY']));
            $this->assertSame(90.0, $longitudinal->features['QUARTERLY_ACQUIRED_HISTORY']['2025-Q1']['mean_score']);
        }
        foreach ([0, 1] as $stat) {
            $this->assertSame($results[0][$stat]->features, $results[1][$stat]->features);
            $this->assertSame($results[0][$stat]->inputHash, $results[1][$stat]->inputHash);
            $this->assertSame($results[0][$stat]->status, $results[1][$stat]->status);
        }
    }

    public function test_quarter_boundary_preserves_means_counts_and_hashes(): void
    {
        $calculator = new Stat44Calculator(new AcquiredHistorySupport(new DeterministicJsonHasher));
        $results = [];
        foreach (['2024-03-31T23:40:00+00:00', '2024-04-01T08:40:00+09:00'] as $at) {
            $results[] = $calculator->calculate($this->target(), [$this->event(1, $at, '80.00'),
                $this->event(2, '2024-04-02T08:40:00+09:00', '100.00')], $this->options(), null);
        }
        $this->record('quarter-boundary', ['expected_quarter' => '2024-Q2', 'expected_mean_score' => 90.0, 'actual' => $results]);
        foreach ($results as $result) {
            $this->assertSame(['2024-Q2'], array_keys($result->features['QUARTERLY_ACQUIRED_HISTORY']));
            $quarter = $result->features['QUARTERLY_ACQUIRED_HISTORY']['2024-Q2'];
            $this->assertSame(2, $quarter['observed_rows']);
            $this->assertSame(2, $quarter['score_count']);
            $this->assertSame(2, $quarter['residual_count']);
            $this->assertSame(90.0, $quarter['mean_score']);
            $this->assertSame(0.2, $quarter['mean_score_expectation_residual']);
        }
        $this->assertSame($results[0]->features, $results[1]->features);
        $this->assertSame($results[0]->inputHash, $results[1]->inputHash);
    }

    public function test_jst_2022_boundary_is_not_rejected_as_utc_2021(): void
    {
        $calculator = new Stat09Calculator(new AcquiredHistorySupport(new DeterministicJsonHasher));
        $results = [];
        foreach (['2021-12-31T23:40:00+00:00', '2022-01-01T08:40:00+09:00'] as $at) {
            try {
                $result = $calculator->calculate($this->target($at), [], $this->options('2021-12-31T15:00:00+00:00'));
                $results[] = ['status' => $result->status->value, 'hash' => $result->inputHash];
            } catch (InvalidArgumentException $error) {
                $results[] = ['error' => $error->getMessage()];
            }
        }
        $this->record('development-start', ['expected' => 'NO_HISTORY', 'actual' => $results]);
        $this->assertSame('NO_HISTORY', $results[0]['status'] ?? null);
        $this->assertSame($results[0], $results[1]);
    }

    public function test_jst_2026_boundary_is_rejected_even_when_utc_is_2025(): void
    {
        $rejected = [];
        foreach (['2025-12-31T23:40:00+00:00', '2026-01-01T08:40:00+09:00'] as $at) {
            try {
                (new Stat09Calculator(new AcquiredHistorySupport(new DeterministicJsonHasher)))->calculate($this->target($at), [], $this->options());
                $rejected[] = false;
            } catch (InvalidArgumentException) {
                $rejected[] = true;
            }
        }
        $this->record('development-end', ['artificial_datetimes_only' => true, 'expected' => [true, true], 'actual' => $rejected]);
        $this->assertSame([true, true], $rejected);
    }

    public function test_calendar_year_subtraction_occurs_after_jst_conversion(): void
    {
        $hash = new DeterministicJsonHasher;
        $calculator = new Stat25Calculator(new Batch02CalculatorSupport($hash), $hash);
        $events = [$this->event(1, '2024-03-01T08:39:59+09:00', state: State::Disqualified),
            $this->event(2, '2024-03-01T08:40:00+09:00', state: State::Disqualified)];
        $results = [];
        foreach (['2025-02-28T23:40:00+00:00', '2025-03-01T08:40:00+09:00'] as $at) {
            $results[] = $calculator->calculate($this->target($at), $events, $this->options());
        }
        $this->record('past-calendar-year', ['expected_start' => '2024-03-01T08:40:00+09:00', 'expected_disqualifications' => 1, 'actual' => $results]);
        foreach ($results as $result) {
            $this->assertSame('2024-03-01T08:40:00+09:00', $result->features['PAST_YEAR']['window_start_at']);
            $this->assertSame(1, $result->features['PAST_YEAR']['disqualification_count']);
            $this->assertSame(1, $result->features['PAST_YEAR']['started_race_count']);
            $this->assertSame(2, $result->features['ACQUIRED_HISTORY']['disqualification_count']);
        }
        $this->assertSame($results[0]->features, $results[1]->features);
        $this->assertSame($results[0]->inputHash, $results[1]->inputHash);
    }

    public function test_leap_day_keeps_the_existing_calendar_rollover_policy(): void
    {
        $hash = new DeterministicJsonHasher;
        $calculator = new Stat25Calculator(new Batch02CalculatorSupport($hash), $hash);
        foreach (['2024-02-28T23:40:00+00:00', '2024-02-29T08:40:00+09:00'] as $at) {
            $result = $calculator->calculate($this->target($at), [], $this->options('2022-01-01T00:00:00+09:00'));
            $this->assertSame('2023-03-01T08:40:00+09:00', $result->features['PAST_YEAR']['window_start_at']);
        }
    }

    public function test_equivalent_instants_and_scope_offsets_keep_both_similarity_outputs_identical(): void
    {
        $hash = new DeterministicJsonHasher;
        $calculator = new SimilarConditionsCalculator(new AcquiredHistorySupport($hash), new SixConditionSimilarity, $hash);
        $results = [];
        foreach ([false, true] as $utc) {
            $zone = new DateTimeZone($utc ? 'UTC' : 'Asia/Tokyo');
            $target = $this->target((new Time('2025-01-04T00:00:00+09:00'))->setTimezone($zone)->format(DATE_ATOM));
            $event = $this->event(1, (new Time('2024-04-01T08:40:00+09:00'))->setTimezone($zone)->format(DATE_ATOM));
            $conditions = [];
            foreach ([999 => [99, 1], 10 => [1, 2]] as $entry => [$race, $meeting]) {
                $conditions[$entry] = new SimilarityConditionsDto(['field_size' => 7, 'race_stage' => 'FINAL', 'meeting_grade' => 'G3',
                    'entry_grade' => 'S1', 'day_number' => 1, 'start_minute_jst' => 520], [],
                    ['entry_id' => $entry, 'race_id' => $race, 'meeting_id' => $meeting, 'meeting_start' => '2025-01-04'], []);
            }
            $results[] = $calculator->calculate($target, [$event], $this->options((new Time('2024-01-01T00:00:00+09:00'))->setTimezone($zone)->format(DATE_ATOM)), $conditions);
        }
        foreach (['STAT-16', 'STAT-27'] as $stat) {
            $this->assertSame($results[0][$stat]->features, $results[1][$stat]->features);
            $this->assertSame($results[0][$stat]->inputHash, $results[1][$stat]->inputHash);
        }
        $this->assertSame('SIX-CONDITIONS-EQUAL-WEIGHT-NO-DECAY-v1', SixConditionSimilarity::VERSION);
    }

    private function target(string $at = '2025-01-04T00:00:00+09:00'): Batch02TargetEntryDto
    {
        return new Batch02TargetEntryDto(99, 999, 7, 1, new Time($at), new Time($at), str_repeat('a', 64), 1);
    }

    private function options(string $from = '2024-01-01T00:00:00+09:00'): Batch02BuildOptionsDto
    {
        return new Batch02BuildOptionsDto(1, new Time($from), null, null, 99, 1, false);
    }

    private function event(int $id, string $at, string $score = '90.00', State $state = State::NormalFinish): HistoricalRaceDto
    {
        // Artificial calendar boundaries only; real acceptance reuses the sealed SQLite snapshot.
        return new HistoricalRaceDto(7, $id, $id * 10, new Time($at), 2, 1, 7, $state, false,
            $state === State::NormalFinish ? 1 : null, $score, null, 0.2, str_repeat('b', 64), new Time($at), new Time($at));
    }

    private function record(string $name, array $data): void
    {
        $directory = getenv('PR127_TEST_REPORT_DIRECTORY');
        if ($directory === false) {
            return;
        }
        $file = fopen($directory.'/calendar-'.$name.'.json', 'xb');
        $text = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
        if ($file === false || fwrite($file, $text) !== strlen($text)) {
            throw new RuntimeException('Could not retain calendar regression evidence.');
        }
        fclose($file);
    }
}
