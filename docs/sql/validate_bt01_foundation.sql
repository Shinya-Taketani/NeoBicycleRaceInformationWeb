-- BT-01B schema and source-manifest validation only.
-- This script performs no performance, winner-rate, 2026, or ROI analysis.

SELECT table_name
FROM information_schema.tables
WHERE table_schema = 'public'
  AND table_name IN (
    'backtest_runs',
    'backtest_folds',
    'backtest_feature_sources',
    'backtest_predictions',
    'backtest_metrics',
    'backtest_exclusions'
  )
ORDER BY table_name;

SELECT
    tc.table_name,
    kcu.column_name,
    ccu.table_name AS referenced_table,
    ccu.column_name AS referenced_column
FROM information_schema.table_constraints tc
JOIN information_schema.key_column_usage kcu
  ON kcu.constraint_name = tc.constraint_name
 AND kcu.constraint_schema = tc.constraint_schema
JOIN information_schema.constraint_column_usage ccu
  ON ccu.constraint_name = tc.constraint_name
 AND ccu.constraint_schema = tc.constraint_schema
WHERE tc.constraint_type = 'FOREIGN KEY'
  AND tc.table_schema = 'public'
  AND tc.table_name LIKE 'backtest\_%' ESCAPE '\'
ORDER BY tc.table_name, kcu.column_name;

WITH expected AS (
    SELECT *
    FROM (VALUES
        (25::bigint, '82a88496-35b4-48fc-81c3-8b46b5eb626f'::uuid, DATE '2022-01-01', DATE '2022-12-31', 24868::bigint, 174152::bigint),
        (26::bigint, '71c344f6-e09b-4496-9cd0-a68642e2c462'::uuid, DATE '2023-01-01', DATE '2023-12-31', 25561::bigint, 181548::bigint),
        (1::bigint, '07f2fc31-0d9c-41d9-95b7-80c7afb396ce'::uuid, DATE '2024-01-01', DATE '2024-12-31', 25624::bigint, 182004::bigint),
        (27::bigint, 'b62ba626-5019-4018-8cd7-7d09c61a8ceb'::uuid, DATE '2025-01-01', DATE '2025-12-31', 25273::bigint, 180005::bigint)
    ) AS source(feature_run_id, run_uuid, target_from, target_to, expected_races, expected_results)
), actual_results AS (
    SELECT
        feature_run_id,
        COUNT(*) AS result_count,
        COUNT(DISTINCT race_id) AS race_count,
        COUNT(*) FILTER (WHERE stat_code <> 'STAT-01') AS invalid_stat_code_count,
        COUNT(*) FILTER (WHERE calculation_version <> 'STAT-01-existing-db-v1') AS invalid_calculation_version_count,
        COUNT(*) FILTER (WHERE subject_type <> 'RACE_ENTRY') AS invalid_subject_type_count
    FROM statistic_feature_results
    WHERE feature_run_id IN (1, 25, 26, 27)
    GROUP BY feature_run_id
)
SELECT
    expected.*,
    runs.stat_code,
    runs.calculation_version,
    runs.status,
    runs.error_count,
    runs.target_race_count,
    runs.target_entry_count,
    actual_results.result_count,
    actual_results.race_count,
    actual_results.invalid_stat_code_count,
    actual_results.invalid_calculation_version_count,
    actual_results.invalid_subject_type_count
FROM expected
LEFT JOIN statistic_feature_runs runs ON runs.id = expected.feature_run_id
LEFT JOIN actual_results ON actual_results.feature_run_id = expected.feature_run_id
ORDER BY expected.target_from;
