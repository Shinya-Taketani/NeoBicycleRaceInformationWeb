<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class LabelResultDto
{
    public function __construct(
        public int $raceId,
        public int $bikeNumber,
        public ?int $rank,
        public string $resultStatus,
    ) {}

    public function isWinner(): bool
    {
        return in_array($this->resultStatus, ['FINISHED', 'TIED'], true) && $this->rank === 1;
    }
}
