# STAT-35-PLAYER-HISTORY-01

Version: `STAT35-PLAYER-HISTORY-v1`. Start main: `baac9c113d8a61061d7e5f8bd2b7cd391d091c54`.
PR #72 is merged and reviewed by the current user instruction. Previous relative-generation and memory-test records remain unchanged.

## Frozen Descriptive Contract

```text
analysis_mode = FINAL_RESULT_DESCRIPTIVE_ONLY
history_time_basis = EVENT_DATE_BACKFILLED_FINAL_RESULTS
historical_as_of_available = false
prediction_use = NOT_AUTHORIZED
points = null
```

This is an observed-result history, not a model input authorization, full attendance census, predictive evaluation or growth-effect claim.
Excluding the target meeting does not establish historical publication availability. C1, old experiments, and the 2026 holdout stay unchanged.
Structure, weather, line and opponent-strength corrections and STAT-35/37 overall remain incomplete.

## Input and Identity

Only the completed `stat35-race-relative-01/pr72-review-fix-20260923-185418-dbdce2/{input,result}` bundles are used.
The command pins races.jsonl (1,264,636,014 bytes, `eefd90026c2144841aa5a169882a8d98efe699ba0d83aae087985bbec96dc142`)
and details.jsonl (993,001,624 bytes, `67a52218421afc85c097a796d4e00eeb75f49f698a20e095cc0472980fbd3d7b`).
COMPLETE, manifest, every body seal, input-to-result manifest reference, disclosure, version, date range and code-identity shape are checked.
Source-generation identity is recorded separately from the current processing code. A merge does not invalidate the old code hash.
Fixtures supply their own expected seals through the same Source validator; the command has no synthetic bypass or pin override.
Input and result streams are joined in race-ID order, validating date, result/bike/import/observation/external/auxiliary identity and counts.
Duplicate race/result/bike/observation identities, missing rows, invalid exact fractions and out-of-range race dates fail publication.

Player identity is `keirin_jp + observed six-digit external_player_id`, retaining leading zeros. Auxiliary current/observed internal IDs remain audit fields.
Missing/invalid external IDs are retained as unresolved, not merged. Same-race duplicate external IDs are `IDENTITY_CONFLICT`, excluded from means.
Meeting period and venue come from input context; class/grade from sealed result classification. Unknown or conflicting periods/venues/classes remain explicit.
Series are source + player + fixed measurement definition + race class. No class fallback or grade-based opponent adjustment.

## Meeting and Window Arithmetic

Each meeting averages exact saved percentile numerator/denominator values of CALCULATED rows from COMPLETE comparisons with valid context/identity.
PARTIAL, abnormal, missing and incomparable rows remain in nonexclusive reason counts. No adopted rows gives null, not zero.
Meeting evidence includes race/result/import/observation/bike IDs. Meetings crossing the input boundary cannot provide complete-history values.
They remain null observed slots when their ordering/context is known. Invalid context groups remain in outputs but cannot establish a history series.

History uses calendar dates in Asia/Tokyo: `history.ends_on < target.starts_on`, excluding the target meeting entirely.
The latest 3/6/12 observed meetings are selected **before** dropping null values from numerical aggregation.
Within-meeting races have equal weights; across meetings each nonnull meeting has equal weight regardless of race count.
Mean, median and population variance are exact Brick Math rationals; variance requires two values. Display decimals use 12-place half-even.
No SQL AVG or floating-point percentile aggregation. Null consumes a slot; one valid value gives mean/median and null variance.
Insufficient observed/valid meetings, missing values, and left-truncation are independent flags. Short fixed-input histories are not evidence of novice status.
`input_left_truncated` means the bounded corpus cannot fill the requested observed slots, or a selected meeting starts before it; it does not assert actual older attendance.
Overlapping eligible history meetings, including overlap across the selected window boundary, block that window as AMBIGUOUS_MEETING_ORDER.
No arbitrary meeting-ID tie chooses a numerical window. Meetings ending on the target start date are not eligible.
Trend is recent-three mean minus previous-three mean, requiring six distinct ordered slots and all six nonnull values.
Positive/negative denotes observed rise/fall only, not ability change or predictive improvement. Windows are fixed candidates, not optimized choices.

## Offline Execution and Publication

