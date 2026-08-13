<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class EffectBinDto
{
    public function __construct(
        public int $index,
        public string $kind,
        public ?float $lowerBound,
        public ?float $upperBound,
        public ?string $categoryValue,
        public int $trainingSampleCount,
    ) {}
}
