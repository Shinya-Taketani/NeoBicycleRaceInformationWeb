<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

use InvalidArgumentException;

readonly class Bt02EvaluationRowDto
{
    public function __construct(
        public int $raceId,
        public int $raceEntryId,
        public float $baselineValue,
        public float $signalValue,
        public Bt02BinaryLabelsDto $labels,
    ) {}

    public function label(string $labelCode): int
    {
        return match ($labelCode) {
            'IS_WIN' => (int) $this->labels->isWin,
            'IS_TOP2' => (int) $this->labels->isTop2,
            'IS_TOP3' => (int) $this->labels->isTop3,
            default => throw new InvalidArgumentException("Unknown BT-02 label {$labelCode}."),
        };
    }
}
