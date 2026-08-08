-- Read-only Batch04 validation for PostgreSQL/psql.
-- Pass -v batch_execution_uuid='<uuid>' to inspect one batch. When omitted, the
-- latest complete 2024 Batch04 execution is selected.
\if :{?batch_execution_uuid}
\else
\set batch_execution_uuid ''
\endif

WITH expected_stats(stat_code, calculation_version) AS (
    VALUES
        ('STAT-39', 'STAT-39-existing-db-v1'),
        ('STAT-42', 'STAT-42-existing-db-v1')
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
    SELECT parameters->>'batch_execution_uuid' AS batch_execution_uuid, MAX(started_at) AS started_at
    FROM complete_2024_runs
    WHERE parameters->>'batch_execution_uuid' IS NOT NULL
      AND parameters->>'stat01_run_id' IS NOT NULL
      AND history_from IS NOT NULL
    GROUP BY parameters->>'batch_execution_uuid'
    HAVING COUNT(*) = 2
       AND COUNT(DISTINCT stat_code) = 2
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
    VALUES ('STAT-39', 'STAT-39-existing-db-v1'), ('STAT-42', 'STAT-42-existing-db-v1')
), complete_2024_runs AS (
    SELECT runs.* FROM statistic_feature_runs AS runs
    JOIN expected_stats USING (stat_code, calculation_version)
    WHERE runs.target_from = DATE '2024-01-01' AND runs.target_to = DATE '2024-12-31'
      AND runs.target_race_id IS NULL AND runs.target_race_count > 0
      AND runs.processed_race_count = runs.target_race_count AND runs.error_count = 0
      AND runs.finished_at IS NOT NULL
      AND (SELECT COUNT(*) FROM statistic_feature_results AS results WHERE results.feature_run_id = runs.id) = runs.target_entry_count
), candidate_batches AS (
    SELECT parameters->>'batch_execution_uuid' AS batch_execution_uuid, MAX(started_at) AS started_at
    FROM complete_2024_runs GROUP BY parameters->>'batch_execution_uuid'
    HAVING COUNT(*) = 2 AND COUNT(DISTINCT stat_code) = 2
       AND MIN(target_race_count) = MAX(target_race_count) AND MIN(target_entry_count) = MAX(target_entry_count)
       AND MIN(history_from) = MAX(history_from)
       AND MIN(parameters->>'stat01_run_id') = MAX(parameters->>'stat01_run_id')
), selected_batch AS (
    SELECT COALESCE(NULLIF(:'batch_execution_uuid', ''), (SELECT batch_execution_uuid FROM candidate_batches ORDER BY started_at DESC LIMIT 1)) AS batch_execution_uuid
), results AS (
    SELECT result.status, result.features
    FROM statistic_feature_results AS result
    JOIN complete_2024_runs AS run ON run.id = result.feature_run_id
    CROSS JOIN selected_batch
    WHERE run.parameters->>'batch_execution_uuid' = selected_batch.batch_execution_uuid
      AND result.stat_code = 'STAT-39'
), distributions AS (
    SELECT 'status' AS metric, status AS category FROM results
    UNION ALL SELECT 'target entrant count', features->'TARGET_POSITION_CONTEXT'->>'declared_entrant_count' FROM results
    UNION ALL SELECT 'target bike number', features->'TARGET_POSITION_CONTEXT'->>'bike_number' FROM results
    UNION ALL SELECT 'target frame present', CASE WHEN features->'TARGET_POSITION_CONTEXT'->>'frame_number' IS NULL THEN 'NULL' ELSE 'NON_NULL' END FROM results
    UNION ALL SELECT 'entrant count x bike number', CONCAT(features->'TARGET_POSITION_CONTEXT'->>'declared_entrant_count', ':', features->'TARGET_POSITION_CONTEXT'->>'bike_number') FROM results
    UNION ALL SELECT 'FIELD_BIKE sample count', features->'FIELD_BIKE'->>'sample_count' FROM results
    UNION ALL SELECT 'TRACK_FIELD_BIKE sample count', features->'TRACK_FIELD_BIKE'->>'sample_count' FROM results
    UNION ALL SELECT 'FIELD_FRAME sample count', features->'FIELD_FRAME'->>'sample_count' FROM results
    UNION ALL SELECT 'FIELD_BIKE residual sample count', features->'FIELD_BIKE'->>'residual_sample_count' FROM results
)
SELECT metric, COALESCE(category, '<NULL>') AS category, COUNT(*) AS result_count
FROM distributions GROUP BY metric, category ORDER BY metric, category;

