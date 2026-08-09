<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

use App\Domain\Keirin\Statistics\Enums\StatisticFeatureResultStatus;
use App\Domain\Keirin\Statistics\Enums\StatisticQualityStatus;
use DateTimeImmutable;

readonly class Batch05FeatureResultDto
{
    /**
     * @param  array<string, mixed>  $features
     * @param  array<string, mixed>  $evidence
     */
    public function __construct(
        public int $raceId,
        public DateTimeImmutable $inputAsOf,
        public ?DateTimeImmutable $sourceFetchedAt,
        public StatisticFeatureResultStatus $status,
        public StatisticQualityStatus $qualityStatus,
        public array $features,
        public array $evidence,
        public string $inputHash,
    ) {}
}
