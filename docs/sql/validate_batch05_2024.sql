-- Read-only compact Batch05 validation for PostgreSQL/psql.
-- Pass -v batch_execution_uuid='<uuid>' to select an explicit run.
\if :{?batch_execution_uuid}
\else
\set batch_execution_uuid ''
\endif

WITH candidates AS (
    SELECT runs.*
    FROM statistic_feature_runs AS runs
    WHERE runs.stat_code = 'STAT-41'
      AND runs.calculation_version = 'STAT-41-existing-db-v1'
      AND runs.target_from = DATE '2024-01-01'
      AND runs.target_to = DATE '2024-12-31'
      AND runs.target_race_id IS NULL
      AND runs.target_race_count > 0
      AND runs.processed_race_count = runs.target_race_count
      AND runs.error_count = 0
      AND runs.finished_at IS NOT NULL
      AND (SELECT COUNT(*) FROM statistic_feature_results AS results WHERE results.feature_run_id = runs.id) = runs.target_race_count
      AND (NULLIF(:'batch_execution_uuid', '') IS NULL
           OR runs.parameters->>'batch_execution_uuid' = NULLIF(:'batch_execution_uuid', ''))
)
SELECT id AS selected_run_id
FROM candidates
ORDER BY started_at DESC, id DESC
LIMIT 1
\gset

SELECT
    runs.id AS run_id,
    runs.parameters->>'batch_execution_uuid' AS batch_execution_uuid,
    runs.stat_code,
    runs.calculation_version,
    runs.status AS run_status,
    runs.history_from,
    runs.target_race_count,
    runs.processed_race_count,
    runs.target_entry_count,
    COUNT(results.id) AS result_count,
    COUNT(*) FILTER (WHERE results.status = 'VALID') AS valid_count,
    COUNT(*) FILTER (WHERE results.status = 'PARTIAL') AS partial_count,
    COUNT(*) FILTER (WHERE results.status = 'MISSING_INPUT') AS missing_count,
    COUNT(*) FILTER (WHERE results.status = 'INVALID_INPUT') AS invalid_count,
    COUNT(*) FILTER (WHERE results.quality_status = 'FULL') AS quality_full_count,
    COUNT(*) FILTER (WHERE results.quality_status = 'PARTIAL') AS quality_partial_count,
    COUNT(*) FILTER (WHERE results.quality_status = 'DEGRADED') AS quality_degraded_count,
    COUNT(*) FILTER (WHERE results.raw_points IS NOT NULL) AS raw_points_not_null,
    COUNT(*) FILTER (WHERE results.confidence IS NOT NULL) AS confidence_not_null,
    COUNT(*) FILTER (WHERE results.effective_points IS NOT NULL) AS effective_points_not_null,
    runs.error_count
FROM statistic_feature_runs AS runs
LEFT JOIN statistic_feature_results AS results ON results.feature_run_id = runs.id
WHERE runs.id = :'selected_run_id'
GROUP BY runs.id;

WITH selected_results AS (
    SELECT * FROM statistic_feature_results WHERE feature_run_id = :'selected_run_id'
), source_target_races AS (
    SELECT DISTINCT source.race_id
    FROM statistic_feature_runs AS run
    JOIN statistic_feature_results AS source
      ON source.feature_run_id = (run.parameters->>'stat01_run_id')::bigint
    JOIN races ON races.id = source.race_id
    WHERE run.id = :'selected_run_id'
      AND source.stat_code = 'STAT-01'
      AND races.race_date BETWEEN run.target_from AND run.target_to
)
SELECT
    COUNT(*) FILTER (WHERE subject_type <> 'RACE') AS subject_type_not_race,
    COUNT(*) FILTER (WHERE race_entry_id IS NOT NULL) AS race_entry_id_not_null,
    COUNT(*) FILTER (WHERE player_id IS NOT NULL) AS player_id_not_null,
    COUNT(*) FILTER (WHERE opponent_player_id IS NOT NULL) AS opponent_player_id_not_null,
    COUNT(*) FILTER (WHERE bike_number IS NOT NULL) AS bike_number_not_null,
    (SELECT COUNT(*) FROM (SELECT race_id FROM selected_results GROUP BY race_id HAVING COUNT(*) > 1) AS duplicates) AS duplicate_race_results,
    (SELECT COUNT(*) FROM source_target_races AS source WHERE NOT EXISTS (SELECT 1 FROM selected_results AS result WHERE result.race_id = source.race_id)) AS missing_race_results,
    COUNT(*) FILTER (WHERE features->'RACE_COMPETITIVENESS_SCORE' IS NOT NULL AND jsonb_typeof(features->'RACE_COMPETITIVENESS_SCORE') <> 'null') AS race_competitiveness_score_non_null,
    COUNT(*) FILTER (WHERE features->'RACE_PREDICTION_UNCERTAINTY_SCORE' IS NOT NULL AND jsonb_typeof(features->'RACE_PREDICTION_UNCERTAINTY_SCORE') <> 'null') AS race_prediction_uncertainty_score_non_null,
    COUNT(*) FILTER (WHERE features->'RACE_UPSET_STRUCTURE_SCORE' IS NOT NULL AND jsonb_typeof(features->'RACE_UPSET_STRUCTURE_SCORE') <> 'null') AS race_upset_structure_score_non_null,
    COUNT(*) FILTER (WHERE features->'PREDICTION_PROBABILITY_ENTROPY' IS NOT NULL AND jsonb_typeof(features->'PREDICTION_PROBABILITY_ENTROPY') <> 'null') AS prediction_probability_entropy_non_null
