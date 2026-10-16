# C1-2023-OOF-RECOVERY-01

## 1. 結論

2026-10-17。保存済み2022年学習モデルのlambda=0.1、1から、それぞれ2023年25,197レース・179,007出走の予測確率を新規生成した。C1再学習は0回。別PHPプロセスによる各候補の再生成と、確率・識別・E06 Primaryの独立検証を完了した。

```ini
status=2023_CANDIDATES_GENERATED_PARENT_SELECTION_PENDING
prediction_classification=2023_OUT_OF_TIME_UNSELECTED_CANDIDATE
parent_training_years=2022_ONLY
candidate_lambdas=0.1,1
selected_lambda=NONE
parent_selection_independent_of_2023_outcomes=NOT_ESTABLISHED
final_selected_2023_oof=NOT_ESTABLISHED
h1_u_training_use=PENDING_PARENT_SELECTION_REVIEW
historical_as_of_available=false
formal_adoption=false
prediction_use=NOT_AUTHORIZED
2026_holdout=FROZEN_NO_ACCESS
```

今回の成果は選手別確率の生成・検証であり、的中精度改善・補正モデルの成立・最終OOF親の選定ではない。候補の性能順位、検証損失平均、採否Gateは計算していない。

## 2. Git・工程ゲート

開始時は`design/c1-low-rank-calibration-feasibility-01`、HEAD `7f5b214f581c7a09a7653d5acce7fab3010d1557`、worktree clean。PR #112の差分はコミット済みの成立性報告書1件だった。ローカルmainとorigin/mainがともに指定`f95d26bc8d2bed81e988d5912719749f39541d0e`、同名ブランチ不存在を確認した後、clean mainへ切り替え、`research/c1-2023-oof-recovery-01`を作成した。PR #112のブランチ・コミット・文書は変更しない。fetch・外部PR操作は行わない。

AGENTSを全文確認。MASTER PLANは前工程で全文確認した原本と全ファイルのSHA-256が一致することを再照合し、冒頭・現在地・8工程制御項目を再確認した。全文確認済み原本の同一性確認と、今回の再読範囲を区別する。

| 根拠 | bytes | SHA-256 |
| --- | ---: | --- |
| AGENTS.md | 14227 | `28679d6ae0b79fd700b9f4a29a01fd2419c659f82337e235c112508fd6642642` |
| MASTER PLAN | 449936 | `bcd4cff217ecec1217efa03b57b110ece596760df5c62c5a12541f396113fe6a` |

| 制御項目 | 確認と今回の制限 |
| --- | --- |
| current_engine_state | Section 5.0の`CATEGORY1_MVP_CODE_TESTED_AWAITING_REVIEW`は2026-10-12の保存記録。今回の限定許可・実行状態を本報告へ分離 |
| next_allowed_action | 同節の`REVIEW_CATEGORY1_MVP_CODE_TESTS_AND_LEDGER_ONLY`と後続履歴を確認。最新指示による候補確率生成だけを実施し、次は本報告のレビュー |
| completed_phases | 既存の統計基盤・BT系・C1学習・限定比較の完了を維持。完了済み学習・評価・生成を再実行しない |
| superseded_phases | BT-03D-PREDICTIVE-SELECTION、旧粗整数点数化を復活させない |
| blocked_phases | 旧TACTICAL-PILOT、時点未確認の一般利用、BT-04/BT-05-LIVEを開放しない |
| frozen_contracts | C1 anchor・16特徴・bin/support・係数、Solver/Scorer/Decoder/Evaluator/Gate、欠損・異常結果・時系列・128M・artifact integrityを維持 |
| unfrozen_contracts | 将来統合の未決事項を、既存最終C1のlambda・bin・係数の凍結解除としない。今回も親lambdaは選ばない |
| holdout_status | development 2022〜2025、2026モデル選択・性能評価・outcome参照は禁止 |

MASTER PLAN本体は変更しない。PR #112の成立性報告書は`git show 7f5b214:docs/c1-low-rank-calibration-feasibility-01.md`で参照した。そこにある将来設計・未承認補正学習を今回の実行へ混入させない。

## 3. Phase A: 何が欠けていたか

確認した親root:

```text
BASE=/home/shinya/neo-keirin-artifacts/tactical-history-01-review-fix-20260916-01
INPUT=/home/shinya/neo-keirin-artifacts/stat35-c1-input-02/run-20260930-054458-8739da4b/result
FINAL=/home/shinya/neo-keirin-artifacts/tactical-history-final-01-20260917-01
```

