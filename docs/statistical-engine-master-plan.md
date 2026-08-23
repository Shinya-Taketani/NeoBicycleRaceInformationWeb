# STATISTICAL_ENGINE_MASTER_PLAN

- Document: 統計エンジン開発工程マスター
- Version: 1.1
- Created: 2026-08-23
- Repository: `Shinya-Taketani/NeoBicycleRaceInformationWeb`
- Intended repository path: `docs/statistical-engine-master-plan.md`
- Remote `main` at creation: `82d394ec014b46ca4792858fbe9fe35eaa7434d5`
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
- threshold / points / score仕様の提案
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

**1着・2着・3着を最大限正確に予測できる加点数・減点数を決定する。**

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
current_engine_state: BT-03E-01_COMPLETED
current_scoring_hypothesis_status: REJECTED_FOR_ADOPTION
next_allowed_action: BT-03E-02_DESIGN_ONLY
next_implementation_phase: NOT_FROZEN

completed_phases:
- STATISTICS_FEATURE_FOUNDATION_CURRENT_SCOPE
- BT-01
- BT-02
- BT-03A
- BT-03B
- BT-03C
- BT-03E-01_ENGINEERING

superseded_phases:
- BT-03D-PREDICTIVE-SELECTION

blocked_phases:
- BT-03E-02_IMPLEMENTATION
- BT-04
- BT-05-LIVE

frozen_contracts:
- LEAKAGE
- SOURCE_INTEGRITY
- MISSING_STATUS_SEMANTICS
- ABNORMAL_RESULT_HANDLING
- BOUNDED_MEMORY
- READ_ONLY_BACKTEST
- ARTIFACT_INTEGRITY

unfrozen_contracts:
- FINAL_STAT_SELECTION
- FINAL_POINTS
- FINAL_THRESHOLDS
- FINAL_SCORE_FORMULA
- FINAL_OPTIMIZATION_ALGORITHM
- FINAL_ACCEPTANCE_THRESHOLD

holdout_status:
  development_corpus: 2022-2025
  final_holdout: 2026
  final_holdout_model_selection_access: FORBIDDEN
  final_holdout_performance_evaluation: FORBIDDEN
```

## 5.1 意味

- BT-03E-01の **historical-forward scoring基盤実装自体は完成** している。
- ただしBT-03E-01で試した粗い加点方式は2024でSTAT-01 baselineを下回ったため、**最終配点としては採用しない**。
- 次に許可されるのは **BT-03E-02の設計** であり、仕様をfreezeする前にCodexへ実装させない。
- 2025を「次のholdout」としてそのまま開いて評価する工程には進まない。
- 2026は最終モデル選択・配点・score仕様がfreezeされるまで評価禁止。

---

# 6. STAT要件全体と現在の実装範囲

統計エンジン要件定義はSTAT-01～STAT-46を管理対象としている。

配点・閾値・減衰率は原則としてバックテスト後に決定する契約であり、確定前のpointsは `NULL` / `NOT_SCORED` が原則である。

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

**統計エンジンのモデル選択・配点決定・予測性能評価には使用しない。**

禁止対象:

- 2026 result labelを用いた性能評価
- 2026 outcomeを見たthreshold決定
- 2026 outcomeを見たpoints調整
- 2026 outcomeを見たSTAT採否
- 2026 outcomeを見たscore formula変更

現時点:

```yaml
2026_model_selection_access: FORBIDDEN
2026_performance_evaluation: FORBIDDEN
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
| BT-03E-02 | scoring方式再設計 | READY_FOR_DESIGN | IMPLEMENTATION BLOCKED |
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

# 15. 次工程 — BT-03E-02 Scoring Redesign

## 15.1 状態

```yaml
working_name: BT-03E-02-SCORING-RULE-REDESIGN
name_status: PROVISIONAL
phase_status: READY_FOR_DESIGN
codex_implementation: BLOCKED_UNTIL_SPEC_APPROVAL
```

## 15.2 次に許可される作業

**設計のみ。**

以下をChatGPT上で先に決める。

1. score表現
2. bin別pointsをどこまで独立させるか
3. `IS_WIN / IS_TOP2 / IS_TOP3` の情報をどう統合するか
4. STAT単位weightを残すか
5. 非線形STATをどう扱うか
6. redundancy / correlationをどう制御するか
7. optimization objective
8. optimization algorithm
9. overfitting抑制
10. tie削減
11. development validation設計
12. Goal 3 acceptance gate

これをfreezeする前にCodexへ実装させない。

## 15.3 設計上の重点候補

### A. 3label共通方向への過度な圧縮を見直す

BT-03E-01では、

```text
IS_WIN
IS_TOP2
IS_TOP3
```

がすべて同方向でないbinを0へ寄せた。

これにより、有効な順位境界固有signalを捨てた可能性がある。

次工程では、

- winner contribution
- top2 contribution
- top3 contribution

を分離保持する方式を比較対象とする。

### B. STAT単一weightだけでなくbin固有強度を検討

BT-03でbinごとの効果量差が明確だったため、

「STAT全体で1 weight」

だけでは情報損失が大きい可能性がある。

