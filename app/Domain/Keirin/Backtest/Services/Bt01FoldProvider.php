<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\DTO\FoldDefinitionDto;
use DateTimeImmutable;

class Bt01FoldProvider
{
    /** @return list<FoldDefinitionDto> */
    public function folds(): array
    {
        return [
            new FoldDefinitionDto('DEV_2022', 1, null, null, new DateTimeImmutable('2022-01-01'), new DateTimeImmutable('2022-12-31')),
            new FoldDefinitionDto('WF_2023', 2, new DateTimeImmutable('2022-01-01'), new DateTimeImmutable('2022-12-31'), new DateTimeImmutable('2023-01-01'), new DateTimeImmutable('2023-12-31')),
            new FoldDefinitionDto('WF_2024', 3, new DateTimeImmutable('2022-01-01'), new DateTimeImmutable('2023-12-31'), new DateTimeImmutable('2024-01-01'), new DateTimeImmutable('2024-12-31')),
            new FoldDefinitionDto('WF_2025', 4, new DateTimeImmutable('2022-01-01'), new DateTimeImmutable('2024-12-31'), new DateTimeImmutable('2025-01-01'), new DateTimeImmutable('2025-12-31')),
        ];
    }
}
