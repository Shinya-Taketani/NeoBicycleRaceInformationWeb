# STAT35-TREND6-P1-INCREMENTAL-01

## Scope

開始clean main / origin/main: `d19f5656b20b45aaa6302d9ce63f8d8d3968eb2d`（PR102 merge）。
作業branch: `feature/stat35-trend6-p1-incremental-01`。
今回の最新ユーザー許可は、固定2022〜2025資料に限定した18項目C3の学習・Outer比較・独立2runだけ。
既存C1、PR92のC2構成、最終学習モデル、数値契約、正式成果物は変更しない。
用途は `LIMITED_DEVELOPMENT_EXPERIMENT_ONLY`。`historical_as_of_available=false`、正式採用・2026・LIVE利用は未承認。

| 比較 | 正本 / 意味 |
|---|---|
| A | 元C1 run-01の保存Outer予測。既存12 STAT＋決まり手4値。再学習なし |
| B | PR92 run-01の保存Outer `C2_P1_C1_P23_COMPOSITION`。最終fitを代用しない |
| C | 新C3のP1 utility＋元C1のP2/P3 utilityから一つの逐次分布と既存E06を計算 |
| C−B | trend追加の増分効果。最重要の採否比較 |
| C−A | 元C1に対する総合差 |
| B−A | PR92の既存測定・paired CIとの再照合。新しい改善実績ではない |

## Input Definition And Evidence

固定INPUT-02のmean6と原C1の16値を保存値・型・順序のまま維持する。
INPUT-02のseal済みauditに選択6開催と正確な値はあるが、履歴各行のclass/計測定義を独立照合するため、
その親manifestが参照するseal済みplayer-meetingsを追加で読む。業務DB/Rawで補完しない。

| 資料 | manifest SHA-256 |
|---|---|
| INPUT-02 | `7f4356b93f90203dc727dc95d6215a8d8ce3f44869c4441c3981087ff1099c26` |
| STAT35-PLAYER-HISTORY PR73修正版 | `844440cb08b06b0e69195a6384689bc545e63806ff85237ca36a81f5dd08507e` |
| 元C1 inputs-v2 | `d5158a7671f8c453afc77dccc1ab0b089c940198f58f388c038ad0d4fad684cf` |
| PR92保存構成 | `f1c1687aed2aa6cf9540c6f351e8017c240f096177e3206af775d936e3d92264` |

Sourcesは親manifest/COMPLETE/bodyとPR92のinput/model/prediction/teacher参照を検証する。
原資料は親manifestのsealを使い、存在しない個別JSONL manifestの作成を要求しない。
教師ファイルの事前hash確認はバイト完全性だけであり、評価年のrank/statusを予測前に解析しない。

`STAT35_TREND6` は凍結済み `History::calculate()` の
`recent3_minus_previous3_meeting_percentile` を `Exact` の規則でfloat化する。
本人・class・計測定義・sourceが一致し、履歴終了日が対象開始日よりstrictに前の履歴だけ。
対象開催を除外し、終了日DESC/開始日DESC/IDの既存順で最大13候補を取り、既存の重複期間検査を維持する。
NULLを除外する前に6観測開催を選ぶ。6未満/有効値不足/文脈・順序ブロックはNULL、理由を保存する。
有効0.0はNULLにしない。全出走で旧windowとmean6を厳密照合し、不一致は学習前に停止する。
欠損による出走者の除外はない。

入力生成はディスクSQLite索引＋逐次JSONLで行い、生成後も全年の原値・対象・順序・sealを照合する。
2026年入力は人工テストで拒否し、実際の2026資料は読み込まない。

## Frozen Training And Temporal Boundary

