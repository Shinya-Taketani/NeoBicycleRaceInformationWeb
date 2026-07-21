<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Services;

use App\Domain\Keirin\Scraping\DTO\RaceEntryListPageDto;
use App\Domain\Keirin\Scraping\Enums\RaceCategory;
use App\Domain\Keirin\Scraping\Exceptions\ParserException;
use App\Domain\Keirin\Scraping\Exceptions\RaceDayMetadataUnavailableException;
use App\Domain\Keirin\Scraping\Exceptions\RaceEntryListUnavailableException;
use App\Domain\Keirin\Scraping\Fetchers\RaceDayMetadataFetcher;
use App\Domain\Keirin\Scraping\Fetchers\RaceEntryListFetcher;
use App\Domain\Keirin\Scraping\Fetchers\RaceListPageFetcher;
use App\Domain\Keirin\Scraping\Parsers\RaceDayMetadataParser;
use App\Domain\Keirin\Scraping\Parsers\RaceEntryListParser;
use App\Domain\Keirin\Scraping\Parsers\RaceListConsistencyValidator;
use App\Models\BatchRun;
use App\Models\RaceDay;
use App\Models\RaceMeeting;
use App\Models\Racetrack;
use App\Repositories\RaceRepository;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
                    $targetDays = $this->reconciledTargetDays($meeting, $targetDays, $from, $to, $options);
                    $this->batchRuns->succeedItem($meetingItem, ['days' => count($meetingMetadata->days)]);
                } catch (RaceDayMetadataUnavailableException $exception) {
                    try {
                        $this->assertMeetingUnavailableContext($exception, $meeting);
                    } catch (Throwable $throwable) {
                        $failed += $targetDays->count();
                        $lastError = $throwable->getMessage();
                        $this->batchRuns->failItem(
                            $meetingItem,
                            $throwable::class,
                            $throwable->getMessage(),
                            $this->meetingUnavailableMetadata($exception, $meeting, $targetDays->count(), $raceListRaw->rawFilePath),
                        );

                        continue;
                    }
                    $skipped += $targetDays->count();
                    $this->batchRuns->skipItem(
                        $meetingItem,
                        $exception->reason,
                        $this->meetingUnavailableMetadata($exception, $meeting, $targetDays->count(), $raceListRaw->rawFilePath),
                    );

                    continue;
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
                        $entryPage = $this->entryListParser->parse($entriesRaw->utf8Body);
                        $metadata = $this->metadataParser->parse($metadataRaw->utf8Body);
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
                    } catch (RaceEntryListUnavailableException $exception) {
                        try {
                            $this->assertUnavailableContext($exception, $day, $meeting);
                        } catch (Throwable $throwable) {
                            $failed++;
                            $lastError = $throwable->getMessage();
                            $this->batchRuns->failItem($item, $throwable::class, $throwable->getMessage(), [
                                'race_day_id' => (int) $day->id,
                                'race_date' => $day->race_date->format('Y-m-d'),
                                'reason' => $exception->reason,
                                'message' => $exception->getMessage(),
                                'evidence' => $exception->evidence,
                                'raw_file_path' => $entriesRaw->rawFilePath,
                                'metadata_raw_file_path' => $metadataRaw->rawFilePath,
                            ]);

                            continue;
                        }
                        $skipped++;
                        $this->batchRuns->skipItem($item, $exception->reason, [
                            'race_day_id' => (int) $day->id,
                            'race_date' => $day->race_date->format('Y-m-d'),
                            'reason' => $exception->reason,
                            'message' => $exception->getMessage(),
                            'evidence' => $exception->evidence,
                            'raw_file_path' => $entriesRaw->rawFilePath,
                            'metadata_raw_file_path' => $metadataRaw->rawFilePath,
                        ]);
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

    private function assertMeetingUnavailableContext(
        RaceDayMetadataUnavailableException $exception,
        RaceMeeting $meeting,
    ): void {
        if ($exception->reason !== RaceDayMetadataUnavailableException::REASON_RACE_MEETING_CANCELLED) {
            throw new ParserException('PJ0301 meeting metadata unavailable reason was unsupported.');
        }

        $trackCode = Racetrack::query()->whereKey($meeting->racetrack_id)->value('external_track_id');
        $storedDates = RaceDay::query()
            ->where('race_meeting_id', $meeting->id)
            ->orderBy('race_date')
            ->get()
            ->map(fn (RaceDay $day): string => $day->race_date->format('Ymd'))
            ->all();
        $meetingStart = $meeting->starts_on?->format('Ymd');
        $firstStoredDate = $storedDates[0] ?? null;
        if (! is_string($trackCode)
            || ($exception->evidence['selKjyoCd'] ?? null) !== $trackCode
            || ! in_array($exception->evidence['selKaisai'] ?? null, [$meetingStart, $firstStoredDate], true)
            || ($exception->evidence['raceDates'] ?? null) !== $storedDates) {
            throw new ParserException('PJ0301 cancelled meeting metadata did not match the stored race meeting.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function meetingUnavailableMetadata(
        RaceDayMetadataUnavailableException $exception,
        RaceMeeting $meeting,
        int $targetDayCount,
        string $rawFilePath,
    ): array {
        return [
            'race_meeting_id' => (int) $meeting->id,
            'external_meeting_id' => $meeting->external_meeting_id,
            'starts_on' => $meeting->starts_on?->format('Y-m-d'),
            'ends_on' => $meeting->ends_on?->format('Y-m-d'),
            'target_day_count' => $targetDayCount,
            'reason' => $exception->reason,
            'message' => $exception->getMessage(),
            'evidence' => $exception->evidence,
            'raw_file_path' => $rawFilePath,
        ];
    }

    private function assertUnavailableContext(
        RaceEntryListUnavailableException $exception,
        RaceDay $day,
        RaceMeeting $meeting,
    ): void {
        if ($exception->reason !== RaceEntryListUnavailableException::REASON_RACE_DAY_CANCELLED) {
            return;
        }

        $trackCode = Racetrack::query()->whereKey($meeting->racetrack_id)->value('external_track_id');
        $responseTrackCode = $exception->evidence['reqprm.bkcd'] ?? null;
        $responseRaceDate = $exception->evidence['reqprm.kday'] ?? null;
        if (! is_string($trackCode)
            || $responseTrackCode !== $trackCode
            || $responseRaceDate !== $day->race_date->format('Ymd')) {
            throw new ParserException('JSJ017 cancelled response request parameters did not match the target race day.');
        }
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

    /**
     * @param  Collection<int, RaceDay>  $originalDays
     * @return Collection<int, RaceDay>
     */
    private function reconciledTargetDays(
        RaceMeeting $meeting,
        Collection $originalDays,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        array $options,
    ): Collection {
        $query = RaceDay::query()
            ->where('race_meeting_id', $meeting->id)
            ->whereDate('race_date', '>=', $from->format('Y-m-d'))
            ->whereDate('race_date', '<=', $to->format('Y-m-d'))
            ->orderBy('race_date');

        if (isset($options['limit']) || isset($options['race_id'])) {
            $query->whereIn('id', $originalDays->pluck('id')->all());
        }

        return $query->get();
    }
}
