-- Read-only Batch02 validation for PostgreSQL/psql.
-- Optionally replace the empty value with a batch_execution_uuid.
\set batch_execution_uuid ''

WITH expected_stats(stat_code, calculation_version) AS (
    VALUES
        ('STAT-10', 'STAT-10-existing-db-v1'),
        ('STAT-11', 'STAT-11-existing-db-v1'),
        ('STAT-12', 'STAT-12-existing-db-v1'),
        ('STAT-24', 'STAT-24-existing-db-v1'),
        ('STAT-26', 'STAT-26-existing-db-v1')
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
    HAVING COUNT(*) = 5
       AND COUNT(DISTINCT stat_code) = 5
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
    FROM statistic_feature_runs AS runs
    CROSS JOIN selected_batch
    WHERE runs.parameters->>'batch_execution_uuid' = selected_batch.batch_execution_uuid
      AND runs.stat_code IN ('STAT-10', 'STAT-11', 'STAT-12', 'STAT-24', 'STAT-26')
), result_totals AS (
    SELECT
        results.feature_run_id,
        COUNT(*) AS result_count,
        COUNT(*) FILTER (WHERE results.status = 'VALID') AS valid_count,
        COUNT(*) FILTER (WHERE results.status = 'NO_HISTORY') AS no_history_count,
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
    COALESCE(totals.result_count, 0) AS result_count,
    COALESCE(totals.valid_count, 0) AS valid_count,
    COALESCE(totals.no_history_count, 0) AS no_history_count,
    COALESCE(totals.partial_history_count, 0) AS partial_history_count,
    COALESCE(totals.missing_count, 0) AS missing_count,
    COALESCE(totals.invalid_count, 0) AS invalid_count,
    COALESCE(totals.quality_full_count, 0) AS quality_full_count,
    COALESCE(totals.quality_partial_count, 0) AS quality_partial_count,
    COALESCE(totals.quality_degraded_count, 0) AS quality_degraded_count,
    COALESCE(totals.raw_points_not_null, 0) AS raw_points_not_null,
    COALESCE(totals.confidence_not_null, 0) AS confidence_not_null,
    COALESCE(totals.effective_points_not_null, 0) AS effective_points_not_null,
    runs.error_count
FROM selected_runs AS runs
LEFT JOIN result_totals AS totals ON totals.feature_run_id = runs.id
ORDER BY runs.stat_code;

WITH expected_stats(stat_code, calculation_version) AS (
    VALUES
        ('STAT-10', 'STAT-10-existing-db-v1'),
        ('STAT-11', 'STAT-11-existing-db-v1'),
        ('STAT-12', 'STAT-12-existing-db-v1'),
        ('STAT-24', 'STAT-24-existing-db-v1'),
        ('STAT-26', 'STAT-26-existing-db-v1')
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
    SELECT
        parameters->>'batch_execution_uuid' AS batch_execution_uuid,
        MAX(started_at) AS started_at
    FROM complete_2024_runs
    WHERE parameters->>'batch_execution_uuid' IS NOT NULL
      AND parameters->>'stat01_run_id' IS NOT NULL
      AND history_from IS NOT NULL
    GROUP BY parameters->>'batch_execution_uuid'
    HAVING COUNT(*) = 5
       AND COUNT(DISTINCT stat_code) = 5
       AND MIN(target_race_count) = MAX(target_race_count)
       AND MIN(target_entry_count) = MAX(target_entry_count)
       AND MIN(history_from) = MAX(history_from)
       AND MIN(parameters->>'stat01_run_id') = MAX(parameters->>'stat01_run_id')
), selected_batch AS (
    SELECT COALESCE(
        NULLIF(:'batch_execution_uuid', ''),
        (SELECT batch_execution_uuid FROM candidate_batches ORDER BY started_at DESC LIMIT 1)
    ) AS batch_execution_uuid
), results AS (
    SELECT result.stat_code, result.features
    FROM statistic_feature_results AS result
    JOIN statistic_feature_runs AS run ON run.id = result.feature_run_id
    CROSS JOIN selected_batch
    WHERE run.parameters->>'batch_execution_uuid' = selected_batch.batch_execution_uuid
), windows AS (
    SELECT stat_code, 'PRE_MEETING' AS scope, 'COUNT_WINDOWS' AS window_type, item.key AS window_key, item.value
    FROM results CROSS JOIN LATERAL jsonb_each(features->'PRE_MEETING'->'COUNT_WINDOWS') AS item
    WHERE stat_code IN ('STAT-10', 'STAT-24')
    UNION ALL
    SELECT stat_code, 'PRE_MEETING', 'DAY_WINDOWS', item.key, item.value
    FROM results CROSS JOIN LATERAL jsonb_each(features->'PRE_MEETING'->'DAY_WINDOWS') AS item
    WHERE stat_code IN ('STAT-10', 'STAT-24')
    UNION ALL
    SELECT stat_code, 'ALL_HISTORY', 'COUNT_WINDOWS', item.key, item.value
    FROM results CROSS JOIN LATERAL jsonb_each(features->'COUNT_WINDOWS') AS item
    WHERE stat_code = 'STAT-11'
    UNION ALL
    SELECT stat_code, 'ALL_HISTORY', 'DAY_WINDOWS', item.key, item.value
    FROM results CROSS JOIN LATERAL jsonb_each(features->'DAY_WINDOWS') AS item
    WHERE stat_code IN ('STAT-11', 'STAT-26')
)
SELECT
    stat_code,
    scope,
    window_type,
    window_key,
    COUNT(*) AS result_count,
    MIN((value->>'sample_count')::integer) AS min_sample_count,
    ROUND(AVG((value->>'sample_count')::numeric), 3) AS avg_sample_count,
    MAX((value->>'sample_count')::integer) AS max_sample_count,
    COUNT(*) FILTER (WHERE COALESCE((value->>'window_complete')::boolean, false) = false) AS incomplete_window_count
FROM windows
GROUP BY stat_code, scope, window_type, window_key
ORDER BY stat_code, scope, window_type, window_key::integer;
