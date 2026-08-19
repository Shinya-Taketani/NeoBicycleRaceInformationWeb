<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt03CenteredRacePayloadDto
{
    /** @param list<Bt03CenteredResidualEntryDto> $entries */
    public function __construct(
        public int $raceId,
        public array $entries,
    ) {}
}
