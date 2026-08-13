<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Support;

use App\Domain\Keirin\Backtest\Contracts\LogisticTrainingRowSource;
use App\Domain\Keirin\Backtest\DTO\LogisticTrainingRowDto;
use Closure;
use RuntimeException;

class CallbackLogisticTrainingRowSource implements LogisticTrainingRowSource
{
    /** @param Closure(): iterable<LogisticTrainingRowDto> $rowFactory */
    public function __construct(private readonly Closure $rowFactory) {}

    public function rows(): iterable
    {
        $rows = ($this->rowFactory)();
        if (! is_iterable($rows)) {
            throw new RuntimeException('Logistic training row factory did not return an iterable.');
        }

        yield from $rows;
    }
}
