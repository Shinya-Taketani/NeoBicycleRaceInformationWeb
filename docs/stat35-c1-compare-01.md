# STAT-35-C1-COMPARE-01

## Contract

2026-09-30、PR #77レビュー後マージ。開始main/origin:
`b8596e3bc621703eadd89fba42101be8b70dde23`、cleanから `feature/stat35-c1-compare-01` を作成。
今回のみ固定INPUT-02のdevelopment比較を許可。原本training_evaluation_authorized=falseは書き換えない。
historical_as_of_available=false、observed_at=null、既知保留1,003件とNULLを維持する。

C1は旧修正版run-01のOuter 2024/2025。C2は同じ12 STAT+戦法4値に別sidecarのSTAT35_MEAN6を追加。
anchor係数1、17項目の全3順位係数を各学習期間で新規推定する。旧C1の再学習は0回。
最終C1モデル（2022〜2025全fit、SHA e3cc1f7f10af60bb22f97a2172ca52ef2c09a3393222ee46c25619de752d43a1）は使わない。

INPUT-02正本:
`/home/shinya/neo-keirin-artifacts/stat35-c1-input-02/run-20260930-054458-8739da4b/result`
manifest SHA `7f4356b93f90203dc727dc95d6215a8d8ce3f44869c4441c3981087ff1099c26`。
基準root: `/home/shinya/neo-keirin-artifacts/tactical-history-01-review-fix-20260916-01`。
export manifest SHA `4268b801b74b77cfb3a94b64832ff483e9eb5e3221ee8e75ceb467e5a5cf92e6`、
frozen contract SHA `5db3b57b08fb8b91dbe6c057b459e518252c454b0a4ff60531c2d671b7ce506a`。
実ファイルから確認した値であり、子sealはこれらmanifestから取得する。

## Implementation

専用namespace `Stat35C1Comparison`、Command `keirin:stat35:c1-compare plan|execute --output-dir=...`。
Sourcesは固定pin/COMPLETE/版/旧契約/子sealを確認。DatasetはC1との非結果値・型・year/race/entry/bike順を照合。
予測readerに教師値を渡さず、2024/2025の教師readerは双方予測sealと出走集合照合後だけ開く。
2022/2023は保存学習原本からrank/statusだけを取り出す。2024/2025のlabels内の入力は正本へ置き換えず照合する。
原本byte hashの検査は正解の学習開放ではない。

旧TacticalHistoryのfinal/型制約を維持するため、Layout/LayoutBuilder/Objective/Optimizer/Trainerを独立型へ適応。
数値目的/勾配/射影/prox/line search/200 accepted updates/収束閾値は不変。
16項目時と17番目非active時の数値一致、17項目勾配、偏りsupport近接最適性を人工試験する。
EffectBinBuilder、DTO、One-SE、確率、E06 decoder、metric、bootstrap、Gateは旧純粋処理を再利用。
モデル版 `STAT35-C2-SEQUENTIAL-POSITION-v1`、solver参照は旧修正版
`TACTICAL-HISTORY-CONSTRAINED-EUCLIDEAN-FISTA-v2`。旧正式クラスは変更しない。

Inner A=2022→2023、Outer2024 refit=2022/2023。予測固定後2024教師開放。
Inner B=2022/2023→2024、Inner A/B共通収束候補でOne-SE、Outer2025 refit=2022〜2024。
strong-to-weak、収束済みのみwarm start、固定8候補、選択refit非収束は停止。
NULLはbin/supportから除外するだけで出走者は残す。有効0と部分窓値は保持。all-NULL追加groupは非active。

## Evaluation And Integrity

主比較C2-C1、補助C2-STAT01。4主指標は1着・2着・3着・位置Hit@3。
WINNER/POSITION_1を二重Gate加算しない。Hit@3は公式1〜3着一意のレースだけ位置一致数/(3×レース数)。
SupportingのMAP/marginalとPrimaryを区別。2000回、seed20260812、Type7、年層別paired race-cluster、年等重み。
選手/開催を跨ぐ相関まで補正するものではない。
追加Gateは4下限>-0.0015、Hit@3下限>0、各年Hit@3>=0、各年4差>=-0.003、integrity/独立再現。
分母0/非収束/不整合を0差や性能不合格へ変換しない。旧C1 PASSを流用しない。Gate通過は正式採用ではない。

初回と別runの独立再学習を各1回。モデル/bin/support/選択/診断/予測/寄与/CIのbyte/semantic一致を確認。
同run内でInner Aを再fitしない。失敗は診断を残しCOMPLETEを公開しない。旧成果物は上書きしない。
固定source/code START/END照合と公開前子seal検査を実施する。
アプリDB/HTTPはprocess-localで例外拒否、ローカルSQLite/外部sort/spoolのみ許可。
保存rootは `/home/shinya/neo-keirin-artifacts/stat35-c1-compare-01/`。独立メモリ試験128M、実処理512M。
DB、Raw、Migration、入力再生成、旧C1再学習、追加探索、2026/LIVEは対象外。

## Verification And Execution

### Tests

