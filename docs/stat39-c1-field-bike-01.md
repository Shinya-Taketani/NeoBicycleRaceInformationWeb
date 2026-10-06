# STAT-39-C1-FIELD-BIKE-01

## 契約と用途

開始main/origin: `f20947aa028b0a3f4efe3838ee0a0bbf355b1b22` (PR86レビュー後マージ済み)。
branch: `feature/stat39-c1-field-bike-01`。ユーザーの最新指示により、本工程の実装・人工検証・
2022–2025 development学習、Outer2024/2025の比較と独立再現だけを許可する。

- experiment: `STAT-39-C1-FIELD-BIKE-01-v1`
- candidate: `C1_PLUS_FIELD_BIKE`
- feature: `C1_ENTRY_COUNT_BIKE_CATEGORY`
- model: `STAT39-C1-FIELD-BIKE-SEQUENTIAL-POSITION-v1`
- 用途: `LIMITED_DEVELOPMENT_EXPERIMENT_ONLY`
- `historical_as_of_available=false`, `formal_adoption=false`, `live_use_authorized=false`, `2026_access=FORBIDDEN`, `points=null`

元C1の12 STAT (07/08/10/11/12/23/24/26/31/32/39/42)と開催前120日履歴4項目をすべて残し、
追加カテゴリをモデルsignals末尾へ置く17項目比較。STAT01 anchor係数は1。
STAT10除外モデル、全期間最終fit、S/mean6/D/gapは使用しない。旧不採用結果を再試行しない。

## 固定入力と非結果投影

固定2束だけをSourcesで版・manifest・COMPLETE・実読取り子sealと照合する。

| 束 | 絶対パス | manifest SHA-256 |
|---|---|---|
| outcome-free C1 | `/home/shinya/neo-keirin-artifacts/stat35-c1-input-02/run-20260930-054458-8739da4b/result` | `7f4356b93f90203dc727dc95d6215a8d8ce3f44869c4441c3981087ff1099c26` |
| original/保存Outer C1/教師 | `/home/shinya/neo-keirin-artifacts/tactical-history-01-review-fix-20260916-01` | export `4268b801b74b77cfb3a94b64832ff483e9eb5e3221ee8e75ceb467e5a5cf92e6` |

後者のfrozen契約SHAは `5db3b57b08fb8b91dbe6c057b459e518252c454b0a4ff60531c2d671b7ce506a`。
原12+4・型・識別子・順序を元資料のSourceProjectorと厳密照合し、教師も全16項目を投影前に照合する。
`N=count(race.entries)` (出走表5–9人)、`B=entry.bike` (元車番1–9)として `N{N}_B{B}`。
欠番を詰めず `N5_B9` も有効。履歴欠損、実発走、完走、着順による人数の再計算なし。
raw/anchor/stat01_rank/全対象/順序を保持し、historyを5項目へ増やさない。
カテゴリ生成器へrank/status/labelsを渡さず、不正schemaはUNKNOWNへ補正しない。

## 専用カテゴリbinと数値契約

追加項目だけCategoryBinsを使用する。許可schemaは厳密な `N[5-9]_B[1-9]` の最大45種類。
学習期間に観測したカテゴリだけを人数昇順・車番昇順でindex 1から並べる。
kind=CATEGORY、境界NULL、supportは正整数、合計を独立に数えた学習出走者数と照合する。
Layout/Loaderも型・正規形・順序・重複・supportを照合。support0ダミーなし。
評価専用の妥当なカテゴリは非active、対象保持と人数別unseen監査。不正値をUNSEENとして受理しない。
追加groupにsmoothness edgeなし。共有ExternalSortの高cardinality文字列10種類制限と元16binは不変。

具体型を要するLayout/Objective/Optimizer等だけ専用namespaceへ適応。
目的関数・support加重中心化へのユークリッド射影後group prox、L2/group/smoothness、200更新上限、
係数/近接勾配/中心化1e-7、相対目的関数1e-10、reference step1、lambda候補は既存修正版を維持する。
実際の17項目構造からM/G/edgeを算出。strong-to-weak、収束済みだけwarm start、全3順位収束だけOne-SE。
非収束は理由を保存して除外。refit不成立はNOT_EVALUATED/NULL、別lambdaや閾値緩和で回避しない。

