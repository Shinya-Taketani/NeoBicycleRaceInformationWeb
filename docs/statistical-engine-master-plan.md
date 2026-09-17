# STATISTICAL_ENGINE_MASTER_PLAN

- Document: 統計エンジン開発工程マスター
- Version: 1.12
- Created: 2026-08-23
- Updated: 2026-09-17
- Repository: `Shinya-Taketani/NeoBicycleRaceInformationWeb`
- Intended repository path: `docs/statistical-engine-master-plan.md`
- Remote `main` at creation: `82d394ec014b46ca4792858fbe9fe35eaa7434d5`
- Remote `main` at last update: `5071339125a5423cc37327953512634c0910cf42`
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
current_engine_state: TACTICAL-HISTORY-FINAL-01_REPRODUCED_AWAITING_REVIEW
current_scoring_hypothesis_status: BT-03E-08_REJECTED_FOR_ADOPTION
next_allowed_action: REVIEW_FINAL_C1_ARTIFACT_AND_WAIT_FOR_USER_INSTRUCTION
next_implementation_phase: NOT_AUTHORIZED
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

superseded_phases:
  - BT-03D-PREDICTIVE-SELECTION
  - BT-03E-01-COARSE-INTEGER-SCORING-RULE

blocked_phases:
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
| TACTICAL-HISTORY-FINAL-01 | 評価済みC1の最終development fit・保存モデル読込 | FINAL_FIT_REPRODUCED_AWAITING_REVIEW | USER_REVIEW_REQUIRED / NO_NEW_ACCURACY_EVALUATION |
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

Production相当処理は `php -d memory_limit=128M` で完走可能なbounded-memory / streamingを必須とする。pair全件を巨大arrayへmaterializeせず、raceを読み、当該raceのpairでloss / gradientを更新し、race payloadを破棄する。bootstrapもrace payloadを不要に全複製しない。

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
状態は `FINAL_FIT_REPRODUCED_AWAITING_REVIEW`。数値モデル候補の固定であり、追加の精度改善・最終採用・LIVE開始・2026利用許可を意味しない。
`BACKFILLED_FINAL_RESULT / DEVELOPMENT_ONLY`、過去公開時刻UNKNOWNを維持する。詳細・コマンド・検証結果は `docs/tactical-history-final-01.md`。

---

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

Production系は原則:

```text
php -d memory_limit=128M
```

で完走可能なbounded / streaming構造を維持する。

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
| Goal 3 score / parameter決定 | NOT_COMPLETED / DEVELOPMENT_GATE_PASSED | TACTICAL-HISTORY-01 v2はC0/C1の学習・比較・再現性確認と成果物レビューを完了し、開発評価Gateを通過。FINAL-01で最終C1候補のfit・保存読込・再現性も完了したが、最終候補のレビューと正式freezeは未完了。E08の不採用と旧TACTICAL-PILOT-01の保留を維持 |
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
TACTICAL-HISTORY-FINAL-01 = FINAL_FIT_REPRODUCED_AWAITING_REVIEW

Next:
Review the final C1 model candidate and wait for user instruction; keep the completed PR55 comparison, PJ0315 pilot block, and v1 failure evidence. Do not automatically adopt, deploy, or open 2026.

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
