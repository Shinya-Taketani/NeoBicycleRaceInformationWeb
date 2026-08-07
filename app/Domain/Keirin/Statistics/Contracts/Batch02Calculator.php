<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Contracts;

use App\Domain\Keirin\Statistics\DTO\Batch02BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch02FeatureResultDto;
use App\Domain\Keirin\Statistics\DTO\Batch02TargetEntryDto;
use App\Domain\Keirin\Statistics\DTO\HistoricalRaceDto;
use App\Domain\Keirin\Statistics\Enums\Batch02Stat;

interface Batch02Calculator
{
    public function stat(): Batch02Stat;

    /**
     * @param  list<HistoricalRaceDto>  $histories
     */
    public function calculate(
        Batch02TargetEntryDto $target,
        array $histories,
        Batch02BuildOptionsDto $options,
        string $batchExecutionUuid,
    ): Batch02FeatureResultDto;
}
