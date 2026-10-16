# C1-LOW-RANK-CALIBRATION-FEASIBILITY-01

## 1. 結論と許可範囲

2026-10-16。**候補の技術的成立可能性はあるが、保存済み成果物を読むだけで補正学習・評価を開始できる状態ではない。**

```ini
H0=SAVED_RUN_01_C1_OUTER_UNCHANGED
H1=H1_REQUIRES_OOF_GENERATION
H2=H2_REQUIRES_NEW_PRESTART_EVIDENCE
H2_execution=BLOCKED
experiment_design=PROPOSAL_NOT_FROZEN_NOT_AUTHORIZED_FOR_IMPLEMENTATION
accuracy_improvement=NOT_EVALUATED
historical_as_of_available=false
formal_adoption=false
prediction_use=NOT_AUTHORIZED
2026_holdout=FROZEN_NO_ACCESS
C1_PLUS_FIELD_BIKE=NOT_ADOPTED
status=C1_LOW_RANK_CALIBRATION_FEASIBILITY_READY_FOR_REVIEW
```

H1の候補方式と、OOF・時系列・評価の開始条件を整理した。2023年OOF**確率**は確認対象の固定束内で未保存、ただし2022年学習の候補モデルは残存する。将来の生成に再学習が必須とは限らないが、使用モデル・lambda選択の時点、補正器の内部検証手順を承認する必要がある。現段階では`H1_EXPERIMENT_DESIGN_READY`としない。

H2は新規情報・時点証拠が不足し、旧人数×車番カテゴリの再試行へ戻さない。今回の終了は設計・成立性レビュー待ちであり、補正係数決定・実装・学習・OOF生成・予測・性能比較はすべて0回。

## 2. Git・工程ゲート・資料の時点

開始時はclean main、HEAD・origin/mainとも`f95d26bc8d2bed81e988d5912719749f39541d0e`。ローカルmerge履歴からPR #111を確認（親は`cb77621`と`a838ba3`、前者はPR #110 merge）。fetchやGitHub照会は行っていない。同名ブランチ不存在確認後、`design/c1-low-rank-calibration-feasibility-01`を作成した。mainは編集しない。

AGENTSは全文再読。MASTER PLANは前工程で全文確認した原文と、今回の全ファイルbytes/SHAが完全一致することを照合し、冒頭・現在地・8制御項目と適用契約を再確認した。

| 根拠 | bytes | SHA-256 |
| --- | ---: | --- |
| AGENTS.md | 14227 | `28679d6ae0b79fd700b9f4a29a01fd2419c659f82337e235c112508fd6642642` |
| MASTER PLAN | 449936 | `bcd4cff217ecec1217efa03b57b110ece596760df5c62c5a12541f396113fe6a` |

| 制御項目 | 確認した保存状態と今回の扱い |
| --- | --- |
| current_engine_state | Section 5.0: `CATEGORY1_MVP_CODE_TESTED_AWAITING_REVIEW`。2026-10-12の保存記録 |
| next_allowed_action | 同: `REVIEW_CATEGORY1_MVP_CODE_TESTS_AND_LEDGER_ONLY`。その後のPR110/111と今回の候補成立性確認許可を別に扱う |
| completed_phases | 統計基盤、BT01〜03/E系、既存限定比較等の完了一覧を確認。完了済み生成・評価を再実行しない |
| superseded_phases | `BT-03D-PREDICTIVE-SELECTION`、`BT-03E-01-COARSE-INTEGER-SCORING-RULE`。復活させない |
| blocked_phases | STAT36の時点未確認一般利用、旧TACTICAL-PILOT、BT04、BT05-LIVE等を維持 |
| frozen_contracts | 既存C1の位置別utility・逐次softmax・正確な周辺化・anchor、temporal、lambda/solver、Evaluator/Gate、leakage/source/missing/abnormal/128M/artifactを維持 |
| unfrozen_contracts | 将来統合の係数・選択・confidence等の未確定一覧。既存最終C1の固定済み係数・bin・lambdaを再び未決へ戻す許可ではない |
| holdout_status | development=2022〜2025、2026のモデル選択・性能評価・outcome参照すべてFORBIDDEN |

保存記録の古さは既知の履歴差であり、凍結解除の根拠にはしない。最新指示が許可するのは今回の準備だけ。提案する新補正層の数値契約は将来の別承認が必要で、既存クラス・版・契約には適用しない。今回MASTER PLANは更新しない。

