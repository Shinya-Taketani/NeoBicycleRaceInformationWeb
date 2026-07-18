<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Services;

use App\Domain\Keirin\Scraping\DTO\RaceEntryListPageDto;
use App\Domain\Keirin\Scraping\Enums\RaceCategory;
use App\Domain\Keirin\Scraping\Fetchers\RaceDayMetadataFetcher;
use App\Domain\Keirin\Scraping\Fetchers\RaceEntryListFetcher;
use App\Domain\Keirin\Scraping\Fetchers\RaceListPageFetcher;
use App\Domain\Keirin\Scraping\Parsers\RaceDayMetadataParser;
use App\Domain\Keirin\Scraping\Parsers\RaceEntryListParser;
use App\Domain\Keirin\Scraping\Parsers\RaceListConsistencyValidator;
use App\Models\BatchRun;
use App\Models\RaceDay;
use App\Models\RaceMeeting;
use App\Repositories\RaceRepository;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use Throwable;

class RaceListSyncService
{
    public function __construct(
        private readonly BatchRunService $batchRuns,
        private readonly RaceListPageFetcher $raceListFetcher,
        private readonly RaceDayMetadataFetcher $metadataFetcher,
        private readonly RaceEntryListFetcher $entryListFetcher,
        private readonly ScrapingFetchService $fetches,
        private readonly RaceDayMetadataParser $metadataParser,
        private readonly RaceEntryListParser $entryListParser,
        private readonly RaceListConsistencyValidator $consistency,
        private readonly RaceRepository $races,
    ) {}

