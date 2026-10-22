<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Statistics;

use App\Domain\Keirin\Statistics\Calculators\Stat19OfficialMethodHistoryCalculator;
use App\Domain\Keirin\Statistics\Calculators\Stat30OfficialMethodMixCalculator;
use App\Domain\Keirin\Statistics\DTO\OfficialMethodHistoryInputDto;
use App\Domain\Keirin\Statistics\DTO\OfficialMethodHistoryProfileDto;
use App\Domain\Keirin\Statistics\OfficialMethodHistory\Contract;
use App\Domain\Keirin\Statistics\OfficialMethodHistory\WindowBuilder;
use App\Domain\Keirin\Statistics\Support\DeterministicJsonHasher;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Artificial boundary/arithmetic fixtures only; not evidence of real race outcomes. */
final class OfficialMethodHistoryTest extends TestCase
{
    public function test_n_windows_and_non_overlapping_five_and_preceding_fifteen(): void
    {
        $rows = $this->rows(25);
        $profile = $this->build($rows);
        foreach ([3, 5, 10, 20] as $n) {
            $window = $profile->windows['LAST_'.$n];
            $this->assertSame('AVAILABLE', $window['status']);
            $this->assertSame($n, $window['verified_started_count']);
            $this->assertSame($n, $window['method_observed_count']);
            $this->assertSame(array_column(array_slice($rows, 0, $n), 'race_id'), $window['race_ids']);
        }
        $this->assertSame(array_column(array_slice($rows, 0, 5), 'race_id'), $profile->recent['race_ids']);
        $this->assertSame(array_column(array_slice($rows, 5, 15), 'race_id'), $profile->baseline['race_ids']);
        $this->assertSame([], array_intersect($profile->recent['race_ids'], $profile->baseline['race_ids']));
    }

    public function test_each_day_window_includes_lower_boundary_and_excludes_meeting_start(): void
    {
        foreach ([30, 60, 90, 180] as $days) {
            $rows = $this->rows(2);
            $boundary = new DateTimeImmutable('2025-01-01T00:00:00+09:00')->modify('-'.$days.' days');
            $rows[0]['race_date'] = $boundary->format('Y-m-d');
            $rows[0]['scheduled_start_at'] = $boundary->format(DATE_ATOM);
            $rows[1]['race_date'] = $boundary->modify('-1 day')->format('Y-m-d');
            $rows[1]['scheduled_start_at'] = $boundary->modify('-1 second')->format(DATE_ATOM);
            $profile = $this->build($rows);
            $this->assertSame(1, $profile->windows['DAYS_'.$days]['verified_started_count']);
            $this->assertSame(['ESCAPE' => 1, 'MAKURI' => 0, 'SASHI' => 0, 'MARK' => 0], $profile->windows['DAYS_'.$days]['method_counts']);
        }
    }

    public function test_insufficient_missing_result_and_missing_top2_do_not_fill_from_older_rows(): void
    {
        $profile = $this->build($this->rows(2));
        $this->assertContains('INSUFFICIENT_SAMPLE', $profile->windows['LAST_3']['reasons']);
        $this->assertNull($profile->windows['LAST_3']['method_counts']);
        $rows = $this->rows(25);
        $rows[1]['evidence_verified'] = false;
        $rows[1]['participation'] = 'UNKNOWN';
        $rows[1]['method'] = null;
        $rows[1]['issues'] = ['RESULT_EVIDENCE_MISSING'];
        $profile = $this->build($rows);
        $this->assertSame(2, $profile->windows['LAST_3']['verified_started_count']);
        $this->assertSame(array_column(array_slice($rows, 0, 3), 'race_id'), $profile->windows['LAST_3']['race_ids']);
        $this->assertNull($profile->windows['LAST_3']['method_counts']);
        $this->assertContains('RESULT_EVIDENCE_MISSING', $profile->windows['LAST_3']['reasons']);
        $rows = $this->rows(20);
        $rows[0]['method'] = null;
        $rows[0]['method_missing'] = true;
        $profile = $this->build($rows);
        $this->assertContains('MISSING_TOP2_METHOD', $profile->recent['reasons']);
        $this->assertNull($this->stat30($profile)['RETROSPECTIVE']['l1_distance']);
    }

    public function test_observed_zero_null_no_history_and_zero_composition_are_separate(): void
    {
        $rows = $this->rows(20);
        foreach ($rows as &$row) {
            $row['method'] = null; // Artificial verified third-or-lower finisher.
        }
        $profile = $this->build($rows);
        $this->assertSame(array_fill_keys(array_values(Contract::METHODS), 0), $profile->windows['LAST_20']['method_counts']);
        $this->assertSame(20, $profile->windows['LAST_20']['verified_started_count']);
        $this->assertNull($profile->recent['composition']);
        $this->assertSame('ZERO_METHOD_OBSERVATIONS', $profile->recent['composition_status']);
        $this->assertNull($this->stat30($profile)['RETROSPECTIVE']['l1_distance']);
        $empty = $this->build([]);
        $this->assertNull($empty->windows['LAST_3']['method_counts']);
        $this->assertContains('NO_HISTORY', $empty->windows['LAST_3']['reasons']);
    }

