-- Read-only comparison of the latest complete 2024 STAT-01 feature run with
-- the latest legacy STAT-01 calculation_run_id represented in 2024 rows.
--
-- Legacy difference_from_max is score - max (zero or negative). The new
-- RACE_SCORE_GAP_TO_MAX is max - score (zero or positive), so this query
-- compares legacy.difference_from_max with -new_gap_to_max.

WITH complete_new_runs AS (
    SELECT run.id
    FROM statistic_feature_runs AS run
    WHERE run.stat_code = 'STAT-01'
      AND run.calculation_version = 'STAT-01-existing-db-v1'
      AND run.target_from = DATE '2024-01-01'
      AND run.target_to = DATE '2024-12-31'
      AND run.target_race_count > 0
      AND run.processed_race_count = run.target_race_count
      AND (
          SELECT COUNT(*)
          FROM statistic_feature_results AS result
          WHERE result.feature_run_id = run.id
            AND result.stat_code = 'STAT-01'
      ) = run.target_entry_count
),
selected_new_run AS (
    SELECT id
    FROM complete_new_runs
    ORDER BY id DESC
    LIMIT 1
),
selected_legacy_run AS (
    SELECT MAX(legacy.calculation_run_id) AS id
    FROM statistic_entry_results AS legacy
    INNER JOIN races ON races.id = legacy.race_id
    WHERE legacy.stat_code = 'STAT-01'
      AND races.race_date BETWEEN DATE '2024-01-01' AND DATE '2024-12-31'
),
new_results AS (
    SELECT
        result.race_entry_id,
        (result.features ->> 'RACE_SCORE_RAW')::numeric AS race_score,
        (result.features ->> 'RACE_SCORE_RANK')::integer AS score_rank,
        (result.features ->> 'RACE_SCORE_DENSE_RANK')::integer AS dense_rank,
        (result.features ->> 'RACE_SCORE_STRENGTH_PERCENTILE')::numeric AS strength_percentile,
        (result.features ->> 'RACE_SCORE_RACE_MEAN')::numeric AS race_average_score,
        (result.features ->> 'RACE_SCORE_RACE_MAX')::numeric AS race_max_score,
        (result.features ->> 'RACE_SCORE_DIFF_FROM_MEAN')::numeric AS difference_from_average,
        (result.features ->> 'RACE_SCORE_GAP_TO_MAX')::numeric AS gap_to_max,
        (result.features ->> 'RACE_SCORE_STDDEV_POP')::numeric AS race_standard_deviation,
        (result.features ->> 'RACE_SCORE_Z')::numeric AS z_score,
        result.quality_status
    FROM statistic_feature_results AS result
    INNER JOIN selected_new_run ON selected_new_run.id = result.feature_run_id
    WHERE result.stat_code = 'STAT-01'
),
legacy_results AS (
    SELECT legacy.*
    FROM statistic_entry_results AS legacy
    INNER JOIN selected_legacy_run ON selected_legacy_run.id = legacy.calculation_run_id
    INNER JOIN selected_new_run ON TRUE
    INNER JOIN races ON races.id = legacy.race_id
    WHERE legacy.stat_code = 'STAT-01'
      AND races.race_date BETWEEN DATE '2024-01-01' AND DATE '2024-12-31'
),
comparison AS (
    SELECT
        COALESCE(new_result.race_entry_id, legacy.race_entry_id) AS race_entry_id,
        legacy.race_entry_id IS NULL AS missing_in_legacy,
        new_result.race_entry_id IS NULL AS missing_in_new,
        legacy.race_score IS DISTINCT FROM new_result.race_score AS race_score_differs,
        legacy.score_rank IS DISTINCT FROM new_result.score_rank AS score_rank_differs,
        legacy.dense_rank IS DISTINCT FROM new_result.dense_rank AS dense_rank_differs,
        legacy.strength_percentile IS DISTINCT FROM new_result.strength_percentile AS percentile_differs,
        legacy.race_average_score IS DISTINCT FROM new_result.race_average_score AS mean_differs,
        legacy.race_max_score IS DISTINCT FROM new_result.race_max_score AS max_differs,
        legacy.difference_from_average IS DISTINCT FROM new_result.difference_from_average AS mean_difference_differs,
        legacy.difference_from_max IS DISTINCT FROM -new_result.gap_to_max AS max_difference_differs_after_sign_conversion,
        legacy.race_standard_deviation IS DISTINCT FROM new_result.race_standard_deviation AS stddev_differs,
        legacy.z_score IS DISTINCT FROM new_result.z_score AS z_score_differs,
        legacy.quality_status IS DISTINCT FROM new_result.quality_status AS quality_status_differs
    FROM legacy_results AS legacy
    FULL OUTER JOIN new_results AS new_result USING (race_entry_id)
)
SELECT
    (SELECT id FROM selected_new_run) AS selected_new_run_id,
    COUNT(*) AS compared_rows,
    COUNT(*) FILTER (WHERE missing_in_legacy) AS missing_in_legacy,
    COUNT(*) FILTER (WHERE missing_in_new) AS missing_in_new,
    COUNT(*) FILTER (WHERE race_score_differs) AS race_score_differences,
    COUNT(*) FILTER (WHERE score_rank_differs) AS rank_differences,
    COUNT(*) FILTER (WHERE dense_rank_differs) AS dense_rank_differences,
    COUNT(*) FILTER (WHERE percentile_differs) AS percentile_differences,
    COUNT(*) FILTER (WHERE mean_differs) AS mean_differences,
    COUNT(*) FILTER (WHERE max_differs) AS max_differences,
    COUNT(*) FILTER (WHERE mean_difference_differs) AS mean_difference_differences,
    COUNT(*) FILTER (WHERE max_difference_differs_after_sign_conversion) AS max_difference_differences,
    COUNT(*) FILTER (WHERE stddev_differs) AS stddev_differences,
    COUNT(*) FILTER (WHERE z_score_differs) AS z_score_differences,
    COUNT(*) FILTER (WHERE quality_status_differs) AS quality_status_differences
