<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt03ModelPairDto
{
    public function __construct(
        public Bt03StoredModelDto $baseline,
        public Bt03StoredModelDto $incremental,
    ) {}
}
