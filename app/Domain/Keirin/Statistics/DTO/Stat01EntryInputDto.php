<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

use DateTimeImmutable;

readonly class Stat01EntryInputDto
{
    public function __construct(
        public int $id,
        public ?int $playerId,
        public int $bikeNumber,
        public ?string $grade,
        public ?string $raceScore,
        public ?DateTimeImmutable $fetchedAt,
    ) {}
}
