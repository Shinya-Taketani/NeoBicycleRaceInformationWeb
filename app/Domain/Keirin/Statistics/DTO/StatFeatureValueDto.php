<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

use App\Domain\Keirin\Statistics\Enums\StatFeatureStatus;
use App\Domain\Keirin\Statistics\Enums\StatFeatureValueType;

final readonly class StatFeatureValueDto
{
    public function __construct(
        public string $featureCode,
        public StatFeatureValueType $valueType,
        public string $unitCode,
        public StatFeatureStatus $status,
        public int|float|string|bool|array $value,
        public ?float $numerator = null,
        public ?float $denominator = null,
        public ?int $sampleCount = null,
        public ?string $windowType = null,
        public ?string $windowValue = null,
    ) {}
}
