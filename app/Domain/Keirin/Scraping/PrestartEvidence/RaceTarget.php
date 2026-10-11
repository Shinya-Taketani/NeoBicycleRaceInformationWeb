<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartEvidence;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class RaceTarget
{
    /** @param array<int, string> $registrations Bike number => registration (including leading zeros). */
    public function __construct(
        public string $raceDate,
        public string $trackCode,
        public int $raceNumber,
        public array $registrations,
        public ?int $raceId = null,
    ) {
        $date = DateTimeImmutable::createFromFormat('!Ymd', $raceDate);
        if ($date === false || $date->format('Ymd') !== $raceDate || $raceDate >= '20260101'
            || preg_match('/^[0-9]+$/D', $trackCode) !== 1 || $raceNumber < 1 || $raceNumber > 99
            || count($registrations) < 5 || count($registrations) > 9 || ($raceId !== null && $raceId < 1)) {
            throw new InvalidArgumentException('Invalid or forbidden synthetic race target.');
        }
        foreach ($registrations as $bike => $registration) {
            if (! is_int($bike) || $bike < 1 || $bike > 9 || ! is_string($registration)
                || preg_match('/^[0-9]+$/D', $registration) !== 1) {
                throw new InvalidArgumentException('Invalid synthetic entrant identity.');
            }
        }
        if (count(array_unique($registrations)) !== count($registrations)) {
            throw new InvalidArgumentException('Duplicate synthetic registration.');
        }
    }

    public function toArray(): array
    {
        $entries = [];
        foreach ($this->registrations as $bike => $registration) {
            $entries[] = ['bike_number' => $bike, 'external_player_id' => $registration];
        }

        return ['race_date' => $this->raceDate, 'track_code' => $this->trackCode,
            'race_number' => $this->raceNumber, 'race_id' => $this->raceId, 'entries' => $entries];
    }
}
