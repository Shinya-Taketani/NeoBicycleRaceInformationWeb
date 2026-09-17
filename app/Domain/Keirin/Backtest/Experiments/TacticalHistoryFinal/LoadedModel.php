<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal;

use App\Domain\Keirin\Backtest\DTO\Bt03e03FitResultDto;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Layout;

final readonly class LoadedModel
{
    public function __construct(public Layout $layout, public Bt03e03FitResultDto $fit, public array $artifact) {}
}
