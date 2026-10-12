<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

use App\Domain\Keirin\Statistics\Enums\StatisticFeatureResultStatus;
use App\Domain\Keirin\Statistics\Enums\StatisticQualityStatus;
use DateTimeImmutable;

readonly class Stat20FeatureResultDto
{
    public function __construct(
        public Stat20SourceInputDto $target,
        public StatisticFeatureResultStatus $status,
        public StatisticQualityStatus $qualityStatus,
        public ?DateTimeImmutable $sourceFetchedAt,
        public array $features,
        public array $evidence,
        public string $inputHash,
    ) {}
}
