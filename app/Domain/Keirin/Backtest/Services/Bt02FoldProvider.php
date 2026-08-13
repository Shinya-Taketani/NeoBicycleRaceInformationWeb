<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\DTO\Bt02FoldDefinitionDto;
use DateTimeImmutable;

class Bt02FoldProvider
{
    /** @return list<Bt02FoldDefinitionDto> */
    public function folds(): array
    {
        return [
            new Bt02FoldDefinitionDto('WF_2023', 1, new DateTimeImmutable('2022-01-01'), new DateTimeImmutable('2022-12-31'), new DateTimeImmutable('2022-01-01'), new DateTimeImmutable('2022-09-30'), new DateTimeImmutable('2022-10-01'), new DateTimeImmutable('2022-12-31'), new DateTimeImmutable('2023-01-01'), new DateTimeImmutable('2023-12-31')),
            new Bt02FoldDefinitionDto('WF_2024', 2, new DateTimeImmutable('2022-01-01'), new DateTimeImmutable('2023-12-31'), new DateTimeImmutable('2022-01-01'), new DateTimeImmutable('2022-12-31'), new DateTimeImmutable('2023-01-01'), new DateTimeImmutable('2023-12-31'), new DateTimeImmutable('2024-01-01'), new DateTimeImmutable('2024-12-31')),
            new Bt02FoldDefinitionDto('WF_2025', 3, new DateTimeImmutable('2022-01-01'), new DateTimeImmutable('2024-12-31'), new DateTimeImmutable('2022-01-01'), new DateTimeImmutable('2023-12-31'), new DateTimeImmutable('2024-01-01'), new DateTimeImmutable('2024-12-31'), new DateTimeImmutable('2025-01-01'), new DateTimeImmutable('2025-12-31')),
        ];
    }
}