参照文書は[PR110診断](stat39-c1-bike-residual-diagnostics-01.md)第13節、[PR111条件付き診断](stat39-c1-bike-ability-conditional-diagnostics-01.md)、[旧カテゴリ比較](stat39-c1-field-bike-01.md)、[拡張要件](stat39-extension-requirements-01.md)、[発走前契約](stat39-prestart-assignment-evidence-contract-01.md)、[公式出典台帳](stat39-official-rule-source-ledger-01.md)、[時点監査](c1-racecard-asof-preflight-01.md)、[C1学習](tactical-history-01.md)、[最終fit](tactical-history-final-01.md)。PR110初版の正常行限定残差と、レビュー修正版C/A cohortを混同しない。

## 3. 現行C1の能力・車番・STAT39

現行utilityは各順位kについて次の形（既存[Scorer::utility](../app/Domain/Keirin/Backtest/Calculators/Bt03e03ProbabilityScorer.php)、[Objective](../app/Domain/Keirin/Backtest/Experiments/TacticalHistory/Objective.php)）。

```text
u_k(i) = anchor(i) + sum(active bin coefficients for fixed 12 STATs + 4 history counts)
anchor(i) = (raw(i) - race mean raw) / race population SD
```

anchor係数は全順位で1。ゼロ分散は既存ZERO_VARIANCE/anchor=0、欠損状態と別。`raw`と`stat01_rank`は保存・識別・基準評価に使われるが、Scorerのutilityへそれぞれ独立の学習項として直接加算されるわけではない。`Dataset::binned()`は12信号＋履歴4値をbin化する。能力と相関する既存STATがあることと、相対能力順位の非線形応答が明示的に学習済みであることは別である。

STAT39は[Registry](../app/Domain/Keirin/Backtest/Services/Bt02SignalRegistry.php)の`features.FIELD_BIKE_DELTA.mean_score_expectation_residual`。`STAT_CODES`・保存レイアウトでindex10に対応し、既に全3順位に投入されている。[Stat39Calculator](../app/Domain/Keirin/Statistics/Calculators/Stat39Calculator.php)は人数/車番別の得点期待残差等を持つが、公式割当制度・変更履歴・ライン/初手はunavailable。車番そのものの独立加点や因果係数ではない。

[Stat01Calculator](../app/Domain/Keirin/Statistics/Calculators/Stat01Calculator.php)の保存順位は`1 + 自分より高い得点の人数`という同点共有順位で、dense rankは別field。相対順位の候補`(r-1)/(N-1)`はN>1で定義可能だが、同点時には最大rがNに達しない場合がある。「下位1/2位」を`r==N`等だけで表すと最下位同点を落とし得る。Nは元登録出走表の人数で、実発走/正常完走人数から作り直さない。欠番・同点を車番で解消しない。

### 引用する既存実測（今回は再集計しない）

| PR111の記録 | 2024 | 2025 | 解釈 |
| --- | ---: | ---: | --- |
| C・7車6番の能力6位 / 7位出走数 | 5608 / 15379 | 5853 / 15059 | 下位への強い集中 |
| 能力7位・確率帯標準化P1の6番−他車番差pp | -0.964 | +0.101 | 年別に方向不一致 |
| C・7車6番のPrimary P1選択 | 4/21019 | 5/20949 | 極めて少数 |
| 他車最大P1−6番P1の中央値 | 0.4184 | 0.4150 | 小さい補正で順位変更できる根拠にはならない |

PR110/111とも総合B。一律6番加点/減点は合理化されず、同能力・同確率帯で相対差の向きが変わる。PR111ではSTAT39符号を追加すると2025のP1/P2差が弱まり、比較相手保持率も約6〜7%へ縮小した。旧`C1_PLUS_FIELD_BIKE`は主Gate未達・NOT_ADOPTEDのまま。これは今回の新実測ではない。

## 4. H0 / H1 / H2

| 仮説 | 問い・入力 | 既存方式との違い | 現段階 |
| --- | --- | --- | --- |
| H0 | `SAVED_RUN_01_C1_OUTER`を変更しない | 予測・係数・bin・Primary・評価指標はそのまま | 唯一の正式比較基準 |
| H1 | 元N、保存能力順位、raw、anchorに対する既存C1の応答/較正不足を改善できるか | 新情報追加ではなく、既存能力情報の非線形表現またはOOF上の較正。車番を説明変数にしない | OOF/選択時点/補正仕様が不足。製造未承認 |
| H2 | 6/7番差等に、能力・既存FIELD_BIKE_DELTAを超える新しい予測情報があるか | 旧人数×車番カテゴリと実質同義の追加では不可 | 新情報・発走前証拠不足、BLOCKED |

H1で改善が将来観測されてもSTAT39固有の増分効果とは呼ばない。raw、anchor、相対順位は同じ得点由来で冗長になり得るため、無制限に重複追加しない。検討対象は低次元・縮約可能な表現で、どれを採用するかは未決。

