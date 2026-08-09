<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Statistics;

use Tests\TestCase;

class Batch05ValidationSqlTest extends TestCase
{
    public function test_validation_selects_a_complete_race_grain_run_and_keeps_continuous_output_compact(): void
    {
        $sql = file_get_contents(base_path('docs/sql/validate_batch05_2024.sql'));

        $this->assertIsString($sql);
        foreach ([
            "runs.stat_code = 'STAT-41'",
            "runs.calculation_version = 'STAT-41-existing-db-v1'",
            "runs.target_from = DATE '2024-01-01'",
            "runs.target_to = DATE '2024-12-31'",
            'runs.target_race_id IS NULL',
            'runs.target_race_count > 0',
            'runs.processed_race_count = runs.target_race_count',
            'runs.error_count = 0',
            'results.feature_run_id = runs.id) = runs.target_race_count',
            "NULLIF(:'batch_execution_uuid', '')",
            'subject_type_not_race',
            'race_entry_id_not_null',
            'duplicate_race_results',
            'missing_race_results',
            'percentile_cont(0.95)',
            'score_stddev_pop',
            'gap_rank1_rank3',
            'pairwise_mean_absolute_gap',
            'expected entrant count',
            'full_coverage_races',
            'race_competitiveness_score_non_null',
            'race_prediction_uncertainty_score_non_null',
            'race_upset_structure_score_non_null',
            'prediction_probability_entropy_non_null',
            'raw_points_not_null',
            'confidence_not_null',
            'effective_points_not_null',
        ] as $required) {
            $this->assertStringContainsString($required, $sql);
        }
        $this->assertStringNotContainsString('= runs.target_entry_count', $sql);
        $this->assertStringNotContainsString('GROUP BY metric, value', $this->continuousSection($sql));
        $this->assertFalse(str_starts_with(ltrim($sql), "\\set batch_execution_uuid ''"));
    }

    private function continuousSection(string $sql): string
    {
        $start = strpos($sql, "('score_stddev_pop'");
        $end = strpos($sql, 'WITH entrant_counts AS');

        return substr($sql, $start === false ? 0 : $start, $end === false ? null : $end - (int) $start);
    }
}
