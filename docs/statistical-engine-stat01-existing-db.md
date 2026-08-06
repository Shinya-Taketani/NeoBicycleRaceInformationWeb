# STAT-01 existing database foundation

## Scope

This implementation builds the first statistical feature foundation from data already stored in `races` and `race_entries`. It adds only STAT-01 race-score features and the shared quality evidence intended for future STAT-20 use.

It does not change scraping code, scraping tables, parsers, fetchers, raw response storage, or existing source records. It also does not implement scoring weights, confidence, predictions, backtesting, odds, other STAT codes, a scheduler, API, or UI.

The initial operating convention is:

- history period: 2023
- first calculation and validation target: 2024
- category: men's races whose `race_type` begins with `Ａ級` or `Ｓ級`
- field size: 5 through 9 entrants
- result status: not used as an input filter
- acquisition mode: `BACKFILL`

## Storage

The forward migration creates only:

- `statistic_feature_runs`
- `statistic_feature_run_items`
- `statistic_feature_results`

Every invocation creates a new run. A run item isolates each race, and results are stored once per race entry within that run. One failed race does not roll back another race.

The legacy tables `statistic_calculation_runs`, `statistic_entry_results`, and `statistic_run_entry_results` remain read-only reference data. The application does not require them and never writes to them. Comparison is available only through `docs/sql/compare_legacy_stat01.sql`.

## STAT-01

Only `race_score > 0` belongs to the valid score population. `NULL` is `MISSING_INPUT`; zero and negative values are `INVALID_INPUT` with `RACE_SCORE_NON_POSITIVE_UNRESOLVED`. Invalid and missing values are not replaced and do not participate in rank, mean, maximum, standard deviation, or z-score calculations.

The feature JSON contains:

- raw score and availability
- descending competition rank and dense rank
- strength percentile, with the highest score at `1.0`
- race mean and maximum
- score minus race mean
- race maximum minus score
- population standard deviation using divisor N
- z-score, or `NULL` with `ZERO_VARIANCE` evidence
- valid, missing, invalid, and expected entrant counts

Calculations retain full intermediate precision. `raw_points`, `confidence`, and `effective_points` remain `NULL` because their policies are not defined in this stage.

## Quality Evidence

Each result records the shared quality values needed by future STAT-20 work:

- actual and expected entrant counts and whether they match
- valid, missing, and invalid score counts
- valid score coverage divided by expected entrant count
- whether `player_id` is resolved
- `input_as_of` source
- acquisition mode
- source fetch timestamp and whether it is after scheduled start
- calculation version and explicit quality reasons

`input_as_of` uses `sales_close_at`, then `scheduled_start_at`, then `NULL`. Missing both timestamps degrades quality. A source fetched after race start remains visible as evidence but is not blocked or labelled as leakage in `BACKFILL` mode.

The SHA-256 `input_hash` uses canonical JSON over the documented race, entry, timing, version, and mode inputs. No raw file or fetch-log reconstruction is performed.

## Commands

Apply the new migration only when ready:

```bash
php artisan migrate
```

Small read-and-calculate validation without statistic writes:

```bash
php artisan keirin:statistics:build-stat01 \
  --from=2024-01-01 \
  --to=2024-01-01 \
  --dry-run
```

Full 2024 build, to be run by the operator after migration:

```bash
php artisan keirin:statistics:build-stat01 \
  --from=2024-01-01 \
  --to=2024-12-31 \
  --chunk=200
```

The command also accepts a single `--race-id`. A race ID cannot be combined with `--from` or `--to`; an unbounded invocation is rejected.

After the full build, run the read-only checks in `docs/sql/validate_stat01_2024.sql`. The known source baseline is 25,624 races, 182,004 entries, 181,450 positive scores, 554 zero scores, and no null scores. Then use `docs/sql/compare_legacy_stat01.sql` for the optional legacy comparison.

## Known Constraints

- Existing timestamps reflect later backfill acquisition and do not prove pre-race observation.
- This stage stores evidence but defines no points, confidence, or quality threshold.
- The 2024 full write and development-database migration are intentionally left to the operator.
- Future backtests must independently restrict outcomes to their approved confirmed/corrected statuses.