## 時系列・比較・実行

Inner A: Train2022→Val2023。Outer24は2022–23 refit後に予測・seal・保存Loader forward照合。
固定C1/候補の順序とsealを確認後に2024教師を開放。
Inner B: Train2022–23→Val2024。共通適格lambdaから選択しOuter25を2022–24でrefit、同じ固定確認後に2025教師開放。
両Outer予測固定後だけ評価する。保存Outer C1はforward照合のみで再学習0。

主比較は候補−C1、補助は候補−STAT01。同じ指標別分母を用いる。
1着/2着/3着/位置Hit@3がPrimary、Supportingとの区別を保持。
Hit@3は公式1–3着が一意なレースの位置一致数/(3×適格レース数)。3連単/集合一致率ではない。
年層別paired race-cluster bootstrap 2000回、seed20260812、Type7、年等重み。
既存incrementalGateを再利用し、CI非劣性 `> -0.0015`、Hit3優越 `> 0`、
年別Hit3 `>= 0`、各年4指標 `>= -0.003`、integrityと独立再現を要求する。
補助PASSを主Gateへ代用せず、表示丸め値で判定しない。

```bash
php -d memory_limit=512M artisan keirin:stat39:c1-field-bike plan
php -d memory_limit=512M artisan keirin:stat39:c1-field-bike execute --output-dir="$RUN/result"
```

保存root: `/home/shinya/neo-keirin-artifacts/stat39-c1-field-bike-01/`。
新規resultだけを許可し、process-localにアプリDB/HTTPを拒否。SQLite/spool/external sortだけ使用。
1 execute内でrun-01/run-02を独立学習・予測・比較し、意味ファイルを実列挙して照合する。
人数別/カテゴリ別件数、学習support、検証/Outer未学習件数、収束/選択lambda、source/code START/ENDを記録する。
各学習JSONL等のsealと独立run一致を保持し、過去の76ファイル数を期待定数にしない。

## 人工回帰と実行前検証

新規57ケースは最終関連回帰に含めて成功 (新規790 assertions)。欠番/型/順序/元16値、
45カテゴリの正規形・support・共有10種類制限、未学習inactiveと人数別監査、保存モデルの改変拒否、
追加係数0の旧C1 forward/NLL一致、17項目有限差分・偏ったsupportでの独立prox最小化、
実M/G/edge正則化、実service経由の教師変更の時系列依存、独立2run、Gate境界を確認した。

- 最終関連回帰: 128M、379 passed / 3,700 assertions。
- 通常全体: 最終PHPで1回、2,828 passed / 26,915 assertions / 既存9 skipped (成功件数に含めない)。
- 変更PHP21ファイル構文、限定Pint --test成功。
- 独立128M人工試験: 50,000レース/250,000出走、113,450,000 bytes (>100MiB)、ピーク10,485,760 bytes、exit0。
- Objective/Optimizerは旧修正版Stat17C1Comparisonとnamespaceだけの相違。定数緩和・共有計算変更なし。

検証証跡と実行コードsealの保存先:
`/home/shinya/neo-keirin-artifacts/stat39-c1-field-bike-01/run-20261005-214221-c370ee05/`。
実行ディレクトリ名とrunner時刻はUTC、本文の工程日はJST。PHPは今回の実環境8.5.4で実施。

## 実測結果と判断

1 execute内の独立2runを完了。主Gateは `NOT_PASSED`、今回の固定カテゴリ追加方式は不採用・元C1維持。
2着は改善方向だが、1着・3着・位置Hit@3は両年で悪化した。補助STAT01 Gateの
`PASS / GO_TO_FREEZE` は主比較の未達を置き換えず、正式freeze・C1置換の許可にも読み替えない。