H2に必要な証拠は、同一race/出走者への本人対応、当時の得点・車番・登録人数の原観測と利用可能時刻、変更前後の履歴、当該期間/競走に適用される公式割当制度・同点/補充等の例外。制度を変数に使う場合は公式原文・版・期間・race適用の一意性が必要。ライン/初手を使う場合は別の定義・発走前観測が必要で、結果の実初手や脚質から推定しない。

公式制度の説明が得られるだけでは新しい増分情報の存在も保証されない。既存信号との重複・common supportを別途確認する。出典台帳R01〜R12およびSCR39のOPEN、旧時点監査の発走前候補0という範囲限定記録を維持する。`day_kind`を通常/深夜等へ推定変換しない。H1のdevelopment設計をH2の証拠不足の解消としない。

## 5. 固定成果物の保存状況（今回の読取り確認）

使用root:

```text
BASE=/home/shinya/neo-keirin-artifacts/tactical-history-01-review-fix-20260916-01
INPUT=/home/shinya/neo-keirin-artifacts/stat35-c1-input-02/run-20260930-054458-8739da4b/result
FINAL=/home/shinya/neo-keirin-artifacts/tactical-history-final-01-20260917-01
```

BASE export `4268b801b74b77cfb3a94b64832ff483e9eb5e3221ee8e75ceb467e5a5cf92e6`、固定契約`5db3b57b08fb8b91dbe6c057b459e518252c454b0a4ff60531c2d671b7ce506a`、INPUT manifest `7f4356b93f90203dc727dc95d6215a8d8ce3f44869c4441c3981087ff1099c26`を実測照合。INPUT COMPLETEも照合した。

### 5.1 2023年のOOF確率は未保存

[Trainer::grid](../app/Domain/Keirin/Backtest/Experiments/TacticalHistory/Trainer.php)は学習期間だけでlayout/bin/support・係数pathを作るが、検証時は`Objective::raceLoss()`を呼び、`race_id`と候補別3順位の損失だけを`validation-losses.jsonl`へ保存する。`Predictor::predict()`を呼ぶのは`refit()`側。損失はteacher-forced条件付きNLLであり、全選手のP1/P2/P3周辺確率ではない。損失から予測分布を逆算・補完しない。

| 保存物 | 今回確認できたもの | 確率stream |
| --- | --- | --- |
| `BASE/run-01/C1-inner-A` | Train2022、Val2023、layout/path/候補診断/学習資料/検証損失。収束済みモデルlambda=0.1,1 | `predictions.jsonl`なし |
| `BASE/run-01/C1-inner-B` | Train2022〜23、Val2024。同様にlambda=0.01,0.1,1のモデルと損失 | 同なし |
| `FINAL/fit/reuse-oof-1`、`reuse-oof-2` | `reuse.json`は検証損失spool再構築の記録。元path/学習input/損失sealを参照 | 2023/2024確率の保存を意味しない |
| `FINAL/fit/run-01/oof-3` | Train2022〜24→Val2025の候補pathと検証損失 | 同なし |

inner A検証損失は25,197行、inner Bは25,212行で、対応する保存検証入力とrace ID順序が一致。入力出走数は179,007/179,089。損失行のキーは全件`race_id,losses`、候補集合はそれぞれ上記収束済み候補と一致した。

確認範囲はBASE exportのC1/run-01の掲載ファイル、inner A/B実ディレクトリ、FINAL/fit配下の実ファイル名・export・結果・reuse記録。**この範囲では2023年の真のOOF予測確率は見つからない**。全ディスク・全実験に存在しないとは断定せず、別候補やrun-02を代用品にしない。

### 5.2 Outer予測と最終fitの区別

| 確率 | 係数学習期間 | lambda選択に使用した検証 | 使える役割 |
| --- | --- | --- | --- |
| `BASE/run-01/C1-fit-2024/predictions.jsonl` | 2022〜23 | Inner Aの2023 | 2024の固定H0。2024評価終了後、2025補正学習用の過去年確率候補にもできる |
| `BASE/run-01/C1-fit-2025/predictions.jsonl` | 2022〜24 | A/Bの2023/2024 | 2025の固定H0。2025補正の学習・選択には使わない |
| `FINAL/fit/run-01/final/predictions.jsonl`等 | 2022〜25 | 2023/2024/2025の3fold損失 | 2025への適用はin-sampleの技術roundtrip。補正学習用OOF・未知年評価の代用不可 |

