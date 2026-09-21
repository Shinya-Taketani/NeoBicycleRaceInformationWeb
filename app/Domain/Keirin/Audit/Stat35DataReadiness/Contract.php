<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Audit\Stat35DataReadiness;

use RuntimeException;

final class Contract
{
    public const VERSION = 'STAT35-DATA-READINESS-v3-PR64-REVIEW-FIX';

    public const FROM = '2022-01-01';

    public const TO = '2025-12-31';

    public const TABLES = ['races', 'race_days', 'race_meetings', 'racetracks', 'race_entries', 'race_results', 'race_result_imports', 'scraping_fetch_logs'];

    // Observed in 71 saved PJ0326 pages spanning all four development years.
    public const HEADERS = ['着', '車番', '選手名', '年齢', '府県', '期別', '級班', '着差', '上り', '決まり手', 'H/B', '個人状況'];

    public const ROW_KEYS = ['tyaku', 'syaban', 'syabanCharColor', 'syabanBgColor', 'sensyuName', 'sensyuRegistNo', 'age', 'huken', 'sotugyouki', 'kyuhan', 'tyakusa', 'agari', 'kimarite', 'BH', 'inLineJyuni', 'kojinStateItemSubData'];

    public const STATUSES = ['FINISHED', 'TIED', 'DISQUALIFIED', 'DID_NOT_START', 'DID_NOT_FINISH', 'WITHDRAWN', 'CRASHED'];

    public static function plan(): array
    {
        return ['version' => self::VERSION, 'scope' => 'DATA_READINESS_AUDIT_ONLY', 'from' => self::FROM, 'to' => self::TO,
            'allowed_tables' => self::TABLES, 'category' => 'EXISTING_STATISTICS_FULLWIDTH_A_S_PREFIX',
            'db' => 'EXECUTE_ONLY_READ_ONLY', 'raw' => 'READ_ONLY_HASH_VERIFIED', 'writes' => 0,
            'migration' => 0, 'production_parser_changes' => 0, 'model_fitting' => 0, '2026_access' => 'FORBIDDEN',
            'outcome_policy' => 'TARGET_OUTCOME_NOT_ALLOWED_FOR_PREDICTIVE_ANALYSIS',
            'result_status_usage' => 'DATA_QUALITY_ONLY', 'rank_winner_semantic_consumption' => 'FORBIDDEN',
            'historical_cutoff' => 'H != T AND H.start < T.start AND import.fetched_at < T.start',
            'history_status_policy' => 'VALID_FORMAT_COVERAGE_WITH_SEPARATE_NORMAL_STATUS_COUNT_NOT_FINAL_STAT_POLICY',
            'publication_time' => 'UNKNOWN', 'left_truncation' => 'LEFT_TRUNCATED_POSSIBLE',
            'header_policy' => 'EXACT_OBSERVED_SET_ORDER_INDEPENDENT_NO_ALIASES',
            'raw_hash_semantics' => 'SOURCE_HASH_ORIGINAL_BYTES_CONVERTED_HASH_UTF8',
            'cancelled_policy' => 'VALIDATED_BLANK_ROWS_WITHOUT_DB_RESULTS_AUDIT_ONLY_NEVER_HISTORY',
            'identity_policy' => 'EXPLICIT_MAPPING_AND_UNRESOLVED_EXTRACTION_AND_TARGET_BLOCKERS',
            'reproduce_policy' => 'UNIQUE_ATTEMPT_RETAIN_SUCCESS_AND_FAILURE_EVIDENCE',
            'numeric_policy' => 'UNROUNDED_DECIMAL_STRING_NO_EMPIRICAL_EXCLUSION_THRESHOLD'];
    }

    public static function date(string $date): void
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (! $parsed || $parsed->format('Y-m-d') !== $date || $date < self::FROM || $date > self::TO) {
            throw new RuntimeException('Forbidden or invalid race date: '.$date);
        }
    }

    public static function id(mixed $id): int
    {
        if (! is_int($id) || $id < 1) {
            throw new RuntimeException('Invalid identity.');
        }

        return $id;
    }
}
