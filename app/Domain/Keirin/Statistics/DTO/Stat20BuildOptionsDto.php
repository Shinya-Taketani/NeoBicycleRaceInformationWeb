<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

readonly class Stat20BuildOptionsDto
{
    /** @param list<int> $sourceRunIds */
    public function __construct(
        public int $stat01RunId,
        public array $sourceRunIds,
        public ?DateTimeImmutable $from,
        public ?DateTimeImmutable $to,
        public ?int $raceId,
        public int $chunkSize = 100,
        public bool $dryRun = false,
    ) {
        if ($stat01RunId < 1 || $chunkSize < 1 || $chunkSize > 1000
            || ($raceId !== null && ($raceId < 1 || $from !== null || $to !== null))
            || ($raceId === null && ($from === null || $to === null || $from > $to))
            || ($from !== null && $from->format('Y') >= '2026')
            || ($to !== null && $to->format('Y') >= '2026')
            || count($sourceRunIds) > 14 || count($sourceRunIds) !== count(array_unique($sourceRunIds))) {
            throw new InvalidArgumentException('STAT-20 requires bounded pre-2026 targets and unique source runs.');
        }
        foreach ($sourceRunIds as $id) {
            if (! is_int($id) || $id < 1 || $id === $stat01RunId) {
                throw new InvalidArgumentException('Invalid STAT-20 source run ID.');
            }
        }
    }
}
