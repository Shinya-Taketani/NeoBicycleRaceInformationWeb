<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

use App\Domain\Keirin\Scraping\Parsers\EmbeddedJsonExtractor;
use App\Domain\Keirin\Scraping\Parsers\RaceListConsistencyValidator;
use App\Domain\Keirin\Scraping\Parsers\RaceLiveResultParser;
use App\Models\BatchRun;
use App\Models\BatchRunItem;
use App\Models\Player;
use App\Models\Race;
use App\Models\RaceDay;
use App\Models\RaceEntry;
use App\Models\RaceMeeting;
use App\Models\RacePayout;
use App\Models\RaceResult;
use App\Models\RaceResultImport;
use App\Models\Racetrack;
use App\Models\ScrapingFetchLog;
use App\Repositories\RaceRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;

class SyncAutomatedRacesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_postponed_race_day_is_skipped_audited_and_idempotent_without_creating_races(): void
    {
        Storage::fake('local');
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        $this->meetingWithDays();
        $day = RaceDay::query()->whereDate('race_date', '2026-06-16')->firstOrFail();
        $dayIdentity = $this->raceDayIdentity($day);
        $postponedResponse = $this->fixture('race-sync-jsj017-postponed.json');
        $this->fakeRaceListResponses($postponedResponse);
        $arguments = [
            '--date' => '2026-06-16',
            '--sleep-ms' => '1',
        ];

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $exitCode = Artisan::call('keirin:races:sync-race-list', $arguments);
            $output = Artisan::output();
            $this->assertSame(0, $exitCode, $output);
            $this->assertStringContainsString(
                'days=0 races=0 entries=0 unresolved_players=0 skipped=1 failed=0',
                $output,
            );
        }

        $this->assertSame(0, Race::query()->count());
        $this->assertSame(0, RaceEntry::query()->count());
        $this->assertSame($dayIdentity, $this->raceDayIdentity($day->refresh()));
        $this->assertSame(2, BatchRun::query()->where('status', 'SUCCEEDED')->whereNotNull('finished_at')->count());
        $this->assertSame(0, BatchRun::query()->where('status', 'RUNNING')->count());

        $items = BatchRunItem::query()->where('item_type', 'RACE_ENTRY_LIST')->orderBy('id')->get();
        $this->assertCount(2, $items);
        foreach ($items as $item) {
            $this->assertSame('SKIPPED', $item->status);
            $this->assertSame('RACE_DAY_POSTPONED', $item->skip_reason);
            $this->assertSame((int) $day->id, $item->metadata['race_day_id']);
            $this->assertSame('2026-06-16', $item->metadata['race_date']);
            $this->assertSame('RACE_DAY_POSTPONED', $item->metadata['reason']);
            $this->assertSame('順延となりました。', $item->metadata['message']);
            $this->assertSame([
                'resultCd' => 0,
                'syusouDispFlag' => 0,
                'kaisaiMsg' => '順延となりました。',
            ], $item->metadata['evidence']);
            $this->assertNotEmpty($item->metadata['raw_file_path']);
        }

        $logs = ScrapingFetchLog::query()->where('request_url', 'like', '%type=JSJ017%')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        foreach ($logs as $log) {
            $this->assertSame($postponedResponse, Storage::disk('local')->get($log->raw_file_path));
        }
    }

    public function test_postponed_race_day_preserves_existing_race_entries_results_and_payouts_on_retries(): void
    {
        Storage::fake('local');
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        $race = $this->raceWithEntries(1, 'existing-postponed');
        $race->forceFill([
            'name' => '既存レース',
            'result_status' => 'CONFIRMED',
            'result_confirmed_at' => now(),
        ])->save();
        $entry = RaceEntry::query()->where('race_id', $race->id)->where('bike_number', 1)->firstOrFail();
        RaceResult::query()->create([
            'race_id' => $race->id,
            'race_entry_id' => $entry->id,
            'bike_number' => 1,
            'rank' => 1,
            'result_status' => 'FINISHED',
            'raw_result_text' => 'existing result',
            'source_url' => 'https://example.test/existing-result',
            'fetched_at' => now(),
        ]);
        RacePayout::query()->create([
            'race_id' => $race->id,
            'bet_type_code' => 'WIN',
            'combination' => '1',
            'payout_amount' => 120,
            'popularity' => 1,
            'sequence' => 1,
            'source_url' => 'https://example.test/existing-result',
            'fetched_at' => now(),
        ]);
        $day = RaceDay::query()->findOrFail($race->race_day_id);
        $before = [
            'day' => $this->raceDayIdentity($day),
            'race' => $race->refresh()->toArray(),
            'entries' => RaceEntry::query()->where('race_id', $race->id)->orderBy('id')->get()->toArray(),
            'results' => RaceResult::query()->where('race_id', $race->id)->orderBy('id')->get()->toArray(),
            'payouts' => RacePayout::query()->where('race_id', $race->id)->orderBy('id')->get()->toArray(),
        ];
        $this->fakeRaceListResponses($this->fixture('race-sync-jsj017-postponed.json'));
        $arguments = ['--date' => '2026-06-16', '--sleep-ms' => '1'];

        $this->artisan('keirin:races:sync-race-list', $arguments)->assertExitCode(0);
        $this->artisan('keirin:races:sync-race-list', $arguments)->assertExitCode(0);

        $this->assertSame($before['day'], $this->raceDayIdentity($day->refresh()));
        $this->assertSame($before['race'], $race->refresh()->toArray());
        $this->assertSame($before['entries'], RaceEntry::query()->where('race_id', $race->id)->orderBy('id')->get()->toArray());
        $this->assertSame($before['results'], RaceResult::query()->where('race_id', $race->id)->orderBy('id')->get()->toArray());
        $this->assertSame($before['payouts'], RacePayout::query()->where('race_id', $race->id)->orderBy('id')->get()->toArray());
        $this->assertSame('CONFIRMED', $race->refresh()->result_status);
    }

    public function test_sync_continues_with_the_next_race_day_after_a_postponed_day(): void
    {
        Storage::fake('local');
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        $this->meetingWithDays();
        $this->fakePostponedThenNormalRaceListResponses();

        $this->artisan('keirin:races:sync-race-list', [
            '--from' => '2026-06-16',
            '--to' => '2026-06-17',
            '--sleep-ms' => '1',
        ])->expectsOutputToContain('days=1 races=12 entries=78 unresolved_players=78 skipped=1 failed=0')
            ->assertExitCode(0);

        $this->assertSame(0, Race::query()->whereDate('race_date', '2026-06-16')->count());
        $this->assertSame(12, Race::query()->whereDate('race_date', '2026-06-17')->count());
        $this->assertDatabaseHas('batch_run_items', [
            'item_type' => 'RACE_ENTRY_LIST',
            'status' => 'SKIPPED',
            'skip_reason' => 'RACE_DAY_POSTPONED',
        ]);
    }

    public function test_cancelled_race_day_with_null_jsj001_races_is_skipped_and_audited_without_syncing(): void
    {
        Storage::fake('local');
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        $this->meetingWithDays();
        $day = RaceDay::query()->whereDate('race_date', '2026-06-16')->firstOrFail();
        $metadata = json_decode($this->fixture('race-sync-jsj001.json'), true, flags: JSON_THROW_ON_ERROR);
        $metadata['C0201data']['selRaceNo'] = 0;
        $metadata['C0201data']['C0201race'] = null;
        $metadataResponse = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $cancelledResponse = $this->fixture('race-sync-jsj017-cancelled.json');
        $this->mock(RaceListConsistencyValidator::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('validate');
        });
        $this->partialMock(RaceRepository::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('syncRaceDay');
        });
        $this->fakeRaceListResponses($cancelledResponse, $metadataResponse);

        $exitCode = Artisan::call('keirin:races:sync-race-list', [
            '--date' => '2026-06-16',
            '--sleep-ms' => '1',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString(
            'days=0 races=0 entries=0 unresolved_players=0 skipped=1 failed=0',
            $output,
        );
        $this->assertSame(0, Race::query()->count());
        $this->assertSame(0, RaceEntry::query()->count());
        $item = BatchRunItem::query()->where('item_type', 'RACE_ENTRY_LIST')->firstOrFail();
        $this->assertSame('SKIPPED', $item->status);
        $this->assertSame('RACE_DAY_CANCELLED', $item->skip_reason);
        $this->assertSame((int) $day->id, $item->metadata['race_day_id']);
        $this->assertSame('2026-06-16', $item->metadata['race_date']);
        $this->assertSame('RACE_DAY_CANCELLED', $item->metadata['reason']);
        $this->assertSame('中止となりました。', $item->metadata['message']);
        $this->assertSame('56', $item->metadata['evidence']['reqprm.bkcd']);
        $this->assertSame('20260616', $item->metadata['evidence']['reqprm.kday']);

        $metadataLog = ScrapingFetchLog::query()->where('request_url', 'like', '%type=JSJ001%')->firstOrFail();
        $entryLog = ScrapingFetchLog::query()->where('request_url', 'like', '%type=JSJ017%')->firstOrFail();
        $this->assertSame($entryLog->raw_file_path, $item->metadata['raw_file_path']);
        $this->assertSame($metadataLog->raw_file_path, $item->metadata['metadata_raw_file_path']);
        $this->assertSame($cancelledResponse, Storage::disk('local')->get($entryLog->raw_file_path));
        $this->assertSame($metadataResponse, Storage::disk('local')->get($metadataLog->raw_file_path));

        $run = BatchRun::query()->latest('id')->firstOrFail();
        $this->assertSame('SUCCEEDED', $run->status);
        $this->assertSame(0, $run->success_count);
        $this->assertSame(1, $run->skipped_count);
        $this->assertSame(0, $run->failure_count);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(0, BatchRun::query()->where('status', 'RUNNING')->count());
    }

    public function test_cancelled_race_day_with_normal_jsj001_preserves_existing_data_on_retries(): void
    {
        Storage::fake('local');
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        $player = Player::query()->create([
            'source' => 'keirin_jp',
            'external_player_id' => 'cancelled-player',
            'name' => '既存選手',
        ]);
        $race = $this->raceWithEntries(1, 'existing-cancelled');
        $race->forceFill([
            'name' => '既存中止日レース',
            'result_status' => 'CONFIRMED',
            'result_confirmed_at' => now(),
        ])->save();
        $entry = RaceEntry::query()->where('race_id', $race->id)->where('bike_number', 1)->firstOrFail();
        RaceResult::query()->create([
            'race_id' => $race->id,
            'race_entry_id' => $entry->id,
            'bike_number' => 1,
            'rank' => 1,
            'result_status' => 'FINISHED',
            'raw_result_text' => 'existing cancelled-day result',
            'source_url' => 'https://example.test/existing-result',
            'fetched_at' => now(),
        ]);
        RacePayout::query()->create([
            'race_id' => $race->id,
            'bet_type_code' => 'WIN',
            'combination' => '1',
            'payout_amount' => 130,
            'popularity' => 1,
            'sequence' => 1,
            'source_url' => 'https://example.test/existing-result',
            'fetched_at' => now(),
        ]);
        $day = RaceDay::query()->findOrFail($race->race_day_id);
        $before = [
            'day' => $this->raceDayIdentity($day),
            'player' => $player->refresh()->toArray(),
            'race' => $race->refresh()->toArray(),
            'entries' => RaceEntry::query()->where('race_id', $race->id)->orderBy('id')->get()->toArray(),
            'results' => RaceResult::query()->where('race_id', $race->id)->orderBy('id')->get()->toArray(),
            'payouts' => RacePayout::query()->where('race_id', $race->id)->orderBy('id')->get()->toArray(),
        ];
        $this->fakeRaceListResponses($this->fixture('race-sync-jsj017-cancelled.json'));
        $arguments = ['--date' => '2026-06-16', '--sleep-ms' => '1'];

        $this->artisan('keirin:races:sync-race-list', $arguments)
            ->expectsOutputToContain('days=0 races=0 entries=0 unresolved_players=0 skipped=1 failed=0')
            ->assertExitCode(0);
        $this->artisan('keirin:races:sync-race-list', $arguments)->assertExitCode(0);

        $this->assertSame($before['day'], $this->raceDayIdentity($day->refresh()));
        $this->assertSame($before['player'], $player->refresh()->toArray());
        $this->assertSame($before['race'], $race->refresh()->toArray());
        $this->assertSame($before['entries'], RaceEntry::query()->where('race_id', $race->id)->orderBy('id')->get()->toArray());
        $this->assertSame($before['results'], RaceResult::query()->where('race_id', $race->id)->orderBy('id')->get()->toArray());
        $this->assertSame($before['payouts'], RacePayout::query()->where('race_id', $race->id)->orderBy('id')->get()->toArray());
        $this->assertSame('CONFIRMED', $race->refresh()->result_status);
        $this->assertSame(2, BatchRunItem::query()->where('status', 'SKIPPED')->where('skip_reason', 'RACE_DAY_CANCELLED')->count());
        $this->assertSame(0, BatchRun::query()->where('status', 'RUNNING')->count());
    }

    public function test_cancelled_response_for_a_different_date_or_track_fails_instead_of_skipping(): void
    {
        Storage::fake('local');
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        $this->meetingWithDays();
        $fixture = json_decode($this->fixture('race-sync-jsj017-cancelled.json'), true, flags: JSON_THROW_ON_ERROR);
        $cases = [
            'different date' => function (array &$json): void {
                $json['reqprm']['kday'] = '20260617';
            },
            'different track' => function (array &$json): void {
                $json['reqprm']['bkcd'] = '57';
            },
        ];

        foreach ($cases as $case => $mutate) {
            $json = $fixture;
            $mutate($json);
            $this->fakeRaceListResponses(json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $exitCode = Artisan::call('keirin:races:sync-race-list', [
                '--date' => '2026-06-16',
                '--sleep-ms' => '1',
            ]);
            $output = Artisan::output();

            $this->assertSame(1, $exitCode, $case.': '.$output);
            $this->assertStringContainsString('skipped=0 failed=1', $output);
            $item = BatchRunItem::query()->latest('id')->firstOrFail();
            $this->assertSame('FAILED', $item->status);
            $this->assertSame('RACE_DAY_CANCELLED', $item->metadata['reason']);
            $this->assertStringContainsString('did not match the target race day', (string) $item->error_message);
        }

        $this->assertSame(0, Race::query()->count());
        $this->assertSame(0, RaceEntry::query()->count());
        $this->assertSame(0, BatchRun::query()->where('status', 'RUNNING')->count());
    }

    public function test_race_list_sync_is_idempotent_and_preserves_unresolved_players(): void
    {
        Storage::fake('local');
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        $this->meetingWithDays();
        Player::query()->create([
            'source' => 'keirin_jp',
            'external_player_id' => '000001',
            'registration_number' => '000001',
            'name' => '登録済み選手',
        ]);
        $this->fakeRaceListResponses();

        $arguments = [
            '--from' => '2026-06-16',
            '--to' => '2026-06-16',
            '--sleep-ms' => '1',
        ];
        $this->assertSame(0, Artisan::call('keirin:races:sync-race-list', $arguments), Artisan::output());
        $this->assertSame(0, Artisan::call('keirin:races:sync-race-list', $arguments), Artisan::output());

        $this->assertSame(12, Race::query()->count());
        $this->assertSame(78, RaceEntry::query()->count());
        $this->assertSame(1, Player::query()->count());
        $this->assertDatabaseHas('races', [
            'external_race_id' => '56:20260616:01',
            'encrypted_parameter' => 'enc-r1',
            'entrant_count' => 7,
            'result_available' => true,
        ]);
        $this->assertDatabaseHas('races', [
            'external_race_id' => '56:20260616:12',
            'encrypted_parameter' => 'enc-r12',
        ]);
        $this->assertDatabaseHas('races', [
            'external_race_id' => '56:20260616:03',
            'entrant_count' => 8,
        ]);
        $eightCarRace = Race::query()->where('external_race_id', '56:20260616:03')->firstOrFail();
        $this->assertSame(8, RaceEntry::query()->where('race_id', $eightCarRace->id)->count());
        $this->assertSame(
            range(1, 8),
            RaceEntry::query()->where('race_id', $eightCarRace->id)->orderBy('bike_number')->pluck('bike_number')->all(),
        );
        $this->assertDatabaseHas('race_entries', [
            'external_player_id' => '000001',
            'player_id' => Player::query()->value('id'),
        ]);
        $this->assertDatabaseHas('race_entries', [
            'external_player_id' => '000002',
            'player_id' => null,
        ]);
        $this->assertSame(6, RaceDay::query()->whereNotNull('encrypted_parameter')->count());
        $this->assertSame(4, ScrapingFetchLog::query()->where('request_method', 'GET')->count());
        $this->assertSame(2, ScrapingFetchLog::query()->where('request_method', 'POST')->count());
        $this->assertNotNull(ScrapingFetchLog::query()->where('request_method', 'POST')->firstOrFail()->request_parameters);
        Http::assertSent(fn (Request $request): bool => ! str_contains($request->url(), '/pc/racelist')
            || $request->hasHeader('Referer', 'https://keirin.jp/pc/raceschedule'));
    }

    public function test_five_car_race_entries_sync_idempotently_without_breaking_other_races(): void
    {
        Storage::fake('local');
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        $this->meetingWithDays();
        $entries = json_decode($this->fixture('race-sync-jsj017.json'), true, flags: JSON_THROW_ON_ERROR);
        $entries['rInfo'][0]['sInfo'] = array_slice($entries['rInfo'][0]['sInfo'], 0, 5);
        $this->fakeRaceListResponses(json_encode($entries, JSON_THROW_ON_ERROR));
        $arguments = [
            '--from' => '2026-06-16',
            '--to' => '2026-06-16',
            '--sleep-ms' => '1',
        ];

        $this->artisan('keirin:races:sync-race-list', $arguments)->assertExitCode(0);
        $fiveCarRace = Race::query()->where('external_race_id', '56:20260616:01')->firstOrFail();
        $otherRace = Race::query()->where('external_race_id', '56:20260616:02')->firstOrFail();
        $fiveCarEntryIds = RaceEntry::query()->where('race_id', $fiveCarRace->id)->orderBy('bike_number')->pluck('id')->all();

        $this->artisan('keirin:races:sync-race-list', $arguments)->assertExitCode(0);

        $this->assertSame(5, $fiveCarRace->refresh()->entrant_count);
        $this->assertSame(range(1, 5), RaceEntry::query()->where('race_id', $fiveCarRace->id)->orderBy('bike_number')->pluck('bike_number')->all());
        $this->assertSame($fiveCarEntryIds, RaceEntry::query()->where('race_id', $fiveCarRace->id)->orderBy('bike_number')->pluck('id')->all());
        $this->assertSame(9, $otherRace->refresh()->entrant_count);
        $this->assertSame(range(1, 9), RaceEntry::query()->where('race_id', $otherRace->id)->orderBy('bike_number')->pluck('bike_number')->all());
    }

    public function test_result_sync_updates_details_results_and_all_payouts(): void
    {
        Storage::fake('local');
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        $race = $this->raceWithEntries(1, 'enc-result');
        $this->fakeResultResponses();

        $arguments = [
            '--date' => '2026-06-16',
            '--race-id' => (string) $race->id,
            '--sleep-ms' => '1',
        ];
        $this->artisan('keirin:races:sync-results', $arguments)->assertExitCode(0);
        $this->artisan('keirin:races:sync-results', $arguments)->assertExitCode(0);

        $this->assertSame(7, RaceResult::query()->where('race_id', $race->id)->count());
        $this->assertSame(8, RacePayout::query()->where('race_id', $race->id)->count());
        $this->assertDatabaseHas('race_results', ['race_id' => $race->id, 'bike_number' => 3, 'rank' => null, 'result_status' => 'CRASHED']);
        $this->assertDatabaseHas('race_results', ['race_id' => $race->id, 'bike_number' => 4, 'rank' => null, 'result_status' => 'DISQUALIFIED']);
        $this->assertSame(7, RacePayout::query()->where('race_id', $race->id)->distinct()->count('bet_type_code'));
        $this->assertDatabaseHas('race_entries', ['race_id' => $race->id, 'bike_number' => 1, 'frame_number' => 1, 'race_score' => 110.50]);
        $this->assertSame('CONFIRMED', $race->refresh()->result_status);
        $this->assertSame(2, RaceResultImport::query()->where('race_id', $race->id)->where('import_status', 'SUCCEEDED')->count());
        $this->assertNotNull(RaceResultImport::query()->where('race_id', $race->id)->firstOrFail()->scraping_fetch_log_id);
    }

    public function test_complete_results_with_only_unavailable_and_full_refund_payouts_sync_idempotently(): void
    {
        Storage::fake('local');
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        $race = $this->raceWithEntries(1, 'enc-full-refund');
        $detailResponse = $this->fixture('race-sync-pj0315.html');
        $resultResponse = $this->fullRefundResultHtml();
        Http::fake(function (Request $request) use ($detailResponse, &$resultResponse) {
            parse_str($request->body(), $form);

            return ($form['disp'] ?? null) === 'PJ0315'
                ? Http::response($detailResponse, 200, ['Content-Type' => 'text/html; charset=UTF-8'])
                : Http::response($resultResponse, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        });
        $arguments = [
            '--date' => '2026-06-16',
            '--race-id' => (string) $race->id,
            '--sleep-ms' => '1',
        ];

        $this->artisan('keirin:races:sync-results', $arguments)
            ->expectsOutputToContain('success=1 skipped=0 failed=0 results=7 payouts=0')
            ->assertExitCode(0);
        $resultIds = RaceResult::query()->where('race_id', $race->id)->orderBy('bike_number')->pluck('id')->all();
        $this->artisan('keirin:races:sync-results', $arguments)->assertExitCode(0);

        $this->assertCount(7, $resultIds);
        $this->assertSame($resultIds, RaceResult::query()->where('race_id', $race->id)->orderBy('bike_number')->pluck('id')->all());
        $this->assertSame(0, RacePayout::query()->where('race_id', $race->id)->count());
        $this->assertDatabaseMissing('race_payouts', ['race_id' => $race->id, 'payout_amount' => 0]);
        $this->assertDatabaseMissing('race_payouts', ['race_id' => $race->id, 'payout_amount' => 100]);
        $this->assertSame('CONFIRMED', $race->refresh()->result_status);
        $this->assertSame(2, RaceResultImport::query()->where('race_id', $race->id)->where('import_status', 'SUCCEEDED')->count());
        foreach (RaceResultImport::query()->where('race_id', $race->id)->get() as $import) {
            $this->assertNotNull($import->scraping_fetch_log_id);
            $this->assertTrue(Storage::disk('local')->exists($import->raw_file_path));
        }

        $beforeResults = RaceResult::query()->where('race_id', $race->id)->orderBy('bike_number')->get()->toArray();
        $resultResponse = $this->fullRefundResultHtml(function (array $result): array {
            $result['tyakujyunItemSubData'] = array_slice($result['tyakujyunItemSubData'], 0, 6);

            return $result;
        });
        $this->assertSame(1, Artisan::call('keirin:races:sync-results', $arguments), Artisan::output());
        $this->assertSame($beforeResults, RaceResult::query()->where('race_id', $race->id)->orderBy('bike_number')->get()->toArray());
        $this->assertSame(0, RacePayout::query()->where('race_id', $race->id)->count());
        $this->assertSame('CONFIRMED', $race->refresh()->result_status);

        $resultResponse = $this->fullRefundResultHtml(function (array $result): array {
            foreach ($result['tyakujyunItemSubData'] as &$row) {
                if ((int) $row['syaban'] === 7) {
                    $row['syaban'] = '8';
                    break;
                }
            }
            unset($row);

            return $result;
        });
        $this->assertSame(1, Artisan::call('keirin:races:sync-results', $arguments), Artisan::output());
        $this->assertSame($beforeResults, RaceResult::query()->where('race_id', $race->id)->orderBy('bike_number')->get()->toArray());
        $this->assertSame(0, RacePayout::query()->where('race_id', $race->id)->count());
        $this->assertSame('CONFIRMED', $race->refresh()->result_status);
        $this->assertSame(2, RaceResultImport::query()->where('race_id', $race->id)->where('import_status', 'SUCCEEDED')->count());
        $this->assertSame(1, RaceResultImport::query()->where('race_id', $race->id)->where('import_status', 'FAILED')->count());

        $resultLogs = ScrapingFetchLog::query()->orderBy('id')->get()->filter(
            fn (ScrapingFetchLog $log): bool => ($log->request_parameters['disp'] ?? null) === 'PJ0326',
        );
        $this->assertCount(4, $resultLogs);
        foreach ($resultLogs as $log) {
            $this->assertTrue(Storage::disk('local')->exists($log->raw_file_path));
        }
        $this->assertSame(0, BatchRun::query()->where('status', 'RUNNING')->count());
    }

    public function test_mixed_unavailable_full_refund_and_numeric_payouts_store_only_numeric_rows(): void
    {
        Storage::fake('local');
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        $race = $this->raceWithEntries(1, 'enc-mixed-refund');
        $this->fakeResultResponses($this->mixedRefundResultHtml());

        $this->artisan('keirin:races:sync-results', [
            '--date' => '2026-06-16',
            '--race-id' => (string) $race->id,
            '--sleep-ms' => '1',
        ])->expectsOutputToContain('success=1 skipped=0 failed=0 results=7 payouts=3')
            ->assertExitCode(0);

        $this->assertSame(7, RaceResult::query()->where('race_id', $race->id)->count());
        $this->assertSame(3, RacePayout::query()->where('race_id', $race->id)->count());
        $this->assertDatabaseHas('race_payouts', ['race_id' => $race->id, 'bet_type_code' => 'QUINELLA_PLACE', 'payout_amount' => 1110]);
        $this->assertDatabaseHas('race_payouts', ['race_id' => $race->id, 'bet_type_code' => 'QUINELLA', 'payout_amount' => 1940]);
        $this->assertDatabaseHas('race_payouts', ['race_id' => $race->id, 'bet_type_code' => 'EXACTA', 'payout_amount' => 4060]);
        $this->assertDatabaseMissing('race_payouts', ['race_id' => $race->id, 'payout_amount' => 0]);
        $this->assertDatabaseMissing('race_payouts', ['race_id' => $race->id, 'payout_amount' => 100]);
        $this->assertSame(1, RaceResultImport::query()->where('race_id', $race->id)->where('import_status', 'SUCCEEDED')->count());
    }

    public function test_invalid_full_refund_row_preserves_existing_results_payouts_and_status(): void
    {
        Storage::fake('local');
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        $race = $this->raceWithEntries(1, 'enc-invalid-refund');
        $resultResponse = $this->fixture('race-sync-pj0326.html');
        Http::fake(function (Request $request) use (&$resultResponse) {
            parse_str($request->body(), $form);

            return ($form['disp'] ?? null) === 'PJ0315'
                ? Http::response($this->fixture('race-sync-pj0315.html'), 200, ['Content-Type' => 'text/html; charset=UTF-8'])
                : Http::response($resultResponse, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        });
        $arguments = ['--date' => '2026-06-16', '--race-id' => (string) $race->id, '--sleep-ms' => '1'];
        $this->artisan('keirin:races:sync-results', $arguments)->assertExitCode(0);
        $beforeResults = RaceResult::query()->where('race_id', $race->id)->orderBy('bike_number')->get()->toArray();
        $beforePayouts = RacePayout::query()->where('race_id', $race->id)->orderBy('id')->get()->toArray();

        $resultResponse = $this->resultHtmlWith(function (array $result): array {
            $result['haraiGakuSubData']['WH2HaraiGakuDispItemSubData'] = [[
                'haraiGaku' => '【全返還】',
                'ninkiDispFlg' => false,
                'kumiDispFlg' => true,
            ]];

            return $result;
        });
        $this->assertSame(1, Artisan::call('keirin:races:sync-results', $arguments), Artisan::output());

        $this->assertSame($beforeResults, RaceResult::query()->where('race_id', $race->id)->orderBy('bike_number')->get()->toArray());
        $this->assertSame($beforePayouts, RacePayout::query()->where('race_id', $race->id)->orderBy('id')->get()->toArray());
        $this->assertSame('CONFIRMED', $race->refresh()->result_status);
        $this->assertSame(1, RaceResultImport::query()->where('race_id', $race->id)->count());
        $this->assertSame(2, ScrapingFetchLog::query()->get()->filter(
            fn (ScrapingFetchLog $log): bool => ($log->request_parameters['disp'] ?? null) === 'PJ0326',
        )->count());
        $this->assertSame(0, BatchRun::query()->where('status', 'RUNNING')->count());
    }

    public function test_result_sync_continues_after_one_race_http_failure(): void
    {
        Storage::fake('local');
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        $failedRace = $this->raceWithEntries(1, 'enc-fail');
        $succeededRace = $this->raceWithEntries(2, 'enc-success');
        $detail = str_replace('"selRaceNo":1', '"selRaceNo":2', $this->fixture('race-sync-pj0315.html'));
        $result = str_replace('"selRaceNo":1', '"selRaceNo":2', $this->fixture('race-sync-pj0326.html'));
        $result = str_replace('"raceNo":1', '"raceNo":2', $result);
        Http::fake(function (Request $request) use ($detail, $result) {
            parse_str($request->body(), $form);
            if (($form['encp'] ?? null) === 'enc-fail') {
                return Http::response('temporary failure', 500, ['Content-Type' => 'text/plain; charset=UTF-8']);
            }

            return ($form['disp'] ?? null) === 'PJ0315'
                ? Http::response($detail, 200, ['Content-Type' => 'text/html; charset=UTF-8'])
                : Http::response($result, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        });

        $this->artisan('keirin:races:sync-results', [
            '--date' => '2026-06-16',
            '--force' => true,
            '--sleep-ms' => '1',
        ])->assertExitCode(1);

        $this->assertSame(0, RaceResult::query()->where('race_id', $failedRace->id)->count());
        $this->assertSame(7, RaceResult::query()->where('race_id', $succeededRace->id)->count());
        $this->assertSame(1, BatchRunItem::query()->where('item_key', 'race:'.$failedRace->id)->where('status', 'FAILED')->count());
        $this->assertSame(1, BatchRunItem::query()->where('item_key', 'race:'.$succeededRace->id)->where('status', 'SUCCEEDED')->count());
    }

    public function test_girls_race_is_recorded_as_unsupported_without_deleting_existing_data(): void
    {
        Storage::fake('local');
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        $this->meetingWithDays();
        $existingRace = $this->raceWithEntries(1, 'existing-girls');
        $existingRace->forceFill([
            'name' => 'existing girls race',
            'race_type' => 'Ｌ級ガールズ予選',
        ])->save();
        $entries = json_decode($this->fixture('race-sync-jsj017.json'), true, flags: JSON_THROW_ON_ERROR);
        $entries['rInfo'][0]['syumoku'] = 'Ｌ級ガールズ予選';
        $entries['rInfo'][0]['sInfo'] = $entries['rInfo'][2]['sInfo'];
        $this->fakeRaceListResponses(json_encode($entries, JSON_THROW_ON_ERROR));

        $this->artisan('keirin:races:sync-race-list', [
            '--date' => '2026-06-16',
            '--sleep-ms' => '1',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('races', [
            'id' => $existingRace->id,
            'name' => 'existing girls race',
            'race_type' => 'Ｌ級ガールズ予選',
        ]);
        $this->assertSame(12, Race::query()->count());
        $this->assertSame(78, RaceEntry::query()->count());
        $this->assertDatabaseHas('batch_run_items', [
            'item_type' => 'RACE_CATEGORY',
            'status' => 'SKIPPED_UNSUPPORTED_CATEGORY',
        ]);
    }

    public function test_unknown_eight_car_race_is_recorded_as_unsupported(): void
    {
        Storage::fake('local');
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        $this->meetingWithDays();
        $entries = json_decode($this->fixture('race-sync-jsj017.json'), true, flags: JSON_THROW_ON_ERROR);
        $entries['rInfo'][0]['syumoku'] = 'カテゴリ未定';
        $entries['rInfo'][0]['sInfo'] = $entries['rInfo'][2]['sInfo'];
        $this->fakeRaceListResponses(json_encode($entries, JSON_THROW_ON_ERROR));

        $this->artisan('keirin:races:sync-race-list', [
            '--date' => '2026-06-16',
            '--sleep-ms' => '1',
        ])->assertExitCode(0);

        $this->assertDatabaseMissing('races', ['external_race_id' => '56:20260616:01']);
        $this->assertDatabaseHas('batch_run_items', [
            'item_type' => 'RACE_CATEGORY',
            'status' => 'SKIPPED_UNSUPPORTED_CATEGORY',
        ]);
    }

    public function test_girls_race_is_not_sent_to_the_mens_result_parsers(): void
    {
        Storage::fake('local');
        $race = $this->raceWithEntries(1, 'enc-girls');
        $race->forceFill(['race_type' => 'Ｌ級ガールズ予選'])->save();
        Http::fake();

        $this->artisan('keirin:races:sync-results', [
            '--date' => '2026-06-16',
            '--race-id' => (string) $race->id,
        ])->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertDatabaseHas('batch_run_items', [
            'item_key' => 'race:'.$race->id,
            'status' => 'SKIPPED_UNSUPPORTED_CATEGORY',
        ]);
        $this->assertSame(0, RaceResultImport::query()->count());
    }

    public function test_partial_jsj017_preserves_existing_race_entries(): void
    {
        Storage::fake('local');
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        $race = $this->raceWithEntries(1, 'enc-existing');
        $otherRace = $this->raceWithEntries(2, 'enc-other-existing');
        $entries = json_decode($this->fixture('race-sync-jsj017.json'), true, flags: JSON_THROW_ON_ERROR);
        $entries['rInfo'][0]['sInfo'] = array_slice($entries['rInfo'][0]['sInfo'], 0, 1);
        $this->fakeRaceListResponses(json_encode($entries, JSON_THROW_ON_ERROR));

        $this->artisan('keirin:races:sync-race-list', [
            '--date' => '2026-06-16',
            '--sleep-ms' => '1',
        ])->assertExitCode(1);

        $this->assertSame(7, RaceEntry::query()->where('race_id', $race->id)->count());
        $this->assertSame(range(1, 7), RaceEntry::query()->where('race_id', $race->id)->orderBy('bike_number')->pluck('bike_number')->all());
        $this->assertSame(7, RaceEntry::query()->where('race_id', $otherRace->id)->count());
        $this->assertSame(range(1, 7), RaceEntry::query()->where('race_id', $otherRace->id)->orderBy('bike_number')->pluck('bike_number')->all());
    }

    public function test_partial_pj0326_preserves_existing_results_and_payouts(): void
    {
        Storage::fake('local');
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        $race = $this->raceWithEntries(1, 'enc-result');
        $resultResponse = $this->fixture('race-sync-pj0326.html');
        Http::fake(function (Request $request) use (&$resultResponse) {
            parse_str($request->body(), $form);

            return ($form['disp'] ?? null) === 'PJ0315'
                ? Http::response($this->fixture('race-sync-pj0315.html'), 200, ['Content-Type' => 'text/html; charset=UTF-8'])
                : Http::response($resultResponse, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        });
        $arguments = ['--date' => '2026-06-16', '--race-id' => (string) $race->id, '--sleep-ms' => '1'];
        $this->artisan('keirin:races:sync-results', $arguments)->assertExitCode(0);

        $resultResponse = $this->resultHtmlWith(function (array $result): array {
            $result['tyakujyunItemSubData'] = array_slice($result['tyakujyunItemSubData'], 0, 1);

            return $result;
        });
        $this->assertSame(1, Artisan::call('keirin:races:sync-results', $arguments), Artisan::output());

        $this->assertSame(7, RaceResult::query()->where('race_id', $race->id)->count());
        $this->assertSame(8, RacePayout::query()->where('race_id', $race->id)->count());
        $this->assertSame('CONFIRMED', $race->refresh()->result_status);
    }

    public function test_five_car_detail_and_complete_result_sync_are_idempotent_and_reject_partial_results(): void
    {
        Storage::fake('local');
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        $bikeNumbers = [1, 2, 3, 4, 6];
        $race = $this->raceWithEntries(1, 'enc-five-result', 5, $bikeNumbers);
        $otherRace = $this->raceWithEntries(2, 'enc-other-result', 7);
        $detailResponse = $this->fiveEntrantDetailHtml($bikeNumbers);
        $completeResultResponse = $this->fiveEntrantResultHtml($bikeNumbers);
        $resultResponse = $completeResultResponse;
        Http::fake(function (Request $request) use ($detailResponse, &$resultResponse) {
            parse_str($request->body(), $form);

            return ($form['disp'] ?? null) === 'PJ0315'
                ? Http::response($detailResponse, 200, ['Content-Type' => 'text/html; charset=UTF-8'])
                : Http::response($resultResponse, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        });
        $arguments = [
            '--date' => '2026-06-16',
            '--race-id' => (string) $race->id,
            '--sleep-ms' => '1',
        ];

        $this->assertSame(0, Artisan::call('keirin:races:sync-results', $arguments), Artisan::output());
        $this->assertSame(0, Artisan::call('keirin:races:sync-results', $arguments), Artisan::output());

        $beforeResults = RaceResult::query()->where('race_id', $race->id)->orderBy('bike_number')->get()->toArray();
        $beforePayouts = RacePayout::query()->where('race_id', $race->id)->orderBy('id')->get()->toArray();
        $this->assertCount(5, $beforeResults);
        $this->assertSame($bikeNumbers, array_column($beforeResults, 'bike_number'));
        $this->assertCount(6, $beforePayouts);
        $this->assertDatabaseHas('race_entries', ['race_id' => $race->id, 'bike_number' => 6]);
        $this->assertDatabaseMissing('race_entries', ['race_id' => $race->id, 'bike_number' => 5]);
        $this->assertDatabaseMissing('race_payouts', ['race_id' => $race->id, 'bet_type_code' => 'FRAME_QUINELLA']);
        $this->assertDatabaseMissing('race_payouts', ['race_id' => $race->id, 'bet_type_code' => 'FRAME_EXACTA']);
        $this->assertSame(7, RaceEntry::query()->where('race_id', $otherRace->id)->count());
        $this->assertSame(0, RaceResult::query()->where('race_id', $otherRace->id)->count());
        $this->assertSame(0, RacePayout::query()->where('race_id', $otherRace->id)->count());

        $resultResponse = $this->fiveEntrantResultHtml();
        $this->assertSame(1, Artisan::call('keirin:races:sync-results', $arguments), Artisan::output());
        $this->assertSame($beforeResults, RaceResult::query()->where('race_id', $race->id)->orderBy('bike_number')->get()->toArray());
        $this->assertSame($beforePayouts, RacePayout::query()->where('race_id', $race->id)->orderBy('id')->get()->toArray());
        $this->assertSame('CONFIRMED', $race->refresh()->result_status);

        $completeResult = (new EmbeddedJsonExtractor)->extract($completeResultResponse, 'PJ0326');
        $resultResponse = $this->resultHtmlWith(function (array $result) use ($completeResult): array {
            $result = $completeResult;
            $result['tyakujyunItemSubData'] = array_slice($result['tyakujyunItemSubData'], 0, 4);

            return $result;
        });
        $this->assertSame(1, Artisan::call('keirin:races:sync-results', $arguments), Artisan::output());

        $this->assertSame($beforeResults, RaceResult::query()->where('race_id', $race->id)->orderBy('bike_number')->get()->toArray());
        $this->assertSame($beforePayouts, RacePayout::query()->where('race_id', $race->id)->orderBy('id')->get()->toArray());
        $this->assertSame('CONFIRMED', $race->refresh()->result_status);
        $this->assertSame(2, RaceResultImport::query()->where('race_id', $race->id)->where('import_status', 'SUCCEEDED')->count());
        $this->assertSame(1, RaceResultImport::query()->where('race_id', $race->id)->where('import_status', 'FAILED')->count());
    }

    public function test_undetermined_result_status_is_skipped_without_importing_or_confirming(): void
    {
        Storage::fake('local');
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        $race = $this->raceWithEntries(1, 'enc-undetermined');
        $unknown = $this->resultHtmlWith(function (array $result): array {
            unset($result['haraiGakuDispFlg']);

            return $result;
        });
        $this->fakeResultResponses($unknown);

        $this->artisan('keirin:races:sync-results', [
            '--date' => '2026-06-16',
            '--race-id' => (string) $race->id,
            '--sleep-ms' => '1',
        ])->assertExitCode(0);

        $this->assertSame('UNAVAILABLE', $race->refresh()->result_status);
        $this->assertSame(0, RaceResultImport::query()->count());
        $this->assertDatabaseHas('batch_run_items', [
            'item_key' => 'race:'.$race->id,
            'status' => 'SKIPPED',
            'skip_reason' => 'RESULT_STATUS_UNDETERMINED',
        ]);
    }

    public function test_eight_car_results_require_the_complete_race_entry_bike_set(): void
    {
        Storage::fake('local');
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        $race = $this->raceWithEntries(1, 'enc-eight-result', 8);
        $detailResponse = $this->eightEntrantDetailHtml();
        $resultResponse = $this->eightEntrantResultHtml();
        Http::fake(function (Request $request) use ($detailResponse, &$resultResponse) {
            parse_str($request->body(), $form);

            return ($form['disp'] ?? null) === 'PJ0315'
                ? Http::response($detailResponse, 200, ['Content-Type' => 'text/html; charset=UTF-8'])
                : Http::response($resultResponse, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        });
        $arguments = [
            '--date' => '2026-06-16',
            '--race-id' => (string) $race->id,
            '--sleep-ms' => '1',
        ];

        $this->artisan('keirin:races:sync-results', $arguments)->assertExitCode(0);
        $beforeResults = RaceResult::query()->where('race_id', $race->id)->orderBy('bike_number')->get()->toArray();
        $beforePayouts = RacePayout::query()->where('race_id', $race->id)->orderBy('id')->get()->toArray();
        $this->assertCount(8, $beforeResults);
        $this->assertSame(range(1, 8), array_column($beforeResults, 'bike_number'));

        $resultResponse = $this->fixture('race-sync-pj0326.html');
        $this->assertSame(1, Artisan::call('keirin:races:sync-results', $arguments), Artisan::output());

        $this->assertSame($beforeResults, RaceResult::query()->where('race_id', $race->id)->orderBy('bike_number')->get()->toArray());
        $this->assertSame($beforePayouts, RacePayout::query()->where('race_id', $race->id)->orderBy('id')->get()->toArray());
        $this->assertSame('CONFIRMED', $race->refresh()->result_status);
    }

    public function test_nine_car_live_result_sync_preserves_long_raw_json_and_import_history(): void
    {
        Storage::fake('local');
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        Player::query()->create([
            'source' => 'keirin_jp',
            'external_player_id' => 'existing-player',
            'registration_number' => 'existing-player',
            'name' => '既存選手',
        ]);
        $race = $this->raceWithEntries(1, 'enc-nine-result', 9);
        $detailResponse = $this->nineEntrantDetailHtml();
        $completeResultResponse = $this->nineEntrantResultHtml();
        $completeResult = (new EmbeddedJsonExtractor)->extract($completeResultResponse, 'PJ0326');
        $partialResultResponse = $this->resultHtmlWith(function (array $result) use ($completeResult): array {
            $result = $completeResult;
            $result['tyakujyunItemSubData'] = array_slice($result['tyakujyunItemSubData'], 0, 8);

            return $result;
        });
        $resultResponse = $partialResultResponse;
        Http::fake(function (Request $request) use ($detailResponse, &$resultResponse) {
            parse_str($request->body(), $form);

            return ($form['disp'] ?? null) === 'PJ0315'
                ? Http::response($detailResponse, 200, ['Content-Type' => 'text/html; charset=UTF-8'])
                : Http::response($resultResponse, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        });
        $arguments = [
            '--date' => '2026-06-16',
            '--race-id' => (string) $race->id,
            '--sleep-ms' => '1',
        ];

        $this->assertSame(1, Artisan::call('keirin:races:sync-results', $arguments), Artisan::output());
        $failedImport = RaceResultImport::query()->where('race_id', $race->id)->firstOrFail();
        $this->assertSame('FAILED', $failedImport->import_status);
        $this->assertSame(0, RaceResult::query()->where('race_id', $race->id)->count());

        $resultResponse = $completeResultResponse;
        $this->assertSame(0, Artisan::call('keirin:races:sync-results', $arguments), Artisan::output());
        $this->assertSame(0, Artisan::call('keirin:races:sync-results', $arguments), Artisan::output());

        $parsed = $this->app->make(RaceLiveResultParser::class)->parse($completeResultResponse);
        $expectedRawByBike = [];
        foreach ($parsed->resultPage->results as $result) {
            $expectedRawByBike[$result->bikeNumber] = $result->rawText;
        }
        foreach (RaceResult::query()->where('race_id', $race->id)->get() as $storedResult) {
            $expectedRaw = $expectedRawByBike[$storedResult->bike_number];
            $this->assertGreaterThan(255, mb_strlen($expectedRaw));
            $this->assertSame($expectedRaw, $storedResult->raw_result_text);
        }

        $this->assertSame(9, RaceResult::query()->where('race_id', $race->id)->count());
        $this->assertSame(8, RacePayout::query()->where('race_id', $race->id)->count());
        $this->assertSame(7, RacePayout::query()->where('race_id', $race->id)->distinct()->count('bet_type_code'));
        $this->assertSame(3, RaceResultImport::query()->where('race_id', $race->id)->count());
        $this->assertSame(1, RaceResultImport::query()->whereKey($failedImport->id)->where('import_status', 'FAILED')->count());
        $this->assertSame(2, RaceResultImport::query()->where('race_id', $race->id)->where('import_status', 'SUCCEEDED')->count());
        $this->assertSame(1, Player::query()->count());
        $this->assertSame(1, Race::query()->count());
        $this->assertSame(9, RaceEntry::query()->where('race_id', $race->id)->count());
    }

    private function meetingWithDays(): RaceMeeting
    {
        $track = Racetrack::query()->create(['source' => 'keirin_jp', 'external_track_id' => '56', 'name' => '合成競輪場']);
        $meeting = RaceMeeting::query()->create([
            'source' => 'keirin_jp',
            'external_meeting_id' => '56:20260616:synthetic',
            'racetrack_id' => $track->id,
            'meeting_name' => '合成記念競輪',
            'starts_on' => '2026-06-16',
            'ends_on' => '2026-06-21',
            'duration_days' => 6,
            'encrypted_parameter' => 'enc-meeting',
        ]);
        for ($day = 1; $day <= 6; $day++) {
            RaceDay::query()->create([
                'race_meeting_id' => $meeting->id,
                'external_race_day_id' => "synthetic-day-{$day}",
                'race_date' => '2026-06-'.str_pad((string) (15 + $day), 2, '0', STR_PAD_LEFT),
                'day_number' => $day,
            ]);
        }

        return $meeting;
    }

    /** @return array{id:int,race_date:string,day_number:int} */
    private function raceDayIdentity(RaceDay $day): array
    {
        return [
            'id' => (int) $day->id,
            'race_date' => $day->race_date->format('Y-m-d'),
            'day_number' => (int) $day->day_number,
        ];
    }

    /** @param null|list<int> $bikeNumbers */
    private function raceWithEntries(int $raceNumber, string $encryptedParameter, int $entrantCount = 7, ?array $bikeNumbers = null): Race
    {
        $meeting = RaceMeeting::query()->first() ?? $this->meetingWithDays();
        $day = RaceDay::query()->where('race_meeting_id', $meeting->id)->whereDate('race_date', '2026-06-16')->firstOrFail();
        $race = Race::query()->create([
            'source' => 'keirin_jp',
            'external_race_id' => sprintf('56:20260616:%02d', $raceNumber),
            'race_day_id' => $day->id,
            'racetrack_id' => $meeting->racetrack_id,
            'race_date' => '2026-06-16',
            'race_number' => $raceNumber,
            'race_type' => 'S級予選',
            'entrant_count' => $entrantCount,
            'encrypted_parameter' => $encryptedParameter,
            'result_available' => true,
        ]);
        foreach ($bikeNumbers ?? range(1, $entrantCount) as $bikeNumber) {
            RaceEntry::query()->create([
                'race_id' => $race->id,
                'bike_number' => $bikeNumber,
                'external_player_id' => str_pad((string) $bikeNumber, 6, '0', STR_PAD_LEFT),
                'fetched_at' => now(),
            ]);
        }

        return $race;
    }

    private function fakeRaceListResponses(?string $entryResponse = null, ?string $metadataResponse = null): void
    {
        Http::fake(function (Request $request) use ($entryResponse, $metadataResponse) {
            if (str_contains($request->url(), '/pc/racelist')) {
                return Http::response($this->fixture('race-sync-racelist.html'), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
            }

            return str_contains($request->url(), 'type=JSJ001')
                ? Http::response($metadataResponse ?? $this->fixture('race-sync-jsj001.json'), 200, ['Content-Type' => 'application/json; charset=UTF-8'])
                : Http::response($entryResponse ?? $this->fixture('race-sync-jsj017.json'), 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        });
    }

    private function fakePostponedThenNormalRaceListResponses(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/pc/racelist')) {
                return Http::response($this->fixture('race-sync-racelist.html'), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
            }

            $secondDay = str_contains($request->url(), 'encp=enc-day-2');
            if (str_contains($request->url(), 'type=JSJ001')) {
                $metadata = json_decode($this->fixture('race-sync-jsj001.json'), true, flags: JSON_THROW_ON_ERROR);
                if ($secondDay) {
                    $metadata['C0201data']['selKaisai'] = '20260617';
                }

                return Http::response($metadata, 200, ['Content-Type' => 'application/json; charset=UTF-8']);
            }
            if (! $secondDay) {
                return Http::response($this->fixture('race-sync-jsj017-postponed.json'), 200, ['Content-Type' => 'application/json; charset=UTF-8']);
            }

            $entries = json_decode($this->fixture('race-sync-jsj017.json'), true, flags: JSON_THROW_ON_ERROR);
            $entries['kaisaihi'] = '20260617';
            $entries['reqprm']['kday'] = '20260617';

            return Http::response($entries, 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        });
    }

    private function fakeResultResponses(?string $resultResponse = null): void
    {
        Http::fake(function (Request $request) use ($resultResponse) {
            parse_str($request->body(), $form);

            return ($form['disp'] ?? null) === 'PJ0315'
                ? Http::response($this->fixture('race-sync-pj0315.html'), 200, ['Content-Type' => 'text/html; charset=UTF-8'])
                : Http::response($resultResponse ?? $this->fixture('race-sync-pj0326.html'), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        });
    }

    private function resultHtmlWith(callable $mutate): string
    {
        $extractor = new EmbeddedJsonExtractor;
        $fixture = $this->fixture('race-sync-pj0326.html');
        $context = $extractor->extract($fixture, 'PC0201');
        $result = $mutate($extractor->extract($fixture, 'PJ0326'));

        return '<!doctype html><html><body><script>jsonData["PC0201"] = '
            .json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
            .'; jsonData["PJ0326"] = '
            .json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
            .';</script></body></html>';
    }

    private function fullRefundResultHtml(?callable $mutate = null): string
    {
        return $this->resultHtmlWith(function (array $result) use ($mutate): array {
            $rows = [
                'WH2HaraiGakuDispItemSubData' => '【未発売】',
                'WT2HaraiGakuDispItemSubData' => '【未発売】',
                'SH2HaraiGakuDispItemSubData' => '【全返還】',
                'ST2HaraiGakuDispItemSubData' => '【全返還】',
                'RH3HaraiGakuDispItemSubData' => '【全返還】',
                'RT3HaraiGakuDispItemSubData' => '【全返還】',
                'WHaraiGakuDispItemSubData' => '【全返還】',
            ];
            foreach ($rows as $key => $amount) {
                $result['haraiGakuSubData'][$key] = [[
                    'haraiGaku' => $amount,
                    'ninkiDispFlg' => false,
                    'kumiDispFlg' => false,
                ]];
            }

            return $mutate !== null ? $mutate($result) : $result;
        });
    }

    private function mixedRefundResultHtml(): string
    {
        return $this->resultHtmlWith(function (array $result): array {
            $result['haraiGakuSubData']['WH2HaraiGakuDispItemSubData'] = [[
                'haraiGaku' => '【未発売】',
                'ninkiDispFlg' => false,
                'kumiDispFlg' => false,
            ]];
            $result['haraiGakuSubData']['WT2HaraiGakuDispItemSubData'] = [[
                'haraiGaku' => '【未発売】',
                'ninkiDispFlg' => false,
                'kumiDispFlg' => false,
            ]];
            $result['haraiGakuSubData']['SH2HaraiGakuDispItemSubData'] = [[
                'haraiGaku' => '1,940',
                'ninkiDispFlg' => true,
                'kumiDispFlg' => true,
                'kumiBan' => '2=4',
                'ninki' => '(6)',
            ]];
            $result['haraiGakuSubData']['ST2HaraiGakuDispItemSubData'] = [[
                'haraiGaku' => '4,060',
                'ninkiDispFlg' => true,
                'kumiDispFlg' => true,
                'kumiBan' => '4-2',
                'ninki' => '(12)',
            ]];
            $result['haraiGakuSubData']['RH3HaraiGakuDispItemSubData'] = [[
                'haraiGaku' => '【全返還】',
                'ninkiDispFlg' => false,
                'kumiDispFlg' => false,
            ]];
            $result['haraiGakuSubData']['RT3HaraiGakuDispItemSubData'] = [[
                'haraiGaku' => '【全返還】',
                'ninkiDispFlg' => false,
                'kumiDispFlg' => false,
            ]];
            $result['haraiGakuSubData']['WHaraiGakuDispItemSubData'] = [[
                'haraiGaku' => '1,110',
                'ninkiDispFlg' => true,
                'kumiDispFlg' => true,
                'kumiBan' => '2=4',
                'ninki' => '(6)',
            ]];

            return $result;
        });
    }

    /** @param list<int> $bikeNumbers */
    private function fiveEntrantDetailHtml(array $bikeNumbers = [1, 2, 3, 4, 5]): string
    {
        $extractor = new EmbeddedJsonExtractor;
        $fixture = $this->fixture('race-sync-pj0315.html');
        $context = $extractor->extract($fixture, 'PC0201');
        $detail = $extractor->extract($fixture, 'PJ0315');
        $context['C0201data']['C0201racedtl']['C0201sensyu'] = array_values(array_filter(
            $context['C0201data']['C0201racedtl']['C0201sensyu'],
            fn (array $entry): bool => in_array((int) $entry['carNum'], $bikeNumbers, true),
        ));
        $detail['sensyuTypeInfo'] = array_values(array_filter(
            $detail['sensyuTypeInfo'],
            fn (array $entry): bool => in_array((int) $entry['syaban'], $bikeNumbers, true),
        ));

        return '<!doctype html><html><body><script>jsonData["PC0201"] = '
            .json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
            .'; jsonData["PJ0315"] = '
            .json_encode($detail, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
            .';</script></body></html>';
    }

    /** @param list<int> $bikeNumbers */
    private function fiveEntrantResultHtml(array $bikeNumbers = [1, 2, 3, 4, 5]): string
    {
        return $this->resultHtmlWith(function (array $result) use ($bikeNumbers): array {
            $result['tyakujyunItemSubData'] = array_values(array_filter(
                $result['tyakujyunItemSubData'],
                fn (array $row): bool => in_array((int) $row['syaban'], $bikeNumbers, true),
            ));
            $unavailable = [[
                'haraiGaku' => '【未発売】',
                'ninkiDispFlg' => false,
                'kumiDispFlg' => false,
            ]];
            $result['haraiGakuSubData']['WH2HaraiGakuDispItemSubData'] = $unavailable;
            $result['haraiGakuSubData']['WT2HaraiGakuDispItemSubData'] = $unavailable;

            return $result;
        });
    }

    private function eightEntrantDetailHtml(): string
    {
        $extractor = new EmbeddedJsonExtractor;
        $fixture = $this->fixture('race-sync-pj0315.html');
        $context = $extractor->extract($fixture, 'PC0201');
        $detail = $extractor->extract($fixture, 'PJ0315');
        $context['C0201data']['C0201racedtl']['C0201sensyu'][] = [
            'carNum' => 8,
            'numPlayer' => '000008',
        ];
        $detail['sensyuTypeInfo'][] = [
            'syaban' => '8',
            'wakuban' => '6',
            'sensyuRegistNo' => '000008',
            'sensyuName' => '選手 8',
            'huKen' => '大阪',
            'kyuhan' => 'S2',
            'kyakusitu' => '追',
            'heikinTokuten' => '100.00',
        ];

        return '<!doctype html><html><body><script>jsonData["PC0201"] = '
            .json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
            .'; jsonData["PJ0315"] = '
            .json_encode($detail, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
            .';</script></body></html>';
    }

    private function eightEntrantResultHtml(): string
    {
        return $this->resultHtmlWith(function (array $result): array {
            $result['tyakujyunItemSubData'][] = [
                'tyaku' => '6',
                'syaban' => '8',
                'sensyuName' => '選手 8',
                'sensyuRegistNo' => '000008',
                'kimarite' => '',
                'kojinStateItemSubData' => [],
            ];

            return $result;
        });
    }

    private function nineEntrantDetailHtml(): string
    {
        $extractor = new EmbeddedJsonExtractor;
        $fixture = $this->eightEntrantDetailHtml();
        $context = $extractor->extract($fixture, 'PC0201');
        $detail = $extractor->extract($fixture, 'PJ0315');
        $context['C0201data']['C0201racedtl']['C0201sensyu'][] = [
            'carNum' => 9,
            'numPlayer' => '000009',
        ];
        $detail['sensyuTypeInfo'][] = [
            'syaban' => '9',
            'wakuban' => '7',
            'sensyuRegistNo' => '000009',
            'sensyuName' => '選手 9',
            'huKen' => '京都',
            'kyuhan' => 'S2',
            'kyakusitu' => '両',
            'heikinTokuten' => '99.00',
        ];

        return '<!doctype html><html><body><script>jsonData["PC0201"] = '
            .json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
            .'; jsonData["PJ0315"] = '
            .json_encode($detail, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
            .';</script></body></html>';
    }

    private function nineEntrantResultHtml(): string
    {
        return $this->resultHtmlWith(function (array $result): array {
            foreach ($result['tyakujyunItemSubData'] as &$row) {
                $row['rawPayload'] = str_repeat('長', 300);
            }
            unset($row);
            $result['tyakujyunItemSubData'][] = [
                'tyaku' => '6',
                'syaban' => '8',
                'sensyuName' => '選手 8',
                'sensyuRegistNo' => '000008',
                'kimarite' => '',
                'kojinStateItemSubData' => [],
                'rawPayload' => str_repeat('長', 300),
            ];
            $result['tyakujyunItemSubData'][] = [
                'tyaku' => '7',
                'syaban' => '9',
                'sensyuName' => '選手 9',
                'sensyuRegistNo' => '000009',
                'kimarite' => '',
                'kojinStateItemSubData' => [],
                'rawPayload' => str_repeat('長', 300),
            ];

            return $result;
        });
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/Keirin/synthetic/'.$name));
    }
}
