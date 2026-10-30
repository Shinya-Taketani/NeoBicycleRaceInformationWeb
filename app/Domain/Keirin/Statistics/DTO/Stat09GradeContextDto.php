<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

use App\Domain\Keirin\Statistics\Support\AcquiredHistoryTimestamp;
use App\Domain\Keirin\Statistics\Support\Stat09GradeContract;
use DateTimeImmutable;

final readonly class Stat09GradeContextDto
{
    public function __construct(public int $raceEntryId, public int $raceId, public int $playerId,
        public string $entryGrade, public string $meetingGrade, public array $raw, public array $sources, public array $reasons) {}

    public function hashPayload(): array
    {
        $copy = get_object_vars($this);
        foreach (Stat09GradeContract::FETCH_FIELDS as $key) {
            if (array_key_exists($key, $copy['sources']) && $copy['sources'][$key] !== null && $copy['sources'][$key] !== '') {
                $copy['sources'][$key] = AcquiredHistoryTimestamp::parse($copy['sources'][$key])->format('U.u');
            }
        }

        return $copy;
    }

    public function maxFetchedAt(): ?DateTimeImmutable
    {
        $max = null;
        foreach (Stat09GradeContract::FETCH_FIELDS as $key) {
            $value = $this->sources[$key] ?? null;
            if ($value !== null && $value !== '') {
                $date = AcquiredHistoryTimestamp::parse($value);
                $max = $max === null ? $date : max($max, $date);
            }
        }

        return $max;
    }
}
