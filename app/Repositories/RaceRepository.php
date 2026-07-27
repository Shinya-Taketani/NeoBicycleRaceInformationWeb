<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Keirin\Scraping\DTO\RaceDayMetadataPageDto;
use App\Domain\Keirin\Scraping\DTO\RaceDetailEntryDto;
use App\Domain\Keirin\Scraping\DTO\RaceDetailPageDto;
use App\Domain\Keirin\Scraping\DTO\RaceEntryListPageDto;
use App\Domain\Keirin\Scraping\DTO\RaceParameterDto;
use App\Domain\Keirin\Scraping\DTO\RaceScheduleItemDto;
use App\Domain\Keirin\Scraping\Exceptions\ParserException;
use App\Models\Player;
use App\Models\Race;
use App\Models\RaceDay;
use App\Models\RaceEntry;
use App\Models\RaceMeeting;
use App\Models\Racetrack;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class RaceRepository
{
    public function upsertScheduleItem(RaceScheduleItemDto $dto, DateTimeImmutable $fetchedAt): RaceMeeting
    {
        return DB::transaction(function () use ($dto, $fetchedAt): RaceMeeting {
            $source = (string) config('keirin.source');
            $track = Racetrack::query()->updateOrCreate(
                [
                    'source' => $source,
                    'external_track_id' => $dto->trackCode,
                ],
                ['name' => $dto->trackName],
            );

            $externalMeetingId = implode(':', [
                $dto->trackCode,
                $dto->startsOn->format('Ymd'),
                $dto->encryptedParameter ?: 'schedule',
            ]);

            $meeting = $this->findScheduleMeeting(
                $source,
                (int) $track->id,
                $dto->encryptedParameter,
                $externalMeetingId,
            );
            if (! $meeting instanceof RaceMeeting) {
                $meeting = new RaceMeeting([
                    'source' => $source,
                    'external_meeting_id' => $externalMeetingId,
                    'racetrack_id' => $track->id,
                    'starts_on' => $dto->startsOn->format('Y-m-d'),
                    'ends_on' => $dto->startsOn->modify('+'.($dto->durationDays - 1).' days')->format('Y-m-d'),
                    'duration_days' => $dto->durationDays,
                ]);
            }
            $meeting->forceFill([
                'meeting_name' => $dto->trackName.' '.$dto->grade,
                'grade' => $dto->grade ?? $meeting->grade,
                'race_list_url' => $dto->raceListUrl ?? $meeting->race_list_url,
                'encrypted_parameter' => $dto->encryptedParameter ?? $meeting->encrypted_parameter,
                'day_kind' => $dto->dayKind ?? $meeting->day_kind,
                'last_fetched_at' => $fetchedAt,
            ])->save();

            for ($offset = 0; $offset < $dto->durationDays; $offset++) {
                $raceDate = $dto->startsOn->modify("+{$offset} days");
                $date = $raceDate->format('Y-m-d');
                $day = RaceDay::query()
                    ->where('race_meeting_id', $meeting->id)
                    ->whereDate('race_date', $date)
                    ->lockForUpdate()
                    ->first();
                if (! $day instanceof RaceDay) {
                    $day = new RaceDay([
                        'race_meeting_id' => $meeting->id,
                        'external_race_day_id' => $this->newRaceDayExternalId($meeting, $raceDate),
                        'race_date' => $date,
                        'day_number' => 1,
                    ]);
                }
                $day->forceFill([
                    'race_list_url' => $dto->raceListUrl ?? $day->race_list_url,
                    'last_fetched_at' => $fetchedAt,
                ])->save();
            }

            $days = RaceDay::query()
                ->where('race_meeting_id', $meeting->id)
                ->lockForUpdate()
                ->orderBy('race_date')
                ->get();
            $this->renumberMeetingDays($days);
            $this->updateMeetingDateRange($meeting, $days, $fetchedAt);

            return $meeting->refresh();
        });
    }

    public function updateMeetingDayParameters(RaceMeeting $meeting, RaceDayMetadataPageDto $metadata, DateTimeImmutable $fetchedAt): void
    {
        DB::transaction(function () use ($meeting, $metadata, $fetchedAt): void {
            $lockedMeeting = RaceMeeting::query()->whereKey($meeting->id)->lockForUpdate()->firstOrFail();
            $days = RaceDay::query()
                ->where('race_meeting_id', $lockedMeeting->id)
                ->lockForUpdate()
                ->orderBy('race_date')
                ->get()
                ->keyBy(fn (RaceDay $day): string => $day->race_date->format('Ymd'));
            $track = Racetrack::query()->findOrFail($lockedMeeting->racetrack_id);
            if ($track->external_track_id !== $metadata->trackCode) {
                throw new ParserException('JSJ001 track did not match the stored race meeting.');
            }

            $metadataDates = array_map(fn ($day): string => $day->raceDate, $metadata->days);
            if ($metadataDates === [] || count($metadataDates) !== count(array_unique($metadataDates))) {
                throw new ParserException('JSJ001 meeting dates were empty or duplicated.');
            }
            $sortedMetadataDates = $metadataDates;
            sort($sortedMetadataDates);
            if ($metadataDates !== $sortedMetadataDates) {
                throw new ParserException('JSJ001 meeting dates were not in ascending order.');
            }
            foreach ($metadataDates as $metadataDate) {
                $date = DateTimeImmutable::createFromFormat('!Ymd', $metadataDate);
                if (! $date instanceof DateTimeImmutable || $date->format('Ymd') !== $metadataDate) {
                    throw new ParserException("JSJ001 meeting date {$metadataDate} was invalid.");
                }
            }

            $databaseOnlyDates = array_diff($days->keys()->all(), $metadataDates);
            foreach ($databaseOnlyDates as $databaseOnlyDate) {
                $day = $days->get($databaseOnlyDate);
                $relatedRaceCount = $day instanceof RaceDay
                    ? Race::query()->where('race_day_id', $day->id)->count()
                    : 0;
                if ($relatedRaceCount > 0) {
                    throw new ParserException("Stored race_day {$databaseOnlyDate} had related races ({$relatedRaceCount}) and could not be removed.");
                }
            }
            foreach ($metadataDates as $metadataDate) {
                if ($days->has($metadataDate)) {
                    continue;
                }
                $externalRaceDayId = $lockedMeeting->external_meeting_id.':date:'.$metadataDate;
                if (RaceDay::query()->where('external_race_day_id', $externalRaceDayId)->exists()) {
                    throw new ParserException("Race day external ID {$externalRaceDayId} already existed.");
                }
            }

            foreach ($databaseOnlyDates as $databaseOnlyDate) {
                $day = $days->get($databaseOnlyDate);
                if ($day instanceof RaceDay) {
                    $day->delete();
                }
            }
            foreach ($metadata->days as $dayParameter) {
                $day = $days->get($dayParameter->raceDate);
                if (! $day instanceof RaceDay) {
                    $raceDate = DateTimeImmutable::createFromFormat('!Ymd', $dayParameter->raceDate);
                    if (! $raceDate instanceof DateTimeImmutable) {
                        throw new ParserException("JSJ001 meeting date {$dayParameter->raceDate} was invalid.");
                    }
                    $day = new RaceDay([
                        'race_meeting_id' => $lockedMeeting->id,
                        'external_race_day_id' => $this->newRaceDayExternalId($lockedMeeting, $raceDate),
                        'race_date' => $raceDate->format('Y-m-d'),
                        'day_number' => 1,
                        'race_list_url' => $lockedMeeting->race_list_url,
                    ]);
                }
                $day->forceFill([
                    'encrypted_parameter' => $dayParameter->encryptedParameter,
                    'last_fetched_at' => $fetchedAt,
                ])->save();
            }

            $reconciledDays = RaceDay::query()
                ->where('race_meeting_id', $lockedMeeting->id)
                ->lockForUpdate()
                ->orderBy('race_date')
                ->get();
            $this->renumberMeetingDays($reconciledDays);
            $this->updateMeetingDateRange($lockedMeeting, $reconciledDays, $fetchedAt);
        });
    }

    private function findScheduleMeeting(
        string $source,
        int $racetrackId,
        ?string $encryptedParameter,
        string $externalMeetingId,
    ): ?RaceMeeting {
        if (is_string($encryptedParameter) && $encryptedParameter !== '') {
            $matches = RaceMeeting::query()
                ->where('source', $source)
                ->where('racetrack_id', $racetrackId)
                ->where('encrypted_parameter', $encryptedParameter)
                ->lockForUpdate()
                ->get();
            if ($matches->count() > 1) {
                throw new ParserException('Multiple race meetings had the same track and encrypted parameter.');
            }

            return $matches->first();
        }

        return RaceMeeting::query()
            ->where('source', $source)
            ->where('external_meeting_id', $externalMeetingId)
            ->lockForUpdate()
            ->first();
    }

    private function newRaceDayExternalId(RaceMeeting $meeting, DateTimeImmutable $raceDate): string
    {
        $externalRaceDayId = $meeting->external_meeting_id.':date:'.$raceDate->format('Ymd');
        if (RaceDay::query()->where('external_race_day_id', $externalRaceDayId)->exists()) {
            throw new ParserException("Race day external ID {$externalRaceDayId} already existed.");
        }

        return $externalRaceDayId;
    }

    /** @param Collection<int, RaceDay> $days */
    private function renumberMeetingDays(Collection $days): void
    {
        foreach ($days->values() as $index => $day) {
            if ($day->day_number !== $index + 1) {
                $day->forceFill(['day_number' => $index + 1])->save();
            }
        }
    }

    /** @param Collection<int, RaceDay> $days */
    private function updateMeetingDateRange(RaceMeeting $meeting, Collection $days, DateTimeImmutable $fetchedAt): void
    {
        if ($days->isEmpty()) {
            throw new ParserException('Race meeting had no race_days after reconciliation.');
        }

        $meeting->forceFill([
            'starts_on' => $days->first()->race_date,
            'ends_on' => $days->last()->race_date,
            'duration_days' => $days->count(),
            'last_fetched_at' => $fetchedAt,
        ])->save();
    }

    /**
     * @param  array<int,RaceParameterDto>  $parametersByRaceNumber
     * @return array{races:int,entries:int,unresolved_players:int}
     */
    public function syncRaceDay(
        RaceDay $day,
        RaceDayMetadataPageDto $metadata,
        RaceEntryListPageDto $entryPage,
        array $parametersByRaceNumber,
        DateTimeImmutable $fetchedAt,
    ): array {
        return DB::transaction(function () use ($day, $metadata, $entryPage, $parametersByRaceNumber, $fetchedAt): array {
            $lockedDay = RaceDay::query()->whereKey($day->id)->lockForUpdate()->firstOrFail();
            $meeting = RaceMeeting::query()->findOrFail($lockedDay->race_meeting_id);
            $track = Racetrack::query()->findOrFail($meeting->racetrack_id);
            if ($track->external_track_id !== $metadata->trackCode || $lockedDay->race_date->format('Ymd') !== $entryPage->raceDate) {
                throw new ParserException('Race day database context did not match JSJ001 and JSJ017.');
            }

            $externalPlayerIds = [];
            foreach ($entryPage->races as $race) {
                foreach ($race->entries as $entry) {
                    $externalPlayerIds[] = $entry->externalPlayerId;
                }
            }
            $players = Player::query()
                ->where('source', (string) config('keirin.source'))
                ->whereIn('external_player_id', array_values(array_unique($externalPlayerIds)))
                ->get()
                ->keyBy('external_player_id');

            $entryCount = 0;
            $unresolved = 0;
            foreach ($entryPage->races as $raceDto) {
                $parameter = $parametersByRaceNumber[$raceDto->raceNumber] ?? null;
                if (! $parameter instanceof RaceParameterDto) {
                    throw new ParserException("Race {$raceDto->raceNumber} encrypted parameter was missing.");
                }
                $externalRaceId = sprintf('%s:%s:%02d', $metadata->trackCode, $entryPage->raceDate, $raceDto->raceNumber);
                $race = Race::query()->updateOrCreate(
                    [
                        'source' => (string) config('keirin.source'),
                        'external_race_id' => $externalRaceId,
                    ],
                    [
                        'race_day_id' => $lockedDay->id,
                        'racetrack_id' => $track->id,
                        'race_date' => $lockedDay->race_date,
                        'race_number' => $raceDto->raceNumber,
                        'scheduled_start_at' => $this->dateTime($entryPage->raceDate, $raceDto->startTime),
                        'sales_close_at' => $this->dateTime($entryPage->raceDate, $raceDto->salesCloseTime),
                        'name' => $raceDto->raceType,
                        'grade' => $metadata->grade,
                        'race_type' => $raceDto->raceType,
                        'entrant_count' => count($raceDto->entries),
                        'encrypted_parameter' => $parameter->encryptedParameter,
                        'race_card_url' => $this->raceLiveUrl(),
                        'result_url' => $this->raceLiveUrl(),
                        'result_available' => $parameter->resultAvailable || $raceDto->resultAvailable,
                        'last_fetched_at' => $fetchedAt,
                    ],
                );

                $seenBikes = [];
                foreach ($raceDto->entries as $entryDto) {
                    $seenBikes[] = $entryDto->bikeNumber;
                    $player = $players->get($entryDto->externalPlayerId);
                    if (! $player instanceof Player) {
                        $unresolved++;
                    }
                    RaceEntry::query()->updateOrCreate(
                        ['race_id' => $race->id, 'bike_number' => $entryDto->bikeNumber],
                        [
                            'player_id' => $player?->id,
                            'external_player_id' => $entryDto->externalPlayerId,
                            'player_name' => $entryDto->playerName,
                            'prefecture' => $entryDto->prefecture,
                            'riding_style' => $entryDto->ridingStyle,
                            'fetched_at' => $fetchedAt,
                        ],
                    );
                    $entryCount++;
                }
                RaceEntry::query()->where('race_id', $race->id)->whereNotIn('bike_number', $seenBikes)->delete();
            }

            $selectedDayParameter = null;
            foreach ($metadata->days as $dayParameter) {
                if ($dayParameter->raceDate === $entryPage->raceDate) {
                    $selectedDayParameter = $dayParameter;
                    break;
                }
            }
            if ($selectedDayParameter === null) {
                throw new ParserException('JSJ001 did not contain the selected JSJ017 race date.');
            }
            $lockedDay->forceFill([
                'encrypted_parameter' => $selectedDayParameter->encryptedParameter,
                'last_fetched_at' => $fetchedAt,
            ])->save();

            return ['races' => count($entryPage->races), 'entries' => $entryCount, 'unresolved_players' => $unresolved];
        });
    }

    public function updateRaceDetail(Race $race, RaceDetailPageDto $detail, DateTimeImmutable $fetchedAt): void
    {
        DB::transaction(function () use ($race, $detail, $fetchedAt): void {
            $lockedRace = Race::query()->whereKey($race->id)->lockForUpdate()->firstOrFail();
            $track = Racetrack::query()->findOrFail($lockedRace->racetrack_id);
            if ($lockedRace->race_date->format('Ymd') !== $detail->raceDate
                || (int) $lockedRace->race_number !== $detail->raceNumber
                || $track->external_track_id !== $detail->trackCode) {
                throw new ParserException("PJ0315 context did not match race {$lockedRace->external_race_id}.");
            }

            $entries = RaceEntry::query()->where('race_id', $lockedRace->id)->lockForUpdate()->get()->keyBy('bike_number');
            $detailBikes = array_map(fn (RaceDetailEntryDto $entry): int => $entry->bikeNumber, $detail->entries);
            $storedBikes = $entries->keys()->map(fn ($number): int => (int) $number)->sort()->values()->all();
            sort($detailBikes);
            if ($detailBikes !== $storedBikes) {
                throw new ParserException("PJ0315 entrant bikes did not match race {$lockedRace->external_race_id}.");
            }

            foreach ($detail->entries as $detailEntry) {
                $entry = $entries->get($detailEntry->bikeNumber);
                if (! $entry instanceof RaceEntry || $entry->external_player_id !== $detailEntry->externalPlayerId) {
                    throw new ParserException("PJ0315 player did not match bike {$detailEntry->bikeNumber} for race {$lockedRace->external_race_id}.");
                }
                $entry->forceFill([
                    'frame_number' => $detailEntry->frameNumber,
                    'player_name' => $detailEntry->playerName,
                    'prefecture' => $detailEntry->prefecture,
                    'grade' => $detailEntry->grade,
                    'riding_style' => $detailEntry->ridingStyle,
                    'race_score' => $detailEntry->raceScore,
                    'fetched_at' => $fetchedAt,
                ])->save();
            }

            $lockedRace->forceFill([
                'name' => $detail->raceName ?? $lockedRace->name,
                'race_type' => $detail->raceType ?? $lockedRace->race_type,
                'scheduled_start_at' => $this->dateTime($detail->raceDate, $detail->startTime) ?? $lockedRace->scheduled_start_at,
                'sales_close_at' => $this->dateTime($detail->raceDate, $detail->salesCloseTime) ?? $lockedRace->sales_close_at,
                'last_fetched_at' => $fetchedAt,
            ])->save();
        });
    }

    private function dateTime(string $raceDate, ?string $time): ?DateTimeImmutable
    {
        if ($time === null) {
            return null;
        }

        $dateTime = DateTimeImmutable::createFromFormat(
            '!Ymd H:i',
            $raceDate.' '.$time,
            new DateTimeZone((string) config('app.timezone', 'Asia/Tokyo')),
        );
        if (! $dateTime instanceof DateTimeImmutable) {
            throw new ParserException("Race time {$raceDate} {$time} was invalid.");
        }

        return $dateTime;
    }

    private function raceLiveUrl(): string
    {
        return rtrim((string) config('keirin.base_url'), '/').(string) config('keirin.routes.race_live');
    }
}
