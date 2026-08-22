<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt03eSpoolMetadataDto
{
    public function __construct(
        public int $year,
        public int $raceCount,
        public int $entryCount,
        public int $byteCount,
        public string $sha256,
    ) {}
}