### 年別分子・分母・率と差

「候補」はC1_PLUS_FIELD_BIKE。差は候補−各基準、単位pp (percentage points)。
率/差は表示用丸めで、判定は保存JSONの未丸め値を使用した。

| 年 | 指標 | 候補 | C1 | STAT01 | 差対C1 (pp) | 差対STAT01 (pp) |
|---|---|---|---|---|---|---|
| 2024 | 1着 | 10307/25158 (40.9691%) | 10424/25158 (41.4341%) | 9713/25158 (38.6080%) | -0.4651 | +2.3611 |
| 2024 | 2着 | 6129/25106 (24.4125%) | 6041/25106 (24.0620%) | 5876/25106 (23.4048%) | +0.3505 | +1.0077 |
| 2024 | 3着 | 4495/25094 (17.9126%) | 4701/25094 (18.7336%) | 4436/25094 (17.6775%) | -0.8209 | +0.2351 |
| 2024 | 位置Hit@3 | 20854/75120 (27.7609%) | 21091/75120 (28.0764%) | 19959/75120 (26.5695%) | -0.3155 | +1.1914 |
| 2025 | 1着 | 9646/24789 (38.9124%) | 9886/24789 (39.8806%) | 9336/24789 (37.6619%) | -0.9682 | +1.2506 |
| 2025 | 2着 | 5801/24727 (23.4602%) | 5743/24727 (23.2256%) | 5714/24727 (23.1083%) | +0.2346 | +0.3518 |
| 2025 | 3着 | 4448/24739 (17.9797%) | 4677/24739 (18.9054%) | 4369/24739 (17.6604%) | -0.9257 | +0.3193 |
| 2025 | 位置Hit@3 | 19825/73989 (26.7945%) | 20241/73989 (27.3568%) | 19349/73989 (26.1512%) | -0.5622 | +0.6433 |

### 年等重み差とpaired 95%CI

| 指標 | 対C1: 差 [95%CI] (pp) | 対STAT01: 差 [95%CI] (pp) |
|---|---|---|
| 1着 | -0.7166 [-0.9548, -0.4843] | +1.8058 [+1.5302, +2.1027] |
| 2着 | +0.2925 [-0.0308, +0.6047] | +0.6798 [+0.3949, +0.9857] |
| 3着 | -0.8733 [-1.2041, -0.5650] | +0.2772 [-0.0036, +0.5638] |
| 位置Hit@3 | -0.4389 [-0.6336, -0.2544] | +0.9174 [+0.7263, +1.1129] |

主Gate: non_inferiority=false (1着/3着/Hit3のCI下限が -0.0015を超えない)、
temporal=false (両年Hit3差が負、各年の主指標低下も -0.003を下回る)、
superiority=false (Hit3 CI下限が0を超えない)、integrity=true。
2着のCIは0をまたぐため、確実な改善とは表現しない。Supportingの全値・Gate別の判定は保存比較JSONに保持する。

### 全対象・カテゴリと学習support

原16値/型/順序・raw/anchor/stat01_rankと全対象を厳密照合した。
合計99,669レース/706,051出走。正常カテゴリNULL=0。年別観測カテゴリは35種類。

| 年 | レース | 出走 | N5レース | N6 | N7 | N8 | N9 |
|---|---|---|---|---|---|---|---|
| 2022 | 24394 | 170835 | 715 | 2768 | 18691 | 165 | 2055 |
| 2023 | 25197 | 179007 | 335 | 1378 | 21084 | 124 | 2276 |
| 2024 | 25212 | 179089 | 324 | 1360 | 21165 | 113 | 2250 |
| 2025 | 24866 | 177120 | 233 | 1121 | 21116 | 147 | 2249 |

今回の固定入力では各NのB1～BNのカテゴリ件数がそれぞれ上表のレース数と一致した。
schema上で有効な残り10組合せは未観測であり、0%の実測成績やsupport0のparameterを作っていない。
入力のbike<=N制約へ変更してはいない (人工N5_B9も有効)。

