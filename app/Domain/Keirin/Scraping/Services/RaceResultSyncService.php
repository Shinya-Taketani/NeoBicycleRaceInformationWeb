<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Services;

use App\Domain\Keirin\Scraping\DTO\ParsedRaceResultPageDto;
use App\Domain\Keirin\Scraping\Enums\RaceCategory;
use App\Domain\Keirin\Scraping\Enums\RaceResultStatus;
use App\Domain\Keirin\Scraping\Fetchers\RaceLiveFetcher;
use App\Domain\Keirin\Scraping\Parsers\RaceDetailParser;
use App\Domain\Keirin\Scraping\Parsers\RaceLiveResultParser;
use App\Domain\Keirin\Scraping\Support\RaceCategoryPolicy;
use App\Models\BatchRun;
use App\Models\Race;
use App\Models\RaceEntry;
use App\Repositories\RaceRepository;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class RaceResultSyncService
{
    private const RACE_CHUNK_SIZE = 100;

    public function __construct(
        private readonly BatchRunService $batchRuns,
        private readonly RaceLiveFetcher $fetcher,
        private readonly ScrapingFetchService $fetches,
        private readonly RaceDetailParser $detailParser,
        private readonly RaceLiveResultParser $resultParser,
        private readonly RaceRepository $races,
        private readonly RaceResultImportService $resultImports,
        private readonly RaceCategoryPolicy $categories,
    ) {}

    /** @return array{batch_run:BatchRun,success:int,skipped:int,failed:int,results:int,payouts:int} */
    public function sync(DateTimeImmutable $from, DateTimeImmutable $to, array $options = []): array
    {
        $lockKey = 'keirin:races:sync-results:'.$from->format('Y-m-d').':'.$to->format('Y-m-d');
        $run = $this->batchRuns->start('race_result_sync', [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            ...$options,
        ], $lockKey);
        $success = $skipped = $failed = $resultCount = $payoutCount = 0;
        $lastError = null;
        $outerException = null;

        try {
            foreach ($this->racesForSync($from, $to, $options) as $race) {
                $item = $this->batchRuns->startItem($run, 'RACE_RESULT', 'race:'.$race->id);
                try {
                    $category = $this->categories->classify($race->race_type);
                    if ($category !== RaceCategory::Men) {
                        $skipped++;
                        $this->batchRuns->skipUnsupportedCategoryItem($item, 'UNSUPPORTED_RACE_CATEGORY', [
                            'race_type' => $race->race_type,
                            'category' => $category->value,
                        ]);

                        continue;
                    }

                    if (! is_string($race->encrypted_parameter) || $race->encrypted_parameter === '') {
                        throw new \RuntimeException("Race {$race->external_race_id} has no encrypted parameter.");
                    }
                    $detailRaw = $this->fetches->fetch(
                        fn () => $this->fetcher->fetchDetail($race->encrypted_parameter, $options['sleep_ms'] ?? null),
                        (int) $run->id,
                    );
                    $detail = $this->detailParser->parse($detailRaw->utf8Body);
                    $this->races->updateRaceDetail($race, $detail, new DateTimeImmutable('now'));

                    $resultRaw = $this->fetches->fetch(
                        fn () => $this->fetcher->fetchResult($race->encrypted_parameter, $options['sleep_ms'] ?? null),
                        (int) $run->id,
                    );
                    $resultPage = $this->resultParser->parse($resultRaw->utf8Body);
                    $this->assertResultContext($race, $resultPage->raceDate, $resultPage->trackCode, $resultPage->raceNumber);
                    if (! $resultPage->detectedStatus instanceof RaceResultStatus) {
                        $skipped++;
                        $this->batchRuns->skipItem($item, 'RESULT_STATUS_UNDETERMINED', [
                            'evidence' => $resultPage->statusEvidence,
                            'raw_file_path' => $resultRaw->rawFilePath,
                        ]);

                        continue;
                    }
                    $this->assertResultPlayers($race, $resultPage->resultPage);
                    $imported = $this->resultImports->importStoredResponse(
                        $race,
                        $run,
                        $item,
                        $resultRaw,
                        $resultPage->resultPage,
                        rtrim((string) config('keirin.base_url'), '/').(string) config('keirin.routes.race_live'),
                        $resultPage->detectedStatus,
                    );
                    $resultCount += $imported['results'];
                    $payoutCount += $imported['payouts'];
                    if ($imported['status'] === 'SKIPPED') {
                        $skipped++;
                        $this->batchRuns->skipItem($item, 'RESULT_NOT_AVAILABLE', ['import_id' => $imported['import']->id]);
                    } else {
                        $success++;
                        $this->batchRuns->succeedItem($item, [
                            'import_id' => $imported['import']->id,
                            'results' => $imported['results'],
                            'payouts' => $imported['payouts'],
                        ]);
                    }
                } catch (Throwable $throwable) {
                    $failed++;
                    $lastError = $throwable->getMessage();
                    $this->batchRuns->failItem($item, $throwable::class, $throwable->getMessage());
                }
            }
        } catch (Throwable $throwable) {
            $failed++;
            $lastError = $throwable->getMessage();
            $outerException = $throwable;
        } finally {
            try {
                $run = $this->batchRuns->finish($run, $success, $skipped, $failed, $lastError);
            } finally {
                $this->batchRuns->releaseLock($lockKey);
            }
        }

        if ($outerException instanceof Throwable) {
            throw $outerException;
        }

        return [
            'batch_run' => $run,
            'success' => $success,
            'skipped' => $skipped,
            'failed' => $failed,
            'results' => $resultCount,
            'payouts' => $payoutCount,
        ];
    }

    /** @return \Generator<int, Race> */
    private function racesForSync(DateTimeImmutable $from, DateTimeImmutable $to, array $options): \Generator
    {
        $remaining = isset($options['limit']) ? max(0, (int) $options['limit']) : null;
        $lastRaceDate = null;
        $lastRaceNumber = null;
        $lastId = null;

        while ($remaining === null || $remaining > 0) {
            $pageSize = $remaining === null
                ? self::RACE_CHUNK_SIZE
                : min(self::RACE_CHUNK_SIZE, $remaining);
            $races = $this->raceQuery($from, $to, $options)
                ->when($lastRaceDate !== null, function (Builder $query) use ($lastRaceDate, $lastRaceNumber, $lastId): void {
                    $query->where(function (Builder $cursor) use ($lastRaceDate, $lastRaceNumber, $lastId): void {
                        $cursor->whereDate('races.race_date', '>', $lastRaceDate)
                            ->orWhere(function (Builder $sameDate) use ($lastRaceDate, $lastRaceNumber): void {
                                $sameDate->whereDate('races.race_date', $lastRaceDate)
                                    ->where('races.race_number', '>', $lastRaceNumber);
                            })
                            ->orWhere(function (Builder $sameRace) use ($lastRaceDate, $lastRaceNumber, $lastId): void {
                                $sameRace->whereDate('races.race_date', $lastRaceDate)
                                    ->where('races.race_number', $lastRaceNumber)
                                    ->where('races.id', '>', $lastId);
                            });
                    });
                })
                ->orderBy('races.race_date')
                ->orderBy('races.race_number')
                ->orderBy('races.id')
                ->limit($pageSize)
                ->get();

            if ($races->isEmpty()) {
                return;
            }

            foreach ($races as $race) {
                yield $race;
            }

            $lastRace = $races->last();
            $lastRaceDate = $lastRace->race_date->format('Y-m-d');
            $lastRaceNumber = (int) $lastRace->race_number;
            $lastId = (int) $lastRace->id;
            $fetchedCount = $races->count();
            unset($races);

            if ($remaining !== null) {
                $remaining -= $fetchedCount;
                if ($remaining <= 0) {
                    return;
                }
            }
            if ($fetchedCount < $pageSize) {
                return;
            }
        }
    }

    private function raceQuery(DateTimeImmutable $from, DateTimeImmutable $to, array $options): Builder
    {
        return Race::query()
            ->select('races.*')
            ->leftJoin('racetracks', 'racetracks.id', '=', 'races.racetrack_id')
            ->whereDate('races.race_date', '>=', $from->format('Y-m-d'))
            ->whereDate('races.race_date', '<=', $to->format('Y-m-d'))
            ->when(! ($options['force'] ?? false), fn (Builder $query): Builder => $query->where('races.result_available', true))
            ->when(isset($options['race_id']), fn (Builder $query): Builder => $query->where('races.id', $options['race_id']))
            ->when(isset($options['track_code']), fn (Builder $query): Builder => $query->where('racetracks.external_track_id', $options['track_code']))
            ->when(isset($options['race_number']), fn (Builder $query): Builder => $query->where('races.race_number', $options['race_number']));
    }

    private function assertResultContext(Race $race, string $date, string $trackCode, int $raceNumber): void
    {
        $expected = sprintf('%s:%s:%02d', $trackCode, $date, $raceNumber);
        if ($race->external_race_id !== $expected) {
            throw new \RuntimeException("PJ0326 context {$expected} did not match race {$race->external_race_id}.");
        }
    }

    private function assertResultPlayers(Race $race, ParsedRaceResultPageDto $page): void
    {
        $entries = RaceEntry::query()->where('race_id', $race->id)->get()->keyBy('bike_number');
        foreach ($page->results as $result) {
            $entry = $entries->get($result->bikeNumber);
            if (! $entry instanceof RaceEntry || $result->externalPlayerId === null || $entry->external_player_id !== $result->externalPlayerId) {
                throw new \RuntimeException("PJ0326 player did not match bike {$result->bikeNumber} for race {$race->external_race_id}.");
            }
        }
    }
}