ただし全bin独立pointの無制限探索は過学習・組合せ爆発を起こすため禁止。

候補:

- effect magnitudeを段階化
- monotonic / shape constraint
- grouped point levels
- regularized bin points
- hierarchical STAT weight × bin multiplier
- shrinkage

### C. STAT-31

非線形専用扱いを検討する。

### D. STAT-26

bin indexではなくraw-value semantic alignmentを維持する。

### E. STAT-39

cohort差を無視しない。

### F. Tie

BT-03E-01ではPoint Engine tie raceが3052まで増加した。

point resolution / score representationを改善し、

**大量tieを最終性能のボトルネックにしない。**

## 15.4 correlation / redundancy

独立工程として再選択はしないが、

scoring optimizer内で次を診断してよい。

- 高相関STATの二重加点
- 同じ情報を含むbin ruleの重複
- leave-one-STAT-out
- group ablation
- point shrinkage

「相関が高い」という理由だけでSTATを削除しない。

実際に除外して順位性能が悪化するなら有用とみなす。

## 15.5 Development evaluation

2022～2025はdevelopment corpusとして扱う。

次工程では、必要に応じてfold-localなnested temporal validationを使用する。

重要:

**2024 / 2025をfinal untouched holdoutとは呼ばない。**

## 15.6 Acceptance gate

現時点では具体的な改善閾値を未freezeとする。

BT-03E-02実装前に、最低限次をpre-registerする。

Primary:

- `WINNER_HIT_AT_1`
- `POSITION_2_ACCURACY`
- `POSITION_3_ACCURACY`
- `POSITION_HIT_RATE_AT_3`

Supporting:

- `EXACT_ORDERED_TOP3_RATE`
- `EXACT_TOP3_SET_RATE`
- `TOP3_COVERAGE_AT_3`
- `EXACT_TOP2_SET_RATE`
- `TOP2_COVERAGE_AT_2`
- `NDCG_AT_3`

次工程で、

**1着だけを上げ、2着・3着を大きく悪化させるcandidateを自動採用しない。**

複数metricのPareto比較を許可する。

---

# 16. BT-04 — Final Frozen Holdout Evaluation

## 16.1 状態

`BLOCKED`

## 16.2 開始条件

以下がすべてfreezeされるまで開始禁止。

- 使用STAT
- feature version
- rule generation
- threshold
- bin semantics
- points
- score formula
- missing handling
- confidence handling
- tie rule
- prediction output
- metric definitions
- optimization終了条件
- model / rule version

## 16.3 2026を開く前の必須記録

最低限:

```yaml
final_engine_version:
final_rule_version:
final_points_manifest_hash:
final_feature_manifest_hash:
final_score_formula_hash:
final_code_main_sha:
freeze_datetime:
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
- outcomeを見て同じevaluation対象のpointsを決定しない
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
- scoring層で利用不能なら原則contribution 0
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

---

# 19. Unfrozen Contracts

現時点で **最終仕様として確定していない**。

- 最終STAT採否
- 最終points
- bin別points
- threshold
- STAT weight
- STAT-01 base scale
- final score formula
- confidenceによるpoints補正
- STAT-20統合方法
- correlation penalty
- optimization algorithm
- exact acceptance threshold
- market overlay（STAT-22）
- final prediction confidence
- bet / ROI rule

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

性能・threshold・point選定につながる確認は禁止。

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

Current remote `main` at this document creation:

```text
82d394ec014b46ca4792858fbe9fe35eaa7434d5
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
| Goal 3 加減点決定 | NOT_COMPLETED | BT-03E-01 coarse ruleはbaseline未満 |
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
- point / threshold / score formulaがfreeze
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

## v1.1 — 2026-08-23

PR #41レビュー反映。

- 正式repository pathを `docs/statistical-engine-master-plan.md` へ統一
- Section 5を機械参照可能なCanonical Control Blockへ更新
- `AGENTS.md` から統計エンジン工程ゲートを常時参照する運用へ統一

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

# 26. リポジトリへ追加する前の同期注意

この初版作成時点で、ユーザーのローカルrepositoryはPR #40 merge後の`main`をまだpullしていない。

このファイルをrepositoryへ追加する前に、

1. 現在の作業ツリーがcleanであることを確認
2. `main`へ移動
3. `git pull --ff-only`
4. `main`がremoteの最新mergeを含むことを確認
5. その後、文書追加用branchを作成
6. `docs/statistical-engine-master-plan.md` として追加

する。

未コミット変更が存在する場合は、勝手にreset / restore / stash / cleanせずSTOPする。

---

# 27. 次回ChatGPT開始時の確認文

統計エンジン作業を再開するとき、ChatGPTは少なくとも次を認識してから回答する。

```text
Current:
BT-03E-01 engineering = COMPLETED
BT-03E-01 coarse points = REJECTED

Do not:
redo BT-02 discovery
restart old BT-03D
rerun BT-03 run6
adopt base_step=30 / STAT23=5 / STAT31=5 as final points
open 2026
treat 2024 or 2025 as untouched final holdout

Next:
BT-03E-02 design only

Objective:
maximize actual 1st / 2nd / 3rd prediction accuracy
under leakage-safe, auditable, reproducible constraints
```
