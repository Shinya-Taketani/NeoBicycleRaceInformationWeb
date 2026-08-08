<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Statistics;

use Tests\TestCase;

class Batch04ValidationSqlTest extends TestCase
{
    public function test_validation_selects_only_a_complete_two_run_2024_batch_and_preserves_an_explicit_uuid(): void
    {
        $sql = file_get_contents(base_path('docs/sql/validate_batch04_2024.sql'));

        $this->assertIsString($sql);
        foreach ([
            "('STAT-39', 'STAT-39-existing-db-v1')",
            "('STAT-42', 'STAT-42-existing-db-v1')",
            "runs.target_from = DATE '2024-01-01'",
            "runs.target_to = DATE '2024-12-31'",
            'runs.target_race_id IS NULL',
            'runs.target_race_count > 0',
            'runs.processed_race_count = runs.target_race_count',
            'runs.error_count = 0',
            'results.feature_run_id = runs.id) = runs.target_entry_count',
            'HAVING COUNT(*) = 2',
            'COUNT(DISTINCT stat_code) = 2',
            'MIN(target_race_count) = MAX(target_race_count)',
            'MIN(target_entry_count) = MAX(target_entry_count)',
            'MIN(history_from) = MAX(history_from)',
            "MIN(parameters->>'stat01_run_id') = MAX(parameters->>'stat01_run_id')",
            '\\if :{?batch_execution_uuid}',
            "NULLIF(:'batch_execution_uuid', '')",
            "results.status = 'NOT_APPLICABLE'",
            'raw_points_not_null',
            'confidence_not_null',
            'effective_points_not_null',
            'target entrant count',
            'entrant count x bike number',
            'FIELD_BIKE sample count',
            'TRACK_FIELD_BIKE sample count',
            'FIELD_FRAME sample count',
            'FIELD_BIKE residual sample count',
            'current coentrant count',
            'resolved coentrant count',
            'unresolved coentrant count',
            'opponents with direct history count',
            'opponents without direct history count',
            'opponents with normal history count',
            'sum pair direct meeting count',
            'unique direct source race count',
            'pair normal direct meeting count',
            'pair relative residual sample count',
        ] as $required) {
            $this->assertStringContainsString($required, $sql);
        }
        $this->assertStringNotContainsString("runs.status = 'SUCCEEDED'", $sql);
        $this->assertFalse(str_starts_with(ltrim($sql), "\\set batch_execution_uuid ''"));
    }
}
