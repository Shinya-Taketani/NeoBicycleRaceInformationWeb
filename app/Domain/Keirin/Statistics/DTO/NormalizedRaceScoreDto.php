<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

use App\Domain\Keirin\Statistics\Enums\RaceScoreValidationStatus;

final readonly class NormalizedRaceScoreDto
{
    public function __construct(
        public ?string $rawText,
        public ?float $value,
        public RaceScoreValidationStatus $status,
    ) {}
}
