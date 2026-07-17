<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Scraping\Services\RaceImportService;
use App\Domain\Keirin\Scraping\Services\RaceResultImportService;
use DateTimeImmutable;
use Illuminate\Console\Command;

class ImportRaceResultsCommand extends Command
{
    protected $signature = 'keirin:races:import-results
        {--from= : YYYY-MM-DD}
        {--to= : YYYY-MM-DD}
        {--raw-file= : Parse saved schedule/result HTML without network}
        {--parse-result-only : Use --raw-file as race result detail HTML and only verify result/payout parsers}
        {--sleep-ms= : Request interval override in milliseconds}';

    protected $description = 'Import keirin race schedule structure and provide raw-file verification for result and payout parsers.';

    public function handle(RaceImportService $schedules, RaceResultImportService $results): int
    {
        if ($this->option('parse-result-only')) {
            $rawFile = $this->option('raw-file');
            if (! is_string($rawFile) || $rawFile === '') {
                $this->error('--raw-file is required with --parse-result-only.');

                return self::FAILURE;
            }

            $counts = $results->parseRawFile($rawFile);
            $this->line("results={$counts['results']} payouts={$counts['payouts']}");

            return self::SUCCESS;
        }

        if (! is_string($this->option('from')) || ! is_string($this->option('to'))) {
            $this->error('--from and --to are required.');

            return self::FAILURE;
        }

        $result = $schedules->importSchedule(
            new DateTimeImmutable((string) $this->option('from')),
            new DateTimeImmutable((string) $this->option('to')),
            [
                'raw_file' => $this->option('raw-file') ?: null,
                'sleep_ms' => $this->option('sleep-ms') !== null ? (int) $this->option('sleep-ms') : null,
            ],
        );

        $this->info("batch_run_id={$result['batch_run']->id}");
        $this->line("success={$result['success']} skipped={$result['skipped']} failed={$result['failed']}");

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
