<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt03ComputedBinEffectDto
{
    public function __construct(
        public Bt03BinAssignmentDto $bin,
        public string $labelCode,
        public Bt03ModelPairDto $models,
        public Bt03BinEffectResultDto $result,
        public string $effectHash,
    ) {}
}
