<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Enums;

enum HistoricalResultState: string
{
    case NormalFinish = 'NORMAL_FINISH';
    case Disqualified = 'DISQUALIFIED';
    case FallDnf = 'FALL_DNF';
    case OtherDnf = 'OTHER_DNF';
    case DidNotStart = 'DID_NOT_START';
    case Withdrawn = 'WITHDRAWN';
    case UnknownAbnormal = 'UNKNOWN_ABNORMAL';

    public function started(): bool
    {
        return match ($this) {
            self::NormalFinish,
            self::Disqualified,
            self::FallDnf,
            self::OtherDnf,
            self::UnknownAbnormal => true,
            self::DidNotStart,
            self::Withdrawn => false,
        };
    }

    public function abnormal(): bool
    {
        return match ($this) {
            self::Disqualified,
            self::FallDnf,
            self::OtherDnf,
            self::UnknownAbnormal => true,
            self::NormalFinish,
            self::DidNotStart,
            self::Withdrawn => false,
        };
    }
}
