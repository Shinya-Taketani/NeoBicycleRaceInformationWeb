<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\DTO\Bt02BinaryLabelsDto;
use InvalidArgumentException;

class Bt02LabelDefinition
{
    public function labels(string $resultStatus, ?int $rank): Bt02BinaryLabelsDto
    {
        $normal = in_array($resultStatus, ['FINISHED', 'TIED'], true);
        if ($normal && $rank === null) {
            throw new InvalidArgumentException('A finished/tied BT-02 label requires a rank.');
        }
        $rank = $normal ? $rank : null;

        return new Bt02BinaryLabelsDto(
            isWin: $rank === 1,
            isTop2: $rank !== null && $rank <= 2,
            isTop3: $rank !== null && $rank <= 3,
        );
    }
}
