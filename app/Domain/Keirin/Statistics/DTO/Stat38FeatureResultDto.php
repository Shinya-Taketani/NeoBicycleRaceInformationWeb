<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

use App\Domain\Keirin\Statistics\Enums\StatisticFeatureResultStatus;
use App\Domain\Keirin\Statistics\Enums\StatisticQualityStatus;

readonly class Stat38FeatureResultDto
{
    public function __construct(
        public StatisticFeatureResultStatus $status,
        public StatisticQualityStatus $qualityStatus,
        public array $features,
        public array $evidence,
        public string $inputHash,
    ) {}
}
