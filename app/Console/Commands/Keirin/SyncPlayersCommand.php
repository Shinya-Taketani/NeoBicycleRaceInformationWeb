<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Scraping\Services\PlayerSyncService;
use Illuminate\Console\Command;

class SyncPlayersCommand extends Command
{
    protected $signature = 'keirin:players:sync
        {--grade-code=15 : KEIRIN.JP kyuhanCD. Default is S級S班 to keep first run small}
        {--prefecture-code= : KEIRIN.JP hukenCD}
        {--page=1 : Search result page}
        {--limit-players= : Max players to persist from the page}
        {--with-detail : Fetch and persist each player detail page}
        {--raw-file= : Parse a saved player search result HTML without network}
        {--source-url= : Source URL used with --raw-file}
        {--sleep-ms= : Request interval override in milliseconds}';

    protected $description = 'Sync male keirin player summaries and optional details from KEIRIN.JP.';

    public function handle(PlayerSyncService $service): int
    {
        $result = $service->sync([
            'grade_code' => $this->option('grade-code') ?: null,
            'prefecture_code' => $this->option('prefecture-code') ?: null,
            'page' => (int) $this->option('page'),
            'limit_players' => $this->option('limit-players') !== null ? (int) $this->option('limit-players') : null,
            'with_detail' => (bool) $this->option('with-detail'),
            'raw_file' => $this->option('raw-file') ?: null,
            'source_url' => $this->option('source-url') ?: null,
            'sleep_ms' => $this->option('sleep-ms') !== null ? (int) $this->option('sleep-ms') : null,
        ]);

        $this->info("batch_run_id={$result['batch_run']->id}");
        $this->line("success={$result['success']} skipped={$result['skipped']} failed={$result['failed']}");

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
