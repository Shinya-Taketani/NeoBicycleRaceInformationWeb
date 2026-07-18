<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

use App\Models\BatchRunItem;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncPlayersCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_raw_file_sync_is_idempotent(): void
    {
        $fixture = base_path('tests/Fixtures/Keirin/actual/player_search_s_class.html');

        $this->artisan('keirin:players:sync', [
            '--raw-file' => $fixture,
            '--source-url' => 'https://keirin.jp/sp/racersearchresult?dppg=1&seibetuCD=1&kyuhanCD=15&stgt=1',
        ])->assertExitCode(0);

        $this->artisan('keirin:players:sync', [
            '--raw-file' => $fixture,
            '--source-url' => 'https://keirin.jp/sp/racersearchresult?dppg=1&seibetuCD=1&kyuhanCD=15&stgt=1',
        ])->assertExitCode(0);

        $this->assertSame(9, Player::query()->count());
        $this->assertGreaterThan(0, BatchRunItem::query()->where('item_type', 'PLAYER_LIST_PAGE')->count());
    }

    public function test_first_page_http_failure_stops_grade_without_running_items(): void
    {
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0, 'keirin.male_grade_codes' => ['15']]);
        Http::fake(['keirin.jp/*' => Http::response('too many', 429, ['Content-Type' => 'text/plain; charset=UTF-8'])]);

        $this->artisan('keirin:players:sync')->assertExitCode(1);

        $this->assertSame(1, BatchRunItem::query()->where('item_type', 'PLAYER_LIST_PAGE')->where('status', 'FAILED')->count());
        $this->assertSame(0, BatchRunItem::query()->where('status', 'RUNNING')->count());
    }

    public function test_single_page_s_class_sync_honors_limit_players(): void
    {
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        Http::fake(['keirin.jp/*' => Http::response(
            file_get_contents(base_path('tests/Fixtures/Keirin/actual/player_search_s_class.html')),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        )]);

        $this->artisan('keirin:players:sync', [
            '--grade-code' => '15',
            '--limit-players' => '3',
            '--sleep-ms' => '0',
        ])->assertExitCode(0);

        $this->assertSame(3, Player::query()->count());
        $this->assertSame(1, BatchRunItem::query()->where('item_type', 'PLAYER_LIST_PAGE')->where('status', 'SUCCEEDED')->count());
        Http::assertSentCount(1);
    }

    public function test_first_page_parser_failure_stops_grade(): void
    {
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0, 'keirin.male_grade_codes' => ['15']]);
        Http::fake(['keirin.jp/*' => Http::response('<html><body>選手検索結果</body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8'])]);

        $this->artisan('keirin:players:sync')->assertExitCode(1);

        $this->assertSame(1, BatchRunItem::query()->where('item_type', 'PLAYER_LIST_PAGE')->where('status', 'FAILED')->count());
        $this->assertSame(1, BatchRunItem::query()->where('item_key', 'player-list:15:all:1')->count());
        $this->assertSame(0, BatchRunItem::query()->where('item_key', 'player-list:15:all:2')->count());
    }

    public function test_foreign_rider_page_sync_succeeds_without_field_shift(): void
    {
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0]);
        Http::fake(['keirin.jp/*' => Http::response(
            file_get_contents(base_path('tests/Fixtures/Keirin/synthetic/player_search_foreign_rider_page.html')),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        )]);

        $this->artisan('keirin:players:sync', [
            '--grade-code' => '12',
            '--page' => '23',
            '--limit-players' => '2',
            '--sleep-ms' => '0',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('players', [
            'external_player_id' => '900002',
            'name' => 'テスト　ライダー',
            'name_kana' => null,
            'current_grade' => 'S2',
            'district' => '外国',
            'prefecture' => 'イギリス',
            'graduation_period' => null,
            'home_bank' => null,
            'riding_style' => '両',
        ]);
        $this->assertSame(2, Player::query()->count());
        Http::assertSentCount(1);
    }

    public function test_second_page_failure_does_not_fetch_third_page(): void
    {
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0, 'keirin.male_grade_codes' => ['15']]);
        $page1 = $this->playerPage('1/3');
        Http::fake(function ($request) use ($page1) {
            return str_contains($request->url(), 'dppg=1')
                ? Http::response($page1, 200, ['Content-Type' => 'text/html; charset=UTF-8'])
                : Http::response('server error', 500, ['Content-Type' => 'text/plain; charset=UTF-8']);
        });

        $this->artisan('keirin:players:sync')->assertExitCode(1);

        $this->assertSame(1, BatchRunItem::query()->where('item_key', 'player-list:15:all:1')->where('status', 'SUCCEEDED')->count());
        $this->assertSame(1, BatchRunItem::query()->where('item_key', 'player-list:15:all:2')->where('status', 'FAILED')->count());
        $this->assertSame(0, BatchRunItem::query()->where('item_key', 'player-list:15:all:3')->count());
    }

    public function test_failed_grade_continues_to_next_grade(): void
    {
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0, 'keirin.male_grade_codes' => ['15', '11']]);
        $page = $this->playerPage('1/1');
        Http::fake(function ($request) use ($page) {
            return str_contains($request->url(), 'kyuhanCD=15')
                ? Http::response('too many', 429, ['Content-Type' => 'text/plain; charset=UTF-8'])
                : Http::response($page, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        });

        $this->artisan('keirin:players:sync')->assertExitCode(1);

        $this->assertSame(1, BatchRunItem::query()->where('item_key', 'player-list:15:all:1')->where('status', 'FAILED')->count());
        $this->assertSame(1, BatchRunItem::query()->where('item_key', 'player-list:11:all:1')->where('status', 'SUCCEEDED')->count());
    }

    public function test_invalid_or_excessive_last_page_fails(): void
    {
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0, 'keirin.male_grade_codes' => ['15'], 'keirin.max_player_pages_per_grade' => 1]);
        Http::fake(['keirin.jp/*' => Http::response($this->playerPage('1/2'), 200, ['Content-Type' => 'text/html; charset=UTF-8'])]);

        $this->artisan('keirin:players:sync')->assertExitCode(1);

        $this->assertSame(1, BatchRunItem::query()->where('item_key', 'player-list:15:all:1')->where('status', 'FAILED')->count());
    }

    public function test_repeated_player_set_on_next_page_fails(): void
    {
        config(['keirin.sleep_ms' => 0, 'keirin.retry_times' => 0, 'keirin.male_grade_codes' => ['15']]);
        Http::fake(function ($request) {
            return str_contains($request->url(), 'dppg=1')
                ? Http::response($this->playerPage('1/2'), 200, ['Content-Type' => 'text/html; charset=UTF-8'])
                : Http::response($this->playerPage('2/2'), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        });

        $this->artisan('keirin:players:sync')->assertExitCode(1);

        $this->assertSame(1, BatchRunItem::query()->where('item_key', 'player-list:15:all:2')->where('status', 'FAILED')->count());
        $this->assertSame(0, BatchRunItem::query()->where('status', 'RUNNING')->count());
    }

    private function playerPage(string $pageText): string
    {
        return file_get_contents(base_path('tests/Fixtures/Keirin/actual/player_search_s_class.html')).'<div>ページ '.$pageText.'</div>';
    }
}
