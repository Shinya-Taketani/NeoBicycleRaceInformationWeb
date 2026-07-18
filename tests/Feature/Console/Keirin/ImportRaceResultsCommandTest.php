<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

use App\Domain\Keirin\Scraping\Parsers\RaceResultPageParser;
use App\Models\BatchRun;
use App\Models\BatchRunItem;
use App\Models\Race;
use App\Models\RaceDay;
use App\Models\RaceEntry;
use App\Models\RaceMeeting;
use App\Models\RacePayout;
use App\Models\RaceResult;
use App\Models\RaceResultImport;
use App\Models\Racetrack;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ImportRaceResultsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_raw_schedule_import_is_idempotent(): void
    {
        $fixture = base_path('tests/Fixtures/Keirin/actual/race_schedule_2026_07.html');

        $exitCode = Artisan::call('keirin:races:sync-schedule', [
            '--from' => '2026-07-01',
            '--to' => '2026-07-31',
            '--raw-file' => $fixture,
        ]);
        $this->assertSame(0, $exitCode, Artisan::output());

        $firstMeetingCount = RaceMeeting::query()->count();
        $firstDayCount = RaceDay::query()->count();
        $this->assertSame(0, Race::query()->count());

        $exitCode = Artisan::call('keirin:races:sync-schedule', [
            '--from' => '2026-07-01',
            '--to' => '2026-07-31',
            '--raw-file' => $fixture,
        ]);
        $this->assertSame(0, $exitCode, Artisan::output());

        $this->assertGreaterThan(0, $firstMeetingCount);
        $this->assertSame($firstMeetingCount, RaceMeeting::query()->count());
        $this->assertSame($firstDayCount, RaceDay::query()->count());
    }

    public function test_raw_result_import_syncs_results_and_payouts_idempotently(): void
    {
        Storage::fake('local');
        $race = $this->race();
        $fixture = base_path('tests/Fixtures/Keirin/synthetic/race_result_normal.html');

        $this->artisan('keirin:races:import-results', [
            '--race-id' => (string) $race->id,
            '--raw-file' => $fixture,
            '--source-url' => 'https://example.invalid/result',
            '--result-status' => 'CONFIRMED',
        ])->assertExitCode(0);

        $this->artisan('keirin:races:import-results', [
            '--race-id' => (string) $race->id,
            '--raw-file' => $fixture,
            '--source-url' => 'https://example.invalid/result',
            '--result-status' => 'CONFIRMED',
        ])->assertExitCode(0);

        $this->assertSame(7, RaceResult::query()->where('race_id', $race->id)->count());
        $this->assertSame(3, RacePayout::query()->where('race_id', $race->id)->count());
        $this->assertSame('CONFIRMED', $race->refresh()->result_status);
        $this->assertSame(2, RaceResultImport::query()->where('race_id', $race->id)->where('import_status', 'SUCCEEDED')->count());
        $import = RaceResultImport::query()->latest('id')->firstOrFail();
        Storage::disk('local')->assertExists($import->raw_file_path);
        $this->assertSame(hash_file('sha256', $fixture), $import->source_hash);
        $this->assertSame(filesize($fixture), $import->raw_response_size);
        $this->assertSame('UTF-8', $import->detected_encoding);
        $this->assertTrue($import->utf8_conversion_succeeded);
        $this->assertSame(hash_file('sha256', $fixture), $import->converted_hash);
        $this->assertSame($import->id, RaceResult::query()->where('race_id', $race->id)->firstOrFail()->race_result_import_id);
        $this->assertSame($import->id, RacePayout::query()->where('race_id', $race->id)->firstOrFail()->race_result_import_id);
        $run = BatchRun::query()->latest('id')->firstOrFail();
        $this->assertSame([1, 0, 0], [$run->success_count, $run->skipped_count, $run->failure_count]);
    }

    public function test_corrected_result_keeps_all_entries_and_removes_stale_payouts(): void
    {
        Storage::fake('local');
        $race = $this->race();

        $this->artisan('keirin:races:import-results', [
            '--race-id' => (string) $race->id,
            '--raw-file' => base_path('tests/Fixtures/Keirin/synthetic/race_result_normal.html'),
            '--source-url' => 'https://example.invalid/result',
            '--result-status' => 'CONFIRMED',
        ])->assertExitCode(0);

        $this->artisan('keirin:races:import-results', [
            '--race-id' => (string) $race->id,
            '--raw-file' => base_path('tests/Fixtures/Keirin/synthetic/race_result_corrected.html'),
            '--source-url' => 'https://example.invalid/result-corrected',
            '--result-status' => 'CORRECTED',
        ])->assertExitCode(0);

        $this->assertSame(7, RaceResult::query()->where('race_id', $race->id)->count());
        $this->assertSame(1, RacePayout::query()->where('race_id', $race->id)->count());
        $this->assertSame(1, RaceResult::query()->where('race_id', $race->id)->where('bike_number', 2)->where('rank', 1)->count());
        $this->assertDatabaseMissing('race_payouts', ['race_id' => $race->id, 'combination' => '1-2']);
        $this->assertDatabaseHas('race_payouts', ['race_id' => $race->id, 'combination' => '2-1', 'payout_amount' => 2340]);
        $this->assertSame('CORRECTED', $race->refresh()->result_status);
        $this->assertSame(2, RaceResultImport::query()->where('race_id', $race->id)->count());
    }

    public function test_corrected_result_is_idempotent_and_rejects_regressions(): void
    {
        Storage::fake('local');
        $race = $this->race();

        $this->importResult($race, 'race_result_normal.html', 'CONFIRMED');
        $this->importResult($race, 'race_result_corrected.html', 'CORRECTED');
        $this->importResult($race, 'race_result_corrected.html', 'CORRECTED');

        foreach ([
            ['race_result_normal.html', 'CONFIRMED'],
            ['race_result_under_review.html', 'UNDER_REVIEW'],
        ] as [$fixture, $status]) {
            $this->artisan('keirin:races:import-results', [
                '--race-id' => (string) $race->id,
                '--raw-file' => base_path('tests/Fixtures/Keirin/synthetic/'.$fixture),
                '--source-url' => 'https://example.invalid/regression',
                '--result-status' => $status,
            ])->assertExitCode(1);
        }

        $this->assertSame('CORRECTED', $race->refresh()->result_status);
        $this->assertSame(7, RaceResult::query()->where('race_id', $race->id)->count());
        $this->assertSame(1, RacePayout::query()->where('race_id', $race->id)->count());
        $this->assertSame(3, RaceResultImport::query()->where('race_id', $race->id)->where('import_status', 'SUCCEEDED')->count());
        $this->assertSame(2, RaceResultImport::query()->where('race_id', $race->id)->where('import_status', 'FAILED')->count());
    }

    public function test_race_entries_allow_complete_7_and_9_car_results(): void
    {
        Storage::fake('local');
        $race7 = $this->race(externalRaceId: 'entries-7', raceNumber: 7, entrantCount: 7);
        $this->createRaceEntries($race7, range(1, 7));
        $race9 = $this->race(externalRaceId: 'entries-9', raceNumber: 9, entrantCount: 9);
        $this->createRaceEntries($race9, range(1, 9));

        $this->importResult($race7, 'race_result_normal.html', 'CONFIRMED');
        $this->importResult($race9, 'race_result_normal_9.html', 'CONFIRMED');

        $this->assertSame(7, RaceResult::query()->where('race_id', $race7->id)->count());
        $this->assertSame(9, RaceResult::query()->where('race_id', $race9->id)->count());
    }

    public function test_entrant_count_allows_complete_7_and_9_car_results_without_entries(): void
    {
        Storage::fake('local');
        $race7 = $this->race(externalRaceId: 'count-7', raceNumber: 7, entrantCount: 7);
        $race9 = $this->race(externalRaceId: 'count-9', raceNumber: 9, entrantCount: 9);

        $this->importResult($race7, 'race_result_normal.html', 'CONFIRMED');
        $this->importResult($race9, 'race_result_normal_9.html', 'CONFIRMED');

        $this->assertSame(7, RaceResult::query()->where('race_id', $race7->id)->count());
        $this->assertSame(9, RaceResult::query()->where('race_id', $race9->id)->count());
    }

    public function test_truncated_7_car_result_preserves_all_existing_data_and_audit_state(): void
    {
        Storage::fake('local');
        CarbonImmutable::setTestNow('2026-07-18 10:00:00');

        try {
            $race = $this->race(externalRaceId: 'truncated-7', entrantCount: 7);
            $this->createRaceEntries($race, range(1, 7));
            $this->importResult($race, 'race_result_normal.html', 'CONFIRMED');
            $beforeResults = $this->resultSnapshot($race);
            $beforePayouts = $this->payoutSnapshot($race);
            $beforeRace = $race->refresh()->only(['result_status', 'result_url', 'result_confirmed_at', 'last_fetched_at']);

            CarbonImmutable::setTestNow('2026-07-18 11:00:00');
            $this->importResult($race, 'race_result_truncated_2_of_7.html', 'CORRECTED', expectedExitCode: 1);

            $this->assertSame($beforeResults, $this->resultSnapshot($race));
            $this->assertSame($beforePayouts, $this->payoutSnapshot($race));
            $this->assertEquals($beforeRace, $race->refresh()->only(['result_status', 'result_url', 'result_confirmed_at', 'last_fetched_at']));
            $this->assertSame('FAILED', RaceResultImport::query()->latest('id')->firstOrFail()->import_status);
            $this->assertSame('FAILED', BatchRunItem::query()->latest('id')->firstOrFail()->status);
            $run = BatchRun::query()->latest('id')->firstOrFail();
            $this->assertSame([0, 0, 1], [$run->success_count, $run->skipped_count, $run->failure_count]);
            $this->assertSame(0, BatchRunItem::query()->where('status', 'RUNNING')->count());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_truncated_9_car_result_is_rejected_against_race_entries(): void
    {
        Storage::fake('local');
        $race = $this->race(externalRaceId: 'truncated-9', entrantCount: 9);
        $this->createRaceEntries($race, range(1, 9));

        $this->importResult($race, 'race_result_truncated_8_of_9.html', 'CONFIRMED', expectedExitCode: 1);

        $this->assertSame(0, RaceResult::query()->where('race_id', $race->id)->count());
        $this->assertSame('UNAVAILABLE', $race->refresh()->result_status);
        $this->assertSame('FAILED', RaceResultImport::query()->latest('id')->firstOrFail()->import_status);
    }

    public function test_equal_count_with_wrong_bike_set_is_rejected(): void
    {
        Storage::fake('local');
        $race = $this->race(externalRaceId: 'wrong-bike-set', entrantCount: 7);
        $this->createRaceEntries($race, range(1, 7));

        $this->importResult($race, 'race_result_wrong_bike_set.html', 'CONFIRMED', expectedExitCode: 1);

        $this->assertSame(0, RaceResult::query()->where('race_id', $race->id)->count());
        $this->assertSame(0, RacePayout::query()->where('race_id', $race->id)->count());
        $this->assertSame('UNAVAILABLE', $race->refresh()->result_status);
    }

    public function test_invalid_race_entry_expectations_are_rejected(): void
    {
        Storage::fake('local');
        $outOfRange = $this->race(externalRaceId: 'entry-out-of-range', entrantCount: 7);
        $this->createRaceEntries($outOfRange, [1, 2, 3, 4, 5, 6, 10]);
        $mismatched = $this->race(externalRaceId: 'entry-count-mismatch', entrantCount: 9);
        $this->createRaceEntries($mismatched, range(1, 7));
        $unsupported = $this->race(externalRaceId: 'entry-count-unsupported', entrantCount: 6);
        $this->createRaceEntries($unsupported, range(1, 6));

        foreach ([$outOfRange, $mismatched, $unsupported] as $race) {
            $this->importResult($race, 'race_result_normal.html', 'CONFIRMED', expectedExitCode: 1);
            $this->assertSame(0, RaceResult::query()->where('race_id', $race->id)->count());
        }
    }

    public function test_duplicate_race_entry_bike_number_is_rejected_by_database_constraint(): void
    {
        $race = $this->race(externalRaceId: 'entry-bike-duplicate', entrantCount: 7);
        $this->createRaceEntries($race, [1]);

        $this->expectException(QueryException::class);

        $this->createRaceEntries($race, [1]);
    }

    public function test_missing_or_unsupported_entrant_count_is_rejected_without_entries(): void
    {
        Storage::fake('local');
        $missing = $this->race(externalRaceId: 'count-missing', entrantCount: null);
        $unsupported = $this->race(externalRaceId: 'count-unsupported', entrantCount: 6);
        $truncated = $this->race(externalRaceId: 'count-truncated', entrantCount: 7);

        $this->importResult($missing, 'race_result_normal.html', 'CONFIRMED', expectedExitCode: 1);
        $this->importResult($unsupported, 'race_result_normal.html', 'CONFIRMED', expectedExitCode: 1);
        $this->importResult($truncated, 'race_result_truncated_2_of_7.html', 'CONFIRMED', expectedExitCode: 1);

        $this->assertSame(0, RaceResult::query()->whereIn('race_id', [$missing->id, $unsupported->id, $truncated->id])->count());
        $this->assertSame(3, RaceResultImport::query()->where('import_status', 'FAILED')->count());
    }

    public function test_confirmed_at_is_preserved_across_confirmed_and_corrected_reimports(): void
    {
        Storage::fake('local');
        $race = $this->race(externalRaceId: 'confirmed-at', entrantCount: 7);

        try {
            CarbonImmutable::setTestNow('2026-07-18 10:00:00');
            $this->importResult($race, 'race_result_normal.html', 'CONFIRMED');
            $confirmedAt = $race->refresh()->result_confirmed_at;
            $firstFetchedAt = $race->last_fetched_at;

            CarbonImmutable::setTestNow('2026-07-18 11:00:00');
            $this->importResult($race, 'race_result_normal.html', 'CONFIRMED');
            $this->assertEquals($confirmedAt, $race->refresh()->result_confirmed_at);
            $this->assertGreaterThan($firstFetchedAt, $race->last_fetched_at);

            CarbonImmutable::setTestNow('2026-07-18 12:00:00');
            $this->importResult($race, 'race_result_corrected.html', 'CORRECTED');
            $this->assertEquals($confirmedAt, $race->refresh()->result_confirmed_at);

            CarbonImmutable::setTestNow('2026-07-18 13:00:00');
            $this->importResult($race, 'race_result_corrected.html', 'CORRECTED');
            $this->assertEquals($confirmedAt, $race->refresh()->result_confirmed_at);
            $this->assertEquals(CarbonImmutable::parse('2026-07-18 13:00:00'), $race->last_fetched_at);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_missing_target_race_fails(): void
    {
        Storage::fake('local');
        $this->artisan('keirin:races:import-results', [
            '--race-id' => '999',
            '--raw-file' => base_path('tests/Fixtures/Keirin/synthetic/race_result_normal.html'),
            '--source-url' => 'https://example.invalid/result',
            '--result-status' => 'CONFIRMED',
        ])->assertExitCode(1);

        $this->assertSame(1, RaceResultImport::query()->whereNull('race_id')->where('import_status', 'FAILED')->count());
    }

    public function test_schedule_sync_rejects_invalid_date_range(): void
    {
        $this->artisan('keirin:races:sync-schedule', [
            '--from' => '2026-07-31',
            '--to' => '2026-07-01',
            '--raw-file' => base_path('tests/Fixtures/Keirin/actual/race_schedule_2026_07.html'),
        ])->assertExitCode(1);
    }

    public function test_raw_schedule_parser_failure_does_not_leave_running_item(): void
    {
        $this->artisan('keirin:races:sync-schedule', [
            '--from' => '2026-07-01',
            '--to' => '2026-07-31',
            '--raw-file' => base_path('tests/Fixtures/Keirin/synthetic/race_schedule_invalid.html'),
        ])->assertExitCode(1);

        $this->assertSame(0, RaceMeeting::query()->count());
        $this->assertSame(1, BatchRunItem::query()->where('item_type', 'RACE_SCHEDULE_MONTH')->where('status', 'FAILED')->count());
        $this->assertSame(0, BatchRunItem::query()->where('status', 'RUNNING')->count());
    }

    public function test_result_import_rejects_conflicting_race_targets(): void
    {
        $race = $this->race();

        $this->artisan('keirin:races:import-results', [
            '--race-id' => (string) $race->id,
            '--external-race-id' => $race->external_race_id,
            '--raw-file' => base_path('tests/Fixtures/Keirin/synthetic/race_result_normal.html'),
            '--source-url' => 'https://example.invalid/result',
        ])->assertExitCode(1);
    }

    public function test_result_import_requires_explicit_result_status(): void
    {
        $race = $this->race();

        $this->artisan('keirin:races:import-results', [
            '--race-id' => (string) $race->id,
            '--raw-file' => base_path('tests/Fixtures/Keirin/synthetic/race_result_normal.html'),
            '--source-url' => 'https://example.invalid/result',
        ])->assertExitCode(1);
    }

    public function test_unavailable_result_is_skipped_with_consistent_batch_counts(): void
    {
        Storage::fake('local');
        $race = $this->race();

        $this->artisan('keirin:races:import-results', [
            '--race-id' => (string) $race->id,
            '--raw-file' => base_path('tests/Fixtures/Keirin/synthetic/race_result_unavailable.html'),
            '--source-url' => 'https://example.invalid/unavailable',
            '--result-status' => 'UNAVAILABLE',
        ])->expectsOutputToContain('import_status=SKIPPED')->assertExitCode(0);

        $run = BatchRun::query()->latest('id')->firstOrFail();
        $this->assertSame(0, $run->success_count);
        $this->assertSame(1, $run->skipped_count);
        $this->assertSame(0, $run->failure_count);
        $this->assertSame('SUCCEEDED', $run->status);
        $this->assertSame('SKIPPED', $run->items()->firstOrFail()->status);
        $this->assertSame(1, RaceResultImport::query()->where('race_id', $race->id)->where('import_status', 'SKIPPED')->count());
        $this->assertSame(0, BatchRunItem::query()->where('status', 'RUNNING')->count());
    }

    public function test_under_review_result_is_skipped_with_consistent_batch_counts(): void
    {
        Storage::fake('local');
        $race = $this->race();

        $this->artisan('keirin:races:import-results', [
            '--race-id' => (string) $race->id,
            '--raw-file' => base_path('tests/Fixtures/Keirin/synthetic/race_result_under_review.html'),
            '--source-url' => 'https://example.invalid/under-review',
            '--result-status' => 'UNDER_REVIEW',
        ])->expectsOutputToContain('import_status=SKIPPED')->assertExitCode(0);

        $run = BatchRun::query()->latest('id')->firstOrFail();
        $this->assertSame('UNDER_REVIEW', $race->refresh()->result_status);
        $this->assertSame([0, 1, 0], [$run->success_count, $run->skipped_count, $run->failure_count]);
        $this->assertSame('SKIPPED', $run->items()->firstOrFail()->status);
        $this->assertSame(0, BatchRunItem::query()->where('status', 'RUNNING')->count());
    }

    public function test_confirmed_result_rejects_regressions_and_preserves_data(): void
    {
        Storage::fake('local');
        $race = $this->race();

        $this->artisan('keirin:races:import-results', [
            '--race-id' => (string) $race->id,
            '--raw-file' => base_path('tests/Fixtures/Keirin/synthetic/race_result_normal.html'),
            '--source-url' => 'https://example.invalid/result',
            '--result-status' => 'CONFIRMED',
        ])->assertExitCode(0);

        $regressions = [
            ['race_result_unavailable.html', 'UNAVAILABLE'],
            ['race_result_under_review.html', 'UNDER_REVIEW'],
            ['race_result_normal.html', 'PROVISIONAL'],
            ['race_result_cancelled.html', 'CANCELLED'],
        ];

        foreach ($regressions as [$fixture, $status]) {
            $this->artisan('keirin:races:import-results', [
                '--race-id' => (string) $race->id,
                '--raw-file' => base_path('tests/Fixtures/Keirin/synthetic/'.$fixture),
                '--source-url' => 'https://example.invalid/regression',
                '--result-status' => $status,
            ])->assertExitCode(1);
        }

        $this->assertSame(7, RaceResult::query()->where('race_id', $race->id)->count());
        $this->assertSame(3, RacePayout::query()->where('race_id', $race->id)->count());
        $this->assertSame('CONFIRMED', $race->refresh()->result_status);
        $this->assertSame(4, RaceResultImport::query()->where('race_id', $race->id)->where('import_status', 'FAILED')->count());
        $this->assertSame(4, BatchRunItem::query()->where('status', 'FAILED')->count());
    }

    public function test_cancelled_result_requires_marker_and_clears_stale_race_fields(): void
    {
        Storage::fake('local');
        $race = $this->race();

        $this->artisan('keirin:races:import-results', [
            '--race-id' => (string) $race->id,
            '--raw-file' => base_path('tests/Fixtures/Keirin/synthetic/race_result_normal.html'),
            '--source-url' => 'https://example.invalid/provisional',
            '--result-status' => 'PROVISIONAL',
        ])->assertExitCode(0);

        $race->forceFill(['result_confirmed_at' => now()])->save();

        $this->artisan('keirin:races:import-results', [
            '--race-id' => (string) $race->id,
            '--raw-file' => base_path('tests/Fixtures/Keirin/synthetic/race_result_normal.html'),
            '--source-url' => 'https://example.invalid/not-cancelled',
            '--result-status' => 'CANCELLED',
        ])->assertExitCode(1);

        $this->assertSame(7, RaceResult::query()->where('race_id', $race->id)->count());

        $this->artisan('keirin:races:import-results', [
            '--race-id' => (string) $race->id,
            '--raw-file' => base_path('tests/Fixtures/Keirin/synthetic/race_result_cancelled.html'),
            '--source-url' => 'https://example.invalid/cancelled',
            '--result-status' => 'CANCELLED',
        ])->assertExitCode(0);

        $this->assertSame(0, RaceResult::query()->where('race_id', $race->id)->count());
        $this->assertSame(0, RacePayout::query()->where('race_id', $race->id)->count());
        $this->assertSame('CANCELLED', $race->refresh()->result_status);
        $this->assertSame('https://example.invalid/cancelled', $race->result_url);
        $this->assertNull($race->result_confirmed_at);
        $this->assertNotNull($race->last_fetched_at);
    }

    public function test_partial_result_parser_failure_preserves_all_existing_data_and_status(): void
    {
        Storage::fake('local');
        $race = $this->race();

        $this->artisan('keirin:races:import-results', [
            '--race-id' => (string) $race->id,
            '--raw-file' => base_path('tests/Fixtures/Keirin/synthetic/race_result_normal.html'),
            '--source-url' => 'https://example.invalid/result',
            '--result-status' => 'CONFIRMED',
        ])->assertExitCode(0);

        $this->artisan('keirin:races:import-results', [
            '--race-id' => (string) $race->id,
            '--raw-file' => base_path('tests/Fixtures/Keirin/synthetic/race_result_partial_invalid.html'),
            '--source-url' => 'https://example.invalid/bad',
            '--result-status' => 'CONFIRMED',
        ])->assertExitCode(1);

        $this->assertSame(7, RaceResult::query()->where('race_id', $race->id)->count());
        $this->assertSame(3, RacePayout::query()->where('race_id', $race->id)->count());
        $this->assertSame('CONFIRMED', $race->refresh()->result_status);
        $this->assertSame(1, RaceResultImport::query()->where('race_id', $race->id)->where('import_status', 'FAILED')->count());
        $this->assertSame(1, BatchRun::query()->where('failure_count', 1)->count());
    }

    public function test_external_race_id_lookup_is_scoped_to_configured_source(): void
    {
        Storage::fake('local');
        $target = $this->race('keirin_jp', 'shared-race', 1);
        $other = $this->race('other_source', 'shared-race', 2);

        $this->artisan('keirin:races:import-results', [
            '--external-race-id' => 'shared-race',
            '--raw-file' => base_path('tests/Fixtures/Keirin/synthetic/race_result_normal.html'),
            '--source-url' => 'https://example.invalid/result',
            '--result-status' => 'CONFIRMED',
        ])->assertExitCode(0);

        $this->assertSame(7, RaceResult::query()->where('race_id', $target->id)->count());
        $this->assertSame(0, RaceResult::query()->where('race_id', $other->id)->count());

        $this->race('other_source', 'other-only-race', 3);
        $this->artisan('keirin:races:import-results', [
            '--external-race-id' => 'other-only-race',
            '--raw-file' => base_path('tests/Fixtures/Keirin/synthetic/race_result_normal.html'),
            '--source-url' => 'https://example.invalid/result',
            '--result-status' => 'CONFIRMED',
        ])->assertExitCode(1);
    }

    public function test_cp932_raw_file_is_converted_and_audited(): void
    {
        Storage::fake('local');
        $race = $this->race();
        $utf8 = file_get_contents(base_path('tests/Fixtures/Keirin/synthetic/race_result_normal.html'));
        $cp932 = mb_convert_encoding($utf8, 'CP932', 'UTF-8');
        $rawFile = $this->temporaryRawFile($cp932);

        $this->artisan('keirin:races:import-results', [
            '--race-id' => (string) $race->id,
            '--raw-file' => $rawFile,
            '--source-url' => 'https://example.invalid/cp932',
            '--result-status' => 'CONFIRMED',
        ])->assertExitCode(0);

        $import = RaceResultImport::query()->latest('id')->firstOrFail();
        $this->assertSame('CP932', $import->detected_encoding);
        $this->assertTrue($import->utf8_conversion_succeeded);
        $this->assertSame(hash('sha256', $cp932), $import->source_hash);
        $this->assertSame(hash('sha256', $utf8), $import->converted_hash);
        $this->assertSame(strlen($cp932), $import->raw_response_size);
        $this->assertSame(7, RaceResult::query()->where('race_id', $race->id)->count());
    }

    public function test_encoding_failures_do_not_call_parser_or_change_existing_data(): void
    {
        Storage::fake('local');
        $race = $this->race();
        $this->importResult($race, 'race_result_normal.html', 'CONFIRMED');

        $parser = Mockery::mock(RaceResultPageParser::class);
        $parser->shouldNotReceive('parse');
        $this->app->instance(RaceResultPageParser::class, $parser);

        $invalidUtf8 = $this->temporaryRawFile('<meta charset="UTF-8"><body>'."\xFF".'</body>');
        $undetectable = $this->temporaryRawFile("\xFF");

        foreach ([$invalidUtf8, $undetectable] as $rawFile) {
            $this->artisan('keirin:races:import-results', [
                '--race-id' => (string) $race->id,
                '--raw-file' => $rawFile,
                '--source-url' => 'https://example.invalid/invalid-encoding',
                '--result-status' => 'CONFIRMED',
            ])->assertExitCode(1);
        }

        $this->assertSame(7, RaceResult::query()->where('race_id', $race->id)->count());
        $this->assertSame(3, RacePayout::query()->where('race_id', $race->id)->count());
        $this->assertSame('CONFIRMED', $race->refresh()->result_status);
        $this->assertSame(2, RaceResultImport::query()
            ->where('import_status', 'FAILED')
            ->where('utf8_conversion_succeeded', false)
            ->where('error_type', 'ENCODING_CONVERSION_FAILED')
            ->count());
        $this->assertSame(2, RaceResultImport::query()->where('import_status', 'FAILED')->whereNotNull('raw_file_path')->count());
    }

    public function test_import_success_audit_failure_rolls_back_results_and_race(): void
    {
        Storage::fake('local');
        $race = $this->race();
        $event = 'eloquent.saving: '.RaceResultImport::class;
        Event::listen($event, static function (RaceResultImport $import): void {
            if ($import->import_status === 'SUCCEEDED') {
                throw new RuntimeException('audit failure');
            }
        });

        try {
            $this->importResult($race, 'race_result_normal.html', 'CONFIRMED', expectedExitCode: 1);
        } finally {
            Event::forget($event);
        }

        $this->assertSame(0, RaceResult::query()->where('race_id', $race->id)->count());
        $this->assertSame(0, RacePayout::query()->where('race_id', $race->id)->count());
        $this->assertSame('UNAVAILABLE', $race->refresh()->result_status);
        $this->assertSame('FAILED', RaceResultImport::query()->latest('id')->firstOrFail()->import_status);
    }

    public function test_result_failure_mid_sync_rolls_back_everything(): void
    {
        Storage::fake('local');
        $race = $this->race();
        $event = 'eloquent.saved: '.RaceResult::class;
        $saved = 0;
        Event::listen($event, static function () use (&$saved): void {
            if (++$saved === 2) {
                throw new RuntimeException('result failure');
            }
        });

        try {
            $this->importResult($race, 'race_result_normal.html', 'CONFIRMED', expectedExitCode: 1);
        } finally {
            Event::forget($event);
        }

        $this->assertSame(0, RaceResult::query()->where('race_id', $race->id)->count());
        $this->assertSame(0, RacePayout::query()->where('race_id', $race->id)->count());
        $this->assertSame('UNAVAILABLE', $race->refresh()->result_status);
        $this->assertSame('FAILED', RaceResultImport::query()->latest('id')->firstOrFail()->import_status);
    }

    public function test_payout_failure_mid_sync_rolls_back_results_and_payouts(): void
    {
        Storage::fake('local');
        $race = $this->race();
        $event = 'eloquent.saved: '.RacePayout::class;
        $saved = 0;
        Event::listen($event, static function () use (&$saved): void {
            if (++$saved === 2) {
                throw new RuntimeException('payout failure');
            }
        });

        try {
            $this->importResult($race, 'race_result_normal.html', 'CONFIRMED', expectedExitCode: 1);
        } finally {
            Event::forget($event);
        }

        $this->assertSame(0, RaceResult::query()->where('race_id', $race->id)->count());
        $this->assertSame(0, RacePayout::query()->where('race_id', $race->id)->count());
        $this->assertSame('UNAVAILABLE', $race->refresh()->result_status);
        $this->assertSame('FAILED', RaceResultImport::query()->latest('id')->firstOrFail()->import_status);
    }

    public function test_race_update_failure_rolls_back_results_and_payouts(): void
    {
        Storage::fake('local');
        $race = $this->race();
        $event = 'eloquent.saving: '.Race::class;
        Event::listen($event, static function (Race $savingRace): void {
            if ($savingRace->isDirty('result_status') && $savingRace->result_status === 'CONFIRMED') {
                throw new RuntimeException('race failure');
            }
        });

        try {
            $this->importResult($race, 'race_result_normal.html', 'CONFIRMED', expectedExitCode: 1);
        } finally {
            Event::forget($event);
        }

        $this->assertSame(0, RaceResult::query()->where('race_id', $race->id)->count());
        $this->assertSame(0, RacePayout::query()->where('race_id', $race->id)->count());
        $this->assertSame('UNAVAILABLE', $race->refresh()->result_status);
        $this->assertSame('FAILED', RaceResultImport::query()->latest('id')->firstOrFail()->import_status);
    }

    public function test_cancelled_failure_rolls_back_result_deletion(): void
    {
        Storage::fake('local');
        $race = $this->race();
        $this->importResult($race, 'race_result_normal.html', 'PROVISIONAL');
        $event = 'eloquent.deleting: '.RacePayout::class;
        Event::listen($event, static function (): void {
            throw new RuntimeException('cancel failure');
        });

        try {
            $this->importResult($race, 'race_result_cancelled.html', 'CANCELLED', expectedExitCode: 1);
        } finally {
            Event::forget($event);
        }

        $this->assertSame(7, RaceResult::query()->where('race_id', $race->id)->count());
        $this->assertSame(3, RacePayout::query()->where('race_id', $race->id)->count());
        $this->assertSame('PROVISIONAL', $race->refresh()->result_status);
        $this->assertSame('FAILED', RaceResultImport::query()->latest('id')->firstOrFail()->import_status);
    }

    private function importResult(Race $race, string $fixture, string $status, int $expectedExitCode = 0): void
    {
        $this->artisan('keirin:races:import-results', [
            '--race-id' => (string) $race->id,
            '--raw-file' => base_path('tests/Fixtures/Keirin/synthetic/'.$fixture),
            '--source-url' => 'https://example.invalid/'.$status,
            '--result-status' => $status,
        ])->assertExitCode($expectedExitCode);
    }

    private function temporaryRawFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'keirin-result-');
        if (! is_string($path) || file_put_contents($path, $contents) === false) {
            $this->fail('Failed to create a temporary raw result file.');
        }

        $this->beforeApplicationDestroyed(static function () use ($path): void {
            if (is_file($path)) {
                unlink($path);
            }
        });

        return $path;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function resultSnapshot(Race $race): array
    {
        return RaceResult::query()
            ->where('race_id', $race->id)
            ->orderBy('bike_number')
            ->get(['bike_number', 'rank', 'result_status', 'raw_result_text', 'source_url'])
            ->map(fn (RaceResult $result): array => $result->toArray())
            ->all();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function payoutSnapshot(Race $race): array
    {
        return RacePayout::query()
            ->where('race_id', $race->id)
            ->orderBy('bet_type_code')
            ->orderBy('combination')
            ->get(['bet_type_code', 'combination', 'payout_amount', 'popularity', 'sequence', 'source_url'])
            ->map(fn (RacePayout $payout): array => $payout->toArray())
            ->all();
    }

    private function race(
        string $source = 'keirin_jp',
        string $externalRaceId = 'test-race-1',
        int $raceNumber = 1,
        ?int $entrantCount = 7,
    ): Race {
        $track = Racetrack::query()->firstOrCreate([
            'source' => $source,
            'external_track_id' => '99',
        ], [
            'name' => 'テスト',
        ]);

        return Race::query()->create([
            'source' => $source,
            'external_race_id' => $externalRaceId,
            'racetrack_id' => $track->id,
            'race_date' => '2026-07-18',
            'race_number' => $raceNumber,
            'entrant_count' => $entrantCount,
            'result_status' => 'UNAVAILABLE',
        ]);
    }

    /**
     * @param  list<int>  $bikeNumbers
     */
    private function createRaceEntries(Race $race, array $bikeNumbers): void
    {
        foreach ($bikeNumbers as $bikeNumber) {
            RaceEntry::query()->create([
                'race_id' => $race->id,
                'external_player_id' => 'player-'.$bikeNumber,
                'bike_number' => $bikeNumber,
                'fetched_at' => now(),
            ]);
        }
    }
}
