<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

use App\Domain\Keirin\Statistics\Enums\RaceScoreValidationStatus;
use DateTimeImmutable;

final readonly class Stat01EntryInputDto
{
    public function __construct(
        public int $raceEntryId,
        public ?int $raceEntrySnapshotId,
        public ?int $sourceStateId,
        public ?int $playerId,
        public int $bikeNumber,
        public ?float $raceScore,
        public RaceScoreValidationStatus $validationStatus,
        public string $snapshotHash,
        public string $sourceFingerprint,
        public string $inputSnapshotType,
        public bool $sourceLinkMissing,
        public bool $raceScoreEligible,
        public ?DateTimeImmutable $fetchedAt,
    ) {}
}
