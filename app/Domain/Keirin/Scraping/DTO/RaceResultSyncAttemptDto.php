<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\DTO;

use App\Domain\Keirin\Scraping\Enums\RaceResultSyncAttemptStatus;

readonly class RaceResultSyncAttemptDto
{
    public function __construct(
        public RaceResultSyncAttemptStatus $status,
        public int $results = 0,
        public int $payouts = 0,
        public ?string $errorMessage = null,
    ) {}
}