```bash
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= \
APP_CONFIG_CACHE="$run_dir/absent-config.php" \
php -d memory_limit=512M artisan keirin:stat35:player-history:build \
  --source-input-dir="$source_root/input" --source-result-dir="$source_root/result" \
  --output-dir="$run_dir/result"
```

The thin command invokes a DB/HTTP-independent builder. A new private output directory holds a dedicated PDO SQLite workspace.
Indexes support player/meeting aggregation and at most 13 historical candidates per target group; each group history is reused by its result rows.
Global input lists are never loaded into PHP. One pathological player meeting above 4,096 evidence rows fails explicitly rather than growing without bound.
All current result rows are emitted, and zero-result races remain in summary. Unknown identity/quality does not silently reduce the universe.
Entry output separates target_result_audit from history; target own/future outcomes cannot alter its history values.
All six artifacts are deterministic: player-meetings.jsonl, entry-history.jsonl, summary.json, summary.csv, manifest.json, COMPLETE.json.
SQLite, runtime timestamps, PIDs, paths and elapsed time are excluded from semantic comparisons. Source/code are checked again before publication.
Generation-time seals are reverified before COMPLETE. Failure keeps partial evidence without COMPLETE; existing directories are never overwritten.
Summary contains year/meeting-grade and race-class totals, each fixed window's availability, identity, context/exclusion and trend-null reasons.
`identified_players` counts distinct syntactically identified external IDs, not a guarantee that every observation is conflict-free.
Groups without identifiable players/meetings remain audit groups; `identified_player_meetings` distinguishes the known-player subset.

## Validation and Execution Record

Status: `DESCRIPTIVE_PLAYER_HISTORY_GENERATED_REPRODUCED_AWAITING_REVIEW`.
Evidence root for this task: `/home/shinya/neo-keirin-artifacts/stat35-player-history-01/run-20260927-072445-4FJxV4/`.
Initial 28 focused cases passed (254 assertions). An initial large-memory run detected a code edit during execution and refused publication;
its code-drift failure log is retained, not reported as a memory failure or a successful generation.
After the boundary-code change, final related verification passed: 86 tests / 881 parent assertions, exit 0.
The 32 new cases cover exact arithmetic/rounding/variance, identity/auxiliary changes, equal meeting weights,
null slots, 3/6/12 windows, trend groups, reversed IDs, target/future outcome isolation, overlap/same-day exclusions,
boundary metadata, unknown contexts, source corruption/pins, zero-result/empty inventories, no DB/HTTP and six-file reproduction.

All 13 changed PHP files passed syntax checks and scoped Pint. Normal `php artisan test` ran once on final PHP code:
2,153 tests, **2,144 passed / 9 existing PostgreSQL-only skips / 0 failures / 0 errors**, **17,677 parent assertions**, exit 0.
Elapsed 152.317929 seconds (Artisan 152.17s). No shared TestCase, PHPUnit configuration, vendor, threshold or fixture reduction.
The independent memory registry is now 17 cases (existing 16 preserved). The new case generates 12,000 races / 108,000 result rows,
with input exceeding 100MiB, and executes the real builder under actual 128M: peak **48,758,784 bytes**, 107,478 child assertions.
Full-run direct child PID 29972 and high-parent child PID 29799 both passed with that peak. The high parent uses 512M and exceeds 128MiB.
All 43 helper regressions passed. Direct 17 children total 107,929 assertions; high-parent 17 children separately total 107,929.
Neither is added to parent assertion totals. Existing skips cover PostgreSQL-only transaction/COPY/constraint/lock/READ ONLY enforcement.

### Fixed-Input Execution

The persisted wrapper explicitly defines the agreed run/source roots, uses umask 077 and a 1,800-second timeout per PHP process.
It supplies testing/SQLite in-memory configuration, blank DB URL/credentials and absent config-cache path. No production DB connection.
The build command above ran once with output `result`, then once with output `reproduced`; calculation code/input were unchanged between them.

| Stage (2026-09-27 JST) | Start / End | Seconds | Exit | Peak bytes | stderr bytes |
|---|---|---:|---:|---:|---:|
| Generate | 07:31:19 / 07:34:14 | 175.620946 | 0 | 33,554,432 | 0 |
| Reproduce | 07:34:14 / 07:37:09 | 174.343540 | 0 | 33,554,432 | 0 |
| Independent file/count/date verification | 07:37:18 / 07:38:09 | 50.513929 | 0 | Not measured | 0 |

