-- STAT39-BIKE-FRAME-PROBABILITY-RESEARCH-01-v1 / PostgreSQL 18
-- READ ONLY. No objects are created. No 2026 races or runs are selected.
-- Review preflight/quality evidence before using the probability table.
-- Frame numbers and day_kind are stored raw attributes, not inferred meanings.
-- The recorded full-year runs are selected explicitly, not by latest timestamp.
BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY;
SET LOCAL statement_timeout = '60s';
SET LOCAL lock_timeout = '2s';
SET LOCAL TIME ZONE 'Asia/Tokyo';

-- query: quality
WITH scoped AS (
    SELECT * FROM races
    WHERE race_date >= DATE '2024-01-01' AND race_date < DATE '2026-01-01'
), counts AS (
    SELECT r.id, COUNT(e.id) AS entry_count, COUNT(DISTINCT e.bike_number) AS bike_count,
        COUNT(rr.id) AS result_count,
        COUNT(*) FILTER (WHERE rr.race_entry_id IS NOT NULL AND rr.race_entry_id <> e.id) AS entry_conflicts,
        COUNT(*) FILTER (WHERE rr.player_id IS NOT NULL AND e.player_id IS NOT NULL AND rr.player_id <> e.player_id) AS player_conflicts,
        COUNT(*) FILTER (WHERE rr.race_entry_id IS NULL) AS entry_links_missing,
        COUNT(*) FILTER (WHERE rr.player_id IS NULL) AS player_links_missing,
        COUNT(*) FILTER (WHERE e.bike_number NOT BETWEEN 1 AND r.entrant_count) AS invalid_bikes,
        COUNT(*) FILTER (WHERE rr.result_status IN ('FINISHED', 'TIED')
            AND (rr.rank IS NULL OR rr.rank NOT BETWEEN 1 AND r.entrant_count)) AS invalid_normal_ranks
    FROM scoped r LEFT JOIN race_entries e ON e.race_id = r.id
    LEFT JOIN race_results rr ON rr.race_id = r.id AND rr.bike_number = e.bike_number
    WHERE (r.race_type LIKE 'Ａ級%' OR r.race_type LIKE 'Ｓ級%')
        AND r.entrant_count IN (7, 9) AND r.result_status IN ('CONFIRMED', 'CORRECTED')
    GROUP BY r.id
)
SELECT EXTRACT(YEAR FROM r.race_date)::int AS year, COUNT(*) AS all_year_races,
    COUNT(*) FILTER (WHERE NOT COALESCE(r.race_type LIKE 'Ａ級%' OR r.race_type LIKE 'Ｓ級%', false)) AS excluded_not_identified_men_as,
    COUNT(*) FILTER (WHERE (r.race_type LIKE 'Ａ級%' OR r.race_type LIKE 'Ｓ級%')
        AND NOT COALESCE(r.entrant_count IN (7, 9), false)) AS excluded_other_field,
    COUNT(*) FILTER (WHERE (r.race_type LIKE 'Ａ級%' OR r.race_type LIKE 'Ｓ級%')
        AND r.entrant_count IN (7, 9) AND r.result_status NOT IN ('CONFIRMED', 'CORRECTED')) AS excluded_result_state,
    COUNT(c.id) AS candidate_races, SUM(c.entry_count) AS registered_entries,
    COUNT(*) FILTER (WHERE c.entry_count <> r.entrant_count) AS field_count_mismatch_races,
    SUM(c.entry_count - c.bike_count) AS duplicate_entry_bikes,
    SUM(c.entry_count - c.result_count) AS missing_result_rows,
    SUM(c.entry_conflicts) AS nonnull_entry_conflicts, SUM(c.player_conflicts) AS nonnull_player_conflicts,
    SUM(c.entry_links_missing) AS null_result_entry_ids, SUM(c.player_links_missing) AS null_result_player_ids,
    SUM(c.invalid_bikes) AS invalid_bikes, SUM(c.invalid_normal_ranks) AS invalid_normal_ranks
FROM scoped r LEFT JOIN counts c ON c.id = r.id GROUP BY 1 ORDER BY 1;

