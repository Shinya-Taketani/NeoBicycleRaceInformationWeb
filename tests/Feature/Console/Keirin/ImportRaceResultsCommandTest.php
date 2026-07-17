<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

use App\Models\BatchRunItem;
use App\Models\Race;
use App\Models\RaceDay;
use App\Models\RaceMeeting;
use App\Models\RacePayout;
use App\Models\RaceResult;
use App\Models\RaceResultImport;
use App\Models\Racetrack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
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
        $this->assertSame($import->id, RaceResult::query()->where('race_id', $race->id)->firstOrFail()->race_result_import_id);
        $this->assertSame($import->id, RacePayout::query()->where('race_id', $race->id)->firstOrFail()->race_result_import_id);
    }

    public function test_corrected_result_removes_stale_rows(): void
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

        $this->assertSame(2, RaceResult::query()->where('race_id', $race->id)->count());
        $this->assertSame(1, RacePayout::query()->where('race_id', $race->id)->count());
        $this->assertSame(1, RaceResult::query()->where('race_id', $race->id)->where('bike_number', 2)->where('rank', 1)->count());
        $this->assertSame('CORRECTED', $race->refresh()->result_status);
        $this->assertSame(2, RaceResultImport::query()->where('race_id', $race->id)->count());
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

    public function test_unavailable_result_does_not_delete_existing_confirmed_data(): void
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
            '--raw-file' => base_path('tests/Fixtures/Keirin/synthetic/race_result_unavailable.html'),
            '--source-url' => 'https://example.invalid/unavailable',
            '--result-status' => 'UNAVAILABLE',
        ])->assertExitCode(0);

        $this->assertSame(7, RaceResult::query()->where('race_id', $race->id)->count());
        $this->assertSame(3, RacePayout::query()->where('race_id', $race->id)->count());
        $this->assertSame('CONFIRMED', $race->refresh()->result_status);
        $this->assertSame(1, RaceResultImport::query()->where('race_id', $race->id)->where('import_status', 'SKIPPED')->count());
    }

    public function test_under_review_result_does_not_delete_existing_confirmed_data(): void
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
            '--raw-file' => base_path('tests/Fixtures/Keirin/synthetic/race_result_under_review.html'),
            '--source-url' => 'https://example.invalid/under-review',
            '--result-status' => 'UNDER_REVIEW',
        ])->assertExitCode(0);

        $this->assertSame(7, RaceResult::query()->where('race_id', $race->id)->count());
        $this->assertSame(3, RacePayout::query()->where('race_id', $race->id)->count());
        $this->assertSame('CONFIRMED', $race->refresh()->result_status);
    }

    public function test_cancelled_result_clears_existing_data_only_when_explicitly_requested(): void
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
            '--raw-file' => base_path('tests/Fixtures/Keirin/synthetic/race_result_cancelled.html'),
            '--source-url' => 'https://example.invalid/cancelled',
            '--result-status' => 'UNDER_REVIEW',
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
    }

    public function test_parser_failure_keeps_existing_data_and_records_failed_import(): void
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
            '--raw-file' => base_path('tests/Fixtures/Keirin/synthetic/payout_unknown_type.html'),
            '--source-url' => 'https://example.invalid/bad',
            '--result-status' => 'CONFIRMED',
        ])->assertExitCode(1);

        $this->assertSame(7, RaceResult::query()->where('race_id', $race->id)->count());
        $this->assertSame(3, RacePayout::query()->where('race_id', $race->id)->count());
        $this->assertSame(1, RaceResultImport::query()->where('race_id', $race->id)->where('import_status', 'FAILED')->count());
    }

    private function race(): Race
    {
        $track = Racetrack::query()->create([
            'source' => 'keirin_jp',
            'external_track_id' => '99',
            'name' => 'テスト',
        ]);

        return Race::query()->create([
            'source' => 'keirin_jp',
            'external_race_id' => 'test-race-1',
            'racetrack_id' => $track->id,
            'race_date' => '2026-07-18',
            'race_number' => 1,
            'result_status' => 'UNAVAILABLE',
        ]);
    }
}
