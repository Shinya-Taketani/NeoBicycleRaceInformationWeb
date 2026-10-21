<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

use DateTimeImmutable;

readonly class Stat38InputDto
{
    /** @param iterable<array<string, mixed>> $history */
    public function __construct(
        public int $raceId,
        public string $raceDate,
        public DateTimeImmutable $inputAsOf,
        public array $entry,
        public ?array $predictionObservation,
        public ?array $retrospectiveObservation,
        public iterable $history,
    ) {}
}
