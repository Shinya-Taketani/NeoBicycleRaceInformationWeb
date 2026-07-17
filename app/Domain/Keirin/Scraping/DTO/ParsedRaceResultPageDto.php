<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\DTO;

use App\Domain\Keirin\Scraping\Enums\ParsedRaceResultPageStatus;

readonly class ParsedRaceResultPageDto
{
    /**
     * @param  list<RaceResultDto>  $results
     * @param  list<RacePayoutDto>  $payouts
     */
    public function __construct(
        public ParsedRaceResultPageStatus $pageStatus,
        public array $results,
        public array $payouts,
        public bool $resultMarkerFound,
        public bool $payoutMarkerFound,
        public bool $explicitNoPayoutMarker,
        public string $sourceHash,
        public string $parserVersion,
    ) {}
}
