<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Contracts;

use App\Domain\Keirin\Backtest\DTO\LogisticTrainingRowDto;

interface LogisticTrainingRowSource
{
    /** @return iterable<LogisticTrainingRowDto> */
    public function rows(): iterable;
}
