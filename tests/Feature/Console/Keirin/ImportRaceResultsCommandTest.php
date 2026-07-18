<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

use App\Domain\Keirin\Scraping\Parsers\RaceResultPageParser;
use App\Models\BatchRun;
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
        $this->assertSame(2, RaceResult::query()->where('race_id', $race->id)->count());
        $this->assertSame(1, RacePayout::query()->where('race_id', $race->id)->count());
        $this->assertSame(2, RaceResultImport::query()->where('race_id', $race->id)->where('import_status', 'FAILED')->count());
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

    private function race(string $source = 'keirin_jp', string $externalRaceId = 'test-race-1', int $raceNumber = 1): Race
    {
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
            'result_status' => 'UNAVAILABLE',
        ]);
    }
}
