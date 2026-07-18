<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\DTO;

use DateTimeImmutable;

readonly class PlayerGradeHistoryDto
{
    public function __construct(
        public ?string $grade,
        public ?DateTimeImmutable $assignedOn,
    ) {}
}
