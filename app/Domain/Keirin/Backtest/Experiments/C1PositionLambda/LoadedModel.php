<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1PositionLambda;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Layout;

final readonly class LoadedModel
{
    public function __construct(public Layout $layout, public Fit $fit, public array $artifact) {}
}
