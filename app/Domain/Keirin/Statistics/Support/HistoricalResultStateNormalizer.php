<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Support;

use App\Domain\Keirin\Statistics\DTO\NormalizedHistoricalResultDto;
use App\Domain\Keirin\Statistics\Enums\HistoricalResultState;

class HistoricalResultStateNormalizer
{
    public function normalize(string $resultStatus): NormalizedHistoricalResultDto
    {
        return match ($resultStatus) {
            'FINISHED' => new NormalizedHistoricalResultDto(HistoricalResultState::NormalFinish, false),
            'TIED' => new NormalizedHistoricalResultDto(HistoricalResultState::NormalFinish, true),
            'DISQUALIFIED' => new NormalizedHistoricalResultDto(HistoricalResultState::Disqualified, false),
            'CRASHED' => new NormalizedHistoricalResultDto(HistoricalResultState::FallDnf, false),
            'DID_NOT_FINISH' => new NormalizedHistoricalResultDto(HistoricalResultState::OtherDnf, false),
            'DID_NOT_START' => new NormalizedHistoricalResultDto(HistoricalResultState::DidNotStart, false),
            'WITHDRAWN' => new NormalizedHistoricalResultDto(HistoricalResultState::Withdrawn, false),
            default => new NormalizedHistoricalResultDto(HistoricalResultState::UnknownAbnormal, false),
        };
    }
}
