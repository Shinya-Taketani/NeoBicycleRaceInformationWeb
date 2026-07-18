<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SyncAutomatedRacesCommandTest extends TestCase
{
    use RefreshDatabase;

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
        $this->assertSame(26, RaceEntry::query()->count());
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

    public function test_result_sync_continues_after_one_race_http_failure(): void
    {
        Storage::fake('local');
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        $failedRace = $this->raceWithEntries(1, 'enc-fail');
        $succeededRace = $this->raceWithEntries(2, 'enc-success');
        $detail = str_replace('"selRaceNo":1', '"selRaceNo":2', $this->fixture('race-sync-pj0315.html'));
        $result = str_replace('"selRaceNo":1', '"selRaceNo":2', $this->fixture('race-sync-pj0326.html'));
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

    private function raceWithEntries(int $raceNumber, string $encryptedParameter): Race
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
            'entrant_count' => 7,
            'encrypted_parameter' => $encryptedParameter,
            'result_available' => true,
        ]);
        foreach (range(1, 7) as $bikeNumber) {
            RaceEntry::query()->create([
                'race_id' => $race->id,
                'bike_number' => $bikeNumber,
                'external_player_id' => str_pad((string) $bikeNumber, 6, '0', STR_PAD_LEFT),
                'fetched_at' => now(),
            ]);
        }

        return $race;
    }

    private function fakeRaceListResponses(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/pc/racelist')) {
                return Http::response($this->fixture('race-sync-racelist.html'), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
            }

            return str_contains($request->url(), 'type=JSJ001')
                ? Http::response($this->fixture('race-sync-jsj001.json'), 200, ['Content-Type' => 'application/json; charset=UTF-8'])
                : Http::response($this->fixture('race-sync-jsj017.json'), 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        });
    }

    private function fakeResultResponses(): void
    {
        Http::fake(function (Request $request) {
            parse_str($request->body(), $form);

            return ($form['disp'] ?? null) === 'PJ0315'
                ? Http::response($this->fixture('race-sync-pj0315.html'), 200, ['Content-Type' => 'text/html; charset=UTF-8'])
                : Http::response($this->fixture('race-sync-pj0326.html'), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        });
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/Keirin/synthetic/'.$name));
    }
}
