<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

use App\Domain\Keirin\Statistics\Enums\StatisticFeatureResultStatus;
use App\Domain\Keirin\Statistics\Enums\StatisticQualityStatus;

readonly class Batch04FeatureResultDto
{
    /**
     * @param  array<string, mixed>  $features
     * @param  array<string, mixed>  $evidence
     */
    public function __construct(
        public Batch04TargetEntryDto $target,
        public StatisticFeatureResultStatus $status,
        public StatisticQualityStatus $qualityStatus,
        public array $features,
        public array $evidence,
        public string $inputHash,
    ) {}
}
