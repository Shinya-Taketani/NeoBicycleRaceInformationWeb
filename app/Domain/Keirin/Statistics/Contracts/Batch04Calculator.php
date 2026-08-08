<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Contracts;

use App\Domain\Keirin\Statistics\DTO\Batch04BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch04FeatureResultDto;
use App\Domain\Keirin\Statistics\DTO\Batch04HeadToHeadEventDto;
use App\Domain\Keirin\Statistics\DTO\Batch04PositionHistoryContextDto;
use App\Domain\Keirin\Statistics\DTO\Batch04RaceInputDto;
use App\Domain\Keirin\Statistics\DTO\Batch04TargetEntryDto;
use App\Domain\Keirin\Statistics\Enums\Batch04Stat;

interface Batch04Calculator
{
    public function stat(): Batch04Stat;

    /** @param array<string, list<Batch04HeadToHeadEventDto>> $pairHistories */
    public function calculate(
        Batch04TargetEntryDto $target,
        Batch04RaceInputDto $race,
        Batch04PositionHistoryContextDto $positionHistory,
        array $pairHistories,
        Batch04BuildOptionsDto $options,
        string $batchExecutionUuid,
    ): Batch04FeatureResultDto;
}
