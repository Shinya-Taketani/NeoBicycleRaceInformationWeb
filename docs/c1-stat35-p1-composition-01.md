# C1-STAT35-P1-COMPOSITION-01

## 実行前契約

開始main/origin `f5cc8316b0deaa4bfb8b1e83ebd12417846b307b`、PR91レビュー後MERGED。
cleanから `feature/c1-stat35-p1-composition-01` を作成した。旧PR89/90/91の不採用記録・成果物は保持する。
今回は保存STAT35_MEAN6追加C2のP1と保存元C1のP2/P3のutilityを組み合わせる別比較。
旧各Contract/実行記録を確認し、今回rootが未作成であることも確認した。同一構成比較の完了記録は確認されていない。

- experiment: `C1-STAT35-P1-COMPOSITION-01-v1`
- candidate: `C2_P1_C1_P23_COMPOSITION`
- calculation: `C1-STAT35-P1-COMPOSITION-PROBABILITY-v1`
- artifact_role: `FROZEN_POSITION_MODEL_COMPOSITION`
- C1: `TACTICAL-HISTORY-SEQUENTIAL-POSITION-v2`（保存run-01のOuter 2024/2025）。
- C2: `STAT35-C2-SEQUENTIAL-POSITION-v1`（STAT35_MEAN6追加17項目、保存run-01）。
- U1=C2.POSITION_1、U2=C1.POSITION_2、U3=C1.POSITION_3。
- 学習・係数更新・bin生成・lambda探索0。最終fit、他実験のC2、PR91非低下制約、PR90 P2固定は使わない。
- LIMITED_DEVELOPMENT_EXPERIMENT_ONLY / historical_as_of_available=false / formal_adoption=false / live_use_authorized=false / points=null。
- DB/HTTP/Raw/2026/LIVE/正式置換は禁止。score gap P3既知残件を保持する。

ProbabilityCalculatorは旧scorer非公開の周辺化/MAP部分だけをutility入力へ適応する。
公開conditionalLogProbabilitiesと補償加算を再利用し、列挙/加算順、logAddExp、tie、許容幅を維持。
一つの逐次分布 `P(i,j,k)=P1(i)*P2(j|not i)*P3(k|not i,j)` を厳密周辺化する。
周辺列の連結や、旧decisionの車番連結ではない。Supportingも新分布から計算する。
既存E06を変更せず使い、source-run metadataのみ専用の生成契約に変換。
新fitを偽るEXPERIMENTAL_REFIT/reconstruction_verifiedは新候補へ出さない。model_expected_gainはNULL/NOT_APPLICABLE。

## 出典と結果開放

固定3束のみ：

1. INPUT-02 `/home/shinya/neo-keirin-artifacts/stat35-c1-input-02/run-20260930-054458-8739da4b/result`。
   manifest `7f4356b93f90203dc727dc95d6215a8d8ce3f44869c4441c3981087ff1099c26`、本文はc1-2024/2025のみ。
2. C1 `/home/shinya/neo-keirin-artifacts/tactical-history-01-review-fix-20260916-01`。
   export `4268b801b74b77cfb3a94b64832ff483e9eb5e3221ee8e75ceb467e5a5cf92e6`、
   contract `5db3b57b08fb8b91dbe6c057b459e518252c454b0a4ff60531c2d671b7ce506a`。
   run-01の保存予測・model/layout/selection/refit出典、開放後labelsとsidecarのみ。
3. C2 `/home/shinya/neo-keirin-artifacts/stat35-c1-compare-01/run-20260929-215427-3b1776ba/result`。
   manifest 138599 bytes、`8fc40000c93bbc604caac37cf21e60519f26f28c4e77cf9284fa31403f5487c8`。
   COMPLETE/契約/COMPLETED_NOT_ADOPTED/入力・基準C1参照を照合し、manifest.runs.run-01の子sealを使う。
   対象年C2の予測/model/layout/selection/refit/sealedとsidecarのみ。teacher/旧contributions/学習本文は読まない。

年/race/entry/bike/順序/共通非結果値を厳密照合し、欠落/余分/重複は停止。ID昇順を仮定しない。
全C1と全C2のutility forwardを保存数学出力へ厳密照合。構成utility/P1/logP1/winner一致と
winner同一群Primary全順位一致を確認。両年予測・decision seal検証後だけlabelsを開く。
原モデル・原予測のseal/位置別親/source行/構成契約を保存し、新候補をC1モデルhashだけで識別しない。
元の学習code記録と今回の実行code sealを区別する。

## 評価・再現

