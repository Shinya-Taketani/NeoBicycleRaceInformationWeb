<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt03SourceBinDto
{
    public function __construct(
        public int $sourceEffectBinId,
        public int $index,
        public string $kind,
        public ?float $lowerBound,
        public ?float $upperBound,
        public ?string $categoryValue,
        public int $trainingSampleCount,
        public string $boundariesHash,
    ) {}

    public function effectBin(): EffectBinDto
    {
        return new EffectBinDto(
            $this->index,
            $this->kind,
            $this->lowerBound,
            $this->upperBound,
            $this->categoryValue,
            $this->trainingSampleCount,
        );
    }
}