Outer両年の保存lambdaは0.1だが、後からその値だけを2023モデルへ適用し「当時も2023結果から独立」と呼んではいけない。最終fitの選択lambdaも2025損失を参照しており、2024/2025を評価する親モデル選択へ遡及利用できない。

今回のseal/行数照合:

| ファイル（BASEからの相対） | bytes | SHA-256 |
| --- | ---: | --- |
| `run-01/C1-inner-A/path.json` | 219289 | `62d90c8f0ce56177bbadf9e9b0786bf7646a77b464e5dbfccd62f69dab8efdf5` |
| `run-01/C1-inner-A/validation-losses.jsonl` | 6275417 | `148f31f772a14c9e125880c17c3cebf12af177f6afc03fcd5d369f6d7f95e03e` |
| `run-01/C1-inner-B/path.json` | 323056 | `13755f960eb6e061710bc4213a1658730a25f2073421a98622621eb7003d23f2` |
| `run-01/C1-inner-B/validation-losses.jsonl` | 8903894 | `09be2b90e3b12207aeee5831f20c185e9eee26754552cc8431ef26ee54617a80` |
| `run-01/C1-fit-2024/predictions.jsonl` | 221756982 | `e7d77d5118e0e530e2eafaf020416f32f8e6a806948460b788cca35b7816dbf1` |
| `run-01/C1-fit-2025/predictions.jsonl` | 219184562 | `de9c0746821d03e97994cb9e0928461298d2f70d7d6c8e736351b6fbe0562ce9` |

Outerは25,212/24,866レース、179,089/177,120出走。固定入力・教師・予測のrace ID順序と件数をストリーム照合した。モデルSHAは2024=`38a78da4efc01249d3ad579f620c9473e1c231d19dc5eeb2e0b9e5a6bf26c249`、2025=`f37452a8fe5cef4108f5c0357c1b880222742f7610ea5700fb54caa636bdce43`でexportと一致。予測には3順位utilitiesが保存されている。先頭行のschema確認ではrank/status/labelsなし（全行の新しいoutcome監査を実施したという意味ではない）。

FINAL exportの今回実測SHAは`3980707596ca7c36db68647b1084783b59cff47080d1b3c8ef714af66d1cbee4`、`fit/result.json`は`71e6de55803b18e5b82e1f90a399f747e688cf1eba5695b53c463be16cff302e`。選択したreuse/path/selection/final modelをexport sealと照合。最終モデルSHA `e3cc1f7f10af60bb22f97a2172ca52ef2c09a3393222ee46c25619de752d43a1`は従来記録と一致。`reused_oof=[2023,2024]`は確率streamの存在証明ではない。

### 5.3 既存資料だけでは足りない理由

2024保存確率とその正解だけで補正を学習し、同じ2024を独立評価とはできない。2024から補正を学習して2025へ適用する片側development比較なら時系列上は構成可能だが、要求される両Outerの検証ではなく、2025も既に探索済みである。今回はこの別設計へ縮退して実行しない。

2023のpathモデル＋固定入力は**新規OOF生成の材料**であって、OOF確率そのものではない。保存モデルのforward再生でも新しい予測を生成するため、今回は禁止。候補モデルの全収束診断・訓練ファイルbody/bin/support・実行コードを再利用前に照合する必要があり、今回の限定seal確認を再利用受入完了とは扱わない。

## 6. 時系列として成立させるための案（未承認）

二種類の問題を分離する。

- **補正学習のモデル時系列**: 当該予測年の結果を係数/bin/support・補正・選択へ入れない。これは将来のreplayで検査可能。
- **当時の入力利用可能性**: 後日取得のraw/anchor/信号/履歴が歴史的な発走前に取得・利用可能だったか。既存証拠では未証明なので、上の時系列が成立しても`historical_as_of_available=false`。運用可能性まで「リークなし確認済み」とはしない。

### 6.1 厳密な2023 OOFと選択の扱い

2022のみで学習した**個々の固定lambda候補**は2023係数学習をしていない。しかし2023の損失でlambdaを選び、その同じ2023確率を「選択も2023から独立したOOF」と呼ぶのは不適切。最終lambdaの流用も同じ問題を持つ。

将来承認が必要な選択肢:

1. 2023教師を開かない親モデル/候補識別を事前指定する。保存候補の再利用時も、その選択が2023/2024/2025の好成績を見た選別ではないこと、探索済みdevelopmentという限界を記録する。現在確認されたlambda=0.1/1を本書でどちらかに決めない。
2. 親lambdaや能力表現をデータから選ぶ必要があるなら、2022内の開催単位の前進分割で選び、選択情報が2023へ越えない方式を先に承認する。分割日・必要な開催metadata・不足時の停止条件は未決。新しい学習pathが必要になり得るが今回は実行しない。
3. 2023全体で選択した親を補正訓練に使う緩い設計を採るなら、2023は選択込みOOFではないことを明記する。将来の2024評価に対する直接的な未来参照とは別でも、補正訓練の楽観性を持つため、本工程でこの緩和は承認しない。

