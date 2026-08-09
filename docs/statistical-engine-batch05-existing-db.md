# Statistical Engine Batch05 Existing-DB MVP

## Purpose and scope

Batch05 implements the existing-database portion of STAT-41. The formal STAT-41 goal is to evaluate race competitiveness, prediction uncertainty, and upset structure. This version only fixes reproducible pre-race raw structure from an accepted STAT-01 snapshot.

The result grain is one `RACE` result per race. A seven-entry or nine-entry race therefore creates one `statistic_feature_results` row with `subject_key=race:{race_id}`. `target_entry_count` remains the audited count of source STAT-01 entry rows; result count and processed count are race counts.

## Input and time policy

The only calculation input is the selected accepted `STAT-01-existing-db-v1` run. Current `race_entries.race_score`, results, payouts, odds, and popularity are not read. `races.race_date` is used only to select the requested date range.

Every selected STAT-01 result must have `input_as_of`, and every result in a race must share exactly one value. This is checked before a Batch05 run is created and again while constructing the race DTO. There is no current-time or scheduled-start fallback. The shared value becomes the race prediction `input_as_of`; the maximum source `source_fetched_at` is provenance only.

## Raw race structure

A score is usable only when `RACE_SCORE_AVAILABLE === true` and `RACE_SCORE_RAW` is numeric and positive. Missing and invalid values are never replaced with zero. The calculation records expected/actual/usable/missing/invalid counts, coverage, distinct count, mean, range, population variance and standard deviation, median, linear-interpolated quartiles, IQR, MAD, and population coefficient of variation.

Usable scores are sorted by score descending and `race_entry_id` ascending. The ID is only a deterministic tie ordering, not a sporting tie breaker. Top one through four values, raw rank-boundary gaps, tie count, and every unordered pair's absolute gap summary are retained. Partial distributions are explicitly marked `VALID_SCORE_SUBSET`.

The following remain `null` because formulas, probabilities, thresholds, or dependencies have not been accepted:

- `RACE_COMPETITIVENESS_SCORE`
- `RACE_PREDICTION_UNCERTAINTY_SCORE`
- `RACE_UPSET_STRUCTURE_SCORE`
- prediction probability entropy and concentration
- candidate count and selection policy
- STAT-40 line strength
- scenario, prediction-interval, and rank-reversal components
- confidence-adjusted output

STAT-24 player volatility and STAT-20 confidence are available work products but are deliberately deferred from this STAT-01-only version. No candidate, dominance, orderly-race, or upset classification threshold is inferred.

## Status and quality

Invalid or inconsistent expected entrant counts produce `INVALID_INPUT`. No usable scores produce `MISSING_INPUT` when all values are missing, or `INVALID_INPUT` when invalid values are present. One usable score produces `PARTIAL`. Two or more scores with incomplete coverage or an entry-count mismatch also produce `PARTIAL`. Only complete scores with matching expected and actual counts produce `VALID/FULL`. An unresolved `player_id` is audited but does not invalidate score structure.

## Hash and persistence

The target context hashes STAT/version, source STAT-01 run/version, race ID, shared input time, expected and actual counts, and participant rows sorted by `race_entry_id`. Participant audit data includes IDs, bike number, source status/quality, score and availability, source ranks, source input hashes, race input hash, and source fetch time. The final input hash deterministically hashes STAT code, calculation version, and the target context hash.

The service uses `input_as_of,race_id` keyset pages and five-race internal working batches. Source entry rows are fetched for the working race IDs in one query. Each race has an isolated calculation/save transaction and run item. Dry runs create no statistic rows. All point and confidence columns remain `null`; no migration is required.

## Validation and 2024 acceptance

Run `docs/sql/validate_batch05_2024.sql` with `psql`, optionally passing `-v batch_execution_uuid='<uuid>'`. It selects the latest complete 2024 run when no UUID is supplied. Continuous values are reported as compact count/min/percentile/max/mean summaries, never grouped by exact continuous values.

Acceptance order:

1. Run the test suite, Pint, PHP syntax checks, and `git diff --check`.
2. Dry-run one complete and one partial real race with a 128 MB PHP memory limit.
3. Dry-run 2024-01-01 and 2024-12-31 separately and verify statistic/source table counts are unchanged.
4. After review, run the full-year stored build outside this implementation task and inspect it with the validation SQL.
