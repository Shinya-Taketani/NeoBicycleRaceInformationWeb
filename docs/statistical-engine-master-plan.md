# STATISTICAL_ENGINE_MASTER_PLAN

- Document: 統計エンジン開発工程マスター
- Version: 1.49
- Created: 2026-08-23
- Updated: 2026-10-03
- Repository: `Shinya-Taketani/NeoBicycleRaceInformationWeb`
- Intended repository path: `docs/statistical-engine-master-plan.md`
- Remote `main` at creation: `82d394ec014b46ca4792858fbe9fe35eaa7434d5`
- Remote `main` at last update: `db94280fbab7434ccf34043dcef40b6deb7401df`
- Current review: PR85マージ済み。C1-STAT10-ABLATION-01の実学習・比較・独立再現完了、主Gate未達・C1維持。未コミットのコード/結果レビュー待ち
- Current code: 独立C1Stat10Ablation、原16項目検証後の名前付き15項目投影。旧C1/STAT35/S/D/gapコード・モデル・原資料は不変
- Current execution record: execute1回/独立2run/実列挙76意味ファイル一致、主Gate NOT_PASSED、補助STAT01 PASS。manifest 671bbcd9…、15.58参照。旧15.57/P3未解決は保持
- Current tests record: 関連128M322 passed/2910 assertions、通常全体2771 passed/9既存skip/26125 assertions、変更PHP19構文/限定Pint成功。旧試験/実測値は各工程の当時の記録として保持
- Remote state at creation: PR #40 merged
- Local repository state at creation: user reported that the merged `main` had **not yet been pulled locally**
- Purpose: 統計エンジンの工程・確定事項・禁止事項・監査根拠・次工程を一元管理し、ChatGPT / Codex / 人手レビュー間の工程ずれを防止する

---

# 1. この文書の位置付け

この文書は、統計エンジン開発工程に関する **Single Source of Truth（工程管理上の正本）** とする。

ただし、事実の種類によって優先する根拠を分ける。

## 1.1 優先順位

### 工程・方針・目的

1. ユーザーが現在の会話で明示した最新決定
2. 本 `STATISTICAL_ENGINE_MASTER_PLAN.md`
3. 統計エンジン要件定義
4. 過去のChatGPTプロジェクト内チャット・作成資料

### 実装状態

1. GitHub `main` の実コード
2. merged PR / merge commit
3. 本文書
4. 過去チャット

### Production実行結果・監査値

1. DB上の正式run / manifest / fingerprint / immutable artifact
2. 実行ログ・監査JSON・正式export
3. 本文書
4. 過去チャット要約

矛盾を発見した場合は、推測して進めず **STOP** し、矛盾を報告して本文書を更新する。

---

# 2. ChatGPTが統計エンジン作業前に必ず行うこと

統計エンジンに関する次の作業を行う前に、ChatGPTは必ず本ファイルを確認する。

- 次工程の提案
- Codexへの実装指示作成
- PRレビュー
- Production実行手順の作成
- バックテスト結果の解釈
- STAT採否判断
- threshold / score / parameter仕様の提案
- 2025・2026データ利用判断
- 統計エンジンの完成判定

最低限、以下を確認する。

1. `current_engine_state`
2. `next_allowed_action`
3. `completed_phases`
4. `superseded_phases`
5. `blocked_phases`
6. `frozen_contracts`
7. `unfrozen_contracts`
8. `holdout_status`
9. 正式run ID / UUID / manifest hash
10. 直近merged PR / `main` SHA

本ファイルを確認せず、過去チャットの記憶だけで次工程を提案してはいけない。

---

# 3. Codexが統計エンジン実装前に必ず行うこと

今後の統計エンジン用Codexプロンプトには、必ず次の工程ゲートを含める。

```text
==================================================
■ 統計エンジン工程ゲート
==================================================

実装・修正・Production実行を開始する前に必ず、

docs/statistical-engine-master-plan.md

を全文確認すること。

最低限:

- current_engine_state
- next_allowed_action
- completed_phases
- superseded_phases
- blocked_phases
- frozen_contracts
- unfrozen_contracts
- holdout_status

を確認する。

今回の指示がMASTER PLANと一致しない場合、
コードを変更せずSTOPして工程不整合を報告すること。

COMPLETED済み工程を、
明示的な再検証理由・version変更なしに再実装しないこと。

SUPERSEDED工程を復活させないこと。

2026 holdoutをMASTER PLANの許可なしに参照しないこと。

MASTER PLANと実コード / DB正式runに矛盾がある場合、
推測で進めずSTOPすること。
```

---

# 4. 統計エンジンの最終5目標

統計エンジンの完成条件は以下の5目標とする。

## Goal 1

**1着・2着・3着以内への入賞に影響する統計項目を割り出す。**

## Goal 2

**レース内順位に影響する統計項目を割り出す。**

## Goal 3

影響項目についてバックテスト結果を利用し、

**1着・2着・3着を最大限正確に予測できる連続値scoreと学習済みparameterを決定する。**

整数の加点数・減点数はBT-03E-01で棄却した仮説であり、最終仕様の必須成果物ではない。

## Goal 4

完成・freezeした統計エンジンを、

**結果を参照しない発走前データだけでholdoutレースへ適用し、1着・2着・3着予測精度を測定する。**

## Goal 5

最終的に未来レースを結果取得前に予測・保存し、

**実際のレース終了後に結果と比較して実運用予測精度を測定する。**

### 最上位判断基準

統計的な整理の美しさ、係数の説明容易性、相関構造の単純さよりも、

**最終的な1着・2着・3着予測精度を優先する。**

ただし、リーク、監査不能、再現不能な方法で精度を高く見せることは禁止する。

---

# 5. 現在地

```yaml
current_engine_state: C1_STAT10_ABLATION_01_COMPLETED_AWAITING_REVIEW
current_scoring_hypothesis_status: BT-03E-08_REJECTED_FOR_ADOPTION
next_allowed_action: C1_STAT10_ABLATION_01_RESULT_REVIEW_ONLY
next_implementation_phase: NOT_AUTHORIZED
current_phase: C1-STAT10-ABLATION-01
remote_main: db94280fbab7434ccf34043dcef40b6deb7401df
pr64_status: MERGED
pr65_status: MERGED
pr66_status: MERGED
pr67_status: MERGED
pr68_status: MERGED
pr69_status: MERGED
pr70_status: MERGED_REVIEW_COMPLETED
pr71_status: MERGED_REVIEW_COMPLETED
pr72_status: MERGED_REVIEW_COMPLETED
pr73_status: MERGED
pr74_status: MERGED_DESIGN_DOCUMENTATION
pr75_status: MERGED_REVIEW_COMPLETED
pr76_status: MERGED_REVIEW_COMPLETED
pr77_status: MERGED_REVIEW_COMPLETED
pr78_status: MERGED_REVIEW_COMPLETED
pr79_status: MERGED_REVIEW_COMPLETED
pr80_status: MERGED_REVIEW_COMPLETED
pr81_status: MERGED_REVIEW_COMPLETED
pr82_status: MERGED
pr83_status: MERGED_REVIEW_COMPLETED
pr84_status: MERGED_REVIEW_COMPLETED
pr85_status: MERGED_REVIEW_COMPLETED_P3_RESIDUAL_UNRESOLVED
c1_stat10_ablation_01: COMPLETED_DEVELOPMENT_COMPARISON_AWAITING_REVIEW
c1_stat10_ablation_01_candidate: C1_MINUS_STAT10
c1_stat10_ablation_01_performance: INCREMENTAL_GATE_NOT_PASSED
c1_stat10_ablation_01_decision: NOT_ADOPTED_RETAIN_C1_AWAITING_REVIEW
c1_stat10_ablation_01_stat01_aux_gate: PASS
c1_stat10_ablation_01_reproduction: SEVENTY_SIX_ENUMERATED_SEMANTIC_FILES_IDENTICAL
c1_stat10_ablation_01_c1_retraining_count: 0
c1_stat10_ablation_01_manifest_sha256: 671bbcd97463123076846c4dcd6f7273e6e6aa0d9da8dabba1dc205ef2dca339
c1_stat10_ablation_01_historical_as_of_available: false
c1_stat10_ablation_01_formal_adoption: false
c1_stat10_ablation_01_live_use_authorized: false
c1_stat10_ablation_01_points: null
c1_stat10_ablation_01_2026_access: FORBIDDEN
stat01_c1_score_gap_01: COMPLETED_PR85_MERGED_NOT_ADOPTED
stat01_c1_score_gap_01_scope: FIXED_C1_ALL_ENTRANT_RAW_CENTERED_GAP_ONLY_TWO_INDEPENDENT_DEVELOPMENT_RUNS
stat01_c1_score_gap_01_model_version: STAT01-C1-SCORE-GAP-SEQUENTIAL-POSITION-v1
stat01_c1_score_gap_01_incremental_gate: NOT_PASSED
stat01_c1_score_gap_01_decision: NOT_ADOPTED_RETAIN_C1
stat01_c1_score_gap_01_stat01_aux_gate: FAIL_REDESIGN_REQUIRED
stat01_c1_score_gap_01_reproduction: SEVENTY_SIX_SEMANTIC_FILES_IDENTICAL
stat01_c1_score_gap_01_c1_retraining_count: 0
stat01_c1_score_gap_01_manifest_sha256: 214ba0fa3a144b7053457c5ad2d123e874256d0f34927a04700ce74186dc9ee1
stat01_c1_score_gap_01_historical_as_of_available: false
stat01_c1_score_gap_01_formal_adoption: false
stat01_c1_score_gap_01_live_use_authorized: false
stat01_c1_score_gap_01_points: null
stat01_c1_score_gap_01_2026_access: FORBIDDEN
stat17_c1_compare_01: PR84_MERGED_REVIEW_COMPLETED_NOT_ADOPTED
stat17_c1_compare_01_scope: FIXED_C1_HISTORY4_DERIVED_DIVERSITY_ONLY_TWO_INDEPENDENT_DEVELOPMENT_RUNS
stat17_c1_compare_01_model_version: STAT17-C1-METHOD-DIVERSITY-SEQUENTIAL-POSITION-v1
stat17_c1_compare_01_incremental_gate: NOT_PASSED
stat17_c1_compare_01_decision: NOT_ADOPTED_RETAIN_C1
stat17_c1_compare_01_stat01_aux_gate: PASS
stat17_c1_compare_01_reproduction: SEVENTY_SIX_SEMANTIC_FILES_IDENTICAL
stat17_c1_compare_01_c1_retraining_count: 0
stat17_c1_compare_01_manifest_sha256: f99c7d0dbed5e1fda0e387d2877cb181629e0236953a1317f9ab76716283b69f
stat17_c1_compare_01_historical_as_of_available: false
stat17_c1_compare_01_formal_adoption: false
stat17_c1_compare_01_points: null
stat17_c1_compare_01_2026_access: FORBIDDEN
stat36_c1_compare_01: PR83_MERGED_REVIEW_COMPLETED_NOT_ADOPTED
stat36_c1_compare_01_scope: FIXED_F175DEFF_CANDIDATE_ONLY_C1_PLUS_S_TWO_INDEPENDENT_DEVELOPMENT_RUNS
stat36_c1_compare_01_incremental_gate: NOT_PASSED
stat36_c1_compare_01_stat01_gate: PASS
stat36_c1_compare_01_reproduction: SEVENTY_SIX_SEMANTIC_FILES_IDENTICAL
stat36_c1_compare_01_c1_retraining_count: 0
stat36_c1_compare_01_historical_as_of_available: false
stat36_c1_compare_01_formal_adoption: false
stat36_c1_compare_01_live_use_authorized: false
stat36_c1_compare_01_2026_access: FORBIDDEN
stat36_c1_candidate_01: PR82_MERGED_CANDIDATES_PREPARED_GENERAL_TRAINING_NOT_AUTHORIZED
stat36_c1_candidate_01_contract: STAT36-C1-CANDIDATE-v1
stat36_c1_candidate_01_scope: FIXED_THREE_BUNDLES_OFFLINE_NO_DB_HTTP_RAW_TRAINING
stat36_c1_candidate_01_counts: RACES_99669_ENTRIES_706051_CONNECTED_706051_NUMERIC_706051_NULL_0
stat36_c1_candidate_01_source_rows: ROWS_901038_LINKED_886783_OUTSIDE_ROWS_14255_OUTSIDE_ENTRIES_11658
stat36_c1_candidate_01_versions: MULTI_ENTRY_180725_VALUE_CONFLICT_0_FETCH_FAILURE_1
stat36_c1_candidate_01_integrity: ONE_BUILD_ONE_INDEPENDENT_REPRODUCTION_FIFTEEN_FILES_AND_MANIFEST_IDENTICAL
stat36_c1_candidate_01_manifest: f175deff20fe8905b40e92ffa2d9ff16de71f432584c9e13e129d47045306437
stat36_c1_candidate_01_timing: UNKNOWN_S_PERIOD_BASELINE_CORRECTION_ALL_706051_CANDIDATES
stat36_c1_candidate_01_prediction_use: NOT_AUTHORIZED
stat36_c1_candidate_01_training_evaluation_authorized: false
stat36_c1_candidate_01_historical_as_of_available: false
stat36_c1_candidate_01_points: null
stat36_start_count_01: PR81_MERGED_REVIEW_COMPLETED
stat36_start_count_01_counts: RACES_101326_ENTRIES_717709_FETCH_VERSIONS_127160_ROWS_901038
stat36_start_count_01_timing: UNKNOWN_ALL_901038_NUMERIC_ROWS
stat36_start_count_01_execution: ONE_BUILD_ONE_OFFLINE_REPRODUCTION_14_FILES_IDENTICAL
stat36_start_count_01_export: ONE_COMPLETE_BUNDLE_AFTER_PRECHECK_FAILURE_AND_ABORTED_PARTIAL
stat36_start_count_01_manifest: bcadbe02aecb3eaee510b2646fc38b305db08f11633d3bf397be1e193190386d
stat36_start_count_01_prediction_use: NOT_AUTHORIZED
stat36_start_count_01_historical_as_of_available: false
stat36_observation_01: PR80_MERGED_PAGE_V3_SIGNATURE_V2
stat36_observation_01_signature_contract: STAT36-DISPLAY-SIGNATURE-v2-SORTED-OBJECT-KEYS
stat36_observation_01_page_contract: STAT36-PAGE-STATE-v3-EXPLICIT-DISPLAY-FLAG
stat36_observation_01_real_execution: V1_RECORD_PRESERVED_V2_V3_NOT_RERUN
stat36_observation_01_real_miscount_impact: NOT_ASSESSED_IN_PR80
stat36_observation_01_scope: FIXED_PR64_LEDGER_2022_2025_RAW_OFFLINE_ALL_IMPORT_VERSIONS_ONLY
stat36_observation_01_counts: IMPORTS_127121_RAW_RACES_101297_ROWS_900049_NO_IMPORT_RACES_29
stat36_observation_01_start_acquired: ALL_NULL_UNKNOWN_POSITION_DEFINITION
stat36_observation_01_initial_position: MISSING_INITIAL_POSITION
stat36_observation_01_historical_as_of_available: false
stat36_observation_01_prediction_use: NOT_AUTHORIZED
stat36_observation_01_points: null
stat36_observation_01_manifest: d3aba9fb2374fe17c4f360cbd74b9b7738bfbb794a3c751e30ed551d7868ae1b
stat35_c1_diagnostic_01: PR79_MERGED_REVIEW_COMPLETED
stat35_c1_diagnostic_01_scope: POST_HOC_DESCRIPTIVE_DIAGNOSTIC_SAVED_OUTER_2024_2025_ONLY
stat35_c1_diagnostic_01_integrity: RACES_50078_ENTRIES_356209_UTILITY_EXACT_SOURCE21_CODE35_UNCHANGED_FOURTEEN_FILES_IDENTICAL
stat35_c1_diagnostic_01_manifest: 304d2329df00ff17de383352ec45b01522430cb9181c2e13c399f531b8d451f9
stat35_c1_compare_01: PR78_MERGED_REVIEW_COMPLETED_INCREMENTAL_GATE_NOT_PASSED_C1_RETAINED
stat35_c1_compare_01_scope: FIXED_MEAN6_C2_ONLY_TWO_INDEPENDENT_DEVELOPMENT_RUNS_SAVED_OUTER_C1_BASELINE
stat35_c1_compare_01_source: INPUT02_MANIFEST_7f4356b93f90203dc727dc95d6215a8d8ce3f44869c4441c3981087ff1099c26
stat35_c1_compare_01_integrity: SOURCE_39_CODE_354_UNCHANGED_BOTH_RUNS_76_FILES_IDENTICAL_C1_RETRAINING_0
stat35_c1_compare_01_incremental_gate: NOT_PASSED_HIT3_CI_LOWER_NOT_POSITIVE
stat35_c1_compare_01_stat01_gate: PASS_NOT_AN_ADOPTION_AUTHORIZATION
stat35_c1_compare_01_manifest: 8fc40000c93bbc604caac37cf21e60519f26f28c4e77cf9284fa31403f5487c8
stat35_c1_compare_01_historical_as_of_available: false
stat35_c1_compare_01_formal_adoption: NOT_AUTHORIZED
stat35_c1_input_02: PR77_MERGED_INPUTS_ACCEPTED_FOR_LIMITED_COMPARE_01_ONLY
stat35_c1_input_02_scope: FIXED_CONTEXT_PIN_TESTS_OFFLINE_BUILD_ONCE_REPRODUCE_ONCE
stat35_c1_input_02_counts: RACES_99669_ENTRIES_706051_CONNECTED_705048_NUMERIC_685719_NULL_20332
stat35_c1_input_02_verification: C1_UNCHANGED_HELD_1003_SET_MATCH_FOURTEEN_FILES_AND_MANIFEST_IDENTICAL
stat35_c1_input_02_historical_as_of_available: false
stat35_c1_input_02_training_evaluation_2026_live: ORIGINAL_PROHIBITION_PRESERVED_COMPARE_01_FIXED_DEVELOPMENT_EXCEPTION_ONLY_NO_2026_OR_LIVE
stat35_c1_context_01: PR76_MERGED_FIXED_CANDIDATE_ACCEPTED_FOR_INPUT_PREPARATION
stat35_c1_context_01_scope: FIXED_C1_2022_2025_ENTRY_RACE_DAY_MEETING_METADATA_ONLY
stat35_c1_context_01_acceptance: FIXED_248_BYTE_CANDIDATE_ONLY_SAVED_METADATA_RECONSTRUCTION
stat35_c1_context_01_historical_as_of_available: false
stat35_c1_context_01_prior_review_pending: PRESERVED_IN_ORIGINAL_ARTIFACTS
stat35_c1_context_01_mean6: AUTHORIZED_ONLY_IN_STAT35_C1_INPUT_02
stat35_c1_context_01_writes_training_evaluation_2026: NOT_AUTHORIZED
stat35_c1_context_01_counts: RACES_99669_ENTRIES_706051_CANDIDATES_705048_HELD_UNKNOWN_CLASS_1003
stat35_c1_context_01_production_reads: ONE_READ_ONLY_REPEATABLE_READ_SNAPSHOT
stat35_c1_context_01_production_writes: 0
stat35_c1_context_01_reproduction: SEVEN_FILES_AND_MANIFEST_BYTE_SHA256_EXACT_NO_DB_RECONNECT
stat35_c1_context_01_retry_or_next_generation: NOT_AUTHORIZED
pr73_head: 3a18de5bb08dd7f83cd8e35b176ce58dc7e49893
pr73_merged_at: '2026-09-27T21:33:48Z'
pr73_artifact_acceptance_record: 判定記録未確認
stat35_next_use_design_01: MERGED_DESIGN_DRAFT_INPUT_SCOPE_ADOPTED_ONLY
stat35_next_use_design_01_scope: ORIGINAL_DRAFT_PRESERVED_INPUT_PREPARATION_ONLY_NOW_AUTHORIZED
stat35_c1_input_01: PR75_CODE_REVIEW_MERGED_PRIOR_ALL_NULL_DIAGNOSTIC_PRESERVED
stat35_c1_input_01_counts_reason_scope: PRIOR_V1_DIAGNOSTIC_NOT_REGENERATED
stat35_c1_input_01_subset: MEAN6_PARTIAL_WINDOW_RATIONAL_DECIMAL12_HALF_EVEN_FLOAT_SIDECAR
stat35_c1_input_01_counts: RACES_99669_ENTRIES_706051_CONNECTED_0_NUMERIC_0_NULL_706051
stat35_c1_input_01_reason: MISSING_IDENTITY_CONTEXT_EVIDENCE
stat35_c1_input_01_input_ready: false
stat35_c1_input_01_performance: NOT_EVALUATED
stat35_c1_input_01_production_access: 0
stat35_player_history_01_code: PR73_IDENTITY_SCOPE_FIX_MERGED
stat35_player_history_01_execution: DESCRIPTIVE_PLAYER_HISTORY_GENERATED_REPRODUCED_PRIOR_RECORD_PRESERVED
stat35_player_history_01_prior_review_state: PR73_IDENTITY_SCOPE_FIX_VERIFIED_AWAITING_REVIEW
stat35_player_history_01_analysis_mode: FINAL_RESULT_DESCRIPTIVE_ONLY
stat35_player_history_01_history_time_basis: EVENT_DATE_BACKFILLED_FINAL_RESULTS
stat35_player_history_01_historical_as_of_available: false
stat35_player_history_01_prediction_use: NOT_AUTHORIZED
stat35_player_history_01_points: null
stat35_player_history_01_input: RACES_101326_CURRENT_RESULTS_716837
stat35_player_history_01_inventory: PLAYERS_2436_PLAYER_MEETING_CLASS_GROUPS_241464_NUMERICAL_235725
stat35_player_history_01_full_valid_windows: N3_640112_N6_573435_N12_457715_TARGET_RESULT_ROWS
stat35_player_history_01_trend: CALCULATED_573435_NULL_143402_TARGET_RESULT_ROWS
stat35_player_history_01_reproduction: SIX_FILES_BYTE_AND_SHA256_EXACT_INDEPENDENT_COUNTS_AND_DATES_VERIFIED
stat35_player_history_01_old_comparison: FOUR_DATA_FILES_BYTE_AND_SHA256_EXACT_ZERO_CHANGED_ROWS
stat35_player_history_01_tests: ARTISAN_2152_PASSED_9_EXISTING_SKIPS_18129_PARENT_ASSERTIONS
stat35_player_history_01_isolated_memory_cases: 17
stat35_player_history_01_production_connection_and_write: 0
stat35_race_relative_01_code: REVIEW_COMPLETED_PR72_MERGED
stat35_race_relative_01_test_caveat: ISOLATED_MEMORY_CHECKS_AND_ARTISAN_FULL_SUITE_PASS
stat35_race_relative_01_isolated_memory_cases: 16
stat35_race_relative_01_execution: DESCRIPTIVE_DATASET_GENERATED_REPRODUCED_REVIEW_COMPLETED_PR72_MERGED
stat35_race_relative_01_prior_export: ONE_ATTEMPT_EXIT_1_ACTUAL_MISMATCH_UNCONFIRMED_PRESERVED
stat35_race_relative_01_review_fix_export_attempts: 1
stat35_race_relative_01_review_fix_export_exit_code: 0
stat35_race_relative_01_real_counts: RACES_101326_CURRENT_RESULTS_716837
stat35_race_relative_01_comparison_races: COMPLETE_95712_PARTIAL_5475_UNUSABLE_139
stat35_race_relative_01_generated_rows: RELATIVE_700869_SPEED_1079
stat35_race_relative_01_build_and_reproduction: EXIT_0_BOTH_FIVE_FILES_BYTE_AND_SHA256_EXACT
stat35_race_relative_01_analysis_mode: FINAL_RESULT_DESCRIPTIVE_ONLY
stat35_race_relative_01_historical_as_of_available: false
stat35_race_relative_01_prediction_use: NOT_AUTHORIZED
stat35_race_relative_01_points: null
stat35_race_relative_01_production_business_reads: ONE_AUTHORIZED_2022_2025_REPEATABLE_READ_SNAPSHOT
stat35_race_relative_01_production_writes: 0
stat35_race_relative_01_read_only_preflight: VERIFIED_ENDPOINT_SESSION_ON_TRANSACTION_ON_SNAPSHOT_ON_REPEATABLE_READ
stat35_race_relative_01_retry: NOT_AUTHORIZED_WITHOUT_NEW_INSTRUCTION
stat35_37_track_context_01_code: REVIEW_COMPLETED_PR70_MERGED
stat35_37_track_context_01_master: V1_42_TRACKS_44_OBSERVATION_LAYOUTS
stat35_37_track_context_01_historical_coverage: RESOLVED_3_OF_10660_TRACK_DAYS
stat35_37_track_context_01_unknown_layout_days: 10657
stat35_37_track_context_01_conflicting_days: 0
stat35_37_track_context_01_individual_guides: AT_V1_TWO_CHECKED_FORTY_CURRENT_GUIDES_UNCONFIRMED
stat35_37_track_context_01_production_writes: 0
stat35_37_track_context_01_prediction_use: NOT_AUTHORIZED
stat35_37_track_context_02_code: REVIEW_COMPLETED_PR71_MERGED
stat35_37_track_context_02_master: V2_42_TRACKS_89_OBSERVATION_LAYOUTS
stat35_37_track_context_02_source_review: ALL_42_ATTEMPTED_37_NAVIGATIONS_CHECKED_5_UNAVAILABLE_NOT_HISTORY_COMPLETE
stat35_37_track_context_02_historical_coverage: RESOLVED_14_OF_10660_TRACK_DAYS
stat35_37_track_context_02_distance_resolved_days: 14
stat35_37_track_context_02_added_distance_days: 11
stat35_37_track_context_02_unknown_layout_days: 10646
stat35_37_track_context_02_conflicting_days: 0
stat35_37_track_context_02_regressed_days: 0
stat35_37_track_context_02_v1: ALL_FILES_VALUES_PERIODS_AND_EVIDENCE_UNCHANGED
stat35_37_track_context_02_production_connections_and_writes: 0
stat35_37_track_context_02_prediction_use: NOT_AUTHORIZED
scr_stat35_02_overall: NOT_COMPLETED_HISTORICAL_PERIODS_AND_SOURCE_FIELDS_REMAIN_UNCONFIRMED
stat35_storage_backfill_01: IMPLEMENTATION_REVIEW_MERGE_COMPLETED
stat35_production_preflight_01: COMPLETED_PR66_MERGED
stat35_production_migration_01: APPLIED_AND_SCHEMA_VERIFIED_REVIEW_COMPLETED_PR67_MERGED
stat35_production_schema: APPLIED_AND_SCHEMA_VERIFIED
stat35_migration_batch: 14
stat35_other_pending_migrations: 0
stat35_production_write_authorization: YEARS_2022_2025_EXCLUDING_PILOT_2024_12_31_OBSERVATIONS_CURRENT_AGARI_AND_BATCH_AUDIT_EXECUTED
stat35_further_production_writes: NOT_AUTHORIZED
stat35_production_dry_run: POST_SAVE_READ_ONLY_COMPLETED_ALL_48_INTERVALS
stat35_production_backfill: YEARS_2022_2025_SCOPE_PROCESSED_GAPS_AND_MISSING_REMAIN
stat35_production_backfill_pilot_01: REVIEW_COMPLETED_PR68_MERGED_SAVED_ROWS_UNCHANGED
# 以下3件は既存pilotの実績。今回の増分と混同しない。
stat35_backfill_batch_run_id: 120
stat35_backfill_observations_added: 490
stat35_backfill_current_results_updated: 490
stat35_production_backfill_2022_2025_01: REVIEW_COMPLETED_PR69_MERGED_SAVED_ROWS_UNCHANGED
stat35_post_save_dry_run: ZERO_PLANNED_CHANGES_ALL_EXECUTED_INTERVALS
stat35_backfill_2022_2025_executed_intervals: 48
stat35_backfill_2022_2025_unstarted_intervals: 0
stat35_backfill_2022_2025_batch_run_ids: 121-168
stat35_backfill_2022_2025_observations_added: 899506
stat35_backfill_2022_2025_current_results_updated: 716347
stat35_backfill_2022_2025_no_import_races: 29
stat35_backfill_2022_2025_no_import_unsupported_races: 0
stat35_backfill_2022_2025_cancelled_imports_skipped: 113
stat35_backfill_2022_2025_failed_imports: 0
stat35_backfill_other_dates: OUTSIDE_EXECUTED_2022_2025_SCOPE_NOT_AUTHORIZED
stat35_backup: CUSTOM_DUMP_AND_ARCHIVE_LIST_SUCCEEDED_RESTORE_TEST_NOT_PERFORMED
bounded_memory_test_limit: INDEPENDENT_PROCESS_128M
production_memory_example: 512M_ADJUST_BY_MEASUREMENT
stat35_data_readiness_audit_01: COMPLETED_PR64_MERGED
stat35_readiness: BLOCKED_INSUFFICIENT_RAW_HISTORY
stat35_identity_safe: true
stat35_primary_blocker: INSUFFICIENT_RAW_HISTORY
stat35_secondary_blocker: RAW_GAP_POLICY
stat35_production_migration: APPLIED_AND_SCHEMA_VERIFIED
stat35_historical_as_of_available: false
tactical_history_final_01_review: COMPLETED_PR56_MERGED
tactical_history_final_01_model_sha256: e3cc1f7f10af60bb22f97a2172ca52ef2c09a3393222ee46c25619de752d43a1
tactical_history_final_01_lambda: 0.1
tactical_history_final_01_parameters: FIXED_EXISTING_C1_NOT_REOPENED_BY_STAT35_DESIGN
tactical_prediction_pipeline_mode: DEVELOPMENT_REPLAY_ONLY
tactical_prediction_pipeline_code_review: COMPLETED_PR57_MERGED
tactical_prediction_pipeline_chatgpt_report_zip_review: COMPLETED_USER_CONFIRMED
tactical_prediction_result_pr58_fixes: REVIEW_COMPLETED_PR58_MERGED
2025_next_evaluation: DEVELOPMENT_CORPUS_ONLY_NOT_FINAL_HOLDOUT
2026_holdout: FROZEN_FOR_MODEL_SELECTION
bt03e02_status: COMPLETED_WITH_REPRODUCIBLE_NEGATIVE_RESULT
bt03e02_performance: FAIL / REDESIGN_REQUIRED
bt03e02_reproducibility: VERIFIED
bt03e03_design_contract: FROZEN
bt03e03_status: COMPLETED_WITH_REPRODUCIBLE_NEGATIVE_RESULT
bt03e03_reproducibility: VERIFIED
bt03e03_integrity: PASS
bt03e03_performance: FAIL / REDESIGN_REQUIRED
bt03e04_design_contract: FROZEN
bt03e04_status: COMPLETED_WITH_REPRODUCIBLE_NEGATIVE_RESULT
bt03e04_reproducibility: VERIFIED
bt03e04_integrity: PASS
bt03e04_performance: FAIL / REDESIGN_REQUIRED
bt03e04_2026_access: 0
bt03e04_gates: NI_FAIL_SUPERIORITY_FAIL_TEMPORAL_PASS_SUPPORTING_PASS_TIE_PASS_POSITION_REDESIGN_PASS_WIN_PRESERVATION_PASS
bt03e05_design_contract: FROZEN
bt03e05_status: COMPLETED_WITH_REPRODUCIBLE_NEGATIVE_RESULT
bt03e05_reproducibility: VERIFIED
bt03e05_integrity: PASS
bt03e05_performance: FAIL / REDESIGN_REQUIRED
bt03e05_2026_access: 0
bt03e05_gates: NI_FAIL_SUPERIORITY_PASS_TEMPORAL_PASS_SUPPORTING_PASS_TIE_PASS_POSITION_REDESIGN_PASS_WIN_PRESERVATION_PASS
bt03e05_non_inferiority_failures: POSITION_2_ACCURACY_POSITION_3_ACCURACY
bt03e06_design_contract: FROZEN
bt03e06_status: COMPLETED_WITH_REPRODUCIBLE_NEGATIVE_RESULT
bt03e06_reproducibility: VERIFIED
bt03e06_integrity: PASS
bt03e06_performance: FAIL / REDESIGN_REQUIRED
bt03e06_2026_access: 0
bt03e06_gates: NI_FAIL_SUPERIORITY_PASS_TEMPORAL_PASS_SUPPORTING_PASS_TIE_PASS_POSITION_REDESIGN_PASS_WIN_PRESERVATION_PASS
bt03e06_non_inferiority_failures: POSITION_2_ACCURACY_POSITION_3_ACCURACY
bt03e07_design_contract: FROZEN
bt03e07_status: COMPLETED_WITH_REPRODUCIBLE_NEGATIVE_RESULT
bt03e07_reproducibility: VERIFIED
bt03e07_performance: FAIL / REDESIGN_REQUIRED
bt03e07_2026_access: 0
bt03e06_vs_e07_diagnostic: COMPLETED
bt03e08_status: COMPLETED_WITH_REPRODUCIBLE_NEGATIVE_RESULT
bt03e08_reproducibility: VERIFIED
bt03e08_integrity: PASS
bt03e08_performance: FAIL / REDESIGN_REQUIRED
bt03e08_2026_access: 0
bt03e08_same_condition_rerun: FORBIDDEN
tactical_pilot_01_status: BLOCKED_INPUT_SEMANTICS
tactical_pilot_01_scope: EXPERIMENT_ONLY_NOT_FORMAL_STAT_OR_LIVE
tactical_pilot_01_eligible_inputs: NOT_FROZEN
tactical_pilot_01_new_fits: 0
tactical_history_01_status: V2_EVALUATED_AND_REPRODUCED
tactical_history_01_scope: HISTORICAL_EVENT_RECONSTRUCTION_BACKFILLED_FINAL_RESULT_DEVELOPMENT_ONLY
tactical_history_01_v1_status: MODEL_FIT_FAILED_NOT_EVALUATED_PRESERVED
tactical_history_01_v2_c0_reuse: FORBIDDEN_BOTH_CANDIDATES_USE_CORRECTED_SOLVER
tactical_history_01_v2_incremental_gate: PASS_DEVELOPMENT_INCREMENTAL_EFFECT_ONLY
tactical_history_01_v2_stat01_gate: PASS / GO_TO_FREEZE
tactical_history_01_v2_reproducibility: VERIFIED_TWO_REAL_FITS_AND_EVALUATIONS
tactical_history_01_v2_live_adoption: NOT_AUTHORIZED
2026_access: 0
final_points: NOT_APPLICABLE_CONTINUOUS_SCORE
final_thresholds: UNFROZEN
final_score_formula: POSITION_SPECIFIC_PROBABILITY_STRUCTURE_FROZEN_PARAMETERS_UNFROZEN
final_stat_selection: PARTIAL
acceptance_gate: FROZEN

completed_phases:
  - STATISTICS_FEATURE_FOUNDATION_CURRENT_SCOPE
  - BT-01
  - BT-02
  - BT-03A
  - BT-03B
  - BT-03C
  - BT-03E-01_ENGINEERING
  - BT-03E-02_ENGINEERING
  - BT-03E-02_DEVELOPMENT_EVALUATION
  - BT-03E-03_ENGINEERING
  - BT-03E-03_DEVELOPMENT_EVALUATION
  - BT-03E-04_ENGINEERING
  - BT-03E-04_DEVELOPMENT_EVALUATION
  - BT-03E-05_ENGINEERING
  - BT-03E-05_DEVELOPMENT_EVALUATION
  - BT-03E-06_ENGINEERING
  - BT-03E-06_DEVELOPMENT_EVALUATION
  - BT-03E-07_ENGINEERING
  - BT-03E-07_DEVELOPMENT_EVALUATION
  - BT-03E-06_VS_E07_DIAGNOSTIC
  - BT-03E-08_ENGINEERING
  - BT-03E-08_DEVELOPMENT_EVALUATION
  - STAT-35-STORAGE-BACKFILL-01_IMPLEMENTATION_REVIEW_MERGE
  - STAT-35-PRODUCTION-PREFLIGHT-01
  - STAT-35-PRODUCTION-MIGRATION-01
  - STAT-35-PRODUCTION-BACKFILL-PILOT-01_ONE_DAY_2024_12_31
  - STAT-35-PRODUCTION-BACKFILL-2022-2025-01_SCOPE_PROCESSED_AND_VERIFIED
  - STAT-35-PRODUCTION-BACKFILL-2022-2025-01_RESULT_REVIEW_PR69_MERGED
  - STAT-35-RACE-RELATIVE-01_GENERATION_REPRODUCTION_REVIEW_PR72_MERGED
  - PR72_HERMETIC_MEMORY_COMPLETION_REVIEW
  - STAT-35-PLAYER-HISTORY-01_IMPLEMENTATION_PR73_MERGED
  - STAT-36-START-COUNT-01_PR81_REVIEW_MERGE
  - STAT-36-C1-COMPARE-01_PR83_REVIEW_MERGE_NOT_ADOPTED
  - STAT-17-C1-COMPARE-01_DEVELOPMENT_COMPARISON_AND_INDEPENDENT_REPRODUCTION
  - STAT-01-C1-SCORE-GAP-01_DEVELOPMENT_COMPARISON_AND_INDEPENDENT_REPRODUCTION
  - C1-STAT10-ABLATION-01_DEVELOPMENT_COMPARISON_AND_INDEPENDENT_REPRODUCTION

superseded_phases:
  - BT-03D-PREDICTIVE-SELECTION
  - BT-03E-01-COARSE-INTEGER-SCORING-RULE

blocked_phases:
  - STAT-36-S_COUNT_GENERAL_TRAINING_AND_LIVE_UNTIL_S_TIMING_CONFIRMED_EXCEPT_FIXED_COMPARE_01
  - TACTICAL-PILOT-01_TRAIN_COMPARE_UNTIL_INPUT_SEMANTICS_CONFIRMED
  - BT-04
  - BT-05-LIVE

frozen_contracts:
  - BT03E02_CONTINUOUS_SCORE
  - BT03E02_THREE_SCORE_CHANNELS
  - BT03E02_HIERARCHICAL_BIN_MODEL
  - BT03E02_NO_EXPLICIT_STAT_MULTIPLIER
  - BT03E02_NONLINEAR_RULE
  - BT03E02_REDUNDANCY_POLICY
  - BT03E02_PARETO_OBJECTIVE
  - BT03E02_TWO_STAGE_OPTIMIZATION
  - BT03E02_REGULARIZATION_POLICY
  - BT03E02_LAMBDA_GRID
  - BT03E02_ALPHA_GRID
  - BT03E02_INNER_ALPHA_SELECTION
  - BT03E02_TIE_AND_DETERMINISM
  - BT03E02_CHANNEL_NORMALIZATION
  - BT03E02_STAT01_ANCHOR
  - BT03E02_NESTED_TEMPORAL_VALIDATION
  - BT03E02_ACCEPTANCE_GATE
  - BT03E03_POSITION_SPECIFIC_UTILITY
  - BT03E03_SEQUENTIAL_CONDITIONAL_SOFTMAX
  - BT03E03_EXACT_POSITION_MARGINALIZATION
  - BT03E03_MAP_ORDERED_TOP3
  - BT03E03_PROBABILITY_OUTPUT
  - BT03E03_SHARED_LAMBDA_SELECTION
  - BT03E03_NO_ALPHA_COMBINATION
  - BT03E03_2022_2025_DEVELOPMENT_ONLY
  - BT03E03_2026_FORBIDDEN
  - BT03E04_DECISION_DECODER_SEPARATION
  - BT03E04_FIXED_E03_V2_PROBABILITY_SOURCE
  - BT03E04_METRIC_TO_DECODER_MAPPING
  - BT03E04_PRIMARY_COHERENT_POSITION
  - BT03E04_2024_2025_DEVELOPMENT_ONLY
  - BT03E04_2026_FORBIDDEN
  - BT03E05_WINNER_PRESERVING_LEXICOGRAPHIC
  - BT03E05_FIXED_E03_V2_PROBABILITY_SOURCE
  - BT03E05_2024_2025_DEVELOPMENT_ONLY
  - BT03E05_2026_FORBIDDEN
  - BT03E07_P1_BIT_EXACT_FREEZE
  - BT03E07_DIRECT_P2_P3_FULL_FIELD_SOFTMAX
  - BT03E07_SHARED_ONE_SE_P2_P3_ONLY
  - BT03E07_2022_2025_DEVELOPMENT_ONLY
  - BT03E07_2026_FORBIDDEN
  - BT03E08_SOURCE_P1_BIT_EXACT_FREEZE
  - BT03E08_E06_WINNER_CONDITIONED_Q2_FREEZE
  - BT03E08_WINNER_CONDITIONED_DIRECT_P3_ONLY
  - BT03E08_ACTUAL_RANK2_REMAINS_P3_CANDIDATE
  - BT03E08_2022_2025_DEVELOPMENT_ONLY
  - BT03E08_2026_FORBIDDEN
  - LEAKAGE
  - SOURCE_INTEGRITY
  - MISSING_STATUS_SEMANTICS
  - ABNORMAL_RESULT_HANDLING
  - BOUNDED_MEMORY
  - READ_ONLY_BACKTEST
  - ARTIFACT_INTEGRITY

unfrozen_contracts:
  - FITTED_BETA_COEFFICIENTS
  - SELECTED_FINAL_LAMBDA
  - FINAL_POSITION_SPECIFIC_PROBABILITY_PARAMETERS
  - FINAL_TRAINING_GENERATED_BINS
  - FINAL_CHANNEL_SCALE_VALUES
  - FINAL_STAT_SELECTION_AFTER_DIAGNOSTICS
  - FINAL_ENGINE_MODEL_CALCULATION_VERSION
  - OPTIMIZER_NUMERIC_SOLVER_CONSTANTS_BEFORE_FIRST_FORMAL_EXECUTION
  - FINAL_MODEL_AND_FEATURE_MANIFESTS
  - FINAL_PREDICTION_CONFIDENCE
  - OPTIONAL_OUTPUT_THRESHOLD_IF_INTRODUCED
  - FUTURE_STAT_INTEGRATION
  - MARKET_OVERLAY
  - BET_AND_ROI_RULE

holdout_status:
  development_corpus: 2022-2025
  final_holdout: 2026
  final_holdout_model_selection_access: FORBIDDEN
  final_holdout_performance_evaluation: FORBIDDEN
  final_holdout_outcome_access: FORBIDDEN
```

## 5.1 意味

- PR #85はレビュー後マージ済み。C1-STAT10-ABLATION-01の15項目実学習・比較・独立2run/実列挙76意味ファイル一致まで完了。主Gateは非劣性/年別/優越未達のNOT_PASSEDで今回方式不採用・C1維持、補助STAT01 PASSを採用根拠へ代用しない。次はコード/結果レビューのみ。旧score gap/STAT17/S/mean6の結果とscore gap P3残件を保持し、同条件再試行・次ablation・正式置換/LIVE/2026へ進まない。15.57/15.58参照。

- STAT-35-C1-INPUT-02はPR #77マージ・固定入力受入済み。COMPARE-01はPR #78レビュー/マージ完了、追加Gate未達・C1維持。限定事後診断はPR #79レビュー/マージ完了。現在保存値の再構成、observed_at=null/historical_as_of_available=false、保留1,003件を維持。原本REVIEW_PENDING・旧全NULL診断・旧未承認表示を書き換えない。15.52当時のSTAT-36固定台帳2022-2025 Raw読取り/観測生成1回/独立再現1回だけの許可と完了は過去記録。今回の別候補工程は15.54に記録する。DB/HTTP、追加試行、context/mean6再生成、旧C1再学習、2026/LIVE、正式採用は禁止。
- PR #73のマージ、過去の修正/実生成/再現/テスト記録、全成果物受入の判定記録未確認を分離する。未確認を修正失敗へ戻さず、15.45とv1.36は当時の履歴として維持する。上記件数・テスト値は過去引用で、今回の再測定ではない。
- `unfrozen_contracts`と全体`final_score_formula`の未確定は統計エンジン全体/将来統合の範囲。既存C1最終fitのlambda=0.1・係数・bin・manifestは固定済みで、再び未決定にはしない。旧E02のalpha/3-channelを後続C1へ適用しない。
- BT-03E-01の **historical-forward scoring基盤実装自体は完成** している。
- ただしBT-03E-01で試した粗い整数加点方式は2024でSTAT-01 baselineを下回ったため、**最終仕様としては採用しない**。
- BT-03E-02はengineeringと2024/2025 development evaluationを完了し、再現性 `VERIFIED`、2026 access `0`を確認した。
- BT-03E-02はWINを2024/2025とも改善したが、2024のPosition 3が悪化して正式Gateは `FAIL / REDESIGN_REQUIRED` となった。
- BT-03E-03 v2はoptimizer縮退を解消し、lambda `0.1` / `1.0`をeligible、selected lambdaを`0.1`として再現性検証まで完了した。P2・P3・Hit@3のyear-equalは改善したがWINは負で、performanceは `FAIL / REDESIGN_REQUIRED` となった。
- BT-03E-04は再現性 `VERIFIED`、integrity `PASS`で完了したが、NIとSuperiorityがFAILし、performanceは `FAIL / REDESIGN_REQUIRED` となった。Primary 4指標のpoint estimateは両年で全てpositiveだった一方、NI failureはP3 CI lowerだけだった。
- BT-03E-05は再現性 `VERIFIED`、integrity `PASS`で完了したが、P2・P3のNon-InferiorityがFAILし、performanceは `FAIL / REDESIGN_REQUIRED` となった。Superiority、Temporal、Supporting、Tie、Position Redesign、Win PreservationはPASS、2026 accessは`0`だった。
- BT-03E-06は再現性 `VERIFIED`、integrity `PASS`で完了したが、P2・P3のNon-InferiorityがFAILし、performanceは `FAIL / REDESIGN_REQUIRED` となった。その他のGateはPASS、2026 accessは`0`だった。
- BT-03E-07はformal development evaluationと再現性検証を完了し、performance `FAIL / REDESIGN_REQUIRED`のため採用を棄却した。
- BT-03E-08はE03 source artifactのP1とE06 winner-conditioned Q2を固定し、actual rank2をcandidateに残したwinner-conditioned direct P3だけを再学習する設計で実装済みである。
- BT-03E-08のformal development evaluationは再現性 `VERIFIED`、integrity `PASS`、performance `FAIL / REDESIGN_REQUIRED`で完了した。同条件の再学習・再評価は次工程にしない。
- ユーザーの2026-09-13の新規指示は **TACTICAL-PILOT-01** に限定する。これはE08成果物による承認ではなく、戦法7候補の意味・過去時点を確認してから行う独立実験であり、正式STAT追加・LIVE開始ではない。
- 旧TACTICAL-PILOT-01は7候補の集計基準日・対象レース自身の除外根拠が未確認で `BLOCKED_INPUT_SEMANTICS`。この旧pilotは適格入力を確定できるまでfit・比較へ進まない。詳細はSection 15.24および `docs/tactical-pilot-01-input-definition-status.md`。
- 別実験TACTICAL-HISTORY-01のPR #55修正版は、C0/C1の実学習・比較・再現性確認を完了。開発期間の追加効果Gateと対STAT-01 Gateは通過したが、LIVE採用・2026利用・次工程の自動開始は許可しない。詳細はSection 15.25。
- 2024・2025はdevelopment corpusとしてのみ利用し、final untouched holdoutとは扱わない。
- 2026は最終モデル選択・fitted parameter・score仕様がfreezeされるまで評価禁止。

---

# 6. STAT要件全体と現在の実装範囲

統計エンジン要件定義はSTAT-01～STAT-46を管理対象としている。

score parameter・閾値・減衰率は原則としてバックテスト後に決定する契約であり、確定前のcontributionは `NULL` / `NOT_SCORED` が原則である。最終prediction contractに整数pointsは要求しない。

現時点でBT-02 / BT-03 / BT-03Eの中心評価に使用した範囲は次のとおり。

## 6.1 Baseline

- `STAT-01`

## 6.2 EntryIncremental 12 STAT

- `STAT-07`
- `STAT-08`
- `STAT-10`
- `STAT-11`
- `STAT-12`
- `STAT-23`
- `STAT-24`
- `STAT-26`
- `STAT-31`
- `STAT-32`
- `STAT-39`
- `STAT-42`

## 6.3 補助

- `STAT-33`: `DIAGNOSTIC_ONLY`
- `STAT-41`: `RACE_STRATIFIER`

`STAT-33` と `STAT-41` はBT-02のentry加点モデルには含めない。

## 6.4 重要な範囲制約

Goal 1 / Goal 2が完全に終了したという意味ではない。

正確には、

> **現在実装・正式評価可能な12 EntryIncremental STATについては、BT-02 / BT-03で入賞境界・値域効果の評価を実施済み。**

という状態である。

STAT-01～46全体で見ると、未実装・データ取得待ち・別工程のSTATが残っているため、

```text
Goal 1 overall = PARTIAL
Goal 2 overall = PARTIAL
```

とする。

---

# 7. 開発データとholdoutの扱い

このセクションは今後のリーク管理で最重要とする。

## 7.1 2022～2025

2022～2025のデータは、すでに以下で利用・レビューされている。

- BT-01 baseline
- BT-02 signal evaluation
- BT-03 bin effect analysis
- BT-03の年次比較
- BT-03E-01の設計判断
- 2024 BT-03E-01 OOS結果のレビュー

特にBT-02 / BT-03では2023・2024・2025の結果を評価済みであり、ChatGPTプロジェクト内でも結果を確認している。

したがって今後、

**2022～2025を「完全に未観測のfinal holdout」と呼んではいけない。**

今後の扱いは次のとおり。

```yaml
2022_2025_role: DEVELOPMENT_CORPUS
historical_walk_forward: DEVELOPMENT_VALIDATION
final_unbiased_holdout: NOT_2022_2025
```

時間方向を守ったnested / walk-forward評価は引き続き有効だが、

**モデル設計者がすでに2023～2025の結果を見ていることを明示し、final untouched testとは区別する。**

## 7.2 2024 BT-03E-01

BT-03E-01における2024結果は、

- 2023だけでcandidateを決定
- candidate freeze後に2024 outcomeを開く

という工程で実行したため、

**BT-03E-01という固定済み仮説に対しては正しいout-of-sample評価**

である。

しかしその結果は現在すでに確認済みであり、今後のBT-03E-02設計へ影響する。

したがってBT-03E-02以降で2024を「新しい未観測holdout」と呼ばない。

## 7.3 2025

BT-03E-01では2025を直接scoring評価に使っていない。

ただしBT-02 / BT-03で2025のsignal/effect結果はすでに評価・レビュー済みである。

したがって2025もfinal untouched holdoutではない。

BT-03E-02以降で利用する場合は、

**development validation / temporal validation**

として扱う。

## 7.4 2026

2026について、過去のinventoryでrace数等のメタデータを確認した履歴はあるが、

**統計エンジンのモデル選択・parameter決定・予測性能評価には使用しない。**

禁止対象:

- 2026 result labelを用いた性能評価
- 2026 outcomeを見たthreshold決定
- 2026 outcomeを見たscore parameter調整
- 2026 outcomeを見たSTAT採否
- 2026 outcomeを見たscore formula変更

現時点:

```yaml
2026_model_selection_access: FORBIDDEN
2026_performance_evaluation: FORBIDDEN
2026_outcome_access: FORBIDDEN
2026_final_holdout_status: FROZEN
```

---

# 8. Phase Status一覧

| Phase | 目的 | 状態 | 判定 |
|---|---|---|---|
| Statistics Feature Foundation | 発走前統計特徴量の構築 | COMPLETED for current scope | GO |
| BT-01 | STAT-01 baseline確立 | COMPLETED | PASS |
| BT-02 | STAT-01に対する各STAT増分予測力評価 | COMPLETED | PASS |
| BT-03A | STAT別・値域別effect算出 | COMPLETED | PASS |
| BT-03B | 値域別の正負方向整理 | COMPLETED as analysis | PASS |
| BT-03C | 年次安定性整理 | COMPLETED as analysis | PASS |
| BT-03D old predictive-selection plan | broad再選択 / joint ablationを独立工程化 | SUPERSEDED | DO NOT IMPLEMENT |
| BT-03E-01 | 粗い加減点方式を2023→2024で検証 | COMPLETED_WITH_NEGATIVE_RESULT | TOOL GO / RULE REJECT |
| BT-03E-02 | continuous 3-channel scoring方式 | COMPLETED_WITH_REPRODUCIBLE_NEGATIVE_RESULT | FAIL / REDESIGN_REQUIRED |
| BT-03E-03 | position-specific sequential probability方式 | COMPLETED_WITH_REPRODUCIBLE_NEGATIVE_RESULT | FAIL / REDESIGN_REQUIRED |
| BT-03E-04 | fixed probabilityに対するdecision decoder分離 | COMPLETED_WITH_REPRODUCIBLE_NEGATIVE_RESULT | FAIL / REDESIGN_REQUIRED |
| BT-03E-05 | winner-preserving lexicographic decoder | COMPLETED_WITH_REPRODUCIBLE_NEGATIVE_RESULT | FAIL / REDESIGN_REQUIRED |
| BT-03E-06 | winner-conditioned sequential decoder | COMPLETED_WITH_REPRODUCIBLE_NEGATIVE_RESULT | FAIL / REDESIGN_REQUIRED |
| BT-03E-07 | P1-frozen direct P2/P3 position model | COMPLETED_WITH_REPRODUCIBLE_NEGATIVE_RESULT | CLOSED / REDESIGN_REQUIRED |
| BT-03E-08 | P1/Q2-frozen winner-conditioned direct P3 model | COMPLETED_WITH_REPRODUCIBLE_NEGATIVE_RESULT | CLOSED / REDESIGN_REQUIRED |
| TACTICAL-PILOT-01 | 戦法回数追加あり/なしの限定比較 | BLOCKED_INPUT_SEMANTICS | NOT_EVALUATED / NO_FIT |
| TACTICAL-HISTORY-01 v2 | 過去レース別決まり手4回数の追加比較 | EVALUATED_AND_REPRODUCED | PASS_DEVELOPMENT_INCREMENTAL_EFFECT_ONLY |
| TACTICAL-HISTORY-FINAL-01 | 評価済みC1の最終development fit・保存モデル読込 | REVIEW_COMPLETED_PR56_MERGED | NO_NEW_ACCURACY_EVALUATION / NO_LIVE_AUTHORIZATION |
| TACTICAL-PREDICTION-PIPELINE-01 | 対象レースから入力生成・保存C1予測・ファイル固定 | REVIEW_COMPLETED_PR57_MERGED | 報告ZIP本体照合もユーザー確認済み |
| TACTICAL-PREDICTION-RESULT-01 | 固定予測と保存済み結果の照合・集計・再現 | REVIEW_COMPLETED_PR58_MERGED | IN_SAMPLE_REPLAY_TECHNICAL_CHECK / 63_RACES_MATCHED |
| TACTICAL-GRADE-ANALYSIS-01 | 保存済みOuter C1予測の級班別着順分析 | VERIFIED_AWAITING_REVIEW | 50,078レース・356,209出走、元評価一致・DB不要再現 |
| TACTICAL-MEETING-GRADE-ANALYSIS-01 | 保存済みOuter C1の開催グレード別分析（現在の主軸） | VERIFIED_AWAITING_REVIEW | 50,078レース・1,796開催、4指標の元評価一致・DB不要再現 |
| GROWTH-POINT-ANALYSIS-01 | 前走比較の成長指標と次走成績の探索診断 | PR60_MERGED | 50,078レース・356,209出走、DB不要再現、モデル変更・正式採用なし |
| GROWTH-POINT-ANALYSIS-01-v2 | 符号・0を保持する独立point版の再集計 | PR60_MERGED | raw全件一致、v1/v2 DB不要再現、QUANTILE_BASEDのv1は不変 |
| GROWTH-ADJUSTMENT-CALIBRATION-01 | 固定C1へのA SCORE_POINT_V2 utility補正 | PR61_MERGED / COMPLETED_NEGATIVE_DEVELOPMENT_RESULT / NOT_REPLICATED | 正式weight未採用、旧数値・時系列修正の記録を維持 |
| GROWTH-TREND-ANALYSIS-01 | outcome-free得点観測から開催・日数粒度を診断 | PR62_MERGED / DEVELOPMENT_SELECTED_GRANULARITY | MEETING_DELTA_LAG_1維持、修正版36生成物byte-exact再現、正式STAT未採用 |
| GROWTH-TREND-ADJUSTMENT-CALIBRATION-01 | 固定MEETING_DELTA_LAG_1のC1 utility補正 | PR63_MERGED / COMPLETED_NEGATIVE_DEVELOPMENT_TRANSFER | GLOBAL_LINEAR_WEIGHT_NOT_ADOPTED、P99=3.03、k=34/w=+0.34、2025 NOT_TRANSFERREDを維持 |
| STAT-35-DATA-READINESS-AUDIT-01 | 保存済み上がりRawの抽出・識別・取得時点監査 | COMPLETED_PR64_MERGED | identity blocker 0、BLOCKED_INSUFFICIENT_RAW_HISTORY、DATA_QUALITY_ONLY |
| STAT-35-STORAGE-BACKFILL-01 | current上がり・import別観測保存とRaw backfill基盤 | IMPLEMENTATION_REVIEW_MERGE_COMPLETED_PR65 | 本番schema適用済み、2022-2025処理範囲の保存・照合完了、gap/skip/MISSINGは残る |
| STAT-35-PRODUCTION-PREFLIGHT-01 | READ ONLY構造確認・plan・適用手順作成 | COMPLETED_PR66_MERGED | 当工程の本番書込み0という記録は履歴として維持 |
| STAT-35-PRODUCTION-MIGRATION-01 | 対象1本の本番DDL・適用後READ ONLY照合 | REVIEW_COMPLETED_PR67_MERGED | Migration batch 14、再適用・構造再監査なし |
| STAT-35-PRODUCTION-BACKFILL-DRYRUN-01 | 対象1日の保存前READ ONLY試行 | COMPLETED_ONE_DAY_2024_12_31 | 75 import成功、観測/現在値補完予定490/490、batchなし |
| STAT-35-PRODUCTION-BACKFILL-PILOT-01 | 同日正式保存・保存照合・保存後dry-run | REVIEW_COMPLETED_PR68_MERGED | BatchRun 120、実増分490/490、保存後予定0/0、今回終端でも保存行不変 |
| STAT-35-PRODUCTION-BACKFILL-2022-2025-01 | pilot日を除く月別正式保存・照合・保存後dry-run | REVIEW_COMPLETED_PR69_MERGED | 48区間・BatchRun 121-168、実増分899,506/716,347、全区間保存後予定0/0、既存記録保持・再実行なし |
| STAT-35-37-TRACK-CONTEXT-01 | 版付き構造マスタ・日付解決・未補正距離換算・実coverage | REVIEW_COMPLETED_PR70_MERGED | v1の42場44観測版・3解決場日と全原文/値/期間を保持。旧テスト・coverageは当時の記録。SCR全体未完了、予測利用未承認 |
| STAT-35-37-TRACK-CONTEXT-02 | 公式資料一巡・v2出典/期間追加・offline coverage比較 | REVIEW_COMPLETED_PR71_MERGED | 部分的拡充のレビュー完了。42場89観測版、距離14/10,660（+11）、期間不明10,646・競合/後退0という前工程記録を維持。歴史網羅未完了 |
| STAT-35-RACE-RELATIVE-01 | 保存済みagariの同一レース内相対値・v2未補正速度 | MERGED_REVIEW_COMPLETED_PR72 | 101,326レース/716,837行、5成果物一致と16件独立128M修正のレビュー完了。旧失敗・成功は15.44へ保持。実export/旧build再実行なし |
| STAT-35-PLAYER-HISTORY-01 | 選手×開催×classの相対値履歴・3/6/12開催窓 | PR73_IDENTITY_SCOPE_FIX_MERGED / PRIOR_DESCRIPTIVE_GENERATION_REPRODUCED | PR #73 MERGED。修正版生成/再現成功、新旧4データ不変・新6成果物一致等は15.45の過去記録。全成果物受入の判定記録未確認。窓最適化・予測利用未許可 |
| STAT-35-NEXT-USE-DESIGN-01 | 工程同期・C1追加効果の次用途仕様案・46 STAT/6エンジン索引 | MERGED_DESIGN_DRAFT_LIMITED_COMPARE_SCOPE_ADOPTED | PR74当時は設計案保存・入力準備のみ。今回の固定mean6限定比較条件だけ15.50で採用・実行済み。追加探索/DB/2026/正式採用は未承認 |
| STAT-35-C1-INPUT-01 | 固定C1への6観測開催mean入力準備 | PR75_CODE_REVIEW_MERGED_PRIOR_DIAGNOSTIC_PRESERVED | 15.47の全706,051出走・本人証拠不足の全NULL診断と当時の空allowlistを保持。旧処理再実行なし |
| STAT-35-C1-CONTEXT-01 | 固定対象と出走表由来メタデータの照合候補 | PR76_MERGED_FIXED_CANDIDATE_ACCEPTED_FOR_INPUT_PREPARATION | 705,048候補だけ限定受入、UNKNOWN class保留1,003と原本REVIEW_PENDINGを維持。再抽出/再生成なし |
| STAT-35-C1-INPUT-02 | 受入済みcontext登録・既存mean6生成/独立再現 | PR77_MERGED_FIXED_INPUT_ACCEPTED | 接続705,048・数値685,719・NULL20,332、保留1,003集合一致、14ファイル/manifest一致。過去実行記録は不変 |
| STAT-35-C1-COMPARE-01 | 固定mean6 C2対保存Outer C1 | PR78_MERGED_REVIEW_COMPLETED / INCREMENTAL_GATE_NOT_PASSED | C2だけ初回/独立再現、76ファイル一致。Hit@3差CI下限が0以下。旧C1保持、追加試行/2026/正式採用なし |
| STAT-35-C1-DIAGNOSTIC-01 | 保存モデル・予測の的中変化とutility寄与 | PR79_MERGED_REVIEW_COMPLETED | 50,078レース/356,209出走を保持、14成果物/manifest一致。事後診断のみ、新規学習・予測・CI/Gateなし。15.51参照 |
| STAT-36-START-COUNT-01 | 保存PJ0315表示S回数 | PR81_MERGED_REVIEW_COMPLETED | 101,326レース/901,038行、14成果物一致。期間/基準時点未確認・予測利用未承認。抽出の停止記録を含め15.53参照 |
| STAT-36-C1-CANDIDATE-01 | 固定C1への表示S候補接続 | PR82_MERGED_GENERAL_TRAINING_NOT_AUTHORIZED | 706,051出走全数数値、15成果物/manifest一致。原契約は不変、別契約の今回比較限定許可は15.55、当時の生成実績は15.54 |
| STAT-36-C1-COMPARE-01 | 固定表示SのC1追加development比較 | PR83_MERGED_REVIEW_COMPLETED_NOT_ADOPTED | 独立2run/76成果物一致、選択0.1/0.1、追加Gate未達・C1維持、補助STAT01 PASS。旧C1再学習0、時点UNKNOWN/正式採用/LIVE/2026禁止、15.55参照 |
| STAT-17-C1-COMPARE-01 | 固定C1 history4由来多様性指数の追加比較 | PR84_MERGED_REVIEW_COMPLETED_NOT_ADOPTED | 17項目/旧Outer C1基準/独立2run・76意味ファイル一致。追加Gate優越未達・C1維持、補助STAT01 PASS。15.56参照、旧S/mean6再試行なし |
| STAT-01-C1-SCORE-GAP-01 | 固定C1全出走raw平均との差の追加比較 | PR85_MERGED_REVIEW_COMPLETED_NOT_ADOPTED | 独立2run/76意味ファイル一致、選択0.1/0.1、主Gate非劣性/年別/優越未達・C1維持、補助STAT01 FAIL。旧Outer C1再学習0・2026禁止。P3残差未解決。15.57参照 |
| C1-STAT10-ABLATION-01 | 固定C1からSTAT-10だけ除く15項目再学習比較 | COMPLETED_AWAITING_REVIEW_NOT_ADOPTED | 原16項目厳密照合・99669レース/706051出走維持。独立2run/実列挙76意味ファイル一致、旧C1再学習0。選択0.1/0.1、主Gate非劣性/年別/優越未達・C1維持、補助STAT01 PASS。15.58参照 |
| STAT-36-OBSERVATION-01 | 全import版のスタート候補表示観測 | PR80_MERGED_PAGE_V3_SIGNATURE_V2 | 旧v1:127,121版/900,049行。署名v2・ページ判定v3は人工検証のみ、実データ再生成なし。startはNULL・初手/予測利用未成立。15.52参照 |
| BT-04 | freeze後holdout評価 | BLOCKED | 2026 CLOSED |
| BT-05 / LIVE | 未来レース事前予測→結果後評価 | BLOCKED | NOT STARTED |

---

# 9. Statistics Feature Foundation

## 9.1 主要merged PR

- PR #22: `feature:statistics foundation stat01 existing db`
- PR #23: `feature:statistics batch02 player history existing db`
- PR #24: `fix:statistics batch02 bounded memory`
- PR #25: `feature:statistics batch03 existing db`
- PR #26: `feature:statistics batch04 existing db`
- PR #27: `feature:statistics batch05 existing db`

この工程により、現在のBT対象STATについて、発走前時点を意識した既存DBベースのfeature生成・監査基盤が整備された。

## 9.2 現在の原則

- 対象レース発走前に存在した情報のみを使用する。
- historyは対象レースより前だけを使用する。
- 欠損を能力0・最下位とみなさない。
- status / quality / input_as_of / source / calculation_versionを監査可能にする。
- 実データ生成時はbounded memoryを維持する。

---

# 10. BT-01 — STAT-01 Baseline

## 10.1 状態

`COMPLETED`

## 10.2 正式run

```yaml
backtest_run_id: 1
run_uuid: 73087af0-7796-4ea0-85b8-d8b6b12d088a
backtest_code: BT-01
prediction_rule: STAT01-RACE-SCORE-RANK-v1
holdout_policy: BLOCK_AFTER_2025-12-31
source_manifest_hash: b2848ab16931999a5a75529d10bd86f6b3b996aba37a4192f06f978db0c8bb97
target_races: 101326
predicted_races: 99793
excluded_races: 1533
prediction_rows: 706907
errors: 0
```

## 10.3 Folds

- `DEV_2022`
- `WF_2023`
- `WF_2024`
- `WF_2025`

## 10.4 Baseline rule

- score: `RACE_SCORE_RAW`
- rank: 保存済み `RACE_SCORE_RANK`
- `RACE_SCORE_RANK`はcompetition rank
- rank1 tieは分解しない
- top3境界tieも強制分解しない
- predictionはlabel参照前にfreezeする

## 10.5 主要OPERATIONAL winner hit

- 2022: 約 `0.38768`
- 2023: 約 `0.38719`
- 2024: 約 `0.38700`
- 2025: 約 `0.37770`

## 10.6 再実施ルール

BT-01 baselineを別の理由なく作り直さない。

再実行を許可するのは、例えば次の場合だけ。

- STAT-01 calculation version変更
- source snapshot契約変更
- baseline prediction rule version変更
- audit defect修正で成果物の正当性が失われた場合

---

# 11. BT-02 — Incremental Signal Evaluation

## 11.1 状態

`COMPLETED`

## 11.2 正式Production run

```yaml
run_id: 5
run_uuid: 8e81ae0d-8018-4d99-b31d-203d8076e6cb
status: SUCCEEDED
folds: 3
models: 432
metrics: 648
effect_bins: 668
errors: 0
source_manifest_hash: 92aa8439775101c4f9d190d829b8a0f3e3702fd8646101b66a42b68babb79e6d
outcome_snapshot_manifest: a4b1800095b22fe0ae40216ce90243c7e80a0cf652a96e328c45223160c3dad9
bootstrap_iterations: 2000
bootstrap_seed: 20260812
```

Fold:

- `WF_2023`
- `WF_2024`
- `WF_2025`

## 11.3 Labels

- `IS_WIN`
- `IS_TOP2`
- `IS_TOP3`

BT-02は単なる「勝率」評価ではなく、

**1着境界 / 2着以内境界 / 3着以内境界**

への増分予測力を評価している。

したがってGoal 1とGoal 2の候補選定を現在の12 STATについて相当程度実施済みであり、

**同じ12 STATについて広範なfeature discoveryをゼロからやり直さない。**

## 11.4 現在のBT-02総括

### 強い増分予測力

- `STAT-39`
- `STAT-42`
- `STAT-07`
- `STAT-32`

### 安定した有用性

- `STAT-08`

### 中程度

- `STAT-23`
- `STAT-24`
- `STAT-11`

### 線形だけでは扱いに注意

- `STAT-31`
- `STAT-12`
- `STAT-10`
- `STAT-26`

重要:

`STAT-31` は単純線形評価だけで無効と判断しない。BT-03で明確な非線形構造が確認された。

## 11.5 旧BT-03Dを再実施しない理由

BT-02ですでに `IS_WIN / IS_TOP2 / IS_TOP3` の増分予測力を評価している。

そのため、旧案の

`BT-03D-PREDICTIVE-SELECTION`

として再度広範なjoint model / ablation中心のfeature discoveryを行う工程は、

**Goal 1 / Goal 2を重複してやり直す可能性が高いためSUPERSEDEDとする。**

相関・冗長性・ablationは今後も、

**scoring最適化の診断・正則化・候補比較**

として利用してよいが、独立した必須工程にはしない。

---

# 12. BT-03 — Bin Effect Analysis

## 12.1 状態

- BT-03A: `COMPLETED`
- BT-03B: `COMPLETED_AS_ANALYSIS`
- BT-03C: `COMPLETED_AS_ANALYSIS`

## 12.2 関連PR

- PR #36: BT-03 bin effect foundation
- PR #37: BT-03 bin effect execution core
- PR #38: BT-03 bin effect production
- PR #39: centered residual bounded-memory fix

主要merge後main:

```text
PR #36後: 2e0f29ea95a225050855e94be5e7b22f9dfac202
PR #37後: 4415d549a4575ab0ac3d74ff59906b39dd141bae
PR #38後: 1a3debdf60f809cb50684a12f46e480bb52daced
PR #39後: 9bef716007d983d5b56d049f4794dbc23055d7cc
```

## 12.3 正式Production run

BT-03 run 6は初回128M OOM後、PR #39のbounded-memory fixを適用してresumeし、正式完了した。

```yaml
run_id: 6
run_uuid: 28144da5-ad1b-4cc7-a17d-cb456fcf5719
status: SUCCEEDED
scope_count: 72
effect_count: 2004
completed_scope_count: 72
error_count: 0
resume_count: 1
effect_manifest_hash: 1bcf2eb3ff4d7857e16622d5d719f6034764dd1785f4dbd7ceafbb63069c88cb
```

Centered residual status:

- `AVAILABLE / OBSERVED`: 1980
- `NO_EVALUATION_ROWS`: 15
- `SPARSE_BOOTSTRAP_UNSUPPORTED`: 9

## 12.4 BT-03固定source fingerprints

```yaml
run_fold: aa26d72c206b9d70401e4649c401d390818cbd5d292d08881d047908270f02f7
specs: d9a0c4363ba3f370ff7925be525d6fd8b6cc6cc41ed6010c4c6f279f6fe7f359
models: 26d831a05a668d95613a90e56e9c465b3126fda7be4d2b96157253f8882d4cd1
metrics: e483ab582cdad2b2996f65b86bcb50e68c9a22ade2cf683feeadcb1cf9acfb02
bins: 8d9030775176c59d5a13cc5c67b7f080fb3c3bd7cddca071c131927d9f2fef7c
artifact_manifest: 5178fd7207cb9d043fdc1c7b6808d3f3a59a565f18298dc2b89c1353d11cb1fa
source_manifest: f114e079768748cf0bf84746471bb7e84ea304e5fcb61db83d59b79940e45d98
```

## 12.5 Bin contract

Numeric bin:

```text
(lower_bound, upper_bound]
```

Category:

- training categoryは固定category bin
- 未観測categoryは `UNSEEN_CATEGORY`

空evaluation bin:

- `NO_EVALUATION_ROWS`

## 12.6 BT-03B/Cの主要開発知見

以下は **development findings** であり、final frozen scoring thresholdではない。

### STAT-42

最も明瞭な方向構造。

- 低値: 強いプラス
- 中央付近: hold
- 高値: 強いマイナス

### STAT-07

- 低値: 強いプラス
- 中央: hold
- 高値: 強いマイナス

### STAT-08

- 低値: プラス
- 中央: hold
- 高値: マイナス

### STAT-32

- 低値: 強いプラス
- 中央: hold
- 高値: 強いマイナス

### STAT-31

単調ではなく明確な非線形。

- 極端な低値: マイナス
- 中心帯: プラス
- 極端な高値: マイナス

したがって線形係数だけで除外してはいけない。

### STAT-11

category別に方向が異なる。

- category 0.0: プラス
- 0.1: 強いマイナス
- 0.2: マイナス
- 高category: データ不足を含む

### STAT-12

大半はhold。

- 最上位tailのみ安定したマイナス候補

### STAT-23

- 最低binはプラス
- 一部中間binはマイナス候補
- model directionとの整合性は主要STATより弱い

### STAT-24

- 高bin側の一部がプラス
- 中間の一部がマイナス
- STRICT / OPERATIONAL差を考慮する

### STAT-26

fold間でbin数が同じでないため、

**bin_indexを年跨ぎで直接同一視しない。**

raw-value semantic alignmentが必要。

### STAT-39

BT-02では強いが、

**BT-03ではcohort-dependent。**

STRICT / OPERATIONALで同じthresholdを無条件共有しない。

### STAT-10

現時点では弱い。

初期scoringの主要加点源として優先しない。

## 12.7 重要なリーク注記

上記BT-03B/Cの横断的知見は2023～2025を見て整理したdevelopment analysisである。

したがって、これらを手作業で固定して「2024を完全OOS」と主張することは禁止する。

---

# 13. BT-03E-01 — Historical Forward Coarse Scoring

## 13.1 状態

```yaml
engineering_status: COMPLETED
scoring_hypothesis_status: REJECTED_FOR_ADOPTION
```

## 13.2 関連PR

- PR #40: `feature:backtest bt03e historical forward scoring`
- merged head: `89d3dce2491ea1fd0cdd6d653df92a2d802882e6`
- merge commit: `82d394ec014b46ca4792858fbe9fe35eaa7434d5`

## 13.3 目的

BT-02 / BT-03の知見を実際の整数加点・減点へ変換し、

**2023でpointsを決定 → freeze → 2024へ適用**

してSTAT-01 baselineを超えるか確認する。

## 13.4 この工程で試した仮説

これは **最終仕様ではない**。

### Rule source

- run 6
- `WF_2023`
- `OPERATIONAL`

### Direction

同一binの `IS_WIN / IS_TOP2 / IS_TOP3` の3labelが同方向の場合だけ、

- `+2`
- `+1`
- `0`
- `-1`
- `-2`

へ縮約。

### STAT weight grid

```text
0, 5, 10, 20, 30, 40
```

### STAT-01 base-step grid

```text
0, 5, 10, 20, 30, 40
```

### Optimization

deterministic multi-start coordinate descent。

### Primary selection objective

1. `POSITION_HIT_RATE_AT_3`
2. `WINNER_HIT_AT_1`
3. `EXACT_TOP3_SET_RATE`
4. `TOP3_COVERAGE_AT_3`
5. `EXACT_ORDERED_TOP3_RATE`
6. complexity
7. canonical key

## 13.5 正しいSTAT-01 contract

BT-03E-01最終版では、独自lower-count rankを廃止し、

**保存済み `RACE_SCORE_RANK`**

を使用する。

試験用base points:

```text
(max_stat01_rank - stat01_rank) * base_step
```

これはBT-03E-01のcoarse hypothesisであり、

**最終統計エンジンのbase formulaとしてfreezeされてはいない。**

## 13.6 Missing

missing / ineligible / NO_HISTORY等は、

**このscoring experimentでは contribution = 0**

とする。

欠損自体を減点理由にしない。

## 13.7 Tie

Point Engine:

1. total score DESC
2. STAT-01 raw DESC
3. bike number ASC

Baseline:

1. STAT-01 raw DESC
2. bike number ASC

## 13.8 Metric denominator

### Position 1

公式1着が一意なrace。

### Position 2

公式2着が一意なrace。

### Position 3

公式3着が一意なrace。

### POSITION_HIT_RATE_AT_3 / EXACT_ORDERED_TOP3

公式1・2・3着がすべて一意なrace。

dead heat等を勝手に一意順位へ変換しない。

## 13.9 Source integrity

最終merged版は次を満たす。

- PostgreSQL READ ONLY transaction
- START / END feature fingerprint preflight
- START / END run6 effect full verification
- persisted effect artifactから `Bt03EffectHasher` を再計算
- scope manifest再検証
- 12 selected scopes / 333 selected effectsのfull verification
- START / END semantic digest一致
- outcome partition seal検証
- 2025 / 2026 access 0
- atomic artifact bundle publication
- partial artifact publication防止

BT-03E effect semantic digest:

```text
c57826c082233b716e831979cb4089bbb6f4bf3ddb31ead121eb9b1cf3941cd6
```

## 13.10 選択candidate

```yaml
base_step: 30
STAT-23: 5
STAT-31: 5
STAT-07: 0
STAT-08: 0
STAT-10: 0
STAT-11: 0
STAT-12: 0
STAT-24: 0
STAT-26: 0
STAT-32: 0
STAT-39: 0
STAT-42: 0
evaluated_candidates: 378
```

このcandidateは **最終配点ではない。**

## 13.11 2024 OOS結果

| Metric | STAT-01 Baseline | Point Engine | Delta |
|---|---:|---:|---:|
| Winner / Position1 | 0.386040 | 0.384132 | -0.001908 |
| Position2 | 0.233968 | 0.232415 | -0.001553 |
| Position3 | 0.177293 | 0.176417 | -0.000877 |
| Position Hit@3 | 0.265828 | 0.264364 | -0.001464 |
| Exact Ordered Top3 | 0.042572 | 0.042372 | -0.000200 |
| Exact Top3 Set | 0.150603 | 0.150365 | -0.000238 |
| Top3 Coverage | 0.614522 | 0.614456 | -0.000066 |
| Exact Top2 Set | 0.245320 | 0.244130 | -0.001190 |
| Top2 Coverage | 0.534210 | 0.533615 | -0.000595 |
| NDCG@3 | 0.625387 | 0.624958 | -0.000428 |

2024 denominator:

```yaml
unique_position1_races: 25158
unique_position2_races: 25106
unique_position3_races: 25094
ordered_top3_eligible_races: 25040
```

Tie:

```yaml
baseline_tied_races: 564
baseline_tied_entries: 1144
engine_tied_races: 3052
engine_tied_entries: 6343
engine_stat01_raw_tiebreak_groups: 3073
```

## 13.12 結論

**現在の12 STATが無効だった、という結論ではない。**

否定されたのは、

> 3labelを共通方向へ縮約し、STATごとに単一weightを与える粗い整数加点方式

である。

特に、

- BT-02では強いSTATが複数確認済み
- BT-03では明確な非線形bin効果が確認済み
- それでもBT-03E-01では多くのSTAT weightが0になった

ことから、

**情報の圧縮方法・score表現・最適化方法が粗すぎる可能性**

を次工程で検討する。

## 13.13 再実施禁止

次の条件をそのまま使ったBT-03E-01を、結果確認のためだけに再実行しない。

```text
3 labels共通direction
×
STAT単一weight
×
[0,5,10,20,30,40]
×
同じcoordinate descent
```

再実行するなら、明示的なbug修正・source drift確認等の理由が必要。

---

# 14. SUPERSEDED: 旧BT-03D Predictive Selection

## 14.1 状態

`SUPERSEDED`

## 14.2 経緯

一度、

- joint dataset
- ridge logistic joint model
- ablation
- predictive selection

を中心とする `BT-03D-PREDICTIVE-SELECTION` 実装を開始した。

その後、工程を再確認し、

- BT-02ですでにIS_WIN / IS_TOP2 / IS_TOP3の増分予測力を評価済み
- 目的は再度feature importanceを作ることではない
- Goal 3の加点・減点最適化へ進む必要がある

と判断し、途中実装を破棄した。

## 14.3 禁止

将来のChatGPT / Codexは、

**旧BT-03Dを「未実施だから次にやるべき」と判断してはいけない。**

必要なcorrelation / overlap / ablationはBT-03E-02内の診断として必要最小限に行う。

---

# 15. BT-03E-02 — Scoring Redesign

## 15.1 状態

```yaml
working_name: BT-03E-02-SCORING-RULE-REDESIGN
name_status: FROZEN_FOR_V1
phase_status: DESIGN_FROZEN
codex_implementation: ALLOWED
implementation_status: NOT_STARTED
performance_status: NOT_EVALUATED
goal_3_status: NOT_COMPLETED
approved_decisions:
  - DECISION_01
  - DECISION_02
  - DECISION_03
  - DECISION_04
  - DECISION_05
  - DECISION_06
  - DECISION_07
  - DECISION_08
  - DECISION_09
  - DECISION_10
  - DECISION_10_A
  - DECISION_10_B
  - DECISION_11
  - DECISION_12
```

Decision 01～12および補助Decision 10-A / 10-Bは、BT-03E-02 v1の実装前契約として承認・freeze済みである。

これは設計完了を意味するが、モデル実装、係数fit、性能評価、Goal 3達成を意味しない。実装時に契約変更が必要になった場合は、理由、影響範囲、versionを明示して本MASTER PLANを先に更新する。

## 15.2 Decision 01 — Score Representation

- 内部scoreは符号付きcontinuous valueとする。
- 全score軸で高いscoreほど上位評価とする。
- 人工的な上限・下限を設けない。
- ランキングに表示用丸め値を使用しない。
- per-STAT contributionを監査可能にする。
- missingと数値0を区別する。
- 表示用pointsと内部scoreは別概念にできる。
- BT-03E-01の粗い整数加点・減点方式へ戻さない。

BT-03E-01の整数pointsは最終仕様ではなく、continuous score採用後のprediction contractでは `NOT_APPLICABLE / SUPERSEDED` とする。

## 15.3 Decision 02 — 3 Score Channel Architecture

独立した次の3 channelを保持する。

- `WIN_SCORE`
- `TOP2_SCORE`
- `TOP3_SCORE`

各channelは独立したcontinuous contributionを持ち、その後に別途 `RANKING_SCORE` を生成する。WIN / TOP2 / TOP3の情報を、`RANKING_SCORE` 生成前に共通directionへ圧縮しない。

## 15.4 Decision 03 — Hierarchical Regularized Bin Score

採用方式は `Hierarchical Regularized Bin Score` とする。

- STATごとの単一weightだけに縮約しない。
- bin固有効果およびchannel固有bin効果を保持する。
- 基本parameterは `STAT × BIN × CHANNEL` 単位のcontinuous coefficientとする。
- 全parameterを無制限な完全自由parameterにはしない。
- hierarchical regularizationおよびshrinkageを使用する。
- numeric ordered binにはsmoothnessを許可する。
- monotonicityを強制しない。
- missingは独立状態であり、数値0または0 binではない。

概念parameter:

```text
beta[stat, bin, channel]
```

## 15.5 Decision 04 — STAT単位weight

明示的な `STAT weight × BIN weight` 方式や、`STAT-07 weight = 0.8` のようなprediction用独立STAT乗算weightは採用しない。

`beta[stat, bin, channel]` を直接prediction contributionとし、STAT全体はgroup-level regularization / shrinkageで制御する。STAT importanceはprediction用追加weightではなく、診断指標として算出する。

各training fold、各 `STAT × channel` groupで、training-local supportから次を計算する。

```text
p_b = support_b / sum_b(support_b)
```

identifiability constraint:

```text
sum_b(p_b * beta_b) = 0
```

accepted parameter update後は、必ず次のdeterministic projectionを行う。

```text
m = sum_b(p_b * beta_b)
beta_b <- beta_b - m
```

- supportはtraining dataだけから計算する。
- missing / `NO_HISTORY` / `INSUFFICIENT_SAMPLE` はsupportへ含めない。
- support = 0のbinはactive coefficientとして無理に学習しない。
- validation supportで再中心化しない。
- validation / outer dataでprojectionを再計算しない。
- final effective betaとtraining supportをartifactへ保存する。

## 15.6 Decision 05 — Nonlinear STAT

採用方式は `Regularized Piecewise-Bin Nonlinear Model` とする。

- 全STAT線形モデルにはしない。
- 全STATへmonotonic constraintを強制しない。
- v1ではraw-value polynomial / splineモデルを新規導入しない。
- BT-03 bin構造を使って非線形性を表現する。
- WIN / TOP2 / TOP3で異なるshapeを許可する。
- numeric binにはadjacent smoothness penaltyを許可する。
- category binにはadjacent smoothnessを掛けない。

STAT固有契約:

- `STAT-31`: `NON_MONOTONIC_ALLOWED`。中央プラス・両端マイナスを手作業で固定せず、training dataから学習する。
- `STAT-26`: fold / year間でbin indexを直接同一視せず、raw-value semantic alignmentを維持する。
- `STAT-39`: cohort dependencyを維持し、STRICT / OPERATIONAL等の意味差を無条件統合しない。

既存development findingsをfuture OOS結果として扱わない。

## 15.7 Decision 06 — Redundancy / Correlation

採用方式は `Soft Redundancy Control + Temporal Ablation` とする。

- 相関が高いだけでSTATを削除しない。
- 固定correlation thresholdによるpre-filterを採用しない。
- STAT-level correlation、bin-level overlap、channel別redundancyの診断を許可する。
- leave-one-STAT-outを必須診断とする。
- 必要な相関groupについてgroup ablationを行える。
- 局所bin shrinkageを許可する。
- temporal validationで除外時性能が改善した場合だけSTAT除外を許可する。

BT-03E-02 v1では独立した `lambda_REDUNDANCY` や新しい大量hyperparameterを追加しない。L2、STAT group shrinkage、numeric smoothness、temporal ablationを基本制御とし、correlation / overlapは主に診断情報として扱う。旧BT-03D predictive selectionは復活させない。

## 15.8 Decision 07 — Optimization Objective

採用方式は `Pareto-Constrained Multi-Objective` とする。

Primary Objective:

- `WINNER_HIT_AT_1`
- `POSITION_2_ACCURACY`
- `POSITION_3_ACCURACY`
- `POSITION_HIT_RATE_AT_3`

4指標を単一の固定weight objectiveへ圧縮しない。candidateごとにmetric value、baseline value、deltaを保持し、Pareto dominanceを使用する。一つのPrimary改善で他のPrimaryの重大悪化を自動相殺しない。

Supporting:

- `EXACT_ORDERED_TOP3_RATE`
- `EXACT_TOP3_SET_RATE`
- `TOP3_COVERAGE_AT_3`
- `EXACT_TOP2_SET_RATE`
- `TOP2_COVERAGE_AT_2`
- `NDCG_AT_3`

Complexityは性能が同等の場合の後順位判定にのみ使用する。

## 15.9 Decision 08 — Optimization Algorithm

採用方式は `Deterministic Two-Stage Regularized Ranking Optimization` とする。

### Stage 1

WIN / TOP2 / TOP3を独立fitする。labelは既存BT-02の固定済み `IS_WIN` / `IS_TOP2` / `IS_TOP3` semanticsをそのまま再利用し、BT-03E-02側でfinish rankから独自labelを生成しない。

各channel / raceで次を定義する。

```text
P = entries with channel label == 1
N = entries with channel label == 0
generated_pairs = P × N
```

positive-positiveおよびnegative-negative pairは生成しない。`P`または`N`が空なら、そのrace / channelをpairwise surrogate lossから除外し、exclusion reason、eligible race denominator、excluded race countを監査する。3 channelのいずれかでeligible race count = 0ならfail closedとする。dead heat、abnormal result、ineligible resultをBT-03E-02側で一意順位へ変換しない。

positive `p`、negative `n`について、overflow-safe softplusで次を計算する。

```text
d = SCORE(p) - SCORE(n)
pair_loss = log(1 + exp(-d))
race_loss = sum(pair_loss) / pair_count
channel_loss = eligible race_lossのrace-equal mean
combined_loss = (WIN_loss + TOP2_loss + TOP3_loss) / 3
```

必ずpair mean within race、race equal meanの順とし、pair数の多いraceへ大きなweightを与えない。

各channel raw score:

```text
CHANNEL_SCORE_RAW(entry)
= STAT01_ANCHOR(entry)
+ sum(available incremental STAT contributions)
```

missing / `NO_HISTORY` / `INSUFFICIENT_SAMPLE` 等は `stored contribution = null`、`included_in_sum = false`、statusは明示値とする。observed numeric zeroとmissingを区別し、missing contributionをobserved zeroとして保存しない。

coefficient optimizerにはdeterministic Proximal Gradient / FISTA系を採用する。random search、genetic algorithm、BT-03E-01の粗いcoordinate descent流用は採用しない。max iteration、convergence tolerance、line-search rule、initial step、Lipschitz関連constant、restart ruleは `OPTIMIZER_NUMERIC_SOLVER_CONSTANTS_BEFORE_FIRST_FORMAL_EXECUTION` として未凍結である。最初の正式development実行より前にimplementation PRで `OPTIMIZER_VERSION` とともに一意にfreezeする。

- validation-based early stoppingは禁止する。
- solver数値定数を変える場合はoptimizer versionを更新する。
- 同一optimizer versionで数値定数をsilent変更しない。

### Stage 2

```text
RANKING_SCORE
= alpha_WIN  * normalized_WIN_SCORE
+ alpha_TOP2 * normalized_TOP2_SCORE
+ alpha_TOP3 * normalized_TOP3_SCORE
```

制約:

```text
alpha_WIN >= 0
alpha_TOP2 >= 0
alpha_TOP3 >= 0
alpha_WIN + alpha_TOP2 + alpha_TOP3 = 1
```

non-negative convex combinationとし、alphaを手作業で決めない。deterministic simplex candidate searchで決定する。Primary hit metricsをgradientで直接最適化せず、Stage 1はsmooth surrogate loss、Stage 2はinner OOF上の実際のPrimary Metricsでcandidateを選択する。Outer resultはalpha選択に使用しない。

bounded-memory / streaming構造を必須とし、独立PHP processのbounded-memoryテストは `memory_limit=128M` を維持する。2026-09-22の最新方針では実データ処理の基本例を `php -d memory_limit=512M` とし、実測で調整する。本番を一律128Mへ限定しない。pair全件を巨大arrayへmaterializeせず、raceを読み、当該raceのpairでloss / gradientを更新し、race payloadを破棄する。bootstrapもrace payloadを不要に全複製しない。

## 15.10 Decision 09 — Overfitting

採用方式は `Nested Temporal Regularization Selection + One-SE Rule` とする。

正規化後のL2、STAT Group Shrinkage、Numeric Smoothnessを固定1:1:1で合成する。計算はchannelごとに行う。

active coefficient総数を`M`として、L2 penaltyを次とする。`M = 0`は不正model stateとしてfail closedとする。

```text
P_L2 = (1 / M) * sum_j(beta_j^2)
```

channel内active STAT group数を`G`、group `g`のactive bin数を`m_g`として、non-smooth group shrinkageを次とする。

```text
group_rms_g = sqrt((1 / m_g) * sum_{b in g}(beta_b^2))
P_GROUP = (1 / G) * sum_g(group_rms_g)
```

training上ordered numeric binの隣接edge集合を`E`として、smoothness penaltyを次とする。

```text
P_SMOOTH
= (1 / |E|)
* sum_{(b,b_next) in E}((beta[b_next] - beta[b])^2)
```

`|E| = 0`なら `P_SMOOTH = 0` とする。category bin、`UNSEEN_CATEGORY`、missing状態にはsmoothness edgeを作らない。

```text
P_COMPOSITE = P_L2 + P_GROUP + P_SMOOTH
OBJECTIVE = PAIRWISE_RACE_BALANCED_LOSS + lambda * P_COMPOSITE
```

penalty別weightを結果確認後に追加しない。`lambda_L2`、`lambda_GROUP`、`lambda_SMOOTH`、`lambda_REDUNDANCY`を独立探索しない。この式の変更はBT-03E-02 v1のversioned contract変更とし、実装より先にMASTER PLANを更新する。

単一共通lambdaをWIN / TOP2 / TOP3へ使用し、candidate gridを次でfreezeする。

```text
0, 1e-6, 1e-5, 1e-4, 1e-3, 1e-2, 1e-1, 1
```

One-SE bootstrap contract:

```yaml
unit: race
iterations: 2000
seed: 20260812
multiple_years: year_stratified
```

各replicateはvalidation year内でrace resamplingし、channelごとのrace-balanced lossを計算し、3 channelをequal weightで集約する。複数yearではreplicate単位でyear equal meanを作る。

```text
SE(lambda)
= 2000 bootstrap aggregate lossesのsample standard deviation

lambda_best
= point validation lossが最小のlambda

one_se_threshold
= loss(lambda_best) + SE(lambda_best)
```

`loss(lambda) <= one_se_threshold` を満たす最も大きいlambdaを選ぶ。同値でも大きいlambdaを優先する。Outer outcomeをlambda選択へ使用しない。結果確認後にgridを追加しない。

validation-based early stoppingは行わず、FISTA停止条件は数値収束条件だけとする。

alpha simplex contract:

```text
alpha = (k_win, k_top2, k_top3) / 20
k_win, k_top2, k_top3 >= 0
k_win + k_top2 + k_top3 = 20
step = 0.05
candidate_count = 231
adaptive_refinement = FORBIDDEN
```

degenerate channelがある場合はその`k = 0`だけを許可し、残るnon-degenerate channelでsum = 20を満たすcandidateだけを生成する。

最低限のcomplexity diagnostics:

- `non_zero_coefficients`
- `active_stat_groups`
- `coefficient_norm`
- `smoothness_measure`
- `regularization_lambda`
- `channel_alpha`

## 15.11 Decision 10 — Tie / Determinism

採用方式は `Full-Precision Deterministic Ranking` とする。

- 内部scoreはIEEE-754 binary64を使用する。
- 順位決定前のroundは禁止する。
- contributionをcanonical orderで加算する。
- 浮動小数点集計は `NEUMAIER_COMPENSATED_SUM_V1` を使用する。
- artifact serializationはC locale固定でbinary64 round-trip可能な `%.17g` 相当を使用する。
- `-0.0`はcanonical artifact上`0`へnormalizeする。
- NaN / +INF / -INFはERRORとする。
- epsilon以内をtieとみなさず、exact score comparisonを使用する。

Ranking tie-break:

1. `RANKING_SCORE DESC`
2. `NORMALIZED_WIN_SCORE DESC`
3. `NORMALIZED_TOP2_SCORE DESC`
4. `NORMALIZED_TOP3_SCORE DESC`
5. `STAT-01 RACE_SCORE_RAW DESC`
6. deterministic technical tie key ASC

bike number ASCをpredictive fallbackに使用しない。technical tie keyは次で固定する。

```text
SHA-256("BT03E02-TIE-v1|" + race_id + "|" + bike_number)
```

lowercase hexadecimalをlexicographic ASCで比較する。technical keyはprediction signalではなく、完全同値時の技術的全順序化である。technical fallbackを「モデルが区別できた」と扱わず、`technical_tiebreak_used = true` 相当を監査する。

最低限のtie diagnostics:

- `exact_ranking_score_tied_races`
- `exact_ranking_score_tied_entries`
- `resolved_by_win_score`
- `resolved_by_top2_score`
- `resolved_by_top3_score`
- `resolved_by_stat01_raw`
- `technical_tiebreak_races`
- `technical_tiebreak_entries`
- `minimum_score_gap`
- `score_gap_distribution`

## 15.12 Decision 10-A — Channel Scale Normalization

採用方式は `RACE_CENTERED_RMS_V1` とする。

channel `c`、training race集合`R`、race `r`のentry数`n_r`、raw score `s[r,i,c]`について、training race meanとvarianceを次で計算する。

```text
mu[r,c] = (1 / n_r) * sum_i(s[r,i,c])
v[r,c] = (1 / n_r) * sum_i((s[r,i,c] - mu[r,c])^2)
```

各raceを等weightとしてtraining scaleを計算する。

```text
SCALE[c] = sqrt((1 / |R|) * sum_r(v[r,c]))
```

Validation / Outer / Final applicationでは、trainingでfreezeしたscaleだけを使う。

```text
NORMALIZED_SCORE[r,i,c]
= RAW_SCORE[r,i,c] / FROZEN_TRAINING_SCALE[c]
```

validation race自身のmean、variance、RMSを使って再標準化しない。「race-centered」はtraining scale推定時のrace varianceを意味し、validation scoreからvalidation race meanを引く契約ではない。

`SCALE[c] <= 0`またはfiniteでない場合は `DEGENERATE_CHANNEL` とし、そのchannelのalpha = 0を強制する。epsilonを足して無理に有効化せず、残るnon-degenerate channelだけでalpha sum = 1を満たすcandidateを生成する。

artifactへ最低限次を保存する。

- `channel`
- `training_race_count`
- `training_entry_count`
- `scale_method`
- `scale_value`
- `degenerate_status`
- `calculation_version`

`scale_method` / normalization versionは `RACE_CENTERED_RMS_V1` に固定する。

## 15.13 Decision 10-B — STAT-01 Anchor

採用方式は `Fixed STAT-01 Anchor + Learned Incremental Residuals` とする。STAT-01を再学習対象として消してはならない。

```text
STAT01_ANCHOR = RACE_SCORE_Z
anchor_coefficient = 1.0 (fixed)

WIN_SCORE_raw  = STAT01_ANCHOR + WIN incremental contributions
TOP2_SCORE_raw = STAT01_ANCHOR + TOP2 incremental contributions
TOP3_SCORE_raw = STAT01_ANCHOR + TOP3 incremental contributions
```

STAT-01 Anchorはregularization対象外とし、他STATのbetaだけを学習する。v1ではSTAT-01 RAW、RANK、percentile、Zを複数featureとして同時predictor投入しない。prediction anchorは `RACE_SCORE_Z` だけとし、他のSTAT-01 featureはbaseline comparison / audit / tie / diagnostics用途とする。

全incremental betaが0なら次を満たす。

```text
WIN_SCORE_RAW  = STAT01_ANCHOR
TOP2_SCORE_RAW = STAT01_ANCHOR
TOP3_SCORE_RAW = STAT01_ANCHOR
```

3 channelが同じtraining dataで同値ならscaleも同値となる。`sum(alpha) = 1`のため、最終`RANKING_SCORE`はSTAT-01 Anchorの正のscale変換となり、STAT-01 rawの順位順序と一致しなければならない。この `Baseline Nesting Contract` をfreezeする。

必須Baseline Equivalence Test:

- raw scoreが異なるentry間の順位一致
- raw score tie group一致
- rank1 set一致
- top3 boundary set一致

STAT-01 standard deviation = 0の場合は欠損補完と区別し、`STAT01_ANCHOR = 0.0`、`anchor_status = ZERO_VARIANCE` とする。STAT-01 missingを0補完してはならない。

## 15.14 Decision 11 — Development Validation

採用方式は `Expanding-Window Nested Temporal Validation + Final Development OOF Refit` とする。2022～2025はすべてdevelopment corpusであり、2024 / 2025をfinal untouched holdoutと呼ばない。

各Outer foldは必ず次の時系列境界を守る。

```text
Inner data only
-> lambda決定
-> selected lambdaでinner OOF channel prediction生成
-> training-local frozen scale適用
-> alpha candidates評価
-> inner OOFだけでalphaを一意決定
-> alpha freeze
-> Outer refit
-> freeze済みalphaでOuterを1回だけ評価
-> Acceptance Gate
```

Outer resultを見て同Outer foldのlambdaまたはalphaを再選択してはならない。

### Outer 2024

```text
Inner: Train 2022 -> Validation 2023でlambda / alpha選択
Refit: 2022-2023
Outer Development Validation: 2024を1回だけ評価
```

### Outer 2025

```text
Inner A: Train 2022 -> Validation 2023
Inner B: Train 2022-2023 -> Validation 2024
Inner A / B OOFだけでlambda / alpha選択
Refit: 2022-2024
Outer Development Validation: 2025を1回だけ評価
```

lambdaを先に決め、その後だけ0.05 simplexのalpha candidateをinner OOF predictionで評価する。lambda × alphaをjoint exhaustive searchしない。

inner alphaはCandidate vs STAT-01のpaired metricsだけを用い、次の順序で一意に決定する。

1. Primary Pareto dominance
2. `worst_primary_delta`最大
3. `POSITION_HIT_RATE_AT_3` delta最大
4. `EXACT_ORDERED_TOP3_RATE` delta最大
5. `EXACT_TOP3_SET_RATE` delta最大
6. `NDCG_AT_3` delta最大
7. lower model complexity
8. canonical alpha key ASC

```text
worst_primary_delta
= min(
  delta WINNER_HIT_AT_1,
  delta POSITION_2_ACCURACY,
  delta POSITION_3_ACCURACY,
  delta POSITION_HIT_RATE_AT_3
)

canonical_alpha_key
= sprintf('%02d-%02d-%02d', k_win, k_top2, k_top3)
```

Decision 12のNon-Inferiority、Superiority、Temporal Stability、Supporting、Tie Quality Gateはinner alpha selectionには使用しない。これらはinnerで選択・freezeされたcandidateのOuter Development Validation結果だけを評価する。

次はすべて各foldのtraining portionだけで決定し、validation / outer dataで再推定しない。

- bin boundaries
- category definitions
- support
- beta
- centering / projection
- lambda
- channel scale
- STAT-26 semantic alignment
- STAT-39 cohort-specific basis
- ablation定義に使うcorrelation / overlap diagnostics

既存BT-02 / BT-03 artifactを再利用する場合も、対象foldのtraining identityと一致することをfull verificationする。別foldのbinやeffectを便宜的に流用しない。

candidateとSTAT-01 baselineはsame race set、same metric denominator、same outcome eligibility contractで比較する。追加STAT missingだけを理由にcandidate側だけraceを除外しない。STAT-01 Anchor自体が利用不能なraceは既存BT-01 eligibility contractに従う。

Acceptance Gate通過後のFinal Development Fit:

```text
OOF 1: Train 2022 -> Validate 2023
OOF 2: Train 2022-2023 -> Validate 2024
OOF 3: Train 2022-2024 -> Validate 2025
```

このOOFだけで同じpre-registered algorithmを使って最終lambda / alphaを決定し、2022～2025でfinal bin / basis、beta、channel scaleをrefitしてfreezeする。この工程でも2026参照は禁止する。

2026-09-17のTACTICAL-HISTORY-FINAL-01では、この3組OOFをPR #55のC1順位別確率モデルへ接続する。
OOF-1/2を検証して再利用し、OOF-3全pathと2022～2025最終refitのみを新規学習する。
旧E02のalpha混合・RACE_CENTERED_RMS・channel scaleは適用せず、理由付きNOT_APPLICABLEとする。
実学習前の数値契約は `docs/tactical-history-final-01.md`。旧実験とGateは保持し、2026・LIVEは引き続き禁止。

## 15.15 Decision 12 — Goal 3 Acceptance Gate

採用方式は `Hierarchical Pre-Registered Acceptance Gate` とし、次の順で判定する。

1. Integrity Gate
2. Non-Inferiority Gate
3. Superiority Gate
4. Temporal Stability Gate
5. Supporting Metric Gate
6. Tie Quality Gate
7. Pareto / Maximin Selection

### Integrity Gate

次は絶対条件であり、1件でも失敗した場合は性能結果を無効とする。

- outcome leakageなし
- 2026 access = 0
- candidate / baseline cohortが完全paired
- Decision 11 fold contract遵守
- bins / scalesがtraining-local
- lambda / alphaがinner dataのみ
- Baseline Nesting Test PASS
- deterministic rerun artifact / hash一致
- NaN / INF = 0
- source fingerprint START / END一致
- outer result確認後に同foldを再調整していない

Primary metricsとdelta:

- `WINNER_HIT_AT_1`
- `POSITION_2_ACCURACY`
- `POSITION_3_ACCURACY`
- `POSITION_HIT_RATE_AT_3`
- delta = BT-03E-02 - STAT-01 baseline

### Non-Inferiority Gate

year-stratified paired race bootstrap 95% CIを使用し、全4 Primaryで次を要求する。

```text
margin = -0.0015
95% CI lower bound > -0.0015
```

### Superiority Gate

次のA、B、Cをすべて要求する。

- A: `POSITION_HIT_RATE_AT_3` の95% CI lower bound > 0
- B: WINNER / POSITION_2 / POSITION_3のうち最低1つで95% CI lower bound > 0
- C: Primary 4指標のうち最低3つでpoint estimate delta > 0

### Temporal Stability Gate

Outer 2024 / Outer 2025の各年について、全Primary metricで `delta >= -0.0030` を要求する。平均が良くても、一年度で下回ればFAILとする。

### Supporting Metric Gate

対象は次の6指標。

- `EXACT_ORDERED_TOP3_RATE`
- `EXACT_TOP3_SET_RATE`
- `TOP3_COVERAGE_AT_3`
- `EXACT_TOP2_SET_RATE`
- `TOP2_COVERAGE_AT_2`
- `NDCG_AT_3`

6指標中4指標以上でyear-equal mean delta >= 0、かつ全Supportingでyear-equal mean delta >= -0.0020を要求する。

### Tie Quality Gate

- exact `RANKING_SCORE` tied race rateはpaired STAT-01 baseline以下とする。
- `technical_tiebreak_races / eligible_races <= 0.001`、すなわち0.1%以下とする。

### Bootstrap Contract

```yaml
unit: race
iterations: 2000
seed: 20260812
confidence_interval: 95%
quantile_method: Type-7
resampling: paired_candidate_baseline
multiple_years: year_stratified
```

既存の `RaceClusterBootstrap`、`PairedRaceClusterMetricEvaluator`、`Type7Quantile` と整合させる。CandidateとBaselineは同一race bootstrap sampleでpaired resamplingし、別々のbootstrap streamでresampleして差を取らない。複数yearではyear内resampling後、replicate単位でyear equal meanを作る。

```text
ci_lower = Type7Quantile(samples, 0.025)
ci_upper = Type7Quantile(samples, 0.975)
```

### Final Candidate Selection

Acceptance Gate通過candidate間で次の順に選択する。

1. Primary Pareto dominance
2. maximin primary delta
3. `POSITION_HIT_RATE_AT_3` delta
4. `EXACT_ORDERED_TOP3_RATE` delta
5. `EXACT_TOP3_SET_RATE` delta
6. `NDCG_AT_3` delta
7. lower model complexity
8. canonical candidate key

```text
worst_primary_delta = min(delta WIN, delta P2, delta P3, delta HIT3)
```

Final status:

- `PASS / GO_TO_FREEZE`: Integrity、Non-Inferiority、Superiority、Temporal Stability、Supporting、Tie QualityのすべてがPASS。これだけがFinal Development FitとBT-04準備へ進める。
- `HOLD / PROMISING_NOT_ADOPTABLE`: Integrity、Non-Inferiority、Temporal Stability、Supporting、Tie QualityはPASSだが、Superiorityだけが不足。2026へ進まない。
- `FAIL / REDESIGN_REQUIRED`: Integrity、Non-Inferiority、Temporal Stability、Supporting、Tie QualityのいずれかがFAIL。Supporting failureをHOLD扱いにしない。

## 15.16 用語対応と非拘束期待値

プロジェクト上の用語対応:

- 単勝的中率 = `WINNER_HIT_AT_1`
- 3連単的中率 = `EXACT_ORDERED_TOP3_RATE`
- 3連複的中率 = `EXACT_TOP3_SET_RATE`

BT-03E-01の2024正式実測値:

| Engine | 単勝 | 3連単 | 3連複 |
|---|---:|---:|---:|
| STAT-01 baseline | 38.6040% | 4.2572% | 15.0603% |
| BT-03E-01 Point Engine | 38.4132% | 4.2372% | 15.0365% |

BT-03E-02について会話上置いた次の範囲は `NON_BINDING_EXPECTATION_ONLY` である。

- 単勝: 38.8～39.5%
- 3連単: 4.3～4.6%
- 3連複: 15.2～15.8%

これは参考期待レンジ、非契約、実測前の値であり、Acceptance Gate、性能保証、目標達成条件ではない。この範囲へ合わせるための事後調整は禁止する。

## 15.17 Missing / Unseen / Outcome Eligibility

次を異なる状態として保持し、混同しない。

- observed numeric zero
- `MISSING_INPUT`
- `NO_HISTORY`
- `INSUFFICIENT_SAMPLE`
- `NOT_APPLICABLE`
- `UNSEEN_CATEGORY`
- invalid input
- `DEGENERATE_CHANNEL`

利用不能なincremental contributionは原則 `stored contribution = null`、`included = false` とする。missingを能力0、最下位、減点理由として扱わない。validationで初出の`UNSEEN_CATEGORY`へvalidation情報からcoefficientを作らない。

metric denominatorは既存BT-01 / BT-03E-01 contractを維持する。

- Position 1: 公式1着が一意なrace
- Position 2: 公式2着が一意なrace
- Position 3: 公式3着が一意なrace
- `POSITION_HIT_RATE_AT_3` / `EXACT_ORDERED_TOP3_RATE`: 公式1・2・3着がすべて一意なrace
- set系metric: 既存dead-heat contractを変更しない

BT-03E-02だけでdead heatやabnormal resultを一意順位へ変換しない。pairwise trainingは既存BT-02 binary label semantics、metric evaluationは既存BT-01 / BT-03E-01 eligibility contractを正本とする。

## 15.18 Read-only / Source Integrity / Artifact

Development backtestは原則PostgreSQL READ ONLYとし、Statistics source、Scraping、race / result sourceを更新しない。evaluation prediction freeze前に当該evaluation outcomeを読むことは禁止する。

正式artifactでは最低限次を監査可能にする。

- source identity / source manifest / source fingerprint
- START / END verification
- effective beta / training support
- selected lambda / selected alpha
- channel scale / normalization version
- optimizer version / summation version / tie rule version
- tie diagnostics / metric denominators / missing status counts
- candidate / baseline paired identity
- artifact hash

partial publicationは禁止し、source drift時はfail closedとする。

## 15.19 BT-03E-02正式Development Evaluation結果

```yaml
engineering_status: COMPLETED
development_evaluation_status: COMPLETED
reproducibility: VERIFIED
integrity: PASS
2026_access: 0
performance: FAIL / REDESIGN_REQUIRED
```

- WINNERは2024・2025ともSTAT-01 baselineを改善した。
- POSITION_2も2024・2025とも点推定では改善した。
- POSITION_3 deltaは2024 `-0.004981270423208728`、2025 `+0.0031124944419742007`だった。
- temporal stability failureの主因は2024 POSITION_3である。
- この結果は1着改善を保持しつつ、正確な2着・3着位置生成を再設計する根拠とする。

## 15.20 BT-03E-03 Frozen Design

BT-03E-03は次を実装前契約としてfreezeする。

- `POSITION_SPECIFIC_UTILITY`
- `SEQUENTIAL_CONDITIONAL_SOFTMAX`
- `EXACT_POSITION_MARGINALIZATION`
- `MAP_ORDERED_TOP3`
- `PROBABILITY_OUTPUT`
- `SHARED_LAMBDA_SELECTION`
- `NO_ALPHA_COMBINATION`
- `2022_2025_DEVELOPMENT_ONLY`
- `2026_FORBIDDEN`

BT-03E-02を上書きせず、別versionの監査可能なmodelとして実装する。詳細な数式・eligibility・確率不変条件・GateはBT-03E-03実装指示を正本とする。

## 15.21 BT-03E-04 / BT-03E-05結果とBT-03E-06

BT-03E-03 v2はoptimizer縮退解消後にformal development evaluationと再現性検証を完了した。lambda `0.1` / `1.0`がeligible、selected lambdaは`0.1`、reproducibilityは`VERIFIED`、integrityは`PASS`だった。P2・P3・Hit@3のyear-equalは改善した一方、WINは負でperformanceは`FAIL / REDESIGN_REQUIRED`だった。

BT-03E-04はこのverified v2 probability artifactを固定入力とし、再学習せずmetric別decision decoderを分離した。formal development evaluationと再現性検証を完了し、reproducibilityは`VERIFIED`、integrityは`PASS`、performanceは`FAIL / REDESIGN_REQUIRED`だった。Primary point estimateは2024/2025両年で4/4 positiveだったが、P3のNI CI lowerとSuperiorityがGateを満たさなかった。

P1単独のwinner精度がcoherent firstより両年で高く、first decisionが約7%不一致だったため、BT-03E-05ではP1 winnerを固定し、残りのP2/P3 pairだけを最適化した。formal development evaluationと再現性検証は完了し、reproducibilityは`VERIFIED`、integrityは`PASS`、performanceは`FAIL / REDESIGN_REQUIRED`だった。Non-InferiorityはP2・P3でFAILし、その他のGateはPASS、2026 accessは`0`だった。

BT-03E-06ではE03 v2 artifactの固定modelを再構築し、P1 winner条件付きのP2/P3逐次decoderを評価した。formal development evaluationは再現性`VERIFIED`、integrity`PASS`だったが、P2・P3のNon-InferiorityがFAILし、performanceは`FAIL / REDESIGN_REQUIRED`となった。

BT-03E-07ではE03 v2 artifactのP1をbit-exact固定し、P2/P3だけを全出走者direct softmaxで学習した。formal development evaluationは再現性`VERIFIED`、performanceは`FAIL / REDESIGN_REQUIRED`、2026 accessは`0`で完了した。

## 15.22 BT-03E-06 / BT-03E-07診断とBT-03E-08

E06とE07の診断は完了した。P1は50,078 racesでexact matchし、E07の悪化はP2/P3に限定された。E07 full-field分布ではwinner massがD2平均約0.38、D3平均約0.34を消費していた。D2のwinner除外後正規化はE06 Q2へ大きく近づき、D3も改善したがshape差が残った。eligibility増加は主因ではなく、7車cohortで悪化が明確だった。

BT-03E-08はE03 source artifactのP1とE06 winner-conditioned Q2を固定し、P3だけを学習時・推論時ともwinnerを分母から除くdirect softmaxとして実装した。rank2はP3 candidateに残す。development evaluationは再現可能な否定結果として完了し、2026は引き続きclosedとする。

## 15.23 BT-03E-08正式結果の確定

2026-09-13の成果物再利用・比較で確認済み。今回のTACTICAL-PILOT-01ではE08の再学習・再評価・163検証の再実行を行わない。

```yaml
engineering_status: COMPLETED
development_evaluation_status: COMPLETED_WITH_REPRODUCIBLE_NEGATIVE_RESULT
first_run: bt03e08-20260902-221559-4d321fac724ff4664e9136a35b43e7d1
verified_run: bt03e08-20260903-091134-1156938f229c4861ac1975b5e5477029
reproducibility: VERIFIED
reproducibility_sha256: 6a27d6407bec2b4780dfd377385db73cd1dffaffcd07ec0fa1fabcacd977f591
integrity: PASS
performance: FAIL / REDESIGN_REQUIRED
adoption: REJECTED
2026_access: 0
```

実パス:

- 初回: `/tmp/tmp/bt03e08-development-20260903-042748-5d45f2a01ec0d7402f25012a1ed628a6`
- 検証回: `/tmp/tmp/bt03e08-development-20260903-152323-bd009f89dc2e66e2e210451fd5d9ad6e`
- 確認済み比較: `/tmp/neo-keirin-bt03e08-evaluation-20260913-01/performance-summary.md`

年別等重みのE08-E06差はP1 `0`、P2 `-1.378547pp`、P3 `-0.594071pp`、Hit@3 `-0.658586pp`。E06も正式採用Gateを通過していない比較参照であり、本番採用モデルとは呼ばない。
E08の整合性・再現性成功を精度向上とは解釈しない。過去のstdout/stderr・実終了コード・実行時コードSHAは前回比較でも未確認であり、今回補完しない。

## 15.24 TACTICAL-PILOT-01の限定許可と入力停止条件

以下はユーザーの新規指示による実験範囲であり、E08の凍結契約の変更ではない。

- 入力候補はPJ0315の `nigeCnt / makuriCnt / sasiCnt / markCnt / backCnt / homeTori / stTori` の回数生値だけ。意味・集計期間・対象レース以前の内容である根拠を確認できた項目だけ、最初のfit前に適格と固定する。
- C0はSTAT-01 anchor+既存12 STAT、E03 v2の逐次条件付きcategorical NLL、E06型decoder。C1は同じ学習・選択・評価規則で適格戦法回数だけを追加し、全3順位を学習する。E08 P3-onlyを持ち込まない。
- 既存lambda grid、strong-to-weak、200 accepted updates、tolerance・正則化・bin・One-SE・decoderを変更しない。C0再利用には元のE03係数からE06予測までの同一性検証が必要。
- Outer 2024は2022/2023で選択・refit、Outer 2025は2022/2023と2022-2023/2024のinnerで選択し2022-2024でrefit。各outer prediction seal後に当該年のoutcomeを開く。
- C1-C0のHit@3 CI下限>0、全4主指標CI下限>-0.0015、各年Hit@3差>=0、各年全主指標差>=-0.0030、入力・時系列・再現性・cohort検査を要求する。年層別paired race bootstrapは2000回、seed20260812、Type7。対STAT-01の現行Gateも別に報告する。
- 本番DBはREAD ONLY、Rawは対象レースを先に解決したallowlistだけを読む。実験snapshot・学習成果物はリポジトリ外。本番Migration・正式STAT番号追加・新規scraping・既存モデル更新はしない。
- DATA-AUDIT-01を再実行せず、既存標本から各年先頭・末尾8レースだけを定義確認した。7列が「直近4ヶ月成績」配下にある構造は確認したが、期間の端点・各値の基準日・当該レース結果の除外は確認できなかった。
- `lastUpdateTime`が対象日朝であることや `tyo4InfoSubData` に過去開催日があることを、7回数の時点証明として代用しない。現在 `BLOCKED_INPUT_SEMANTICS`、適格リスト未freeze、新規fit=0、精度差/Gateは未評価。
- この停止は精度の否定結果ではない。必要な仕様資料を得て入力適格性を確認した後にだけ、許可済み限定実験を継続する。正式採用、BT-04/BT-05、2026解禁へ自動移行しない。

---

## 15.25 TACTICAL-HISTORY-01の独立実験

2026-09-15のユーザー指示により、PJ0315集計値を使わない別実験を許可した。
旧TACTICAL-PILOT-01は引き続き `BLOCKED_INPUT_SEMANTICS / fit=0 / NOT_EVALUATED`。
その原ZIP・ログは消失しており、文書のみの復旧版を完全な実行証拠と扱わない。

- 入力は過去の `race_results.winning_technique` 由来の逃げ・捲り・差し・マーク4回数のみ。
- 窓は対象開催初日00:00 JSTをTとして `[T-120日,T)`。別開催・同一選手の既知出走を使い、対象自身・同開催・T以降を除く。2022-2025だけを使用する。
- 公式120日値の復元ではなく `OBSERVED_DB_HISTORY`。取得開始前へ出る窓、履歴なし、不足、未知決まり手はNULLと監査状態に分離する。
- `HISTORICAL_EVENT_RECONSTRUCTION / BACKFILLED_FINAL_RESULT / DEVELOPMENT_ONLY`。イベント時点の排除と公式公開時点の保証を混同せず、LIVE再現や全面的LEAKAGE_FREEを主張しない。
- C0は既存12 STATとSTAT-01 anchor、C1はそれに4回数だけ追加。E03 v2の全3順位conditional NLL・正則化・solver・grid・One-SEとE06 decoderを維持する。既存正式クラス/成果物は変更しない。
- Outer 2024/2025のtraining境界、予測seal後の評価、2000回/seed20260812/Type7の年層別paired比較を維持。C1-C0のHit@3 CI下限>0、全4指標CI下限>-0.0015、各年Hit@3差>=0、各年全4指標差>=-0.0030。対STAT-01 Gateは別判定。
- 既存DBはREAD ONLY。成果物と実行ログは最初から `/home/shinya/neo-keirin-artifacts/` 配下。E08/DATA-AUDIT再実行、Migration、2026参照は禁止。
- AGENTSの予測実装対象外という初期スコープとSection 26のmain切替手順については、今回の明示的許可を優先する。保存済み `db653d64a34914802e49d93818720a65ea2eb8e2` から実験ブランチを作成し、mainを編集しない。
- 詳細は `docs/tactical-history-01.md`。学習・比較・再現性の未実施を完了として記録しない。

実行結果: 2022-2025入力を生成し、READ ONLYで固定52 STAT run・履歴窓221,559件・対象出走706,051件の不変性を確認した。
Outer 2024のC0は25,212レースについて旧E06 CSV全列が一致し再利用できた（C0新規fit=0）。
C1のinner A（2022学習、2023検証用）で全8lambda候補が200 accepted updates内に収束せず、終了コード2で停止した。
lambda=0.1はPOSITION_2、残る7候補はPOSITION_1が非収束。solver定数・grid・採否基準は変更していない。
C1 outer refit・予測・精度比較・実データ再現実行は未実施。C1-C0/C1-STAT-01の4指標・Gateは `NOT_EVALUATED` であり、差0や性能FAILではない。
この数値的停止を旧PJ0315の入力意味未確定と混同せず、次の変更・再学習は新しいユーザー指示を待つ。

2026-09-16 PR #55への追加指示により、上記v1停止の記録を保持して限定修正を許可した。
v2はsupport中心化の直交射影とgroup縮小を統合した正しいユークリッド近接更新を使い、C0/C1とも新規学習する。
200回上限・既存閾値・lambda grid・目的関数・Gateは緩和しない。旧E03/E06/E08は変更しない。
中止履歴は識別検証を維持して予定時刻NULLによる全値欠損を防ぐ。既存221,559窓への該当は0件で、入力数値は変更しない。
修正版の保存先は `/home/shinya/neo-keirin-artifacts/tactical-history-01-review-fix-20260916-01/`。
学習前のREAD ONLY固定STAT・履歴検査は成功。Outer 2024はC0/C1ともlambda=0.1で再学習し、各25,212レースの予測を固定した。
初回はOuter 2025もC0/C1ともlambda=0.1で再学習し、各24,866レースの予測を固定した。両年ともC0/C1予測固定後に当該年labelsを開放した。
2回の独立した実学習でモデル・bin/support・選択・予測等100ファイルがバイト単位で一致。評価・paired bootstrapも完全一致した。
終了時のREAD ONLY検査でも固定52 STAT・履歴221,559窓・対象706,051出走・717,709出走IDの不変性を確認した。
2024/2025年等重みのC1-C0差は1着+2.066185、2着+0.238033、3着+0.979463、Hit@3+1.097709ポイント。
Hit@3の95%CIは[+0.893535,+1.325206]ポイント。追加効果Gateは `PASS_DEVELOPMENT_INCREMENTAL_EFFECT_ONLY`、既存対STAT-01 Gateは `PASS / GO_TO_FREEZE`。
ただしC1-C0の2着差CIは0を含む。全4指標のCI・各年の率・分母は `docs/tactical-history-01.md` と成果物 `comparisons.json` に記録した。
これは実測したdevelopment比較であり、学習完了だけから精度向上を結論していない。過去公開時刻はUNKNOWNのままで、LIVE採用や次工程への許可を意味しない。旧pilotのBLOCKED_INPUT_SEMANTICSとは区別して保持する。

---

## 15.26 TACTICAL-HISTORY-FINAL-01

PR #55 merge `5071339125a5423cc37327953512634c0910cf42` を起点に、C1だけのFinal Development Fitを2026-09-17に完了した。
旧run-01のOOF-1/2をhash・bin/support・対象集合・検証損失順序まで照合して再利用し、再学習していない。
2022-2024学習/2025検証のOOF-3全8候補を新規生成し、3組共通適格候補と既存One-SE（2000回、seed20260812、順位/年等重み）でlambda=0.1を選択した。
2022-2025全99,669レース・706,051出走から最終bin/support/係数を新規生成。全3順位が113/78/100 accepted updatesで収束した。
OOF-3と最終fitを独立に2回実行し、選択・model・bin/support・予測・診断等26ファイルがbyte単位で一致した。
旧Outer 2024/2025 C1予測と保存前後の最終予測も一致。保存モデルをArtisanから読み込む経路も24,866件で一致した。
開始/終了のREAD ONLY検査で固定52 STAT・履歴221,559窓・対象706,051出走・717,709出走IDを照合し、旧成果物と実行コードの不変性を確認した。
保存先は `/home/shinya/neo-keirin-artifacts/tactical-history-final-01-20260917-01/`。最終model SHA-256は `e3cc1f7f10af60bb22f97a2172ca52ef2c09a3393222ee46c25619de752d43a1`。
artifact contractは `TACTICAL-HISTORY-FINAL-01-v1`、solver/modelはPR #55のv2を変更しない。alpha/channel scale/追加判定閾値は理由付きNOT_APPLICABLE。
実行時の記録は `FINAL_FIT_REPRODUCED_AWAITING_REVIEW` のまま保持する。2026-09-18時点では成果物・読込検証のレビューを完了し、PR #56はmainへマージ済み。追加の精度改善・最終LIVE採用・2026利用許可を意味しない。
`BACKFILLED_FINAL_RESULT / DEVELOPMENT_ONLY`、過去公開時刻UNKNOWNを維持する。詳細・コマンド・検証結果は `docs/tactical-history-final-01.md`。

## 15.27 TACTICAL-PREDICTION-PIPELINE-01の限定許可

2026-09-18のユーザー指示に基づき、対象race_id・input_as_ofから固定STAT-01/12 STATと開催前120日履歴を読み、保存済み最終C1で予測してファイル固定する接続だけを実装・技術検証する。
実行モードは `DEVELOPMENT_REPLAY_ONLY`。対象年はSQLでも2022-2025に限定し、対象自身の結果は照会しない。STATの時点が指定時刻を超える、欠損、出走集合不一致は停止する。発売締切優先・予定発走時刻fallbackを維持し、公開時点の証明とは扱わない。
モデルSHA-256 `e3cc1f7f10af60bb22f97a2172ca52ef2c09a3393222ee46c25619de752d43a1`、数値契約・decoder・既存成果物は不変。学習InputBuilderによる全4年再生成・OOF・最終fit・bootstrap・精度評価・DATA-AUDIT・E08再実行は禁止。
stagingで入力・監査・予測を作成し、対象範囲の固定STAT/履歴とモデルの終了照合後だけ `DEVELOPMENT_REPLAY_LOCKED` として公開する。request単位の排他・再利用・競合拒否・破損拒否と、DB不要の固定入力再現を検証する。
保存先は既存合意root `/home/shinya/neo-keirin-artifacts/` 内の新規専用ディレクトリとし、既存FINAL-01/v2の原本ディレクトリへ出力しない。人工検証後、保存済み2025入力の対象メタデータから最終開催日を選び、先に対象一覧を固定して全対象の入力/予測を比較する。正誤での選別・的中率/Gate再計算はしない。
本番DBはREAD ONLYのみ。2026・LIVE・scheduler・他エンジン統合・新規取得・Migrationは対象外。旧pilot保留・旧否定結果は維持し、完了後は接続機能レビュー待ちで停止する。

接続検証結果: 保存済み2025入力の24,866レースからメタデータだけで最終日2025-12-31を確定し、63レース・432出走を検証。入力生成・保存済み入力照合・既存PredictionServiceとの予測照合・DB接続なしの再現は全63件一致、最終不一致/拒否0件。39件の既存固定束を検証して再利用し、24件を新requestで固定した。再利用は新規入力生成として数えない。
初回の新規実装で存在しないdeleted_at参照、次の試行で既存NO_HISTORY等の状態受理不足を検出・修正し、旧試行ログ/stagingを保持した。今回のscopeは接続回帰であり、新しい精度/Gateを算出していない。READ ONLY on/on、最終modelと参照8ファイルは不変。出力は `/home/shinya/neo-keirin-artifacts/tactical-prediction-pipeline-01-20260918-01/`。詳細は `docs/tactical-prediction-pipeline-01.md`。

---

## 15.28 TACTICAL-PREDICTION-RESULT-01の限定許可

PR #57はmain `aebabc3618706a8e9c1e7b2f2c4c88538e620b02` へマージ済み。コード修正レビュー完了と、ChatGPT側の63レース報告ZIP本体の照合未完了を区別する。Codexによる今回の確認をChatGPTの確認済みとは記載しない。
ユーザー指示により、保存済み2025-12-31の63件の固定requestと既存2025 labelsの照合・既存Bt03e05MetricEvaluatorによる集計・別成果物保存・DB不要再現を許可する。39再利用/24新規生成の過去区分と失敗証拠を維持する。
モードはDEVELOPMENT_REPLAY_ONLY、用途はIN_SAMPLE_REPLAY_TECHNICAL_CHECK。対象一覧と結果原本hashを集計前に固定し、全対象・原本の終了検査後に別rootへ公開する。予測生成、学習、Gate、CI、bootstrap、DB接続、新規取得、2026、LIVEは行わない。未知データ精度・追加の精度改善・採用判定とは扱わない。完了後はレビュー待ちで停止する。

Codex実行結果: 63レース・432出走を照合し不足/不一致0。1着26/63、2着18/63、3着10/63、Hit@3=54/189。既存Evaluatorを固定入力・保存labelsへ直接接続した参照結果とレース別寄与・全11指標の集計が完全一致し、DB不要再現/再利用も成功。原本765ファイルと最終モデルhashは不変。詳細は `docs/tactical-prediction-result-01.md`、出力は `/home/shinya/neo-keirin-artifacts/tactical-prediction-result-01-20260918-01/`。学習期間内の照合技術検証だけを完了し、レビュー待ちで停止する。ChatGPTの旧ZIP照合を完了扱いにしない。

PR #58レビュー修正: 同着status/順位グループ整合性、baseline同点規則の依存コードseal、生成時期待sealと公開前の全13成果物照合を追加。新ID `development-2025-12-31-fixed63-pr58-review-01` で同じ63レース・432出走の保存・再利用・再現を確認。全11指標とレース別寄与は旧評価と完全一致、保護対象854ファイルとモデルhashは不変。旧IDはコード不一致で拒否し旧記録は保持。追加26テストと全体回帰を確認し、`pr58-review-01/` に証拠を保存。未知データ精度・Gate通過ではなく、修正のレビュー待ち。TACTICAL-GRADE-ANALYSIS-01・級班別集計は未着手で、次工程への自動移行はしない。

---

## 15.29 TACTICAL-GRADE-ANALYSIS-01の限定許可

2026-09-18の新規ユーザー指示により、PR #58のコード・成果物レビュー完了、
main `67d6795fde722cae96536b54d8894b761bd8fd16` へのマージ、旧PR #57報告ZIP本体の照合完了を現在地へ反映する。
Section 15.27/15.28および旧変更履歴にあるレビュー待ちは当時の記録として残す。
今回許可するのは修正版run-01のOuter 2024/2025 C1予測50,078レースの事後内訳分析だけ。
最終モデルや63件の接続確認を母集団へ代用せず、固定decisionと既存着順別Evaluatorを使う。
当該race_entries.gradeの属性照会は対象ID/2024-2025日付限定のREAD ONLYとし、DB結果は再取得しない。
UNKNOWNも分母へ保持し、年・車立て・級班・着順と件数加重合算、参考Wilson区間を保存する。
集計前契約は `docs/tactical-grade-analysis-01.md`。新namespace/コマンドによる保存・DBなし再現を実装する。
学習・推論・旧Gate/CIの再実行、級班重みや採用条件の変更、2026、LIVEは許可しない。

実行結果: run-01のC1 Outer 2024/2025を全50,078レース・356,209出走で照合。
当該出走級班の確認率100%、UNKNOWN/識別不一致0。各位置の予測級班へ割り当てた150,234明細を保存した。
全級班合計は保存済み未丸め評価・レース別寄与と分子/分母とも一致。
31原本（両Outerモデルを含む）とコード、対象DB属性のSTART/ENDは不変。DBなし再現で明細・集計JSON/CSVが一致。
両年ともA3の1着/2着観測率は高めだが、年・車立て・予測選択条件による構成差を含み、因果効果や優越性を主張しない。
保存先は `/home/shinya/neo-keirin-artifacts/tactical-grade-analysis-01-20260918-01/`。
実行ピーク66.5MiB、再現60.5MiB。参考Wilson区間は相関未補正、過去公開時刻UNKNOWNを維持。
詳細・件数・CIは `docs/tactical-grade-analysis-01.md` と成果物summary。採用Gate・モデル更新・次工程には進まずレビュー待ち。

## 15.30 TACTICAL-MEETING-GRADE-ANALYSIS-01

2026-09-18のユーザー更新指示で、主分析軸を「予測選手の級班」から「開催グレードGP/G1/G2/G3/F1/F2」へ変更。
15.29の旧分析・未コミット実装・固定成果物は参考資料として保持し、開催分析の完了とは扱わない。
同じrun-01 Outer C1 50,078レースの保存decision/寄与を再利用する。
開催ID対応、月間日程由来meeting.gradeとJSJ001開催ヘッダー由来race.gradeを照合し、同一開催で分類を統一する。
GP欠損補完は同一開催の全対象ヘッダーが一致する場合のみ。矛盾・認識不能はUNKNOWNとして残す。
年×開催gradeを主表、車立て・race情報由来競走区分・段階を補助表とする。開催数はdistinct ID、合算は件数加重。
Hit@3は公式1/2/3がすべて一意なraceの位置一致数/(3×適格race数)。CI未計算、P1/P2/P3のみ相関未補正Wilson。
集計前契約は `docs/tactical-meeting-grade-analysis-01.md`。READ ONLY属性取得・START/END不変確認・DBなし再現までを許可。
旧分析の単純なラベル置換、再学習・推論・結果DB再取得・新bootstrap/Gate・2026・LIVE・grade重み変更は禁止。

実行結果: 全50,078レースを開催分類。50,016レースは双方一致、62レース（2開催）は開催grade=NULLを同一開催のJSJ001ヘッダーGPで補完。
UNKNOWN・矛盾・開催内混在は0。GP分類は各年31レース（各1開催）の併催を含み、単発GP競走だけの指標ではない。
P1/P2/P3/Hit@3の分子・分母が元保存寄与と一致。属性START/ENDは不変、DBを無効化した再現で分類・明細・集計が一致。
全体のHit@3はF2が高めだが、同じA1/A2戦ではF1との差は小さく、1着率はF1が高い。開催構成の相関を含む観測であり一般的優位・採用Gate通過を意味しない。
今回の保存先は `/home/shinya/neo-keirin-artifacts/tactical-meeting-grade-analysis-01-20260918-01/`。実行・再現ピーク38.5MiB。
旧級班分析は参考として残す。文書・実装とも未コミットのレビュー待ちとし、次工程には自動移行しない。

---

## 15.31 GROWTH-POINT-ANALYSIS-01

2026-09-18ユーザー指示により、開始main `056e339a8d7ef56aacb3537fe413ca6d72a8813b` から専用experimentブランチを作成。
旧級班・開催grade分析と固定C1を保持し、同じ50,078レースの全356,209出走を分析単位とする。
今回の対象は成長指標そのものの診断であり、C1モデル変更やSTAT正式採用ではない。
結果閲覧前に `docs/growth-point-analysis-01.md` へ以下を固定した。
Aは歴史的race_entriesのtarget競走得点-prev1競走得点、Bは既存Batch02正式残差のprev1-prev2。
実発走前走は日付・予定時刻順で選び、欠場/取消/中止を除外、異常完走は履歴へ残すが正常残差へ変換しない。
2024閾値は2022-2023、2025閾値は2022-2024だけのType7分位点。A/Bを-3～+3、両方利用可能な場合だけC=A点+B点。
開催分類は前工程の固定資料を検証して再利用。相関・単調性・欠損を年/grade/競走区分/同一開催別に報告。

実行結果: Aの年別raw Spearmanは+0.016586/+0.016975、Bは-0.072251/-0.070199、C点は-0.050279/-0.048289。
全体の事前分類はA/B/Cとも両年NON_MONOTONIC。Aの観測point別率は上昇するが相関基準未満、Bは主に逆方向でも3着内率に隣接逆転がある。
同一開催のAは全件raw=0。Bの逆方向は同一開催で約-0.105、開催間ではほぼ0であり、長期成長とは混同しない。
F2全体の弱い正方向にはA_CHALLENGE構成の影響があり、F1/F2のA1_A2比較では年次の強弱が一定しない。
単純な「成長点が高いほど次走が良い」、C1改善、因果効果、正式採用を結論しない。E08不採用・旧pilot保留・2026閉鎖を維持。
READ ONLY履歴101,317レース・717,661出走、旧原本62ファイルと直接コードのSTART/ENDは不変。
DBを無効化した再現は閾値/入力/明細/全診断と集計hash一致。実行48MiB、再現42MiB。
保存先は `/home/shinya/neo-keirin-artifacts/growth-point-analysis-01-20260918-01/`。
取得時点はBACKFILLED_FINAL_RESULT / DEVELOPMENT_ONLY / publication_time_verified=UNKNOWN。
未コミットのレビュー待ちで停止し、再学習・推論・Gate/bootstrap・LIVE・新規取得・本番書込みは行わない。

---

## 15.32 GROWTH-POINT-ANALYSIS-01-v2

2026-09-19 PR #60レビュー指示。v1はQUANTILE_BASED_POINT契約どおりの完了済み診断であり、計算バグとは扱わない。
zero massによりA raw=0がv1では-2点になるため、成長/悪化/変化なしという意味付けを分離したv2を追加検証した。
v1 PHP・文書・固定bundle・旧ZIPはbyte-for-byte不変。Section 15.31の歴史記録は維持する。
結果閲覧前契約は `docs/growth-point-analysis-01-v2.md`。新namespace/command、SIGN_PRESERVING_POINT。
負/正の過去年分布を分離したType7 P33/P67、0は常に0、各側n<3はNULL/INSUFFICIENT_SIGN_TRAINING。
既存v1 snapshotだけから全50,078レース・356,209出走を再計算し、入力/raw/status/prev1/prev2/同一開催を全件一致で検証。
A raw=0の119,668/118,519件がv2では0点。同一開催Aの118,820/117,729件もraw/pointとも全件0。符号違反0。
全層raw Spearmanは未丸め値でv1と完全一致。年別v2 point rhoはA +0.016604/+0.016912、B -0.069889/-0.067532、C -0.056421/-0.053351。
既存診断基準は不変、両年A/B/CともNON_MONOTONIC。符号の意味は保持したが、予測精度改善・因果効果・STAT採用は結論しない。
DB無効・128MBでv2 execute/reproduce、v1 reproduce成功。v2ピーク約48MiB、v1再現42MiB。
保存先は `/home/shinya/neo-keirin-artifacts/growth-point-analysis-01-v2-20260919-01/`。
年/開催grade/競走区分/同一開催、C1取り逃し、v1→v2遷移・欠損・原本不変の証跡を保存。
E08不採用、旧pilot保留、2026閉鎖、モデル/STAT/DB/正式成果物の保護を維持。未コミットでレビュー待ち、次工程への自動移行なし。

---

## 15.33 GROWTH-ADJUSTMENT-CALIBRATION-01

PR #60 merged、開始mainは `62abf52c612038cfd32e1ccbfd069a319981e629`。
ユーザー承認の独立development backtest。v1/v2のPHP・文書・固定成果物・ZIPは変更しない。
結果閲覧前契約は `docs/growth-adjustment-calibration-01.md`。
修正版run-01 Outer C1の2024/2025全50,078レースを固定し、A SCORE_POINT_V2だけをanchorへ加算する。
`adjusted_anchor = original_anchor + (k/100) * point`、k=-50..50の101候補。B/Cは使用しない。
C1のモデル、lambda=0.1、bin/support/係数、scorer/decoder/評価定義は不変。再学習なし。
w=0の保存済み確率・decisionを結果なしで照合し、2024指標・curveだけで係数を選択してselectionをsealする。
2025 labels/contributionsとw=0指標はseal後に初めて読む。selected-w validation後に2025全gridと件数加重pooledを診断専用で評価し、再選択しない。
0・欠損・同一開催0のanchorは不変。相手のutilityが変わるため、その選手の確率・相対順位不変とは主張しない。
DB接続なし、128MB、2026アクセスなし。新しい正式STAT採用、C1改変、正式Gate、LIVEではない。
保存先: `/home/shinya/neo-keirin-artifacts/growth-adjustment-calibration-01-20260919-01/`。
初回実行は `NUMERIC_RESULT_AVAILABLE_BUT_TEMPORAL_READ_ORDER_REVIEW_ISSUE` として保持。
w=0は両年50,078レースで一致したが、2025指標をseal前に読んでいたためPR #61で順序を修正した。
2024でw=+0.03を選択・seal。2024の差は1着+0.055648、2着+0.175257、3着+0.127521、Hit@3 +0.119808pp。
固定2025では+0.008068、-0.032353、-0.020211、-0.014867ppとなり **NOT_REPLICATED**。
2024の改善は2025へ継続せず、正式採用・C1変更は行わない。全101候補の曲線とselected明細を保存した。
DB無効の完全再現で、全26生成物のhashが初回と一致。実行・再現のPHPピークはともに30MiB。
実入力50,078レースの全101係数で0点・欠損・同一開催0点のanchor不変も検証した。
数値再現性は確認できたが、年をまたぐ改善の継続は未確認。両者を混同しない。
PR #61 review-fixの実行は成功し、旧実行とselected k・未丸め指標・全曲線・予測明細が一致。
`NUMERICALLY_UNCHANGED_AFTER_TEMPORAL_FIX`、2025 `NOT_REPLICATED` を維持。全30生成物のbyte-exact再現も成功。
修正版execute/reproduceはともに128MB制限で完走し、PHPピーク30MiB。
監査連番はselection seal=6、first 2025 outcome access=7、2025 baseline=8、labels/contributions open=9/10。
DB=NONE、2026 access=0。C1・数値規則は不変、正式growth weightは未採用。
128MB関連テストとfile-by-file全件は成功。通常artisan全件成功と、旧単一128MB全件OOMは区別する。
未コミットでレビュー待ち。係数の再選択、正式採用、次工程への自動移行は行わない。

---

## 15.34 GROWTH-TREND-ANALYSIS-01

PR #61はmain `eef27c9e80d80a733a4c0c3c82e18151259e8f87` へマージ済み。
旧w=+0.03は `COMPLETED_NEGATIVE_DEVELOPMENT_RESULT / NOT_REPLICATED / FORMAL_WEIGHT_NOT_ADOPTED`。
Section 15.33のレビュー待ちは当時の履歴として保持する。

前回は実発走判定とseal前の結果参照禁止が両立せず停止した。ユーザーの改訂契約に従い、
実発走ではなくOUTCOME-FREE SCORE OBSERVATION SNAPSHOTを新規固定する。
captureのみ4テーブルの許可列へREAD ONLY接続。結果・順位・2026を参照せず、得点の公開時点はUNKNOWN。
以後DB無効、全356,209出走の41候補・入力・直接コードをsealしてから2024/2025結果を読む。
両年をdevelopment selectionとし、旧H1/H2案を使わない。詳細は `docs/growth-trend-analysis-01.md`。
READ ONLY captureは50,078レース・356,209出走・2,285選手を照合し、704,202観測を固定した。
選択はMEETING_DELTA_LAG_1（S0と直前の別開催代表得点の差）、適格9候補、ROBUST_RHO=0.009639846634467018。
overall rhoは2024=0.016814352、2025=0.017429850。C1条件付きrhoは0.009639847 /0.012011709。
旧race growthよりzero massは約66.9%から約1.4%へ減ったが、相関増加は小さく予測精度改善の確認ではない。
初回はwinner gapのSQL計画による巨大直積のため停止し、旧stage・コード・ログを保存した。
結合順のみ修正したexecute-02と初回のtrend・両年候補値は同一。式・grid・選択規則は不変。
DB無効・128MBで実集計と31生成物のbyte-exact再現が成功し、PHP peakは双方32MiB。
seal前outcome access=0、2026実データaccess=0。状態はDEVELOPMENT_SELECTED_AWAITING_REVIEW。
正式STAT採用、C1変更、次工程は許可しない。2026はFROZENを維持。

### PR #62 Review Fix / 2026-09-20

上記の初回開発選択・31生成物は旧runの履歴として保持する。
PR #62は `OPEN / REVIEW_FIX_COMPLETED_AWAITING_REVIEW`。開始HEADは
`c3d5145a3d0e565c8576992deb8e65321ee0975e`、同じPR branch上の未コミット修正である。

- preseal provenanceへのoutcome identity混入、過去開催start=NULLのDAY処理、C1正常予測分母の先行修正を維持。
- 同日別開催が履歴SQLで消える問題を修正。対象境界と同日の別開催を保持し、全41候補を `raw=NULL / PARTIAL_TIME_ORDER` とする。古い開催へのfallback・IDによる時系列推測はしない。
- presealでexport registry全体を読む問題を解消。Outerの入力・予測とsidecarの8ファイル、meeting metadataの3ファイルを実物確認済みliteral sealで固定し、全体manifestを参照しない。
- 版は `GROWTH-TREND-ANALYSIS-01-v3-PR62-REVIEW-FIX` と `GROWTH-TREND-SCORE-SOURCE-01-v3-STRICT-OUTCOME-ISOLATION`。旧bundleを新契約として解釈しない。

正式なreview-fix source IDは `outer-c1-score-observations-2022-2025-pr62-review-fix-02`、
analysis IDは `outer-c1-growth-trend-2024-2025-pr62-review-fix-02`。
最初のprojectionのrowsキー欠落と、旧source sealのrows付き配列比較による停止を修正・回帰検証した。
実ファイルのbyte比較でDB driftでないことを確認し、review-fix-01の固定source・未完成stage・失敗ログは保持、新IDで全工程を実行した。

READ ONLY recaptureは50,078レース・356,209出走・2,285選手・704,202得点観測。
4許可テーブルのみ、source START/END一致、初回score-observationsとtargetsはbyte-identical。
同日曖昧は2024年8出走・4別開催、2025年12出走・5別開催。各41候補で旧VALIDからPARTIAL_TIME_ORDERへ8/12件ずつ遷移。
全27 MEETING候補と全14 DAY候補の両年指標が変化した。
sourceのstart=NULL観測・開催とDAY影響数は0で `PRODUCTION_DAY_NULL_FIX_NUMERICALLY_INERT`。
今回の数値差は同日境界修正によるもので、DAY NULL処理やDB変化とは区別する。

selectedは初回・修正版とも `MEETING_DELTA_LAG_1`、適格9候補も不変。
ROBUST_RHOは `0.009639846634467018` から `0.00964758593957939`。
41候補全ての未丸めold/newと同日別開催監査を保存した。C1の正常分母診断も維持し、C1自体は変更していない。
presealのregistry open / label identity resolve / label file openは、保護した実行経路の監査で全て0。
物理的にregistry・labelsを置かないテストと両年outcome変更テストも成功。
trend sealの連番4より後に2024/2025 labelを解決（6/9）し、結果を開いた（7/10）。

DB無効・128MBのexecute/reproduceは成功、全36生成物がBYTE_EXACT、PHP peakは双方36MiB（capture34MiB）。
旧成果物48ファイルとreview-fix-01証拠33ファイルのbytes/SHAはSTART/END不変。
focused 81 tests /937 assertions、全体1,641 passed /9 skipped /12,760 assertions。
結果は両年development corpusにおける弱い正方向の条件付き関連に限る。予測精度改善・正式STAT採用の根拠とはしない。
2026実データaccess=0、C1 refit/変更=0、旧Growth・旧負の結果・旧pilot保留を維持する。
次工程は `NOT_AUTHORIZED`、レビューと新しいユーザー指示を待つ。

---

## 15.35 GROWTH-TREND-ADJUSTMENT-CALIBRATION-01

2026-09-20のユーザー指示とPR #62 MERGED（main `17cc492e077034a4ebab46594cb2a6e1d3c3642f`）を確認し、独立実験を許可。
Section 15.34のレビュー待ちは当時の履歴として保持する。固定signalはreview-fix-02のMEETING_DELTA_LAG_1のみ。
2024のoutcome-free VALID abs(raw) Type7 P99でscaleを固定し、[-1,1]へclipした値をanchorへ(k/100)倍加算する。
旧C1を再学習せず、全101係数で2024を評価・選択・sealしてから2025結果を解決する。
2025はsignal粒度選択に使用済みのためPOST_SELECTION_DEVELOPMENT_TRANSFER_DIAGNOSTICでありholdout/replicationではない。
詳細の結果閲覧前契約は `docs/growth-trend-adjustment-calibration-01.md`。
実装・人工テスト・DB不要実集計・再現が今回の許可範囲。旧各Growth・C1・旧成果物は変更しない。
実集計と39成果物のbyte-exact再現を完了。2024 VALID n=178,641、SCALE_P99=3.03、k=34/w=+0.34、INTERIOR_SELECTED。
2024のP1/P2/P3/Hit@3差は+0.254392/+0.458058/+0.314816/+0.348775pp。
2025の固定transfer差は-0.076647/-0.214341/+0.226363/-0.020273ppで、NOT_TRANSFERRED_POST_SELECTION_DEVELOPMENT_REPLAY。
状態はPOST_SELECTION_TRANSFER_NOT_CONSISTENT_AWAITING_REVIEW。2024選択年の改善を独立評価での向上とは扱わない。
w=0は両年の全確率・decision・保存済み評価寄与に完全一致。sequenceはscaling seal=5、2024 outcome解決=6、selection seal=13、2025 outcome解決=14。
2025 full gridは診断のみで再選択なし。DB NONE、2026参照0。128MBで実集計・再現ともPHP peak 32MiB、元source/model/codeは不変。
成果物は `/home/shinya/neo-keirin-artifacts/growth-trend-adjustment-calibration-01-20260920-01/` に保存。
focused 50 tests / 608 assertions、関連331 tests / 2819 assertions成功。全体1691成功・9skip / 13368 assertions。
変更PHP構文・Pint検査成功。未コミットレビュー待ちで停止し、2026・正式STAT・LIVE・次の実装はNOT_AUTHORIZEDを維持。

### PR #63 Review Fix / 2026-09-20

PR #63はOPEN / REVIEW_FIX。上記v1実行・旧ZIP・旧判定は履歴として維持する。
開始HEAD `65a4cee3d987717e774b95b9381f26b6dd110eaa`、同じexperiment branch、開始時clean。
問題は `OUTCOME_SOURCE_SELF_SIGNED_SIDECAR_TRUST`。現在のbodyとsidecarを整合的に改変すると受理できた。
`FIXED_REVIEWED_PER_YEAR_OUTCOME_SEALS` へ変更し、実物照合した8原本のbytes/rows/SHAを年別literalで固定。
sidecar自体のhash、JSON内の固定本文seal一致、本文hashを検証する。mixed-year registryは使わない。
2024はscaling seal後、2025はselection seal後のみにruntime検証・参照を許可する。
版は `GROWTH-TREND-ADJUSTMENT-CALIBRATION-01-v2-PR63-OUTCOME-SEAL-FIX`。
新ID `outer-c1-meeting-delta-lag1-adjustment-2024-2025-pr63-review-fix-01` を旧runと同じrootへ別保存。
全101候補（両年/pooled）・全診断・selected明細・変更件数、scale/選択結果は旧runと完全一致。
`NUMERICALLY_UNCHANGED_AFTER_OUTCOME_SOURCE_TRUST_FIX`、P99=3.03、k=34/w=+0.34、eligible 73個を維持。
2025は `NOT_TRANSFERRED_POST_SELECTION_DEVELOPMENT_REPLAY` のまま。正式採用・C1変更は行わない。
DB無効・128MBでexecute/reproduce成功、両方PHP peak 32MiB。全41成果物を外部照合でもBYTE_EXACT確認。
旧bundle39ファイルと旧ZIPはSTART/END不変。body+sidecar、片側1byte、rows改変の拒否とpreselection不変性を検証。
focused 70 tests /788 assertions、関連401 tests /3607 assertions、全体1711 passed /9 skipped /13548 assertions。
変更PHP5ファイルの構文・Pint検査成功。2026 access=0、次実装NOT_AUTHORIZED。
状態は `GROWTH-TREND-ADJUSTMENT-CALIBRATION-01_PR63_REVIEW_FIX_VERIFIED_AWAITING_REVIEW`。
次は `REVIEW_PR63_OUTCOME_SEAL_FIX_AND_WAIT_FOR_USER_INSTRUCTION`。同じPR branch上の未コミット状態でレビューを待つ。

---

## 15.36 STAT-35-DATA-READINESS-AUDIT-01

2026-09-21の新規指示と結果参照範囲の明示承認により、PR #63 merge
`121517ebf6edb311e20be2b730b378cb7b201c27` から専用audit branchで開始。
PR #63はMERGED、GrowthはCOMPLETED_NEGATIVE_DEVELOPMENT_TRANSFER / GLOBAL_LINEAR_WEIGHT_NOT_ADOPTED。
Section 15.35のレビュー待ちは過去の記録として保持し、k=34/w=+0.34と2025 NOT_TRANSFERREDは変更しない。

2022-2025 result outcome fields are accessible only for STAT-35 data-quality classification.
Target rank/winner are not consumed for predictive evaluation, feature selection or parameter selection.

結果Rawの物理読取りとresult_statusの品質分類は許可。着順・勝者を分析変数として消費しない。
対象自身の上がりを自身の履歴へ入れず、過去レースとその取得時刻が対象発走より前のものだけをas-of候補とする。
PRE_MEETING/IN_MEETING、正常/異常状態、現在DB状態/過去import状態、システム取得/公式公開を区別する。
2024/2025はDEVELOPMENT_CORPUS、2026レースのRaw・結果・メタデータ参照は全て禁止。
DBとRawはREAD ONLY。監査専用実装だけを追加し、production Parser・Migration・STAT・モデル・Gateは変更しない。
詳細契約と実行状態は `docs/stat35-data-readiness-audit-01.md`。
全件監査とDB無効再現を完了。保存先は `/home/shinya/neo-keirin-artifacts/stat35-data-readiness-audit-01-20260921-01/`。
101,326レース・127,121 importのRaw存在/hashは100%、127,008 importから899,996行を抽出。
48中止import / 40レースはDB結果と車番照合不能、別65中止importは空結果。primary判定はBLOCKED_IDENTITY_MAPPING。
全取得時刻が2026年のため、固定Outer 2024/2025の179,089 / 177,120出走行でPRE/IN履歴は全て0。
BLOCKED_INSUFFICIENT_RAW_HISTORYの独立した制約も成立。2026レースを参照したという意味ではない。
26生成物はBYTE_EXACT、独立照合も一致。128MB下のPHP peakはexecute 36MiB / reproduce 32MiB。
focused 58 tests / 109 assertions、全体1,769成功 / 9 skip / 13,657 assertions。production write・Migration・予測評価は0。
状態はSTAT35_DATA_BLOCKED_AWAITING_REVIEW。次の実装・構造化保存・backfillはNOT_AUTHORIZED。
監査結果のレビューと次の明示指示を待ち、未コミットで停止する。

### PR #64 Review Fix

上記は旧v2 runの記録として保持する。PR #64 OPEN / REVIEW_FIXとして同じaudit branchで3点を修正した。
契約はSTAT35-DATA-READINESS-v3-PR64-REVIEW-FIX、新IDはstat35-agari-readiness-2022-2025-pr64-review-fix-01。
中止専用の空欄部分行診断、未解決抽出/targetの明示identity blocker、一意のreproduce attemptを実装。
全127,121 importを原Rawと本番READ ONLYから再監査し、旧48件のidentityエラーは全て
中止・DB結果0・上がり空欄（40レース・53行）と確認した。正常解析数へ加算せず、履歴にも含めない。
新normal mismatch、unresolved extracted/target、中止非空値/DB結果矛盾は全て0、identity_safe=true。
readinessはBLOCKED_INSUFFICIENT_RAW_HISTORY。primary=INSUFFICIENT_RAW_HISTORY、secondary=RAW_GAP_POLICY。
後者はimport未登録29レースであり、確認済みRawの物理欠損0とは区別する。
storage_backfill_feasible=trueは既存確認済みRawの抽出についてのみ。historical_as_of_backtest_feasible=false。
2024/2025のPRE/IN履歴は全て0で、PUBLICATION_TIME_UNKNOWNを維持する。
旧新19ファイルと全Raw取得metadataは完全一致。DB START/END一致、write=0、2026レース参照=0。
同一IDのDB無効reproduceを2回連続実行し、各27生成物がBYTE_EXACT。独立したbytes/SHA/行数照合も一致。
128MB制限でexecute peak 36MiB、reproduceは両方32MiB。旧bundleとZIPの40ファイルはSTART/END不変。
focused 76 tests /225 assertions、全体1,787成功 /9 skip /13,773 assertions。変更PHPのphp-l/Pint成功。
状態はSTAT35_DATA_BLOCKED_INSUFFICIENT_HISTORY_PR64_REVIEW_FIX_VERIFIED_AWAITING_REVIEW。
次はREVIEW_PR64_REVIEW_FIX_AND_WAIT_FOR_USER_INSTRUCTION。正式STAT・backfill・予測評価はNOT_AUTHORIZED。

---

## 15.37 STAT-35-STORAGE-BACKFILL-01

PR #64はMERGED。main/origin `cefd6b4e777315f08cc10980369d5734196d01f7` のclean状態から
`feature/stat35-storage-backfill-01` を作成し、ユーザーが許可した保存基盤だけを実装した。
前節のreview待ちは当時の履歴として保持し、現在の監査状態は `COMPLETED_PR64_MERGED` とする。

PJ0326.agariのdecimal正規化、race_resultsの現在値、import/bike別append-only観測、
同一transactionでの新規結果保存、2022-2025限定のRaw backfill commandを追加した。
現在値のsourceは既存race_result_import_idを使い、訂正前後の観測を上書きしない。
手動HTMLは上りheaderを使い、headerなしはMISSING。0/負数/不正表記と欠損、異常結果の数値を区別する。
PJ0326の取消部分空欄行は観測0件。原Rawのhash/size/path/変換後hashと終了時sealを検証する。

バックフィルはBACKFILLED_FINAL_RESULTであり、publication timestampはUNKNOWN。
取得日時はfetch logの実値だけを保持し、発走前取得証跡の不足は解消しない。
readinessはBLOCKED_INSUFFICIENT_RAW_HISTORY、identity_safe=true、secondary=RAW_GAP_POLICYのまま。
正式STAT・historical model inputへの採用・予測評価・2026アクセスは許可していない。
初回実装では人工SQLite/一時PostgreSQLテストのみ。本番Migration・backfill・Rawへの書込みは実行していない。
仕様と検証結果は [stat35-storage-backfill-01.md](stat35-storage-backfill-01.md) に記録する。

PR #65 review fixでは、NO_IMPORTを既存RaceCategoryPolicyによるMen限定とし、Girls/UnknownはNO_IMPORT_UNSUPPORTEDへ分離。
Manual CANCELLEDは部分行semantic未監査のため非空データ行をfail closedとし、PJ0326の監査済みblank partial契約は維持する。
初回検証結果は履歴として保持し、人工テスト結果は同仕様書のPR #65 Review Fixへ記録した。
マージ前の `REVIEW_PR65_REVIEW_FIX` は当時の記録。2026-09-22にPR #65のマージを確認し、実装・レビュー・マージ完了へ更新する。
現在の許可範囲は次節の本番適用前確認のみ。本番適用・追加実装は `NOT_AUTHORIZED`。
旧モデル・旧成果物・Growth不採用・旧pilot保留・2026 FROZENを維持する。

## 15.38 STAT-35-PRODUCTION-PREFLIGHT-01

ユーザー明示許可により、cleanなmain/origin `adb847c5b0a2b0778ecb02a57c37bffbecf2d926` から
`ops/stat35-production-preflight-01` を作成。PR #65 mergeを確認し、本番適用前確認と手順作成だけを実施した。
接続開始時からREAD ONLYを強制し、default/transactionともonを確認後、Migration管理表とschema metadataだけを照会した。
対象 `2026_09_21_000013_add_agari_storage` は未適用。他の未適用Migrationは0。
race_resultsのagari3列、観測tableと関連制約/trigger/functionは未作成で、履歴とschemaは整合している。
通常結果保存は新観測tableを必ず参照するため、現コードを未適用schemaで実行すると失敗する。同期停止・起動は行っていない。
可視の起動定義には本repoの定期結果同期を確認できず、他ユーザー/手動起動/ロード済みコードの網羅確認は運用側の未充足条件。
512MのDB/Raw非参照planを1回実行しexit 0。Migration、backfill dry-run、backfillは未実施、本番write=0、実2026レース参照=0。
証跡は `/home/shinya/neo-keirin-artifacts/stat35-production-preflight-01/run-20260922-Jtc8st/` に保存し、既存成果物を上書きしない。
手順は [stat35-production-application-01.md](stat35-production-application-01.md)。未適用schemaは想定内であり確認作業を中断していない。
現在地は本番適用手順レビュー待ち。実行担当/排他窓、backup/復旧試験、DB/WAL容量、限定1日の選定、別途書込み承認が必要。
本番Migration/backfillとSTAT-35予測利用は `NOT_AUTHORIZED`、historical_as_of_available=falseを維持する。
実データは512Mを基本例として実測調整。独立bounded-memoryテスト128Mと過去の128M成功実績は変更しない。
機能変更がないため全テスト・過去監査は再実行せず、文書差分とdiff-checkだけを確認する。

---

## 15.39 STAT-35-PRODUCTION-MIGRATION-01

2026-09-22の最新ユーザー指示は、取得済み全DBバックアップの完了報告を確認したうえで、
対象Migration 1本のDDLとLaravel適用履歴登録だけを明示許可した。Section 15.37/15.38の未承認・未適用・write=0は当時の記録として保持する。
PR #66 MERGED、cleanなmain/origin `2715757a6952dbf32fc97f881945d94721a08282` から
`ops/stat35-production-migration-01` を作成。対象はpgsql / neo_keirin_prediction_db / publicのみ。

取得済みbackupは5,583,650,971 bytes、記録済みSHA-256は
`74918382a77ea243e9af1a21e2af1fe834d35c3d39c49fa46e62a0f5e6bbccbe`。
pg_dumpおよびpg_restore --listは成功済み。今回は存在・読取り・サイズと既存記録だけを照合し、再取得・再hash・一覧再取得・復元はしていない。
2026を含む全DB保全は先行する個別許可によるバックアップであり、内容表示・分析・モデル選択の許可ではない。復元試験は未実施。

対象 `2026_09_21_000013_add_agari_storage` のSHA-256は
`91f516f8e9a43138166ffd816635d5b9b7161343d64ef2f65cf6fca8f06eed90` と一致。
READ ONLYで接続先、対象未適用、agari3列・観測table未作成を確認。格納先はローカル18/main・pg_defaultで、実行直前の空き容量を証跡へ保存した。
確認スクリプトのIP比較表記差とdata_directoryの表示権限不足は別途記録し、host()・既存ローカルクラスタmetadataで解消した。権限変更はしていない。
指定した接続限定PGOPTIONS（read_only=off、lock_timeout=5s、statement_timeout=15min）と512Mで対象pathだけを1回実行。
09:44:27～09:44:28 JST、1.306743902秒、exit 0、stderr 0 bytes、警告なし。自動再試行・rollbackはない。

適用後のREAD ONLY構造照合28項目は全て成功。Migration id=17 / batch=14、他Migration履歴は不変。
nullable NUMERIC（precision/scaleなし）・TEXT・VARCHAR(40)、観測全16列、PK/UNIQUE/5 INDEX、race/import RESTRICT FK、
両tableのagari CHECK・bike CHECK、有効なimmutable triggerとfunction本文が定義どおり。
既存race_results全14列の構造と既存制約/index/triggerは不変。業務行全件読取り・順位/払戻集計・試験DMLは行っていない。
状態は `APPLIED_AND_SCHEMA_VERIFIED`、次は `REVIEW_STAT35_PRODUCTION_MIGRATION_RESULT`。
今回は対象DDLとMigration履歴を書き込んでおり、以前のwrite=0は流用しない。
backfill/dry-run/同期起動/2026業務データ分析は未実施。追加書込み・backfill/dry-runは未承認。
historical_as_of_available=false、Growth不採用、C1/旧成果物、2026凍結、Goal 4/5を維持する。
証跡: `/home/shinya/neo-keirin-artifacts/stat35-production-migration-01/run-20260922-093636-fa14dd06/`。
詳細は [stat35-production-application-01.md](stat35-production-application-01.md)。2文書のみ変更し、未コミットで適用結果レビューを待つ。

---

## 15.40 STAT-35-PRODUCTION-BACKFILL-PILOT-01

PR #67 MERGED、Migration結果レビュー完了。開始mainは `15bb52de18eb5908a01d181d8177f33c0b1ca583`。
最新ユーザー指示により2024-12-31だけの正式backfill・READ ONLY保存照合・保存後dry-runを許可した。
Section 15.39以前の未承認・未実施・本番write=0は当時の記録として保持する。
cleanなmainから `ops/stat35-production-backfill-pilot-01` を作成し、既存コードを変更せず使用した。

前回dry-runは2026-09-22 10:33:10 JST、exit 0、0.753224769秒、peak 37,748,736 bytes。
success/skipped/failed=75/0/0、observations/current_updates=490/490（予定）、NO_IMPORT/NO_IMPORT_UNSUPPORTED=0/0、dry_run=true、batch_run_id=null。
証跡は `/home/shinya/neo-keirin-artifacts/stat35-production-backfill-dryrun-01/run-20260922-103047-97c266fc/`。保存前dry-runは繰り返していない。

READ ONLYで対象source/dateだけを保存前記録。75 race・75 import・490 entry・490 result・525 payout。
前回のimport ID・Rawパス・source_hashは全件一致し、既存観測0、agari3列は全490行未補完。
512M・chunk100・接続限定read_only=off/lock_timeout=5s/statement_timeout=5minで正式コマンドを1回実行。
11:12:13～11:12:14 JST、exit 0、1.181310603秒、peak 37,748,736 bytes、stderr空。
正式summaryはsuccess/skipped/failed=75/0/0、observations/current_updates=490/490、NO_IMPORT/NO_IMPORT_UNSUPPORTED=0/0、dry_run=false、batch_run_id=120。
BatchRun 120はSUCCEEDED、AGARI_IMPORT全75 itemがSUCCEEDED、FAILED/RUNNINGなし。前回import集合・summary・itemは一致。

保存前後の実DB差分は観測追加490、agari3列の現在結果補完490。予定・正式summaryと一致し、各importの増分も一致。
観測/現在値ともVALID 477・MISSING 13。NULLと0は区別し、decimalをfloatへ変換せず照合。
各現在行自身のimport＋bikeに対する観測値不一致0、観測重複0、出典不一致0。
race_resultsのagari3列以外（ID・順位・状態・import参照・fetched_at・updated_atを含む）、races/entries/payouts/importsと参照fetch metadataは不変。
origin=BACKFILLED_FINAL_RESULT、publication_timestamp=UNKNOWN。既存観測の上書き・削除なし。

保存照合後だけREAD ONLYのdry-runを1回実行。11:13:22～11:13:23 JST、exit 0、1.080487291秒、peak 37,748,736 bytes、stderr空。
success/skipped/failed=75/0/0、observations/current_updates=0/0、NO_IMPORT/NO_IMPORT_UNSUPPORTED=0/0、dry_run=true、batch_run_id=null。対象import集合も不変。
これは保存後の追加・更新不要の確認であり、2回目の正式書込みではない。全success件数はimport単位である。

今回の証跡は `/home/shinya/neo-keirin-artifacts/stat35-production-backfill-pilot-01/run-20260922-110730-e4408a8c/`。
状態は `SAVED_AND_VERIFIED_AWAITING_REVIEW`、次は今回1日分の結果レビューだけ。
今回は観測・現在値・batch監査の本番書込みがある。全2022-2025期間の完了とは扱わず、他期間書込みはNOT_AUTHORIZED。
backup取得/一覧確認成功・復元試験未実施を維持し、再取得/再hash/復元/Migration/構造28項目再監査はしていない。
STAT計算・学習・予測評価・新規取得・同期起動・2026レース参照は未実施。historical_as_of_available=false、既存C1、2026凍結を維持。
詳細は [stat35-production-application-01.md](stat35-production-application-01.md)。2文書だけ更新し、未コミットでレビューを待つ。

---

## 15.41 STAT-35-PRODUCTION-BACKFILL-2022-2025-01

PR #68 MERGED、pilot結果レビュー完了。開始main/実行SHAは `88ac8ff4677dde5c53a40ef028e5d83923671e07`。
cleanなmainから `ops/stat35-production-backfill-2022-2025-01` を作成し、既存コードは変更せず使用した。
最新ユーザー指示により、source=`keirin_jp`、2022-01-01～2025-12-31のうち保存済み2024-12-31を除く
観測追加・current agari3列補完・BatchRun/Item記録、前後READ ONLY照合と各区間1回の保存後dry-runを許可した。
Section 15.40以前の他期間未承認・レビュー待ちは当時の記録として保持する。

日付ライブラリで48暦月区間・1,460日を固定し、重複/対象外日付/除外日以外の欠落なしをDBアクセス前に検査。
2024年12月だけ12-01～12-30。昇順・逐次で全48区間を実行・照合し、未着手0、対象import 0の区間0。
正式実行は各月1回、BatchRun 121-168は全てSUCCEEDED、FAILED/RUNNING itemなし。
各PHPは512M・chunk100、接続限定lock_timeout=5s/statement_timeout=5min、子プロセス上限30分。
全正式実行・全保存後dry-runがexit 0、stderr空、タイムアウト/再試行なし。

| 年（今回分） | success/import | skipped/import | failed/import | 観測実追加 | 現在結果実補完 | NO_IMPORT/race | NO_IMPORT_UNSUPPORTED/race |
|---|---:|---:|---:|---:|---:|---:|---:|
| 2022 | 24,824 | 41 | 0 | 173,847 | 173,847 | 3 | 0 |
| 2023 | 51,076 | 32 | 0 | 362,784 | 181,392 | 7 | 0 |
| 2024（12-31除外） | 25,526 | 14 | 0 | 181,357 | 181,357 | 9 | 0 |
| 2025 | 25,507 | 26 | 0 | 181,518 | 179,751 | 10 | 0 |
| 合計 | 126,933 | 113 | 0 | 899,506 | 716,347 | 29 | 0 |

正式summaryと実DB増分は各区間・各importで一致。skipは全113 importがCANCELLED。
観測のagari_statusはVALID 879,184 / MISSING 20,319 / INVALID_FORMAT 0 / OBSERVED_ABNORMAL_RESULT 3。
補完した現在結果はVALID 700,405 / MISSING 15,939 / INVALID_FORMAT 0 / OBSERVED_ABNORMAL_RESULT 3。
観測はimport-version行、現在結果は採用importに対応するcurrent行であり、件数を同一視しない。
年別status、全月batch ID、gap race ID/カテゴリ、skip import ID/理由は下記手順文書と証跡に保存した。

保存前後照合は全区間成功。既存観測変更、import＋bike重複、出典/現在値不一致、非agari変更は全て0。
対象races/entries/payouts/imports/fetch metadataと現在結果のimport参照を含むagari3列以外は不変。
NULLと0を区別し、decimalはfloat化せず照合。origin=BACKFILLED_FINAL_RESULT、publication_timestamp=UNKNOWNを維持。
全48区間の保存後READ ONLY dry-runはfailed=0、dry_run=true、batch_run_id=null、observations/current_updates=0/0。
dry-run後も対象import集合・業務行・当該batch/itemは不変。これは追加・更新予定0の確認であり、正式書込みの再実行ではない。

2026-09-22 15:58:43.894883～17:07:00.259958 JST、照合を含む処理全体4,096.365072123秒（約68分16秒）。
正式実行時間合計1,943.007422218秒、保存後dry-run合計1,653.220918562秒、最大peak 37,748,736 bytes（36 MiB）。
pilot BatchRun 120の対象9種の保存行は旧保存後snapshotと一致。pilotのRaw再解析・コマンド再実行なし。
pilotの実績490/490（VALID 477 / MISSING 13）は不変で、1回だけ加えた統合実増分は観測899,996 / 現在結果716,837。

証跡: `/home/shinya/neo-keirin-artifacts/stat35-production-backfill-2022-2025-01/run-20260922-155146-60eeb526/`。
実行スクリプトはリポジトリ外へ保存しphp -l成功後に実行。全ログ、分割snapshot、月/年/合計report、pilot終端照合を保持。
詳細は [stat35-production-application-01.md](stat35-production-application-01.md)。次は今回の結果レビューのみ。
これは処理範囲の完了であり、gap/skip/MISSINGの解消やas-of回復・STAT採用・精度改善を意味しない。
historical_as_of_available=false、PUBLICATION_TIME_UNKNOWN、旧成果物・C1・2026凍結を維持。
Migration/backup/旧監査/全テスト/Pintの再実行、新規取得、同期、STAT、学習、予測評価、2026レース参照はしていない。
追加書込みや次実装へ自動移行せず、2文書だけを未コミットで結果レビュー待ちとする。

---

## 15.42 STAT-35-37-TRACK-CONTEXT-01

PR #69 MERGED、2022-2025保存結果レビュー完了。開始main/originは `3d03273ab8aee5a23e8fd317e98a2dc7bad8e9e6`。
最新ユーザー指示は今回の構造・計測定義マスタ、日付解決、純粋距離換算、限定公式資料取得とテストだけを許可した。
過去のNOT_AUTHORIZEDは当時の記録として保持し、学習/STAT得点/予測利用/追加backfillへ拡張しない。
clean mainから `feature/stat35-37-track-context-01` を作成し、既存保存・scraping・予測・設定・DB schemaは変更していない。

接続開始からREAD ONLYを確認し、keirin_jpの2022-2025対象場ID/外部コード/名称/日付だけを保存。
実対象42場・10,660場日。業務結果・上がり全件・順位・払戻は再取得していない。
公式用語集/Q&Aから上がり半周定義、公式2012資料から対象全場の構造4項目、西武園静的イベント案内と熊本静的施設案内を確認。
固定v1は42場44観測版。公表時刻/計測精度/屋内外は未確認のまま保存し、原文・URL・取得時刻・hash・項目出典を保持。
過去時点の表は2022年以降の通年根拠にせず、西武園の資料が明示する2022-06-28～30だけを限定採用。
熊本の旧500m/現400mは別観測とし、現在値を過去へ遡及しない。他40場の個別現在案内/改修履歴確認は未完了。

日付は根拠付き `[from, until)`、期間重複はSOURCE_CONFLICT、期間不明はUNKNOWN_LAYOUT_VERSION、最新値fallbackなし。
実coverageを1回生成: RESOLVED 3、UNKNOWN_LAYOUT_VERSION 10,657、SOURCE_CONFLICT 0、距離解決3場日。
実際の上がり秒数は読まず、速度計算は人工値だけで検証。FINISHED/TIED + VALID + 確認距離/定義のみ、欠損はnullと理由。
exact decimalによる半周換算、出力だけ12小数桁half-even、m/sの丸めをkm/hへ持ち越さない。
新規/関連78 tests / 222 assertions、追加の原文整合性検査後の影響範囲55 tests / 156 assertionsは成功。
全テストはtesting/SQLiteメモリDBで1回実行: 1,932件中1,923成功・既存9skip、14,489 assertions。新規skipなし。
変更PHPのPint --testと全10 PHPの構文検査も成功。

証跡: `/home/shinya/neo-keirin-artifacts/stat35-37-track-context-01/run-20260923-104546-92962a55/`。
詳細・場別表・原典採否・コマンド・残課題は [stat35-37-track-context-01.md](stat35-37-track-context-01.md)。
コード検証成功と歴史全期間網羅を分離し、SCR-STAT-35-02/STAT-35全体/STAT-37全歴史網羅をCOMPLETEDとしない。
今回の本番DB書込み、Migration、agari再処理、旧backfill/dry-run/監査/backup再実行、STAT得点、学習、予測評価は0。
現在静的構造取得は限定許可内。2026結果/出走表/オッズ/bank record取得・分析は行わない。
historical_as_of_available=false、旧C1/成果物、2026凍結は不変。次は実装とcoverage不足のレビューだけ、未コミットで停止する。

### PR #70 Review Fix / 2026-09-23

上記の初回テスト・coverageは当時の結果として保持する。配布抜粋とDIRECT値・期間の意味照合は当時未実装だった。
開始HEAD `5d69de51c93fc5f6d24e1025a13d31f655355f8b`、同じPR branchのclean状態から2指摘だけを修正した。
P1は旧抽出が `table.hyo3` の先頭（記録表）を選んでいた問題。指定の保存済み静的Rawのサイズ/hashを照合し、
周長・ホーム傾斜角・センター傾斜角の3ラベルが揃う唯一の表を再抽出。400m / 2°51′45″ / 29°26′54″はactual Fixtureとも一致。
実meta原文の「2022年6月28日～30日に西武園競輪場で開催される」も保持し、手書き期間コメントへの依存を除いた。
旧外部抽出スクリプトは不変。修正版 `extract-review.php` は新証跡へ保存した。

P2はhash/sizeとJSON内部整合だけでは原文の取り違えを検出できない問題。
ロード成功前に4形式の同梱抜粋を解析し、source_refs・台帳のtrack_id/場名行/列とDIRECT raw/value/unit、
原文の期間・半周定義・DERIVED出典連鎖を照合する。伊東/伊東温泉、向日町/京都向日町は明示aliasのみ。
decimal/DMSをfloatへ変換せず、抜粋と異なる整合的JSON改変、別場参照、行/項目/期間欠落・重複・単位違いを拒否する。
runtimeは同梱資料だけで完結し、本番DB・HTTP・外部Rawへ依存しない。値・計算式・丸め・結果状態は不変。

修正前manifest SHA: `d8eb9cbfff17e64ab4a97ed7d1f8b33ea8ec86f6f5af2824f2754cd604b42b71`。
修正後manifest SHA: `d987a6eed079a8370492e57cc92e51611145e42705ae6c68866478e32e35da8a`。
固定targetsのSHA `9826917544d22feb5ccbce9855151e293278a974e084f362ec1a88c23e001067` は不変。
修正版coverageは512M・offlineで1回、exit 0・stderr空。manifest SHA以外のJSON全内容が旧結果と一致した。
42場44版、10,660場日中3解決/10,657期間不明/競合0。旧manifest・coverage・Rawは上書きしていない。
TrackContext 84 tests /736 assertions、隔離SQLite全体1,952 passed /既存9 skip /15,069 assertions、変更PHP6本のPint/構文検査成功。
人工Fixtureも原文照合の実体を持ち、検証迂回なし。新規29ケースで配布実ファイル統合と再封印改変拒否を確認した。
証跡: `/home/shinya/neo-keirin-artifacts/stat35-37-track-context-01/pr70-review-fix-20260923-125144-2u5Yb8/`。
詳細は [stat35-37-track-context-01.md](stat35-37-track-context-01.md)。PR #70再レビュー待ちでありMERGED/承認済みではない。
本番DB接続・新規取得・旧処理再実行0。40場の個別案内未確認、歴史期間不足、SCR全体未完了、
historical_as_of_available=false、prediction_use=NOT_AUTHORIZED、旧C1/成果物・2026凍結を維持する。

---

## 15.43 STAT-35-37-TRACK-CONTEXT-02

PR #70 MERGED・2件の指摘対応レビュー完了。上記15.42の未マージ/再レビュー待ちは当時の記録として保持する。
開始main `24a217e2fdfd21c7df490c55a08cb34760031e80`、cleanから `feature/stat35-37-track-context-02` を作成。
今回の許可は公式構造/歴史資料取得、根拠付きv2、限定Parser/検証拡張、テストとoffline比較のみ。
旧NOT_AUTHORIZEDを当該範囲に限り更新し、本番DB接続・agari再処理・STAT得点・学習・予測評価へ広げない。

固定42場の入口と公式案内/歴史候補を一巡。37場のnavigation確認、5場は取得/文字コード変換不能。
一巡を全場の資料確認完了としない。全URL・エラー・採否・不足を台帳化し、無期限の再取得はしない。
2023年版年間記録集の実表から全42場の構造4列を追加したが、構造基準日がないため全42版の期間はUNKNOWN。
前橋公式2023年programの4日、平塚公式2024年11月programの2開催7日だけ新たに期間確認。
平塚11月7日を橋渡しせず、熊本2024-07-20再開を現400m/全幅員の開始日へ転用しない。
原PDF/表見出し・列・画像開催日を確認した最小actual抜粋と生成手順を保持。記録/選手情報をマスタへ混ぜない。

v2は42場89観測版。新しい3形式を明示的に解析し、DIRECT値/単位/場/期間、半周DERIVEDの根拠をロード時照合。
v1の9ファイル、44観測版、旧3日解決、AgariSpeedCalculatorの式/丸め/状態と旧Raw/成果物は不変。
v2 manifest SHA: `3ece1b4066b4b50d326889a1f341d1fee57bdf523029c0fd4d8139b0923a6c35`。
固定targets SHA `9826917544d22feb5ccbce9855151e293278a974e084f362ec1a88c23e001067` と全場日集合/分母は不変。
512Mで実coverageを1回だけ生成: RESOLVED/距離解決14、UNKNOWN 10,646、SOURCE_CONFLICT 0、後退0。
前橋+4、平塚+7、西武園3不変、他39場は0。項目別対象日: 周長/距離/カント14、直線11、直線傾斜3、幅員/屋内外0。
128M隔離testingでTrackContext 108 tests /1,757 assertions成功、全体1,976 passed /既存9 skip /16,090 assertions成功。
変更PHP4本の限定Pint/構文検査成功。新規24ケース、人工改修/競合はtest内だけで実マスタへ混入なし。

証跡: `/home/shinya/neo-keirin-artifacts/stat35-37-track-context-02/run-20260923-061824-fe45e340/`。
詳細は [stat35-37-track-context-02.md](stat35-37-track-context-02.md)。値掲載と歴史適用、公表時点、コード成功を分けて記録。
歴史期間10,646日、取得不能5場、屋内外/幅員/測定精度/公表時点は未確認。SCR/STAT-35/37全体は未完了。
本番DB接続/書込み0、Migration/旧backfill/dry-run/backup/監査再実行0、agari再処理/STAT/学習/予測評価0。
2026取得の静的構造資料は限定許可内。2026レースを目的とした取得/分析はなし。
historical_as_of_available=false、prediction_use=NOT_AUTHORIZED、旧C1/成果物・2026凍結を維持。
次は今回のv2・根拠・未確認期間のレビューのみ。未コミットで停止し、次工程へ自動移行しない。

---

## 15.44 STAT-35-RACE-RELATIVE-01

PR #71 MERGED、TrackContext v2の部分拡充レビュー完了。開始main `7f2a59d15af13785d36471f9f86c1dfa03f1222b`。
clean mainから `feature/stat35-race-relative-01` を作成。同一レース内相対化を先行するユーザーの限定許可により、保存済み現在結果/import/観測のREAD ONLY接続、純粋計算とoffline生成経路を追加した。
計算は実データ確認前に固定。正確なdecimalによる順位min/同タイム平均順位/percentile/最速秒差、n<2のnull、正常完走のタイム欠損によるPARTIALを明示する。
現在結果自身のimport+bikeだけを参照し、版混在・件数・状態・観測/出典hash不一致はレース単位除外。旧import二重計上や補助ID差の誤判定を防ぐ。
AGARI_TIME・保存/正規化/元Parser版・公式出典とv2半周定義を対応付け、共通定義不明と数値距離不明を区別する。v2が解決する場合だけ既存Calculatorの未補正速度を併記する。
構造マスタ/速度式/保存/backfill/import/予測/Migrationを変更せず、前工程の構造期間10,646場日未解決は維持した。

初回関連テストで新規decimal API名の誤りを検出・修正。影響テスト42件/441 assertions成功、最終128M隔離全体2,018 passed・既存9 skip・16,543 assertions成功。
変更PHP11本の限定Pint・構文検査成功。人工のoffline再現はbytes一致。実データ再現とは区別する。
指定PGOPTIONSでexportを1回だけ実行したが、2026-09-23 17:41:43-17:41:44 JST（0.251秒）に終了コード1。
エラーは `Unexpected endpoint or READ ONLY was not enabled before connecting.`。実際の返却設定を保存していないため、不一致が接続先か実効READ ONLYかは未確認であり推測しない。
事前確認は業務データ照会・入力ディレクトリ作成より前。接続設定SELECT以外の本番業務データ読取り/書込みは0。
入力レース数/現在結果行数、完全/部分/不能、相対値/速度行数、年×開催グレード集計は未生成・未確認。build/再現は未開始。失敗時peak memoryは未記録。
証跡: `/home/shinya/neo-keirin-artifacts/stat35-race-relative-01/run-20260923-083705-9f02c3d4/`。
詳細は [stat35-race-relative-01.md](stat35-race-relative-01.md)。失敗ログを保持し、再接続/再export/自動修復はしない。
状態は `IMPLEMENTED_TESTED_EXPORT_PREFLIGHT_FAILED_AWAITING_REVIEW`。実生成成功・READ ONLY検証成功・STAT-35/37全体完了とは記録しない。
`FINAL_RESULT_DESCRIPTIVE_ONLY`、historical_as_of_available=false、prediction_use=NOT_AUTHORIZED、points=nullを維持する。
Raw再解析・HTTP・学習・予測評価・2026レース参照なし。C1/旧成果物不変。次は事前確認失敗のレビューのみ、再試行は新規指示を待つ。

### PR #72 Review Fix / 2026-09-23

上記の初回停止は当時の記録として保持する。旧返却値は未記録であり、IPだけが原因だったとは断定しない。
最新ユーザー指示は3指摘の修正、追加export 1回、固定入力のbuild 1回・offline再現1回を許可した。
開始/終了HEADは `16f3d20e603688542d5b2d2e068fd50d3e009807`、同じPR branch上の未コミット差分。PR #72は未マージ・レビュー承認未完了。

- 接続hostを `host(inet_server_addr())` とし、列順/port型に依存せず各項目を検証。portは整数/桁列だけを正規化、欠落/不正/READ ONLY offを拒否。field/expected/actual/actual_typeの限定診断と失敗時peakを記録し、認証/URL/PDO設定は出力しない。
- 現在結果行自身のrace_idは必須正整数。schema不正は公開せず、親と異なる正整数は `CURRENT_RESULT_RACE_ID_MISMATCH` で全レース除外。import/観測が正しくても検出し、補助ID差の従来許容は維持。
- 空入力は共通10カウンタを整数0で保持。groups=[]、CSVヘッダーのみ、5成果物byte-exact。通常の計算式・集計順は不変。

新規51ケース。PostgreSQL Connection doubleによる実分岐/SQL/transaction順序/拒否時業務照会なし、再seal入力改変、単体Calculator、空入力を検証した。
関連128Mは201件中200成功・テスト側出力取得1件失敗（3,035 assertions）、helper修正後は該当1件/6 assertions成功。初期helper名衝突も修正し履歴を記録。
共有128M全体は既存監査テスト中に終了（原因未記録）、その報告テスト単独1件/5 assertionsは成功。
全件process-isolationは既存Closure providerをserializeできず実行不能。
testing/SQLite・共有512Mで完走した全体は2,078件中2,068成功・既存9skip・1失敗、17,382 assertions（34.902秒）。
残る失敗は既存Stat35DataReadinessAuditTestの共有processピーク135,266,304 bytesを128MiB未満とする検査。
該当ファイルだけ独立128Mで再確認し76件/225 assertions成功。対象外テスト/閾値は変更せず、全件PASSとは記載しない。
変更PHP10本の限定Pint/構文検査成功。新規skip・assertion削除なし。

本番exportは18:54:18～18:54:36 JST、18.213535178秒・peak48,234,496 bytes・exit0。
実接続database=neo_keirin_prediction_db / public / 127.0.0.1:5432、session/transaction READ ONLY=on、
isolation=repeatable read、snapshot_read_only=on、snapshot=274247:274247:を入力manifestへ保存。
同一snapshotから101,326レース・716,837現在結果行を取得。診断専用の別接続なし、本番書込み0。
DB不要buildは22.622826945秒、再現は22.625007102秒。各peak37,748,736 bytes・exit0、全工程stderr空、timeout/再試行なし。
正常完走706,843、有効タイム/比較700,882、相対値700,869、速度1,079行。
完全95,712・部分5,475・比較不能139レース。非排他的理由はCANCELLED97、NO_CURRENT_RESULTS126、RESULT_NOT_FINAL29、INSUFFICIENT_COMPARISON13。
入力と明細の件数、年×開催グレードの全10カウンタ合計が一致。UNKNOWN0、全24区分の表は `docs/stat35-race-relative-01.md` に記録。
details/summary JSON/CSV/manifest/COMPLETEの5ファイルがbytes/SHA-256一致。旧実入力は未生成のため旧実集計との比較はしていない。
証跡: `/home/shinya/neo-keirin-artifacts/stat35-race-relative-01/pr72-review-fix-20260923-185418-dbdce2/`。
旧失敗ディレクトリ・v1/v2マスタ・速度式・既存保存処理は不変。今回の業務読取りを旧read=0と区別する。
状態は `DESCRIPTIVE_DATASET_GENERATED_REPRODUCED_AWAITING_REVIEW`。次は修正・実生成結果と共有peakテスト制約のレビューのみ。
FINAL_RESULT_DESCRIPTIVE_ONLY、historical_as_of_available=false、prediction_use=NOT_AUTHORIZED、points=nullを維持。
STAT-35/37全体未完了、既存C1・2026凍結は不変。Raw再処理/本番書込み/学習/予測評価/2026レース参照は0、次工程へ自動移行しない。

### PR #72 Hermetic Memory Test Fix / 2026-09-27

上記Codex実行の1件失敗は履歴として保持する。その後のユーザー実行は別記録で、
2,064成功/既存9skip/5失敗/17,382 assertions。Growthの4件は135,266,304 bytes、監査Serviceは137,363,456 bytesだった。
今回の開始/終了HEADは `b37c516458d5f398adb5b7d10fb2d663d3f0f6aa`、同じPR branchで開始時clean。
最新指示により指定5件・テスト専用helper/回帰・2文書だけを変更。業務コードと元assertion/Fixture規模は不変。
PHP_BINARYから各対象だけを128Mで起動し、実効ini/PID/元処理peakとJUnitの対象1件/実assertions/成功を照合する。
子でsetUp/tearDownを実行し、Closure/接続/Fixtureをserializeしない。失敗/未実行/skip/壊れた結果を成功にしない。

最初のfocusedは対象5子すべて成功、親32件中2件の新helper期待値不一致（OOM表示/0件exit）を検出。
当該回帰を修正後、影響7件/24 assertions成功。最終全体は1回、2,105件中2,096成功/既存9skip/失敗・エラー0、
親17,268 assertions、exit0、36.491467秒。別集計の対象5子は242 assertions、各実効128M、peak42,467,328～44,564,480 bytes。
さらに別512M親のpeak146,804,736 bytesからの実pagination子も128M/42,467,328 bytesで成功（14 assertions）。
共有全体processへ人工的な巨大割当ては行わない。補助回帰27件すべて成功、旧192 assertionsは子へ保持し架空加算なし。
既存9skipはSQLiteでは実施しないPostgreSQL専用条件によるもの。新規skip/閾値緩和なし。
7 PHPの構文検査と限定Pint成功、git diff --check成功。過去失敗を成功へ書き換えない。

証跡: `/home/shinya/neo-keirin-artifacts/stat35-race-relative-01/pr72-hermetic-memory-fix-20260926-205955-1608979c/`。
詳細の5件別PID・ピーク・コマンド・親子件数は [stat35-race-relative-01.md](stat35-race-relative-01.md) に記録。
当時のtest_caveatは `RESOLVED_FIVE_TESTS_ISOLATED_128M_FULL_SUITE_PASS`（下記completion前の直接PHPUnit成功記録）、実生成状態は変更しない。
本番接続/実export/build/reproduce/Raw参照/2026実データ参照0。旧実成果物の再hashも行わない。
Version1.35・remote mainは不変、PR #72未マージ/未承認。次は今回のテスト修正レビューのみ。
historical_as_of_available=false、prediction_use=NOT_AUTHORIZED、points=null、C1/2026凍結を維持し未コミットで停止。

### PR72-HERMETIC-MEMORY-COMPLETION-01 / 2026-09-27

前回の直接PHPUnit成功後、ユーザー提出の通常 `php artisan test` は別実行で1失敗。
GrowthTrendAdjustmentCalibrationTestのService戻り値peak_bytes=135,266,304 bytesが128MiB未満の判定に失敗した。
過去Codex 1失敗、ユーザー5失敗、前回直接PHPUnit成功、今回提出Artisan 1失敗を混同しない。
今回の開始/終了HEAD: `816a80413b004f4291d3ae352cf27977e9647d45`、同じPR branch、開始clean。

tests/全体の静的検索と必要なService生成元確認で、同種は16件（既存分離5・未分離11）。
追加はGrowthTrendAdjustmentCalibration/GrowthAdjustmentCalibration各1、GrowthPointAnalysis/v2各1、
Bt03e04/05BoundedMemory各2、Bt03e06/07/08BoundedMemory各1。
既存helperへ登録し、元Fixture/Service/全assertion/厳密閾値を保持したままdelegate・最終recordを追加。
生涯ピークの絶対128MiB判定で未分離の残存0。増分判定・記録のみ・既存独立検証は変更しない。
高ピーク親は別512M processで作り、ケース名を渡して全16件の子128Mを確認する。
Service/app/設定/本番成果物は不変。E08テスト既存returnのインデント1行のみ限定Pintに合わせ修正。

関連53件/親281 assertions成功、exit0。変更PHP12本の構文・限定Pint・git diff --check成功。
最終は `php artisan test` を通常のまま1回実行し、2,120件中 **2,111成功/既存9skip/失敗・エラー0**、
親 **17,379 assertions**、exit0、51.062072秒（Artisan表示50.92秒）。テスト選択/順序/設定変更なし。
親CLI既定memory_limit=-1は変更せず、計測対象の子はすべて実効128M。
本体の16子は **451 assertions**、peak23,068,672～44,564,480 bytes。
別の高ピーク親16件は各146,804,736 bytes、各子は上記と同じpeakで成功、別集計451 assertions。
親assertionsへ子の値を加算しない。helper回帰42件すべて成功、新規skip/閾値緩和/検証削除なし。

証跡: `/home/shinya/neo-keirin-artifacts/stat35-race-relative-01/pr72-memory-completion-20260926-213318-6d625ace/`。
修正前後一覧、stdout/stderr、実終了コード・時間、子PID/実測・JUnit、高ピーク親、構文/Pint/差分を保存。
各メソッド・子PID・peak・assertionsは [stat35-race-relative-01.md](stat35-race-relative-01.md) のcompletion節。
現在のtest_caveatは `ISOLATED_MEMORY_CHECKS_AND_ARTISAN_FULL_SUITE_PASS`、分離対象16件。
Version1.35・remote main・実生成状態・件数は維持。実データ再読取り/hash/再生成なし。
本番接続/Raw/HTTP/Migration/backfill/学習/予測評価/2026実データ参照0。
PR #72は未マージ・未承認。historical_as_of_available=false、prediction_use=NOT_AUTHORIZED、points=null、
C1/2026凍結を維持。次は今回のテスト補完レビューのみ、未コミットで停止する。

---

## 15.45 STAT-35-PLAYER-HISTORY-01

本節は初回生成・PR #73マージ前レビュー修正の**当時の記録**。未マージ/未コミット/次は再レビューという記述も保持する。
2026-09-28のマージ後現在地と今回のdocs-only許可は15.46を参照。生成時code identity・hash・数値を現在mainへ置換しない。

### 初回生成記録（2026-09-27）

PR #72 MERGED_REVIEW_COMPLETEDは最新ユーザー確認による。15.44の未マージ/旧失敗/成功は当時の記録として残す。
clean main `baac9c113d8a61061d7e5f8bd2b7cd391d091c54` から `feature/stat35-player-history-01` を作成。
今回だけ許可された固定相対値の選手別履歴実装・人工テスト・実生成/offline再現を完了した。旧NOT_AUTHORIZEDを予測利用へ拡張しない。

外部6桁ID＋sourceを本人キー、input contextの開催期間/場とsealed classificationのrace_classを系列に使用。
COMPLETE/CALCULATED行の正確なpercentile分数から開催平均を作り、開催間は等重み。
過去終了日<対象開始日、別開催だけの直近3/6/12観測開催を先に選び、NULLも枠へ保持。
exact rational平均/中央値/母分散（2値以上）、12桁half-even、非重複の直近3－前3を生成。
期間重複はID順に決めず理由付きNULL。対象自身/未来の結果で対象履歴は変化しない。
窓は固定した候補であり最適化未実施。全開催出走網羅・過去公開時点・能力成長の証明ではない。

入力は旧固定races/detailsの指定bytes/SHAに一致。旧generation codeと新processing codeを分離し、START/END照合成功。
本番DBなし、専用PDO SQLite作業領域による逐次集約。実生成/再現各1回、exit0、stderr空、175.620946/174.343540秒、各peak33,554,432 bytes。
全101,326レース/716,837結果行（結果0の126レースを含む）を保持。識別可能2,436選手、本人不明/重複0。
3,491開催、選手×開催×class241,464群、値あり235,725群。クラスUNKNOWN322レース/2,215行/760群は保持。
全枠数値ありは3/6/12で640,112/573,435/457,715行。推移573,435行、NULL143,402行。
推移NULLは観測不足68,633、窓内欠損64,848、文脈/順序不明9,921。開催重複による窓停止は4,152/7,706/13,775行。
開始/終了境界は191/676行。PARTIAL39,250、異常9,994、AGARI_MISSING15,952、異常数値3などの非排他的理由を保存。
境界・欠損・クラス/順序不明を0で埋めず、期間/場の矛盾・本人識別問題は実入力で観測0。

6成果物のbytes/SHA完全一致。独立スクリプトで明細・年/grade/class/窓別件数と全選択履歴の日付条件を再照合しexit0。
新規32テスト、関連86件/881親assertions成功。初回大容量試行は実行中のコード変更を終端検査が拒否し、失敗証跡を保持。
最終PHPで通常 `php artisan test` を1回: 2,144成功/既存PostgreSQL限定9skip/失敗・エラー0、17,677親assertions、exit0。
13 PHPの構文/限定Pint成功。既存16件＋新規1件を独立128M検証し、新規108,000行ケースはpeak48,758,784 bytes。
高ピーク親下でも17件成功。直接子/高ピーク親下の子は各107,929 assertionsで、親件数へ加算しない。

証跡: `/home/shinya/neo-keirin-artifacts/stat35-player-history-01/run-20260927-072445-4FJxV4/`。
詳細は [stat35-player-history-01.md](stat35-player-history-01.md)。生成物・ログ・検証スクリプトは最初から指定永続root配下。
状態は `DESCRIPTIVE_PLAYER_HISTORY_GENERATED_REPRODUCED_AWAITING_REVIEW`。次は今回成果レビューだけ。
analysis_mode=FINAL_RESULT_DESCRIPTIVE_ONLY、history_time_basis=EVENT_DATE_BACKFILLED_FINAL_RESULTS、historical_as_of_available=false、prediction_use=NOT_AUTHORIZED、points=null。
STAT-35/37全体、構造・気象・ライン・相手水準補正は未完了。C1、過去採否、2026凍結は不変。
本番DB接続/書込み、旧実export/build再実行、Raw/HTTP/Migration/backfill/学習/予測評価/2026実レース参照は0。
未コミットでレビュー待ち。次工程へ自動移行しない。

### PR #73 行単位本人識別のレビュー修正（2026-09-28）

上記初回結果・失敗・hashは当時の記録として保持する。今回の開始/終了HEADは `4e95ddb1009feb5cf14fc22035d67fefe9979c65`、
既存 `feature/stat35-player-history-01` のcleanな状態から追加修正。remote main/versionは変更しない。PR #73は未マージ・再レビュー待ち。
旧実装は行のIDENTITY_CONFLICTをgroup.context_flagsへ追加し、正常行の開催平均もNULL化して後続履歴から開催全体を外していた。
Meetingsは開催/系列対応と行採否を分離し、IDENTIFIED行があれば正常行だけの正確な平均を保持する。
競合行はevidence/exclusionへ残し、本人未確定しかない群は正常履歴候補にしない。本人確認済みのタイム欠損はNULLの観測枠を維持。
Builderは競合/未解決の対象行だけを本人識別理由でブロックし、3/6/12窓のmeetingsは空、各統計/推移はNULL、summaryも出力行に従う。
正常対象行の共有historyは変更しない。真正な期間/場/class/重複/同日終了/境界ブロック、3/6/12窓、Exact/Source/Contract/versionは不変。

旧コードで最小混在Fixtureが期待平均1に対しNULLとなり失敗したことを記録（1 test / 4 assertions、exit1）。
新規8ケースは正常1＋競合2、採用1/除外2、後続へ1枠、正常対象に非NULLの過去履歴、競合対象のみブロック、summary照合、
競合値/追加からの独立、本人未確定と確認済みNULL枠の区別、真正な開催不整合、6成果物人工再現を確認。
途中の関連テスト1失敗は新テストの配列キー順期待値のみを既存表現へ修正し、失敗ログも保持。
最終関連テスト41成功/734親assertions。通常 `php artisan test` は最終PHPで1回、2,152成功/既存9skip/失敗・エラー0、
18,129親assertions、exit0、156.510868秒。変更PHP3件の構文/限定Pint成功。
既存17件の独立128Mと高ピーク親下17件は全成功、player-historyの108,000行ケースpeak50,855,936 bytes。
Fixture縮小/閾値緩和/assertion削除/新規skipなし。子assertionsは親件数へ加算しない。

許可された固定入力から修正版生成1回/再現1回、exit0、178.394141/175.937277秒、各peak33,554,432 bytes、stderr空。
実測101,326レース/716,837行、2,436外部ID/241,464群/235,725数値群、推移573,435/NULL143,402。本人不明/競合とも0件。
新旧4データファイルはbytes/SHA完全一致、変更行0。修正版6成果物もbytes/SHA完全一致。旧manifest/COMPLETEとの一致は要求しない。
新manifestは12,049 bytes / `844440cb08b06b0e69195a6384689bc545e63806ff85237ca36a81f5dd08507e`、
新COMPLETEは92 bytes / `2d63b4657a32c9d36c4a0dca222f283ba238f68142b562887263aaa0f9d11d8d`。
processing code identityの変更はMeetings/Builderだけで、旧hashのコピーなし。START/END seal、公開前検証も維持。
独立逐次比較で年/grade/class/窓/件数・全履歴日付条件を照合しexit0、31.148496秒、peak12,582,912 bytes。
証跡: `/home/shinya/neo-keirin-artifacts/stat35-player-history-01/pr73-review-fix-20260927-210230-b2d48a1f/`。
実行/比較/子processログ、verification、最終差分を保存。旧入力/旧出力/旧証跡は読取りのみで変更なし。

コード状態 `PR73_IDENTITY_SCOPE_FIX_VERIFIED_AWAITING_REVIEW`、実生成状態 `DESCRIPTIVE_PLAYER_HISTORY_GENERATED_REPRODUCED_AWAITING_REVIEW`。
次は `REVIEW_PR73_IDENTITY_SCOPE_FIX_AND_PLAYER_HISTORY_RESULT` のみ。予測利用未承認、FINAL_RESULT_DESCRIPTIVE_ONLY、
EVENT_DATE_BACKFILLED_FINAL_RESULTS、historical_as_of_available=false、points=null、C1/2026凍結、STAT-35/37全体未完了を維持。
本番DB接続/書込み、前工程export/build、Raw/HTTP、Migration/backfill/バックアップ、学習/予測評価/2026実レース参照は0。
未コミットの追加差分で停止し、fetch/add/commit/push/PR操作/merge・次工程移行はしない。

---

## 15.46 STAT-35-NEXT-USE-DESIGN-01 / 2026-09-28

ユーザーの限定指示により、既知のPR #73未マージ記載を同期し、次用途の**未承認仕様案**だけを作成した。
開始main・fetch後origin/mainは `f0834202fcac19919bfa4b8dce0ef9043a457a0a`、cleanを確認して
`docs/stat35-next-use-design-01` を作成。GitHub上のPR #73はMERGED、head
`3a18de5bb08dd7f83cd8e35b176ce58dc7e49893`、merged_at `2026-09-27T21:33:48Z`（06:33:48 JST）。

取得したreviewsは旧headへのCOMMENTED、reviewDecisionは空。マージ事実と、15.45/[実行文書](stat35-player-history-01.md)に残る
修正テスト・生成・再現の成功根拠を引き継ぐ。現headへの明示的APPROVED・全成果物受入の**判定記録未確認**は
修正未完了/失敗とは区別し、今回レビューや実データ処理を再実行しない。マージだけで予測利用を許可しない。

[新規仕様案](stat35-next-use-design-01.md)は `DRAFT_AWAITING_REVIEW`。現行固定契約/提案/未決を分離し、
固定C1出走集合と結果行ベースSTAT35の接続差、結果非依存の本人/開催/class台帳、allowlist、時系列、
同一Outer比較・欠損・Gate・受入条件案を記載。6開催mean一列等の推奨は未承認であり、結果を見て選択したものではない。
46 STATと6エンジンは別表。要件採用、実装、データ、評価、モデル採用を分離し、根拠不足は未確認とした。

変更はMASTER PLAN・player-history実行文書への現在注記/追記・新規仕様案の3 Markdownだけ。
今回テスト/Pint、学習・生成・再現・評価、DB/Raw/競輪HTTP、2026実データ参照は未実施。
旧C1最終fitは固定のまま、STAT35 disclosure・旧pilot保留・E08不採用・Goal 1/2 PARTIAL、Goal 3未完、Goal 4/5 BLOCKEDを維持。
管理状態は `STAT35_NEXT_USE_DESIGN_DOCUMENTED_AWAITING_REVIEW`、次は `REVIEW_STAT35_NEXT_USE_DESIGN_AND_SCOPE`。
`next_implementation_phase=NOT_AUTHORIZED`。文書レビュー待ちで停止し、自動的に次実装へ進まない。

---

## 15.47 STAT-35-C1-INPUT-01 / 2026-09-29

PR #74のマージ済み設計案を保持し、最新指示で今回の独立入力実装・人工試験・保存資料による生成/再現のみ許可。
clean main/origin `029309f2cb0434348a53cd30b74d0419d2f8b6d2`から`feature/stat35-c1-input-01`を作成。
旧C1 solver/モデル/Loader、旧AgariPlayerHistory、DB/Raw/2026/正式成果物は変更しない。

[実装記録](stat35-c1-input-01.md): 既知2022/2023 trainingのlabels/rank/statusを非利用で隔離し、
outcome-free C1＋mean6 sidecarを別版保存。本人/開催/class証拠、NULL観測枠、部分窓、厳密過去境界、窓外重複、
分数→12桁HalfEven→floatを監査。対象結果/内部IDだけの本人fallbackは使わない。
2024/2025評価labelsは未参照。2022/2023原本の結果列同居ファイルは物理的に読んだが、分類/接続/計算には使用しない。

人工35件を含む関連119成功/1,136親assertions。通常`php artisan test`を最終PHPで1回実行:
全2,197件中2,188成功、既存PostgreSQL専用9skip、失敗0、18,279親assertions。Pint限定/変更PHP11本構文成功。
独立128M登録は18件、既存17件を維持。新規100MiB超・11,000レース/55,000出走の子はpeak44,564,480 bytes。

固定資料を各1回生成・独立再現し、exit0、18.687463/19.187037秒、両peak31,457,280 bytes。
99,669レース/706,051出走を保持したが、対象entryの観測外部IDとclassを裏付ける結果非依存資料が未確認。
接続0・有効追加値0、全NULLの理由MISSING_IDENTITY_CONTEXT_EVIDENCE。EMPTYや性能FAILとは別の診断で、
DIAGNOSTIC.jsonのみを作成。未提供資料中の重複/矛盾が0と確認できたわけではない。
14データ/監査ファイルとmanifest一致、C1集合/順序/型/非結果値不変。旧history生成・学習・予測・評価は再実行なし。

証跡: `/home/shinya/neo-keirin-artifacts/stat35-c1-input-01/run-20260929-IDHDYP/`。
manifest SHA `0e68d6adbcddc03fa8ab7b10904f2e0388cec32cc88a0ebe89a20db274f4dd71`。
次はコードと不足証拠のレビューだけ。DB再取得/対象結果fallback/学習/性能評価/2026/LIVEへ進まない。
historical_as_of_available=false、C1固定、旧pilot保留とE08不採用、STAT35/37全体未完を維持。

### PR #75 接続・競合判定レビュー修正

同じPR branch、開始HEAD `05fd969d850ddde2e80f3016647d3bccdcbb94e9` で限定修正。
固定C1 history.targetのseal/7項目対応、確認済みcontext manifest pin、本人/開催/class別の有効性、
キー順非依存比較、本人競合とレース文脈競合の出走単位和集合を検証する。版は `STAT35-C1-INPUT-v2-PR75-CONTEXT-VERIFICATION`。
実本人/class証拠の確認済みリストは空で未接続を維持。人工検証だけを行い、上記14ファイル一致・実行hashは旧v1の記録。
実生成/全NULL再現、本番DB/HTTP/Migration/学習/評価/2026参照は行わない。詳細・テスト結果は[実装記録](stat35-c1-input-01.md)のPR #75節。
これは同じPRのレビュー修正であり、新しい設計・実行許可ではない。旧成果物を保持し未コミットで再レビューを待つ。

## 15.48 STAT-35-C1-CONTEXT-01 / 2026-09-29

PR #75はレビュー修正後MERGED。clean main/origin `e61f7c19dfd7b32d9a0ff1218fc068b7756b1fd5`から
`feature/stat35-c1-context-01`を作成。最新ユーザー指示により、固定C1対象の出走表由来メタデータだけを
READ ONLY/REPEATABLE READの単一snapshotで1回抽出し、切断後に候補bundle生成と独立再現を行う。
許可列はrace_entries 6列、races 7列、race_days 3列、race_meetings 4列。
本人内部IDの欠損/不一致、日付/開催/車番対応、6桁外部ID、class、真の全体競合と個別不備を分離する。
現在保存値の再構成であり、observed_at=null・historical_as_of_available=false。fetched_atは別監査に保存する。

候補はREVIEW_PENDING。COMPLETEは生成/seal完了だけを示し、レビュー済み許可リストへの自動登録はない。
旧入力/診断/モデル/履歴成果物は不変、DB書込み/Raw/結果/2026/学習/評価/mean6生成は行わない。
実行コマンド・検証結果・実測件数・成果物は[実装・実行記録](stat35-c1-context-01.md)へ記録する。

実抽出/生成/再現は各1回、すべてexit0。2022/2023/2024/2025出走170,835/179,007/179,089/177,120。
全出走で保存DB・6桁外部ID・内部本人ID・開催が一致。候補170,739/178,849/178,992/176,468、
保留96/158/97/652はすべてUNKNOWN_RACE_CLASS。正常例と各理由例を年別先頭規則で保存した。
抽出接続は127.0.0.1:5432/neo_keirin_prediction_db/public、session/transaction/snapshot READ ONLY=on、
REPEATABLE READ snapshot `338862:338862:`。1,413チャンク取得後rollback/disconnect、再接続なし。
独立再現7ファイルとmanifest一致、全監査/候補/年別件数・固定C1原本/コードの終了時seal不変を確認。
全体2,274件中2,265成功、既存9skip、19,216親assertions。独立128Mは19件（既存18保持）・高ピーク親も19件成功。

証跡: `/home/shinya/neo-keirin-artifacts/stat35-c1-context-01/run-20260928-215453-d152edab/`。
mapping manifest SHA `5facd83259a5b2b147115ff1632632a6f7a11f96e07f9f4d3078286743eb839b`。
現在保存値との一致であって、発走前の公開/観測時点保証ではない。クラス略記の未知は推測で補完せず、
候補受入と保留の扱いをレビューへ提出する。自動許可リスト登録/mean6/学習/評価には進まない。

---

## 15.49 STAT-35-C1-INPUT-02 / 2026-09-30

PR #76はレビュー後MERGED。clean mainとfetch後origin/mainは `2ec9fd6a4755dab2c59127494748eafa8f98a986`。
専用 `feature/stat35-c1-input-02` で、指定candidate manifest（248 bytes / SHA-256
`7800bc94ed7a1d89e6bf1aee5a3bbd22d3d979dea01d511d4133214a08981268`）だけを明示的に受け入れる。
外側mapping manifestは監査照合に使い、allowlistへ登録しない。原本のREVIEW_PENDING/COMPLETE/hashは保持。
現在保存された出走表情報の再構成であり、observed_at=null・historical_as_of_available=false。
今回のみ既存固定資料からmean6入力生成1回・独立再現1回・集合/値/保留照合を許可する。
Contract版・C1/history pin・計算方式・欠損・クラス分類は不変。新規コードidentityで登録変更を追跡する。
DB/HTTP/Raw/再抽出/旧診断再生成/学習/係数選択/順位予測/評価/2026/LIVEは行わない。
生成1回/別ディレクトリ再現1回ともexit0、各peak30MiB。全99,669レース/706,051出走を保持し、
context接続705,048、数値685,719、NULL20,332。未接続1,003件は旧UNKNOWN_RACE_CLASS保留と年/race/entry/bike集合一致。
C1集合・順序・非結果値/型不変、14ファイルとmanifest一致、source終端検査成功。重複/競合0。
生成/再現manifest SHA-256: `7f4356b93f90203dc727dc95d6215a8d8ce3f44869c4441c3981087ff1099c26`。
関連159成功/1,834 assertions、通常全体2,270成功/既存9skip/19,255親assertions、変更PHP構文/限定Pint成功。
実行root: `/home/shinya/neo-keirin-artifacts/stat35-c1-input-02/run-20260930-054458-8739da4b/`。
詳細は [stat35-c1-input-02.md](stat35-c1-input-02.md)。入力準備完了であり性能未評価。未コミットの生成入力レビュー待ちで停止する。

---

## 15.50 STAT-35-C1-COMPARE-01 / 2026-09-30

PR #77はレビュー後MERGED、開始clean main/originは `b8596e3bc621703eadd89fba42101be8b70dde23`。
新branch `feature/stat35-c1-compare-01`。今回の最新指示だけで、受入済み固定INPUT-02を使う
C2=旧C1入力+stat35_mean6の限定development学習/予測/評価/独立再現を許可する。
旧C1 Outer run-01だけを比較基準にし、旧C1再学習・全期間最終モデル流用はしない。
200 accepted updates/収束閾値/lambda grid/One-SE/decoder/metric/paired bootstrap/Gateは継承。
NULLは非active、0は有効、cohort/順序/型は固定。原本の旧利用制限表示と生成時sealは変更しない。
Outer教師は双方の予測seal・集合照合後に開放。性能集計は両Outer予測固定後。
DB/HTTP/Raw/入力再生成/2026/LIVE/正式モデル置換は禁止。独立再現を追加標本にしない。
実装・検証・実行結果は [stat35-c1-compare-01.md](stat35-c1-compare-01.md)へ記録する。
技術成立、性能Gate、正式採用を区別し、条件を緩めた自動再試行をしない。

実行は2026-09-30 06:58:22〜11:59:43 JST、終了0、18,080.739秒、peak 33,554,432 bytes。
Inner A/Bとも収束lambda=1/0.1、他6候補は非収束除外。両Outer選択0.1、全3順位収束。
初回/独立再現のモデル・bin/support・選択・診断・予測・寄与・CIを含む各76ファイルが一致。
固定入力99,669レース/706,051出走（数値685,719、NULL20,332）と順序/型を維持。
保存C1 forward一致・再学習0。source 39ファイル/code 354ファイルの終了時不変性を確認。
C2-C1の年等重み差は1着+0.109753 pp、2着+0.071849 pp、3着+0.043463 pp、Hit@3+0.071916 pp。
Hit@3差95%CIは[-0.003468,+0.144852] pp。追加Gateは非劣性/年別条件/integrity成功、優越性未達でNOT_PASSED。
補助C2-STAT01 GateはPASS / GO_TO_FREEZEという既存判定値だが、今回の正式freeze/採用許可ではない。
保存rootは `/home/shinya/neo-keirin-artifacts/stat35-c1-compare-01/run-20260929-215427-3b1776ba`。
result manifest SHAは `8fc40000c93bbc604caac37cf21e60519f26f28c4e77cf9284fa31403f5487c8`。
関連132 tests/856 assertions、全体2288 passed/9既存skipped/19449 assertions、限定Pint/構文成功。
技術成立と性能追加効果を分離し、未コミットのコード・結果レビュー待ちで停止。追加探索・正式採用へ進まない。

---

## 15.51 STAT-35-C1-DIAGNOSTIC-01 / 2026-09-30

PR #78はレビュー後MERGED。開始clean main/originは `d2ac7b6e2a9a75d4cb503fb269900dc626e1276e`。
最新ユーザー指示で保存済みOuter 2024/2025の事後診断実装・生成1回・独立再現1回を許可。
独立namespace/CommandでPrimary A/B/C/D、Hit@3の4×4遷移、全bin係数、utility寄与を保存する。
旧COMPAREの追加Gate `NOT_PASSED` とC1維持を変更せず、診断を因果証明・採用PASSと扱わない。
直接依存コードと選択した固定sourceだけをseal検証し、教師と寄与計算入力を分離する。
DB/HTTP/Raw/2026、再学習・予測再生成・bootstrap・CI/Gate再判定はしない。
実績は [stat35-c1-diagnostic-01.md](stat35-c1-diagnostic-01.md) に記録。
2026-09-30 20:15:16〜20:15:53 JSTにbuild1回（37.685506秒）、20:16:07〜20:16:45に
reproduce1回（38.128022秒）、両exit0・peak37,748,736 bytes。
50,078レース/356,209出走を保持、全3順位保存utility exact、元寄与/未丸め率に一致。
選択source21/直接code35不変、14成果物/manifestが独立再現でサイズ・SHA-256一致。
manifest SHA: `304d2329df00ff17de383352ec45b01522430cb9181c2e13c399f531b8d451f9`。
保存root: `/home/shinya/neo-keirin-artifacts/stat35-c1-diagnostic-01/run-20260930-111106-0ec076bb`。
2024の位置別差分子は+40/+31/+35、Hit@3+105。2025は+15/+5/-13、Hit@3+3。
STAT35直接寄与と既存16項目再学習差を分離したが、的中増減の因果証明・新規採用判断ではない。
関連105 tests/567 assertions、全体2320 passed/9既存skipped/19689 assertions、限定Pint/変更PHP構文成功。
未コミットのコード・結果レビュー待ちで終了。DB/HTTP/2026、新規学習・予測・CI/Gate実行0。

---

## 15.52 STAT-36-OBSERVATION-01 / 2026-10-01

以下の実測・manifestは旧v1の実行履歴。PR #80署名v2・ページ判定v3では実データを再生成していない。

PR #79はレビュー後MERGED。clean main/origin・開始HEADは `04429ec9a2df9a56fc37d57cd15f8dd1b6371411`。
新branch `feature/stat36-observation-01` で、最新ユーザー指示の固定PR64台帳内2022-2025 Raw読取り・観測生成1回・独立再現1回を実施。
前工程のRaw禁止は当時の診断範囲として残し、今回の限定許可を別記する。DB/HTTP/2026レースへ拡張しない。
固定manifest `bd7ea209724bb1848ad4a44b1c9930bb0a5bb9c23806b0183eb0c6c07f9710a1` とLOCKED、両台帳・sidecarを検証。
専用Command `keirin:stat36:observations plan/build/reproduce`。production Parser/DTO/Repositoryは変更しない。
PJ0326のBH / inLineJyuni / 個人状況を原値・JSON pointer付きで保存。HTMLのH/B・個人状況見出しは確認したが、
外部表示JavaScriptとS/空欄の意味は未確認。全start_acquired=NULL、UNKNOWN_POSITION_DEFINITION。
台帳101,326レース中Rawあり101,297、importなし29。127,121版/900,049観測、unique対応出走716,880。
候補field完全一致S 0、空配列751,160、意味不明空表示135,036、落車棄権/失格のみ8,801、未知表現5,052。
中止空結果65版・中止非空48版を保持。表示0件をスタート取得0件へ読み替えない。
build/reproduce各1回、exit0、405.279369/388.389617秒、両peak33,554,432 bytes（512M設定）。
16成果物とmanifestがサイズ/SHA-256一致。出力だけの独立照合も全行件数・用途制限に一致。
manifest: `d3aba9fb2374fe17c4f360cbd74b9b7738bfbb794a3c751e30ed551d7868ae1b`。
証跡: `/home/shinya/neo-keirin-artifacts/stat36-observation-01/run-20261001-PWIOV1BU/` のresult/reproduction/各実行ログ。
初手位置はMISSING_INITIAL_POSITION、historical_as_of_available=false、prediction_use=NOT_AUTHORIZED、points=null。
表示観測の生成・再現成功と、意味確定済みSTAT36基盤の成立を区別する。全STAT36完成・予測利用可能とはしない。
旧C2追加Gate NOT_PASSED、C1維持、STAT35比較/診断完了を保持。学習・予測・評価・CI/Gateは実行しない。
関連155 tests/886 assertions、通常全体2359 passed/9既存skipped/19883 assertions。新規39ケースと独立128M大容量試験成功。
限定Pint/新規PHP9ファイル構文成功。詳細と具体例は [stat36-observation-01.md](stat36-observation-01.md)。
未コミットのコード・表示根拠レビュー待ちで終了。追加取得/履歴窓/予測接続など次工程へ自動移行しない。

PR #80 review fix: 同branch・HEAD `7cd7971bcc442feea92c198b7e9e863a0f08de64` から、署名専用objectキー順正規化を追加。
`STAT36-OBSERVATION-v2-DISPLAY-ONLY` / `STAT36-DISPLAY-SIGNATURE-v2-SORTED-OBJECT-KEYS`。
list順・欠落/NULL/型/文字列・観測原値・原hashを保持し、旧コードの人工2失敗を修正。実表示変更の検出は維持。
新規42回帰、関連197 tests/2275 assertions、通常全体2401 passed/9既存skipped/21274 assertions、exit0。変更PHP4件の構文・限定Pint成功。
旧900,049行への影響は今回未確認。旧v1の16成果物一致・manifestをv2結果へ読み替えない。
本番DB/HTTP/Raw build/reproduce/学習/評価/2026実データは未実行。詳細は専用文書のPR80追記。

PR #80追加 review fix: 同branch・HEAD `17bf9f186cb74bda4a4f773433c51723143277ee` からページ判定v3へ更新。
`STAT36-OBSERVATION-v3-DISPLAY-ONLY` / `STAT36-PAGE-STATE-v3-EXPLICIT-DISPLAY-FLAG`。署名v2は無変更。
表示フラグは既存のtrue/1/"1"、false/0/"0"だけを厳密対応し、他はRESULT_DISPLAY_UNKNOWNとして原値・存在・型を保持。
結果fieldの欠落/NULL/空配列/行あり/schema非対応、中止台帳状態を分離。空結果で表示判定を上書きしない。
未知フラグでも行を落とさず、import-auditとcoverageを照合。start_acquired=NULL・予測利用禁止は不変。
旧コードの人工2失敗を確認後修正。追加110回帰、関連307 tests/4960 assertions（128M）、
通常全体2511 passed/9既存skipped/23961 assertions、変更PHP6件の構文・限定Pint成功。新規skipなし。
旧v1実行・v2回帰記録を保持。実Raw再生成・旧監査・DB/HTTP・学習/評価・2026実参照は未実施、実データ影響件数は未確認。
証跡は専用文書のPR80追加節。未コミットのレビュー修正として停止。

---

## 15.53 STAT-36-START-COUNT-01 / 2026-10-01

保存済みPJ0315 `sensyuTypeInfo[].stTori` の出走者別表示スナップショット。固定PR64台帳の2022–2025年だけを対象とし、PJ0326本文を再解析しない。既存台帳にdisp/encpがないため、最新指示が許可したracesとscraping_fetch_logsの識別・取得メタデータだけを専用READ ONLY/REPEATABLE READ接続で1回抽出し、接続を閉じてから処理する。結果・払戻・プロフィールのDB参照はしない。

検索識別子は候補の対応にのみ使い、PC0201/出走者/PJ0315を照合する。全取得版を保持し、原値・型・存在状態と整数値を分ける。技術的受理範囲0–9999を実世界の上限とはしない。S固有の集計期間・基準日・訂正時点が未確認ならUNKNOWN/null。historical_as_of_available=false、prediction_use=NOT_AUTHORIZED、points=null。旧結果観測v3・署名v2・旧v1実行記録を変更しない。

保存rootはユーザー指定 `/home/shinya/neo-keirin-artifacts/stat36-start-count-01/`。静的公式説明2 URLへのGETのみ別途許可、生成・再現は通信禁止。人工検証後の少数資料確認を経て、保存構造が成立する場合だけ実生成1回・独立再現1回。未取得を数値で補わない。詳細・実測は [専用記録](stat36-start-count-01.md)。

実施結果: 101,326対象レース・717,709出走すべてに対応。127,160取得版から901,038行（0=228,011、正数=673,027）を保存。stTori欠損・形式不正・本人/レース不一致は0、S固有の期間/基準時点不明は901,038行。過去DNS_FAILUREの取得版1件は未解決に保持し、同レース別版は正常対応。14成果物とmanifest独立再現一致、build509.335秒/reproduce487.024秒、各32MiB・exit0。

source抽出は接続確認失敗1回、低速で中断した部分抽出1回、接続限定実行計画設定で完成1回。停止証跡を残し、別スナップショットを混ぜず、完成sourceだけを使用。接続/部分読取りまで1回だけとは記載しない。独立再現に伴うDB再抽出なし。業務書込みなし、静的説明2 GET以外のHTTPなし、2026レース参照なし。

成果物root: `/home/shinya/neo-keirin-artifacts/stat36-start-count-01/run-20261001-nK8sZXFb/`。manifest SHA `bcadbe02aecb3eaee510b2646fc38b305db08f11633d3bf397be1e193190386d`。関連230 tests/4502 assertions、最終通常全体2550 passed/9既存skip/24223 assertions。表示値取得の完了と予測利用未承認を分け、次はコード・証跡レビューだけ。


## 15.54 STAT-36-C1-CANDIDATE-01 / 2026-10-01

PR #81レビュー後マージの最新ユーザー確認を反映。main/origin `c24050eb6138483e37c9691ff6d949d222e47624` から `feature/stat36-c1-candidate-01` を作成。固定S snapshots（manifest bcadbe02…）、outcome-free C1（7f4356b9…）、全対象mapping（5facd832…）のみを読んだ。manifest/COMPLETE、実読取り子ファイルseal、C1原対象へのprovenance binding、直接依存コードをSTART/END照合。C1本体にplayer_id列はないため、同原対象とseal照合済みmapping target.player_id/MATCHが本人根拠。DB・プロフィール補完なし。

C1集合/出現順・非結果値は不変。99,669レース/706,051出走すべて一意数値、候補NULL/値競合/対応保留0。UNKNOWN_RACE_CLASSの1,003件も本人/開催照合により数値接続し、旧STAT35の保留規則と元理由は維持。元901,038行 = C1対応886,783行 + 対象外14,255行（unique11,658出走）。180,725 C1出走は複数同値版。127,160取得版のうち失敗1版（race84651/fetch215551）は数値観測にせず監査へ保存、同レース7出走は正常別版で対応。

生成1回・独立offline再現1回で15成果物とmanifestが完全一致。build73.738秒/reproduce75.381秒、各128M指定・peak31,457,280 bytes・exit0。runは `/home/shinya/neo-keirin-artifacts/stat36-c1-candidate-01/run-20261001-WbqYscoJ/`。manifest4603 bytes/SHA `f175deff20fe8905b40e92ffa2d9ff16de71f432584c9e13e129d47045306437`。source linksは全成功/失敗取得版と全snapshot行を追跡する。

全C1対応観測886,783行の取得日は対象日より後。ただしシステム取得日であり、S集計cutoffや未来情報混入の確定ではない。S起算/端点/基準日、対象race以後の除外保証、掲載時刻とS訂正時点の関係は未確認。全候補historical_as_of_available=false/prediction_use=NOT_AUTHORIZED/training_evaluation_authorized=false/points=null。学習/予測/評価用途の実読込み拒否を検証。REVIEW_CANDIDATE_ONLY、正式採用・学習許可ではない。

人工関連200 tests/1514 assertions、最終コード通常全体2593 passed/9既存skip/24407 assertions、追加PHP11件構文・限定Pint成功、100MiB超独立128M成功。CSV最終修正前に開始した全体確認は別ログの初期記録として保持し、最終コードで1回確認した。旧S v1・観測v3/署名v2・C1/STAT35・既存モデル/成果物不変。DB/HTTP/Raw本文・Migration・S再生成・学習/予測/評価・2026レース/LIVEは0。未コミットのレビュー待ちで停止。詳細は [専用記録](stat36-c1-candidate-01.md)。

## 15.55 STAT-36-C1-COMPARE-01 / 2026-10-02

PR #82マージ、clean main/origin `42afdfceb40c049159df6b53d07d2b45066a7fcb` から
`feature/stat36-c1-compare-01` を作成。最新ユーザー許可により固定S候補f175deff…、
固定outcome-free C1、保存run-01のOuter C1/教師だけでC1_PLUS_Sを学習・比較した。
原候補の一般用途未承認/Bundle拒否は変更せず、専用契約/readerに限定。
STAT01 anchor1、12 STAT+history4+表示Sの17群、修正版v2 solverと全固定条件は不変。
S時点UNKNOWN/historical_as_of_available=false、正式採用/LIVE/2026禁止を維持。

execute1回の内部で初回と独立再学習を各1回。Inner A収束1/0.1、Inner B収束1/0.1/0.01、
非収束6/5候補は1着200更新の診断付きで除外。A/B共通候補の選択はOuter2024/2025とも0.1、
選択refit全3順位収束、旧C1再学習0。双方予測seal/cohort/保存forward確認後に当該Outer教師を開放。
C1全99,669レース/706,051出走の順序・非結果値/型は不変。S数値706,051、NULL0、有効0は177,529。
2024/2025評価50,078レースを保持。旧C1保存モデルforward一致、source44/code362のSTART/END不変。

年等重みC1_PLUS_S-C1差（pp）: 1着+0.007743、2着-0.081066、3着-0.092370、
位置Hit@3-0.052721。95%CIは順に[-0.092707,+0.115941]、[-0.221008,+0.070031]、
[-0.210904,+0.023789]、[-0.129355,+0.030178]。追加Gate NOT_PASSED:
2着/3着NI、Hit@3優越性、2025 Hit@3非負条件が未達、integrityはtrue。
補助STAT01 Gateは全条件PASSだが追加効果の代用ではない。既存C1を維持し今回方式は採用しない。
旧モデル/成果物、E08否定結果、旧pilot保留は保持。実行成功を性能向上・S時点保証とは扱わない。

76の意味上の成果物（bin/support、候補状態、係数/モデル/予測、分母/寄与/CI/Gate等）が
独立再学習でbyte/SHA一致。run rootは
`/home/shinya/neo-keirin-artifacts/stat36-c1-compare-01/run-20261002-hSnkpCBN/`。
result manifest148433 bytes/SHA `bd51871d8b0598bb5665517847e6400532f577092f03fe3f735b04583e218585`、
COMPLETE照合成功。JST06:58:14～12:08:11、18597.176秒、peak35651584 bytes（34MiB）、512M設定、exit0/stderr空。
関連214 tests/1280 assertions、新比較37/251、通常全体1回2632 passed/9既存skip/24661 assertions。
PHP19件構文・限定Pint、100MiB超の独立128M人工streaming成功。P3は実改行・未知field理由を検証し、
改行不正と正常対照を分離、旧Rows/Artifactsは不変。小容量レビューZIP/実在確認は同rootへ保存。
DB/HTTP/Raw/Migration/2026実データ/LIVE/正式採用、commit/push/PR操作なし。未コミットで今回結果レビュー待ち。
年別の分子/分母/率、補助差、選択/収束、再現・証跡の詳細は [専用記録](stat36-c1-compare-01.md)。

## 15.56 STAT-17-C1-COMPARE-01 / 2026-10-02

PR #83レビュー後MERGED、clean main/origin `faedde36b1aa31b2bbfb750cda91512c157d50cd`から
`feature/stat17-c1-compare-01`を作成。最新ユーザー指示は固定C1 history4の観測構成指数Dだけの追加比較。
既存16項目とDの17項目を全順位で新規推定し、保存run-01 Outer C1を基準にする。
算術はbinary64・固定6積順の補償加算 `(8/3)*sum(p_i*p_j)`、許容幅1e-12、表示丸めなし。
N=0はNO_TOP2_METHOD_OBSERVATIONS、元履歴欠損は元状態、単一観測D=0は有効値。
元history/対象/順序/型は不変、N/理由は監査専用、S・mean6・Raw・DBを読まない。
source2束とコード固定後、execute1回内でrun-01/02の独立学習・予測・paired評価・再現を完了した。
時系列教師開放、旧solver定数/grid/One-SE/decoder/Gate、128M人工/512M実処理を維持する。
保存先: `/home/shinya/neo-keirin-artifacts/stat17-c1-compare-01/run-20261002-142352-f32cdb4f/`。
99,669レース/706,051出走を維持。D数値621,089、NULL84,962、有効0 76,701、N=0 22,306、元履歴利用不能62,656、境界補正0。
両Innerは1/0.1だけ3順位収束、他6候補は非収束で除外。両Outerの選択lambda=0.1、旧C1再学習0。
候補-C1の年等重み差(pp)は1着+0.021655、2着+0.017680、3着+0.038372、位置Hit@3+0.025293。
Hit@3差の95%CIは[-0.047207,+0.098214]ppで優越条件未達。主Gate NOT_PASSED、NI/temporal/integrityは成立。
補助STAT01 Gate PASSは主Gateの代用ではなく、今回方式は不採用・C1維持としてレビューを待つ。
76意味ファイルが独立2runでbyte/SHA一致。START/END source33・コード355、公開前sealも成立。
manifest: `f99c7d0dbed5e1fda0e387d2877cb181629e0236953a1317f9ab76716283b69f`、COMPLETE一致。
2026-10-02 14:28:46～19:19:46 JST、17,460.504秒、exit0、PHP peak35,651,584 bytes(34MiB)、stderr空。
関連128M 235 passed/1914 assertions、通常全体2684 passed/9既存skip/25129 assertions、PHP19構文/限定Pint成功。
年別の分子/分母/率/差/CI、診断、再現、実行ログは[専用記録](stat17-c1-compare-01.md)と永続成果物へ保存。
旧S/mean6の件数/hash/NOT_PASSEDを変更せず、同条件再試行・事後的な式/grid/閾値変更は行わない。
HISTORICAL_EVENT_RECONSTRUCTION / BACKFILLED_FINAL_RESULT / DEVELOPMENT_ONLY、historical_as_of_available=false。
観測4回数の非線形追加の限定仮説であり、STAT-17全体・戦法有効性・ライン役割自在性の完成ではない。
正式採用/配点/LIVE/2026は禁止。結果提出後はレビュー待ち、次工程へ自動移行しない。

---

## 15.57 STAT-01-C1-SCORE-GAP-01 / 2026-10-03

PR84レビュー後MERGED、main/origin `89544d70eb1c6e74f24ff0dcb2a2d5e76cb4a5f7`を確認。
STAT17の不採用・C1維持/旧76意味ファイル一致を保持し、最新ユーザー許可の固定C1 raw平均との差だけを追加する。
Projectorは共有Validator後、全出走者保存順のbinary64 `array_sum(scores)/n` と差。負値/有効0を保持し、SD除算やclampなし。
元16項目/anchor/型/集合/順序不変、旧Outer C1はforward照合のみ再学習0。今回candidateだけ17項目の全順位を新規fitする。
既存2束の固定manifest/子sealのみ利用、S/mean6/D/Raw/DB/HTTPなし。Inner A/B・教師seal後開放・One-SE・収束定数・decoder・paired Gate不変。
開始時に同じ比較の完了証拠は既存コード/工程文書/永続rootの実験一覧では確認していない。基礎z-score/点差集計を同一比較とみなさない。
人工試験後、execute1回で独立2runの学習・予測・評価まで完了、76意味ファイルがbytes/SHA一致。
source33/code355 START/END・公開前検査成功、保存基準C1 forward一致/再学習0、元C1対象/順序/非結果値/型は不変。
99669レース/706051出走でgap全数数値、負361826/0は65/正344160、NULL/不正/全同得点レース0。
min=-20.142857142857153、max=24.27857142857144。Inner A/Bのlambda=1/0.1は全3順位収束、他6候補はP1で200更新非収束・除外。
One-SE選択は両Outer0.1、選択refit全順位収束。200更新上限・grid・閾値は不変。
対C1年等重み差pp[95%CI]: 1着+0.139594[-0.004834,+0.288477]、2着-0.281565[-0.492260,-0.067794]、
3着-0.121781[-0.429223,+0.190527]、位置Hit@3-0.090092[-0.232836,+0.056111]。
主Gate NOT_PASSED: 2着/3着/Hit@3非劣性CI下限、Hit@3優越、2025のHit@3非負/2着・3着-0.3pp以上が未達。integrity=true。
補助STAT01 GateはFAIL / REDESIGN_REQUIRED (非劣性/supporting未達)。今回方式不採用・既存C1維持、コード/結果レビュー待ち。
run `/home/shinya/neo-keirin-artifacts/stat01-c1-score-gap-01/run-20261002-223318-ae3cf831/`、
manifest `214ba0fa3a144b7053457c5ad2d123e874256d0f34927a04700ce74186dc9ee1`、COMPLETE照合済み。
2026-10-03 07:38:32～12:42:27 JST、18235.122882秒、exit0、peak35651584 bytes (34MiB)、stderr0 bytes。
関連128M 279 passed/2466 assertions、通常全体1回2728 passed/9既存skip/25681 assertions、変更PHP19構文/限定Pint/diff-check成功。
用途はLIMITED_DEVELOPMENT_EXPERIMENT_ONLY、historical_as_of_available=false、points=null、正式採用/LIVE/2026禁止。
詳細/年別分子分母/補助比較は `docs/stat01-c1-score-gap-01.md`。条件変更や旧不採用候補の再試行を許可せず、レビュー待ちで停止する。

---

## 15.58 C1-STAT10-ABLATION-01 / 2026-10-03

PR85マージ、clean main/origin `db94280fbab7434ccf34043dcef40b6deb7401df`から専用branchを作成。
最新指示によりSTAT-10だけ除去した15項目の人工検証・新規学習・Outer24/25比較・独立2runを限定許可。
原12+4を共有Validator/固定source/教師と照合後、名前付きmapで投影する。旧C1はforward照合のみ再学習0。
数値定数/grid/One-SE/decoder/paired bootstrap/追加Gateは不変。M/G/edgeは除外後構造から算出。
旧S/D/mean6/gap不採用と当時のhash/数値は保持。PR85 P3（全同小数得点gapのbinary64残差）は未解決、
将来再利用前の対応事項。今回gap不使用でコード/版/成果物を変更せず旧比較も再実行しない。
詳細・今回の測定結果は [専用記録](c1-stat10-ablation-01.md)。DB/HTTP/Raw/2026/LIVE/正式置換なし。
STAT10要件・集計器の恒久廃止ではなく、使用済みdevelopment corpus内の限定モデル比較。

1 executeで独立2run完了、実列挙76意味ファイルが一致。全99669レース/706051出走を保持し、
残る15値/型/順序・原16資料照合成功。旧Outer C1 forward一致/再学習0、source33/code355の開始終了不変。
両年選択0.1、Inner A収束1/0.1、Inner B収束1/0.1/0.01、共通適格1/0.1を使用。
Inner A/B・Outer24はM126/G15/edge107、Outer25はM127/G15/edge108。
年等重み候補-C1差pp（95%CI）:1着 -0.081213 [-0.213322,+0.052774]、
2着 +0.048438 [-0.119022,+0.221927]、3着 +0.035865 [-0.119662,+0.198496]、
位置Hit@3 -0.001083 [-0.104312,+0.100788]。
主Gate NOT_PASSED:1着CI下限による非劣性、2025 Hit@3負差による年別安定性、Hit@3優越が未達。
integrityは成立、補助STAT01 Gate PASS。今回の15項目方式を採用せずC1維持、STAT10不要とは断定しない。
manifest `671bbcd97463123076846c4dcd6f7273e6e6aa0d9da8dabba1dc205ef2dca339`。
成果物 `/home/shinya/neo-keirin-artifacts/c1-stat10-ablation-01/run-20261003-065046-66fb55f5/result`。
新規43/444、関連128M322/2910、通常全体1回2771 passed/26125 assertions/既存9 skipped。
変更PHP19構文/限定Pint/diff検査成功。512M実行exit0、peak32MiB、4時間39分25秒、stderr空。
未コミットで結果レビュー待ち。正式置換/次ablation/2026/LIVEは未承認、historical_as_of_available=false。

# 16. BT-04 — Final Frozen Holdout Evaluation

## 16.1 状態

`BLOCKED`

## 16.2 開始条件

以下がすべてfreezeされるまで開始禁止。

- 使用STAT
- feature version
- rule generation
- threshold applicability decision
- bin semantics
- fitted beta coefficients
- selected lambda / alpha
- channel scale
- continuous score formula
- missing handling
- confidence handling
- tie rule
- prediction output
- metric definitions
- optimization終了条件
- model / rule version

`threshold_applicability`自体を必ずfreezeする。値は `NOT_APPLICABLE` または `APPLICABLE_AND_FROZEN` のどちらかとする。純粋ranking outputでthresholdを使わない場合は `NOT_APPLICABLE` とする。`APPLICABLE_AND_FROZEN` の場合だけ、threshold数値もBT-04前にfreezeする。

## 16.3 2026を開く前の必須記録

最低限:

```yaml
final_engine_version:
final_rule_version:
optimizer_version:
normalization_version: RACE_CENTERED_RMS_V1
summation_version: NEUMAIER_COMPENSATED_SUM_V1
tie_rule_version: BT03E02-TIE-v1
selected_final_lambda:
selected_final_alpha:
final_stat_selection:
final_bin_manifest_hash:
final_beta_manifest_hash:
final_channel_scale_manifest_hash:
final_model_parameter_manifest_hash:
final_feature_manifest_hash:
final_scoring_contract_hash:
final_code_main_sha:
freeze_datetime:
threshold_applicability:
```

を保存する。

## 16.4 Holdout実行ルール

1. 発走前データだけで2026 predictionを作る
2. prediction artifactをfreezeする
3. prediction hash / manifestを保存する
4. その後で初めて2026 outcomeを読む
5. metric計算
6. 結果が悪くても2026を見ながら再調整しない

2026を見て調整した時点で、その後の2026再評価はfinal holdoutではない。

---

# 17. BT-05 / LIVE — Future Prediction

## 17.1 状態

`BLOCKED`

## 17.2 目的

未来レースについて、

```text
発走前
↓
prediction生成・保存
↓
prediction lock
↓
実レース
↓
結果取得
↓
prediction vs actual評価
```

を行う。

## 17.3 必須条件

- 発走後情報がpredictionへ入らない
- predictionを結果取得後に書き換えない
- prediction timestamp / input_as_of / model versionを保存
- later correctionはpredictionとは別履歴
- live accuracyを累積監査

---

# 18. Frozen Contracts

現時点で変更には明示的な理由とMASTER PLAN更新が必要。

## 18.1 Leakage

- 対象レースより未来の情報禁止
- outcomeを見て同じevaluation対象のscore parameterを決定しない
- prediction freeze後にlabelを開く

## 18.2 Source integrity

- manifest固定
- fingerprint確認
- START / END preflight
- source drift fail closed
- persisted artifact hash再計算
- 監査hashをstored hashだけで信用しない

## 18.3 Missing

- missingを能力0と解釈しない
- missingだけを理由に自動減点しない
- scoring層で利用不能なら原則contributionはnull、sum対象外
- quality/statusは別途保持

## 18.4 Abnormal result

- 異常結果を最下位順位へ強制変換しない
- dead heatを勝手に一意順位へ変換しない

## 18.5 Memory

bounded-memory / streaming構造を維持する。独立PHP processで実施するbounded-memoryテストは128M制約を維持し、
full suite共有processの過去peakに依存させない。過去に128Mで成功した実績は当時の記録として残す。
実データ処理の基本例は次とし、対象範囲・chunk・実測peak・空き容量に基づき調整する。

```text
php -d memory_limit=512M
```

Productionを一律128Mに限定する旧方針は解除する。メモリ上限の増量だけで非boundedな実装を許容するものではない。
この方針変更は本番実行や新しい分析・学習を許可しない。

## 18.6 DB Safety

read-only benchmarkではPostgreSQL READ ONLYを使用し、

- Statistics
- Scraping
- race
- result

を更新しない。

## 18.7 Artifact

正式比較に使うartifactは、

- hash / manifest付き
- partial publicationなし
- fail closed
- source identity確認可能

であること。

## 18.8 BT-03E-02 v1 Design Contract

次の構造・方式はBT-03E-02 v1の実装前契約としてfreeze済みである。

- 符号付きcontinuous score、高いscoreほど上位、ランキング前round禁止
- WIN / TOP2 / TOP3の独立3 channelと、それらから作る `RANKING_SCORE`
- `beta[stat, bin, channel]` によるhierarchical regularized bin model
- prediction用の明示的STAT単一weightを置かない
- piecewise-bin nonlinear model、STAT-31 non-monotonic許可、STAT-26 semantic alignment、STAT-39 cohort維持
- correlation thresholdで削除せず、soft controlとtemporal ablationを使用する
- Pareto-constrained multi-objectiveとPrimary / Supporting metrics
- BT-02固定label semantics、P × Nだけのpairwise logistic loss、race-equal weighting
- deterministic Proximal Gradient / FISTA、inner OOF alpha freeze後のOuter単回評価
- 正規化L2 / group RMS / numeric smoothnessを固定1:1:1で合成するComposite Penalty
- lambda grid `0, 1e-6, 1e-5, 1e-4, 1e-3, 1e-2, 1e-1, 1`
- alpha grid step 0.05、231 simplex candidates、adaptive refinement禁止
- binary64、`NEUMAIER_COMPENSATED_SUM_V1`、exact comparison、`BT03E02-TIE-v1`
- `RACE_CENTERED_RMS_V1` channel normalization
- `RACE_SCORE_Z`、係数1.0固定、regularization対象外のSTAT-01 anchor
- expanding-window nested temporal validation、One-SE Rule、final development OOF refit
- race-paired / year-stratified / Type-7 CIを含むDecision 12 Acceptance Gate
- BT-03E-02のfit、選択、development evaluationで2026参照禁止

詳細な数値・順序・例外条件の正本はSection 15とする。設計変更にはversioned reasonと本MASTER PLANの先行更新が必要である。

---

# 19. Unfrozen Contracts

現時点で **実装・training・選択を経なければ最終値が確定しない** のは次である。

- fitted beta coefficients
- selected final lambda
- selected final alpha
- final training-generated bin boundaries / category definitions
- final channel scale values
- temporal ablation後の最終STAT採否
- optimizer numeric solver constants（最初の正式development実行前にimplementation PRでfreeze）
- threshold applicability、およびapplicableの場合の最終threshold
- final engine / model / calculation version
- final model parameter / feature / scoring manifests and hashes
- final prediction confidence
- 未実装の将来STAT統合方法（STAT-20等）
- market overlay（STAT-22）
- bet / ROI rule

continuous score、higher-is-better、3 channel、`RANKING_SCORE` convex combination、STAT-01 anchor、明示的STAT multiplier禁止、`beta[stat,bin,channel]`、piecewise-bin nonlinear structure、optimizer family、Composite Penalty式、lambda / alpha grid、inner alpha selection、tie hierarchy / technical key、`NEUMAIER_COMPENSATED_SUM_V1`、`RACE_CENTERED_RMS_V1`、temporal validation、Acceptance Gate、2026禁止はunfrozenではなくSection 15 / 18のfrozen contractである。

optimizerのmax iteration、convergence tolerance、line-search rule、initial step、Lipschitz関連constant、restart ruleはfit前Unfrozenだが、最初の正式development実行より前に `OPTIMIZER_VERSION` とともに一意にfreezeする。実行結果を見て決めず、同一versionでsilent変更しない。

整数のfinal points、bin別points、STAT-01 base step、prediction用STAT単一weightは `NOT_APPLICABLE / SUPERSEDED` とする。独立したcorrelation penalty parameterもv1では採用しない。

BT-03E-01の以下は **実験パラメータ** であり、最終仕様ではない。

```text
WEIGHT_GRID = [0,5,10,20,30,40]
BASE_STEP_GRID = [0,5,10,20,30,40]
3-label common direction
STAT single weight
coordinate descent
```

---

# 20. 再実施禁止・重複防止台帳

## 20.1 BT-01を理由なく再構築しない

正式run 1をbaseline正本とする。

## 20.2 BT-02の12 STAT discoveryをゼロからやり直さない

`IS_WIN / IS_TOP2 / IS_TOP3`の増分評価はrun 5で完了済み。

## 20.3 旧BT-03Dを次工程として復活させない

`SUPERSEDED`。

## 20.4 BT-03 run6を再実行しない

run 6は正式完了済み。

再実行はsource / calculation version変更等がある場合だけ。

## 20.5 BT-03E-01 coarse scoringを同一仕様で再実行しない

2024 negative resultは有効な結果として保存する。

## 20.6 2026を「少しだけ確認」しない

性能・threshold・parameter選定につながる確認は禁止。

---

# 21. 主要PR履歴

| PR | 内容 | 状態 |
|---:|---|---|
| #22 | STAT-01 existing DB foundation | MERGED |
| #23 | Statistics Batch02 player history | MERGED |
| #24 | Batch02 bounded memory | MERGED |
| #25 | Statistics Batch03 | MERGED |
| #26 | Statistics Batch04 | MERGED |
| #27 | Statistics Batch05 | MERGED |
| #28 | BT-01 foundation | MERGED |
| #29 | BT-02 signal evaluation foundation | MERGED |
| #30 | BT-02 preflight execution foundation | MERGED |
| #31 | BT-02 signal evaluation execution | MERGED |
| #32 | BT-02 spool ordering fix | MERGED |
| #33 | BT-02 ridge line-search convergence | MERGED |
| #34 | BT-02 optimizer version contract | MERGED |
| #35 | BT-02 compensated objective | MERGED |
| #36 | BT-03 bin effect foundation | MERGED |
| #37 | BT-03 bin effect execution core | MERGED |
| #38 | BT-03 bin effect Production | MERGED |
| #39 | BT-03 centered residual bounded memory | MERGED |
| #40 | BT-03E historical forward scoring | MERGED |
| #41 | docs: add statistical engine master plan | MERGED |
| #42 | docs:bt03e02 design freeze | MERGED |
| #43 | feature:bt03e02 scoring engine | MERGED |
| #44 | fix:bt03e02 fista nonconvergence | MERGED |
| #65 | feature:stat35 storage backfill 01 | MERGED |
| #66 | ops:stat35 production preflight 01 | MERGED |
| #67 | STAT-35 production migration結果 | MERGED |
| #68 | STAT-35 production backfill pilot結果 | MERGED |
| #69 | STAT-35 production backfill 2022-2025結果 | MERGED / 保存結果レビュー完了 |
| #70 | STAT-35/37 track context v1・配布抜粋/ロード時原文照合 | MERGED / 2件のレビュー修正完了 |
| #71 | STAT-35/37 track context v2・部分的歴史構造拡充 | MERGED / レビュー完了 |
| #72 | STAT-35 race-relative・レビュー修正・hermetic memory completion | MERGED_REVIEW_COMPLETED |
| #73 | STAT-35 player-history・本人識別scopeレビュー修正 | MERGED / head 3a18de5bb08dd7f83cd8e35b176ce58dc7e49893 / merge f0834202fcac19919bfa4b8dce0ef9043a457a0a / 2026-09-28 06:33:48 JST。全成果物受入の判定記録未確認 |
| #74 | STAT-35 next-use設計案・工程同期 | MERGED / merge 029309f2cb0434348a53cd30b74d0419d2f8b6d2。設計案保存の完了であり学習/Gate/2026の承認ではない |
| #75 | STAT-35 C1 input・接続証拠/競合判定レビュー修正 | MERGED / merge e61f7c19dfd7b32d9a0ff1218fc068b7756b1fd5。旧診断は保持、今回context候補の受入とは別 |
| #76 | STAT-35 C1 context・固定対象出走表対応 | MERGED_REVIEW_COMPLETED / merge 2ec9fd6a4755dab2c59127494748eafa8f98a986。今回の固定candidate限定受入は15.49 |
| #77 | STAT-35 C1 input-02・固定mean6入力生成と独立再現 | MERGED_REVIEW_COMPLETED / merge b8596e3bc621703eadd89fba42101be8b70dde23。限定development比較許可と実績は15.50 |
| #78 | STAT-35 C1 compare-01・固定mean6 C2比較 | MERGED_REVIEW_COMPLETED / merge d2ac7b6e2a9a75d4cb503fb269900dc626e1276e。追加Gate NOT_PASSED、C1維持。限定診断は15.51 |
| #79 | STAT-35 C1 diagnostic-01・保存モデル/予測の事後診断 | MERGED_REVIEW_COMPLETED / main 04429ec9a2df9a56fc37d57cd15f8dd1b6371411。旧実績15.51を保持。今回の表示観測は15.52 |

Current remote `main` at the v1.2 update:

```text
6fc68f9d17a1b70f8dcb196bd6bf38fb98d75301
```

---

# 22. 正式Run / Artifact Registry

## BT-01

```yaml
run_id: 1
uuid: 73087af0-7796-4ea0-85b8-d8b6b12d088a
source_manifest_hash: b2848ab16931999a5a75529d10bd86f6b3b996aba37a4192f06f978db0c8bb97
status: PARTIALLY_SUCCEEDED
meaning: valid baseline run with explicit exclusions
```

## BT-02

```yaml
run_id: 5
uuid: 8e81ae0d-8018-4d99-b31d-203d8076e6cb
status: SUCCEEDED
models: 432
metrics: 648
bins: 668
source_manifest_hash: 92aa8439775101c4f9d190d829b8a0f3e3702fd8646101b66a42b68babb79e6d
outcome_snapshot_manifest: a4b1800095b22fe0ae40216ce90243c7e80a0cf652a96e328c45223160c3dad9
```

## BT-03

```yaml
run_id: 6
uuid: 28144da5-ad1b-4cc7-a17d-cb456fcf5719
status: SUCCEEDED
scopes: 72
effects: 2004
effect_manifest_hash: 1bcf2eb3ff4d7857e16622d5d719f6034764dd1785f4dbd7ceafbb63069c88cb
```

## BT-03E-01

DB runは新規作成せずread-only benchmark。

```yaml
source_run: BT-03 run 6
source_fold: WF_2023
cohort: OPERATIONAL
selected_scopes: 12
selected_effects: 333
semantic_digest: c57826c082233b716e831979cb4089bbb6f4bf3ddb31ead121eb9b1cf3941cd6
training_year: 2023
evaluation_year: 2024
engineering_status: COMPLETED
scoring_result: REJECTED_FOR_ADOPTION
```

---

# 23. Goal Completion Matrix

| Goal | 現在の状態 | コメント |
|---|---|---|
| Goal 1 入賞影響項目 | PARTIAL / current 12 substantially evaluated | 全STAT-01～46では未完 |
| Goal 2 順位影響項目 | PARTIAL / current 12 rank-boundary evidence available | exact orderはscoring評価で継続 |
| Goal 3 score / parameter決定 | NOT_COMPLETED / DEVELOPMENT_GATE_PASSED | TACTICAL-HISTORY-01 v2はC0/C1の学習・比較・再現性確認と成果物レビューを完了し、開発評価Gateを通過。FINAL-01の最終C1とPR #57/#58の接続・照合レビューは完了。STAT-35 C2追加比較はGate未達でC1を維持し、今回の事後診断は採用判断を変更しない。旧開催グレード/選手級班分析は保持。モデル変更・正式LIVE採用・2026は未許可。E08の不採用と旧TACTICAL-PILOT-01の保留を維持 |
| Goal 4 holdout精度 | BLOCKED | final scoring freeze前 |
| Goal 5 live精度 | BLOCKED | Goal 4後 |

---

# 24. MASTER PLAN更新ルール

以下のいずれかが起きたら本文書を更新する。

- 新しい統計エンジンPRがmerge
- 正式Production runが確定
- runがinvalid / supersededになった
- phaseがCOMPLETED
- phaseがSUPERSEDED
- 次工程が正式決定
- scoring architecture / fitted parameter / threshold applicabilityがfreeze
- 2026 holdoutを開く許可が出た
- final engine versionが決定
- live predictionへ移行

更新時は最低限:

```yaml
document_version:
updated_at:
remote_main_sha:
phase_changed:
related_pr:
related_run:
decision:
reason:
```

を変更履歴へ追記する。

---

# 25. 変更履歴

## v1.49 / 2026-10-03

PR85マージとmain/origin db94280fbab7434ccf34043dcef40b6deb7401dfを同期。
最新指示のC1-STAT10-ABLATION-01だけを限定許可。旧不採用・成果物とP3残件を保持。
1 execute/独立2runの実学習・比較・再現完了、主Gate未達・C1維持。実列挙76意味ファイル一致。
15.58/専用記録へ実測値を保存し、次の許可行為を結果レビューだけへ更新。
原16項目検証・15項目投影・専用モデル・人工検証・独立2run実行を同一工程に記録する。
正式採用/2026/LIVE/自動的な次項目探索は禁止。

## v1.48 / 2026-10-03

PR84レビュー後マージ済み、main/origin 89544d70eb1c6e74f24ff0dcb2a2d5e76cb4a5f7を反映。
STAT17の今回方式不採用・C1維持を確定記録として保持。最新指示の固定C1全出走raw平均との差だけの限定比較を許可する。
専用版/17項目経路・人工検証・execute1回の独立2run/76意味ファイル一致と15.57の結果を記録。
主Gate NOT_PASSED (非劣性/年別/優越未達)・今回方式不採用/C1維持、補助STAT01 FAIL。コード/結果レビューのみを次とする。
旧成果物・時点UNKNOWN・固定数値契約・2026凍結は不変。冒頭/現在地/工程表/引継ぎを同じ工程へ同期する。

## v1.47 / 2026-10-02

PR83レビュー後マージ済み、main/origin faedde36b1aa31b2bbfb750cda91512c157d50cdを反映。
固定C1 history4由来のSTAT-17-C1-COMPARE-01だけを限定許可し、専用contractと17項目経路を追加。
旧C1維持、S/mean6追加のGate NOT_PASSED、原成果物/hash/実行履歴、旧pilot/E08、2026凍結は不変。
今回の実学習・paired評価・独立再現76意味ファイル一致、追加Gate NOT_PASSED/優越未達・C1維持を15.56へ記録。
冒頭metadata・現在地・工程表・引継ぎをコード/結果レビュー待ちへ同期。STAT-17全体や正式採用の完成にはしない。

## v1.46 / 2026-10-02

PR82マージ、main/origin 42afdfceb40c049159df6b53d07d2b45066a7fcbを確認。
最新ユーザー許可により固定S候補manifest f175deff…のSTAT-36-C1-COMPARE-01だけ学習・予測・比較・独立再現を許可する。
元候補の制約と旧Bundleは変更せず、S時点UNKNOWN/historical_as_of_available=false、正式採用/LIVE/2026禁止を維持。
独立2run/76成果物一致と追加Gate NOT_PASSED・C1維持を15.55/専用文書に記録。
旧実績は読み替えず、冒頭metadata・現在地・工程表・引継ぎを今回のレビュー待ちへ同期する。

## v1.45 / 2026-10-01

PR81マージ・レビュー完了と固定3束の候補接続だけの限定許可を反映。STAT-36-C1-CANDIDATE-01で全706,051出走の候補生成・独立再現を完了し、15成果物/manifest一致。冒頭metadata・現在地・工程表・引継ぎを同期した。数値接続100%とS時点未確認/学習利用未承認を分離し、旧工程件数/hash/失敗履歴は当時の記録として維持する。C1維持、C2追加Gate NOT_PASSED、2026凍結。次はコード・候補証跡レビューのみ。

## v1.44 / 2026-10-01

PR80マージを反映。最新ユーザー指示により、STAT-36-START-COUNT-01だけを開始。固定PR64対象のraces/取得ログメタデータに限定したREAD ONLY抽出と、保存PJ0315のoffline生成・独立再現の許可を記録。完成source1束から901,038行を生成し、14成果物とmanifestの独立再現一致を確認した。抽出の失敗・中断各1回は別証跡として15.53に記録。旧v1成果物・v2/v3人工回帰は過去記録として不変。C1維持、C2追加Gate NOT_PASSED、2026凍結、予測利用未承認を維持し、コード・証跡レビュー待ち。

## v1.43 / 2026-10-01

PR #79レビュー/マージ完了、開始main `04429ec9a2df9a56fc37d57cd15f8dd1b6371411` を反映。
STAT-36固定台帳Rawの限定読取り・表示観測生成/独立再現完了を現在地・工程表・引継ぎへ同期。
Sの意味未確認・全start値NULL・初手位置未取得を明示し、STAT36全体完成/予測利用とはしない。
旧STAT35 mean6候補C2の追加Gate未達、C1維持、旧実行記録と2026凍結は保持。詳細15.52。

## v1.42 / 2026-09-30

PR #78レビュー/マージ完了、main `d2ac7b6e2a9a75d4cb503fb269900dc626e1276e` を反映。
STAT-35-C1-DIAGNOSTIC-01の限定事後診断許可と50,078レース生成/独立再現完了を現在地・工程表・引継ぎへ同期。
COMPARE-01の追加Gate未達、C1維持、旧成果物・当時の実績は保持。詳細15.51。

## v1.41 / 2026-09-30

PR #77マージと開始main `b8596e3bc621703eadd89fba42101be8b70dde23` を反映。
STAT-35-C1-COMPARE-01の固定INPUT-02限定development許可を現在地/工程表/引継ぎへ同期。
旧入力・C1・生成時hashを保持し、今回の独立実装と実行記録を分離する。詳細15.50。
初回/独立C2学習・比較・再現が技術成立。追加Gate NOT_PASSED、補助STAT01 Gate PASS、正式採用なし。
次はコード/結果レビューのみ。旧INPUT-02生成/再現の実行hashを今回コードへ読み替えない。

## v1.40 / 2026-09-30

PR #76マージと開始main `2ec9fd6a4755dab2c59127494748eafa8f98a986`を反映。
STAT-35-C1-INPUT-02として固定candidate pinの限定受入、既存mean6生成1回/独立再現1回だけを今回指示で許可。
接続705,048・数値685,719・NULL20,332、保留1,003集合照合、C1不変、14ファイル/manifest再現一致を確認。
旧REVIEW_PENDING/全NULL診断/実行hashは当時の記録として保持。次は生成入力レビューのみ。
履歴計算・旧C1・historical_as_of_available=false・2026凍結・予測利用禁止は不変。実績は15.49と専用文書へ記録。

## v1.39 / 2026-09-29

PR #75マージを反映。STAT-35-C1-CONTEXT-01の限定READ ONLY抽出1回とoffline候補生成/再現を今回指示で許可。
元C1/STAT35・旧全NULL診断を保持し、REVIEW_PENDING候補を確認済み証拠へ自動昇格しない。
historical_as_of_available=false、2026凍結、モデル/予測利用禁止を維持。実績は15.48と専用実行記録に分離する。

## v1.38 / 2026-09-29

```yaml
document_version: 1.38
updated_at: 2026-09-29
remote_main_sha: 029309f2cb0434348a53cd30b74d0419d2f8b6d2
phase_changed: STAT-35-C1-INPUT-01
related_pr: PR74_MERGED_DESIGN_DOCUMENTATION
related_run: stat35-c1-input-01/run-20260929-IDHDYP
decision: CODE_VERIFIED_MAPPING_BLOCKED_DIAGNOSTIC_REPRODUCED_AWAITING_REVIEW
reason: User authorized input preparation; saved target identity evidence remains missing
next: REVIEW_STAT35_C1_INPUT_CODE_AND_MISSING_CONTEXT_EVIDENCE
```

15.47の実績に合わせmetadata/現在地/工程表/引継ぎを同期。旧案の比較Gateやモデル利用まで承認済みにしない。
新入力コード・人工検証は完成、実データ接続は未成立、全対象保持の診断生成/再現は完了、性能は未評価。
過去文書/数値/状態/manifestを当時の記録として維持し、本番接続・既存成果物変更・2026参照は行わない。

## v1.37 / 2026-09-28

```yaml
document_version: 1.37
updated_at: 2026-09-28
remote_main_sha: f0834202fcac19919bfa4b8dce0ef9043a457a0a
phase_changed: STAT-35-NEXT-USE-DESIGN-01
related_pr: PR73_MERGED
related_run: NONE_DOCS_ONLY_PRIOR_EXECUTION_RECORDS_PRESERVED
decision: STAT35_NEXT_USE_DESIGN_DOCUMENTED_AWAITING_REVIEW
reason: User authorized docs-only merge synchronization and next-use draft; implementation and execution remain unauthorized
next: REVIEW_STAT35_NEXT_USE_DESIGN_AND_SCOPE
```

冒頭metadata・現在地・工程表・PR表・引継ぎを同期。PR #73のマージ事実と過去の生成/再現成功、
全成果物受入の判定記録未確認を区別する。v1.36以下の未マージ/レビュー待ちは当時の履歴として保持。
次用途はDRAFT_AWAITING_REVIEW、46 STAT/6エンジン索引は確認基点の根拠表であり別工程正本ではない。
既存C1固定値、記述統計disclosure、2026凍結、全体Goal状態を維持。今回テスト・実生成・再評価・DB接続なし。

## v1.36 / 2026-09-27

```yaml
document_version: 1.36
updated_at: 2026-09-27
remote_main_sha: baac9c113d8a61061d7e5f8bd2b7cd391d091c54
phase_changed: STAT-35-PLAYER-HISTORY-01
related_pr: PR72_MERGED_REVIEW_COMPLETED
decision: DESCRIPTIVE_PLAYER_HISTORY_GENERATED_REPRODUCED_AWAITING_REVIEW
related_run: stat35-player-history-01/run-20260927-072445-4FJxV4
next: REVIEW_STAT35_PLAYER_HISTORY_RESULT
```

固定相対値からの本人/開催対応、正確な開催等重み3/6/12窓と非重複推移を限定実装。
実入力101,326レース/716,837行、2,436選手/241,464群、推移573,435行、6成果物完全再現と独立照合を完了。
通常全体2,144成功/既存9skip/17,677親assertions、独立128M17件。旧記録を保持しmetadata/現在地/工程表/引継ぎを同期。
予測利用・最適窓・STAT全体完了は主張せず、historical_as_of_available=false、C1、2026凍結を維持。次はレビューのみ。

同VersionのPR #73レビュー修正（2026-09-28、上記初回YAML/実績は保持）:

```yaml
document_version: 1.36
updated_at: 2026-09-28
remote_main_sha: baac9c113d8a61061d7e5f8bd2b7cd391d091c54
phase_changed: STAT-35-PLAYER-HISTORY-01-PR73-REVIEW-FIX
related_pr: PR73_OPEN_NOT_MERGED_AWAITING_RE_REVIEW
review_fix_start_head: 4e95ddb1009feb5cf14fc22035d67fefe9979c65
decision: PR73_IDENTITY_SCOPE_FIX_VERIFIED_AWAITING_REVIEW
real_generation: DESCRIPTIVE_PLAYER_HISTORY_GENERATED_REPRODUCED_AWAITING_REVIEW
related_run: stat35-player-history-01/pr73-review-fix-20260927-210230-b2d48a1f
comparison: OLD_FOUR_DATA_FILES_UNCHANGED_NEW_SIX_FILES_REPRODUCED_BYTE_AND_SHA256_EXACT
next: REVIEW_PR73_IDENTITY_SCOPE_FIX_AND_PLAYER_HISTORY_RESULT
```

行品質を開催対応へ波及させず、正常行平均を保持し、本人未確定の対象行だけ履歴をブロック。新規8ケース、
最終通常全体2,152成功/既存9skip/18,129親assertions、独立128M17件を維持。修正版実生成/再現/独立照合成功。
旧データ4本文は不変、manifest/COMPLETEは修正コードidentityを記録。冒頭/現在地/工程表/引継ぎを同じ差分へ同期。
旧成果物・過去失敗/成功記録は保持、未マージ・追加差分未コミットで再レビュー待ち。予測利用・次工程は未許可。

## v1.35 / 2026-09-23

```yaml
document_version: 1.35
updated_at: 2026-09-23
remote_main_sha: 7f2a59d15af13785d36471f9f86c1dfa03f1222b
phase_changed: STAT-35-RACE-RELATIVE-01
related_pr: PR71_MERGED_TRACK_CONTEXT_V2_REVIEW_COMPLETED
decision: IMPLEMENTED_TESTED_EXPORT_PREFLIGHT_FAILED_AWAITING_REVIEW
real_generation: NOT_GENERATED
reproduction: SYNTHETIC_VERIFIED_REAL_NOT_STARTED
next: REVIEW_RACE_RELATIVE_EXPORT_PREFLIGHT_FAILURE
```

同レース内agari相対化を限定実装し、人工回帰・全テストを確認した。許可された1回の本番exportは接続先/READ ONLY確認で終了1、業務データ未取得。
原因の具体的返却値は未確認。自動再試行なし、失敗証跡保持。実件数/実再現を成功扱いにしない。
v2未解決期間10,646場日、SCR-STAT-35-02/STAT-35/37全体未完了、公表時点不明、予測利用未承認、points=null、C1/2026凍結を維持する。

同VersionのPR #72レビュー修正（上記YAMLは初回実行時の履歴として保持）:

```yaml
related_pr: PR72_AWAITING_REVIEW_NOT_MERGED
review_fix_start_head: 16f3d20e603688542d5b2d2e068fd50d3e009807
decision: DESCRIPTIVE_DATASET_GENERATED_REPRODUCED_AWAITING_REVIEW
real_generation: RACES_101326_CURRENT_RESULTS_716837_RELATIVE_700869_SPEED_1079
reproduction: FIVE_FILES_BYTE_AND_SHA256_EXACT
test_caveat: FULL_SUITE_SHARED_PEAK_ONE_FAILURE_AFFECTED_FILE_PASSES_INDEPENDENT_128M
next: REVIEW_PR72_FIXES_DESCRIPTIVE_DATASET_AND_TEST_LIMITATION
```

接続項目検証/安全な診断、current race_id、空入力カウンタの3点を修正。追加許可export/build/再現各1回はexit0。
冒頭metadata・現在地・工程表・15.44・引継ぎへ実結果を同期し、旧失敗と未確認原因は保持する。
remote main/version/数式/予測利用制限は不変。書込み0と業務読取りありを分離し、PRマージ/承認済みにはしない。

同Versionの2026-09-27前回テスト専用追加修正（直接PHPUnit実行時の履歴）: 過去Codexの1件失敗と後続ユーザーの5件失敗を区別し、
指定5件を独立128Mへ分離。全体2,096成功/既存9skip/失敗・エラー0、親17,268 assertions、対象5子242 assertions。
当時のtest_caveatを `RESOLVED_FIVE_TESTS_ISOLATED_128M_FULL_SUITE_PASS` へ更新し、
次を `REVIEW_PR72_HERMETIC_MEMORY_TEST_FIX` へ同期。実生成結果は保持・再実行なし。
詳細と途中の新helper期待値修正は15.44と実行文書に記録し、旧実行YAMLは当時の履歴として残す。

同VersionのPR72-HERMETIC-MEMORY-COMPLETION-01: 後続ユーザーArtisan実行の1失敗を別記録として保持。
静的確認で見つかった未分離11件を追加し、計16件を独立128M化。通常 `php artisan test` を1回実行し、
2,111成功/既存9skip/失敗・エラー0、親17,379 assertions・対象16子451 assertions・高ピーク親下16子451 assertions（別集計）。
現在のtest_caveatは `ISOLATED_MEMORY_CHECKS_AND_ARTISAN_FULL_SUITE_PASS`、次は `REVIEW_PR72_HERMETIC_MEMORY_COMPLETION`。
冒頭metadata/現在地/工程表/15.44/引継ぎを同期し、実生成状態・remote main・Version1.35を保持。
詳細は同実行文書と新規 `pr72-memory-completion-20260926-213318-6d625ace` 証跡。実データ再処理なし。

## v1.34 / 2026-09-23

```text
updated_by: Codex
remote_main_sha: 24a217e2fdfd21c7df490c55a08cb34760031e80
changed_sections: metadata, 5, 8, 15.43, 21, 25, 27
related_pr: PR #70 merged / two review fixes completed
related_run: stat35-37-track-context-02/run-20260923-061824-fe45e340
decision: V2_VERIFIED_PARTIAL_COVERAGE_AWAITING_REVIEW
next_action: REVIEW_TRACK_CONTEXT_V2_AND_HISTORICAL_GAPS
```

全42場の入口確認を一巡し、37場navigation確認・5場取得不能を区別。年刊表42観測と開催根拠3期間をv2へ追加。
歴史網羅は未完了。距離解決は同一10,660場日中3→14（前橋+4、平塚+7）、不明10,646、競合/後退0。
新形式のロード時照合、再封印改変拒否、v1不変を検証。v1・旧Raw・旧coverage・過去テスト記録は保持する。
今回の限定資料収集/検証許可と実績を同期し、本番DB接続/書込み・agari再処理・予測評価は0。
historical_as_of_available=false、prediction_use=NOT_AUTHORIZED、2026凍結。次はレビューのみ、未コミットで停止。

## v1.33 / 2026-09-23

```text
updated_by: Codex
remote_main_sha: 3d03273ab8aee5a23e8fd317e98a2dc7bad8e9e6
changed_sections: metadata, 5, 8, 15.42, 21, 25, 27
related_pr: PR #69 merged / backfill result review completed
related_run: stat35-37-track-context-01/run-20260923-104546-92962a55
decision: IMPLEMENTED_TESTED_PARTIAL_HISTORICAL_COVERAGE_AWAITING_REVIEW
next_action: REVIEW_STAT35_37_TRACK_CONTEXT_IMPLEMENTATION_AND_COVERAGE_GAPS
```

今回のみ許可されたファイルマスタ/日付解決/距離換算を実装・テストし、公式資料と限定READ ONLY対象一覧からcoverageを生成。
42場44観測版は歴史通年42場対応を意味しない。3/10,660場日だけ解決、残り10,657期間不明、競合0、原典確認の不足を維持する。
PR #69 mergeと保存工程レビュー完了をmetadata・現在地・工程表・引継ぎへ同期。過去のBatchRun・件数・状態記録は変更しない。
本番書込み0、旧処理再実行0、予測利用NOT_AUTHORIZED、historical_as_of_available=false、2026凍結。未コミットでレビュー待ち。

同Version内のPR #70レビュー修正: Section 15.42の追記にP1抜粋取り違え/P2ロード時原文照合、
新旧manifest・coverage比較・回帰検証・別証跡を記録。初回結果は旧検証の履歴として残す。
現在地・工程表・引継ぎは `PR70_REVIEW_FIX_VERIFIED_AWAITING_REVIEW` へ同期し、remote mainは変更しない。

## v1.32 / 2026-09-22

```text
updated_by: Codex
remote_main_sha: 88ac8ff4677dde5c53a40ef028e5d83923671e07
changed_sections: metadata, 5, 8, 15.41, 21, 25, 27
related_pr: PR #68 merged / pilot review completed
related_run: stat35-production-backfill-2022-2025-01/run-20260922-155146-60eeb526
decision: ALL_INTERVALS_PROCESSED_AND_VERIFIED_AWAITING_REVIEW
next_action: REVIEW_STAT35_PRODUCTION_BACKFILL_2022_2025_RESULT
```

pilot日を除く2022-2025の限定許可、48区間の正式保存・READ ONLY照合・保存後dry-run完了を記録。
BatchRun 121-168、実増分899,506/716,347、保存後予定は全区間0/0、NO_IMPORT 29、CANCELLED skip 113、MISSINGを保持。
pilot BatchRun 120と保存490/490の不変を終端確認し、統合値へ1回だけ加算。過去の履歴は変更しない。
今回の本番書込みは観測/current補完/batch監査でありDDLなし。as-of回復・正式STAT採用・精度改善は未確認。
次は結果レビューだけ。2026凍結、historical_as_of_available=false、旧モデル/成果物、backup復元試験未実施を維持。

## v1.31 / 2026-09-22

```yaml
document_version: 1.31
updated_at: 2026-09-22
remote_main_sha: 15bb52de18eb5908a01d181d8177f33c0b1ca583
phase_changed: STAT35_PRODUCTION_BACKFILL_PILOT_01_SAVED_AND_VERIFIED_AWAITING_REVIEW
related_pr: PR67_MERGED
related_run: stat35-production-backfill-pilot-01/run-20260922-110730-e4408a8c
decision: Save and verify only 2024-12-31; post-save read-only dry-run requires no additions or updates
reason: Explicit one-day write authorization after the reviewed successful dry-run; other dates remain unauthorized
```

PR #67 merge・Migrationレビュー完了、前回dry-run、今回BatchRun 120の実保存/照合/保存後dry-runを記録。
予定・正式summary・DB差分は490観測/490現在値補完で一致。VALID 477/MISSING 13、既存非agari項目不変、保存後予定0/0。
冒頭metadata・現在地・工程一覧・引継ぎを同期し、過去の未承認・未実施記録は保持する。
1日分の業務値・監査書込みがありwrite=0ではない。他期間・STAT計算・予測利用は未承認、historical_as_of_available=false/2026凍結は不変。

## v1.30 / 2026-09-22

```yaml
document_version: 1.30
updated_at: 2026-09-22
remote_main_sha: 2715757a6952dbf32fc97f881945d94721a08282
phase_changed: STAT35_PRODUCTION_MIGRATION_01_APPLIED_AND_SCHEMA_VERIFIED_AWAITING_REVIEW
related_pr: PR66_MERGED
related_run: stat35-production-migration-01/run-20260922-093636-fa14dd06
decision: Apply only the authorized target migration once; verify schema read-only; await result review
reason: Completed backup and explicit one-time DDL authorization; backfill and dry-run remain unauthorized
```

対象Migrationの本番適用・batch 14・構造28項目一致を記録し、冒頭metadata・現在地・工程一覧・説明・引継ぎを同期。
今回のwriteは対象DDLと適用履歴に限定。旧preflightのwrite=0・未適用記録はそのまま残す。
全DBbackup取得・一覧確認済みと復元試験未実施を区別。今回backup再取得/再hash/一覧再取得なし。
backfill/dry-run/同期/2026分析は未実施、次は適用結果レビューのみ。既存model・historical_as_of_available=false・holdout凍結は不変。

## v1.29 / 2026-09-22

```yaml
document_version: 1.29
updated_at: 2026-09-22
remote_main_sha: adb847c5b0a2b0778ecb02a57c37bffbecf2d926
phase_changed: STAT35_PRODUCTION_PREFLIGHT_01_COMPLETED_AWAITING_APPLICATION_REVIEW
related_pr: PR65_MERGED
related_run: stat35-production-preflight-01/run-20260922-Jtc8st
decision: Sync merged implementation; complete read-only metadata preflight and plan; await application procedure review
reason: Target migration is pending and result synchronization requires the new schema; production writes remain unauthorized
```

冒頭metadata・現在地・工程一覧・説明・引継ぎを同期。旧レビュー待ちと過去変更履歴は当時の記録として残す。
対象schema未適用、他の未適用0、READ ONLY on/on、512M plan成功を確認。Migration/dry-run/backfill未実施。
独立bounded-memoryテスト128Mを維持し、本番の一律128M制限を解除。実データ512M基本例・実測調整へ同期する。
本番書込みNOT_AUTHORIZED、2026 FROZEN、historical_as_of_available=false、Growth不採用・既存C1・Goal 4/5を維持する。

## v1.28 / 2026-09-21

PR #65の2指摘だけを修正。NO_IMPORTをMen限定、対象外gapをNO_IMPORT_UNSUPPORTEDへ分離する。
Manual CANCELLEDの非空結果行をfail closedとし、PJ0326 blank partialの既存契約は変更しない。
現在地はSTAT35_STORAGE_BACKFILL_01_PR65_REVIEW_FIX_AWAITING_REVIEW、次はREVIEW_PR65_REVIEW_FIX。
schema変更なし。本番Migration/backfill、次実装はNOT_AUTHORIZED、2026 FROZENと旧履歴を維持する。

## v1.27 / 2026-09-21

PR #64 MERGED、main/origin `cefd6b4e777315f08cc10980369d5734196d01f7` を確認。
ユーザー明示許可によりSTAT-35-STORAGE-BACKFILL-01のコード・Migration・人工テストを実装。
currentとimport別append-only観測を分離し、Raw検証付きbackfillは2022-2025限定とした。
監査状態をCOMPLETED_PR64_MERGED、現在地をSTAT35_STORAGE_BACKFILL_01_IMPLEMENTATION_AWAITING_REVIEWへ更新。
本番Migration/backfillは未実行・未許可。構造化保存とhistorical as-of availabilityを混同しない。
次はレビューのみ、次実装NOT_AUTHORIZED。旧履歴・既存予測仕様・2026 FROZENを維持する。

## v1.26 / 2026-09-21

main `121517ebf6edb311e20be2b730b378cb7b201c27`、PR #63 MERGED / PR #64 OPEN / REVIEW_FIXを確認。
開始HEAD `267a17c74b4254de4d04337ab7dc38238598de0d`、同じaudit branchを使用。
中止部分空欄行、未解決選手blocker、再現attempt衝突の3点のみをv3監査契約で修正した。
全件READ ONLY再監査・DBなし連続2回再現・独立照合を完了。旧runとZIPはSTART/END不変。
identity blockerは0、readinessはBLOCKED_INSUFFICIENT_RAW_HISTORY。各27生成物がBYTE_EXACT。
STAT35_DATA_BLOCKED_INSUFFICIENT_HISTORY_PR64_REVIEW_FIX_VERIFIED_AWAITING_REVIEWとしてレビューを待つ。
Growthのk=34/w=+0.34・不採用、旧pilot保留、2026 FROZENを維持し次実装はNOT_AUTHORIZED。

## v1.25 / 2026-09-21

main `121517ebf6edb311e20be2b730b378cb7b201c27`、PR #63 MERGEDを確認。
ユーザー明示許可のSTAT-35-DATA-READINESS-AUDIT-01を開始。旧Growth不採用・旧pilot保留を保持。
結果参照契約をDATA_QUALITY_ONLYへ明確化し、rank/winnerの予測分析・2026利用は禁止のまま。
実測・DB無効再現・独立照合を完了。BLOCKED_IDENTITY_MAPPINGとas-of履歴0を記録。
監査以外の実装はNOT_AUTHORIZED。STAT35_DATA_BLOCKED_AWAITING_REVIEWとして停止。

## v1.24 / 2026-09-20

PR #63 `OPEN / REVIEW_FIX`、開始HEAD `65a4cee3d987717e774b95b9381f26b6dd110eaa`。
remote mainは `17cc492e077034a4ebab46594cb2a6e1d3c3642f`、PR #62はMERGED。
`OUTCOME_SOURCE_SELF_SIGNED_SIDECAR_TRUST` を `FIXED_REVIEWED_PER_YEAR_OUTCOME_SEALS` で修正する。
固定8原本を実物照合し、mixed-year registryなし・既存temporal境界のまま年別sealを検証する。
旧成果物・旧ZIPを保持した新IDで実集計・41生成物byte-exact再現を完了。旧新の数値・診断は完全一致。
k=34/w=+0.34、2025 NOT_TRANSFERREDを維持し、PR63_REVIEW_FIX_VERIFIED_AWAITING_REVIEWへ更新。
次はREVIEW_PR63_OUTCOME_SEAL_FIX_AND_WAIT_FOR_USER_INSTRUCTION、次実装NOT_AUTHORIZED。2026 FROZENは変更しない。

## v1.23 / 2026-09-20

PR #62 MERGED、main `17cc492e077034a4ebab46594cb2a6e1d3c3642f`からユーザー許可の独立trend adjustment実験を開始。
MEETING_DELTA_LAG_1と2024 P99規格化、固定101 grid、旧選択規則を固定。2025はpost-selection development診断のみ。
旧v1.22記録・旧不採用・保留を維持する。実集計・39成果物完全再現を完了し、w=+0.34の2025転送条件不達成としてレビュー待ち。
正式採用・2026・次工程は開放しない。

## v1.22 / 2026-09-20

PR #62 `OPEN / REVIEW_FIX_COMPLETED_AWAITING_REVIEW`。remote mainは `eef27c9e80d80a733a4c0c3c82e18151259e8f87`。
preseal provenance、DAY NULL start、C1正常分母の修正を維持し、同日別開催のfail-closedとstrict export registry isolationを追加。
review-fix-02 source/analysisのREAD ONLY recapture・DB無効実集計・36成果物BYTE_EXACT再現を完了。
初回選択MEETING_DELTA_LAG_1と適格9候補は維持、同日曖昧20出走による数値差を旧runと分離して記録。
ROBUST_RHOは0.009639846634467018から0.00964758593957939。正式STAT未採用、C1未変更、2026 access=0。
旧v1.21・旧成果物・失敗証拠は保持し、次工程を開放せず未コミットレビュー待ちとする。

## v1.21 / 2026-09-19

PR #61 mergedを反映。GROWTH-TREND-ANALYSIS-01を改訂契約で実装・実集計・再現し、開発選択済みレビュー待ちへ更新。
実発走履歴を廃止し、結果非依存の得点観測・全trend seal・両年development選択へ変更。
旧不採用・保留・失敗証拠は維持し、2026と次工程は開放しない。

## v1.20 - 2026-09-19

- PR #60 merge main `62abf52c612038cfd32e1ccbfd069a319981e629` からGROWTH-ADJUSTMENT-CALIBRATION-01を開始。
- 初回2024選択w=+0.03、2025 validationはNOT_REPLICATED。正式growth weight未採用、C1未変更。
- reviewで2025 w=0 preflightがselection seal前にlabels/contributionsを読んでいたことを検出。
- 選択計算そのものが2025を使用した証拠はないが、temporal isolation契約違反として修正。
- review-fixでは2025 outcome-bearing accessをseal後に移動し、専用監査artifactと拒否テストを追加。
- 修正版数値は未丸め値・全曲線まで旧実行と一致（NUMERICALLY_UNCHANGED_AFTER_TEMPORAL_FIX）。全30生成物の完全再現も成功。
- 旧analysis/ZIP/ログを保持し、2026 access=0、正式growth weight未採用、C1未変更を維持する。PR #61は未マージでレビュー待ち。

## v1.19 - 2026-09-19

- PR #60でv1のzero massによるpoint意味付けを確認。v1の正当な計算・固定成果物は保持。
- v2 SIGN_PRESERVING_POINTを独立追加し、raw全件一致・符号保持・全層診断・v1/v2再現を確認。
- v1とv2を区別してレビュー待ちとし、モデル改善・正式採用の結論は出さない。

## v1.18 - 2026-09-18

- GROWTH-POINT-ANALYSIS-01の結果閲覧前契約、全出走診断、再現確認とレビュー待ち状態を記録。
- 旧C1/開催grade分析を保持し、成長指標の弱い正方向/逆方向/非単調性をモデル採用と区別。
- 2026、LIVE、正式STAT/モデル変更の禁止を維持。

## v1.17 - 2026-09-18

ユーザー指定の開催グレード主軸へ移行し、旧級班分析を保持。50,078レースの開催対応・4指標集計・保存資料のみの再現を完了。
GPヘッダー補完62レース、UNKNOWN/矛盾0。件数加重合算と構成差の補助表を記録し、未コミットのレビュー待ち。

## v1.16 - 2026-09-18

main `67d6795fde722cae96536b54d8894b761bd8fd16`、PR #58マージ・コード/成果物レビュー完了、旧PR #57 ZIP照合完了をユーザー確認に基づき反映。
TACTICAL-GRADE-ANALYSIS-01だけを新規許可。旧レビュー待ち履歴・旧pilot保留・既存モデル・holdout制限は保持する。

## v1.15 - 2026-09-18

PR #58のHEAD `d2d8df6bf701774b6ef3e9b4c6b5289f64991379` 上で結果照合のレビュー3件を修正。同じ固定63件を別evaluation_idで照合・保存・再現し、旧値・旧原本不変を確認。旧実行記録を維持し、修正確認のレビュー待ちで停止。main SHA、モデル数値、Gate、holdout/LIVE制限は変更しない。

## v1.14 - 2026-09-18

main `aebabc3618706a8e9c1e7b2f2c4c88538e620b02`、関連PR #57。コードレビュー・マージ済みを反映し、ChatGPTの旧ZIP照合未完了を明記。TACTICAL-PREDICTION-RESULT-01の実装と固定63レースの結果照合・再現を完了しレビュー待ちへ移行。既存予測とモデルは更新しない。

## v1.13 - 2026-09-18

main `e559155d703bf8384c035a82e890c3abcf24ff38` でPR #56のレビュー修正・マージを確認。最終成果物レビュー待ちを解消し、ユーザー指示に基づくTACTICAL-PREDICTION-PIPELINE-01の接続実装と開発データ技術検証を許可。63対象の入力・予測一致、DBなし再現を完了し、接続機能レビュー待ちへ移行。既存モデル・実行記録・精度結果は更新しない。2026、LIVE、再学習は引き続き禁止。

## v1.12 - 2026-09-17

PR #55のC1 v2を維持したTACTICAL-HISTORY-FINAL-01を実施。OOF-1/2再利用、OOF-3追加、3組One-SE、2022-2025最終fit、保存モデル読込、独立2回の再現性を完了。
最終lambda=0.1、状態はFINAL_FIT_REPRODUCED_AWAITING_REVIEW。新規精度評価・旧モデル再学習・2026参照は行わず、ユーザーによる最終候補レビュー待ちで停止する。

## v1.11 - 2026-09-16

PR #55限定修正: 制約付き近接更新・中止履歴の修正と、修正版solverによるC0/C1比較を許可。v1失敗履歴・旧pilot保留を保持し、2026・本番書込み・正式モデル更新は禁止のまま。
修正版C0/C1の2024/2025学習・予測・paired比較・実データ再現性を完了。追加効果Gateおよび既存対STAT-01 Gateは通過。結果レビュー待ちで停止し、正式freeze/LIVE工程には自動移行しない。

## v1.10 - 2026-09-15

TACTICAL-HISTORY-01をユーザー指示に基づく独立実験として開始。
旧pilot保留、E08否定結果、既存凍結契約、BT-04/BT-05/2026の禁止は維持。
remote mainは `d7975fb3b09f127cb9afb9c89aa1bb33d07c857c`、開始HEADは `db653d64a34914802e49d93818720a65ea2eb8e2`。
入力生成・C0再利用検証・C1学習試行まで実施し、固定8lambdaすべて非収束で停止。精度とGateは未評価であり、性能FAILや0差ではない。

## v1.9 — 2026-09-13

```yaml
document_version: 1.9
updated_at: 2026-09-13
remote_main_sha: d7975fb3b09f127cb9afb9c89aa1bb33d07c857c
phase_changed: TACTICAL-PILOT-01_BLOCKED_INPUT_SEMANTICS
related_pr: PR #53 merged; new experiment authorized by current user request
related_run: bt03e08-20260903-091134-1156938f229c4861ac1975b5e5477029
decision: Close E08 as a verified negative result; authorize only the new tactical-input pilot after semantic eligibility is confirmed
reason: Existing E08 comparison is complete; PJ0315 aggregate field as-of dates and target-result exclusion remain unverified
```

過去の変更履歴は当時の記録として維持する。現在地のE08評価待ち記載を解消し、今回未学習であることと2026 / BT-04 / BT-05の制限を維持した。

## v1.8 — 2026-09-03

```yaml
document_version: 1.8
updated_at: 2026-09-03
remote_main_sha: 376b291452e2d682ddc5b22d90a7e0fc286d1e06
phase_changed: BT-03E-08_IMPLEMENTED_AWAITING_DEVELOPMENT_EVALUATION
related_pr: PR #53
related_run: NONE
decision: BT-03E-08 engineering implemented; development evaluation remains blocked until merge
reason: P1 source and E06 Q2 remain frozen while only winner-conditioned direct P3 is retrained
```

## v1.7 — 2026-09-02

```yaml
document_version: 1.7
updated_at: 2026-09-02
remote_main_sha: 376b291452e2d682ddc5b22d90a7e0fc286d1e06
phase_changed: BT-03E-08_ENGINEERING
related_pr: BT-03E-07 formal evaluation and diagnostic
related_run: BT-03E-07 reproducibility-verified development artifact
decision: BT-03E-07 closed as a reproducible negative result; proceed with P1/Q2-frozen winner-conditioned direct P3
reason: E07 deterioration was isolated to P2/P3 full-field semantics and winner probability mass consumption
```

反映:

- BT-03E-07 reproducibility `VERIFIED`、performance `FAIL / REDESIGN_REQUIRED`、2026 access `0`
- E06/E07診断完了と主要結論
- 次工程をBT-03E-08 engineeringへ更新

## v1.6 — 2026-08-29

```yaml
document_version: 1.6
updated_at: 2026-08-29
remote_main_sha: 72c91713b6c1ed4e71021231c369d9b25579e5fa
phase_changed: BT-03E-07_IMPLEMENTED_AWAITING_DEVELOPMENT_EVALUATION
related_pr: BT-03E-07 implementation branch
related_run: BT-03E-06 formal development evaluation artifact
decision: BT-03E-06 closed as a reproducible negative result; BT-03E-07 P1-frozen direct P2/P3 model frozen and implemented
reason: BT-03E-06 preserved winner and passed all gates except P2/P3 non-inferiority
```

反映:

- BT-03E-06 reproducibility `VERIFIED`、integrity `PASS`、performance `FAIL / REDESIGN_REQUIRED`
- Non-InferiorityはP2・P3でFAILし、その他のGateはPASS、2026 accessは`0`
- BT-03E-07 design freezeと実装完了、merge後development evaluation待ち
- 2026 access `0`とBT-04 / BT-05 blockを維持

## v1.5 — 2026-08-29

```yaml
document_version: 1.5
updated_at: 2026-08-29
remote_main_sha: b2833a9dc822753c6d3e8515f424010cb46c1ec7
phase_changed: BT-03E-06_IMPLEMENTED_AWAITING_DEVELOPMENT_EVALUATION
related_pr: BT-03E-06 implementation branch
related_run: BT-03E-05 formal development evaluation artifact
decision: BT-03E-05 closed as a reproducible negative result; BT-03E-06 winner-conditioned sequential decoder frozen and implemented
reason: BT-03E-05 improved winner and Hit@3 but failed P2/P3 non-inferiority
```

反映:

- BT-03E-05 reproducibility `VERIFIED`、integrity `PASS`、performance `FAIL / REDESIGN_REQUIRED`
- Non-InferiorityはP2・P3でFAILし、その他のGateはPASS
- BT-03E-06 design freezeと実装完了、merge後development evaluation待ち
- 2026 access `0`とBT-04 / BT-05 blockを維持

## v1.4 — 2026-08-29

```yaml
document_version: 1.4
updated_at: 2026-08-29
remote_main_sha: 842a73272e9adc8c21a6a0ff7fc46518afd47484
phase_changed: BT-03E-05_IMPLEMENTED_AWAITING_DEVELOPMENT_EVALUATION
related_pr: PR #49 / BT-03E-05 implementation
related_run: BT-03E-04 formal development evaluation artifact
decision: BT-03E-04 closed as a reproducible negative result; BT-03E-05 winner-preserving decoder frozen and implemented
reason: P1 winner signal exceeded coherent first while P3 non-inferiority and overall superiority remained insufficient
```

反映:

- BT-03E-04 reproducibility `VERIFIED`、integrity `PASS`、performance `FAIL / REDESIGN_REQUIRED`
- NI / SuperiorityはFAIL、Temporal / Supporting / Tie / Position Redesign / Win PreservationはPASS
- P1 winnerを固定するBT-03E-05 design freezeと実装完了、merge後development evaluation待ち
- 2026 access `0`とBT-04 / BT-05 blockを維持

## v1.3 — 2026-08-27

```yaml
document_version: 1.3
updated_at: 2026-08-27
remote_main_sha: 05c7f9340414b5f5695fb5aa238512372d46c33c
phase_changed: BT-03E-04_IMPLEMENTED_AWAITING_DEVELOPMENT_EVALUATION
related_pr: PR #47 / BT-03E-04 implementation
related_run: BT-03E-03 v2 formal development evaluation artifact
decision: BT-03E-03 v2 completed with reproducible negative result; BT-03E-04 decoder separation implemented
reason: fixed probabilities improved P2/P3/Hit@3 but not WIN, requiring decision-rule separation without retraining
```

反映:

- BT-03E-03 v2 reproducibility `VERIFIED`、integrity `PASS`、performance `FAIL / REDESIGN_REQUIRED`
- optimizer縮退解消、eligible lambda `0.1` / `1.0`、selected lambda `0.1`
- BT-03E-04 design freezeと実装完了、merge後development evaluation待ち
- 2026 access `0`とholdout freezeを維持

## v1.2 — 2026-08-25

```yaml
document_version: 1.2
updated_at: 2026-08-25
remote_main_sha: 6fc68f9d17a1b70f8dcb196bd6bf38fb98d75301
phase_changed: BT-03E-03_DESIGN_FROZEN
related_pr: PR #43 / PR #44
related_run: BT-03E-02 formal development evaluation artifact
decision: BT-03E-02 completed with reproducible negative result; BT-03E-03 implementation authorized
reason: exact Position 3 performance was temporally unstable while Winner performance improved
```

反映:

- BT-03E-02 engineering / development evaluation完了、reproducibility `VERIFIED`
- BT-03E-02 performance `FAIL / REDESIGN_REQUIRED`
- 2024/2025 WIN改善と2024 POSITION_3悪化を記録
- BT-03E-03 position-specific sequential probability designをfreeze
- 2026 access `0`とholdout freezeを維持

## v1.1 — 2026-08-23

```yaml
document_version: 1.1
updated_at: 2026-08-23
remote_main_sha: e379bcc5761c38c8d61f610ee4f528edc81115a5
phase_changed: BT-03E-02_DESIGN_FROZEN
related_pr: PR #42
related_run: none
decision: BT-03E-02 Decision 01-12 + 10-A + 10-B approved and implementation contract hardened
reason: ChatGPT design review completed, user explicitly approved all decisions, and PR #42 review ambiguities were resolved
```

反映:

- BT-03E-02 v1のDecision 01～12、補助Decision 10-A / 10-Bを実装前契約としてfreeze
- 次工程をBT-03E-02 implementationへ変更
- continuous score採用に伴い整数final pointsを `NOT_APPLICABLE / SUPERSEDED` へ変更
- frozen / unfrozen contract、Goal Completion Matrix、BT-04開始条件を整合
- PR #41および更新時のremote `main` SHAを記録
- PR #42のdesign freezeおよびreview fixを記録（OPEN / UNDER_REVIEW）
- inner alpha / Outer境界、Composite Penalty、RMS、pairwise label、threshold applicabilityを一意化
- 2026 final holdout freezeを維持

## v1.0 — 2026-08-23

初版。

反映:

- Statistics feature foundation
- BT-01正式baseline
- BT-02 Production run 5
- BT-03 Production run 6
- BT-03B/C値域分析
- 旧BT-03D SUPERSEDED判断
- BT-03E-01実装・2024 negative OOS result
- PR #40 merge
- 2022～2025 development corpus扱い
- 2026 holdout freeze
- 次工程をBT-03E-02設計とする工程ゲート

Remote `main`:

```text
82d394ec014b46ca4792858fbe9fe35eaa7434d5
```

---

# 26. リポジトリ同期契約

今後このMASTER PLANまたは統計エンジン実装を更新する前に、現在のlocal状態を推測せず、次を実施する。

1. 現在の作業ツリーがcleanであることを確認
2. `git fetch origin`でremote状態を確認
3. `main`へ移動
4. `git pull --ff-only`
5. `main`がremoteの最新mergeを含むことを確認
6. その後、目的に対応する未使用branchを作成

未コミット変更が存在する場合は、勝手にreset / restore / stash / cleanせずSTOPする。

---

# 27. 次回ChatGPT開始時の確認文

統計エンジン作業を再開するとき、ChatGPTは少なくとも次を認識してから回答する。

```text
Current:
Phase = C1-STAT10-ABLATION-01 / COMPLETED_DEVELOPMENT_COMPARISON_AWAITING_REVIEW / PR85_MERGED
Current main = db94280fbab7434ccf34043dcef40b6deb7401df / PR85_MERGED
STAT10 ablation = ORIGINAL16_VALIDATED_THEN_NAMED15_PROJECTION / OLD_OUTER_C1_RETRAINING_0 / COMPLETED_ONE_EXECUTE_TWO_INDEPENDENT_RUNS / SEE_15.58
STAT10 ablation result = INCREMENTAL_GATE_NOT_PASSED_NON_INFERIORITY_TEMPORAL_SUPERIORITY_UNMET_RETAIN_C1 / STAT01_AUX_PASS / SEVENTY_SIX_ENUMERATED_SEMANTIC_FILES_IDENTICAL / MANIFEST_671bbcd97463123076846c4dcd6f7273e6e6aa0d9da8dabba1dc205ef2dca339
Score gap P3 = EQUAL_DECIMAL_SCORE_BINARY64_RESIDUAL_UNRESOLVED / NOT_USED_OR_FIXED_HERE
Score gap = FIXED_C1_ALL_ENTRANT_RAW_MINUS_MEAN_ONLY / OLD_C1_RETRAINING_0 / SEE_15.57
Score gap result = INCREMENTAL_GATE_NOT_PASSED_NON_INFERIORITY_TEMPORAL_SUPERIORITY_UNMET_RETAIN_C1 / STAT01_AUX_FAIL / SEVENTY_SIX_SEMANTIC_FILES_IDENTICAL / MANIFEST_214ba0fa3a144b7053457c5ad2d123e874256d0f34927a04700ce74186dc9ee1
STAT17 compare = FIXED_C1_HISTORY4_DIVERSITY_ONLY / COMPLETED_ONE_EXECUTE_TWO_INDEPENDENT_RUNS / OLD_C1_RETRAINING_0 / SEE_15.56
STAT17 result = INCREMENTAL_GATE_NOT_PASSED_SUPERIORITY_UNMET_RETAIN_C1 / STAT01_AUX_PASS / SEVENTY_SIX_SEMANTIC_FILES_IDENTICAL / MANIFEST_f99c7d0dbed5e1fda0e387d2877cb181629e0236953a1317f9ab76716283b69f
STAT36 compare = FIXED_F175DEFF_SOURCE_LIMITED_EXPERIMENT_ONLY / OLD_C1_RETRAINING_0 / S_TIMING_UNKNOWN / NOT_FORMALLY_ADOPTED / NO_LIVE_OR_2026
STAT36 compare result = INCREMENTAL_GATE_NOT_PASSED_RETAIN_C1 / STAT01_AUX_GATE_PASS / SEVENTY_SIX_SEMANTIC_FILES_INDEPENDENTLY_REPRODUCED / SEE_15.55
STAT36 C1 candidate = FIXED_THREE_SAVED_BUNDLES_ONLY / RACES_99669_ENTRIES_706051_ALL_NUMERIC_NULL_0 / FIFTEEN_FILES_AND_MANIFEST_REPRODUCED / REVIEW_CANDIDATE_ONLY
STAT36 C1 candidate manifest = f175deff20fe8905b40e92ffa2d9ff16de71f432584c9e13e129d47045306437 / S_TIMING_UNKNOWN / TRAINING_EVALUATION_NOT_AUTHORIZED
STAT36 start-count = FIXED_PR64_LEDGER_2022_2025 / RACES_101326_ENTRIES_717709_FETCH_VERSIONS_127160_ROWS_901038 / S_TIMING_UNKNOWN / FOURTEEN_ARTIFACTS_REPRODUCED
STAT36 previous observation v1 = FIXED_PR64_LEDGER_2022_2025 / IMPORTS_127121_RACES_101297_ROWS_900049 / ALL_START_VALUES_NULL
STAT36 limits = S_AGGREGATION_PERIOD_AND_BASELINE_UNKNOWN / UNKNOWN_POSITION_DEFINITION / MISSING_INITIAL_POSITION / historical_as_of_available=false / prediction_use=NOT_AUTHORIZED / points=null
STAT36 manifest = d3aba9fb2374fe17c4f360cbd74b9b7738bfbb794a3c751e30ed551d7868ae1b / V1_INDEPENDENT_RAW_REPRODUCTION_NOT_RERUN_WITH_V2_V3
Diagnostic = POST_HOC_DESCRIPTIVE_ONLY / RACES_50078_ENTRIES_356209 / FOURTEEN_FILES_AND_MANIFEST_IDENTICAL / SOURCE21_CODE35_UNCHANGED
Diagnostic manifest = 304d2329df00ff17de383352ec45b01522430cb9181c2e13c399f531b8d451f9 / UTILITY_EXACT / NO_NEW_GATE_OR_ADOPTION
Draft = docs/stat35-next-use-design-01.md / ORIGINAL_DRAFT_PRESERVED / FIXED_COMPARE_01_SCOPE_ONLY_AUTHORIZED
Prior input diagnostic record = docs/stat35-c1-input-01.md / 99669_RACES_706051_ENTRIES / ALL_NULL_MISSING_IDENTITY_CONTEXT_EVIDENCE
Prior input diagnostic reproducibility = FOURTEEN_FILES_AND_MANIFEST_IDENTICAL / NOT_RERUN
Current context record = docs/stat35-c1-context-01.md / CANDIDATES_705048_HELD_1003 / FIXED_CANDIDATE_ONLY_ACCEPTED_FOR_INPUT_PREPARATION / ORIGINAL_REVIEW_PENDING_UNCHANGED
Current context reproducibility = SEVEN_FILES_AND_MANIFEST_IDENTICAL / ONE_READ_ONLY_EXTRACTION_NO_DB_RECONNECT
Current input record = docs/stat35-c1-input-02.md / ENTRIES_706051_CONNECTED_705048_NUMERIC_685719_NULL_20332
Current input reproducibility = FOURTEEN_FILES_AND_MANIFEST_IDENTICAL / C1_UNCHANGED / HELD_1003_SET_VERIFIED / OFFLINE_ONLY
Current comparison = C2_ONLY_TWO_INDEPENDENT_RUNS / LAMBDA_0.1_BOTH_OUTERS / 76_FILES_PER_RUN_IDENTICAL / C1_RETRAINING_0
Current comparison integrity = SOURCE_39_CODE_354_UNCHANGED / INPUT_COHORT_NULL_TYPES_ORDER_PRESERVED
Current comparison result = C2_MINUS_C1_GATE_NOT_PASSED_HIT3_SUPERIORITY / C2_MINUS_STAT01_GATE_PASS / NOT_ADOPTED
Current comparison manifest = 8fc40000c93bbc604caac37cf21e60519f26f28c4e77cf9284fa31403f5487c8
BT-03E-01 engineering = COMPLETED
BT-03E-01 coarse points = REJECTED
BT-03E-02 engineering / development evaluation = COMPLETED
BT-03E-02 reproducibility = VERIFIED
BT-03E-02 performance = FAIL / REDESIGN_REQUIRED
BT-03E-03 v2 = COMPLETED_WITH_REPRODUCIBLE_NEGATIVE_RESULT
BT-03E-03 v2 reproducibility = VERIFIED
BT-03E-03 v2 integrity = PASS
BT-03E-03 v2 performance = FAIL / REDESIGN_REQUIRED
BT-03E-04 design = FROZEN
BT-03E-04 = COMPLETED_WITH_REPRODUCIBLE_NEGATIVE_RESULT
BT-03E-04 reproducibility = VERIFIED
BT-03E-04 integrity = PASS
BT-03E-04 performance = FAIL / REDESIGN_REQUIRED
BT-03E-05 design = FROZEN
BT-03E-05 = COMPLETED_WITH_REPRODUCIBLE_NEGATIVE_RESULT
BT-03E-05 reproducibility = VERIFIED
BT-03E-05 integrity = PASS
BT-03E-05 performance = FAIL / REDESIGN_REQUIRED
BT-03E-06 design = FROZEN
BT-03E-06 = COMPLETED_WITH_REPRODUCIBLE_NEGATIVE_RESULT
BT-03E-06 reproducibility = VERIFIED
BT-03E-06 integrity = PASS
BT-03E-06 performance = FAIL / REDESIGN_REQUIRED
BT-03E-07 design = FROZEN
BT-03E-07 = COMPLETED_WITH_REPRODUCIBLE_NEGATIVE_RESULT
BT-03E-07 reproducibility = VERIFIED
BT-03E-07 performance = FAIL / REDESIGN_REQUIRED
BT-03E-07 2026 access = 0
BT-03E-06 vs BT-03E-07 diagnostic = COMPLETED
BT-03E-08 = COMPLETED_WITH_REPRODUCIBLE_NEGATIVE_RESULT
BT-03E-08 reproducibility = VERIFIED
BT-03E-08 performance = FAIL / REDESIGN_REQUIRED
BT-03E-08 same-condition rerun = FORBIDDEN
TACTICAL-PILOT-01 = BLOCKED_INPUT_SEMANTICS / NO_FIT
TACTICAL-HISTORY-01 v2 = EVALUATED_AND_REPRODUCED / PASS_DEVELOPMENT_INCREMENTAL_EFFECT_ONLY
TACTICAL-HISTORY-FINAL-01 = REVIEW_COMPLETED_PR56_MERGED
TACTICAL-PREDICTION-PIPELINE-01 = REVIEW_COMPLETED_PR57_MERGED / REPORT_ZIP_REVIEW_COMPLETED_USER_CONFIRMED
TACTICAL-PREDICTION-RESULT-01 = REVIEW_COMPLETED_PR58_MERGED / IN_SAMPLE_REPLAY_TECHNICAL_CHECK
TACTICAL-GRADE-ANALYSIS-01 = VERIFIED_AWAITING_REVIEW / SAVED_OUTER_C1_BREAKDOWN_ONLY
TACTICAL-MEETING-GRADE-ANALYSIS-01 = VERIFIED_AWAITING_REVIEW / CURRENT_PRIMARY_ANALYSIS_AXIS
GROWTH-POINT-ANALYSIS-01 = PR60_MERGED / DEVELOPMENT_DIAGNOSTIC_ONLY
GROWTH-POINT-ANALYSIS-01-v2 = PR60_MERGED / SIGN_PRESERVING_POINT / V1_UNCHANGED
GROWTH-ADJUSTMENT-CALIBRATION-01 = PR61_MERGED / COMPLETED_NEGATIVE_DEVELOPMENT_RESULT / NOT_REPLICATED / FORMAL_WEIGHT_NOT_ADOPTED
GROWTH-TREND-ANALYSIS-01 = PR62_MERGED / MEETING_DELTA_LAG_1 / BYTE_EXACT_36_ARTIFACTS / DEVELOPMENT_ONLY
GROWTH-TREND-ADJUSTMENT-CALIBRATION-01 = PR63_MERGED / COMPLETED_NEGATIVE_DEVELOPMENT_TRANSFER / GLOBAL_LINEAR_WEIGHT_NOT_ADOPTED / k=34 / w=+0.34 / SCALE_P99=3.03
STAT-35-DATA-READINESS-AUDIT-01 = COMPLETED_PR64_MERGED / BLOCKED_INSUFFICIENT_RAW_HISTORY / IDENTITY_SAFE / NO_AS_OF_HISTORY / TWO_REPRODUCTIONS_BYTE_EXACT_27_ARTIFACTS
PR #65 = MERGED / main adb847c5b0a2b0778ecb02a57c37bffbecf2d926
PR #66 = MERGED / migration-phase main 2715757a6952dbf32fc97f881945d94721a08282
PR #67 = MERGED / pilot-phase main 15bb52de18eb5908a01d181d8177f33c0b1ca583
PR #68 = MERGED / backfill-phase main 88ac8ff4677dde5c53a40ef028e5d83923671e07 / PILOT_REVIEW_COMPLETED
PR #69 = MERGED / context-v1-phase main 3d03273ab8aee5a23e8fd317e98a2dc7bad8e9e6 / BACKFILL_RESULT_REVIEW_COMPLETED
PR #70 = MERGED / context-v2-phase main 24a217e2fdfd21c7df490c55a08cb34760031e80 / TWO_REVIEW_FIXES_COMPLETED
PR #71 = MERGED / race-relative-phase main 7f2a59d15af13785d36471f9f86c1dfa03f1222b / TRACK_CONTEXT_V2_REVIEW_COMPLETED
STAT-35-STORAGE-BACKFILL-01 = IMPLEMENTATION_REVIEW_MERGE_COMPLETED / PRODUCTION_SCHEMA_APPLIED_AND_VERIFIED
STAT-35-PRODUCTION-PREFLIGHT-01 = COMPLETED_PR66_MERGED / HISTORICAL_READ_ONLY_PREFLIGHT_RECORD_PRESERVED
STAT-35-PRODUCTION-MIGRATION-01 = APPLIED_AND_SCHEMA_VERIFIED_REVIEW_COMPLETED_PR67_MERGED / MIGRATION_BATCH_14
STAT-35-PRODUCTION-BACKFILL-DRYRUN-01 = COMPLETED_2024_12_31_ONLY / 75_IMPORTS / PLANNED_490_OBSERVATIONS_490_CURRENT_UPDATES
STAT-35-PRODUCTION-BACKFILL-PILOT-01 = REVIEW_COMPLETED_PR68_MERGED / 2024_12_31_ONLY / BATCH_RUN_120_SUCCEEDED / END_SNAPSHOT_UNCHANGED
Pilot saved counts = 490_OBSERVATIONS / 490_CURRENT_RESULTS / VALID_477_MISSING_13 / NON_AGARI_CHANGES_0
Pilot post-save READ ONLY dry-run = SUCCESS_75_FAILED_0 / OBSERVATIONS_0_CURRENT_UPDATES_0 / BATCH_RUN_ID_NULL / NOT_RERUN
STAT-35-PRODUCTION-BACKFILL-2022-2025-01 = REVIEW_COMPLETED_PR69_MERGED / EXCLUDES_2024_12_31 / NO_RERUN
Executed intervals = 48 / unstarted = 0 / BatchRun 121-168 SUCCEEDED
Prior backfill-phase summary = SUCCESS_126933_SKIPPED_113_FAILED_0 / NO_IMPORT_29_NO_IMPORT_UNSUPPORTED_0
Prior backfill-phase actual DB delta = 899506_OBSERVATIONS / 716347_CURRENT_RESULTS / NON_AGARI_CHANGES_0
New observation status = VALID_879184_MISSING_20319_INVALID_FORMAT_0_OBSERVED_ABNORMAL_RESULT_3
Filled current status = VALID_700405_MISSING_15939_INVALID_FORMAT_0_OBSERVED_ABNORMAL_RESULT_3
Combined with pilot exactly once = 899996_OBSERVATIONS / 716837_CURRENT_RESULTS
Post-save READ ONLY dry-run = ZERO_PLANNED_CHANGES_ALL_EXECUTED_INTERVALS / FAILED_0 / BATCH_RUN_ID_NULL
Production write in prior backfill phase = USER_AUTHORIZED_2022_2025_EXCLUDING_PILOT_OBSERVATIONS_CURRENT_AGARI_AND_BATCH_AUDIT
STAT-35-37-TRACK-CONTEXT-01 = REVIEW_COMPLETED_PR70_MERGED
Historical v1 master = 42_TRACKS_44_OBSERVATION_LAYOUTS / ALL_FILES_AND_RESOLVED_EVIDENCE_UNCHANGED
Historical v1 coverage = RESOLVED_3_UNKNOWN_LAYOUT_10657_CONFLICT_0 / TARGET_TRACK_DAYS_10660
STAT-35-37-TRACK-CONTEXT-02 = REVIEW_COMPLETED_PR71_MERGED
Track context v2 master = 42_TRACKS_89_OBSERVATION_LAYOUTS / EXPLICIT_VERSION_NO_FALLBACK
Track context v2 review = ALL_42_ENTRYPOINTS_ATTEMPTED_37_NAVIGATIONS_CHECKED_5_UNAVAILABLE
Track context v2 coverage = RESOLVED_14_DISTANCE_RESOLVED_14_UNKNOWN_LAYOUT_10646_CONFLICT_0_REGRESSED_0 / SAME_10660_TRACK_DAYS
Track context v2 gain = MAEBASHI_4_HIRATSUKA_7 / SEIBUEN_3_UNCHANGED
Track context source gaps = HISTORICAL_INTERVALS_10646_DAYS_AND_OPTIONAL_FIELDS_UNCONFIRMED / SCR_OVERALL_NOT_COMPLETED
Track context production writes = 0 / NO_AGARI_REPROCESSING / NO_PREDICTION_EVALUATION
Track context prediction use = NOT_AUTHORIZED / historical_as_of_available=false
PR #72 = MERGED_REVIEW_COMPLETED / merge-point main baac9c113d8a61061d7e5f8bd2b7cd391d091c54
STAT-35-RACE-RELATIVE-01 = DESCRIPTIVE_DATASET_GENERATED_REPRODUCED_REVIEW_COMPLETED_PR72_MERGED
Race relative fixes = FIELDWISE_POSTGRES_GUARD_SAFE_DIAGNOSTICS / CURRENT_RESULT_RACE_ID / EMPTY_TEN_COUNTERS
Race relative historical tests = CODEX_2068_PASSED_9_SKIP_1_FAILURE / LATER_USER_2064_PASSED_9_SKIP_5_FAILURES / BOTH_17382_ASSERTIONS / PRIOR_DIRECT_PHPUNIT_2096_PASS_9_SKIP_17268_PARENT_ASSERTIONS / LATEST_USER_ARTISAN_1_FAILURE_PEAK_135266304 / RECORDS_PRESERVED
Race relative current tests = ISOLATED_MEMORY_CHECKS_AND_ARTISAN_FULL_SUITE_PASS / ISOLATED_CASES_16 / ARTISAN_FULL_2111_PASSED_9_EXISTING_SKIPPED_0_FAILURES_0_ERRORS_17379_PARENT_ASSERTIONS / DIRECT_CHILDREN_451_ASSERTIONS / HIGH_PARENT_CHILDREN_SEPARATE_451_ASSERTIONS
Race relative checks = LIMITED_PINT_AND_12_CHANGED_PHP_LINT_PASSED / 42_HELPER_REGRESSIONS_PASS / NO_NEW_SKIP_OR_THRESHOLD_RELAXATION
Race relative prior export = ONE_EXIT_1_ACTUAL_MISMATCH_UNCONFIRMED / OLD_EVIDENCE_PRESERVED
Race relative review-fix export = ONE_AUTHORIZED_EXIT_0 / ENDPOINT_VERIFIED_SESSION_AND_TRANSACTION_RO_ON_REPEATABLE_READ / BUSINESS_READS_YES_WRITES_0
Race relative real counts = RACES_101326_CURRENT_RESULTS_716837 / COMPLETE_95712_PARTIAL_5475_UNUSABLE_139 / RELATIVE_700869_SPEED_1079
Race relative build / reproduction = EXIT_0_BOTH / FIVE_FILES_BYTE_AND_SHA256_EXACT / INPUT_DETAIL_AND_YEAR_GRADE_COUNTS_MATCH
Race relative purpose = FINAL_RESULT_DESCRIPTIVE_ONLY / historical_as_of_available=false / prediction_use=NOT_AUTHORIZED / points=null
STAT-35-PLAYER-HISTORY-01 = DESCRIPTIVE_PLAYER_HISTORY_GENERATED_REPRODUCED_PRIOR_RECORD_PRESERVED
PR #73 = MERGED / HEAD_3a18de5bb08dd7f83cd8e35b176ce58dc7e49893 / 2026-09-27T21:33:48Z
PR #73 artifact acceptance = 判定記録未確認 / NOT_A_REVERSAL_OF_IMPLEMENTATION_OR_GENERATION
Player history code = PR73_IDENTITY_SCOPE_FIX_MERGED / MEETING_CONTEXT_ROW_ADOPTION_AND_TARGET_IDENTITY_SEPARATED
Player history = 101326_RACES_716837_RESULTS / 2436_PLAYERS / 241464_PLAYER_MEETING_CLASS_GROUPS / 235725_NUMERICAL_GROUPS
Player history full valid windows = N3_640112_N6_573435_N12_457715_TARGET_RESULT_ROWS
Player history trend = CALCULATED_573435_NULL_143402 / UNKNOWN_CLASS_2215_ROWS / NO_IDENTITY_CONFLICT_OR_UNRESOLVED_EXTERNAL
Player history reproduction = SIX_FILES_BYTE_AND_SHA256_EXACT / OLD_FOUR_DATA_FILES_BYTE_AND_SHA256_UNCHANGED / INDEPENDENT_COUNTS_AND_TEMPORAL_SELECTION_VERIFIED
Player history prior tests = ARTISAN_2152_PASSED_9_EXISTING_SKIPPED_18129_PARENT_ASSERTIONS / PRIOR_RECORDED_TOTALS_PRESERVED / CURRENT_TESTS_IN_15.47
Player history purpose = FINAL_RESULT_DESCRIPTIVE_ONLY / EVENT_DATE_BACKFILLED_FINAL_RESULTS / historical_as_of_available=false / prediction_use=NOT_AUTHORIZED / points=null
Next allowed action = C1_STAT10_ABLATION_01_RESULT_REVIEW_ONLY
C1 final fit = FIXED_LAMBDA_0.1_AND_EXISTING_COEFFICIENTS_BINS / NOT_REOPENED / NOT_AN_OUTER_2024_2025_MODEL
Further production writes / further implementation = NOT_AUTHORIZED
Backup = CUSTOM_DUMP_AND_ARCHIVE_LIST_SUCCEEDED / RESTORE_TEST_NOT_PERFORMED
Memory = INDEPENDENT_BOUNDED_TEST_128M / PRODUCTION_EXAMPLE_512M_ADJUST_BY_MEASUREMENT

Next:
Review C1-STAT10-ABLATION-01 code and completed development comparison only. One execute completed two independent candidate fits/predictions/evaluations; 76 enumerated semantic files match and source/code integrity passed. Incremental Gate NOT_PASSED because non-inferiority, temporal and superiority conditions were unmet: retain C1 and do not adopt this fixed 15-feature model. STAT01 auxiliary PASS is not incremental approval. Do not conclude permanent STAT10 rejection. No same-condition retries, other-STAT ablation, old-negative retries, C1 retraining, formula/grid/threshold changes, DB/HTTP/Raw/2026, formal replacement or LIVE. Preserve unresolved score-gap P3; see 15.58 and docs/c1-stat10-ablation-01.md. Stop uncommitted for review.

Previous handoff (v1.48, PR85 merged; score gap results/artifacts unchanged and P3 unresolved):
Review STAT-01-C1-SCORE-GAP-01 code and completed development comparison only. One execute completed two independent fits/predictions/evaluations; 76 semantic files match and source/code integrity passed. Incremental Gate NOT_PASSED because non-inferiority, temporal and superiority conditions were unmet: retain C1 and do not adopt this fixed addition. STAT01 auxiliary Gate also FAIL / REDESIGN_REQUIRED. No same-condition retries, S/mean6/D retries, C1 retraining, formula/grid/threshold search, DB/HTTP/Raw, 2026, formal adoption or LIVE. See 15.57 and docs/stat01-c1-score-gap-01.md; stop for review without an automatic next phase.

Previous handoff (v1.47, PR84 now reviewed/merged; D result and artifacts unchanged):
Review STAT-17-C1-COMPARE-01 code and completed development comparison only. One execute completed two independent fits/predictions/evaluations; 76 semantic files match and source/code integrity passed. Incremental Gate NOT_PASSED because Hit@3 delta CI lower is not positive: retain C1 and do not adopt this fixed addition. STAT01 auxiliary PASS is not incremental approval. No same-condition retries, S/mean6 retries, C1 retraining, additional formula/grid/threshold search, DB/HTTP/Raw, 2026, formal adoption or LIVE. See 15.56 and docs/stat17-c1-compare-01.md; stop for review without an automatic next phase.

Previous handoff (v1.46, PR83 now reviewed/merged; result and artifacts unchanged):
Review STAT-36-C1-COMPARE-01 code and completed performance/reproduction evidence only. One execute completed two independent C1_PLUS_S fits/evaluations; 76 semantic files match. Incremental Gate NOT_PASSED: retain C1 and do not adopt this S addition. The auxiliary STAT01 Gate PASS is not incremental approval. Preserve original candidate restrictions/general Bundle refusal, old C1/C2/pilot artifacts and closed 2026. S timing remains UNKNOWN/historical_as_of_available=false. No automatic further execution, training, S regeneration, DB/HTTP/Raw, LIVE or formal adoption. See 15.55 and docs/stat36-c1-compare-01.md.

Previous handoff (v1.44, PR81 now reviewed/merged; historical source and generation records unchanged):
Review STAT-36-START-COUNT-01 code and saved display-count evidence only. 901,038 rows cover 101,326 fixed races / 717,709 unique entries; 14 artifacts and the manifest match in an independent offline reproduction. Only the completed READ ONLY source bundle was used; one precheck failure and one aborted partial export remain separate evidence. All S-specific aggregation timing is UNKNOWN. Preserve C1, C2 incremental Gate NOT_PASSED, old observation v1 records, page v3/signature v2 and the closed 2026 holdout. No prediction use, inferred start events, further execution or automatic next phase. See docs/stat36-start-count-01.md.

Previous handoff (v1.43, PR80 now merged; historical execution unchanged):
Review PR80 signature-v2 code and synthetic regressions only; v2 real-data regeneration was not run and the impact on prior real counts was not assessed. The following build/reproduction facts are historical v1 records. Review STAT-36-OBSERVATION-01 code and saved display evidence only. One build and one independent Raw reproduction preserve all accepted 2022-2025 import versions. Generation success does not establish start semantics: all start_acquired values remain null, and initial position is unavailable. No prediction use, inferred positions, scoring or next-phase execution is authorized. Preserve C1, C2 incremental Gate NOT_PASSED, old artifacts and the closed 2026 holdout. See docs/stat36-observation-01.md.

Previous handoff (v1.42, diagnostic completed; PR79 reviewed and merged):
Review STAT-35-C1-DIAGNOSTIC-01 code and results only. One build and one independent reproduction retained all 50,078 races/356,209 entries; 14 files and manifest match, saved utilities are exact and prior contributions/unrounded rates agree. This is post-hoc descriptive diagnosis, not a new independent evaluation or causal explanation. Preserve C1, the C2-C1 NOT_PASSED decision, historical_as_of_available=false and the closed 2026 holdout. No additional execution, DB/HTTP/Raw, retraining, predictions, new CI/Gate or automatic next phase. See docs/stat35-c1-diagnostic-01.md.

Previous handoff (v1.41, comparison completed; PR78 reviewed and merged):
Review STAT-35-C1-COMPARE-01 code and results only. C2 initial and independent training/prediction/evaluation completed; 76 files per run match and source/code integrity passed. The C2-C1 incremental Gate did not pass because the Hit@3 delta CI lower bound is not positive; the auxiliary STAT01 Gate passed, which is not adoption authorization. Preserve saved Outer C1, old artifacts and historical_as_of_available=false. No additional trials, DB/HTTP/Raw, input regeneration, C1 retraining, final model replacement or 2026/LIVE. See docs/stat35-c1-compare-01.md; stop for review without an automatic next phase.

Previous handoff (v1.40, input preparation completed; PR77 merged and fixed comparison now authorized only in 15.50):
Review STAT-35-C1-INPUT-02 generated inputs only. The fixed 248-byte candidate pin is registered; synthetic/full tests and one offline build/independent reproduction completed. All 706,051 entries remain: connected 705,048, numeric 685,719, NULL 20,332. Missing context matches the prior 1,003 UNKNOWN_RACE_CLASS holds by year/race/entry/bike. C1 values/types/order are unchanged; fourteen files and manifest match. Preserve all old artifacts and calculation contracts, historical_as_of_available=false, C1 and 2026 freeze. Input preparation is not performance improvement. No database/HTTP/context regeneration/training/prediction/evaluation. See docs/stat35-c1-input-02.md; no automatic next phase.

Previous handoff (v1.39, historical context-only scope; fixed candidate acceptance and one input build/reproduction now authorized only in 15.49):
Review STAT-35-C1-CONTEXT-01 mapping evidence and UNKNOWN class handling only. Single scoped READ ONLY extraction, offline build and independent reproduction completed: 99,669 races/706,051 entries; all saved identities/meetings matched, 705,048 candidates, 1,003 held for UNKNOWN_RACE_CLASS. Seven files and manifest match, source/code end integrity verified. Keep REVIEW_PENDING, historical_as_of_available=false and the empty reviewedContextPins. No re-extraction, mean6 generation, training, evaluation, production writes or 2026 access is authorized. Preserve the old all-NULL diagnostic as a prior record. See docs/stat35-c1-context-01.md; stop for review without an automatic next phase.

Previous handoff (v1.38, historical input-only scope; superseded only by the explicitly authorized context extraction):
Review STAT-35-C1-INPUT-01 code and its missing target-identity/context evidence. Input preparation alone was authorized after PR74 merge. The standalone implementation and tests passed; generation and reproduction each ran once, preserving 99,669 races/706,051 entries and all C1 non-outcome values/order. All added values are null because a saved result-independent observed external-player-ID/meeting/class mapping was not established. This is a sealed DIAGNOSTIC_ALL_NULL, not usable-input success, model training or performance failure. Ask for the concrete saved evidence or separately scoped acquisition authorization; do not use target results or internal IDs alone as fallback. Preserve old C1/STAT35 artifacts, historical_as_of_available=false, all existing holdout and model restrictions. No automatic next phase.

Previous handoff (v1.37, historical docs-only authorization; input-preparation scope superseded by the Next paragraph above):
Review docs/stat35-next-use-design-01.md and its scope tables only. The current user's instruction authorizes this docs-only draft after PR #73 merge, not implementation, training, prediction evaluation or production access. Resolve D1-D7: development-only disclosure, outcome-free identity/meeting/class mapping to the fixed C1 entrant universe, proposed mean6 subset, numeric/missing conversion, rolling past-history versus evaluation-label access, C1 paired comparison/Gate and separate execution acceptance. Preserve the fixed final C1 and old Outer outputs, STAT35 descriptive artifacts, unknown historical publication timing, old pilot/E08 decisions and 2026 freeze. Prior successful execution/test records are evidence citations, not checks run in this phase. Await document review without automatic next-stage execution.

Previous handoff (v1.36 PR73 review fix before merge, historical record superseded by the Next paragraph above):
Review PR #73's identity-scope fix only (REVIEW_PR73_IDENTITY_SCOPE_FIX_AND_PLAYER_HISTORY_RESULT). PR #73 remains unmerged and awaits re-review, not approval. Existing branch feature/stat35-player-history-01, start/end HEAD 4e95ddb1009feb5cf14fc22035d67fefe9979c65; remote main stays baac9c113d8a61061d7e5f8bd2b7cd391d091c54. Meetings now retains identified-row means in mixed groups; unconfirmed-only groups cannot establish attendance, while identified missing times remain null slots. Builder blocks only unidentified target rows and counts their actual emitted state. Genuine context/boundary/overlap rules are unchanged. Eight regression cases added; old-code minimal fixture failed as expected. Final focused 41 passes/734 assertions; ordinary Artisan once: 2,152 passes, nine existing skips, zero failures/errors, 18,129 parent assertions. All 17 independent 128M and 17 high-parent cases passed. New fixed-input build/reproduction once each: exit0, 178.394141/175.937277 seconds, each peak33,554,432 bytes. Streaming comparisons verified old four data files unchanged and new six artifacts byte/SHA-exact, independent counts and all historical end dates. Actual counts remain 101,326 races/716,837 rows, 2,436 external IDs/241,464 groups/235,725 numerical groups, trend573,435/null143,402. New manifest/COMPLETE hashes record only Meetings/Builder code changes, not copied old hashes. Evidence: /home/shinya/neo-keirin-artifacts/stat35-player-history-01/pr73-review-fix-20260927-210230-b2d48a1f/. Preserve old records, FINAL_RESULT_DESCRIPTIVE_ONLY, EVENT_DATE_BACKFILLED_FINAL_RESULTS, historical_as_of_available=false, prediction_use=NOT_AUTHORIZED, points=null, C1, 2026 freeze and incomplete STAT-35/37. No production DB, upstream rebuild/export, Raw/HTTP, migration/backfill, training/evaluation or 2026 race access. Additional changes remain uncommitted for re-review; no automatic next phase.

Previous handoff (v1.36 initial generation, superseded by the Next paragraph above):
Review STAT-35-PLAYER-HISTORY-01 only (REVIEW_STAT35_PLAYER_HISTORY_RESULT). PR #72 is merged and reviewed. New branch feature/stat35-player-history-01 starts at baac9c113d8a61061d7e5f8bd2b7cd391d091c54. Fixed input/results were verified and read without another export or old relative build. All 101,326 races and 716,837 result rows are retained; 2,436 identified players, 241,464 player-meeting-class groups, 235,725 numerical groups, 573,435 trend rows. Preserve UNKNOWN class, null slots, boundary and ambiguous-order reasons. Six artifacts reproduced byte/SHA-exact; independent counts and historical end-date checks passed. Both 512M builds exited 0 with peak 33,554,432 bytes. Normal php artisan test: 2,144 passes, nine existing PostgreSQL-only skips, zero failures/errors, 17,677 parent assertions. Existing 16 plus one new independent 128M test and all high-parent cases passed; new case processes 108,000 rows at peak 48,758,784 bytes. Evidence: stat35-player-history-01/run-20260927-072445-4FJxV4. No production DB, old real generation, Raw/HTTP, Migration/backfill, learning, prediction evaluation or 2026 race access. Maintain FINAL_RESULT_DESCRIPTIVE_ONLY, EVENT_DATE_BACKFILLED_FINAL_RESULTS, historical_as_of_available=false, prediction_use=NOT_AUTHORIZED, points=null, C1 and old experiment decisions. Windows are descriptive candidates, not optimized features. STAT-35/37 and structure/weather/line/opponent adjustment remain incomplete. Changes are uncommitted for review. No automatic next phase.

Previous handoff (v1.35 historical record, superseded by the Next paragraph above):
Review PR #72's hermetic memory completion (REVIEW_PR72_HERMETIC_MEMORY_COMPLETION). PR #72 remains unmerged and unapproved. Preserve the past successful single export/build/offline reproduction in stat35-race-relative-01/pr72-review-fix-20260923-185418-dbdce2: 101,326 races / 716,837 current rows, complete/partial/unusable 95,712/5,475/139, relative/speed 700,869/1,079, five byte/SHA-identical files. They were not reread, rehashed or rerun. Keep distinct historical Codex one-failure, user five-failure, prior direct-PHPUnit success and latest user Artisan one-failure records. This completion isolated eleven remaining lifetime-peak checks, for sixteen actual-128M children, preserving fixtures/assertions and strict bounds. Normal php artisan test ran once: 2,111 passes, nine existing PostgreSQL-only skips, zero failures/errors, 17,379 parent assertions, exit 0. Direct children: 16 cases / 451 assertions, peaks 23,068,672 to 44,564,480 bytes. Separate high-parent regressions: all 16 passed from parent peak 146,804,736 bytes, another 451 child assertions (not added to parent counts). All 42 helper regressions passed. Limited Pint and 12 changed PHP syntax checks passed. New evidence: stat35-race-relative-01/pr72-memory-completion-20260926-213318-6d625ace; full method/PID/peak inventory in docs/stat35-race-relative-01.md. No production code/data change, production connection, real export/build/reproduce, Raw/HTTP/backup access, Migration/backfill, training, prediction evaluation or 2026 real-data access. Preserve FINAL_RESULT_DESCRIPTIVE_ONLY, historical_as_of_available=false, prediction_use=NOT_AUTHORIZED, points=null, PUBLICATION_TIME_UNKNOWN, primary INSUFFICIENT_RAW_HISTORY, secondary RAW_GAP_POLICY, Growth negative transfer, C1, Goal 4/5 blocked and 2026 FROZEN_FOR_MODEL_SELECTION. STAT-35/37 overall and historical layout coverage remain incomplete. Stop uncommitted for review; no further execution or predictive phase is authorized.

Do not:
redo BT-02 discovery
restart old BT-03D
rerun BT-03 run6
rewrite the audited BT-03E-02 result
rerun BT-03E-08 with the same hypothesis
rerun DATA-AUDIT-01
infer missing tactical input definitions or use unknown current-profile values as historical racecard inputs
rerun BT-03E-01 with the same hypothesis
adopt base_step=30 / STAT23=5 / STAT31=5 as final points
open 2026
modify scraping
treat 2024 or 2025 as untouched final holdout

Objective:
maximize actual 1st / 2nd / 3rd prediction accuracy
under leakage-safe, auditable, reproducible constraints
```
