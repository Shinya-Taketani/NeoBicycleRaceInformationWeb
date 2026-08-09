<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\DTO\FoldDefinitionDto;
use DateTimeImmutable;
use DomainException;

class FinalHoldoutGuard
{
    public const POLICY = 'BLOCK_AFTER_2025-12-31';

    private const LAST_ALLOWED_DATE = '2025-12-31';

    public function assertAllowed(FoldDefinitionDto $fold): void
    {
        if ($fold->evaluationTo > new DateTimeImmutable(self::LAST_ALLOWED_DATE)) {
            throw new DomainException('BT-01 final holdout blocks evaluation after 2025-12-31.');
        }
    }
}
