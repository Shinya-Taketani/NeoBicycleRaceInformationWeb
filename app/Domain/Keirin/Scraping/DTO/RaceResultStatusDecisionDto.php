<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\DTO;

use App\Domain\Keirin\Scraping\Enums\RaceResultStatus;

readonly class RaceResultStatusDecisionDto
{
    public function __construct(
        public ?RaceResultStatus $status,
        public string $evidence,
    ) {}
}
