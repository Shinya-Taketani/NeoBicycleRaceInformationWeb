<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\DTO;

use DateTimeImmutable;

readonly class PlayerListPageDto
{
    /**
     * @param  list<PlayerSummaryDto>  $players
     */
    public function __construct(
        public array $players,
        public ?int $totalCount,
        public int $currentPage,
        public ?int $lastPage,
        public ?DateTimeImmutable $sourceUpdatedAt,
    ) {}
}
