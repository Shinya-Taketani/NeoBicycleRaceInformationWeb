-- Synthetic schema and records only. Never apply to the application DB.
CREATE TABLE statistic_feature_runs (
    id bigint PRIMARY KEY, stat_code varchar(40), calculation_version varchar(100),
    mode varchar(40), status varchar(40), finished_at timestamptz,
    target_race_count integer, processed_race_count integer, target_entry_count integer,
    success_count integer DEFAULT 0, partial_count integer DEFAULT 0,
    missing_count integer DEFAULT 0, invalid_count integer DEFAULT 0, error_count integer DEFAULT 0,
    target_race_id bigint, target_from date, target_to date, parameters jsonb
);
CREATE TABLE races (id bigint PRIMARY KEY, race_date date);
CREATE TABLE race_entries (id bigint PRIMARY KEY, race_id bigint, player_id bigint, bike_number integer);
CREATE TABLE statistic_feature_results (
    id bigint PRIMARY KEY, feature_run_id bigint, stat_code varchar(40), calculation_version varchar(100),
    subject_type varchar(40), subject_key varchar(255), race_id bigint, race_entry_id bigint,
    player_id bigint, opponent_player_id bigint, bike_number integer,
    status varchar(40), quality_status varchar(40), acquisition_mode varchar(40),
    input_as_of timestamptz, input_hash varchar(64),
    raw_points numeric, confidence numeric, effective_points numeric,
    features jsonb, evidence jsonb
);
CREATE TABLE statistic_feature_run_items (
    id bigint PRIMARY KEY, feature_run_id bigint, race_id bigint, status varchar(40),
    feature_result_count integer, finished_at timestamptz
);

INSERT INTO statistic_feature_runs
    (id, stat_code, calculation_version, mode, status, finished_at,
     target_race_count, processed_race_count, target_entry_count, target_race_id, parameters)
VALUES
    (1, 'STAT-01', 'STAT-01-existing-db-v1', 'BACKFILL', 'SUCCEEDED', '2024-01-02 13:00:00+09',
     1, 1, 7, 10, '{}'),
    (2, 'STAT-20', 'STAT-20-existing-db-v1', 'BACKFILL', 'PARTIALLY_SUCCEEDED', '2024-01-02 13:10:00+09',
     1, 1, 7, 10, '{"stat01_run_id":1,"source_run_ids":{"STAT-01":1}}');
INSERT INTO races VALUES (10, '2024-01-02'), (20, '2024-01-03');
INSERT INTO race_entries
SELECT bike, 10, 100 + bike, bike FROM generate_series(1, 7) AS bike;
INSERT INTO statistic_feature_results
    (id, feature_run_id, stat_code, calculation_version, subject_type, subject_key,
     race_id, race_entry_id, player_id, bike_number, status, quality_status, acquisition_mode,
     input_as_of, input_hash, features, evidence)
SELECT bike, 1, 'STAT-01', 'STAT-01-existing-db-v1', 'RACE_ENTRY', 'race_entry:' || bike,
       10, bike, 100 + bike, bike, 'VALID', 'FULL', 'BACKFILL',
       '2024-01-02 11:55:00+09'::timestamptz, repeat('a', 64), '{}', '{}'
FROM generate_series(1, 7) AS bike;
INSERT INTO statistic_feature_results
    (id, feature_run_id, stat_code, calculation_version, subject_type, subject_key,
     race_id, race_entry_id, player_id, bike_number, status, quality_status, acquisition_mode,
     input_as_of, input_hash, features, evidence)
SELECT 100 + bike, 2, 'STAT-20', 'STAT-20-existing-db-v1', 'RACE_ENTRY', 'race_entry:' || bike,
       10, bike, 100 + bike, bike, 'PARTIAL', 'DEGRADED', 'BACKFILL',
       '2024-01-02 11:55:00+09'::timestamptz, repeat('b', 64),
       '{"CONFIDENCE":null,"STAT_QUALITY":{"STAT-10":{"status":"NO_HISTORY"},"STAT-42":{"status":"DATA_SOURCE_PENDING","original_fetch_log_raw_linkage":"DATA_SOURCE_PENDING","official_publication_as_of":"NOT_VERIFIED"}}}',
       '{"stat01_run_id":1,"stat01_input_hash":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","historical_as_of_available":false,"prediction_use":"NOT_AUTHORIZED","blocked_reasons":[]}'
FROM generate_series(1, 7) AS bike;
INSERT INTO statistic_feature_run_items VALUES (1, 2, 10, 'PARTIAL', 7, '2024-01-02 13:10:00+09');
