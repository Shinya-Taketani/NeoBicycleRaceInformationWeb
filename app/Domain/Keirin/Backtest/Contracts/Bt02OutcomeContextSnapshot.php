<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Contracts;

use App\Domain\Keirin\Backtest\DTO\Bt02OutcomeContextRaceDto;
use App\Domain\Keirin\Backtest\DTO\FoldDefinitionDto;

interface Bt02OutcomeContextSnapshot
{
    /** @return \Generator<int, list<Bt02OutcomeContextRaceDto>> */
    public function chunks(FoldDefinitionDto $fold, int $chunkSize): \Generator;

    /** @return array<string, mixed> */
    public function auditParameters(): array;

    public function manifestHash(): string;
}
