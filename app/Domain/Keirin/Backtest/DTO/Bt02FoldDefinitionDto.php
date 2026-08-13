<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

use DateTimeImmutable;

readonly class Bt02FoldDefinitionDto
{
    public function __construct(
        public string $code,
        public int $sequence,
        public DateTimeImmutable $trainingFrom,
        public DateTimeImmutable $trainingTo,
        public DateTimeImmutable $innerFitFrom,
        public DateTimeImmutable $innerFitTo,
        public DateTimeImmutable $innerValidationFrom,
        public DateTimeImmutable $innerValidationTo,
        public DateTimeImmutable $evaluationFrom,
        public DateTimeImmutable $evaluationTo,
    ) {}

    public function holdoutDefinition(): FoldDefinitionDto
    {
        return new FoldDefinitionDto(
            $this->code,
            $this->sequence,
            $this->trainingFrom,
            $this->trainingTo,
            $this->evaluationFrom,
            $this->evaluationTo,
        );
    }
}