### 6.2 両Outerを作る前進手順案

| 段階 | 親C1と補正の学習・選択 | 予測固定と教師 |
| --- | --- | --- |
| 準備2023 | 承認された2022学習モデルを固定し、保存2023入力からOOF utilities/確率を生成。2023を親係数/bin/supportへ入れない | OOF生成物のseal後に2023教師を補正訓練・内部検証へ接続 |
| 補正の内部検証 | 例えば2023内の開催単位前進分割で補正の複雑さ/正則化を選択し、2023で最終補正をfit | 日付/開催対応をseal検証し、同一開催を跨がせない。分割数・境界・探索範囲は承認待ち |
| Outer2024 | 固定H0は元の2022〜23 refit＋2023までのlambda選択。補正は2023以前だけで学習 | H0と候補2024の対象照合・予測seal完了後に2024教師を評価へ解放 |
| Outer2025 | 固定H0は元の2022〜24 refit＋A/B選択。補正は2023 OOFと保存Outer2024確率を使い、2024までの教師だけで更新 | 基本方式/選択規則は2025を見る前に固定。候補2025 seal後に2025教師を評価へ解放 |

補正更新の内部検証規則は両年で事前に決める。2024を補正学習へ開放できるのは2024予測固定後であり、2024の成績で方式を作り直して同じ2024結果を未使用評価へ戻すことは禁止。既に全開発期間を研究に使っているので、実行上の教師解放監査を「過去の研究者が結果を知らなかった証明」としない。

2023親と2024/2025親は訓練規模・bin・support・選択が異なる。補正の転移先が同分布とは限らない。各OOF行へ親モデルhash・訓練年・lambda選択情報の締切・layout/input hashを結び付け、年別の較正/適用範囲を報告する。2023 OOFも学習データ上の再予測や最終fitの出力で代用しない。

### 6.3 追加計算の範囲と上限の決め方

| 将来必要になり得る処理 | 再利用・新規計算の見込み |
| --- | --- |
| 2023 OOF | 少なくとも承認親ごとに1つの新規forward stream（25,197レース）。候補モデルを受入れ再利用できればC1の新規fitは0で済む可能性。選択手順/モデル検証が不成立なら追加学習が必要 |
| 2024/2025親確率 | 固定H0の保存Outerを再利用可能。今回これらを再生成しない。2024は2025補正の過去年訓練材料候補 |
| 補正fit | 2024用・2025用の少なくとも2つの最終補正fit。内部前進分割×候補数のfitは追加。独立2runなら最終補正fitは計4つで、内部試行・forward/検証も別途必要 |
| 2022内の親選択を追加 | 分割数×固定lambda pathの追加訓練が必要になり得る。境界・候補数未決のため回数/時間を確定しない |
| 能力表現をC1本体へ追加して再学習する別案 | 新layout/係数を要するため既存inner/outerの代用不可。元方式と同じA/B/Outer24/25を実施するなら1runで4学習path、独立2runで8path。各pathのlambda試行/3順位fit数・非収束で実計算数は変動 |

これらは将来の作業量見積もりで、実行回数ではない。現在の損失行から確率を復元できるという意味でも、実行時間・収束を保証するものでもない。追加計算の最大候補/分割数・停止条件・再試行上限・保存先を別途承認する。

## 7. H1の技術的候補と確率不変条件

### 7.1 入力表現の候補

元登録Nに対する相対保存順位、得点の連続値raw、標準化anchor、同点を保持した下位1/2群を候補として比較する。全N=5〜9を保持し、7車だけの結果で方式を決めない。下位群は同点で1/2人にならないことを許すか、別の同点規則にするかを承認する。車番によるtie breakは禁止。

全レース共通の補正関数・縮約を基本候補とし、人数別の自由な係数を無制限に作らない。欠損を0・最弱・負けへ補完しない。未知の適用範囲や不足supportは診断として保持し、futureの無補正fallback/停止方針も事前承認する。PR111の診断用200件を新しい学習/採用Gateへ転用しない。

### 7.2 三方式の比較