| 保存物 | 実物の確認結果 |
| --- | --- |
| `BASE/inputs-v2/inputs-2023.jsonl` | 25,197レース・179,007出走。入力に既知の`labels/rank/status`を含む |
| `BASE/run-01/C1-inner-A/validation-losses.jsonl` | 25,197行。`race_id,losses`、収束済み候補0.1/1の3順位損失を保存 |
| `BASE/run-01/C1-inner-A/path.json` | 2022学習の収束済みモデル2個、他6候補の非収束診断が残存 |
| `BASE/run-01/C1-inner-A/predictions.jsonl` | 存在しない。inner処理の生成対象に含まれていない |
| `INPUT/c1-2023.jsonl` | 受入済みの結果なしC1入力が存在。確率成果物ではない |
| `FINAL/fit/reuse-oof-1` | 検証損失の再利用・検証資料。2023選手別確率の保存を意味しない |

実コードでは[Trainer::grid](../app/Domain/Keirin/Backtest/Experiments/TacticalHistory/Trainer.php)が`Objective::raceLoss()`を呼び、候補別損失だけを保存する。全選手の周辺確率を生成する`Predictor::predict()`は同クラスの`refit()`から呼ばれる。[Experiment](../app/Domain/Keirin/Backtest/Experiments/TacticalHistory/Experiment.php)が確率を保存する対象は2024/2025 Outerであり、2023の確率保存経路はない。当時の`code-frozen`と現行の当該計算コード23ファイルのhash一致も確認した。

したがって、**確認した正式束での欠落原因は、inner validationの設計が全選手予測分布の生成・保存を行わなかったこと**。損失から確率を逆算していない。今回確認した正常なseal・完了記録から、生成失敗・破損・削除が原因という証拠は得ていない。他の場所で過去に生成・削除されたことまで否定するものではない。

不存在の確認範囲はBASEのrun-01/run-02各102ファイル、FINALのfit配下72ファイルの名前と予測stream14本の先頭年・保存契約、および親exportと指定INPUTである。見つかった予測streamは2024/2025。全媒体を探索していないので、「どこにも2023確率が存在しない」とは断定しない。既存の適格2023確率束はこの確認範囲では見つからなかった。

### 親seal

| ファイル | SHA-256 |
| --- | --- |
| BASE/report-export-manifest.json | `4268b801b74b77cfb3a94b64832ff483e9eb5e3221ee8e75ceb467e5a5cf92e6` |
| BASE/frozen-experiment-contract.json | `5db3b57b08fb8b91dbe6c057b459e518252c454b0a4ff60531c2d671b7ce506a` |
| BASE/run-01/C1-inner-A/path.json | `62d90c8f0ce56177bbadf9e9b0786bf7646a77b464e5dbfccd62f69dab8efdf5` |
| BASE/run-01/C1-inner-A/layout.json | `3860fc5fc3cc8bf817b7f25d155650bdc7df4fcf8d6b3d8498d9be29c23dba74` |
| BASE/run-01/C1-inner-A/training.jsonl | `24f6148def4a70da8daca0ff048e96c93068a50ed03fa5073f7fa4d595a6cd79` |
| BASE/run-01/C1-inner-A/validation-losses.jsonl | `148f31f772a14c9e125880c17c3cebf12af177f6afc03fcd5d369f6d7f95e03e` |
| BASE/inputs-v2/inputs-2023.jsonl | `e55cc5a40ccfdd920498af84d6fb1ebf7364114b7a5b3fb92b88da9579ed0615` |
| INPUT/manifest.json | `7f4356b93f90203dc727dc95d6215a8d8ce3f44869c4441c3981087ff1099c26` |
| FINAL/report-export-manifest.json | `3980707596ca7c36db68647b1084783b59cff47080d1b3c8ef714af66d1cbee4` |

BASEの完了記録は旧形式の`run-01-completion.json`/`run-02-completion.json`。rootの`COMPLETE.json`が元からあるとは記録しない。exportのincluded/omitted両seal、入力の年別子manifest、完了記録、`reproducibility.json`の`VERIFIED_TWO_REAL_FITS`と両runのseal一致を照合した。INPUTのCOMPLETEはmanifestのbytes/SHAと一致した。

## 4. Phase B: 2022モデルの受入

