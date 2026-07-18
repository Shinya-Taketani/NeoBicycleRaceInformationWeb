<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\DTO;

readonly class PlayerSummaryDto
{
    public function __construct(
        public string $externalPlayerId,
        public string $name,
        public ?string $nameKana,
        public ?string $grade,
        public ?string $district,
        public ?string $prefecture,
        public ?string $graduationPeriod,
        public ?int $age,
        public ?string $homeBank,
        public ?string $ridingStyle,
        public ?string $detailUrl,
        public string $gender = 'male',
    ) {}
}
