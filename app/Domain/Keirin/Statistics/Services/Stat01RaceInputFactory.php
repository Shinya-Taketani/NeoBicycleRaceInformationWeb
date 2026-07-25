<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Services;

use App\Domain\Keirin\Statistics\DTO\Stat01EntryInputDto;
use App\Domain\Keirin\Statistics\DTO\Stat01RaceInputDto;
use App\Domain\Keirin\Statistics\Enums\StatisticAcquisitionMode;
use App\Models\Race;
use App\Models\RaceEntry;
use DateTimeImmutable;
use DateTimeZone;

final class Stat01RaceInputFactory
{
    public function make(Race $race): Stat01RaceInputDto
    {
        $entries = $race->relationLoaded('entries')
            ? $race->entries
            : $race->entries()->orderBy('id')->get();

        return new Stat01RaceInputDto(
            raceId: (int) $race->id,
            source: (string) $race->source,
            raceDate: $race->race_date->format('Y-m-d'),
            scheduledStartAt: $race->scheduled_start_at,
            entries: $entries
                ->sortBy('id')
                ->map(fn (RaceEntry $entry): Stat01EntryInputDto => new Stat01EntryInputDto(
                    raceEntryId: (int) $entry->id,
                    playerId: $entry->player_id === null ? null : (int) $entry->player_id,
                    bikeNumber: (int) $entry->bike_number,
                    raceScore: $entry->race_score,
                    fetchedAt: $entry->fetched_at,
                    acquisitionMode: $this->acquisitionMode($race, $entry),
                ))
                ->values()
                ->all(),
        );
    }

    private function acquisitionMode(Race $race, RaceEntry $entry): StatisticAcquisitionMode
    {
        $fetchedAt = $entry->fetched_at;
        if (! $fetchedAt instanceof DateTimeImmutable) {
            return StatisticAcquisitionMode::Unknown;
        }

        $scheduledStartAt = $race->scheduled_start_at;
        if ($scheduledStartAt instanceof DateTimeImmutable) {
            return $fetchedAt <= $scheduledStartAt
                ? StatisticAcquisitionMode::LivePreRace
                : StatisticAcquisitionMode::HistoricalRaceCard;
        }

        $timezone = new DateTimeZone((string) config('app.timezone', 'Asia/Tokyo'));
        $raceDateEnd = new DateTimeImmutable($race->race_date->format('Y-m-d').' 23:59:59', $timezone);

        return $fetchedAt > $raceDateEnd
            ? StatisticAcquisitionMode::HistoricalRaceCard
            : StatisticAcquisitionMode::Unknown;
    }
}