- 18項目: 原C1 16項目、`STAT35_MEAN6`、`STAT35_TREND6`。anchor係数1を維持。
- 既存FISTA v2の目的関数、中心化prox、line search/restart、200更新上限、収束閾値は不変。
- 固定8lambdaのstrong-to-weak順、収束候補だけのwarm start/One-SE適格性、Outer非収束fail-closedを維持。
- bin/欠損inactive/supportは各foldのtrainingだけから生成。validation/Outerからfitしない。
- Inner A: Train2022 / Validate2023。Outer2024: Refit2022/23。
- 2024のC3モデル/予測・構成予測・元C1/保存Bの順序付きcohortをseal照合してから2024教師を開放。
- Inner B: Train2022/23 / Validate2024。A/B共通収束集合の既存One-SEを使用。Outer2025: Refit2022〜2024。
- 2025は同じseal/cohort検証完了後に教師を開放。両年の予測固定後に精度を計測する。
- C3の全3順位を既存C2と同条件で学習・選択するが、構成へ使うのはP1だけ。C1 P2/P3は保存utility。
- 保存C3モデルを読込器で再読込し、特徴量からのforwardが保存予測と厳密一致することを全対象で検証。
- 独立run-01/02は新しいlayout/model/cacheで学習。意味的JSON/JSONLを比較し、再実行を新標本に数えない。

既存クラスのfinal/type契約を維持するため、今回専用namespaceに数値互換のtyped adapterを置く。
Optimizer/Objective/LayoutBuilderの計算は旧C2とnamespace以外同一で、回帰テストがsourceと実数値を検証する。
bin/validation/metric spoolは今回専用の保持版を使い、旧削除処理を変更せず永久削除を避ける。
One-SE/paired bootstrapの計算本体・乱数・Type7は凍結版と同じで、差は専用spool型への参照だけ。
spoolは自身の保存領域に残し、推測したpathの移動・削除はしない。

## Evaluation Contract

同一race/entry/bike/teacher/分母でC−B、C−A、B−Aを比較する。
POSITION_1/2/3_ACCURACY、POSITION_HIT_RATE_AT_3、Primary完全順序一致、Supporting MAP完全順序一致、
TOP3集合/coverage等の既存11指標、年別の変更/改善/悪化・両的中/片方のみ/両不的中/除外を保存する。
Hit3は公式上位3位置が一意なraceの位置一致数/(3×適格race数)。3連単完全一致率とは別。
Primaryの完全順序一致にはPrimaryを投影して同じEvaluatorを使い、MAP指標へ混同しない。

CIは2000反復、seed20260812、年層別paired race-cluster、Type7、年等重み。
FULL/BASELINEを別々にbootstrapしてCI端点を減算しない。
既存incremental GateのNI `CI lower > -0.0015`、Hit3優越 `CI lower > 0`、年別Hit3 `>=0`、
年別各位置 `>=-0.003`、独立再現/資料完全性条件は変更しない。
主GateはC−B。C−AのPASSを代用しない。Primary完全順序悪化はGateとは別に報告する。
2024/2025は多重の仮説探索済みdevelopment corpusであり、独立未使用holdoutや多重探索補正済みCIとは呼ばない。
否定結果でも窓/solver/lambda/Gateを調整して自動再試行しない。正式採用は常にfalse。

## Execution And Protection

専用証跡root:
`/home/shinya/neo-keirin-artifacts/stat35-trend6-p1-incremental-01/run-20261011-xhqtu0fq/`。
最終実行コードは同root `result-outer-parent-verified/frozen.json` にSTART sealを記録し、END/公開前にも照合する。
本物の.env/storage/bootstrap cacheを隠し、必要な旧資料をread-only bindしたbwrap、ネットワーク/DB socket遮断。
人工Desktop/Trash、専用rootと一時SQLite/spoolだけが書込み対象。業務DBはCommandでも例外拒否。
process-local testing環境で、認証情報を記録しない。原本・旧成果物の書込み/削除/移動なし。

```bash
/usr/bin/php8.5 -d memory_limit=128M artisan keirin:stat35:trend6-p1 plan
/usr/bin/php8.5 -d memory_limit=128M artisan keirin:stat35:trend6-p1 execute \
  --output-dir=/home/shinya/neo-keirin-artifacts/stat35-trend6-p1-incremental-01/run-20261011-xhqtu0fq/result-outer-parent-verified
```

