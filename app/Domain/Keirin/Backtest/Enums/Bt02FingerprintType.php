<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Enums;

enum Bt02FingerprintType: string
{
    case Source = 'source';
    case Content = 'content';
}
