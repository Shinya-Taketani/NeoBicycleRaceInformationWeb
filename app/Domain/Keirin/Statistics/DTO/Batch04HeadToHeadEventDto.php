<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

use App\Domain\Keirin\Statistics\Enums\HistoricalResultState;
use DateTimeImmutable;

readonly class Batch04HeadToHeadEventDto
{
    public function __construct(
        public int $raceId,
        public DateTimeImmutable $scheduledStartAt,
        public int $entrantCount,
        public ?int $racetrackId,
        public int $firstPlayerId,
        public int $secondPlayerId,
        public int $firstRaceEntryId,
        public int $secondRaceEntryId,
        public int $firstBikeNumber,
        public int $secondBikeNumber,
        public ?int $firstFrameNumber,
        public ?int $secondFrameNumber,
        public HistoricalResultState $firstResultState,
        public HistoricalResultState $secondResultState,
        public bool $firstTied,
        public bool $secondTied,
        public ?int $firstRank,
        public ?int $secondRank,
        public ?float $firstFinishPercentile,
        public ?float $secondFinishPercentile,
        public ?string $firstRaceScore,
        public ?string $secondRaceScore,
        public ?float $firstScorePercentile,
        public ?float $secondScorePercentile,
        public string $historicalScoreContextHash,
        public DateTimeImmutable $firstRaceEntryFetchedAt,
        public DateTimeImmutable $secondRaceEntryFetchedAt,
        public ?DateTimeImmutable $firstRaceResultFetchedAt,
        public ?DateTimeImmutable $secondRaceResultFetchedAt,
        public ?DateTimeImmutable $resultConfirmedAt,
    ) {}

    public function pairKey(): string
    {
        return $this->firstPlayerId.':'.$this->secondPlayerId;
    }
}