Logs contain UTC timestamps; the table converts them to JST. No timeout, real-input retry or fallback.
Source body identities matched the pinned values. START/END source and processing code seals matched in both executions.
Six files match in both bytes and SHA-256; workspace.sqlite is not a semantic artifact.

| Artifact | Bytes | SHA-256 |
|---|---:|---|
| player-meetings.jsonl | 371,640,449 | `521b9218e4ddcedd10cf7c8b34e212ccb5f9ddfcf88dbc5af17e1d7fc4aea7e7` |
| entry-history.jsonl | 6,914,745,054 | `8c90a85a63935d0bf972e972ef0a5aa725bf4ba1ebd5d64455b495ed437075e3` |
| summary.json | 35,519 | `8349c18b96919f233fe79bd3c0ab420f79169cf672740a1edc67591b9bfb892e` |
| summary.csv | 9,354 | `6290f05ff747c46fa7e55731d5679a38a801d8887759ad0b89b3b96b8a98e619` |
| manifest.json | 12,049 | `da31b2988a3a6ffcd6c839169a85a0d5a6a1ec929006757181fb8e748c9311fa` |
| COMPLETE.json | 92 | `793b0a45542aa3722b9e049c7927bb9a1ac005472ecf800cfe23001ba8125e1f` |

### Actual Availability

Input: **101,326 races / 716,837 current result rows**, including 126 zero-result races in summary.
All result rows are retained. Identified external players **2,436**; unidentified/duplicate external-identity rows **0/0**.
Observed meeting IDs **3,491**; player x meeting x class groups **241,464**, of which **235,725** have numerical means.
Context-eligible groups **240,704**; all 241,464 groups have identified players. Meeting totals are assigned to first observed race year.

Counts below are target result rows, not unique historical meetings or independent predictive samples:

| Window | All observed slots present | All slots numerical | No numerical history | Missing values within selected slots | Left-truncation flag | Blocked |
|---|---:|---:|---:|---:|---:|---:|
| 3 | 676,008 | 640,112 | 18,586 | 37,190 | 41,163 | 6,367 |
| 6 | 638,283 | 573,435 | 22,112 | 69,698 | 78,904 | 9,921 |
| 12 | 565,139 | 457,715 | 28,174 | 123,760 | 152,022 | 15,990 |

Trend calculated **573,435** rows; null **143,402**: insufficient observed meetings 68,633, missing meeting values 64,848,
blocked context/order 9,921. Same target meeting/player/class reuses its history; repeated entry counts are not distinct histories.
Class UNKNOWN is 322 races / 2,215 rows / 760 groups, never reassigned to a nearby class.
Ambiguous ordering blocks 4,152 / 7,706 / 13,775 rows for 3/6/12 respectively; the other 2,215 in each blocked count are UNKNOWN class.
Input-start/end boundary flags affect 191 / 676 rows. Meeting date/venue conflicts, unknown identity and duplicate external IDs were not observed.
Nonexclusive source exclusions: PARTIAL 39,250 rows, abnormal result 9,994, stored AGARI_MISSING 15,952,
AGARI_OBSERVED_ABNORMAL_RESULT 3, relative missing 5,961 and insufficient comparison 13.
`missing_rows` in player-meetings is the broad non-VALID agari counter (including those 3 observed-abnormal rows);
use the explicit AGARI_MISSING versus AGARI_OBSERVED_ABNORMAL_RESULT reasons to distinguish genuine stored missingness from abnormal numeric observations.
Do not sum overlapping reason/flag counts as unique rows. Class/grade and all window totals were independently reconciled from generated records.

The separate verifier streamed both outputs and sealed source details, checked all six byte comparisons,
recomputed race/entry/player/group/window/year/grade/class inventories, and checked every selected history end strictly precedes target start.
Results are in `verification.json`; commands/stdout/stderr/timing in the stage directories. No expected availability count was hard-coded.

This completes descriptive generation/reproduction only, not STAT selection, optimized windows, growth causality, historical as-of availability or prediction improvement.
No production DB/write, real export/old relative rebuild, Raw/HTTP, migration/backfill, training/evaluation or 2026 race access occurred.
Next is review only. Changes remain uncommitted; no add/commit/push/PR/merge or automatic next stage.
