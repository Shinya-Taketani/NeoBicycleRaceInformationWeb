-- Read-only PostgreSQL/psql checks. Supply an explicit -v stat20_run_id=<id>.
-- This script was not executed against the production DB during implementation.
\set ON_ERROR_STOP on
\unset stat20_validation_ok
\if :{?stat20_run_id}
\else
\echo 'ERROR: An explicit stat20_run_id is required; no latest-run fallback.'
SELECT 1 / 0 AS stat20_validation_missing_run_id;
\endif

BEGIN READ ONLY;
SET TRANSACTION ISOLATION LEVEL REPEATABLE READ;
SET LOCAL statement_timeout = '5min';

-- Structural completeness only: partial/degraded/missing/pending quality is valid.
-- Use one snapshot for the gate and the diagnostic reports that follow it.
WITH selected_run AS (
    SELECT * FROM statistic_feature_runs WHERE id = :'stat20_run_id'::bigint
), root_run AS (
    SELECT root.* FROM statistic_feature_runs AS root
    JOIN selected_run AS run ON root.id = (run.parameters->>'stat01_run_id')::bigint
), source_entries AS (
    SELECT source.id, source.race_id, source.race_entry_id, source.player_id,
           source.bike_number, source.input_as_of, source.input_hash
    FROM statistic_feature_results AS source
    JOIN root_run AS root ON root.id = source.feature_run_id
    WHERE source.stat_code = 'STAT-01' AND source.subject_type = 'RACE_ENTRY'
), expected_entries AS (
    SELECT source.* FROM source_entries AS source
    JOIN races ON races.id = source.race_id
    CROSS JOIN selected_run AS run
    WHERE races.race_date < DATE '2026-01-01'
      AND ((run.target_race_id IS NOT NULL AND source.race_id = run.target_race_id)
           OR (run.target_race_id IS NULL AND races.race_date BETWEEN run.target_from AND run.target_to))
), saved AS (
    SELECT results.stat_code, results.calculation_version, results.acquisition_mode,
           results.subject_type, results.subject_key, results.race_id, results.race_entry_id,
           results.player_id, results.bike_number, results.input_as_of, results.input_hash,
           results.raw_points, results.confidence, results.effective_points,
           (results.evidence->>'stat01_run_id')::bigint AS source_run_id,
           results.evidence->>'stat01_input_hash' AS source_input_hash
    FROM statistic_feature_results AS results
    JOIN selected_run AS run ON run.id = results.feature_run_id
), items AS (
    SELECT items.* FROM statistic_feature_run_items AS items
    JOIN selected_run AS run ON run.id = items.feature_run_id
), race_counts AS (
    SELECT race_id, COUNT(*) AS result_count FROM saved GROUP BY race_id
)
SELECT EXISTS (
    SELECT 1 FROM selected_run AS run CROSS JOIN root_run AS root
    WHERE run.stat_code = 'STAT-20'
      AND run.calculation_version = 'STAT-20-existing-db-v1'
      AND run.mode = 'BACKFILL'
      AND run.status IN ('SUCCEEDED', 'PARTIALLY_SUCCEEDED')
      AND run.finished_at IS NOT NULL
      AND run.target_race_count > 0 AND run.target_entry_count > 0
      AND run.processed_race_count = run.target_race_count AND run.error_count = 0
      AND root.stat_code = 'STAT-01' AND root.calculation_version = 'STAT-01-existing-db-v1'
      AND root.mode = 'BACKFILL' AND root.status IN ('SUCCEEDED', 'PARTIALLY_SUCCEEDED')
      AND root.finished_at IS NOT NULL AND root.processed_race_count = root.target_race_count
      AND root.error_count = 0 AND (SELECT COUNT(*) FROM source_entries) = root.target_entry_count
      AND (SELECT COUNT(*) FROM expected_entries) = run.target_entry_count
      AND (SELECT COUNT(DISTINCT race_id) FROM expected_entries) = run.target_race_count
      AND (SELECT COUNT(*) FROM saved) = run.target_entry_count
      AND (SELECT COUNT(*) FROM race_counts) = run.target_race_count
      AND (SELECT COUNT(*) FROM items) = run.target_race_count
      AND NOT EXISTS (
          SELECT 1 FROM saved AS result
          LEFT JOIN race_entries AS entry ON entry.id = result.race_entry_id
          LEFT JOIN expected_entries AS source
            ON source.race_entry_id = result.race_entry_id AND source.race_id = result.race_id
          WHERE result.stat_code IS DISTINCT FROM 'STAT-20'
             OR result.calculation_version IS DISTINCT FROM 'STAT-20-existing-db-v1'
             OR result.acquisition_mode IS DISTINCT FROM 'BACKFILL'
             OR result.subject_type IS DISTINCT FROM 'RACE_ENTRY'
             OR result.race_entry_id IS NULL OR result.race_entry_id < 1
             OR result.subject_key IS DISTINCT FROM 'race_entry:' || result.race_entry_id::text
             OR entry.id IS NULL OR entry.race_id IS DISTINCT FROM result.race_id
             OR entry.player_id IS DISTINCT FROM result.player_id OR entry.bike_number IS DISTINCT FROM result.bike_number
             OR source.id IS NULL OR source.player_id IS DISTINCT FROM result.player_id
             OR source.bike_number IS DISTINCT FROM result.bike_number
             OR source.input_as_of IS DISTINCT FROM result.input_as_of
             OR result.source_run_id IS DISTINCT FROM root.id
             OR source.input_hash IS DISTINCT FROM result.source_input_hash
             OR result.input_hash IS NULL OR result.input_hash !~ '^[a-f0-9]{64}$'
             OR result.raw_points IS NOT NULL OR result.confidence IS NOT NULL OR result.effective_points IS NOT NULL
      )
      AND NOT EXISTS (SELECT 1 FROM saved GROUP BY race_entry_id HAVING COUNT(*) <> 1)
      AND NOT EXISTS (
          SELECT 1 FROM items LEFT JOIN race_counts ON race_counts.race_id = items.race_id
          WHERE items.status IS NULL OR items.status NOT IN ('SUCCEEDED', 'PARTIAL')
             OR items.finished_at IS NULL OR race_counts.race_id IS NULL
             OR items.feature_result_count IS DISTINCT FROM race_counts.result_count
      )
      AND NOT EXISTS (SELECT 1 FROM items GROUP BY race_id HAVING COUNT(*) <> 1)
) AS stat20_validation_ok
\gset

