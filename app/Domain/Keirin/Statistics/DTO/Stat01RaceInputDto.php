<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

use DateTimeImmutable;

final readonly class Stat01RaceInputDto
{
    /**
     * @param  list<Stat01EntryInputDto>  $entries
     */
    public function __construct(
        public int $raceId,
        public string $source,
        public string $raceDate,
        public ?DateTimeImmutable $scheduledStartAt,
        public array $entries,
    ) {}
}
