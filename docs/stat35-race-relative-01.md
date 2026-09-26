# STAT-35-RACE-RELATIVE-01

## Scope and Frozen Calculation Contract

Version: `STAT35-RACE-RELATIVE-v1`. Start main: `7f2a59d15af13785d36471f9f86c1dfa03f1222b` (PR #71 merged, TrackContext v2 reviewed).

This is a result-final descriptive dataset, not a prediction experiment:

```text
analysis_mode = FINAL_RESULT_DESCRIPTIVE_ONLY
historical_as_of_available = false
prediction_use = NOT_AUTHORIZED
points = null
```

These formulas and eligibility rules are fixed before reading real inputs. There is no learning, Gate, prediction accuracy, growth score or cross-race ranking. SCR-STAT-35-02 and STAT-35/37 as a whole remain incomplete. Publication timing remains unverified, including for prior races. C1 and the 2026 holdout are unchanged.

## Input and Provenance

The exporter reads source `keirin_jp`, dates 2022-01-01 through 2025-12-31 only, in SQL. It checks the actual PostgreSQL endpoint and READ ONLY settings before business reads, then uses one REPEATABLE READ / READ ONLY transaction. One keyset chunk contains complete races; current results and their own import/bike observations are joined, never all historical observations or the latest import. No Raw, payout, odds or player profile is read. No production audit rows are written.

PR #72 validates the endpoint field by field using `host(inet_server_addr())`. Port must first be an integer or digits-only string; normalized port 5432 is accepted without depending on PDO column order or integer/string representation. Missing or incorrect endpoint/read-only fields fail before business queries and output creation. Diagnostics contain only the failed field, expected/actual value and actual type; credentials, connection URLs and PDO configuration are not dumped. Effective isolation and transaction read-only are checked after SET TRANSACTION and before business queries on the same connection. Both commands report peak memory on failure as well as success.

Race eligibility requires men's category, CONFIRMED/CORRECTED race and successful final RESULTS_AVAILABLE import, one common import version, matching import race, unique result IDs/bikes 1-9, current count equal to import.result_count, matching observation race/import/bike/result state/agari fields, and matching source/converted hashes. Null and decimal equality are distinguished. Missing, cancelled, unsupported and inconsistent races remain in the inventory with exclusion reasons. Reasons are nonexclusive; do not sum them to obtain race counts.

The mapping to `keirin-jp-final-back-half-lap-v1` requires the sealed v2 CONFIRMED HALF_LAP definition (glossary/QA evidence), `keirin_jp` race source, matching official keirin.jp source URLs, observation `AGARI-STORAGE-v1`, semantic `AGARI_TIME`, normalizer `AGARI-TIME-v1`, and the recorded nonempty source Parser version matching its import. The source Parser version names the original result import; the storage/normalizer versions establish agari extraction semantics. Unknown mappings block relative calculations, not only speed. A historical layout/distance gap does not invalidate the independently confirmed common half-lap meaning.

Current result/entry/player IDs and observation IDs/external registration number are retained separately. Observation auxiliary IDs need not equal current IDs. The comparison unit is race+bike, not a fabricated player ID. `link_status` distinguishes missing external identity from an observed registration number whose historical availability timing is still unverified.

Each current result's own `race_id` is a mandatory positive integer in the input schema. Missing, null, string, boolean and nonpositive IDs reject publication. A correctly typed ID unequal to the parent race excludes the entire race as `CURRENT_RESULT_RACE_ID_MISMATCH`, even when import and observation IDs are correct. All its relative values are null and speeds are unavailable; no row is dropped or repaired.

## Exact Arithmetic

Comparison set: FINISHED/TIED + VALID + positive decimal seconds, within an eligible race. Let n be its size, f the strictly faster count, e the equal-time count including self:

```text
rank_min = 1 + f
rank_average = 1 + f + (e - 1) / 2
percentile = 1 - (rank_average - 1) / (n - 1)
gap_to_fastest_seconds = time - min(time)
```

Brick Math exact decimals are used for comparisons, average ranks and differences. `12.0` equals `12.00`; there is no epsilon or pre-ranking rounding. Percentile retains integer numerator `2(n-1)-2f-(e-1)` and denominator `2(n-1)`. Terminating decimal output is exact; recurring decimals alone use 12 places, half-even. Original decimal text remains available. Nonpositive, NaN/INF and nondecimal numeric storage values fail rather than become missing.

For n<2 all relative values are null (`INSUFFICIENT_COMPARISON`). All equal times with n>=2 yield percentile 0.5 and gap 0. Official TIED finishing status does not itself create equal agari ranks.

`normal_finisher_count`, `valid_timing_count`, `missing_or_invalid_normal_finisher_count`, `abnormal_result_count` describe the comparison set. Missing/invalid timing for any normal finisher makes it PARTIAL; abnormal results are separately excluded. Scope is `OBSERVED_VALID_NORMAL_FINISHERS`. Partial ranks are not ranks among all starters. Aggregates distinguish complete, partial and unusable races, valid timing rows, eligible comparison rows, relative output rows and speed output rows.

## Speed and Meeting Classification

Only explicit TrackContext v2 and the unchanged AgariSpeedCalculator are used. Unknown structure/distance gives null speed and the existing reason, without a 200m fallback or annual-period inference. Speed is unadjusted distance/time; weather, line, tactics and opponent effects are not corrected. Relative status and speed eligibility are independent. Prior v2 coverage (14 resolved and 10,646 unknown track-days) is a historical record, not a hard-coded expectation for this new universe.

Main aggregation: year x meeting grade GP/G1/G2/G3/F1/F2/UNKNOWN. Side axes: track, saved race class, completeness, distance resolution. Existing TacticalMeetingGradeAnalysis Classification normalization/conflict/fallback rules are reused, not its 2024/2025-only validator. Export includes every distinct target-period race-header grade for the meeting in the same snapshot, including across chunks; mixed headers remain UNKNOWN, never a majority vote. Missing meeting or inconsistent meeting relation remains UNKNOWN. No rider grade substitute is used.

## Artifacts and Commands

`keirin:stat35:race-relative:export --from=2022-01-01 --to=2025-12-31 --chunk=200 --output-dir=.../input`

`keirin:stat35:race-relative:build --input-dir=.../input --master-version=v2 --output-dir=.../result`

New output directories only (0700). Incomplete files remain distinguishable; COMPLETE.json is written only after generation-time hashes are verified. JSONL has bounded lines and ascending unique race IDs. Schema, dates, count, hash and manifest are checked, and input/master/direct code fingerprints are rechecked before publication. Build has no DB/HTTP dependency. Details, summary JSON/CSV and manifests have no runtime timestamps; execution timing belongs in separate logs. Reproduce uses the same input and a distinct output directory, without another export.

Evidence root: `/home/shinya/neo-keirin-artifacts/stat35-race-relative-01/`. Tests use synthetic database-shaped fixtures in `tests/Support/AgariRaceRelativeFixture.php`, not real Raw or rider records.

Empty input retains all ten integer-zero total counters, an empty groups array and the unchanged CSV header only. No synthetic classification group is added. Normal and empty summaries share the counter definition.

## Initial Execution Record (Preserved)

Status: `IMPLEMENTED_TESTED_EXPORT_PREFLIGHT_FAILED_AWAITING_REVIEW`.

Evidence: `/home/shinya/neo-keirin-artifacts/stat35-race-relative-01/run-20260923-083705-9f02c3d4/`.

Synthetic tests: initial focused/related run found an incorrect new Brick Math method name. It was corrected to the installed `strippedOfTrailingZeros()` API. The failing log is retained. The affected new tests then passed: 42 tests / 441 assertions. Final isolated 128M full suite: 2,018 passed, 9 existing skipped, 16,543 assertions (34.28 seconds). No new skip. Limited Pint passed and all 11 changed PHP files passed syntax checks. Tests include exact decimals/ties, 7/9 entries, n=0/1, partial/abnormal cases, version/provenance failure, auxiliary identity, complete-race chunking, old-import exclusion, missing races, meeting conflicts, 2022/2023, corrupt/duplicate/2026 rejection and byte-exact offline generation without DB/HTTP.

One production export was attempted at 2026-09-23 17:41:43 JST, ending 17:41:44 JST (0.251 seconds), with the prescribed command and process-local PGOPTIONS. It exited 1:

```text
Unexpected endpoint or READ ONLY was not enabled before connecting.
```

The guard failed before the business-data transaction, race query and input-directory creation. The combined error does not identify which endpoint/setting differed; actual returned settings were not logged, so that cause remains **unconfirmed**, not inferred. No second connection or retry was made. This is not an assertion that production READ ONLY verification succeeded.

| Item | Result |
|---|---|
| Export invocations | 1, failed preflight |
| Business race/result rows exported | Not generated |
| Input race/current-result counts | Unconfirmed |
| Complete/partial/unusable counts, reasons | Not calculated on real data |
| Relative/speed rows, year x grade table | Not calculated on real data |
| Real build / offline reproduction | Not started |
| Peak memory of failed export | Not captured by this failure path; not estimated |
| Production business-data reads / writes | 0 / 0; only connection-settings verification SELECT |
| Raw parsing, scraping, training, evaluation, 2026 race reads | None |

`export.execution.json`, `export.stdout.log`, `export.stderr.log` preserve the command, exit and timing/error evidence. There is no completed input/result artifact. Real reproduction must not be claimed from synthetic byte-equality. Next is review of this preflight failure; another production connection/export requires a new instruction. No auto retry or DB repair, no change to formula or eligibility, and no automatic next phase.

## PR #72 Review Fix and Authorized Execution

Current status: `DESCRIPTIVE_DATASET_GENERATED_REPRODUCED_AWAITING_REVIEW`.
PR #72 is not recorded as merged or review-approved. Start/end HEAD remains `16f3d20e603688542d5b2d2e068fd50d3e009807`, branch `feature/stat35-race-relative-01`; changes remain uncommitted.
The new user instruction authorized exactly one additional export and two offline builds. The old failure above remains unmodified, with its actual cause still unconfirmed.

Evidence: `/home/shinya/neo-keirin-artifacts/stat35-race-relative-01/pr72-review-fix-20260923-185418-dbdce2/`.
The wrapper defines the root and unique directory itself, uses umask 077, records actual argv/process-local environment overrides, stdout/stderr, timestamps, exit codes and peak memory, and stops on any error with a 30-minute per-stage timeout. No automatic retry occurred.

### Connection and Commands

One production connection verified database `neo_keirin_prediction_db`, schema `public`, host `127.0.0.1`, port 5432, session/transaction read-only `on`, isolation `repeatable read`, snapshot read-only `on`, snapshot `274247:274247:`. These values are sealed in the input manifest. Business reads occurred in this one snapshot; production writes remain zero. No diagnostic-only second connection was used.

```bash
DB_CONNECTION=pgsql PGOPTIONS='-c default_transaction_read_only=on -c lock_timeout=5s -c statement_timeout=5min' \
php -d memory_limit=512M artisan keirin:stat35:race-relative:export \
  --from=2022-01-01 --to=2025-12-31 --chunk=200 --output-dir="$run_dir/input"

APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= \
php -d memory_limit=512M artisan keirin:stat35:race-relative:build \
  --input-dir="$run_dir/input" --master-version=v2 --output-dir="$run_dir/result"

APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= \
php -d memory_limit=512M artisan keirin:stat35:race-relative:build \
  --input-dir="$run_dir/input" --master-version=v2 --output-dir="$run_dir/reproduced"
```

Here `$run_dir` denotes the explicit path above, passed by the same wrapper, not a variable inherited from another session.

| Stage (2026-09-23 JST) | Start / End | Seconds | PHP exit | Peak bytes | stderr bytes |
|---|---|---:|---:|---:|---:|
| Export | 18:54:18 / 18:54:36 | 18.213535178 | 0 | 48,234,496 | 0 |
| Build | 18:54:36 / 18:54:59 | 22.622826945 | 0 | 37,748,736 | 0 |
| Reproduce | 18:54:59 / 18:55:22 | 22.625007102 | 0 | 37,748,736 | 0 |

No timeout, forced termination or warning was recorded. The two builds have no production DB access, and code/master were unchanged between them.

### Actual Counts

Input and generated detail: 101,326 races / 716,837 current-result rows, exactly reconciled.
Normal finishers: 706,843; valid timing/comparison rows: 700,882; relative rows: 700,869; speed rows: 1,079.
Complete/partial/unusable race counts: 95,712 / 5,475 / 139.

| Year | Meeting grade | Races | Complete | Partial | Unusable | Relative rows | Speed rows |
|---|---|---:|---:|---:|---:|---:|---:|
| 2022 | F1 | 8,974 | 8,531 | 428 | 15 | 59,903 | 0 |
| 2022 | F2 | 13,438 | 12,540 | 869 | 29 | 89,151 | 139 |
| 2022 | G1 | 333 | 320 | 13 | 0 | 2,865 | 0 |
| 2022 | G2 | 121 | 114 | 7 | 0 | 1,035 | 0 |
| 2022 | G3 | 1,971 | 1,819 | 148 | 4 | 16,464 | 0 |
| 2022 | GP | 31 | 20 | 11 | 0 | 256 | 0 |
| 2023 | F1 | 9,254 | 8,764 | 478 | 12 | 62,623 | 0 |
| 2023 | F2 | 13,823 | 12,882 | 925 | 16 | 93,034 | 0 |
| 2023 | G1 | 345 | 316 | 29 | 0 | 2,999 | 0 |
| 2023 | G2 | 121 | 115 | 6 | 0 | 1,065 | 0 |
| 2023 | G3 | 1,987 | 1,820 | 167 | 0 | 17,048 | 411 |
| 2023 | GP | 31 | 30 | 1 | 0 | 269 | 0 |
| 2024 | F1 | 9,293 | 8,841 | 443 | 9 | 62,990 | 529 |
| 2024 | F2 | 13,838 | 12,919 | 904 | 15 | 93,303 | 0 |
| 2024 | G1 | 345 | 315 | 30 | 0 | 2,996 | 0 |
| 2024 | G2 | 121 | 102 | 19 | 0 | 1,032 | 0 |
| 2024 | G3 | 1,996 | 1,843 | 152 | 1 | 17,044 | 0 |
| 2024 | GP | 31 | 22 | 9 | 0 | 264 | 0 |
| 2025 | F1 | 9,224 | 9,025 | 196 | 3 | 63,044 | 0 |
| 2025 | F2 | 13,477 | 12,903 | 540 | 34 | 91,409 | 0 |
| 2025 | G1 | 348 | 324 | 24 | 0 | 3,003 | 0 |
| 2025 | G2 | 142 | 129 | 12 | 1 | 1,220 | 0 |
| 2025 | G3 | 2,051 | 1,987 | 64 | 0 | 17,577 | 0 |
| 2025 | GP | 31 | 31 | 0 | 0 | 275 | 0 |
| Total | All | 101,326 | 95,712 | 5,475 | 139 | 700,869 | 1,079 |

UNKNOWN has zero races, not an omitted cohort. All ten year/grade counters sum to the overall totals. Classification is meeting grade, not individual rider grade or GP-only races.
Nonexclusive race reasons: CANCELLED=97, NO_CURRENT_RESULTS=126, RESULT_NOT_FINAL=29, INSUFFICIENT_COMPARISON=13. These counts must not be summed as unique races.
Row statuses: CALCULATED=700,869, ABNORMAL_RESULT=9,994, MISSING=5,961, INSUFFICIENT_COMPARISON=13.
Speed reasons: CALCULATED=1,079, UNKNOWN_LAYOUT_VERSION=699,803, MISSING=15,952, OBSERVED_ABNORMAL_RESULT=3.
No current-result race-ID mismatch or other provenance exclusion occurred in this export. This does not eliminate historical layout gaps or prove as-of availability.

### Reproduction and Tests

`details.jsonl` (993,001,624 bytes), `summary.json`, `summary.csv`, `manifest.json` and `COMPLETE.json` all match byte-for-byte and SHA-256. Per-file seals are in `reproduction-comparison.json`; independent count reconciliation is in `verification.json`.
Input JSONL is 1,264,636,014 bytes, SHA-256 `eefd90026c2144841aa5a169882a8d98efe699ba0d83aae087985bbec96dc142`.
Details SHA-256: `67a52218421afc85c097a796d4e00eeb75f49f698a20e095cc0472980fbd3d7b`.

Added 51 synthetic cases: real PostgreSQL exporter branch via Connection double (SQL, key order/types, guards, safe diagnostics, transaction order/rollback/no publication, code seal); current result race-ID schema and whole-race exclusion even after resealing; empty ten-counter command/JSON/CSV and five-file reproduction. Existing decimal/tie/PARTIAL/speed/auxiliary-ID/offline tests are unchanged.

- Related 128M run: 201 tests, 200 passed and one test-output assertion failed (3,035 assertions). Initial loading also exposed a helper-name collision with Laravel. Both test-helper issues were fixed; only the failed output test was rerun, passing 1 test / 6 assertions. All new cases also passed in the completed full suite below.
- Changed PHP: all 10 syntax checks and limited Pint `--test` passed; no new skip or assertion removal.
- Shared-process 128M full suite terminated prematurely at the existing Stat35DataReadinessAuditTest. That reported test passed independently (1 test / 5 assertions). Exact termination cause was not recorded.
- PHPUnit `--process-isolation` could not run the existing Closure-bearing data providers (serialization error); this is not a successful suite.
- Isolated testing/SQLite, shared-process 512M full suite completed: 2,078 tests, 2,068 passed, 9 existing skipped, **1 failed**, 17,382 assertions, 34.902 seconds. Failure: existing `Stat35DataReadinessAuditTest::test_real_execution_path_and_db_disabled_byte_exact_reproduction` asserts the shared process peak below 128MiB; observed 135,266,304 bytes.
- Only that affected file was rechecked in its own 128M PHP process: **76 tests / 225 assertions passed**. Its code and threshold were not changed. The shared-process full-suite failure remains explicitly reported, not relabelled as all-pass.

No production DML/DDL, BatchRun/FetchLog writes, Migration/backfill/dry-run, Raw processing, scraping, training, prediction evaluation or 2026 race access occurred. Existing C1, masters and prior evidence are unchanged. The outcome is descriptive generation/reproduction, not predictive improvement or STAT-35/37 completion. Next: review these three fixes, generated data and the pre-existing shared-peak test limitation; no automatic next phase.