主比較は候補−元C1、補助は候補−STAT01。既存Evaluator/Bootstrap/追加Gateを変更しない。
位置Hit@3は全公式1～3着が一意のraceの位置一致数/(3×適格race)。順位別分母と区別する。
2000回/seed20260812/Type7/年層別paired race-cluster/年等重み、同じ抽選を両方式へ適用。
全4CI下限>-0.0015、Hit3下限>0、各年Hit3>=0、各年4差>=-0.003、integrity/独立再現を要求。
補助PASSを主Gateへ代用しない。分母0/技術失敗はNULL/NOT_EVALUATED、性能0差へ補完しない。

全3順位の両的中/C1のみ/候補のみ/両不的中/除外、winner同一/変更群とHit3適格内の順位別増減を保存。
Primary完全順序一致とSupporting MAP完全順序一致を別集計する。
P1の既知C2改善（2024+40/2025+15）は独立実集計と照合する既知結果で、新発見としない。
過去development結果を見た後の仮説であり、未観測holdout/探索全体調整済み確認試験ではない。

1 execute内でrun-01と原資料独立再読込みrun-02を生成・評価。再現は追加標本ではない。
動的意味ファイル列挙、source/code START/END・公開前検証後のみmanifest/COMPLETE。
出力root `/home/shinya/neo-keirin-artifacts/c1-stat35-p1-composition-01/`。旧成果物を上書きしない。
process-localでDB/HTTPを例外拒否。ローカルSQLite/spoolは許可、実行と人工大容量試験は128M。

```bash
php -d memory_limit=128M artisan keirin:c1:stat35-p1-composition plan
php -d memory_limit=128M artisan keirin:c1:stat35-p1-composition execute --output-dir=RUN/result
```

## 実測結果 / 2026-10-08

`COMPLETED_DEVELOPMENT_COMPARISON_AWAITING_REVIEW`。実行1回内の独立run-01/run-02が完了。
対象は2024 25,212レース/179,089出走、2025 24,866/177,120、合計50,078/356,209。再現を追加標本にしない。
元C1比の的中増減は1着+55、2着+26、3着+4、Hit@3位置一致数+81。
P1は保存C2の既知結果+40/+15との一致確認であり、今回初めて見つかった改善ではない。
今回新しく評価した構成のP2/P3/Hit3と、旧C2全順位比較の採否を混同しない。

### 年別主比較

率/差は表示のみ6桁に丸め、計算・Gateは未丸め値。Hit3分母は3×ordered適格レース数。

| 年 | 指標 | 元C1 分子/分母（%） | 候補 分子/分母（%） | 件数差 | 差pp |
|---|---|---|---|---:|---:|
| 2024 | 1着 | 10424/25158（41.434136） | 10464/25158（41.593131） | +40 | +0.158995 |
| 2024 | 2着 | 6041/25106（24.061977） | 6063/25106（24.149606） | +22 | +0.087628 |
| 2024 | 3着 | 4701/25094（18.733562） | 4709/25094（18.765442） | +8 | +0.031880 |
| 2024 | Hit@3 | 21091/75120（28.076411） | 21159/75120（28.166933） | +68 | +0.090522 |
| 2025 | 1着 | 9886/24789（39.880592） | 9901/24789（39.941103） | +15 | +0.060511 |
| 2025 | 2着 | 5743/24727（23.225624） | 5747/24727（23.241800） | +4 | +0.016177 |
| 2025 | 3着 | 4677/24739（18.905372） | 4673/24739（18.889203） | −4 | −0.016169 |
| 2025 | Hit@3 | 20241/73989（27.356769） | 20254/73989（27.374339） | +13 | +0.017570 |

| 指標 | 年等重み差pp | paired95%CI pp |
|---|---:|---|
| 1着 | +0.109753 | [0.033411, 0.195174] |
| 2着 | +0.051903 | [−0.014684, 0.123821] |
| 3着 | +0.007856 | [−0.018187, 0.031896] |
| Hit@3 | +0.054046 | [0.009830, 0.099708] |

主Gate `PASS_DEVELOPMENT_INCREMENTAL_EFFECT_ONLY`、NI/temporal/superiority/integrity全true。
Hit3のCI下限0.00009830416416531796>0、全4CI下限>−0.0015、各年Hit3>=0/全4差>=−0.003を満たした。
開発上の採用候補をレビューへ提出するだけで、正式C1置換・freeze・LIVE・次工程は自動実行しない。
P2/P3のCIは0を含み、各順位の改善を個別に確証したとは言わない。

### 補助STAT01

