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

Race eligibility requires men's category, CONFIRMED/CORRECTED race and successful final RESULTS_AVAILABLE import, one common import version, matching import race, unique result IDs/bikes 1-9, current count equal to import.result_count, matching observation race/import/bike/result state/agari fields, and matching source/converted hashes. Null and decimal equality are distinguished. Missing, cancelled, unsupported and inconsistent races remain in the inventory with exclusion reasons. Reasons are nonexclusive; do not sum them to obtain race counts.

The mapping to `keirin-jp-final-back-half-lap-v1` requires the sealed v2 CONFIRMED HALF_LAP definition (glossary/QA evidence), `keirin_jp` race source, matching official keirin.jp source URLs, observation `AGARI-STORAGE-v1`, semantic `AGARI_TIME`, normalizer `AGARI-TIME-v1`, and the recorded nonempty source Parser version matching its import. The source Parser version names the original result import; the storage/normalizer versions establish agari extraction semantics. Unknown mappings block relative calculations, not only speed. A historical layout/distance gap does not invalidate the independently confirmed common half-lap meaning.

Current result/entry/player IDs and observation IDs/external registration number are retained separately. Observation auxiliary IDs need not equal current IDs. The comparison unit is race+bike, not a fabricated player ID. `link_status` distinguishes missing external identity from an observed registration number whose historical availability timing is still unverified.

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

## Execution Status

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