FROM selected_results;

WITH values_by_metric AS (
    SELECT metric.name AS metric, metric.value
    FROM statistic_feature_results AS results
    CROSS JOIN LATERAL (VALUES
        ('score_stddev_pop', NULLIF(results.features->'SCORE_DISTRIBUTION'->>'stddev_pop', '')::numeric),
        ('score_range', NULLIF(results.features->'SCORE_DISTRIBUTION'->>'range', '')::numeric),
        ('score_iqr', NULLIF(results.features->'SCORE_DISTRIBUTION'->>'iqr', '')::numeric),
        ('score_mad', NULLIF(results.features->'SCORE_DISTRIBUTION'->>'mad', '')::numeric),
        ('score_cv_pop', NULLIF(results.features->'SCORE_DISTRIBUTION'->>'cv_pop', '')::numeric)
    ) AS metric(name, value)
    WHERE results.feature_run_id = :'selected_run_id'
)
SELECT metric, COUNT(value), MIN(value),
       percentile_cont(0.25) WITHIN GROUP (ORDER BY value) AS p25,
       percentile_cont(0.50) WITHIN GROUP (ORDER BY value) AS median,
       percentile_cont(0.75) WITHIN GROUP (ORDER BY value) AS p75,
       percentile_cont(0.90) WITHIN GROUP (ORDER BY value) AS p90,
       percentile_cont(0.95) WITHIN GROUP (ORDER BY value) AS p95,
       MAX(value), AVG(value) AS mean
FROM values_by_metric GROUP BY metric ORDER BY metric;

WITH values_by_metric AS (
    SELECT metric.name AS metric, metric.value
    FROM statistic_feature_results AS results
    CROSS JOIN LATERAL (VALUES
        ('gap_rank1_rank2', NULLIF(results.features->'TOP_SCORE_STRUCTURE'->>'gap_rank1_rank2', '')::numeric),
        ('gap_rank1_rank3', NULLIF(results.features->'TOP_SCORE_STRUCTURE'->>'gap_rank1_rank3', '')::numeric),
        ('gap_rank2_rank3', NULLIF(results.features->'TOP_SCORE_STRUCTURE'->>'gap_rank2_rank3', '')::numeric),
        ('gap_rank3_rank4', NULLIF(results.features->'TOP_SCORE_STRUCTURE'->>'gap_rank3_rank4', '')::numeric),
        ('pairwise_mean_absolute_gap', NULLIF(results.features->'PAIRWISE_SCORE_GAPS'->>'mean_absolute_gap', '')::numeric),
        ('pairwise_median_absolute_gap', NULLIF(results.features->'PAIRWISE_SCORE_GAPS'->>'median_absolute_gap', '')::numeric),
        ('pairwise_max_absolute_gap', NULLIF(results.features->'PAIRWISE_SCORE_GAPS'->>'max_absolute_gap', '')::numeric)
    ) AS metric(name, value)
    WHERE results.feature_run_id = :'selected_run_id'
)
SELECT metric, COUNT(value), MIN(value),
       percentile_cont(0.25) WITHIN GROUP (ORDER BY value) AS p25,
       percentile_cont(0.50) WITHIN GROUP (ORDER BY value) AS median,
       percentile_cont(0.75) WITHIN GROUP (ORDER BY value) AS p75,
       percentile_cont(0.90) WITHIN GROUP (ORDER BY value) AS p90,
       percentile_cont(0.95) WITHIN GROUP (ORDER BY value) AS p95,
       MAX(value), AVG(value) AS mean
FROM values_by_metric GROUP BY metric ORDER BY metric;

WITH entrant_counts AS (
    SELECT 'expected entrant count' AS metric, features->'RACE_CONTEXT'->>'expected_entrant_count' AS value
    FROM statistic_feature_results WHERE feature_run_id = :'selected_run_id'
    UNION ALL
    SELECT 'actual entry count', features->'RACE_CONTEXT'->>'actual_entry_count'
    FROM statistic_feature_results WHERE feature_run_id = :'selected_run_id'
)
SELECT metric, COALESCE(value, '<NULL>') AS value, COUNT(*) AS race_count
FROM entrant_counts GROUP BY metric, value ORDER BY metric, value;

SELECT status, COUNT(*) AS race_count,
       MIN((features->'SCORE_COVERAGE'->>'score_coverage_ratio')::numeric) AS min_coverage,
       percentile_cont(0.5) WITHIN GROUP (ORDER BY (features->'SCORE_COVERAGE'->>'score_coverage_ratio')::numeric) AS median_coverage,
       AVG((features->'SCORE_COVERAGE'->>'score_coverage_ratio')::numeric) AS mean_coverage,
       MAX((features->'SCORE_COVERAGE'->>'score_coverage_ratio')::numeric) AS max_coverage,
       COUNT(*) FILTER (WHERE (features->'SCORE_COVERAGE'->>'score_coverage_ratio')::numeric = 1.0) AS full_coverage_races,
       COUNT(*) FILTER (WHERE (features->'SCORE_COVERAGE'->>'score_coverage_ratio')::numeric < 1.0) AS partial_coverage_races,
       MIN((features->'SCORE_COVERAGE'->>'usable_score_count')::int) AS min_usable_scores,
       MAX((features->'SCORE_COVERAGE'->>'missing_score_count')::int) AS max_missing_scores,
       MAX((features->'SCORE_COVERAGE'->>'invalid_score_count')::int) AS max_invalid_scores
FROM statistic_feature_results
WHERE feature_run_id = :'selected_run_id'
GROUP BY status ORDER BY status;
