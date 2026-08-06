-- Read-only Batch02 validation for PostgreSQL/psql.
-- Optionally replace the empty value with a batch_execution_uuid.
\set batch_execution_uuid ''

WITH candidate_batches AS (
    SELECT
        parameters->>'batch_execution_uuid' AS batch_execution_uuid,
        MAX(started_at) AS started_at,
        COUNT(DISTINCT stat_code) AS stat_count
    FROM statistic_feature_runs
    WHERE stat_code IN ('STAT-10', 'STAT-11', 'STAT-12', 'STAT-24', 'STAT-26')
      AND finished_at IS NOT NULL
    GROUP BY parameters->>'batch_execution_uuid'
    HAVING COUNT(DISTINCT stat_code) = 5
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
    COUNT(results.id) AS result_count,
    COUNT(results.id) FILTER (WHERE results.status = 'VALID') AS valid_count,
    COUNT(results.id) FILTER (WHERE results.status = 'NO_HISTORY') AS no_history_count,
    COUNT(results.id) FILTER (WHERE results.status = 'PARTIAL_HISTORY') AS partial_history_count,
    COUNT(results.id) FILTER (WHERE results.status = 'MISSING_INPUT') AS missing_count,
    COUNT(results.id) FILTER (WHERE results.status = 'INVALID_INPUT') AS invalid_count,
    COUNT(results.id) FILTER (WHERE results.quality_status = 'FULL') AS quality_full_count,
    COUNT(results.id) FILTER (WHERE results.quality_status = 'PARTIAL') AS quality_partial_count,
    COUNT(results.id) FILTER (WHERE results.quality_status = 'DEGRADED') AS quality_degraded_count,
    COUNT(results.id) FILTER (WHERE results.raw_points IS NOT NULL) AS raw_points_not_null,
    COUNT(results.id) FILTER (WHERE results.confidence IS NOT NULL) AS confidence_not_null,
    COUNT(results.id) FILTER (WHERE results.effective_points IS NOT NULL) AS effective_points_not_null,
    runs.error_count
FROM selected_runs AS runs
LEFT JOIN statistic_feature_results AS results ON results.feature_run_id = runs.id
GROUP BY runs.id
ORDER BY runs.stat_code;

WITH selected_batch AS (
    SELECT COALESCE(
        NULLIF(:'batch_execution_uuid', ''),
        (
            SELECT parameters->>'batch_execution_uuid'
            FROM statistic_feature_runs
            WHERE stat_code IN ('STAT-10', 'STAT-11', 'STAT-12', 'STAT-24', 'STAT-26')
              AND finished_at IS NOT NULL
            GROUP BY parameters->>'batch_execution_uuid'
            HAVING COUNT(DISTINCT stat_code) = 5
            ORDER BY MAX(started_at) DESC
            LIMIT 1
        )
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
