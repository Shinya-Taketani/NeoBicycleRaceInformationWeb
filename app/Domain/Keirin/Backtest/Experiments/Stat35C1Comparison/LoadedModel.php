<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison;

use App\Domain\Keirin\Backtest\DTO\Bt03e03FitResultDto;

final readonly class LoadedModel
{
    public function __construct(public Layout $layout, public Bt03e03FitResultDto $fit, public array $artifact) {}
}