[ModelLoader::restore](../app/Domain/Keirin/Backtest/Experiments/TacticalHistoryFinal/ModelLoader.php)で両モデルを復元し、版・anchor係数1・固定lambda grid・16特徴順・bin/support・136 active係数/16 group/116 edge・中心化制約・全順位の収束条件と診断整合性を検証した。

モデル版は`TACTICAL-HISTORY-SEQUENTIAL-POSITION-v2`、solverは`TACTICAL-HISTORY-CONSTRAINED-EUCLIDEAN-FISTA-v2`。元path内モデル、別candidate診断、run-02の同一pathを照合した。2022入力24,394レース・170,835出走を保存layoutへ割り当てた結果のstream hashが元training.jsonlと完全一致し、各binのtraining support、順位別適格件数も一致した。binや係数の再学習・再最適化は行わない。

| lambda | 1/2/3着accepted updates | 1/2/3着適格レース | 状態 |
| --- | --- | --- | --- |
| 0.1 | 132 / 85 / 102 | 24339 / 24280 / 24200 | 全順位CONVERGED |
| 1 | 67 / 38 / 49 | 24339 / 24280 / 24200 | 全順位CONVERGED |

順位別除外は55/114/194。数値閾値・200更新上限・lambda gridは変更していない。

新rootの`models/lambda-*/model.json`は、元pathのモデルを値・配列順・型を変えず独立JSONへ直列化したもの。元から同名ファイルがあったと扱わず、親pathのsealとmodel key、semantic hashを`provenance.json`へ記録する。

| 抽出モデル | bytes | SHA-256 |
| --- | ---: | --- |
| lambda-0.1/model.json | 80407 | `1b1d62eb9eac8c87480aa9f1cf92bb5525d4f99a793f2c9be794a8038e696dcb` |
| lambda-1/model.json | 75451 | `ff82e05c6a4f0fb7e551de01c4bc984dde263ba9d868af4f9244d1f1192bc10b` |

2023損失で選択済みのOuter lambda、Inner B、2022〜2025最終fitは使用しない。候補2個とも適格なので両方を生成対象とし、優劣で絞り込まない。

## 5. Phase C: 入力と結果の分離

既存[SourceProjector](../app/Domain/Keirin/Statistics/AgariC1Input/SourceProjector.php)を使用。元raceは`year/race_id/entries`、entryは既知9入力fieldに`labels/rank/status`を加えた完全な許可集合であることを検査した後、結果3fieldを除いた。結果の値による行選別・候補選択はしない。許可入力fieldは以下だけ。

```text
id, bike, raw, stat01_rank, anchor, anchor_status,
signals[12], history[4], history_status
```

`stat01_rank`は固定入力の能力順位であり公式結果rankとは別。[Validator](../app/Domain/Keirin/Statistics/AgariC1Input/Validator.php)で型・人数・車番・anchor/履歴状態を確認し、race IDおよびentry IDの再出現も拒否した。値・型・レース順・出走順・欠損は変更しない。

投影結果は25,197レース・179,007出走、67,675,312 bytes、SHA-256 `419e3a5568c39891fa4d47e589820e332aa2ad570a4ab0123ecaab5aff619168`。INPUTの受入済み`c1-2023.jsonl`とbyte hashが一致した。race/entry/bikeの順序付き識別stream hashは`4fad70493c0295923d2f1d0ffec3ecdc3e1ddd96e65216717a8f7d8eb3feb338`。

`projection/input-2023.jsonl`、子manifest、独立`projection/manifest.json`と`projection/COMPLETE.json`を予測前に保存した。投影と予測は別PHPプロセスで実施。予測時は元BASE/INPUT/FINALをマウントせず、`/home/shinya`も隔離するため、元2023教師・検証損失は予測プロセスから開けない。

## 6. Phase D: 候補別予測の保存

実在する新規root:

```text
/home/shinya/neo-keirin-artifacts/c1-2023-oof-recovery-01/run-20261016-212706-e8690386
```

`generation-01/lambda-0.1`と`generation-01/lambda-1`を新規生成、`generation-02`配下は別プロセスの再現確認用。同じ対象を2年分・2標本として二重加算しない。

各候補の`predictions.jsonl`にrace/entry/bike、P1/P2/P3、上位2/3着確率、log probability、3順位utility、MAP、E06 Primary/Supportingを保存した。親model識別・lambda・学習年・入力/コードsealは同ディレクトリの`PREDICTIONS_SEALED.json`と親provenanceへ結び付ける。`ordered-top3.jsonl`には別人3車の全順序組合せと同時確率を保存し、各raceの総和1も記録する。

