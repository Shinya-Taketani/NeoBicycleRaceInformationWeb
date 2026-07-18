<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\DTO;

use App\Domain\Keirin\Scraping\Enums\RaceEntrantExpectationSource;

readonly class ExpectedRaceEntrantsDto
{
    /**
     * @param  list<int>|null  $bikeNumbers
     */
    public function __construct(
        public int $count,
        public ?array $bikeNumbers,
        public RaceEntrantExpectationSource $source,
    ) {}
}
