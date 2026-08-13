<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt02SpoolMetadataDto
{
    public function __construct(
        public int $rowCount,
        public int $byteCount,
        public string $sha256,
        public string $formatVersion,
    ) {}
}
