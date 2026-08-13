<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt02SignalFeatureDto
{
    /** @param list<string> $qualityReasons */
    public function __construct(
        public int $raceId,
        public int $raceEntryId,
        public string $status,
        public string $qualityStatus,
        public array $qualityReasons,
        public ?float $primaryValue,
    ) {}
}
