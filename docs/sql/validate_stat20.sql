-- Read-only PostgreSQL/psql checks. Supply an explicit -v stat20_run_id=<id>.
-- This script was not executed against the production DB during implementation.
\set ON_ERROR_STOP on
\if :{?stat20_run_id}
\else
\echo 'ERROR: An explicit stat20_run_id is required; no latest-run fallback.'
\quit 1
\endif

BEGIN READ ONLY;
SET LOCAL statement_timeout = '5min';

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
