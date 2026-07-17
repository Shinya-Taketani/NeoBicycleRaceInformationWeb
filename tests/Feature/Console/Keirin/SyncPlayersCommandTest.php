<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

use App\Models\BatchRunItem;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncPlayersCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_raw_file_sync_is_idempotent(): void
    {
        $fixture = base_path('tests/Fixtures/Keirin/player_search_s_class.html');

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
}
