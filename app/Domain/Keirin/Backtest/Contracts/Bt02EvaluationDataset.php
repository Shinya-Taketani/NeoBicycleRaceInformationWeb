<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Contracts;

use App\Domain\Keirin\Backtest\DTO\Bt02EvaluationRowDto;
use App\Domain\Keirin\Backtest\Enums\Bt02SignalCohort;
use DateTimeImmutable;

interface Bt02EvaluationDataset
{
    /** @return iterable<Bt02EvaluationRowDto> */
    public function rows(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        string $statCode,
        Bt02SignalCohort $cohort,
    ): iterable;
}