-- query: probabilities
WITH target AS (
    SELECT r.id, EXTRACT(YEAR FROM r.race_date)::int AS year, r.entrant_count,
        r.racetrack_id, r.race_type, m.day_kind, m.id AS meeting_id, m.grade AS meeting_grade
    FROM races r LEFT JOIN race_days d ON d.id = r.race_day_id
    LEFT JOIN race_meetings m ON m.id = d.race_meeting_id
    WHERE r.race_date >= DATE '2024-01-01' AND r.race_date < DATE '2026-01-01'
        AND (r.race_type LIKE 'Ａ級%' OR r.race_type LIKE 'Ｓ級%')
        AND r.entrant_count IN (7, 9) AND r.result_status IN ('CONFIRMED', 'CORRECTED')
), observations AS (
    SELECT t.*, e.id AS entry_id, e.bike_number, e.frame_number, rr.rank, rr.result_status,
        rr.result_status IN ('FINISHED', 'TIED', 'DISQUALIFIED', 'CRASHED', 'DID_NOT_FINISH') AS started,
        rr.result_status IN ('FINISHED', 'TIED') AS normal,
        CASE WHEN t.race_type LIKE 'Ｓ級%' THEN 'S' ELSE 'A' END AS race_class
    FROM target t JOIN race_entries e ON e.race_id = t.id
    LEFT JOIN race_results rr ON rr.race_id = t.id AND rr.bike_number = e.bike_number
), counts AS (
    SELECT year, entrant_count,
        CASE WHEN GROUPING(frame_number) = 0 THEN 'FRAME_RIDER'
             WHEN GROUPING(racetrack_id) = 0 THEN 'TRACK_BIKE'
             WHEN GROUPING(day_kind) = 0 THEN 'SESSION_RAW_BIKE'
             WHEN GROUPING(race_class) = 0 THEN 'RACE_CLASS_BIKE'
             WHEN GROUPING(meeting_grade) = 0 THEN 'MEETING_GRADE_BIKE'
             WHEN GROUPING(bike_number) = 0 THEN 'BIKE'
             ELSE 'FIELD_BASELINE' END AS scope,
        bike_number, frame_number, racetrack_id, day_kind, race_class, meeting_grade,
        COUNT(DISTINCT id) AS race_count, COUNT(DISTINCT meeting_id) AS meeting_count,
        COUNT(*) AS registered_entry_count, COUNT(*) FILTER (WHERE started) AS eligible_entry_count,
        COUNT(*) FILTER (WHERE normal) AS normal_finish_count,
        COUNT(*) FILTER (WHERE normal AND rank = 1) AS first_count,
        COUNT(*) FILTER (WHERE normal AND rank = 2) AS second_count,
        COUNT(*) FILTER (WHERE normal AND rank = 3) AS third_count,
        COUNT(*) FILTER (WHERE normal AND rank <= 2) AS top2_count,
        COUNT(*) FILTER (WHERE normal AND rank <= 3) AS top3_count,
        COUNT(*) FILTER (WHERE result_status = 'TIED') AS tie_count,
        COUNT(*) FILTER (WHERE result_status IN ('DISQUALIFIED', 'CRASHED', 'DID_NOT_FINISH')) AS abnormal_count,
        COUNT(*) FILTER (WHERE result_status IN ('DID_NOT_START', 'WITHDRAWN')) AS not_started_count,
        COUNT(*) FILTER (WHERE result_status IS NULL OR result_status NOT IN
            ('FINISHED', 'TIED', 'DISQUALIFIED', 'CRASHED', 'DID_NOT_FINISH', 'DID_NOT_START', 'WITHDRAWN')) AS unknown_count
    FROM observations
    GROUP BY GROUPING SETS ((year, entrant_count, bike_number),
        (year, entrant_count, frame_number), (year, entrant_count, racetrack_id, bike_number),
        (year, entrant_count, day_kind, bike_number), (year, entrant_count, race_class, bike_number),
        (year, entrant_count, meeting_grade, bike_number), (year, entrant_count))
)
SELECT *, first_count::numeric / NULLIF(eligible_entry_count, 0) AS first_rate,
    second_count::numeric / NULLIF(eligible_entry_count, 0) AS second_rate,
    third_count::numeric / NULLIF(eligible_entry_count, 0) AS third_rate,
    top2_count::numeric / NULLIF(eligible_entry_count, 0) AS top2_rate,
    top3_count::numeric / NULLIF(eligible_entry_count, 0) AS top3_rate,
    first_count::numeric / NULLIF(normal_finish_count, 0) AS normal_conditional_first_rate,
    second_count::numeric / NULLIF(normal_finish_count, 0) AS normal_conditional_second_rate,
    third_count::numeric / NULLIF(normal_finish_count, 0) AS normal_conditional_third_rate
