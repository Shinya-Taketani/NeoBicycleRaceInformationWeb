<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\ChronologicalValidation;

final readonly class HistoryMemoryBudget
{
    public int $baselineBytes;

    public ?int $phpLimitBytes;

    public function __construct(public int $workingLimitBytes = 96 * 1024 * 1024)
    {
        BundleRules::require($workingLimitBytes > 0, 'Finite positive history working budget required.');
        $this->baselineBytes = memory_get_usage(false);
        $limit = ini_get('memory_limit');
        $this->phpLimitBytes = $limit === '-1' ? null : (int) ini_parse_quantity($limit);
    }

    public function check(): void
    {
        $usage = memory_get_usage(false);
        BundleRules::require(max(0, $usage - $this->baselineBytes) <= $this->workingLimitBytes
            && ($this->phpLimitBytes === null || $usage + 8 * 1024 * 1024 < $this->phpLimitBytes),
            'Offline history memory budget exhausted; no history truncation or fallback.');
    }
}
