# BT-01 STAT-01 Baseline

## Purpose

BT-01 is a non-learning baseline that evaluates the fixed 2022-2025 STAT-01 snapshots. It establishes feature/label separation, walk-forward metadata, tie-aware prediction sets, auditable exclusions, and baseline metrics. It does not assign points, tune thresholds, estimate probabilities, or simulate bets.

## Fixed Source Manifest

Manifest version: `BT01-STAT01-MANIFEST-v1`

| Year | Feature run | UUID | Target | Races | Results |
|---|---:|---|---|---:|---:|
| 2022 | 25 | `82a88496-35b4-48fc-81c3-8b46b5eb626f` | 2022-01-01 to 2022-12-31 | 24,868 | 174,152 |
| 2023 | 26 | `71c344f6-e09b-4496-9cd0-a68642e2c462` | 2023-01-01 to 2023-12-31 | 25,561 | 181,548 |
| 2024 | 1 | `07f2fc31-0d9c-41d9-95b7-80c7afb396ce` | 2024-01-01 to 2024-12-31 | 25,624 | 182,004 |
| 2025 | 27 | `b62ba626-5019-4018-8cd7-7d09c61a8ceb` | 2025-01-01 to 2025-12-31 | 25,273 | 180,005 |

Every ID, UUID, date range, stat code, calculation version, target count, error count, status, physical result count, and distinct result race count is checked before a backtest run is created. Every result row must also contain `STAT-01`, `STAT-01-existing-db-v1`, and `RACE_ENTRY`. `verified_race_count` is the measured `COUNT(DISTINCT race_id)`, not copied run metadata. No latest-run lookup or automatic source replacement is allowed. The manifest hash is SHA-256 over canonical, year-sorted manifest content.

## Folds And Holdout

| Fold | Training metadata | Evaluation |
|---|---|---|
| `DEV_2022` | none | 2022-01-01 to 2022-12-31 |
| `WF_2023` | 2022 | 2023-01-01 to 2023-12-31 |
| `WF_2024` | 2022-2023 | 2024-01-01 to 2024-12-31 |
| `WF_2025` | 2022-2024 | 2025-01-01 to 2025-12-31 |

The baseline learns no parameters from training data. The training dates are forward-looking metadata for later backtests. `BLOCK_AFTER_2025-12-31` rejects a later evaluation date before any label query. The command exposes no date range or holdout-release option.

## Feature And Label Separation

- `BacktestContextRepository` reads only `races`.
- `BacktestFeatureRepository` reads only `statistic_feature_runs` and `statistic_feature_results`.
- `BacktestLabelRepository` reads only `race_results`.
- Prediction generation never reads current `race_entries`, `players`, payouts, or scraping tables.
- For each fold, all Feature queries and all prediction generation finish before the first Label query starts.
- Stored and dry-run processing write compact race context and predictions to an OS temporary spool. Label evaluation reads that spool in bounded chunks and never re-queries STAT features.
- The spool is closed and removed on both success and failure. It is never written under scraping raw storage.
- Backtest writes are restricted to `backtest_*` tables.

The feature repository projects only `RACE_SCORE_RAW`, `RACE_SCORE_AVAILABLE`, and `RACE_SCORE_RANK`; it does not copy the complete feature or evidence JSON.

## Feature Eligibility

A race is predicted only when its feature count equals `entrant_count`, entry and bike IDs are unique, every result is `VALID/FULL`, the score is available and positive, rank is present, and `input_as_of` exists no later than scheduled start. Any failure excludes the entire race rather than evaluating an available subset.

Feature reasons include `FEATURE_RESULT_COUNT_MISMATCH`, duplicate entry/bike, invalid status/quality, unavailable or non-positive score, missing rank/input time, and post-start input.

## Prediction And Ties

Rule version: `STAT01-RACE-SCORE-RANK-v1`

`prediction_score` is the stored `RACE_SCORE_RAW`; `predicted_rank` is the stored `RACE_SCORE_RANK`. Rank 1 entries form `RANK1_SET`, while rank 3 or better entries form `TOP3_SET`. Equal scores retain equal ranks, including a tie at the top-3 boundary. `race_entry_id` is used only for deterministic storage order.

Prediction hashes include the calculation and rule versions, source feature IDs and input hash, race and entry IDs, bike number, score, stored rank, and set flags. They never include results or other label information. Fold prediction manifests stream hashes in explicit race-ID and race-entry-ID order, so chunk size does not affect the result.

Stored predictions receive `locked_at`. Once locked, model-level protection rejects every Eloquent update, including clearing `locked_at`, changing run/fold ownership, source references, scores, ranks, flags, or hashes. Label evaluation creates metrics and exclusions without modifying predictions.

## Failure Lifecycle

All work after `backtest_runs` creation, including source audit storage, is inside run failure handling. An exception closes the run as `FAILED` with an error and `finished_at`. A started fold is also closed as `FAILED` with the target, predicted, and excluded counts reached at failure. Race prediction inserts use short backtest-only transactions so persisted prediction race counts remain consistent. No started run or fold is intentionally left `RUNNING`.

## Label Cohorts

`OPERATIONAL` requires a confirmed race, a complete result count, at least one finished or tied rank-1 winner, and no finished/tied row with missing rank. Disqualification, non-start, non-finish, withdrawal, and crash rows remain in this cohort.

`NORMAL_FINISH` adds the requirement that every result is `FINISHED` or `TIED` with a rank. Tied finish ranks and multiple winners are preserved as sets.

Label reasons include race not confirmed, count mismatch, no winner, missing finished rank, and abnormal results in the normal-finish cohort. Exclusions are stored explicitly rather than silently dropped.

## Metrics

Each fold and cohort stores:

- `FEATURE_COVERAGE_RATE`
- `RANK1_SET_WIN_HIT_RATE`
- `TOP3_SET_WIN_HIT_RATE`
- `RANK1_SET_SIZE_MEAN`
- `TOP3_SET_SIZE_MEAN`

Each metric records numerator, denominator, sample count, and value. A hit means the predicted set intersects the complete actual winner set.

## Schema And Non-Interference

The six new tables are `backtest_runs`, `backtest_folds`, `backtest_feature_sources`, `backtest_predictions`, `backtest_metrics`, and `backtest_exclusions`. Physical foreign keys exist only between these tables. IDs from races, entries, players, result rows, and feature rows are logical audit references so upstream lifecycle changes do not cascade into backtest history.

Processing uses race-ID keyset pagination, bounded query chunks, and an OS-managed temporary spool. It does not lock source/statistics tables and does not use a transaction spanning a fold or run.

## Command

```bash
php artisan keirin:backtest:bt01-baseline
php artisan keirin:backtest:bt01-baseline --dry-run
```

`--dry-run` validates and calculates without writing `backtest_*`. BT-01B validation uses fixture databases only; no real stored backtest is run in this phase.

## Not Implemented

Points, thresholds, decay, probability estimates, Brier score, log loss, AUC, calibration, ROI, betting simulation, bet tables, scoring configuration, and 2026 final evaluation are intentionally absent. Final-holdout release requires a later reviewed phase after the strategy is frozen.
