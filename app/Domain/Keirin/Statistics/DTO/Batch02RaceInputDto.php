<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

readonly class Batch02RaceInputDto
{
    /**
     * @param  list<Batch02TargetEntryDto>  $entries
     * @param  array<int, list<HistoricalRaceDto>>  $historiesByPlayer
     */
    public function __construct(
        public int $raceId,
        public array $entries,
        public array $historiesByPlayer,
    ) {}
}
