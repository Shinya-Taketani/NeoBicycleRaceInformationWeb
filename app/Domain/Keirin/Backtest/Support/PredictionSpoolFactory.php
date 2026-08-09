<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Support;

class PredictionSpoolFactory
{
    public function create(): PredictionSpool
    {
        return new PredictionSpool;
    }
}
