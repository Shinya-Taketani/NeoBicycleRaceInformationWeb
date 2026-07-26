<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Services;

use App\Domain\Keirin\Statistics\DTO\RaceEntrySnapshotDto;
use App\Domain\Keirin\Statistics\DTO\Stat01EntryInputDto;
use App\Domain\Keirin\Statistics\DTO\Stat01RaceInputDto;
use App\Domain\Keirin\Statistics\DTO\StatInputAsOfDto;
use App\Models\Race;

final class Stat01RaceInputFactory
{
    /**
     * @param  list<RaceEntrySnapshotDto>  $snapshots
     */
    public function make(Race $race, array $snapshots, StatInputAsOfDto $inputAsOf): Stat01RaceInputDto
    {
        return new Stat01RaceInputDto(
            raceId: (int) $race->id,
            source: (string) $race->source,
            inputAsOf: $inputAsOf->value,
            inputAsOfPolicy: $inputAsOf->policy,
            entries: array_map(static fn (RaceEntrySnapshotDto $snapshot): Stat01EntryInputDto => new Stat01EntryInputDto(
                raceEntryId: $snapshot->raceEntryId,
                raceEntrySnapshotId: $snapshot->id,
                playerId: $snapshot->playerId,
                bikeNumber: $snapshot->bikeNumber,
                raceScore: $snapshot->raceScore,
                validationStatus: $snapshot->validationStatus,
                snapshotHash: $snapshot->snapshotHash,
                sourceFingerprint: $snapshot->sourceFingerprint,
                inputSnapshotType: $snapshot->inputSnapshotType,
                sourceLinkMissing: $snapshot->sourceLinkMissing,
                raceScoreEligible: $snapshot->raceScoreEligible,
                fetchedAt: $snapshot->observedAt,
            ), $snapshots),
        );
    }
}
