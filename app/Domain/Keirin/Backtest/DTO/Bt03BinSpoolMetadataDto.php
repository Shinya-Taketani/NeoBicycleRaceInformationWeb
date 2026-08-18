<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt03BinSpoolMetadataDto
{
    public function __construct(
        public Bt03BinSpoolIdentityDto $identity,
        public int $rowCount,
        public int $raceCount,
        public int $byteCount,
        public string $sha256,
        public string $formatVersion,
    ) {}
}
