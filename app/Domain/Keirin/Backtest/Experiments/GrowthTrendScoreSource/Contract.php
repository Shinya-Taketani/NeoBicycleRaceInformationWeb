<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthTrendScoreSource;

use RuntimeException;

final class Contract
{
    public const COUNTS = [2024 => 25212, 2025 => 24866];

    public const ENTRIES = [2024 => 179089, 2025 => 177120];

    public const COLUMNS = ['e.id as entry_id', 'e.race_id', 'e.player_id', 'e.bike_number as bike', 'r.race_date',
        'r.scheduled_start_at', 'r.race_day_id', 'd.race_date as day_date', 'm.id as meeting_id',
        'm.starts_on as meeting_starts_on', 'm.ends_on as meeting_ends_on', 'm.grade as meeting_grade',
        'e.race_score', 'e.fetched_at as entry_fetched_at'];

    public static function plan(): array
    {
        return ['version' => 'GROWTH-TREND-SCORE-SOURCE-01-v2-OUTCOME-IDENTITY-ISOLATION', 'mode' => 'DEVELOPMENT_BACKFILLED_SCORE_OBSERVATION',
            'years' => [2022, 2023, 2024, 2025], 'targets' => self::COUNTS, 'entries' => self::ENTRIES,
            'tables' => ['race_entries', 'races', 'race_days', 'race_meetings'], 'columns' => self::COLUMNS,
            'capture' => 'READ_ONLY', 'verify' => 'DB_NONE', 'publication_timing' => 'UNKNOWN',
            'outcome_access' => 'FORBIDDEN', 'holdout_2026' => 'FORBIDDEN'];
    }

    public static function year(int $year, bool $target = false): void
    {
        if (! in_array($year, $target ? [2024, 2025] : [2022, 2023, 2024, 2025], true)) {
            throw new RuntimeException('Forbidden score source year.');
        }
    }
}
