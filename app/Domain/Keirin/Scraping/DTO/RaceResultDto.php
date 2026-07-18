<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\DTO;

use App\Domain\Keirin\Scraping\Enums\RaceEntryResultStatus;

readonly class RaceResultDto
{
    public function __construct(
        public ?int $rank,
        public ?int $bikeNumber,
        public ?string $playerName,
        public RaceEntryResultStatus $status,
        public ?string $winningTechnique,
        public ?string $rawText,
        public ?string $externalPlayerId = null,
        public ?int $age = null,
        public ?string $prefecture = null,
        public ?string $graduationPeriod = null,
        public ?string $grade = null,
        public ?string $margin = null,
        public ?string $finishTime = null,
        public ?string $backHome = null,
        public ?string $lineRank = null,
        public array $individualStates = [],
    ) {}
}
