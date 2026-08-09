<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

use DateTimeImmutable;

readonly class FoldDefinitionDto
{
    public function __construct(
        public string $code,
        public int $sequence,
        public ?DateTimeImmutable $trainFrom,
        public ?DateTimeImmutable $trainTo,
        public DateTimeImmutable $evaluationFrom,
        public DateTimeImmutable $evaluationTo,
    ) {}
}
