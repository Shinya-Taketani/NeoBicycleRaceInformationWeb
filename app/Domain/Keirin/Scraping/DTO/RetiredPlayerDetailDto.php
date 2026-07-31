<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\DTO;

use DateTimeImmutable;

readonly class RetiredPlayerDetailDto
{
    public function __construct(
        public string $externalPlayerId,
        public string $registrationNumber,
        public string $name,
        public ?string $prefecture,
        public ?int $age,
        public ?string $graduationPeriod,
        public ?string $grade,
        public DateTimeImmutable $retiredOn,
        public ?DateTimeImmutable $sourceUpdatedAt,
        public string $sourceUrl,
    ) {}
}