FROM counts ORDER BY scope, year, entrant_count, bike_number, frame_number, racetrack_id, day_kind, race_class, meeting_grade;

-- query: frame-events
WITH per_frame AS (
    SELECT EXTRACT(YEAR FROM r.race_date)::int AS year, r.entrant_count, r.id, e.frame_number,
        COUNT(*) AS registered_riders,
        COUNT(*) FILTER (WHERE rr.result_status IN ('FINISHED', 'TIED', 'DISQUALIFIED', 'CRASHED', 'DID_NOT_FINISH')) AS started_riders,
        BOOL_OR(rr.result_status IN ('FINISHED', 'TIED') AND rr.rank = 1) AS first_event,
        BOOL_OR(rr.result_status IN ('FINISHED', 'TIED') AND rr.rank = 2) AS second_event,
        BOOL_OR(rr.result_status IN ('FINISHED', 'TIED') AND rr.rank = 3) AS third_event,
        BOOL_OR(rr.result_status IN ('FINISHED', 'TIED') AND rr.rank <= 2) AS top2_event,
        BOOL_OR(rr.result_status IN ('FINISHED', 'TIED') AND rr.rank <= 3) AS top3_event
    FROM races r JOIN race_entries e ON e.race_id = r.id
    LEFT JOIN race_results rr ON rr.race_id = r.id AND rr.bike_number = e.bike_number
    WHERE r.race_date >= DATE '2024-01-01' AND r.race_date < DATE '2026-01-01'
        AND (r.race_type LIKE 'Ａ級%' OR r.race_type LIKE 'Ｓ級%')
        AND r.entrant_count IN (7, 9) AND r.result_status IN ('CONFIRMED', 'CORRECTED')
    GROUP BY 1, 2, 3, 4
), counts AS (
    SELECT year, entrant_count, frame_number, COUNT(*) AS registered_frame_races,
        COUNT(*) FILTER (WHERE started_riders > 0) AS eligible_frame_races,
        COUNT(*) FILTER (WHERE first_event) AS first_events,
        COUNT(*) FILTER (WHERE second_event) AS second_events,
        COUNT(*) FILTER (WHERE third_event) AS third_events,
        COUNT(*) FILTER (WHERE top2_event) AS top2_events,
        COUNT(*) FILTER (WHERE top3_event) AS top3_events
    FROM per_frame GROUP BY 1, 2, 3
)
SELECT *, first_events::numeric / NULLIF(eligible_frame_races, 0) AS first_event_rate,
    second_events::numeric / NULLIF(eligible_frame_races, 0) AS second_event_rate,
    third_events::numeric / NULLIF(eligible_frame_races, 0) AS third_event_rate,
    top2_events::numeric / NULLIF(eligible_frame_races, 0) AS top2_event_rate,
    top3_events::numeric / NULLIF(eligible_frame_races, 0) AS top3_event_rate
FROM counts ORDER BY year, entrant_count, frame_number;

