<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

final readonly class Stat01RaceCalculationDto
{
    /**
     * @param  list<Stat01EntryResultDto>  $results
     * @param  array<string,mixed>  $inputSnapshot
     */
    public function __construct(
        public int $raceId,
        public string $inputHash,
        public array $inputSnapshot,
        public array $results,
    ) {}
}
