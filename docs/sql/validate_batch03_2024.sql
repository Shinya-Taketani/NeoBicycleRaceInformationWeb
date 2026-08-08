-- Read-only Batch03 validation for PostgreSQL/psql.
-- Optionally replace the empty value with a batch_execution_uuid.
\set batch_execution_uuid ''

WITH expected_stats(stat_code, calculation_version) AS (
    VALUES
        ('STAT-07', 'STAT-07-existing-db-v1'),
        ('STAT-08', 'STAT-08-existing-db-v1'),
        ('STAT-23', 'STAT-23-existing-db-v1'),
        ('STAT-31', 'STAT-31-existing-db-v1'),
        ('STAT-32', 'STAT-32-existing-db-v1'),
        ('STAT-33', 'STAT-33-existing-db-v1')
), complete_2024_runs AS (
    SELECT
        runs.*,
        (SELECT COUNT(*) FROM statistic_feature_results AS results WHERE results.feature_run_id = runs.id) AS result_count
    FROM statistic_feature_runs AS runs
    JOIN expected_stats
      ON expected_stats.stat_code = runs.stat_code
     AND expected_stats.calculation_version = runs.calculation_version
    WHERE runs.target_from = DATE '2024-01-01'
      AND runs.target_to = DATE '2024-12-31'
      AND runs.target_race_id IS NULL
      AND runs.target_race_count > 0
      AND runs.processed_race_count = runs.target_race_count
      AND runs.error_count = 0
      AND runs.finished_at IS NOT NULL
      AND (SELECT COUNT(*) FROM statistic_feature_results AS results WHERE results.feature_run_id = runs.id) = runs.target_entry_count
), candidate_batches AS (
    SELECT
        parameters->>'batch_execution_uuid' AS batch_execution_uuid,
        MAX(started_at) AS started_at
    FROM complete_2024_runs
    WHERE parameters->>'batch_execution_uuid' IS NOT NULL
      AND parameters->>'stat01_run_id' IS NOT NULL
      AND history_from IS NOT NULL
    GROUP BY parameters->>'batch_execution_uuid'
    HAVING COUNT(*) = 6
       AND COUNT(DISTINCT stat_code) = 6
       AND MIN(target_race_count) = MAX(target_race_count)
       AND MIN(target_entry_count) = MAX(target_entry_count)
       AND MIN(history_from) = MAX(history_from)
       AND MIN(parameters->>'stat01_run_id') = MAX(parameters->>'stat01_run_id')
), selected_batch AS (
    SELECT COALESCE(
        NULLIF(:'batch_execution_uuid', ''),
        (SELECT batch_execution_uuid FROM candidate_batches ORDER BY started_at DESC LIMIT 1)
    ) AS batch_execution_uuid
), selected_runs AS (
    SELECT runs.*
    FROM complete_2024_runs AS runs
    CROSS JOIN selected_batch
    WHERE runs.parameters->>'batch_execution_uuid' = selected_batch.batch_execution_uuid
), result_totals AS (
    SELECT
        results.feature_run_id,
        COUNT(*) AS result_count,
        COUNT(*) FILTER (WHERE results.status = 'VALID') AS valid_count,
        COUNT(*) FILTER (WHERE results.status = 'NO_HISTORY') AS no_history_count,
        COUNT(*) FILTER (WHERE results.status = 'NOT_APPLICABLE') AS not_applicable_count,
        COUNT(*) FILTER (WHERE results.status = 'PARTIAL') AS partial_count,
        COUNT(*) FILTER (WHERE results.status = 'PARTIAL_HISTORY') AS partial_history_count,
        COUNT(*) FILTER (WHERE results.status = 'MISSING_INPUT') AS missing_count,
        COUNT(*) FILTER (WHERE results.status = 'INVALID_INPUT') AS invalid_count,
        COUNT(*) FILTER (WHERE results.quality_status = 'FULL') AS quality_full_count,
        COUNT(*) FILTER (WHERE results.quality_status = 'PARTIAL') AS quality_partial_count,
        COUNT(*) FILTER (WHERE results.quality_status = 'DEGRADED') AS quality_degraded_count,
        COUNT(*) FILTER (WHERE results.raw_points IS NOT NULL) AS raw_points_not_null,
        COUNT(*) FILTER (WHERE results.confidence IS NOT NULL) AS confidence_not_null,
        COUNT(*) FILTER (WHERE results.effective_points IS NOT NULL) AS effective_points_not_null
    FROM statistic_feature_results AS results
    JOIN selected_runs AS runs ON runs.id = results.feature_run_id
    GROUP BY results.feature_run_id
)
SELECT
    runs.id AS run_id,
    runs.parameters->>'batch_execution_uuid' AS batch_execution_uuid,
    runs.stat_code,
    runs.calculation_version,
    runs.status AS run_status,
    runs.target_race_count,
    runs.processed_race_count,
    runs.target_entry_count,
    totals.result_count,
    totals.valid_count,
    totals.no_history_count,
    totals.not_applicable_count,
    totals.partial_count,
    totals.partial_history_count,
    totals.missing_count,
    totals.invalid_count,
    totals.quality_full_count,
    totals.quality_partial_count,
    totals.quality_degraded_count,
    totals.raw_points_not_null,
    totals.confidence_not_null,
    totals.effective_points_not_null,
    runs.error_count