-- query: saved-stat39
SELECT EXTRACT(YEAR FROM r.race_date)::int AS year, s.feature_run_id, s.status,
    COUNT(*) AS results,
    COUNT(*) FILTER (WHERE s.player_id IS DISTINCT FROM e.player_id OR s.bike_number IS DISTINCT FROM e.bike_number) AS identity_mismatch,
    COUNT(*) FILTER (WHERE s.evidence->>'stat01_run_id' <> CASE WHEN r.race_date < DATE '2025-01-01' THEN '1' ELSE '27' END) AS lineage_mismatch,
    COUNT(*) FILTER (WHERE s.evidence->>'stat01_input_hash' IS DISTINCT FROM a.input_hash::text) AS stat01_hash_mismatch,
    COUNT(*) FILTER (WHERE s.input_as_of IS NULL OR s.input_as_of > r.scheduled_start_at) AS invalid_cutoff,
    MIN((s.features->'FIELD_BIKE'->>'sample_count')::bigint) AS min_history_samples,
    MAX((s.features->'FIELD_BIKE'->>'sample_count')::bigint) AS max_history_samples,
    AVG((s.features->'FIELD_BIKE'->>'win_rate')::numeric) AS mean_stored_history_win_rate
FROM races r JOIN race_entries e ON e.race_id = r.id
JOIN statistic_feature_results s ON s.race_id = r.id AND s.race_entry_id = e.id
    AND s.feature_run_id = CASE WHEN r.race_date < DATE '2025-01-01' THEN 21 ELSE 57 END
    AND s.stat_code = 'STAT-39' AND s.subject_type = 'RACE_ENTRY'
LEFT JOIN statistic_feature_results a ON a.race_id = r.id AND a.race_entry_id = e.id
    AND a.feature_run_id = CASE WHEN r.race_date < DATE '2025-01-01' THEN 1 ELSE 27 END
    AND a.stat_code = 'STAT-01' AND a.subject_type = 'RACE_ENTRY'
WHERE r.race_date >= DATE '2024-01-01' AND r.race_date < DATE '2026-01-01'
    AND (r.race_type LIKE 'Ａ級%' OR r.race_type LIKE 'Ｓ級%')
    AND r.entrant_count IN (7, 9) AND r.result_status IN ('CONFIRMED', 'CORRECTED')
GROUP BY 1, 2, 3 ORDER BY 1, 2, 3;

-- query: score-availability
SELECT EXTRACT(YEAR FROM r.race_date)::int AS year, a.feature_run_id, COUNT(*) AS joined_rows,
    COUNT(*) FILTER (WHERE a.race_entry_id IS DISTINCT FROM e.id OR a.bike_number IS DISTINCT FROM e.bike_number
        OR a.player_id IS DISTINCT FROM e.player_id) AS identity_mismatch,
    COUNT(*) FILTER (WHERE a.input_hash !~ '^[a-f0-9]{64}$') AS bad_input_hash,
    COUNT(*) FILTER (WHERE a.input_as_of IS NULL OR a.input_as_of > r.scheduled_start_at) AS invalid_cutoff,
    COUNT(*) FILTER (WHERE a.source_fetched_at < r.scheduled_start_at) AS timestamp_before_start,
    COUNT(*) FILTER (WHERE a.source_fetched_at >= r.scheduled_start_at) AS timestamp_at_or_after_start,
    COUNT(*) FILTER (WHERE a.source_fetched_at IS NULL OR r.scheduled_start_at IS NULL) AS unknown_timing,
    COUNT(*) FILTER (WHERE a.features->>'RACE_SCORE_AVAILABLE' = 'true') AS available_backfilled_score
FROM races r JOIN race_entries e ON e.race_id = r.id
JOIN statistic_feature_results a ON a.race_id = r.id AND a.race_entry_id = e.id
    AND a.feature_run_id = CASE WHEN r.race_date < DATE '2025-01-01' THEN 1 ELSE 27 END
    AND a.stat_code = 'STAT-01' AND a.subject_type = 'RACE_ENTRY'
WHERE r.race_date >= DATE '2024-01-01' AND r.race_date < DATE '2026-01-01'
    AND (r.race_type LIKE 'Ａ級%' OR r.race_type LIKE 'Ｓ級%')
    AND r.entrant_count IN (7, 9) AND r.result_status IN ('CONFIRMED', 'CORRECTED')
GROUP BY 1, 2 ORDER BY 1, 2;

ROLLBACK;