WITH expected_stats(stat_code, calculation_version) AS (
    VALUES ('STAT-39', 'STAT-39-existing-db-v1'), ('STAT-42', 'STAT-42-existing-db-v1')
), complete_2024_runs AS (
    SELECT runs.* FROM statistic_feature_runs AS runs
    JOIN expected_stats USING (stat_code, calculation_version)
    WHERE runs.target_from = DATE '2024-01-01' AND runs.target_to = DATE '2024-12-31'
      AND runs.target_race_id IS NULL AND runs.target_race_count > 0
      AND runs.processed_race_count = runs.target_race_count AND runs.error_count = 0
      AND runs.finished_at IS NOT NULL
      AND (SELECT COUNT(*) FROM statistic_feature_results AS results WHERE results.feature_run_id = runs.id) = runs.target_entry_count
), candidate_batches AS (
    SELECT parameters->>'batch_execution_uuid' AS batch_execution_uuid, MAX(started_at) AS started_at
    FROM complete_2024_runs GROUP BY parameters->>'batch_execution_uuid'
    HAVING COUNT(*) = 2 AND COUNT(DISTINCT stat_code) = 2
       AND MIN(target_race_count) = MAX(target_race_count) AND MIN(target_entry_count) = MAX(target_entry_count)
       AND MIN(history_from) = MAX(history_from)
       AND MIN(parameters->>'stat01_run_id') = MAX(parameters->>'stat01_run_id')
), selected_batch AS (
    SELECT COALESCE(NULLIF(:'batch_execution_uuid', ''), (SELECT batch_execution_uuid FROM candidate_batches ORDER BY started_at DESC LIMIT 1)) AS batch_execution_uuid
), results AS (
    SELECT result.status, result.features
    FROM statistic_feature_results AS result
    JOIN complete_2024_runs AS run ON run.id = result.feature_run_id
    CROSS JOIN selected_batch
    WHERE run.parameters->>'batch_execution_uuid' = selected_batch.batch_execution_uuid
      AND result.stat_code = 'STAT-42'
), distributions AS (
    SELECT 'status' AS metric, status AS category FROM results
    UNION ALL SELECT 'current coentrant count', features->'CURRENT_FIELD_CONTEXT'->>'coentrant_count' FROM results
    UNION ALL SELECT 'resolved coentrant count', features->'CURRENT_FIELD_CONTEXT'->>'resolved_coentrant_count' FROM results
    UNION ALL SELECT 'unresolved coentrant count', features->'CURRENT_FIELD_CONTEXT'->>'unresolved_coentrant_count' FROM results
    UNION ALL SELECT 'opponents with direct history count', features->'HEAD_TO_HEAD_SUMMARY'->>'opponents_with_direct_history_count' FROM results
    UNION ALL SELECT 'opponents without direct history count', features->'HEAD_TO_HEAD_SUMMARY'->>'opponents_without_direct_history_count' FROM results
    UNION ALL SELECT 'opponents with normal history count', features->'HEAD_TO_HEAD_SUMMARY'->>'opponents_with_normal_history_count' FROM results
    UNION ALL SELECT 'sum pair direct meeting count', features->'HEAD_TO_HEAD_SUMMARY'->>'sum_pair_direct_meeting_count' FROM results
    UNION ALL SELECT 'unique direct source race count', features->'HEAD_TO_HEAD_SUMMARY'->>'unique_direct_source_race_count' FROM results
)
SELECT metric, COALESCE(category, '<NULL>') AS category, COUNT(*) AS result_count
FROM distributions GROUP BY metric, category ORDER BY metric, category;

WITH expected_stats(stat_code, calculation_version) AS (
    VALUES ('STAT-39', 'STAT-39-existing-db-v1'), ('STAT-42', 'STAT-42-existing-db-v1')
), complete_2024_runs AS (
    SELECT runs.* FROM statistic_feature_runs AS runs
    JOIN expected_stats USING (stat_code, calculation_version)
    WHERE runs.target_from = DATE '2024-01-01' AND runs.target_to = DATE '2024-12-31'
      AND runs.target_race_id IS NULL AND runs.target_race_count > 0
      AND runs.processed_race_count = runs.target_race_count AND runs.error_count = 0
      AND runs.finished_at IS NOT NULL
      AND (SELECT COUNT(*) FROM statistic_feature_results AS results WHERE results.feature_run_id = runs.id) = runs.target_entry_count
), candidate_batches AS (
    SELECT parameters->>'batch_execution_uuid' AS batch_execution_uuid, MAX(started_at) AS started_at
    FROM complete_2024_runs GROUP BY parameters->>'batch_execution_uuid'
    HAVING COUNT(*) = 2 AND COUNT(DISTINCT stat_code) = 2
       AND MIN(target_race_count) = MAX(target_race_count) AND MIN(target_entry_count) = MAX(target_entry_count)
       AND MIN(history_from) = MAX(history_from)
       AND MIN(parameters->>'stat01_run_id') = MAX(parameters->>'stat01_run_id')
), selected_batch AS (
    SELECT COALESCE(NULLIF(:'batch_execution_uuid', ''), (SELECT batch_execution_uuid FROM candidate_batches ORDER BY started_at DESC LIMIT 1)) AS batch_execution_uuid
), pairs AS (
    SELECT pair.value
    FROM statistic_feature_results AS result
    JOIN complete_2024_runs AS run ON run.id = result.feature_run_id
    CROSS JOIN selected_batch
    CROSS JOIN LATERAL jsonb_array_elements(result.features->'HEAD_TO_HEAD_BY_COENTRANT') AS pair(value)
    WHERE run.parameters->>'batch_execution_uuid' = selected_batch.batch_execution_uuid
      AND result.stat_code = 'STAT-42'
), distributions AS (
    SELECT 'pair normal direct meeting count' AS metric, value->'DIRECT_HISTORY'->>'normal_direct_meeting_count' AS category FROM pairs
    UNION ALL
    SELECT 'pair relative residual sample count', value->'DIRECT_HISTORY'->>'relative_expectation_residual_sample_count' FROM pairs
)
SELECT metric, COALESCE(category, '<NULL>') AS category, COUNT(*) AS pair_count
FROM distributions GROUP BY metric, category ORDER BY metric, category;