| lambda | ファイル | rows | bytes | SHA-256 |
| --- | --- | ---: | ---: | --- |
| 0.1 | predictions.jsonl | 25197 | 221660142 | `4a7cba05c7c66bc5ca05ec0a9bdf8725fb31cdedd6c3be8348b2c5088cf6d158` |
| 0.1 | ordered-top3.jsonl | 25197 | 312960536 | `5cf13ef33d552e9a5e4266026ef0e4f861bef5eeb375ff4ac0e3e499ab63dcc8` |
| 1 | predictions.jsonl | 25197 | 221653286 | `ebc131e79dc71e5c4d5bd09520a7c1adca15d342fdbc8ac6ad6c0b145e5b7ccc` |
| 1 | ordered-top3.jsonl | 25197 | 312982874 | `bfff3c57e94a8fb2d5e19523d0490e7d9ff11035c77f6387933bb9b17bd0bb3b` |

現行ModelLoaderのrestore/loadを使い、Datasetのpredictionモード→固定Predictor→Scorer→E06で生成した。ModelLoader::predictionsの自動削除SQLite scratch DB経路は使用せず、上記の事前seal・全体重複検査・各行Validatorで検査した入力を同じbinned/predict処理へ渡した。アプリKernel/Artisan/PredictionService::runは起動していない。PredictionServiceは最終公開モデル束を前提にするため、未選択候補を最終モデルとして公開するための偽artifactは作らない。

既存Predictorが出す`prediction_origin=EXPERIMENTAL_REFIT`と`reconstruction_verified=false`は旧直列化契約をそのまま保持する。これは今回refitしたという意味ではなく、今回の実際の来歴は上記provenanceとclassificationで区別する。計算式・既存モデル・版は変更していない。

## 7. Phase E: 独立検証・保存後監査

| 確認 | 実測結果 |
| --- | --- |
| 対象完全性 | 両候補とも全25,197レース・179,007出走。投影元とのrace/entry/bike・順序一致 |
| probability | 全選手のP1/P2/P3は有限・0〜1、順位別総和1、top2/top3整合・top3上限を確認 |
| joint | 各候補5,801,868組。別人制約・重複なし・全組合せ数・総和1を確認 |
| 独立計算 | 保存utilityから別のlog-softmax/全joint/周辺化を計算。最大joint誤差0.1:1.6653345369377348e-16、1:1.249000902703301e-16。既存許容1e-12内 |
| utility | 元入力の固定bin/保存係数から再構成し保存utilityと厳密一致 |
| Primary | E06全decisionの厳密一致に加え、P1最大値・P23別人ペア最大化・SHA tie ruleを独立に照合 |
| 再現 | generation-01/02の予測・全joint各2ファイルが両候補ともbyte hash一致 |
| 開始終了seal | 元資料47ファイル、現行コード・依存・工程文書275ファイル、抽出モデル2個、投影入力のsealが不変 |

`verification/lambda-*.json`、`end-integrity.json`に記録。元資料47件を照合したことと、旧rootの全ファイルを再hashしたことは同一ではない。旧rootはread-only保護し、既存の予測・モデル・manifest等へ書込みは行わない。

両候補の予測seal・独立再生成・整合性検証後にのみ、別プロセスへ元2023教師と保存損失を開放した（`loss-audit-release.json`）。既存Objectiveによる損失は両候補・3順位・全25,197行で保存値と厳密一致。NULLの除外状態も一致した。

| 順位 | 適格 | 除外 | 候補ごとの保存値一致行数 |
| --- | ---: | ---: | ---: |
| POSITION_1 | 25134 | 63 | 25197 |
| POSITION_2 | 25077 | 120 | 25197 |
| POSITION_3 | 25022 | 175 | 25197 |

保存utilityから独立計算したteacher-forced NLLとの差は全順位・両候補で最大8.881784197001252e-16。これは保存モデル・入力・確率来歴の照合であり、的中率の新評価やlambda選択ではない。平均損失・候補順位・One-SEは計算しない。損失監査後の再予測も行わない。

## 8. 実行・安全性・証跡

PHP8.5.4 CLI、各実処理を独立`php -d memory_limit=128M`で実行。`bwrap --unshare-net`、旧rootのread-only mount、復元済み.envのマスク、storage/bootstrap/cacheと/runの隔離、プロセス環境のclearを適用した。書込み先は今回記録した新rootの必要部分だけ。削除・退避・後片付けは一切行わず、スクリプト・ログ・再現出力をそのまま保持する。

