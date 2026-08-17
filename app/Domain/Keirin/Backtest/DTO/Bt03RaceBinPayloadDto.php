<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt03RaceBinPayloadDto
{
    /** @param list<Bt03BinEffectEntryDto> $entries */
    public function __construct(
        public int $raceId,
        public array $entries,
    ) {}
}