\if :stat20_validation_ok
\else
\echo 'ERROR: STAT-20 run/source/results/items are incomplete or inconsistent.'
SELECT 1 / 0 AS stat20_validation_failed;
\endif

SELECT id, stat_code, calculation_version, status, finished_at,
       target_race_count, processed_race_count, target_entry_count,
       success_count, partial_count, missing_count, invalid_count, error_count,
       parameters->'source_run_ids' AS source_run_ids
FROM statistic_feature_runs
WHERE id = :'stat20_run_id'::bigint
  AND stat_code = 'STAT-20'
  AND calculation_version = 'STAT-20-existing-db-v1';

WITH selected AS (
    SELECT * FROM statistic_feature_results WHERE feature_run_id = :'stat20_run_id'::bigint
)
SELECT COUNT(*) AS result_count, COUNT(DISTINCT race_id) AS race_count,
       COUNT(*) FILTER (WHERE subject_type <> 'RACE_ENTRY'
           OR subject_key <> 'race_entry:' || race_entry_id::text
           OR race_entry_id IS NULL) AS invalid_subjects,
       COUNT(*) FILTER (WHERE raw_points IS NOT NULL OR confidence IS NOT NULL
           OR effective_points IS NOT NULL) AS invented_points_or_confidence,
       COUNT(*) FILTER (WHERE input_hash !~ '^[a-f0-9]{64}$') AS invalid_input_hashes,
       COUNT(*) FILTER (WHERE evidence->>'historical_as_of_available' IS DISTINCT FROM 'false'
           OR evidence->>'prediction_use' IS DISTINCT FROM 'NOT_AUTHORIZED') AS unauthorized_use,
       (SELECT COUNT(*) FROM (
           SELECT subject_key FROM selected GROUP BY subject_key HAVING COUNT(*) > 1
       ) AS duplicates) AS duplicate_subjects
FROM selected;

SELECT status, quality_status, COUNT(*) AS result_count
FROM statistic_feature_results WHERE feature_run_id = :'stat20_run_id'::bigint
GROUP BY status, quality_status ORDER BY status, quality_status;

-- Current identity agreement is not proof of historical prestart identity.
SELECT COUNT(*) AS missing_or_conflicting_current_entry,
       COUNT(*) FILTER (WHERE source.id IS NULL) AS missing_stat01_result
FROM statistic_feature_results AS result
LEFT JOIN race_entries AS entry ON entry.id = result.race_entry_id
LEFT JOIN statistic_feature_results AS source
  ON source.feature_run_id = (result.evidence->>'stat01_run_id')::bigint
 AND source.stat_code = 'STAT-01' AND source.race_entry_id = result.race_entry_id
 AND source.race_id = result.race_id
WHERE result.feature_run_id = :'stat20_run_id'::bigint
  AND (entry.id IS NULL OR entry.race_id IS DISTINCT FROM result.race_id
       OR entry.player_id IS DISTINCT FROM result.player_id
       OR entry.bike_number IS DISTINCT FROM result.bike_number
       OR source.id IS NULL OR source.player_id IS DISTINCT FROM result.player_id
       OR source.bike_number IS DISTINCT FROM result.bike_number
       OR source.input_hash IS DISTINCT FROM result.evidence->>'stat01_input_hash');

SELECT audit.key AS stat_code, audit.value->>'status' AS source_status,
       audit.value->>'quality_status' AS source_quality,
       audit.value->>'fetch_vs_input_as_of' AS fetch_vs_input_as_of,
       audit.value->>'fetch_vs_scheduled_start' AS fetch_vs_scheduled_start,
       audit.value->>'original_fetch_log_raw_linkage' AS raw_linkage,
       audit.value->>'official_publication_as_of' AS official_publication,
       COUNT(*) AS entry_count
FROM statistic_feature_results AS result
CROSS JOIN LATERAL jsonb_each(result.features->'STAT_QUALITY') AS audit
WHERE result.feature_run_id = :'stat20_run_id'::bigint
GROUP BY audit.key, audit.value->>'status', audit.value->>'quality_status',
         audit.value->>'fetch_vs_input_as_of', audit.value->>'fetch_vs_scheduled_start',
         audit.value->>'original_fetch_log_raw_linkage', audit.value->>'official_publication_as_of'
ORDER BY stat_code, source_status;

SELECT items.status, COUNT(*) AS item_count,
       SUM(items.feature_result_count) AS saved_results,
       COUNT(*) FILTER (WHERE items.finished_at IS NULL) AS unfinished_items
FROM statistic_feature_run_items AS items
WHERE items.feature_run_id = :'stat20_run_id'::bigint
GROUP BY items.status ORDER BY items.status;

SELECT reason.value AS blocked_reason, COUNT(*) AS entry_count
FROM statistic_feature_results AS result
CROSS JOIN LATERAL jsonb_array_elements_text(result.evidence->'blocked_reasons') AS reason
WHERE result.feature_run_id = :'stat20_run_id'::bigint
GROUP BY reason.value ORDER BY reason.value;

ROLLBACK;