| 案 | 仕組み・必要データ | モデル変更範囲・不変条件 | リスク・負荷 |
| --- | --- | --- | --- |
| H1-U: 順位別utility補正 | 凍結C1 utilityに、能力だけから作る有限の`g_k(N,r,raw,anchor)`を加え、既存の除外付き逐次softmaxで再周辺化。学習にはOOFのutility/入力＋訓練年教師が必要 | 既存C1モデルは不変、別版の補正層/合成モデルを保存。既存Scorerの確率構造とE06のutilityに基づく条件付けを維持できる。追加層の設計承認は必要 | 基底/自由度/正則化と年別転移に過適合リスク。各反復は候補のNLLに依存、出力時は既存同様にO(N^3)の正確な周辺化 |
| H1-J: Ordered分布の較正 | `Q'(i,j,l)=Q(i,j,l)*exp(h1(x_i)+h2(x_j)+h3(x_l))/Z`、異なる3選手だけでZを正規化。完全な元utility/条件付き分布を要し、周辺Pだけでは元jointを決められない | 正の有限Z、非負/有限joint、全joint和1、各順位和1、選手重複0、選手top3確率和<=1を保証可能。ただし一般には既存のentry別逐次utilityへ表現できない | joint内の再正規化が条件付き確率を変える。現E06::conditionalsへ元utilityを渡すだけでは不整合。専用adapter/契約例外が必要で今回は優先しない。各反復O(N^3)で負荷増 |
| H1-R: C1学習時の能力表現改善 | 相対順位等の非線形基底を追加し、過去年の教師で候補モデルを新規学習。独立のpost-hoc補正器とは異なる | 新版feature/layout/bin/support/係数が必要。旧16項目・モデルを黙って変更しない。将来、既存の逐次確率・decoderを維持する形は可能 | 全体を再学習する範囲が大きい。既存情報の二重表現・過学習、solver非収束のリスク。候補定義/内部選択は別承認 |

H1-Uを最小範囲の**検討候補**とするが、採用方式のfreezeではない。`g`の係数、符号、補正幅、bin境界、人数別/順位別自由度、正則化候補は決めていない。既存anchor値・係数1・元係数を保存した別層であり、既存STAT01の計算変更とは分離する。それでも候補の能力応答は変わるので、旧C1の出力仕様を無断で上書きしてよいという意味ではない。補正を元anchorや旧モデル係数へ埋め込まず、別のutility合成経路・版で区別する。joint案の指数・正規化にはlog-domain等の安定計算も必要で、数式上の正規化だけでbinary64実装の安全性を保証しない。

softmaxには共通定数を足す自由度があるため、追加関数の識別/中心化制約も必要。0補正時の元C1一致、有限性・確率和・条件付き候補集合、同点/欠損/Nの境界を将来の回帰要件にする。既存Solverの200更新・停止条件・lambda gridを緩和しない。別補正層の目的関数・正則化・識別制約は製造前にレビューし、現Solverの型や収束保証を未検証のまま流用しない。

学習候補は既存の順位別teacher-forced categorical NLLと同じ適格性を基本に検討する。1着にはrank1、2着にはrank1/2、3着にはrank1/2/3が各一意であることが必要。教師の上位を学習時の除外に使うことと、予測時に実上位を入力することは全く別。後者は禁止する。

各選手の無条件P1/P2/P3へ独立に加算・clip・順位別再正規化するだけの案は採用しない。順位ごとの和1だけでは、同一選手重複やtop3和<=1・jointとの整合性を保証できない。

## 8. 予測固定・結果解放・監査の必要条件

将来の新規専用namespace・新規出力束を承認してから実装する。既存のrun/manifestへファイル追加・再sealしない。

1. 実行コード・候補族・内部選択規則・年/開催分割・基底・欠損処理・予算・既存Gateを先に固定。診断結果を見た探索であることを明記。
2. 元C1と固定入力の親manifest/COMPLETE、対象年/ID/車番/順序・人数・値・版・学習年/モデル・bin/support・候補収束を検証。元資料はread-only。
3. OOF生成器へは当該年のrank/status/labelsを渡さない。元2023入力は教師を含むので、許可field投影を先に検査し、結果2fieldだけを無検証で捨てる方式にしない。別の封印された教師束へ分離する。
4. OOF保存後、manifest・model/input/コードhash・期間とcandidate選択情報締切を独立検証。OOF学習用教師と最終評価用教師を別の解放eventとして記録。
5. 過去年だけで補正fit/選択、補正model/基底/support/収束診断をsealし、結果なし予測器から当該Outerの候補予測を保存。
6. 固定H0と候補の対象universe/順序/入力依存を照合し、各予測semantic manifestと公開前sealが成立してから、その年の評価教師を解放。
7. 2024の評価終了後のみ、決めた手順で2024を2025の過去年訓練へ開放。2025は当該候補の学習・選択に入れない。
8. 別プロセス・新規出力への独立再現、入力/コード/モデルのSTART/END検査を実施。失敗時は診断を保持し、別lambda・別閾値・無断再試行で成功へ合わせない。

