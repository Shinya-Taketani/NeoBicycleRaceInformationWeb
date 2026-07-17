<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Keirin\Scraping\DTO\RaceScheduleItemDto;
use App\Models\Race;
use App\Models\Racetrack;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

class RaceRepository
{
    public function upsertScheduleItem(RaceScheduleItemDto $dto, DateTimeImmutable $fetchedAt): Race
    {
        return DB::transaction(function () use ($dto, $fetchedAt): Race {
            $track = Racetrack::query()->updateOrCreate(
                [
                    'source' => (string) config('keirin.source'),
                    'external_track_id' => $dto->trackCode,
                ],
                ['name' => $dto->trackName],
            );

            $externalRaceId = implode(':', [
                $dto->trackCode,
                $dto->startsOn->format('Ymd'),
                $dto->encryptedParameter ?: 'schedule',
            ]);

            return Race::query()->updateOrCreate(
                [
                    'source' => (string) config('keirin.source'),
                    'external_race_id' => $externalRaceId,
                ],
                [
                    'racetrack_id' => $track->id,
                    'race_date' => $dto->startsOn->format('Y-m-d'),
                    'grade' => $dto->grade,
                    'race_card_url' => $dto->raceListUrl,
                    'result_status' => 'UNAVAILABLE',
                    'last_fetched_at' => $fetchedAt,
                ],
            );
        });
    }
}