- 新規18ケース/194 assertions: 独立execute、実service時系列変更、ID/順序/余分な結果field/未知版/改変/上書き拒否、0/NULL保持、教師開放、途中失敗証拠、Gate境界、独立128M、旧16項目数値一致・17項目勾配/近接最適性。
- 最終関連試験: `php -d memory_limit=128M vendor/bin/phpunit --filter 'Stat35C1Comparison|TacticalHistory' --no-progress`、132 tests/856 assertions、成功、7.588秒。
- 最終コードの通常全体試験1回: `php artisan test`、2288 passed、9 existing skipped、19449 assertions、0 failure、168.63秒。既存PostgreSQL専用skipは成功扱いにしない。新規skip/削除/緩和なし。
- 新規PHP17ファイルへの限定 `vendor/bin/pint --test` と各 `php -l` が成功。
- 独立子processに `memory_limit=128M` を実適用し、100MiB超の入力、50,000レース/250,000出走のstreamingを検証。共有親processのpeakで合否を決めない。
- 2024教師変更でもOuter2024不変、2025教師変更でも両Outer不変、正式開放後の2024変更はOuter2025へ影響、を実学習serviceの人工回帰で確認。
- DB/HTTP例外拒否、2026/未開放教師拒否、途中失敗時COMPLETE未公開。人工DBはSQLite testing環境で、本番接続なし。

### Execution

保存root（以下R）:
`/home/shinya/neo-keirin-artifacts/stat35-c1-compare-01/run-20260929-215427-3b1776ba`

ディレクトリ名・実行JSONの時刻はUTC。実行開始/終了はJSTで2026-09-30 06:58:22/11:59:43。
所要18,080.739秒、PHP終了コード0、peak 33,554,432 bytes（32MiB）、stderr空。
plan成功後、下記executeを1回起動し、その中で初回run-01と独立run-02を各1回だけ実行した。

```bash
php -d memory_limit=512M artisan keirin:stat35:c1-compare execute \
  --output-dir=/home/shinya/neo-keirin-artifacts/stat35-c1-compare-01/run-20260929-215427-3b1776ba/result
```

R/runner.phpがprocess-localのtesting/SQLite/no-config-cache環境とstdout/stderr/終了コードを記録。
`.env`・共有cache・本番設定は変更しない。C1再学習0、C2追加再試行0。
開始/終了HEADは `b8596e3bc621703eadd89fba42101be8b70dde23`。未コミットの実行コードを別sealで固定。
事前code-frozen-manifest SHA `81f90fb77fa631df5153b30dd1ab7a557488728202f361cdf11e868bd2aa6733`。
source 39ファイル・直接依存を含むcode 354ファイルのSTART/END不変と公開前子seal照合が成功。

入力照合（数値には有効0を含む）:

| 年 | レース | 出走 | 数値 | NULL | 有効0 |
|---|---:|---:|---:|---:|---:|
| 2022 | 24,394 | 170,835 | 162,346 | 8,489 | 239 |
| 2023 | 25,197 | 179,007 | 175,010 | 3,997 | 75 |
| 2024 | 25,212 | 179,089 | 174,950 | 4,139 | 53 |
| 2025 | 24,866 | 177,120 | 173,413 | 3,707 | 98 |
| 合計 | 99,669 | 706,051 | 685,719 | 20,332 | 465 |

year/race/entry/bikeの集合・順序、C1の非結果値・型、NULL/部分窓値を維持。入力再生成なし。
既知保留1,003件の原本監査を維持し、今回のモデル特徴には監査列を取り込まない。
保存C1モデル/予測forwardは両年一致。各runでC2予測seal・双方cohort照合前にOuter教師を読まない。
2024教師はOuter2024固定後にInner B/Outer2025へ使い、2025教師は両Outer固定後の評価のみ。

### Selection And Convergence

両runでInner A/Bとも、lambda=1と0.1だけ全3順位CONVERGED。
0.01/0.001/0.0001/0.00001/0.000001/0はPOSITION_1で200 accepted updates時点の非収束となり除外。
診断を `C2-inner-*/candidate-*.json` に保持。非収束を性能不合格や0差へ変換しない。
One-SE選択はOuter2024/2025とも0.1（強制ではなく選択結果）。
Inner Aのpoint loss/SEは0.1が1.595997433058456/0.0032216394781654136、1が1.6162987364951846/0.003339394385851337。
Inner A/B合成は0.1が1.5927006229919767/0.0022766605292892056、1が1.6125917816169284/0.0023701396802027555。

| Outer | 学習年 | M（順位別係数数） | G | edge | 全3順位係数数 | 受理更新 P1/P2/P3 | 状態 |
|---|---|---:|---:|---:|---:|---|---|
| 2024 | 2022/2023 | 146 | 17 | 125 | 438 | 154/88/97 | 全順位CONVERGED |
| 2025 | 2022/2023/2024 | 147 | 17 | 126 | 441 | 113/119/119 | 全順位CONVERGED |