FROM comparison;

-- Detail sample. This intentionally repeats the read-only CTE so the file can
-- be run as-is in psql without creating temporary objects.
WITH complete_new_runs AS (
    SELECT run.id
    FROM statistic_feature_runs AS run
    WHERE run.stat_code = 'STAT-01'
      AND run.calculation_version = 'STAT-01-existing-db-v1'
      AND run.target_from = DATE '2024-01-01'
      AND run.target_to = DATE '2024-12-31'
      AND run.target_race_count > 0
      AND run.processed_race_count = run.target_race_count
      AND (
          SELECT COUNT(*)
          FROM statistic_feature_results AS result
          WHERE result.feature_run_id = run.id
            AND result.stat_code = 'STAT-01'
      ) = run.target_entry_count
),
selected_new_run AS (
    SELECT id
    FROM complete_new_runs
    ORDER BY id DESC
    LIMIT 1
),
selected_legacy_run AS (
    SELECT MAX(legacy.calculation_run_id) AS id
    FROM statistic_entry_results AS legacy
    INNER JOIN races ON races.id = legacy.race_id
    WHERE legacy.stat_code = 'STAT-01'
      AND races.race_date BETWEEN DATE '2024-01-01' AND DATE '2024-12-31'
)
SELECT
    COALESCE(new_result.race_entry_id, legacy.race_entry_id) AS race_entry_id,
    legacy.race_score AS legacy_race_score,
    (new_result.features ->> 'RACE_SCORE_RAW')::numeric AS new_race_score,
    legacy.score_rank AS legacy_rank,
    (new_result.features ->> 'RACE_SCORE_RANK')::integer AS new_rank,
    legacy.difference_from_max AS legacy_score_minus_max,
    (new_result.features ->> 'RACE_SCORE_GAP_TO_MAX')::numeric AS new_max_minus_score,
    legacy.race_standard_deviation AS legacy_stddev,
    (new_result.features ->> 'RACE_SCORE_STDDEV_POP')::numeric AS new_stddev,
    legacy.z_score AS legacy_z_score,
    (new_result.features ->> 'RACE_SCORE_Z')::numeric AS new_z_score,
    legacy.quality_status AS legacy_quality_status,
    new_result.quality_status AS new_quality_status
FROM statistic_entry_results AS legacy
INNER JOIN selected_legacy_run ON selected_legacy_run.id = legacy.calculation_run_id
INNER JOIN selected_new_run AS required_new_run ON TRUE
FULL OUTER JOIN (
    SELECT result.*
    FROM statistic_feature_results AS result
    INNER JOIN selected_new_run ON selected_new_run.id = result.feature_run_id
    WHERE result.stat_code = 'STAT-01'
) AS new_result USING (race_entry_id)
WHERE legacy.race_entry_id IS NULL
   OR new_result.race_entry_id IS NULL
   OR legacy.race_score IS DISTINCT FROM (new_result.features ->> 'RACE_SCORE_RAW')::numeric
   OR legacy.score_rank IS DISTINCT FROM (new_result.features ->> 'RACE_SCORE_RANK')::integer
   OR legacy.dense_rank IS DISTINCT FROM (new_result.features ->> 'RACE_SCORE_DENSE_RANK')::integer
   OR legacy.difference_from_max IS DISTINCT FROM -(new_result.features ->> 'RACE_SCORE_GAP_TO_MAX')::numeric
   OR legacy.race_standard_deviation IS DISTINCT FROM (new_result.features ->> 'RACE_SCORE_STDDEV_POP')::numeric
   OR legacy.z_score IS DISTINCT FROM (new_result.features ->> 'RACE_SCORE_Z')::numeric
   OR legacy.quality_status IS DISTINCT FROM new_result.quality_status
ORDER BY race_entry_id
LIMIT 200;
