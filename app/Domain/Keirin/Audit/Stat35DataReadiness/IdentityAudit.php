<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Audit\Stat35DataReadiness;

final class IdentityAudit
{
    private const ERROR_COUNTERS = [
        'ENTRY_RESULT_BIKE_MISMATCH' => 'normal_entry_result_mismatch',
        'ROW_COUNT_OR_BIKE_SET_MISMATCH' => 'normal_entry_result_mismatch',
        'PLAYER_IDENTITY_MISMATCH' => 'normal_player_identity_mismatch',
        'RAW_RACE_IDENTITY_MISMATCH' => 'raw_race_identity_mismatch',
        'INVALID_OR_DUPLICATE_BIKE' => 'duplicate_bike',
        'INVALID_OR_DUPLICATE_DB_BIKE' => 'duplicate_bike',
    ];

    private array $counts = [
        'normal_entry_result_mismatch' => 0, 'normal_player_identity_mismatch' => 0,
        'raw_race_identity_mismatch' => 0, 'duplicate_bike' => 0,
        'unresolved_extracted_player_rows' => 0, 'unresolved_target_entries' => 0,
        'cancelled_empty_imports' => 0, 'cancelled_partial_blank_imports' => 0, 'cancelled_partial_blank_rows' => 0,
        'cancelled_nonempty_agari_conflicts' => 0, 'cancelled_db_result_conflicts' => 0,
    ];

    public function error(string $reason): void
    {
        if (isset(self::ERROR_COUNTERS[$reason])) {
            $this->counts[self::ERROR_COUNTERS[$reason]]++;
        }
    }

    public function unresolvedExtracted(): void
    {
        $this->counts['unresolved_extracted_player_rows']++;
    }

    public function unresolvedTarget(): void
    {
        $this->counts['unresolved_target_entries']++;
    }

    public function cancelled(array $audit): void
    {
        if ($audit['nonempty_agari_rows'] > 0) {
            $this->counts['cancelled_nonempty_agari_conflicts']++;
        }
        if ($audit['db_result_rows'] > 0) {
            $this->counts['cancelled_db_result_conflicts']++;
        }
        if ($audit['status'] === 'EXPLICIT_CANCELLED_NO_RESULT_ROWS') {
            $this->counts['cancelled_empty_imports']++;
        } elseif ($audit['status'] === 'EXPLICIT_CANCELLED_PARTIAL_ROWS_NO_AGARI') {
            $this->counts['cancelled_partial_blank_imports']++;
            $this->counts['cancelled_partial_blank_rows'] += $audit['partial_rows'];
        }
    }

    public function artifact(): array
    {
        $total = 0;
        foreach (array_unique([...array_values(self::ERROR_COUNTERS), 'unresolved_extracted_player_rows',
            'unresolved_target_entries', 'cancelled_nonempty_agari_conflicts', 'cancelled_db_result_conflicts']) as $key) {
            $total += $this->counts[$key];
        }

        return $this->counts + ['identity_blocker_total' => $total, 'identity_safe' => $total === 0,
            'counting_scope' => 'IMPORT_ERRORS_AND_EXTRACTED_VERSION_ROWS_AND_UNIQUE_TARGET_ENTRIES; NOT_DISTINCT_PLAYERS',
            'duplicate_bike_scope' => 'INVALID_FORMAT_OR_RANGE_OR_DUPLICATE_RAW_OR_DB_BIKE'];
    }

    public static function semanticErrors(array $errors): int
    {
        $total = 0;
        foreach ($errors as $key => $count) {
            $reason = str_starts_with($key, 'error:') ? substr($key, 6) : $key;
            if (! isset(self::ERROR_COUNTERS[$reason]) && ! in_array($reason, ['RAW_MISSING',
                'CANCELLED_WITH_AGARI_DATA_REQUIRES_REVIEW', 'CANCELLED_WITH_DB_RESULTS_CONFLICT'], true)) {
                $total += $count;
            }
        }

        return $total;
    }
}
