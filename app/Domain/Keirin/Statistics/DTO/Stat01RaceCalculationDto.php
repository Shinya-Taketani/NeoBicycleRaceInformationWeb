<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

final readonly class Stat01RaceCalculationDto
{
    /**
     * @param  list<Stat01EntryResultDto>  $results
     */
    public function __construct(
        public int $raceId,
        public string $inputHash,
        public array $results,
    ) {}
}
