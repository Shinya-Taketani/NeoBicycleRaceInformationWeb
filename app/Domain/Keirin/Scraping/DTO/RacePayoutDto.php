<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\DTO;

readonly class RacePayoutDto
{
    public function __construct(
        public string $betTypeCode,
        public string $combination,
        public ?int $payoutAmount,
        public ?int $popularity,
        public int $sequence,
    ) {}
}
