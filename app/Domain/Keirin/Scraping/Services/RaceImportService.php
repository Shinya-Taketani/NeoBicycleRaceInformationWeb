<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Services;

use App\Domain\Keirin\Scraping\Fetchers\RaceScheduleFetcher;
use App\Domain\Keirin\Scraping\Parsers\RaceScheduleParser;
use App\Models\BatchRun;
use App\Repositories\RaceRepository;
use DateTimeImmutable;
use Illuminate\Support\Facades\File;

class RaceImportService
{
    public function __construct(
        private readonly BatchRunService $batchRuns,
        private readonly RaceScheduleFetcher $fetcher,
        private readonly RawResponseStorageService $rawStorage,
        private readonly RaceScheduleParser $parser,
        private readonly RaceRepository $races,
    ) {}

    /**
     * @return array{batch_run:BatchRun,success:int,skipped:int,failed:int}
     */
    public function importSchedule(DateTimeImmutable $from, DateTimeImmutable $to, array $options = []): array
    {
        $run = $this->batchRuns->start('race_schedule_import', [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            ...$options,
        ], 'keirin:races:import-results');

        $success = 0;
        $failed = 0;
        $error = null;

        try {
            if (($options['raw_file'] ?? null) !== null) {
                $items = $this->parser->parse(File::get((string) $options['raw_file']));
                $fetchedAt = new DateTimeImmutable('now');
            } else {
                $items = [];
                $cursor = $from->modify('first day of this month');
                while ($cursor <= $to) {
                    $response = $this->fetcher->fetch((int) $cursor->format('Y'), (int) $cursor->format('m'), $options['sleep_ms'] ?? null);
                    $stored = $this->rawStorage->store($response, (int) $run->id);
                    $items = [...$items, ...$this->parser->parse($stored->utf8Body)];
                    $cursor = $cursor->modify('first day of next month');
                }
                $fetchedAt = new DateTimeImmutable('now');
            }

            foreach ($items as $item) {
                if ($item->startsOn < $from || $item->startsOn > $to) {
                    continue;
                }
                $this->races->upsertScheduleItem($item, $fetchedAt);
                $success++;
            }
        } catch (\Throwable $throwable) {
            $failed++;
            $error = $throwable->getMessage();
        }

        $run = $this->batchRuns->finish($run, $success, 0, $failed, $error);

        return ['batch_run' => $run, 'success' => $success, 'skipped' => 0, 'failed' => $failed];
    }
}
