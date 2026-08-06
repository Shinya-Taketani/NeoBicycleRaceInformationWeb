-- Read-only acceptance checks for the latest full-year 2024 STAT-01 run.

WITH selected_run AS (
    SELECT *
    FROM statistic_feature_runs
    WHERE stat_code = 'STAT-01'
      AND calculation_version = 'STAT-01-existing-db-v1'
      AND target_from = DATE '2024-01-01'
      AND target_to = DATE '2024-12-31'
    ORDER BY id DESC
    LIMIT 1
),
source_totals AS (
    SELECT
        COUNT(DISTINCT races.id) AS race_count,
        COUNT(race_entries.id) AS entry_count,
        COUNT(*) FILTER (WHERE race_entries.race_score > 0) AS positive_score_count,
        COUNT(*) FILTER (WHERE race_entries.race_score = 0) AS zero_score_count,
        COUNT(*) FILTER (WHERE race_entries.race_score IS NULL) AS null_score_count
    FROM races
    INNER JOIN race_entries ON race_entries.race_id = races.id
    WHERE races.race_date BETWEEN DATE '2024-01-01' AND DATE '2024-12-31'
      AND races.entrant_count BETWEEN 5 AND 9
      AND (races.race_type LIKE 'Ａ級%' OR races.race_type LIKE 'Ｓ級%')
),
result_totals AS (
    SELECT
        COUNT(*) AS result_count,
        COUNT(*) FILTER (WHERE result.status = 'INVALID_INPUT') AS invalid_count,
        COUNT(*) FILTER (WHERE result.status = 'MISSING_INPUT') AS missing_count,
        COUNT(*) FILTER (WHERE result.raw_points IS NOT NULL) AS raw_points_not_null,
        COUNT(*) FILTER (WHERE result.confidence IS NOT NULL) AS confidence_not_null,
        COUNT(*) FILTER (WHERE result.effective_points IS NOT NULL) AS effective_points_not_null,
        COUNT(*) FILTER (
            WHERE (result.features ->> 'RACE_SCORE_RAW')::numeric = 0
              AND (
                  result.status <> 'INVALID_INPUT'
                  OR (result.features ->> 'RACE_SCORE_AVAILABLE')::boolean
                  OR result.features ->> 'RACE_SCORE_RANK' IS NOT NULL
              )
        ) AS invalid_zero_handling_count
    FROM statistic_feature_results AS result
    INNER JOIN selected_run ON selected_run.id = result.feature_run_id
    WHERE result.stat_code = 'STAT-01'
)
SELECT
    selected_run.id AS feature_run_id,
    selected_run.status AS run_status,
    selected_run.target_race_count,
    selected_run.processed_race_count,
    selected_run.target_entry_count,
    source_totals.*,
    result_totals.*
FROM selected_run
CROSS JOIN source_totals
CROSS JOIN result_totals;
