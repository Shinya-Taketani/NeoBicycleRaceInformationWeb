<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt02BinaryLabelsDto
{
    public function __construct(
        public bool $isWin,
        public bool $isTop2,
        public bool $isTop3,
    ) {}
}