実際には専用rootのrunner.phpが上記argvを隔離環境で起動し、stdout/stderr・PID・時刻・実exitを保存する。
既存出力へ再実行しない。初回の個別manifest要求による学習前失敗と、旧spool destructor発見後の学習前停止を、
`execute` / `execute-parent-seal-fixed` と不完全resultに保持する。数値非収束や性能否定による再試行ではない。
`execute-final` はInner AとOuter2024が収束した後、構成用INPUT02の個別manifest要求でexit1となった。
2024教師は未開放、精度はNOT_EVALUATED。61分46秒のログ・モデル・予測とFAILEDをそのまま保持する。
親sealのみの入力を実serviceへ通す回帰は修正前1 error/4 assertions、修正後1 success/8 assertions。
構成の入力readerだけを親seal対応にし、終了時のinput bytes/SHA照合を維持した。旧資料・数値契約は不変。
保存済み今回fitによる25,212レースの読取り専用構成確認もexit0/26MiB、教師未開放で成功。
最終コードの新規2runは `execute-outer-parent-verified` に記録し、途中fitを新runへ流用しない。

## Verification

- 新規36 tests / 243 assertions成功。PHP8.5.4 / PHPUnit12.5.31、PHPUnit本体128M、48.5MiB、exit0。
- trend正負/0/NULL枠、対象・未来・重複期間、本人/class/定義、親sealのみの保存形式、原mean不変、18型順序、
  2026/結果field/集合不一致拒否、保存・独立2run、2024/2025 outcome変更の時系列依存、code/model drift、Gate境界を確認。
- 独立128M子プロセスで100MiB超の人工入力50,000race/250,000entryを逐次処理、実exit0。
- 凍結数値source一致、旧16値のbit-exact fit/forward、偏るsupportの独立1D prox最適性、有限差分を確認。
- 専用validation/metric spoolは2000反復の選択/CIと再現を確認し、destructor後もbytes/SHAが不変。
- 安全な純計算関連22 tests / 333 assertions成功（History、E03 objective/probability、E05 metrics、E06 decoder）。
- 新規PHP23ファイル構文/限定Pint成功。テスト削除/緩和/新規skipなし。
- 通常全体テストは未実行。旧suiteには永久削除するtearDown/spool destructorが残るため、今回の安全条件と両立しない。
  別工程として旧cleanupの限定修正が必要で、今回それらを一括変更・無効化しない。旧全体成功記録を今回へ読み替えない。

## Measured Status

入力生成とDataset照合が完了。以下は今回の実測であり、旧記録の転記ではない。

| 年 | races | entries | trend数値 | trend NULL | 有効0 | 旧mean6数値 |
|---|---:|---:|---:|---:|---:|---:|
| 2022 | 24,394 | 170,835 | 111,489 | 59,346 | 478 | 162,346 |
| 2023 | 25,197 | 179,007 | 148,156 | 30,851 | 758 | 175,010 |
| 2024 | 25,212 | 179,089 | 151,988 | 27,101 | 946 | 174,950 |
| 2025 | 24,866 | 177,120 | 154,632 | 22,488 | 1,209 | 173,413 |
| 合計 | 99,669 | 706,051 | 566,265 | 139,786 | 3,391 | 685,719 |

全出走で旧選択window/mean値が一致し、4年のcohort hash・人数・旧mean数値/NULL件数も一致した。
trend NULLの排他的理由は文脈/順序ブロック8,603、6観測未満67,439、有効値不足63,744。
旧source50ファイルとcode394ファイルをSTART sealし、生成入力4ファイルを加えたsource54ファイルを
教師開放前・END・公開前に検証した。元C1/PR92/INPUT-02/履歴の不変性確認が成功した。