いずれも既存backfilled資料によるdevelopment replayで、当時の公開時刻を証明する仕組みではない。128M・streaming・既存の安全な排他保存/退避を要件とし、今回実装・性能検証済みとはしない。

## 9. 的中精度の比較設計

主比較はH1−固定H0。全保存Outer 50,078レースを同じ教師・同じ指標別分母で比較し、下位/6番だけへ評価対象を限定して主Gateを置き換えない。PR111のC/Aは原因診断用であり、主評価の母集団ではない。

| 指標 | 固定Evaluator上の意味 | 旧C1の分母2024 / 2025（引用） |
| --- | --- | --- |
| WINNER_HIT_AT_1 | 公式1着一意、Primary P1一致 | 25158 / 24789 |
| POSITION_2_ACCURACY | 公式2着一意、Primary P2一致 | 25106 / 24727 |
| POSITION_3_ACCURACY | 公式3着一意、Primary P3一致 | 25094 / 24739 |
| POSITION_HIT_RATE_AT_3 | 公式1/2/3着が全部一意、位置一致数合計/(3×適格レース数) | 75120 / 73989 |
| EXACT_ORDERED_TOP3_RATE | 全3順位完全一致、公式上位3順位一意 | 25040 / 24663 |

[Bt03e05MetricEvaluator](../app/Domain/Keirin/Backtest/Calculators/Bt03e05MetricEvaluator.php)の通常`EXACT_ORDERED_TOP3_RATE`は`map_ordered_top3`を参照する。今回の目的のPrimary三連単とは別なので、将来も[Evaluation::primaryDecision](../app/Domain/Keirin/Backtest/Experiments/TacticalHistory/Evaluation.php)と同じ**評価用投影**で保存Primaryをmap fieldへ渡す系列を別名で出す。元decisionを書き換えたり、Primaryを選び直したりしない。MAP/Supporting系列も元の契約で別表示し、混同しない。

CIは既存[Bt03e05PairedBootstrap](../app/Domain/Keirin/Backtest/Calculators/Bt03e05PairedBootstrap.php): 2,000回、seed20260812、Type7、年層別paired race-cluster、年等重み。同じレース重みを候補・H0で共有し、差を直接bootstrapする。独立CI端点引算は禁止。年別分子/分母・増減を残し、年等重みと2年件数加重を分ける。PR111の記述診断用CR1区間を、この性能比較CIへ代用しない。

主判定は既存`Evaluation::incrementalGate()`を変更せず再利用する:

- 全4主指標の差CI下限strict `> -0.0015`。
- Hit@3差CI下限strict `> 0`。
- 各年Hit@3差`>=0`、各年主4指標差`>=-0.003`。
- integrity・独立再現が成立。丸め前の値で判定。

既存対STAT01 Gateは別の補助判定で、H1対H0の代替にしない。Primary完全一致率も報告するが、今回新たな採否閾値は作らない。NLL/Brier/較正が良くても主的中指標が改善しなければ「的中精度改善」としない。

方式・補正幅・候補を成績で変更した場合は別の探索履歴であり、同じ2024/2025を再び独立の未使用評価として扱わない。Gate失敗は記録し停止する。両年は既使用developmentなので、将来の合格も探索結果・開発上の候補判定に限る。2026を最終評価へ開く許可は含まない。

## 10. Primaryが変わる可能性と損益

PR111の未選択実績数はoracle的な見逃し数で、補正により回収できる的中数ではない。6番車のP1差中央値が約0.42あることから、「少し較正すれば多数回収できる」とは言えない。今回、補正幅別の逆転件数や新Primaryは計算していない。

H1-UでP1を変えるには、対象選手と他車のutility差を補正差が越える必要がある。小さな変化で選択が変わるのは境界付近の場合であり、共通定数だけならsoftmaxも順位も変わらない。無条件確率の加算幅とutility補正量は同じ単位ではない。

P1の交代はE06の条件付き候補集合を変え、P2/P3の両方へ影響する。P1が同じでも、補正u2/u3は`q2(second)+q3_marginal(third)`の別人制約下の最適ペアを変え得る。単純な周辺P2/P3順位とPrimaryは同じではなく、2着を上げて3着を失うこともある。

将来比較では各位置の正解→不正解、不正解→正解、非変更、予測入替、三連単・位置Hit@3の増減を全cohortで保存する。下位だけの改善で上位や全体が悪化していないかを見る。oracle回収上限・較正残差・新予測の実測改善は別の量として表示する。

## 11. 実行前に必要なユーザー判断

