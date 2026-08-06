<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

use App\Domain\Keirin\Statistics\Enums\StatisticFeatureResultStatus;
use App\Domain\Keirin\Statistics\Enums\StatisticQualityStatus;
use DateTimeImmutable;

readonly class Stat01EntryFeatureDto
{
    /**
     * @param  array<string, bool|float|int|null>  $features
     * @param  array<string, bool|float|int|string|array|null>  $evidence
     */
    public function __construct(
        public Stat01EntryInputDto $entry,
        public StatisticFeatureResultStatus $status,
        public StatisticQualityStatus $qualityStatus,
        public ?DateTimeImmutable $inputAsOf,
        public array $features,
        public array $evidence,
        public string $inputHash,
    ) {}
}
