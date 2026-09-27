<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariPlayerHistory\Builder;
use App\Domain\Keirin\Statistics\AgariPlayerHistory\Contract;
use App\Domain\Keirin\Statistics\AgariPlayerHistory\Source;
use App\Domain\Keirin\Statistics\AgariPlayerHistory\Summary;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\AgariPlayerHistoryFixture as Fixture;
use Tests\Support\AgariRaceRelativeFixture;
use Tests\Support\MemoryLimitedTestProcess;
use Tests\TestCase;

class AgariPlayerHistoryCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/agari-history-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        Http::preventStrayRequests();
        DB::shouldReceive('connection')->never();
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
        parent::tearDown();
    }

    private function build(iterable $rows): array
    {
        $source = Fixture::make($this->root, $rows);

        return (new Builder($source))->build($this->root.'/input', $this->root.'/relative', $this->root.'/result');
    }

    private function entries(string $name = 'result'): array
    {
        return array_column(iterator_to_array(Artifacts::lines($this->root.'/'.$name.'/entry-history.jsonl')), null, 'result_id');
    }

    public function test_command_reproduces_six_files_without_database_or_http_and_preserves_zero_result_races(): void
    {
        $source = Fixture::make($this->root, [Fixture::race(1, '2023-01-01'), Fixture::race(2, '2023-02-01'), Fixture::race(3, '2023-03-01', times: [])]);
        $this->app->instance(Source::class, $source);
        foreach (['result', 'reproduced'] as $name) {
            $this->artisan('keirin:stat35:player-history:build', ['--source-input-dir' => $this->root.'/input',
                '--source-result-dir' => $this->root.'/relative', '--output-dir' => $this->root.'/'.$name])->assertSuccessful();
        }
        foreach (Contract::FILES as $file) {
            $this->assertSame(Files::identity($this->root.'/result/'.$file), Files::identity($this->root.'/reproduced/'.$file));
            $this->assertSame(file_get_contents($this->root.'/result/'.$file), file_get_contents($this->root.'/reproduced/'.$file));
        }
        $summary = Files::json($this->root.'/result/summary.json');
        $this->assertSame(3, $summary['totals']['races']);
        $this->assertSame(1, $summary['totals']['zero_result_races']);
        $this->assertSame(4, $summary['totals']['result_rows']);
        $this->assertSame(2, $summary['inventory']['identified_players']);
        $this->assertSame(4, $summary['totals']['player_meetings']);
        Http::assertNothingSent();
    }

    public function test_reversed_race_ids_equal_meeting_weight_and_target_meeting_exclusion(): void
    {
        $rows = [Fixture::race(1, '2023-04-01', 40, '2023-04-01', '2023-04-03'),
            Fixture::race(2, '2023-04-02', 40, '2023-04-01', '2023-04-03'),
            Fixture::race(3, '2023-03-01', 30, times: ['14', '12']),
            Fixture::race(4, '2023-02-01', 20, '2023-02-01', '2023-02-03'),
            Fixture::race(5, '2023-02-02', 20, '2023-02-01', '2023-02-03'),
            Fixture::race(6, '2023-02-03', 20, '2023-02-01', '2023-02-03')];
        $this->build($rows);
        $entries = $this->entries();
        $this->assertSame($entries[11]['history'], $entries[21]['history']);
        $w = $entries[11]['history']['windows'][3];
        $this->assertSame([30, 20], array_column($w['meetings'], 'meeting_id'));
        $this->assertSame(2, $w['valid_meetings']);
        $this->assertSame(4, $w['adopted_races']);
        $this->assertSame('0.500000000000', $w['mean']['decimal']);
        $this->assertSame('0.250000000000', $w['population_variance']['decimal']);
        $this->assertSame('000001', $entries[11]['external_player_id']);
    }

    public function test_class_and_external_identity_are_not_auxiliary_player_ids(): void
    {
        $a = Fixture::race(1, '2023-01-01');
        $b = Fixture::race(2, '2023-02-01');
        $b['results'][0]['player_id'] = 999;
        $b['results'][0]['observation']['player_id'] = 1000;
        $c = Fixture::race(3, '2023-03-01');
        $c['race_type'] = 'S級予選';
        $d = Fixture::race(4, '2023-04-01');
        $d['results'][0]['observation']['external_player_id'] = '000099';
        $d['results'][0]['player_id'] = 999;
        $this->build([$a, $b, $c, $d]);
        $entries = $this->entries();
        $this->assertSame(1, $entries[21]['history']['windows'][3]['valid_meetings']);
        $this->assertSame(0, $entries[31]['history']['windows'][3]['observed_meetings']);
        $this->assertSame(0, $entries[41]['history']['windows'][3]['observed_meetings']);
        $this->assertSame(999, $entries[21]['target_result_audit']['current_player_id']);
        $this->assertSame(1000, $entries[21]['target_result_audit']['observation_player_id']);
    }

    public function test_missing_partial_abnormal_and_boundary_meetings_remain_null_slots(): void
    {
        $a = Fixture::race(1, '2022-01-01', 1, '2021-12-31', '2022-01-02');
        $b = Fixture::race(2, '2022-02-01', times: ['12', null]);
        $c = Fixture::race(3, '2022-03-01');
        AgariRaceRelativeFixture::value($c, 0, 'result_status', 'CRASHED');
        AgariRaceRelativeFixture::value($c, 0, 'agari_status', 'OBSERVED_ABNORMAL_RESULT');
        $d = Fixture::race(4, '2022-04-01');
        $this->build([$a, $b, $c, $d]);
        $w = $this->entries()[41]['history']['windows'][3];
        $this->assertSame([3, 2, 1], array_column($w['meetings'], 'meeting_id'));
        $this->assertSame(3, $w['observed_meetings']);
        $this->assertSame(0, $w['valid_meetings']);
        $this->assertNull($w['mean']);
        $this->assertTrue($w['flags']['missing_meeting_values']);
        $this->assertTrue($w['flags']['input_left_truncated']);
        $meetings = iterator_to_array(Artifacts::lines($this->root.'/result/player-meetings.jsonl'));
        $partial = array_values(array_filter($meetings, fn ($m) => $m['meeting']['meeting_id'] === 2 && $m['external_player_id'] === '000001'))[0];
        $this->assertSame(1, $partial['partial_rows']);
        $this->assertSame(0, $partial['adopted_races']);
        $this->assertNull($partial['meeting_percentile_mean']);
    }

    public function test_unknown_and_duplicate_external_id_are_retained_without_double_count(): void
    {
        $a = Fixture::race(1, '2023-01-01');
        $a['results'][0]['observation']['external_player_id'] = null;
        $b = Fixture::race(2, '2023-02-01');
        $b['results'][1]['observation']['external_player_id'] = '000001';
        $report = $this->build([$a, $b]);
        $this->assertSame(4, $report['totals']['result_rows']);
        $this->assertSame(1, $report['totals']['unresolved_rows']);
        $this->assertSame(2, $report['totals']['identity_conflict_rows']);
        $this->assertContains('IDENTITY_CONFLICT', $this->entries()[21]['context_flags']);
        $this->assertNull($this->entries()[21]['history']['windows'][3]['mean']);
    }

    public function test_mixed_identity_meeting_keeps_only_the_identified_mean(): void
    {
        $normal = Fixture::race(1, '2023-07-01', 70, '2023-07-01', '2023-07-03');
        $conflict = Fixture::race(2, '2023-07-02', 70, '2023-07-01', '2023-07-03');
        $conflict['results'][1]['observation']['external_player_id'] = '000001';
        $this->build([$normal, $conflict]);
        $meetings = iterator_to_array(Artifacts::lines($this->root.'/result/player-meetings.jsonl'));
        $group = array_values(array_filter($meetings, fn ($m) => $m['external_player_id'] === '000001'))[0];
        $this->assertSame(3, $group['observed_result_rows']);
        $this->assertSame(1, $group['adopted_races']);
        $this->assertSame(['numerator' => '1', 'denominator' => '1', 'decimal' => '1.000000000000'], $group['meeting_percentile_mean']);
        $this->assertSame(2, $group['exclusion_reasons']['IDENTITY_CONFLICT']);
        $this->assertTrue($group['history_context_eligible']);
    }

    private function mixedIdentityRows(): array
    {
        $rows = [];
        for ($id = 1; $id <= 6; $id++) {
            $rows[] = Fixture::race($id, sprintf('2023-%02d-01', $id), times: $id <= 3 ? ['14', '12'] : ['12', '14']);
        }
        $rows[] = Fixture::race(7, '2023-07-01', 70, '2023-07-01', '2023-07-03');
        $rows[] = Fixture::race(8, '2023-07-02', 70, '2023-07-01', '2023-07-03');
        $rows[7]['results'][1]['observation']['external_player_id'] = '000001';
        $rows[] = Fixture::race(9, '2023-08-01');

        return $rows;
    }

    private function assertIdentityBlocked(array $entry, string $reason): void
    {
        $this->assertSame($reason, $entry['identity_status']);
        foreach ($entry['history']['windows'] as $window) {
            $this->assertSame([$reason], $window['blocking_reasons']);
            $this->assertSame([], $window['meetings']);
            $this->assertSame(0, $window['observed_meetings']);
            $this->assertSame(0, $window['valid_meetings']);
            foreach (['mean', 'median', 'population_variance'] as $field) {
                $this->assertNull($window[$field]);
            }
        }
        $this->assertNull($entry['history']['recent3_minus_previous3_meeting_percentile']);
        $this->assertSame('BLOCKED_CONTEXT_OR_ORDER', $entry['history']['trend_null_reason']);
    }

    public function test_mixed_identity_targets_are_blocked_individually_and_following_history_uses_one_meeting(): void
    {
        $report = $this->build($this->mixedIdentityRows());
        $entries = $this->entries();
        $normal = $entries[71]['history'];
        $this->assertSame([6, 5, 4, 3, 2, 1], array_column($normal['windows'][6]['meetings'], 'meeting_id'));
        $this->assertSame('0.500000000000', $normal['windows'][6]['mean']['decimal']);
        $this->assertSame('1.000000000000', $normal['recent3_minus_previous3_meeting_percentile']['decimal']);
        foreach ([81, 82] as $id) {
            $this->assertIdentityBlocked($entries[$id], 'IDENTITY_CONFLICT');
            $this->assertSame('000001', $entries[$id]['external_player_id']);
            $this->assertSame('000001', $entries[$id]['target_result_audit']['external_player_id']);
            $this->assertSame($id, $entries[$id]['target_result_audit']['result_id']);
            $this->assertSame($id, $entries[$id]['target_result_audit']['observation_id']);
        }
        $this->assertSame([], $entries[71]['context_flags']);
        $window = $entries[91]['history']['windows'][3];
        $this->assertSame([70, 6, 5], array_column($window['meetings'], 'meeting_id'));
        $this->assertSame(3, $window['adopted_races']);
        $this->assertSame('1.000000000000', $window['mean']['decimal']);
        $this->assertSame(2, $window['exclusion_reasons']['IDENTITY_CONFLICT']);
        $meetings = iterator_to_array(Artifacts::lines($this->root.'/result/player-meetings.jsonl'));
        $group = array_values(array_filter($meetings, fn ($m) => $m['id'] === $entries[71]['player_meeting_id']))[0];
        $this->assertSame([[], ['IDENTITY_CONFLICT'], ['IDENTITY_CONFLICT']], array_column($group['evidence'], 'reasons'));
        $this->assertSame(18, $report['totals']['result_rows']);
        $this->assertSame(16, $report['totals']['identified_rows']);
        $this->assertSame(2, $report['totals']['identity_conflict_rows']);
        $this->assertArrayNotHasKey('IDENTITY_CONFLICT', $report['reasons']['context']);
        $this->assertSame(2, $report['reasons']['meeting_exclusions']['IDENTITY_CONFLICT']);
        $this->assertSame(4, $report['totals']['trend_calculated']);
        $this->assertSame(14, $report['totals']['trend_null']);
        $this->assertSame(10, $report['totals']['window_3_full_valid']);
        $this->assertSame(4, $report['totals']['window_6_full_valid']);
        foreach ([3, 6, 12] as $n) {
            $blocked = $full = 0;
            foreach ($entries as $entry) {
                $blocked += (int) ($entry['history']['windows'][$n]['blocking_reasons'] !== []);
                $full += (int) ($entry['history']['windows'][$n]['valid_meetings'] === $n);
            }
            $this->assertSame(2, $blocked);
            $this->assertSame($blocked, $report['totals']['window_'.$n.'_blocked']);
            $this->assertSame($full, $report['totals']['window_'.$n.'_full_valid']);
        }
        (new Builder(Fixture::source($this->root)))->build($this->root.'/input', $this->root.'/relative', $this->root.'/reproduced');
        foreach (Contract::FILES as $file) {
            $this->assertSame(file_get_contents($this->root.'/result/'.$file), file_get_contents($this->root.'/reproduced/'.$file));
        }
    }

    public function test_conflict_values_or_addition_cannot_change_the_identified_meeting_mean_or_normal_target_history(): void
    {
        $rows = $this->mixedIdentityRows();
        $this->build($rows);
        $entries = $this->entries();
        $meetings = iterator_to_array(Artifacts::lines($this->root.'/result/player-meetings.jsonl'));
        foreach (['changed', 'removed'] as $name) {
            $variant = $rows;
            if ($name === 'changed') {
                foreach ([0 => '18', 1 => '12'] as $offset => $time) {
                    AgariRaceRelativeFixture::value($variant[7], $offset, 'agari_time_seconds', $time);
                    AgariRaceRelativeFixture::value($variant[7], $offset, 'agari_raw_text', $time);
                }
            } else {
                unset($variant[7]);
            }
            mkdir($this->root.'/'.$name, 0700);
            $source = Fixture::make($this->root.'/'.$name, $variant);
            (new Builder($source))->build($this->root.'/'.$name.'/input', $this->root.'/'.$name.'/relative', $this->root.'/'.$name.'/result');
            $new = $this->entries($name.'/result');
            $this->assertNotSame($entries[71]['provenance'], $new[71]['provenance']);
            foreach ([71, 72] as $id) {
                $this->assertSame($entries[$id]['history'], $new[$id]['history']);
            }
            foreach (Artifacts::lines($this->root.'/'.$name.'/result/player-meetings.jsonl') as $group) {
                $old = array_values(array_filter($meetings, fn ($m) => $m['id'] === $group['id']))[0];
                $this->assertSame($old['meeting_percentile_mean'], $group['meeting_percentile_mean']);
                $this->assertSame($old['adopted_races'], $group['adopted_races']);
                $this->assertSame($old['history_context_eligible'], $group['history_context_eligible']);
            }
            if ($name === 'changed') {
                $this->assertNotSame($entries[81]['target_result_audit']['percentile_numerator'], $new[81]['target_result_audit']['percentile_numerator']);
                $this->assertSame($entries[91]['history'], $new[91]['history']);
                $this->assertIdentityBlocked($new[81], 'IDENTITY_CONFLICT');
            }
        }
    }

    public function test_unconfirmed_identity_groups_are_not_history_but_identified_missing_time_consumes_a_slot(): void
    {
        $rows = [Fixture::race(1, '2023-01-01'), Fixture::race(2, '2023-02-01'), Fixture::race(3, '2023-03-01'),
            Fixture::race(4, '2023-04-01', times: [null, '14']), Fixture::race(5, '2023-05-01')];
        $rows[1]['results'][1]['observation']['external_player_id'] = '000001';
        $rows[2]['results'][0]['observation']['external_player_id'] = null;
        foreach ($rows as &$race) {
            foreach ($race['results'] as &$row) {
                $row['player_id'] = 999;
                $row['observation']['player_id'] = 999;
            }
            unset($row);
        }
        unset($race);
        $report = $this->build($rows);
        $entries = $this->entries();
        $this->assertCount(10, $entries);
        $this->assertSame(2, $report['totals']['identity_conflict_rows']);
        $this->assertSame(1, $report['totals']['unresolved_rows']);
        $this->assertIdentityBlocked($entries[21], 'IDENTITY_CONFLICT');
        $this->assertIdentityBlocked($entries[22], 'IDENTITY_CONFLICT');
        $this->assertIdentityBlocked($entries[31], 'UNRESOLVED_EXTERNAL_ID');
        $this->assertNull($entries[31]['external_player_id']);
        $this->assertNull($entries[31]['target_result_audit']['external_player_id']);
        $window = $entries[51]['history']['windows'][3];
        $this->assertSame([4, 1], array_column($window['meetings'], 'meeting_id'));
        $this->assertSame(2, $window['observed_meetings']);
        $this->assertSame(1, $window['valid_meetings']);
        $this->assertNull($window['meetings'][0]['meeting_percentile_mean']);
        $this->assertTrue($window['flags']['missing_meeting_values']);
        foreach (Artifacts::lines($this->root.'/result/player-meetings.jsonl') as $group) {
            if (in_array($group['meeting']['meeting_id'], [2, 3], true) && $group['external_player_id'] !== '000002') {
                $this->assertFalse($group['history_context_eligible']);
                $this->assertNull($group['meeting_percentile_mean']);
            }
        }
    }

    public function test_identified_missing_time_with_conflicts_still_establishes_a_null_observed_meeting(): void
    {
        $rows = $this->mixedIdentityRows();
        AgariRaceRelativeFixture::value($rows[6], 0, 'agari_time_seconds', null);
        AgariRaceRelativeFixture::value($rows[6], 0, 'agari_raw_text', null);
        AgariRaceRelativeFixture::value($rows[6], 0, 'agari_status', 'MISSING');
        $this->build($rows);
        $entries = $this->entries();
        $this->assertSame('1.000000000000', $entries[71]['history']['recent3_minus_previous3_meeting_percentile']['decimal']);
        $this->assertIdentityBlocked($entries[81], 'IDENTITY_CONFLICT');
        $window = $entries[91]['history']['windows'][3];
        $this->assertSame([70, 6, 5], array_column($window['meetings'], 'meeting_id'));
        $this->assertSame(3, $window['observed_meetings']);
        $this->assertSame(2, $window['valid_meetings']);
        $this->assertNull($window['meetings'][0]['meeting_percentile_mean']);
        $this->assertSame(2, $window['exclusion_reasons']['IDENTITY_CONFLICT']);
        $this->assertSame(1, $window['exclusion_reasons']['AGARI_MISSING']);
        foreach (Artifacts::lines($this->root.'/result/player-meetings.jsonl') as $group) {
            if ($group['id'] === $entries[71]['player_meeting_id']) {
                $this->assertTrue($group['history_context_eligible']);
                $this->assertSame(0, $group['adopted_races']);
                $this->assertNull($group['meeting_percentile_mean']);
            }
        }
    }

    #[DataProvider('mixedContextConflicts')]
    public function test_identified_rows_do_not_override_genuine_mixed_meeting_context_blocks(string $kind, string $reason): void
    {
        $rows = $this->mixedIdentityRows();
        if ($kind === 'metadata') {
            $rows[7]['context']['ends_on'] = '2023-07-04';
        } elseif ($kind === 'period') {
            $rows[6]['context']['ends_on'] = $rows[7]['context']['ends_on'] = '2023-06-30';
        } else {
            $rows[6]['context']['meeting_track_id'] = $rows[7]['context']['meeting_track_id'] = 2;
        }
        $this->build($rows);
        $entries = $this->entries();
        $this->assertSame('IDENTIFIED', $entries[71]['identity_status']);
        $this->assertContains($reason, $entries[71]['history']['windows'][3]['blocking_reasons']);
        $this->assertNull($entries[71]['history']['windows'][3]['mean']);
        $this->assertIdentityBlocked($entries[81], 'IDENTITY_CONFLICT');
        $this->assertSame([6, 5, 4], array_column($entries[91]['history']['windows'][3]['meetings'], 'meeting_id'));
        foreach (Artifacts::lines($this->root.'/result/player-meetings.jsonl') as $group) {
            if ($group['meeting']['meeting_id'] === 70) {
                $this->assertContains($reason, $group['context_flags']);
                $this->assertFalse($group['history_context_eligible']);
                $this->assertNull($group['meeting_percentile_mean']);
            }
        }
    }

    public static function mixedContextConflicts(): array
    {
        return [['metadata', 'MEETING_METADATA_CONFLICT'], ['period', 'MEETING_PERIOD_CONFLICT'], ['venue', 'MEETING_RELATION_CONFLICT']];
    }

    public function test_overlapping_periods_are_not_arbitrarily_ordered_and_same_day_end_is_excluded(): void
    {
        $rows = [Fixture::race(1, '2023-01-01', 1, '2023-01-01', '2023-01-03'),
            Fixture::race(2, '2023-01-02', 2, '2023-01-02', '2023-01-04'),
            Fixture::race(3, '2023-02-01'), Fixture::race(4, '2023-01-04')];
        $this->build($rows);
        $entries = $this->entries();
        $this->assertContains('AMBIGUOUS_MEETING_ORDER', $entries[31]['history']['windows'][3]['blocking_reasons']);
        $this->assertSame([], $entries[31]['history']['windows'][3]['meetings']);
        $this->assertNotContains(2, array_column($entries[41]['history']['windows'][3]['meetings'], 'meeting_id'));
        $this->assertSame([1], array_column($entries[41]['history']['windows'][3]['meetings'], 'meeting_id'));
        $this->assertNull($entries[41]['history']['windows'][3]['population_variance']);
    }

    public function test_year_crossing_metadata_does_not_access_2026_and_context_conflict_is_explicit(): void
    {
        $a = Fixture::race(1, '2025-12-30', 50, '2025-12-30', '2026-01-01');
        $b = Fixture::race(2, '2023-02-01', 2, '2023-02-01', '2023-02-03');
        $c = Fixture::race(3, '2023-02-02', 2, '2023-02-01', '2023-02-04');
        $this->build([$a, $b, $c]);
        $entries = $this->entries();
        $this->assertContains('MEETING_CROSSES_INPUT_END', $entries[11]['context_flags']);
        $this->assertContains('MEETING_METADATA_CONFLICT', $entries[21]['context_flags']);
        $this->assertContains('MEETING_METADATA_CONFLICT', $entries[31]['context_flags']);
    }

    public function test_own_and_future_outcomes_do_not_change_target_history_values(): void
    {
        $rows = [Fixture::race(1, '2023-01-01'), Fixture::race(2, '2023-02-01'), Fixture::race(3, '2023-03-01')];
        $this->build($rows);
        $old = $this->entries();
        mkdir($this->root.'/changed', 0700);
        $rows[1] = Fixture::race(2, '2023-02-01', times: [null, '14']);
        $rows[2] = Fixture::race(3, '2023-03-01', times: ['18', '14']);
        $source = Fixture::make($this->root.'/changed', $rows);
        (new Builder($source))->build($this->root.'/changed/input', $this->root.'/changed/relative', $this->root.'/changed/result');
        $new = $this->entries('changed/result');
        foreach ([11, 12, 21, 22] as $id) {
            $this->assertSame($old[$id]['history'], $new[$id]['history']);
            $this->assertNotSame($old[$id]['provenance'], $new[$id]['provenance']);
        }
        $this->assertNotSame($old[31]['history'], $new[31]['history']);
    }

    #[DataProvider('smallInputs')]
    public function test_empty_unknown_and_no_history_have_fixed_counter_schema(string $kind): void
    {
        $rows = $kind === 'empty' ? [] : [Fixture::race(1, '2023-01-01')];
        if ($kind === 'unknown') {
            foreach ($rows[0]['results'] as &$row) {
                $row['observation']['external_player_id'] = null;
            }
        }
        $summary = $this->build($rows);
        $this->assertSame(array_keys(Summary::counters()), array_keys($summary['totals']));
        foreach ($summary['totals'] as $count) {
            $this->assertIsInt($count);
        }
        $this->assertSame(0, $summary['totals']['trend_calculated']);
        if ($kind === 'empty') {
            $this->assertSame([0], array_values(array_unique(array_values($summary['totals']))));
            $this->assertSame([], $summary['groups']);
        }
    }

    public static function smallInputs(): array
    {
        return [['empty'], ['unknown'], ['no-history']];
    }

    #[DataProvider('corruptions')]
    public function test_corrupt_missing_mismatched_resealed_and_2026_sources_never_publish(string $corruption): void
    {
        $source = Fixture::make($this->root, [Fixture::race(1, '2023-01-01'), Fixture::race(2, '2023-02-01')]);
        $path = $this->root.'/relative/details.jsonl';
        $details = iterator_to_array(Artifacts::lines($path));
        switch ($corruption) {
            case 'missing': unlink($path);
                break;
            case 'bytes': file_put_contents($path, ' ', FILE_APPEND);
                break;
            case 'wrong-kind':
                $manifest = Files::json($this->root.'/relative/manifest.json');
                $manifest['kind'] = 'INPUT';
                file_put_contents($this->root.'/relative/manifest.json', Files::canonical($manifest));
                Fixture::reseal($this->root.'/relative');
                break;
            default:
                match ($corruption) {
                    'missing-row' => array_pop($details),
                    'duplicate-race' => $details[1] = $details[0],
                    '2026' => $details[0]['race_date'] = '2026-01-01',
                    'duplicate-result' => $details[0]['results'][1] = $details[0]['results'][0],
                    'wrong-bike' => $details[0]['results'][0]['bike_number'] = 9,
                    'wrong-import' => $details[0]['results'][0]['race_result_import_id'] = 999,
                    'wrong-observation' => $details[0]['results'][0]['observation_id'] = 999,
                    'wrong-external' => $details[0]['results'][0]['external_player_id'] = '000099',
                    'wrong-definition' => $details[0]['measurement_definition_id'] = 'unknown',
                    'invalid-rational' => $details[0]['results'][0]['percentile_denominator'] = 0,
                    'disclosure' => $details[0]['historical_as_of_available'] = true,
                };
                file_put_contents($path, implode('', array_map(fn ($d) => Files::canonical($d)."\n", $details)));
                Fixture::reseal($this->root.'/relative');
                $source = Fixture::source($this->root);
        }
        try {
            (new Builder($source))->build($this->root.'/input', $this->root.'/relative', $this->root.'/result');
            $this->fail('Corrupt fixture was published.');
        } catch (RuntimeException $e) {
            $this->assertNotSame('', $e->getMessage());
            $this->assertFileDoesNotExist($this->root.'/result/COMPLETE.json');
        }
    }

    public static function corruptions(): array
    {
        return array_map(fn ($name) => [$name], ['missing', 'bytes', 'wrong-kind', 'missing-row', 'duplicate-race', '2026',
            'duplicate-result', 'wrong-bike', 'wrong-import', 'wrong-observation', 'wrong-external', 'wrong-definition', 'invalid-rational', 'disclosure']);
    }

    public function test_pinned_identity_cannot_be_replaced_and_existing_output_is_not_overwritten(): void
    {
        $this->build([Fixture::race(1, '2023-01-01')]);
        $before = Files::identity($this->root.'/result/COMPLETE.json');
        foreach ([new Source, Fixture::source($this->root)] as $source) {
            try {
                (new Builder($source))->build($this->root.'/input', $this->root.'/relative', $this->root.'/result');
                $this->fail('Pin or output guard was bypassed.');
            } catch (RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertSame($before, Files::identity($this->root.'/result/COMPLETE.json'));
    }

    public function test_swapped_directories_and_a_result_bound_to_another_input_are_rejected(): void
    {
        $source = Fixture::make($this->root, [Fixture::race(1, '2023-01-01')]);
        try {
            (new Builder($source))->build($this->root.'/relative', $this->root.'/input', $this->root.'/swapped');
            $this->fail('Swapped directories accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('input manifest', $e->getMessage());
        }
        $manifest = Files::json($this->root.'/input/manifest.json');
        $manifest['race_count'] = 99;
        file_put_contents($this->root.'/input/manifest.json', Files::canonical($manifest));
        Fixture::reseal($this->root.'/input');
        $this->expectException(RuntimeException::class);
        $source->verify($this->root.'/input', $this->root.'/relative');
    }

    public function test_source_end_validation_rejects_one_byte_drift_after_successful_start(): void
    {
        $source = Fixture::make($this->root, [Fixture::race(1, '2023-01-01')]);
        $before = $source->verify($this->root.'/input', $this->root.'/relative');
        $this->assertSame(1, $before['input']['race_count']);
        $path = $this->root.'/relative/details.jsonl';
        $bytes = file_get_contents($path);
        file_put_contents($path, substr_replace($bytes, 'X', 10, 1));
        $this->expectException(RuntimeException::class);
        $source->verify($this->root.'/input', $this->root.'/relative');
    }

    public function test_unknown_class_and_missing_period_keep_rows_but_never_use_mixed_history(): void
    {
        $source = Fixture::make($this->root, [Fixture::race(1, '2023-01-01'), Fixture::race(2, '2023-02-01')]);
        $input = iterator_to_array(Artifacts::lines($this->root.'/input/races.jsonl'));
        $detail = iterator_to_array(Artifacts::lines($this->root.'/relative/details.jsonl'));
        $input[1]['context']['starts_on'] = null;
        $detail[1]['classification']['race_class'] = 'UNKNOWN';
        file_put_contents($this->root.'/input/races.jsonl', implode('', array_map(fn ($r) => Files::canonical($r)."\n", $input)));
        file_put_contents($this->root.'/relative/details.jsonl', implode('', array_map(fn ($r) => Files::canonical($r)."\n", $detail)));
        Fixture::reseal($this->root.'/input');
        $manifest = Files::json($this->root.'/relative/manifest.json');
        $manifest['input_manifest'] = Files::identity($this->root.'/input/manifest.json');
        file_put_contents($this->root.'/relative/manifest.json', Files::canonical($manifest));
        Fixture::reseal($this->root.'/relative');
        (new Builder(Fixture::source($this->root)))->build($this->root.'/input', $this->root.'/relative', $this->root.'/result');
        $entry = $this->entries()[21];
        $this->assertContains('UNKNOWN_MEETING_PERIOD', $entry['context_flags']);
        $this->assertContains('UNKNOWN_RACE_CLASS', $entry['context_flags']);
        $this->assertSame(0, $entry['history']['windows'][3]['observed_meetings']);
        $this->assertCount(4, $this->entries());
    }

    public function test_large_streamed_history_generation_in_independent_128m_process(): void
    {
        if (MemoryLimitedTestProcess::delegate(__METHOD__)) {
            return;
        }
        $rows = (function () {
            for ($id = 1; $id <= 12000; $id++) {
                $day = (new \DateTimeImmutable('2022-01-01'))->modify('+'.intdiv($id - 1, 10).' days')->format('Y-m-d');
                $race = Fixture::race($id, $day, times: array_fill(0, 9, '12'));
                foreach ($race['results'] as &$row) {
                    $row['observation']['external_player_id'] = sprintf('%06d', (($id - 1) % 10) * 9 + $row['bike_number']);
                }
                yield $race;
            }
        })();
        $report = $this->build($rows);
        $this->assertSame(12000, $report['totals']['races']);
        $this->assertSame(108000, $report['totals']['result_rows']);
        $this->assertSame(90, $report['inventory']['identified_players']);
        $this->assertSame(106920, $report['totals']['window_12_full_valid']);
        $this->assertGreaterThan(100 * 1024 * 1024, filesize($this->root.'/input/races.jsonl'));
        $count = 0;
        foreach (Artifacts::lines($this->root.'/result/entry-history.jsonl') as $entry) {
            $count++;
            if ($entry['history']['recent3_minus_previous3_meeting_percentile'] !== null) {
                $this->assertSame('0.000000000000', $entry['history']['recent3_minus_previous3_meeting_percentile']['decimal']);
            }
        }
        $this->assertSame(108000, $count);
        $this->assertLessThan(MemoryLimitedTestProcess::LIMIT, memory_get_peak_usage(true));
        MemoryLimitedTestProcess::record(__METHOD__, memory_get_peak_usage(true));
    }
}