| 判断 | 現状・必要な承認 |
| --- | --- |
| H1候補方式 | U/J/Rのどれを1次候補にするか。Uを検討候補とするが未選択/未凍結 |
| 能力表現・同点 | 相対順位/raw/anchorの使用範囲、下位群の同点規則、N間共有、欠損/適用外の扱い |
| OOF親選択 | 2023結果から独立する親識別/選択手順。保存inner A再利用か、2022内前進選択の追加学習が必要か |
| 補正選択と訓練 | 過去年/開催分割、目的関数・正則化・基底・識別制約・選択規則・最大試行数 |
| 実行許可 | 人工回帰の製造、保存資料のみのOOF forward、新規補正fit・候補予測・評価/paired CI・独立再現を個別に許可する必要。今回の許可では実行しない |
| 版・保存・安全 | 新候補/補正/入力・予測版、既存合意root内の専用保存先と上限、128M、失敗資料保持、旧成果物非変更 |
| H2 | 新規情報と公式意味・発走前観測証拠の受入が先。収集許可や旧カテゴリ再試行は含めない |

推奨は**製造・比較へ直行せず、H1-Uの範囲とOOF生成・親選択の設計レビューを先に行うこと**。H2の新証拠収集は別工程。H1 development検討にH2の公式制度完成を必須とする必要はないが、入力時点保証の未成立は両仮説に残る。今回この表を承認済みとは扱わない。

## 12. 今回の検証と未実施

アプリKernelやArtisanを起動せず、単独PHPでJSON/manifest/ファイル名・seal/件数・ID順序のみ確認した。`Trainer::grid/refit`、`Predictor`、`ModelLoader::predictions`、`Evaluation`、`ReuseBundle::restoreFold`は呼び出していない。最後のメソッドは新規spoolや訓練検証資料を作るため、読取りだけの確認に使用しなかった。

確認用スクリプトは`/tmp/c1-low-rank-feasibility-check.php`、SHA-256 `2fa4f74326eb23b9f58fff41ff9df338b3462276819d78d76c51dfd0a60b726c`。確認結果・使用元・主要seal・件数は本報告へ永続記録し、大容量成果物は生成しない。

実行コマンド:

```bash
/usr/bin/bwrap --die-with-parent --unshare-net --ro-bind / / \
  --dev /dev --proc /proc --tmpfs /tmp \
  --ro-bind /tmp/c1-low-rank-feasibility-check.php /tmp/audit.php \
  --ro-bind /dev/null /var/www/NeoBicycleRaceInformationWeb/.env \
  --tmpfs /var/www/NeoBicycleRaceInformationWeb/storage \
  --tmpfs /var/www/NeoBicycleRaceInformationWeb/bootstrap/cache \
  --clearenv --setenv PATH /usr/bin:/bin \
  /usr/bin/time -v /usr/bin/php -d memory_limit=128M /tmp/audit.php
```

2026-10-16 08:18:34〜08:18:39 JST（内部記録は2026-10-15 23:18:34〜23:18:39 UTC）、PHP8.5.4、5.511713秒、PHP peak8,392,704 bytes、最大RSS41,396 KiB、終了コード0。filesystemはread-only、network namespaceを遮断、設定/復元済み.env/storageを保護。先行した隔離起動は存在しない`/audit.php`をread-onlyルートへ配置できず終了1、スクリプト実行前の失敗だった。配置先だけを隔離tmpfs内へ直し、データや安全ガードは変更していない。

確認済みは上記の選択した保存資料、掲載/実在ファイル、固定親SHA、選択した子seal、検証損失と年別保存入力/予測/教師の件数・race ID順序。未確認は全媒体でのOOF不存在、将来の親モデル再利用全要件、全entryの再監査、補正学習の収束/費用/精度、発走前利用可能性。旧調査を再実施したとも、H1が学習成功したとも記録しない。

リポジトリ内の新規作成は本報告書のみ。文書検証は相対リンク18件、制限/分類状態8件、UTF-8、末尾空白0を確認して成功。`git diff --check`は終了0、tracked差分0、新規ファイルは本書1件。コード・モデル・テスト・設定は無変更なのでPHPUnit/Pintは実行しない。DB、HTTP、Raw、2026、バックアップ探索、旧成果物の削除/移動/変更/再seal、OOF生成、C1再学習、補正fit、予測生成、性能評価、commit/push/PR操作/mergeはすべて未実施。

最終状態は **C1_LOW_RANK_CALIBRATION_FEASIBILITY_READY_FOR_REVIEW**。これは報告書のレビュー準備完了であり、実験製造開始・精度改善・正式採用の承認ではない。