    public function test_l1_has_independently_computable_zero_two_and_fractional_values(): void
    {
        foreach (['ESCAPE' => 0.0, 'MARK' => 2.0] as $baseline => $expected) {
            $rows = $this->rows(20);
            foreach ($rows as $i => &$row) {
                $row['method'] = $i < 5 ? 'ESCAPE' : $baseline;
            }
            unset($row);
            $this->assertSame($expected, $this->stat30($this->build($rows))['RETROSPECTIVE']['l1_distance']);
        }
        $rows = $this->rows(20);
        foreach ($rows as $i => &$row) {
            $row['method'] = $i < 2 || ($i >= 5 && $i < 14) ? 'ESCAPE' : 'SASHI';
        }
        $this->assertEqualsWithDelta(0.4, $this->stat30($this->build($rows))['RETROSPECTIVE']['l1_distance'], 1e-12);
    }

    public function test_nonstarts_and_cancellation_with_null_time_do_not_poison_valid_starts(): void
    {
        $rows = $this->rows(6);
        foreach ([0 => 'CANCELLED', 1 => 'NON_START', 2 => 'NON_START'] as $index => $state) {
            $rows[$index]['participation'] = $state;
            $rows[$index]['method'] = null;
        }
        $rows[0]['scheduled_start_at'] = null;
        $profile = $this->build($rows);
        $this->assertSame(1, $profile->audit['cancelled']);
        $this->assertSame(2, $profile->audit['nonstarts']);
        $this->assertSame(3, $profile->windows['LAST_3']['verified_started_count']);
        $this->assertSame('AVAILABLE', $profile->windows['LAST_3']['status']);
        $this->assertSame(array_column(array_slice($rows, 3), 'race_id'), $profile->windows['LAST_3']['race_ids']);
    }

