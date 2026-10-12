<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

use DateTimeImmutable;

readonly class Stat20RaceInputDto
{
    /**
     * @param  array<string,int>  $sourceRuns
     * @param  list<Stat20SourceInputDto>  $sources
     * @param  array<int,array{race_id:int,player_id:?int,bike_number:int}>  $entryIdentities
     */
    public function __construct(
        public int $raceId,
        public DateTimeImmutable $raceDate,
        public ?DateTimeImmutable $scheduledStartAt,
        public array $sourceRuns,
        public array $sources,
        public array $entryIdentities,
    ) {}
}
