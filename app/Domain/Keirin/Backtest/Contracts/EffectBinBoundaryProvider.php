<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Contracts;

use App\Domain\Keirin\Backtest\DTO\EffectBinDto;

interface EffectBinBoundaryProvider
{
    /**
     * Implementations own materialization. Production execution can replace the
     * in-memory provider with an externally sorted spool without changing callers.
     *
     * @param  iterable<int|float|string>  $trainingValues
     * @return list<EffectBinDto>
     */
    public function build(iterable $trainingValues): array;
}
