# STAT-35-PLAYER-HISTORY-01

現在状態注記（2026-09-28）: PR #73はMERGED。実装マージと過去の記述統計生成/再現成功を引き継ぐ。
全成果物受入の明示的な判定記録は未確認であり、予測利用の承認ではない。
以下の初回/修正時の未マージ・未コミット・レビュー待ちは当時の記録。数値・生成時code identity・hash・試験結果は変更しない。
現在の許可は[次用途仕様案](stat35-next-use-design-01.md)のdocs-only作成とレビューのみ。現行disclosureは維持する。

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
Indexes support player/meeting aggregation and at most 13 historical candidates per target group; identified target rows reuse the group history.
Unidentified target rows instead receive identity-blocked 3/6/12 windows, without modifying that shared history; summary counts use the emitted row history.
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

The following is the initial 2026-09-27 record. The PR #73 review-fix execution is recorded separately below.

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

## PR #73 Identity Scope Review Fix / 2026-09-28

Code: `PR73_IDENTITY_SCOPE_FIX_VERIFIED_AWAITING_REVIEW`.
Generation: `DESCRIPTIVE_PLAYER_HISTORY_GENERATED_REPRODUCED_AWAITING_REVIEW`.
PR #73 remains unmerged and awaits re-review, not approval. Branch `feature/stat35-player-history-01`,
start/end HEAD `4e95ddb1009feb5cf14fc22035d67fefe9979c65`; remote main remains `baac9c113d8a61061d7e5f8bd2b7cd391d091c54`.
The initial code/run/hashes above are historical evidence and have not been replaced.

### Scope and Regression

Previously, Meetings excluded a conflicting row from the mean but also added its identity reason to the entire group's context flags.
Saving that group then erased the identified rows' valid mean and removed the whole meeting from later history candidates.
Builder also assigned the same group history to conflicting target rows. The fix separates three responsibilities:

- Meeting/series context: metadata, dates, venue, class, boundaries and overlap rules remain unchanged.
- Mean membership: row identity reasons remain in evidence/exclusion counts. At least one IDENTIFIED row establishes attendance;
  qualifying identified rows alone contribute to the exact mean. A mixed group is not blocked merely by conflicting rows.
- Target identity: IDENTITY_CONFLICT and UNRESOLVED_EXTERNAL_ID rows receive explicit blocking reasons, empty meeting lists,
  null mean/median/variance/trend and no full-valid/trend contribution to summary. Identified rows still use the shared history.

An identified meeting with no usable time remains an eligible null observation consuming one slot. A group containing only unconfirmed
identities cannot establish attendance, remains null/auditable and is not a normal history candidate. Auxiliary IDs never repair identity.
No output fields or calculation version changed. History, Source, Contract, Exact and upstream relative/context calculations are unchanged.

The minimal mixed fixture first failed on the original code: 1 test / 4 assertions, expected exact mean 1 but received null (exit 1).
Eight new cases through the real Builder verify normal 1 + conflicting 2 rows, observed=3/adopted=1/excluded=2, exact mean=1;
one later meeting slot; actual nonnull previous history for the normal target; identity-blocked conflicting targets and matching summary;
conflict value/addition isolation; unresolved/conflict-only versus identified-null slots; genuine metadata/period/venue blocks;
and six-file synthetic reproduction. Existing boundary/class/overlap/same-day/null/rational/empty/corruption checks remain.

The first focused run exposed only a new test's exact-array key-order expectation (40 passed / 1 failed, 732 assertions).
That expectation was aligned with the existing numerator/denominator/decimal representation; production code was not changed for it.
Final changed-PHP syntax checks (3 files) and scoped Pint passed, then:

- `php artisan test --filter=AgariPlayerHistory`: 41 passed / 734 parent assertions, exit 0, 104.065428 seconds.
- Normal `php artisan test`, once on final PHP: 2,161 tests, **2,152 passed / 9 existing skips / 0 failures / 0 errors**,
  **18,129 parent assertions**, exit 0, 156.510868 seconds (Artisan 156.36s).
- The nine skips retain their PostgreSQL transaction/COPY/constraint/lock/READ ONLY reasons; no new skip or weakened assertion.
- All existing 17 independent 128M cases and all 17 high-parent cases passed. Player-history's unchanged >100MiB,
  12,000-race / 108,000-row fixture had peak **50,855,936 bytes** and 107,478 child assertions in each run.
  Direct child PID 109197; high-parent child PID 108752. Child assertions are not added to the parent total.

### New Fixed-Input Execution and Comparison

