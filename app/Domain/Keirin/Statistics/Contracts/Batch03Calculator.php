<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Contracts;

use App\Domain\Keirin\Statistics\DTO\Batch03BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch03FeatureResultDto;
use App\Domain\Keirin\Statistics\DTO\Batch03HistoricalRaceDto;
use App\Domain\Keirin\Statistics\DTO\Batch03TargetEntryDto;
use App\Domain\Keirin\Statistics\Enums\Batch03Stat;

interface Batch03Calculator
{
    public function stat(): Batch03Stat;

    /** @param list<Batch03HistoricalRaceDto> $histories */
    public function calculate(
        Batch03TargetEntryDto $target,
        array $histories,
        Batch03BuildOptionsDto $options,
        string $batchExecutionUuid,
    ): Batch03FeatureResultDto;
}