| fold | 学習出走/support合計 | N5 support | N6 | N7 | N8 | N9 | カテゴリ | M/G/数値edge |
|---|---|---|---|---|---|---|---|---|
| Inner A (2022) | 170835 | 3575 | 16608 | 130837 | 1320 | 18495 | 35 | 171/17/116 |
| Inner B (2022–23) | 349842 | 5250 | 24876 | 278425 | 2312 | 38979 | 35 | 171/17/116 |
| Outer24 (2022–23) | 349842 | 5250 | 24876 | 278425 | 2312 | 38979 | 35 | 171/17/116 |
| Outer25 (2022–24) | 528931 | 6870 | 33036 | 426580 | 3216 | 59229 | 35 | 172/17/117 |

各カテゴリの正supportと固定順はlayout/監査JSONに保存。
検証2023/2024、Outer2024/2025はいずれも未学習カテゴリ出走/レース0件、人数別でも0件。
カテゴリgroupのsmoothness edgeは全foldで0。人数別成績へ対象を絞って主Gateを置換していない。

### 収束・独立再現・実行証跡

両runのInner A/Bとも全3順位収束した候補はlambda=1だけ。
その他7候補はNUMERICALLY_NON_CONVERGEDとして理由・200更新時診断を保存してOne-SEから除外した。
例えばInner Aのlambda=0.1の係数変化は9.366656958248454e-7で、1e-7を満たさない。
選択lambdaはOuter24/25とも1。強い正則化へ結果を合わせる変更や閾値緩和は行っていない。
refitのaccepted updatesはOuter24の順位1/2/3が64/70/91、Outer25が79/73/91、全順位CONVERGED。

保存基準Outer C1は2024/2025のforward完全一致・再学習0。
独立run-01/run-02の投影/学習資料/bin/support/候補診断/選択/係数/予測/寄与/CI/Gateは、
実列挙78意味ファイルのサイズ・SHAが一致。教師は各年の両予測seal・順序照合後にだけ開放した。
source 33ファイル・依存コード356ファイルのSTART/END不変、公開前seal再検証も成功。

- 実行: 2026-10-06 06:48:32～10:46:05 JST (ログはUTC)、14,253.197839秒 (3時間57分33秒)。
- 実処理512Mのpeak: 35,651,584 bytes (34MiB)、exit0、stderr0 bytes。
- 結果: `/home/shinya/neo-keirin-artifacts/stat39-c1-field-bike-01/run-20261005-214221-c370ee05/result/`
- manifest: 136,269 bytes、SHA-256 `9df0ecc2723c5eb2eac9bf1529152ab4defdf68d3acc2d123f115cb4e95d8d77`。
- 小型報告は同じ実行ディレクトリの `report/` と `STAT-39-C1-FIELD-BIKE-01-review.zip`。
  年別/年等重み比較・category定義/件数/support・収束/再現/実行/検証ログとGit差分を含む。
  全学習JSONL/全予測/大容量人工入力はZIPへ重複コピーしない。

## 制限と停止状態

未コミットでコード・結果レビュー待ち。次項目探索、別カテゴリ統合/分割、対象限定、再試行へ自動移行しない。
実行成功やテスト成功は精度向上を意味しない。今回は技術的に評価成立、主Gate未達として不採用。
PR85 score gap P3の等decimal/binary64残差は未解決のまま保持し、今回はProjectorを使用・修正しない。
参考2資料の原文は未読。ユーザー指示にあるサンプル勝率/相関/S閾値/物理比率等を係数や事前確率へ転用しない。
2022–2025は使用済みdevelopment corpus。割当制度・能力・ライン・バンク・気象の完全補正、車番因果効果、
実運用性能、STAT39全体の完成は未証明。結果を見てカテゴリ分割/統合、7/9人限定、別特徴・再試行へ進まない。
主Gate未達なら今回方式は採用せず元C1を維持。通過してもレビュー候補に限り、正式置換・LIVE・2026は行わない。