    /** @return array{batch_run:BatchRun,success:int,skipped:int,failed:int,races:int,entries:int,unresolved_players:int} */
    public function sync(DateTimeImmutable $from, DateTimeImmutable $to, array $options = []): array
    {
        $lockKey = 'keirin:races:sync-race-list:'.$from->format('Y-m-d').':'.$to->format('Y-m-d');
        $run = $this->batchRuns->start('race_list_sync', [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            ...$options,
        ], $lockKey);
        $success = $skipped = $failed = $raceCount = $entryCount = $unresolved = 0;
        $lastError = null;
        $outerException = null;

        try {
            $days = $this->days($from, $to, $options)->get()->groupBy('race_meeting_id');
            foreach ($days as $meetingId => $targetDays) {
                $meeting = RaceMeeting::query()->findOrFail($meetingId);
                $meetingItem = $this->batchRuns->startItem($run, 'RACE_DAY_METADATA', "race-meeting:{$meetingId}");
                try {
                    if (! is_string($meeting->encrypted_parameter) || $meeting->encrypted_parameter === '') {
                        throw new RuntimeException("Race meeting {$meetingId} has no encrypted parameter.");
                    }
                    $raceListRaw = $this->fetches->fetch(
                        fn () => $this->raceListFetcher->fetch($meeting->encrypted_parameter, $options['sleep_ms'] ?? null),
                        (int) $run->id,
                    );
                    $meetingMetadata = $this->metadataParser->parse($raceListRaw->utf8Body);
                    $this->races->updateMeetingDayParameters($meeting, $meetingMetadata, new DateTimeImmutable('now'));
                    $this->batchRuns->succeedItem($meetingItem, ['days' => count($meetingMetadata->days)]);
                } catch (Throwable $throwable) {
                    $failed += $targetDays->count();
                    $lastError = $throwable->getMessage();
                    $this->batchRuns->failItem($meetingItem, $throwable::class, $throwable->getMessage());

                    continue;
                }

                foreach ($targetDays as $day) {
                    $item = $this->batchRuns->startItem($run, 'RACE_ENTRY_LIST', 'race-day:'.$day->id);
                    try {
                        $day->refresh();
                        if (! is_string($day->encrypted_parameter) || $day->encrypted_parameter === '') {
                            throw new RuntimeException("Race day {$day->id} has no encrypted parameter.");
                        }
                        $metadataRaw = $this->fetches->fetch(
                            fn () => $this->metadataFetcher->fetch($day->encrypted_parameter, $options['sleep_ms'] ?? null),
                            (int) $run->id,
                        );
                        $entriesRaw = $this->fetches->fetch(
                            fn () => $this->entryListFetcher->fetch($day->encrypted_parameter, $options['sleep_ms'] ?? null),
                            (int) $run->id,
                        );
                        $metadata = $this->metadataParser->parse($metadataRaw->utf8Body);
                        $entryPage = $this->entryListParser->parse($entriesRaw->utf8Body);
                        $parameters = $this->consistency->validate($metadata, $entryPage);

                        $menRaces = array_values(array_filter(
                            $entryPage->races,
                            fn ($race): bool => $race->category === RaceCategory::Men,
                        ));
                        $unsupportedRaces = array_values(array_filter(
                            $entryPage->races,
                            fn ($race): bool => $race->category !== RaceCategory::Men,
                        ));
                        $skipped += count($unsupportedRaces);

                        if ($menRaces === []) {
                            $this->batchRuns->skipUnsupportedCategoryItem($item, 'UNSUPPORTED_RACE_CATEGORY', [
                                'races' => array_map(fn ($race): array => [
                                    'race_number' => $race->raceNumber,
                                    'race_type' => $race->raceType,
                                    'category' => $race->category->value,
                                ], $unsupportedRaces),
                            ]);

                            continue;
                        }

                        foreach ($unsupportedRaces as $unsupportedRace) {
                            $categoryItem = $this->batchRuns->startItem(
                                $run,
                                'RACE_CATEGORY',
                                sprintf('race-day:%d:race:%02d', $day->id, $unsupportedRace->raceNumber),
                            );
                            $this->batchRuns->skipUnsupportedCategoryItem($categoryItem, 'UNSUPPORTED_RACE_CATEGORY', [
                                'race_number' => $unsupportedRace->raceNumber,
                                'race_type' => $unsupportedRace->raceType,
                                'category' => $unsupportedRace->category->value,
                            ]);
                        }

                        $menEntryPage = new RaceEntryListPageDto(
                            trackCode: $entryPage->trackCode,
                            raceDate: $entryPage->raceDate,
                            lastUpdatedAt: $entryPage->lastUpdatedAt,
                            races: $menRaces,
                        );
                        $counts = $this->races->syncRaceDay($day, $metadata, $menEntryPage, $parameters, new DateTimeImmutable('now'));
                        $success++;
                        $raceCount += $counts['races'];
                        $entryCount += $counts['entries'];
                        $unresolved += $counts['unresolved_players'];
                        $this->batchRuns->succeedItem($item, $counts);
                    } catch (Throwable $throwable) {
                        $failed++;
                        $lastError = $throwable->getMessage();
                        $this->batchRuns->failItem($item, $throwable::class, $throwable->getMessage());
                    }
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
            'races' => $raceCount,
            'entries' => $entryCount,
            'unresolved_players' => $unresolved,
        ];
    }

    private function days(DateTimeImmutable $from, DateTimeImmutable $to, array $options): Builder
    {
        return RaceDay::query()
            ->select('race_days.*')
            ->join('race_meetings', 'race_meetings.id', '=', 'race_days.race_meeting_id')
            ->join('racetracks', 'racetracks.id', '=', 'race_meetings.racetrack_id')
            ->whereDate('race_days.race_date', '>=', $from->format('Y-m-d'))
            ->whereDate('race_days.race_date', '<=', $to->format('Y-m-d'))
            ->when(isset($options['track_code']), fn (Builder $query): Builder => $query->where('racetracks.external_track_id', $options['track_code']))
            ->when(isset($options['race_id']), function (Builder $query) use ($options): Builder {
                return $query->whereExists(function ($subquery) use ($options): void {
                    $subquery->selectRaw('1')->from('races')->whereColumn('races.race_day_id', 'race_days.id')->where('races.id', $options['race_id']);
                });
            })
            ->orderBy('race_days.race_date')
            ->limit(isset($options['limit']) ? (int) $options['limit'] : PHP_INT_MAX);
    }
}
