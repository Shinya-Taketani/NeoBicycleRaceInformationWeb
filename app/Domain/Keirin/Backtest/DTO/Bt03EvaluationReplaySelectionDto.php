<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

use InvalidArgumentException;

readonly class Bt03EvaluationReplaySelectionDto
{
    public function __construct(
        public ?int $trainingBinIndex = null,
        public ?string $labelCode = null,
    ) {
        if (($this->trainingBinIndex !== null && $this->trainingBinIndex < 1)
            || ($this->labelCode !== null && ! in_array($this->labelCode, ['IS_WIN', 'IS_TOP2', 'IS_TOP3'], true))) {
            throw new InvalidArgumentException('BT-03 evaluation replay selection was invalid.');
        }
    }
}
