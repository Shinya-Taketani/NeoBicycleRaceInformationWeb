<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Enums;

enum Bt02AnalysisRole: string
{
    case EntryIncremental = 'ENTRY_INCREMENTAL';
    case RaceStratifier = 'RACE_STRATIFIER';
    case DiagnosticOnly = 'DIAGNOSTIC_ONLY';
}