Evidence: `/home/shinya/neo-keirin-artifacts/stat35-player-history-01/pr73-review-fix-20260927-210230-b2d48a1f/`.
The wrapper defines run/source/old-result paths before execution, uses umask 077, testing/SQLite with blank production credentials,
absent config cache and 1,800-second process limits. Free space was 269,176,553,472 bytes versus the 23,769,617,450-byte allowance
for two outputs including SQLite workspaces plus 1GiB. Generation and reproduction each ran exactly once with the command above,
using new `result` and `reproduced` directories. No PHP/input changes between or during them, retry, source fallback or seal override.

| Stage (2026-09-28 JST) | Start / End | Seconds | Exit | Peak bytes | stderr bytes |
|---|---|---:|---:|---:|---:|
| Generate | 06:12:07 / 06:15:05 | 178.394141 | 0 | 33,554,432 | 0 |
| Reproduce | 06:15:05 / 06:18:01 | 175.937277 | 0 | 33,554,432 | 0 |
| Streaming comparison/count/date verification | 06:18:11 / 06:18:42 | 31.148496 | 0 | 12,582,912 | 0 |

Directory names/log timestamps are UTC; the table is JST. START/END source/code seals and publication checks passed.
Measured again, not hard-coded: 101,326 races / 716,837 result rows, 2,436 external IDs, 241,464 groups / 235,725 numerical groups,
573,435 trend rows / 143,402 null rows; unresolved/conflicting identity rows both 0. All year/grade/class/window totals and every
selected history's strict end-before-target-start condition were independently reconciled from streamed records and sealed source details.

Old result versus new result: **all four data files in the initial artifact table match byte-for-byte and SHA-256**, with zero changed
lines/chunks. New result versus reproduced: **all six artifacts match byte-for-byte and SHA-256**. Their body seals were also verified.
Old versus new manifest/COMPLETE is not an equality requirement: processing identity changed only for Meetings.php and Builder.php.

| New artifact | Bytes | SHA-256 |
|---|---:|---|
| manifest.json | 12,049 | `844440cb08b06b0e69195a6384689bc545e63806ff85237ca36a81f5dd08507e` |
| COMPLETE.json | 92 | `2d63b4657a32c9d36c4a0dca222f283ba238f68142b562887263aaa0f9d11d8d` |

New processing identities: Meetings.php `24e4f72acaaacd3633ac74b8fbb953f7f0d0f04a9b471a3dba8dbfe85b179714`,
Builder.php `dc1e1c54b66c8712203a879bcad89b1e2cb6c1868491a3263b772a433744b50b`.
No old code hashes were copied over the new identities. The old data, manifest, COMPLETE, workspace and logs were never written.
`verification.json` records streaming comparisons and independent counts; stage directories retain commands/stdout/stderr/exit/timing;
`completion.json` and `changes.patch` record final checks and the uncommitted scope. Failed synthetic attempts are retained separately.

No production DB connection/write, export or upstream relative rebuild, Raw/HTTP, migration/backfill/backup, training/evaluation or
2026 real-race access. The frozen descriptive restrictions above, C1 and incomplete STAT-35/37 remain unchanged.
Only re-review is next; no fetch/add/commit/push/PR operation/merge or automatic next phase.

## マージ後の工程同期 / 2026-09-28

[PR #73](https://github.com/Shinya-Taketani/NeoBicycleRaceInformationWeb/pull/73)のGitHub状態MERGED、
head `3a18de5bb08dd7f83cd8e35b176ce58dc7e49893`、merge/main `f0834202fcac19919bfa4b8dce0ef9043a457a0a`、
merged_at `2026-09-27T21:33:48Z`（2026-09-28 06:33:48 JST）を確認した。
取得したreviewsは旧headへのCOMMENTEDで、reviewDecisionは空。現headの明示的APPROVEDや全成果物受入の
**判定記録未確認**を、修正未完了・実行失敗とは区別する。上記の修正・人工試験・固定入力生成/再現の成功記録を維持する。
今回それらを再レビュー・再実行・再ハッシュしておらず、2,152成功/9skip等を今回の結果として報告しない。

ユーザーの今回限定指示に基づき、現在工程をSTAT-35-NEXT-USE-DESIGN-01へ進める。
[仕様案](stat35-next-use-design-01.md)はDRAFT_AWAITING_REVIEWであり、既存C1を維持した将来development比較の案に限る。
結果行ベースの本人/文脈を固定C1出走集合へ接続する根拠、窓/統計量の部分集合、欠損・数値変換・比較Gateは未承認。
モデル入力への直接転用・窓最適化・実装・DB接続・2026利用を許可しない。
元のSTAT35-PLAYER-HISTORY-v1、FINAL_RESULT_DESCRIPTIVE_ONLY、EVENT_DATE_BACKFILLED_FINAL_RESULTS、
historical_as_of_available=false、prediction_use=NOT_AUTHORIZED、points=nullをそのまま維持する。
工程正本は[MASTER PLAN](statistical-engine-master-plan.md) v1.37。次は仕様案と対象範囲表の文書レビューだけ。
