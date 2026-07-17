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
        private readonly ScrapingFetchService $fetches,
        private readonly RaceScheduleParser $parser,
        private readonly RaceRepository $races,
    ) {}

    /**
     * @return array{batch_run:BatchRun,success:int,skipped:int,failed:int}
     */
    public function importSchedule(DateTimeImmutable $from, DateTimeImmutable $to, array $options = []): array
    {
        $lockKey = 'keirin:races:sync-schedule:'.$from->format('Y-m-d').':'.$to->format('Y-m-d');
        $run = $this->batchRuns->start('race_schedule_import', [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            ...$options,
        ], $lockKey);

        $success = 0;
        $failed = 0;
        $error = null;

        try {
            if (($options['raw_file'] ?? null) !== null) {
                $item = $this->batchRuns->startItem($run, 'RACE_SCHEDULE_MONTH', 'raw-file:'.$options['raw_file']);
                try {
                    $items = $this->parser->parse(File::get((string) $options['raw_file']));
                    $this->batchRuns->succeedItem($item, ['count' => count($items)]);
                    $fetchedAt = new DateTimeImmutable('now');
                } catch (\Throwable $throwable) {
                    $failed++;
                    $error = $throwable->getMessage();
                    $items = [];
                    $fetchedAt = new DateTimeImmutable('now');
                    $this->batchRuns->failItem($item, $throwable::class, $throwable->getMessage());
                }
            } else {
                $items = [];
                $cursor = $from->modify('first day of this month');
                while ($cursor <= $to) {
                    $itemKey = 'race-schedule-month:'.$cursor->format('Y-m');
                    $item = $this->batchRuns->startItem($run, 'RACE_SCHEDULE_MONTH', $itemKey);
                    try {
                        $stored = $this->fetches->fetch(fn () => $this->fetcher->fetch((int) $cursor->format('Y'), (int) $cursor->format('m'), $options['sleep_ms'] ?? null), (int) $run->id);
                        $parsed = $this->parser->parse($stored->utf8Body);
                        $items = [...$items, ...$parsed];
                        $this->batchRuns->succeedItem($item, ['count' => count($parsed)]);
                    } catch (\Throwable $throwable) {
                        $failed++;
                        $this->batchRuns->failItem($item, $throwable::class, $throwable->getMessage());
                    }
                    $cursor = $cursor->modify('first day of next month');
                }
                $fetchedAt = new DateTimeImmutable('now');
            }

            foreach ($items as $item) {
                $endsOn = $item->startsOn->modify('+'.($item->durationDays - 1).' days');
                if ($endsOn < $from || $item->startsOn > $to) {
                    continue;
                }
                $this->races->upsertScheduleItem($item, $fetchedAt);
                $success++;
            }
        } catch (\Throwable $throwable) {
            $failed++;
            $error = $throwable->getMessage();
        } finally {
            $this->batchRuns->releaseLock($lockKey);
        }

        $run = $this->batchRuns->finish($run, $success, 0, $failed, $error);

        return ['batch_run' => $run, 'success' => $success, 'skipped' => 0, 'failed' => $failed];
    }
}
