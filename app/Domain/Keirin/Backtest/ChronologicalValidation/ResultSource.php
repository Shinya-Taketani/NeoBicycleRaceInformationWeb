<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\ChronologicalValidation;

interface ResultSource
{
    /** Called only after the immutable opening receipt has been verified. */
    public function read(string $day, array $receipt): iterable;
}