| 年 | 指標 | STAT01 分子/分母（%） | 候補 分子/分母（%） | 差pp |
|---|---|---|---|---:|
| 2024 | 1着 | 9713/25158（38.607997） | 10464/25158（41.593131） | +2.985134 |
| 2024 | 2着 | 5876/25106（23.404764） | 6063/25106（24.149606） | +0.744842 |
| 2024 | 3着 | 4436/25094（17.677532） | 4709/25094（18.765442） | +1.087909 |
| 2024 | Hit@3 | 19959/75120（26.569489） | 21159/75120（28.166933） | +1.597444 |
| 2025 | 1着 | 9336/24789（37.661866） | 9901/24789（39.941103） | +2.279237 |
| 2025 | 2着 | 5714/24727（23.108343） | 5747/24727（23.241800） | +0.133457 |
| 2025 | 3着 | 4369/24739（17.660374） | 4673/24739（18.889203） | +1.228829 |
| 2025 | Hit@3 | 19349/73989（26.151185） | 20254/73989（27.374339） | +1.223155 |

| 指標 | 年等重み差pp | paired95%CI pp |
|---|---:|---|
| 1着 | +2.632185 | [2.289666, 2.983336] |
| 2着 | +0.439150 | [0.047386, 0.831782] |
| 3着 | +1.158369 | [0.807329, 1.530581] |
| Hit@3 | +1.410299 | [1.164561, 1.665624] |

補助Gate `PASS / GO_TO_FREEZE`。これは既存Gateの返り値で、freeze実行許可ではなく主Gateの代用もしない。

### 全指標と悪化

主4指標以外も既存Evaluatorの11指標を保存。Supportingは新分布から再計算した。
以下は元C1比（率の差pp、NDCGは元スケールの差）。全分子/分母と未丸め値はcomparison.json/metrics.csvに保存する。

| 指標 | 2024差 | 2025差 | 年等重み差 |
|---|---:|---:|---:|
| WINNER_HIT_AT_1 | +0.158995 | +0.060511 | +0.109753 |
| POSITION_1_ACCURACY | +0.158995 | +0.060511 | +0.109753 |
| POSITION_2_ACCURACY | +0.087628 | +0.016177 | +0.051903 |
| POSITION_3_ACCURACY | +0.031880 | −0.016169 | +0.007856 |
| POSITION_HIT_RATE_AT_3 | +0.090522 | +0.017570 | +0.054046 |
| Supporting MAP EXACT_ORDERED_TOP3_RATE | +0.019968 | 0 | +0.009984 |
| Supporting EXACT_TOP3_SET_RATE | +0.011899 | −0.020108 | −0.004104 |
| Supporting TOP3_COVERAGE_AT_3 | +0.038342 | −0.002681 | +0.017830 |
| Supporting EXACT_TOP2_SET_RATE | +0.015865 | −0.064345 | −0.024240 |
| Supporting TOP2_COVERAGE_AT_2 | +0.003966 | −0.014075 | −0.005055 |
| Supporting NDCG_AT_3 | +0.000229116 | +0.000210533 | +0.000219824 |

Primary完全順序一致は2024 1195→1197/25040（+2/+0.007987pp）、2025 1179→1173/24663（−6/−0.024328pp）、合計−4。
Supporting MAP完全順序一致は1236→1241/25040（+5）、1198→1198/24663（0）。両者を混同しない。
2025P3とSupporting集合/coverageの低下、Primary完全一致の低下を主Gate通過で隠さない。

### 予測変更とwinner群

| 年 | P1変更 | P2変更 | P3変更 | winner同一 | winner変更 |
|---|---:|---:|---:|---:|---:|
| 2024 | 406 | 376 | 58 | 24806 | 406 |
| 2025 | 368 | 341 | 59 | 24498 | 368 |
| 合計 | 774 | 717 | 117 | 49304 | 774 |

winner同一49,304レースはPrimary全順位・その的中寄与が完全不変。Supporting/無条件周辺確率の不変性は主張しない。
変化はwinner変更774レースだけで起きた。次表はその集合の順位ごとの監査。

| 年 | 順位 | 両的中 | C1のみ | 候補のみ | 両不的中 | 除外 | 全順位分母での的中差 |
|---|---|---:|---:|---:|---:|---:|---:|
| 2024 | P1 | 0 | 101 | 141 | 163 | 1 | +40 |
| 2024 | P2 | 8 | 74 | 96 | 226 | 2 | +22 |
| 2024 | P3 | 63 | 4 | 12 | 325 | 2 | +8 |
| 2025 | P1 | 0 | 96 | 111 | 158 | 3 | +15 |
| 2025 | P2 | 6 | 69 | 73 | 214 | 6 | +4 |
| 2025 | P3 | 59 | 14 | 10 | 282 | 3 | −4 |