最終実行は2026-10-11 18:55:05〜2026-10-12 00:18:40 JST、19,414.975秒（5時間23分35秒）、exit0。
PHP memory_limit=128M、最大37,748,736 bytes（36MiB）、stderrは0 bytes。
同一条件の独立run-01/02で各年の全予測を生成・評価し、77意味的成果物がbytes/SHAまで一致した。
各runで保存モデルの再読込forwardを全対象と照合し、構成確率・Primaryを再計算した。
最終manifestは171ファイルをsealし、COMPLETEと一致する。

| 学習用途（各run） | races | entries |
|---|---:|---:|
| Inner A training 2022 | 24,394 | 170,835 |
| Inner A validation 2023 | 25,197 | 179,007 |
| Outer 2024 refit 2022/23 | 49,591 | 349,842 |
| Inner B training 2022/23 | 49,591 | 349,842 |
| Inner B validation 2024 | 25,212 | 179,089 |
| Outer 2025 refit 2022〜24 | 74,803 | 528,931 |
| Outer予測・評価 2024/25 | 50,078 | 356,209 |

両runともInner A/Bの固定8候補中lambda=1/0.1が収束し、他6候補はNUMERICALLY_NON_CONVERGEDとして
One-SE対象外になった。選択lambdaは両Outerとも0.1で、全Outer refitが収束。
200更新上限・収束閾値・窓・grid・Gateは一切緩めていない。
新C3のOuterモデルは2年×2runの4件（各3順位）。元A/Bの再学習は0回。
2runの物理予測100,156件を独立標本として重複計上せず、評価母集団は50,078レースのまま。

### Annual Accuracy

率は%、分子/分母を併記する。A/Bは保存されたOuter予測を再利用した同一対象の比較。
Primary完全順序一致はPrimary三連単であり、Supporting MAPと区別する。

| 年 / 候補 | 1着 | 2着 | 3着 | 位置Hit@3 | Primary完全順序一致 |
|---|---|---|---|---|---|
| 2024 A | 10424/25158 (41.434136%) | 6041/25106 (24.061977%) | 4701/25094 (18.733562%) | 21091/75120 (28.076411%) | 1195/25040 (4.772364%) |
| 2024 B | 10464/25158 (41.593131%) | 6063/25106 (24.149606%) | 4709/25094 (18.765442%) | 21159/75120 (28.166933%) | 1197/25040 (4.780351%) |
| 2024 C | 10484/25158 (41.672629%) | 6072/25106 (24.185454%) | 4703/25094 (18.741532%) | 21182/75120 (28.197551%) | 1199/25040 (4.788339%) |
| 2025 A | 9886/24789 (39.880592%) | 5743/24727 (23.225624%) | 4677/24739 (18.905372%) | 20241/73989 (27.356769%) | 1179/24663 (4.780440%) |
| 2025 B | 9901/24789 (39.941103%) | 5747/24727 (23.241800%) | 4673/24739 (18.889203%) | 20254/73989 (27.374339%) | 1173/24663 (4.756112%) |
| 2025 C | 9890/24789 (39.896728%) | 5724/24727 (23.148785%) | 4678/24739 (18.909414%) | 20223/73989 (27.332441%) | 1170/24663 (4.743948%) |

2024の位置1/2/3除外は54/106/118、ordered除外172。2025は77/139/127、ordered除外203。
Hit@3分母は3×25,040、3×24,663。順序不確定のレースを黙って0点にしない。

| 年 / 候補 | Supporting MAP完全順序一致 | Supporting MAP Top3集合一致 |
|---|---|---|
| 2024 A | 1236/25040 (4.936102%) | 3849/25212 (15.266540%) |
| 2024 B | 1241/25040 (4.956070%) | 3852/25212 (15.278439%) |
| 2024 C | 1240/25040 (4.952077%) | 3851/25212 (15.274472%) |
| 2025 A | 1198/24663 (4.857479%) | 3649/24866 (14.674656%) |
| 2025 B | 1198/24663 (4.857479%) | 3644/24866 (14.654548%) |
| 2025 C | 1195/24663 (4.845315%) | 3648/24866 (14.670635%) |

