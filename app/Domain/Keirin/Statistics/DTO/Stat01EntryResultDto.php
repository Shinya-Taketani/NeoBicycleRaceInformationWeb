<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

use App\Domain\Keirin\Statistics\Enums\StatDataQualityStatus;
use App\Domain\Keirin\Statistics\Enums\StatFeatureStatus;
use DateTimeImmutable;

final readonly class Stat01EntryResultDto
{
    /**
     * @param  list<StatFeatureValueDto>  $features
     */
    public function __construct(
        public int $raceEntryId,
        public ?int $raceEntrySnapshotId,
        public ?int $playerId,
        public int $bikeNumber,
        public string $inputSnapshotType,
        public StatFeatureStatus $status,
        public StatDataQualityStatus $dataQualityStatus,
        public int $validScoreCount,
        public int $missingScoreCount,
        public int $invalidScoreCount,
        public int $entrantCount,
        public ?DateTimeImmutable $sourceFetchedAt,
        public array $features,
    ) {}
}
