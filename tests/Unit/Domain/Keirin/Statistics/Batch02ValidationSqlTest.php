<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Statistics;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class Batch02ValidationSqlTest extends TestCase
{
    public function test_automatic_selection_requires_a_complete_2024_batch(): void
    {
        $sql = file_get_contents(base_path('docs/sql/validate_batch02_2024.sql'));

        $this->assertIsString($sql);
        foreach ([
            "('STAT-10', 'STAT-10-existing-db-v1')",
            "('STAT-11', 'STAT-11-existing-db-v1')",
            "('STAT-12', 'STAT-12-existing-db-v1')",
            "('STAT-24', 'STAT-24-existing-db-v1')",
            "('STAT-26', 'STAT-26-existing-db-v1')",
            "runs.target_from = DATE '2024-01-01'",
            "runs.target_to = DATE '2024-12-31'",
            'runs.target_race_id IS NULL',
            'runs.target_race_count > 0',
            'runs.processed_race_count = runs.target_race_count',
            'runs.error_count = 0',
            'results.feature_run_id = runs.id) = runs.target_entry_count',
            'HAVING COUNT(*) = 5',
            'COUNT(DISTINCT stat_code) = 5',
            'MIN(target_race_count) = MAX(target_race_count)',
            'MIN(target_entry_count) = MAX(target_entry_count)',
            'MIN(history_from) = MAX(history_from)',
            "MIN(parameters->>'stat01_run_id') = MAX(parameters->>'stat01_run_id')",
        ] as $requiredCondition) {
            $this->assertStringContainsString($requiredCondition, $sql);
        }
        $this->assertStringContainsString("NULLIF(:'batch_execution_uuid', '')", $sql);
        $this->assertStringNotContainsString("runs.status = 'SUCCEEDED'", $sql);
    }

    public function test_batch02_statistics_code_does_not_use_php_84_array_helpers(): void
    {
        foreach (File::allFiles(app_path('Domain/Keirin/Statistics')) as $file) {
            $source = $file->getContents();
            $this->assertStringNotContainsString('array_any(', $source, $file->getPathname());
            $this->assertStringNotContainsString('array_all(', $source, $file->getPathname());
        }
    }
}
