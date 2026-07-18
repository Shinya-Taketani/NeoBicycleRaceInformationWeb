<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Scraping\Services\RaceImportService;
use DateTimeImmutable;
use Illuminate\Console\Command;

class SyncRaceScheduleCommand extends Command
{
    protected $signature = 'keirin:races:sync-schedule
        {--from= : YYYY-MM-DD}
        {--to= : YYYY-MM-DD}
        {--raw-file= : Parse saved schedule HTML without network}
        {--sleep-ms= : Request interval override in milliseconds}';

    protected $description = 'Sync KEIRIN.JP race meeting schedule into race_meetings and race_days.';

    public function handle(RaceImportService $schedules): int
    {
        $from = $this->dateOption('from');
        $to = $this->dateOption('to');

        if ($from === null || $to === null) {
            return self::FAILURE;
        }

        if ($from > $to) {
            $this->error('--from must be before or equal to --to.');

            return self::FAILURE;
        }

        $result = $schedules->importSchedule($from, $to, [
            'raw_file' => $this->option('raw-file') ?: null,
            'sleep_ms' => $this->option('sleep-ms') !== null ? (int) $this->option('sleep-ms') : null,
        ]);

        $this->info("batch_run_id={$result['batch_run']->id}");
        $this->line("success={$result['success']} skipped={$result['skipped']} failed={$result['failed']}");
        if ($result['failed'] > 0 && $result['batch_run']->error_message !== null) {
            $this->error($result['batch_run']->error_message);
        }

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function dateOption(string $name): ?DateTimeImmutable
    {
        $value = $this->option($name);
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            $this->error("--{$name} must be a valid YYYY-MM-DD date.");

            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (! $date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            $this->error("--{$name} must be a real calendar date.");

            return null;
        }

        return $date;
    }
}