### Delta And Gate

差はpercentage point（pp）、年等重みは年別の未丸め率差の平均。
95%CIは共通race抽出のpaired bootstrap。全11Supporting指標の未丸め値/CIはcomparison.jsonに保存。

| 比較 | 指標 | 2024差pp | 2025差pp | 年等重み差pp | 95%CI pp |
|---|---|---:|---:|---:|---|
| C−B | 1着 | +0.079498 | -0.044375 | +0.017562 | [-0.050592, +0.085840] |
| C−B | 2着 | +0.035848 | -0.093016 | -0.028584 | [-0.094617, +0.033868] |
| C−B | 3着 | -0.023910 | +0.020211 | -0.001850 | [-0.024003, +0.020374] |
| C−B | Hit@3 | +0.030618 | -0.041898 | -0.005640 | [-0.045373, +0.032605] |
| C−B | Primary完全順序 | +0.007987 | -0.012164 | -0.002088 | [-0.028219, +0.022303] |
| C−B | Supporting MAP完全順序 | -0.003994 | -0.012164 | -0.008079 | [-0.036001, +0.020124] |
| C−A | 1着 | +0.238493 | +0.016136 | +0.127314 | [+0.029544, +0.225315] |
| C−A | 2着 | +0.123476 | -0.076839 | +0.023319 | [-0.057051, +0.107450] |
| C−A | 3着 | +0.007970 | +0.004042 | +0.006006 | [-0.022140, +0.034221] |
| C−A | Hit@3 | +0.121140 | -0.024328 | +0.048406 | [-0.004110, +0.099348] |
| C−A | Primary完全順序 | +0.015974 | -0.036492 | -0.010259 | [-0.042452, +0.023778] |
| C−A | Supporting MAP完全順序 | +0.015974 | -0.012164 | +0.001905 | [-0.036369, +0.040176] |
| B−A | 1着 | +0.158995 | +0.060511 | +0.109753 | [+0.033411, +0.195174] |
| B−A | 2着 | +0.087628 | +0.016177 | +0.051903 | [-0.014684, +0.123821] |
| B−A | 3着 | +0.031880 | -0.016169 | +0.007856 | [-0.018187, +0.031896] |
| B−A | Hit@3 | +0.090522 | +0.017570 | +0.054046 | [+0.009830, +0.099708] |
| B−A | Primary完全順序 | +0.007987 | -0.024328 | -0.008170 | [-0.034345, +0.018051] |
| B−A | Supporting MAP完全順序 | +0.019968 | +0.000000 | +0.009984 | [-0.022395, +0.044129] |

| 比較 | 非劣性 | 年別条件 | Hit@3優越 | 完全性 | 既存Gate |
|---|---|---|---|---|---|
| C−B | true | false | false | true | NOT_PASSED |
| C−A | true | false | false | true | NOT_PASSED |
| B−A | true | true | true | true | PASS_DEVELOPMENT_INCREMENTAL_EFFECT_ONLY |

C−Bは2025 Hit@3低下、年等重みHit@3のCI下限も0以下。C−Aの1着増加をtrendの増分改善と呼ばない。
Primary完全順序一致はB比で2024 +2件/2025 -3件（合計-1件）、年等重み-0.002088pp。
Supporting MAPは同-1件/-3件（合計-4件）、-0.008079pp。いずれも悪化を明示し、改善と断定しない。
B−Aの全指標・分子/分母・Primary・paired CIはPR92保存結果と厳密一致し、旧候補Bを維持する。

### Prediction Changes

