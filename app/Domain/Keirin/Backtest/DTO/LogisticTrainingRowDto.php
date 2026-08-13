<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class LogisticTrainingRowDto
{
    /** @param list<float> $features */
    public function __construct(
        public array $features,
        public int $label,
    ) {}
}
