<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

readonly class Batch05RaceInputDto
{
    public DateTimeImmutable $inputAsOf;

    /**
     * @param  list<Batch05TargetEntryDto>  $entries
     */
    public function __construct(
        public int $raceId,
        public array $entries,
        public int $stat01RunId = 1,
    ) {
        if ($entries === []) {
            throw new InvalidArgumentException("Batch05 race {$raceId} had no STAT-01 entries.");
        }
        $inputAsOfValues = [];
        foreach ($entries as $entry) {
            $inputAsOfValues[$entry->inputAsOf->format('Y-m-d H:i:s.uP')] = $entry->inputAsOf;
        }
        if (count($inputAsOfValues) !== 1) {
            throw new InvalidArgumentException("Batch05 race {$raceId} had inconsistent input_as_of values.");
        }
        $this->inputAsOf = array_values($inputAsOfValues)[0];
    }
}
