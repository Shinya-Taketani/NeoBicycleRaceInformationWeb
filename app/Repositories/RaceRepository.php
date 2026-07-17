<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Keirin\Scraping\DTO\RaceScheduleItemDto;
use App\Models\RaceDay;
use App\Models\RaceMeeting;
use App\Models\Racetrack;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

class RaceRepository
{
    public function upsertScheduleItem(RaceScheduleItemDto $dto, DateTimeImmutable $fetchedAt): RaceMeeting
    {
        return DB::transaction(function () use ($dto, $fetchedAt): RaceMeeting {
            $track = Racetrack::query()->updateOrCreate(
                [
                    'source' => (string) config('keirin.source'),
                    'external_track_id' => $dto->trackCode,
                ],
                ['name' => $dto->trackName],
            );

            $externalMeetingId = implode(':', [
                $dto->trackCode,
                $dto->startsOn->format('Ymd'),
                $dto->encryptedParameter ?: 'schedule',
            ]);

            $meeting = RaceMeeting::query()->updateOrCreate(
                [
                    'source' => (string) config('keirin.source'),
                    'external_meeting_id' => $externalMeetingId,
                ],
                [
                    'racetrack_id' => $track->id,
                    'meeting_name' => $dto->trackName.' '.$dto->grade,
                    'grade' => $dto->grade,
                    'starts_on' => $dto->startsOn->format('Y-m-d'),
                    'ends_on' => $dto->startsOn->modify('+'.($dto->durationDays - 1).' days')->format('Y-m-d'),
                    'duration_days' => $dto->durationDays,
                    'race_list_url' => $dto->raceListUrl,
                    'encrypted_parameter' => $dto->encryptedParameter,
                    'day_kind' => $dto->dayKind,
                    'last_fetched_at' => $fetchedAt,
                ],
            );

            for ($offset = 0; $offset < $dto->durationDays; $offset++) {
                $raceDate = $dto->startsOn->modify("+{$offset} days");
                RaceDay::query()->updateOrCreate(
                    [
                        'external_race_day_id' => $externalMeetingId.':day:'.($offset + 1),
                    ],
                    [
                        'race_meeting_id' => $meeting->id,
                        'race_date' => $raceDate->format('Y-m-d'),
                        'day_number' => $offset + 1,
                        'race_list_url' => $dto->raceListUrl,
                        'last_fetched_at' => $fetchedAt,
                    ],
                );
            }

            return $meeting;
        });
    }
}
