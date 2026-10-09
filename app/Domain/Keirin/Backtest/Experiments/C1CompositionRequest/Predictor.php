<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest;

use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Forward;

class Predictor
{
    public function __construct(private readonly Forward $forward) {}

    public function predict(array $race, array $model): array
    {
        return $this->forward->predict($race, $model['c1'], $model['c2']);
    }
}
