<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Support;

final class Stat09GradeContract
{
    public const LEGACY_PROFILE = 'legacy-v2';

    public const PROFILE = 'grade-breakdown-v3';

    public const PROJECTION = 'STAT09-SAVED-HISTORY-GRADES-v1';

    public const NORMALIZATION = 'STAT09-HISTORY-GRADE-NORMALIZATION-v1';

    public const ENTRY_GRADES = ['SS', 'S1', 'S2', 'S3', 'A1', 'A2', 'A3', 'A4', 'B1', 'B2'];

    public const FETCH_FIELDS = ['entry_fetched_at', 'race_last_fetched_at', 'day_last_fetched_at', 'meeting_last_fetched_at'];
}
