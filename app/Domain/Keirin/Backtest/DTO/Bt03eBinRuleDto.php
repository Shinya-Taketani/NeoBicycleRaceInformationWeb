<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt03eBinRuleDto
{
    public function __construct(
        public string $statCode,
        public int $binIndex,
        public string $binOrigin,
        public string $binKind,
        public ?float $lowerBound,
        public ?float $upperBound,
        public ?string $categoryValue,
        public int $sourceEffectBinId,
        public string $sourceBoundariesHash,
        public int $trainingSampleCount,
        public int $directionStrength,
    ) {}

    public function sourceBin(): Bt03SourceBinDto
    {
        return new Bt03SourceBinDto(
            $this->sourceEffectBinId,
            $this->binIndex,
            $this->binKind,
            $this->lowerBound,
            $this->upperBound,
            $this->categoryValue,
            $this->trainingSampleCount,
            $this->sourceBoundariesHash,
        );
    }

    /** @return array<string, int|float|string|null> */
    public function canonical(): array
    {
        return [
            'stat_code' => $this->statCode,
            'bin_index' => $this->binIndex,
            'bin_origin' => $this->binOrigin,
            'bin_kind' => $this->binKind,
            'lower_bound' => $this->lowerBound,
            'upper_bound' => $this->upperBound,
            'category_value' => $this->categoryValue,
            'source_effect_bin_id' => $this->sourceEffectBinId,
            'source_boundaries_hash' => $this->sourceBoundariesHash,
            'direction_strength' => $this->directionStrength,
        ];
    }
}
