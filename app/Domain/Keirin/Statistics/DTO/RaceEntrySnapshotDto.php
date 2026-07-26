<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

use App\Domain\Keirin\Statistics\Enums\RaceScoreValidationStatus;
use DateTimeImmutable;

final readonly class RaceEntrySnapshotDto
{
    public function __construct(
        public ?int $id,
        public int $raceEntryId,
        public int $raceId,
        public ?int $playerId,
        public int $bikeNumber,
        public ?int $frameNumber,
        public ?string $grade,
        public ?string $raceScoreRawText,
        public ?float $raceScore,
        public RaceScoreValidationStatus $validationStatus,
        public string $snapshotType,
        public string $inputSnapshotType,
        public string $snapshotHash,
        public ?DateTimeImmutable $observedAt,
        public ?string $parserVersion,
        public bool $sourceLinkMissing,
        public bool $raceScoreEligible,
        public ?int $scrapingFetchLogId,
        public string $sourceIdentityKey,
        public string $sourcePageType,
        public ?string $sourceUrl,
        public ?string $rawFilePath,
        public ?string $rawSha256,
    ) {}
}