| 比較 / 年 | 予測変更race | P1/P2/P3変更 | Hit3改善/悪化/同数/除外 | Primary完全順序改善/悪化/同数/除外 |
|---|---:|---|---|---|
| C−B 2024 | 260 | 260/240/39 | 109/93/24838/172 | 12/10/25018/172 |
| C−B 2025 | 341 | 341/316/52 | 116/137/24410/203 | 9/12/24642/203 |
| C−A 2024 | 476 | 476/438/69 | 214/153/24673/172 | 20/16/25004/172 |
| C−A 2025 | 538 | 538/505/77 | 200/209/24254/203 | 11/20/24632/203 |
| B−A 2024 | 406 | 406/376/58 | 177/132/24731/172 | 15/13/25012/172 |
| B−A 2025 | 368 | 368/341/59 | 143/131/24389/203 | 7/13/24643/203 |

以下は「両的中 / 候補のみ / 基準のみ / 両不的中 / 除外」。全対象から落とさず集計した。

| 比較 / 年 | 1着 | 2着 | 3着 |
|---|---|---|---|
| C−B 2024 | 10403/81/61/14613/54 | 6007/65/56/18978/106 | 4700/3/9/20382/118 |
| C−B 2025 | 9800/90/101/14798/77 | 5666/58/81/18922/139 | 4665/13/8/20053/127 |
| C−A 2024 | 10306/178/118/14556/54 | 5960/112/81/18953/106 | 4691/12/10/20381/118 |
| C−A 2025 | 9730/160/156/14743/77 | 5621/103/122/18881/139 | 4662/16/15/20046/127 |
| B−A 2024 | 10323/141/101/14593/54 | 5967/96/74/18969/106 | 4697/12/4/20381/118 |
| B−A 2025 | 9790/111/96/14792/77 | 5674/73/69/18911/139 | 4663/10/15/20052/127 |

### Final Status And Artifacts

```yaml
implementation_status: IMPLEMENTED_TESTED
evaluation_status: MEASURED_REPRODUCED
incremental_effect_vs_C2: NOT_CONFIRMED
total_effect_vs_C1: NOT_PASSED
exact_order_effect: DETERIORATED_VS_C2
development_gate: NOT_PASSED
accuracy_progress: NOT_CONFIRMED
historical_as_of_available: false
formal_adoption: false
adoption: NOT_ADOPTED
```

今回のPHP実装・実験・再現は技術的に完了したが、予測精度改善は未確認でC3は不採用。
元C1と既存候補Bを自動置換しない。正式採用・2026・LIVEは開放しない。
developmentの多数探索、event-dateによる履歴と取得時点未証明、全体suite未実行、
専用typed数値adapterの将来保守が既知の制約。旧数値変更時はparity確認を省略しない。
次はこの実装・否定結果のレビューだけ。無断の条件変更・再試行・次仮説へ進まない。

| 証跡（上記root配下） | 内容 |
|---|---|
| result-outer-parent-verified/comparison.json | 全未丸め指標/分子分母/年別差/paired CI/Gate/変化集計 |
| result-outer-parent-verified/reproduction.json | 独立2runの77意味的成果物のbytes/SHA一致 |
| result-outer-parent-verified/manifest.json | 171ファイル、source54/code394と状態。165,607 bytes |
| result-outer-parent-verified/COMPLETE.json | manifest identityと一致 |
| execute-outer-parent-verified/ | stdout/stderr、argv、PID、開始終了、実exit0 |
| focused-outer-parent-final/ | 新規36/243の最終コード検証ログ |
| related-safe/ | 純計算22/333の関連回帰ログ |
| final-checks/ | 最終23PHP構文/限定Pint成功。後続Gitだけread-only /dev/null制約で失敗、複合checker exit255を保持 |
| final-git-check/ | 通常環境の読取り専用git diff --check、exit0。DB/アプリ起動なし |

最終manifest SHA-256:
`ff2470d5c8662f169c75f112882ab8aeaae5fb8ab406cc4de5e566022fa5ea9b`。
旧途中停止のログ/FAILED/fit/予測は消さず、最終成功や追加の精度標本へ読み替えない。