全9プロセスは終了0、stderrは全て0 bytes。PHP peakはprepareが28,311,552 bytes（27MiB）、予測・独立検証・損失監査・終了検査は10,485,760 bytes（10MiB）。最大RSSは56,400KiB。開始時空き容量は約99GiB、manifest対象83ファイル合計2,206,842,207 bytes。重要資料を/tmpだけへ保存していない。

実処理全体は2026-10-17 06:27:13〜06:28:29 JST（UTC 2026-10-16 21:27:13〜21:28:29）、75.633923秒、runner終了0。内部日時とrun名はUTC、報告日はJSTである。

| 工程 | 秒 | 終了コード |
| --- | ---: | ---: |
| A/B/C検証・入力seal | 3.119 | 0 |
| generation-01 lambda=0.1 / 1 | 11.522 / 11.621 | 各0 |
| generation-02 lambda=0.1 / 1 | 11.499 / 11.482 | 各0 |
| 独立検証 lambda=0.1 / 1 | 10.369 / 10.086 | 各0 |
| seal後の損失監査 | 5.072 | 0 |
| START/END seal | 0.844 | 0 |

実行済みコマンド（同じrootへの再実行はrunnerが拒否する）:

```bash
php -d memory_limit=128M \
  /home/shinya/neo-keirin-artifacts/c1-2023-oof-recovery-01/run-20261016-212706-e8690386/scripts/c1-2023-recovery-runner.php \
  /home/shinya/neo-keirin-artifacts/c1-2023-oof-recovery-01/run-20261016-212706-e8690386
```

各子プロセスの実argv、隔離条件、開始終了・終了コード、stdout/stderr、`time -v`を`logs/<phase>.command.json`/`.execution.json`/`.stdout.log`/`.stderr.log`/`.resource.txt`へ保存した。rootの`execution.json`へ全工程を集約する。生成スクリプトはPHP構文確認済み。

| 新資料 | SHA-256 |
| --- | --- |
| scripts/c1-2023-recovery.php | `f1a4988cc3dd6db70008d4333d957ffa1cfd51311d5e1d144b3e8a378ed55b31` |
| scripts/c1-2023-recovery-runner.php | `68725455e58337c5519c276913d794c511f12d593a82d8050b038a8cd643b77f` |
| prepare.json | `b16c73c2422280ea9efa3f1641df09ced91961afe68c8acc435e6ea4b3260fcd` |
| manifest.json (15042 bytes) | `1f4e53cc10381451efc6eb56c2ae7cbbab045137a338a6d03c8bcdbf131d963f` |

rootのCOMPLETEとmanifestが一致し、列挙83ファイルのsize/SHAを公開時と公開後の読取り確認で照合した。既存の学習コードのhash/旧成果物は書き換えず、今回の生成・読込スクリプトの版/hashを別記録にした。

## 9. 未生成・未確認事項と次工程

- **生成済み**: 未選択候補2系列の2023確率・utility・Primary・全Ordered Top3、別プロセス再現、独立整合性検証、seal後の損失照合。これらの候補確率生成にC1の再学習は不要だった。
- **未生成**: 選択も2023から独立した最終OOFの認定、補正モデル、H1-U訓練入力束/fit、2024/2025の新規予測・性能評価。両候補を最終OOFとして混ぜたり、損失で片方を選んだりしない。
- **未確認**: 調査root外・全媒体の2023確率保存状況、2023教師から独立した親選択の根拠、今後の選択手順、補正の転移性能・的中改善、当時の発走前公開・取得時刻。入力は既存のbackfilled development再構成である。
- **H1-U**: 候補確率という材料は揃ったが、親モデルの選択根拠・補正契約が未解決なので学習・評価開始可能とはしない。必要なら2022内だけの選択設計などを別途判断するが、追加学習の必要性も今回確定しない。
- **維持**: C1/STAT39/Evaluator/Decoder/Gateの凍結、旧C1_PLUS_FIELD_BIKE不採用、historical_as_of=false、正式採用/2026/LIVE禁止。

リポジトリ変更は本報告書1件のみ。アプリ・テスト・設定・MASTER PLAN・PR #112は無変更。PHPUnit/Pint、DB接続、HTTP、Raw、2026、C1/補正学習、2024/2025新規予測・性能評価、旧成果物の上書き/削除/移動/再seal、commit/push/PR作成/mergeは未実施。次は本報告・新候補資料のChatGPTレビューのみで停止する。
