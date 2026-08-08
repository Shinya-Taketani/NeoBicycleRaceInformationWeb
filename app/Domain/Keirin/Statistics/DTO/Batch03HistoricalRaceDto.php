<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

use App\Domain\Keirin\Statistics\Enums\HistoricalResultState;
use App\Domain\Keirin\Statistics\Enums\RaceStage;
use DateTimeImmutable;

readonly class Batch03HistoricalRaceDto
{
    public function __construct(
        public int $playerId,
        public int $raceId,
        public int $raceEntryId,
        public DateTimeImmutable $scheduledStartAt,
        public ?int $racetrackId,
        public ?int $raceMeetingId,
        public ?int $dayNumber,
        public ?int $meetingDurationDays,
        public ?string $meetingGrade,
        public ?string $meetingDayKind,
        public ?string $rawRaceType,
        public ?string $rawRaceName,
        public RaceStage $normalizedStage,
        public int $entrantCount,
        public HistoricalResultState $resultState,
        public bool $tied,
        public ?int $rank,
        public ?string $raceScore,
        public ?float $finishStrengthPercentile,
        public ?float $scoreExpectationResidual,
        public string $historicalScoreContextHash,
        public ?float $raceScoreMean,
        public ?float $raceScoreMax,
        public ?float $raceScoreStddevPop,
        public ?float $subjectScorePercentile,
        public DateTimeImmutable $raceEntryFetchedAt,
        public DateTimeImmutable $raceResultFetchedAt,
        public ?DateTimeImmutable $resultConfirmedAt,
    ) {}

    public function started(): bool
    {
        return $this->resultState->started();
    }

    public function normalFinish(): bool
    {
        return $this->resultState === HistoricalResultState::NormalFinish;
    }

    public function abnormal(): bool
    {
        return $this->resultState->abnormal();
    }
}