winner同一群の両的中/両不的中/除外、年全体の全カテゴリも保存済みwinner-group-evaluation.jsonとchangesへ残す。
Hit3適格なwinner変更は2024 403/2025 362レース。その集合で順位別差は+38/+22/+8、+12/+4/−3、位置一致数差+68/+13。
全順位個別分母の合計+85とHit3分子差+81が異なるのは、適格集合を揃えないため。Hit3適格内では厳密一致。

## 再現・証跡・検証

実行: 2026-10-08 18:55:08〜19:01:07 JST、359.235717秒、exit0、memory_limit128M、peak33,554,432 bytes（32MiB）。
実argv/環境/開始終了/exit/stdout/stderrは同じ永続runのexecuteへ保存。
run-01/run-02は原資料独立再読込み、16意味ファイル完全一致。各年predictions/decisions/contributions各3本文と各sidecar（12）、
access-order/invariants/evaluation/winner-group-evaluation（4）。旧12ファイルへ合わせた件数ではない。
START/ENDと公開前で直接依存source39・code46のseal不変。両親4モデルと元予測は変更していない。
学習・係数・bin・lambda更新0。旧学習コード記録と今回実行コードを別に保存し、旧C2のCOMPLETED_NOT_ADOPTEDを維持。
DB/HTTPはprocess-localで拒否、Raw/2026/旧比較execute/業務書込みは0。

結果:
`/home/shinya/neo-keirin-artifacts/c1-stat35-p1-composition-01/run-20261008-fALiXmYk/result`

manifest: 114930 bytes、SHA-256 `f1c1687aed2aa6cf9540c6f351e8017c240f096177e3206af775d936e3d92264`。
公開済みCOMPLETEがmanifestを参照。失敗証拠の上書きなし。

共有用ZIP:
`/home/shinya/neo-keirin-artifacts/c1-stat35-p1-composition-01/run-20261008-fALiXmYk/C1-STAT35-P1-COMPOSITION-01-review.zip`

原モデル/原確率/学習本文を複製せず、再集計可能な最小race寄与・集計CSV・契約・監査・再現・ログ・差分を含める。
ZIPのサイズ/SHA-256と実在検証は同runのreview-zip.json、独立寄与再集計/byte比較はreview/verification.jsonに保存する。

### 人工・回帰テスト

- 専用44 tests/327 assertions成功（128M）。5/7/9人・欠番・非単調ID・同値・極端utility、旧scorer全C1/全C2数学出力の厳密一致。
- 正しい3utility出典・使わない列の独立性・単純周辺列連結との差・P1一致・winner条件・新Supporting。
- 原資料の識別/順序/値/版/seal不正、欠落/余分/重複/別年を拒否。結果変更で予測/decision不変、両年seal前labels拒否。
- 元Evaluator/Gateの境界、分母0のNOT_EVALUATED、独立再読込み、終了時driftで非公開、学習/DB/HTTP禁止。
- 独立128Mプロセスで100MiB超の人工JSONL、18,000レース/126,000出走の実reader/forward/構成を処理、exit0。共有プロセスpeakでは判定しない。
- 関連208 tests/2129 assertions成功（128M）。最終コードの通常全体1回3089 passed/29535 assertions、既存PostgreSQL限定9 skipped。
- 限定Pint9PHP・変更9PHP構文成功、git diff --check成功。旧テスト削除/緩和/新規skipなし。
- 新fixtureの異なる出典パス比較と、変更したlabelsのsynthetic sidecar再seal漏れは初回のテスト側不備として修正。初回ログも保持し、成功実行だけに置き換えない。

実行コマンドはrunner.phpから環境をtesting/SQLite/共有cache不使用へ限定して起動し、全argvを記録。

```bash
php -d memory_limit=128M artisan test --filter=C1Stat35P1Composition --colors=never
php -d memory_limit=128M artisan test --filter='C1Stat35P1Composition|C1MarginalP23|C1P23Nondecreasing|Bt03e06|Stat35C1Comparison|Bt03e03Probability' --colors=never
php artisan test --colors=never
```

限定Pint/構文の対象はCommand、専用6クラス、Test/Fixtureの計9PHP（正確なargvはpint/syntaxログ）。
branch `feature/c1-stat35-p1-composition-01`、HEAD/main基準はf5cc8316b0deaa4bfb8b1e83ebd12417846b307bのまま、未コミットのレビュー待ち。
新Command/専用6クラス/人工Test/Fixture/本文書を追加、MASTER PLANの今回状態だけを同期。旧scorer/E06/学習処理/AGENTS/Migrationは無変更。
過去development結果を見た後の限定仮説であり、未観測holdout・多重探索補正済み確認・STAT35因果効果・発走前利用可能性の証明ではない。
正式採用・LIVE・2026開放・次の実験は未承認のまま停止する。