    public function test_contract_classifies_ties_abnormal_results_and_unknowns_without_action_inference(): void
    {
        foreach (['FINISHED', 'TIED'] as $status) {
            $this->assertSame('ESCAPE', Contract::outcome(1, $status, '逃げ')['method']);
            $this->assertSame('MARK', Contract::outcome(2, $status, 'マーク')['method']);
            $this->assertFalse(Contract::outcome(3, $status, null)['method_missing']);
            $this->assertTrue(Contract::outcome(1, $status, null)['method_missing']);
            $this->assertTrue(Contract::outcome(2, $status, '未確認')['method_missing']);
        }
        foreach (['DISQUALIFIED', 'CRASHED', 'DID_NOT_FINISH'] as $status) {
            $this->assertSame('STARTED', Contract::outcome(null, $status, null)['participation']);
        }
        foreach (['DID_NOT_START', 'WITHDRAWN'] as $status) {
            $this->assertSame('NON_START', Contract::outcome(null, $status, null)['participation']);
        }
        $this->assertSame('UNKNOWN', Contract::outcome(null, 'UNKNOWN', null)['participation']);
        foreach ([[1, 'FINISHED', 'マーク'], [3, 'FINISHED', '逃げ'], [1, 'DISQUALIFIED', null], [null, 'FINISHED', null]] as $bad) {
            try {
                Contract::outcome(...$bad);
                $this->fail('Contradictory official method accepted.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('Contradictory', $error->getMessage());
            }
        }
    }

    public function test_same_meeting_target_and_future_rows_never_enter_history(): void
    {
        $rows = $this->rows(4);
        $rows[0]['race_id'] = 999;
        $rows[1]['meeting_id'] = 999;
        $rows[2]['race_date'] = '2025-01-02';
        $rows[2]['scheduled_start_at'] = '2025-01-02T12:00:00+09:00';
        $profile = $this->build($rows);
        $this->assertSame(1, $profile->audit['history_rows']);
        $this->assertSame([$rows[3]['race_id']], $profile->windows['LAST_3']['race_ids']);
    }

    public function test_left_truncation_and_unknown_meeting_time_remain_explicit(): void
    {
        $row = $this->rows(1)[0];
        $row['race_date'] = '2022-01-01';
        $row['scheduled_start_at'] = null;
        $row['meeting_id'] = null;
        $profile = $this->build([$row], '2022-01-02');
        $this->assertContains('LEFT_TRUNCATED', $profile->windows['DAYS_180']['reasons']);
        $this->assertContains('SCHEDULED_TIME_UNKNOWN', $profile->windows['LAST_3']['reasons']);
        $this->assertContains('MEETING_ID_UNKNOWN', $profile->windows['LAST_3']['reasons']);
        $this->assertNull($profile->windows['DAYS_180']['method_counts']);
    }

    public function test_post_acquired_corrected_results_remain_retrospective_and_unapproved(): void
    {
        $rows = $this->rows(20);
        $rows[0]['race_status'] = 'CORRECTED';
        $profile = $this->build($rows);
        $this->assertSame(20, $profile->audit['fetched_at_or_after_input']);
        $this->assertSame(1, $profile->audit['corrected_results']);
        $this->assertSame('UNKNOWN', $profile->audit['correction_effective_time_status']);
        $this->assertFalse($profile->audit['historical_as_of_available']);
        $this->assertNull($profile->audit['official_publication_at']);
        $a = new Stat19OfficialMethodHistoryCalculator(new DeterministicJsonHasher)->calculate($profile);
        $this->assertNull($a->features['recent_aggressiveness']);
        foreach ([$a->features, $this->stat30($profile)] as $features) {
            $this->assertNull($features['points']);
            $this->assertNull($features['confidence']);
            $this->assertSame('NOT_AUTHORIZED', $features['prediction_use']);
        }
    }

    public function test_duplicate_unordered_foreign_player_and_forbidden_year_are_rejected(): void
    {
        $rows = $this->rows(3);
        $duplicate = $rows;
        $duplicate[1] = $duplicate[0];
        $foreign = $rows;
        $foreign[0]['external_player_id'] = '999998';
        $forbidden = $rows;
        $forbidden[0]['race_date'] = '2026-01-01';
        foreach ([$duplicate, array_reverse($rows), $foreign, $forbidden] as $bad) {
            try {
                $this->build($bad);
                $this->fail('Invalid artificial boundary accepted.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_streamed_history_retains_only_twenty_references(): void
    {
        $rows = (function () {
            for ($i = 1; $i <= 5000; $i++) {
                $row = $this->rows(1)[0];
                $date = new DateTimeImmutable('2025-01-01T12:00:00+09:00')->modify('-'.(intdiv($i - 1, 5) + 1).' days');
                $row['race_id'] = $row['entry_id'] = 100000 - $i;
                $row['race_date'] = $date->format('Y-m-d');
                $row['scheduled_start_at'] = $date->format(DATE_ATOM);
                yield $row;
            }
        })();
        $profile = new WindowBuilder(new DeterministicJsonHasher)->build(new OfficialMethodHistoryInputDto(
            ['race_id' => 999, 'race_date' => '2025-01-01', 'meeting_id' => 999, 'meeting_start' => '2025-01-01', 'meeting_end' => '2025-01-01'],
            ['entry_id' => 999, 'player_id' => 1, 'external_player_id' => '999999', 'bike' => 1],
            new DateTimeImmutable('2025-01-01T00:00:00+09:00'), $rows));
        $this->assertSame(5000, $profile->audit['history_rows']);
        $this->assertCount(20, $profile->audit['references']);
        $this->assertSame(20, $profile->windows['LAST_20']['verified_started_count']);
    }

    private function build(array $rows, string $start = '2025-01-01'): OfficialMethodHistoryProfileDto
    {
        return new WindowBuilder(new DeterministicJsonHasher)->build(new OfficialMethodHistoryInputDto(
            ['race_id' => 999, 'race_date' => $start, 'meeting_id' => 999, 'meeting_start' => $start, 'meeting_end' => $start],
            ['entry_id' => 999, 'player_id' => 1, 'external_player_id' => '999999', 'bike' => 1],
            new DateTimeImmutable($start.'T00:00:00+09:00'), $rows));
    }

    private function stat30($profile): array
    {
        return new Stat30OfficialMethodMixCalculator(new DeterministicJsonHasher)->calculate($profile)->features;
    }

    private function rows(int $n): array
    {
        $rows = [];
        for ($i = 1; $i <= $n; $i++) {
            $date = new DateTimeImmutable('2025-01-01T12:00:00+09:00')->modify('-'.$i.' days');
            $rows[] = ['race_id' => 100 - $i, 'entry_id' => 100 - $i, 'player_id' => 1, 'external_player_id' => '999999',
                'race_date' => $date->format('Y-m-d'), 'scheduled_start_at' => $date->format(DATE_ATOM), 'meeting_id' => 100 - $i,
                'race_status' => 'CONFIRMED', 'evidence_verified' => true, 'participation' => 'STARTED', 'method' => 'ESCAPE',
                'method_missing' => false, 'issues' => [], 'fetched_at' => '2026-01-01T00:00:00+09:00'];
        }

        return $rows;
    }
}