FROM selected_runs AS runs
JOIN result_totals AS totals ON totals.feature_run_id = runs.id
ORDER BY runs.stat_code;

WITH expected_stats(stat_code, calculation_version) AS (
    VALUES
        ('STAT-07', 'STAT-07-existing-db-v1'),
        ('STAT-08', 'STAT-08-existing-db-v1'),
        ('STAT-23', 'STAT-23-existing-db-v1'),
        ('STAT-31', 'STAT-31-existing-db-v1'),
        ('STAT-32', 'STAT-32-existing-db-v1'),
        ('STAT-33', 'STAT-33-existing-db-v1')
), complete_2024_runs AS (
    SELECT runs.*
    FROM statistic_feature_runs AS runs
    JOIN expected_stats
      ON expected_stats.stat_code = runs.stat_code
     AND expected_stats.calculation_version = runs.calculation_version
    WHERE runs.target_from = DATE '2024-01-01'
      AND runs.target_to = DATE '2024-12-31'
      AND runs.target_race_id IS NULL
      AND runs.target_race_count > 0
      AND runs.processed_race_count = runs.target_race_count
      AND runs.error_count = 0
      AND runs.finished_at IS NOT NULL
      AND (SELECT COUNT(*) FROM statistic_feature_results AS results WHERE results.feature_run_id = runs.id) = runs.target_entry_count
), candidate_batches AS (
    SELECT parameters->>'batch_execution_uuid' AS batch_execution_uuid, MAX(started_at) AS started_at
    FROM complete_2024_runs
    GROUP BY parameters->>'batch_execution_uuid'
    HAVING COUNT(*) = 6
       AND COUNT(DISTINCT stat_code) = 6
       AND MIN(target_race_count) = MAX(target_race_count)
       AND MIN(target_entry_count) = MAX(target_entry_count)
       AND MIN(history_from) = MAX(history_from)
       AND MIN(parameters->>'stat01_run_id') = MAX(parameters->>'stat01_run_id')
), selected_batch AS (
    SELECT COALESCE(NULLIF(:'batch_execution_uuid', ''), (SELECT batch_execution_uuid FROM candidate_batches ORDER BY started_at DESC LIMIT 1)) AS batch_execution_uuid
), results AS (
    SELECT result.stat_code, result.status, result.features
    FROM statistic_feature_results AS result
    JOIN complete_2024_runs AS run ON run.id = result.feature_run_id
    CROSS JOIN selected_batch
    WHERE run.parameters->>'batch_execution_uuid' = selected_batch.batch_execution_uuid
), distributions AS (
    SELECT 'STAT-07 same-track sample' AS metric, (features->'SAME_TRACK_ACQUIRED'->>'sample_count') AS category FROM results WHERE stat_code = 'STAT-07'
    UNION ALL
    SELECT 'STAT-08 same-hour sample', (features->'SAME_HOUR_ACQUIRED'->>'sample_count') FROM results WHERE stat_code = 'STAT-08'
    UNION ALL
    SELECT 'STAT-08 target hour', (features->'TARGET_TIME'->>'hour_of_day') FROM results WHERE stat_code = 'STAT-08'
    UNION ALL
    SELECT 'STAT-23 target day number', (features->'TARGET_MEETING_DAY'->>'day_number') FROM results WHERE stat_code = 'STAT-23'
    UNION ALL
    SELECT 'STAT-23 same-day sample', (features->'SAME_DAY_NUMBER_HISTORY'->>'sample_count') FROM results WHERE stat_code = 'STAT-23'
    UNION ALL
    SELECT 'STAT-31 semifinal/final observed count', (features->'STAGE_EXPERIENCE'->>'semifinal_or_final_count') FROM results WHERE stat_code = 'STAT-31'
    UNION ALL
    SELECT 'STAT-32 normalized stage', (features->'TARGET_STAGE'->>'normalized_stage') FROM results WHERE stat_code = 'STAT-32'
    UNION ALL
    SELECT 'STAT-33 status', status FROM results WHERE stat_code = 'STAT-33'
    UNION ALL
    SELECT 'STAT-33 current day number', (features->'CURRENT_MEETING_CONTEXT'->>'previous_day_number') FROM results WHERE stat_code = 'STAT-33'
    UNION ALL
    SELECT 'STAT-33 transition sample', (features->'MATCHING_TRANSITION_HISTORY'->>'transition_sample_count') FROM results WHERE stat_code = 'STAT-33'
)
SELECT metric, COALESCE(category, '<NULL>') AS category, COUNT(*) AS result_count
FROM distributions
GROUP BY metric, category
ORDER BY metric, category;