2024の最大近接勾配残差4.231478192839866e-8、最大中心化残差2.2551405187698492e-17。
2025は同7.875478955088333e-8、5.854691731421724e-18。固定reference step=1、閾値/上限は不変。
各bin境界・support weight・係数・目的関数・適格/除外件数は各layout/modelと報告束のselection-convergenceに保存。

### Performance

率は百分率、差はパーセントポイント（pp）。WINNER_HIT_AT_1はPOSITION_1_ACCURACYと同一の主指標。
表の表示値だけ丸め、評価JSONは未丸め値を保存。独立再現を追加標本にしない。

| 年 | 主指標 | C1 | C2 | C2-C1 | 共通分母 |
|---|---|---:|---:|---:|---:|
| 2024 | 1着 | 41.434136% | 41.593131% | +0.158995 pp | 25,158 |
| 2024 | 2着 | 24.061977% | 24.185454% | +0.123476 pp | 25,106 |
| 2024 | 3着 | 18.733562% | 18.873037% | +0.139476 pp | 25,094 |
| 2024 | Hit@3 | 28.076411% | 28.216187% | +0.139776 pp | 75,120 |
| 2025 | 1着 | 39.880592% | 39.941103% | +0.060511 pp | 24,789 |
| 2025 | 2着 | 23.225624% | 23.245845% | +0.020221 pp | 24,727 |
| 2025 | 3着 | 18.905372% | 18.852823% | -0.052549 pp | 24,739 |
| 2025 | Hit@3 | 27.356769% | 27.360824% | +0.004055 pp | 73,989 |

年等重み差と年層別paired race-cluster 95%CI:

| 比較 | 指標 | 差 | 95%CI |
|---|---|---:|---|
| C2-C1 | 1着 | +0.109753 pp | [+0.033411, +0.195174] pp |
| C2-C1 | 2着 | +0.071849 pp | [-0.056620, +0.202884] pp |
| C2-C1 | 3着 | +0.043463 pp | [-0.080519, +0.161107] pp |
| C2-C1 | Hit@3 | +0.071916 pp | [-0.003468, +0.144852] pp |
| C2-STAT01 | 1着 | +2.632185 pp | [+2.289666, +2.983336] pp |
| C2-STAT01 | 2着 | +0.459096 pp | [+0.057929, +0.847094] pp |
| C2-STAT01 | 3着 | +1.193977 pp | [+0.816154, +1.564133] pp |
| C2-STAT01 | Hit@3 | +1.428169 pp | [+1.182713, +1.680563] pp |

追加効果Gate **NOT_PASSED**: non_inferiority=true、temporal=true、integrity=true、superiority=false。
Hit@3 CI下限は率差 -0.000034679712707947724 であり、strict >0を満たさない。
点推定がプラスでも「STAT-35の追加効果が採用基準を満たした」とは判断しない。
補助STAT01 Gateは **PASS / GO_TO_FREEZE**（全8内訳true）。これは既存Gateの出力名であり、正式freeze/採用の許可ではない。
Supporting全指標、tie/decoder診断、両比較の未丸め値は `result/comparisons.json` に保存。

### Reproduction And Artifacts

各runの76ファイル（モデル/bin/support/選択/診断/予測/教師アクセス監査/寄与/CI等）がbyteおよびSHAで完全一致。
日時・PID・出力絶対パス・経過時間のログは意味上の比較外。保存モデル読込み後のforward一致も両run/両年成功。
`result/reproduction.json` とmanifestの2run一覧で追跡できる。

- result manifest: 138,599 bytes、SHA `8fc40000c93bbc604caac37cf21e60519f26f28c4e77cf9284fa31403f5487c8`、COMPLETEと一致。
- C2 2024 model: `ef0776e5303cd8d863a5454836a5118a75c3ef63cce4f2e06b523b22df03d005`。
- C2 2025 model: `de788e9d336a08c5d2ee5c8d5e10838ada6336b2ea70bf849ac8b16a09bd0669`。
- C2 2024 predictions: `4e9a08cbe047816c194593c03b905e4aa28809da30e04bddd5b789867a588b4b`。
- C2 2025 predictions: `4a95826726acf8a39be36a07874fd8e4e07a0476e79c05763f3e7c3f40b43a32`。
- sourceごとのpath/bytes/SHAは `result/frozen.json` / `result/manifest.json`。教師と基準C1を別sourceとして記録。
- 実行証跡: R配下 `plan-execution/`、`focused-execution/`、`full-suite-execution/`、`pint-execution/`、`lint-execution/`、`freeze-execution/`、`comparison-execution/`。
- 小型共有束: R配下 `STAT-35-C1-COMPARE-01-review.zip`。契約・比較表・診断・再現・ログ・コードseal・差分を含み、全JSONL/全予測は重複添付しない。実在確認/サイズ/SHAは `review-zip.json` に記録。

技術状態は **COMPLETED_NOT_ADOPTED**。実学習/比較/再現は成立したが、主比較の追加効果Gateは未達。
旧C1保持、正式採用未判断、DB/HTTP/Raw/Migration/入力再生成/2026/LIVEなし。未コミットのコード・結果レビュー待ちで停止する。
