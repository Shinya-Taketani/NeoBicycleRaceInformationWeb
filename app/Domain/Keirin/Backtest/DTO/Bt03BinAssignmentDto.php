<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt03BinAssignmentDto
{
    public function __construct(
        public int $binIndex,
        public string $binOrigin,
        public string $binKind,
        public ?float $lowerBound,
        public ?float $upperBound,
        public ?string $categoryValue,
        public int $trainingSampleCount,
        public ?int $sourceEffectBinId,
        public string $boundariesHash,
    ) {}
}
