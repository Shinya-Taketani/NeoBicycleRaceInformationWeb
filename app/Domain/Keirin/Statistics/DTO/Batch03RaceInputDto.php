<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

readonly class Batch03RaceInputDto
{
    /**
     * @param  list<Batch03TargetEntryDto>  $entries
     * @param  array<int, list<Batch03HistoricalRaceDto>>  $historiesByPlayer
     */
    public function __construct(
        public int $raceId,
        public array $entries,
        public array $historiesByPlayer,
    ) {}
}
