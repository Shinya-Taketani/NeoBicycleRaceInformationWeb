<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Statistics;

use Tests\TestCase;

class Batch03ValidationSqlTest extends TestCase
{
    public function test_automatic_selection_requires_a_complete_six_run_2024_batch(): void
    {
        $sql = file_get_contents(base_path('docs/sql/validate_batch03_2024.sql'));

        $this->assertIsString($sql);
        foreach (['STAT-07', 'STAT-08', 'STAT-23', 'STAT-31', 'STAT-32', 'STAT-33'] as $stat) {
            $this->assertStringContainsString("('{$stat}', '{$stat}-existing-db-v1')", $sql);
        }
        foreach ([
            "runs.target_from = DATE '2024-01-01'",
            "runs.target_to = DATE '2024-12-31'",
            'runs.target_race_id IS NULL',
            'runs.target_race_count > 0',
            'runs.processed_race_count = runs.target_race_count',
            'runs.error_count = 0',
            'results.feature_run_id = runs.id) = runs.target_entry_count',
            'HAVING COUNT(*) = 6',
            'COUNT(DISTINCT stat_code) = 6',
            'MIN(target_race_count) = MAX(target_race_count)',
            'MIN(target_entry_count) = MAX(target_entry_count)',
            'MIN(history_from) = MAX(history_from)',
            "MIN(parameters->>'stat01_run_id') = MAX(parameters->>'stat01_run_id')",
            "NULLIF(:'batch_execution_uuid', '')",
            "results.status = 'NOT_APPLICABLE'",
            'raw_points_not_null',
            'confidence_not_null',
            'effective_points_not_null',
            'STAT-07 same-track sample',
            'STAT-08 target hour',
            'STAT-23 target day number',
            'STAT-31 semifinal/final observed count',
            'STAT-32 normalized stage',
            'STAT-33 transition sample',
        ] as $required) {
            $this->assertStringContainsString($required, $sql);
        }
        $this->assertStringNotContainsString("runs.status = 'SUCCEEDED'", $sql);
    }
}
