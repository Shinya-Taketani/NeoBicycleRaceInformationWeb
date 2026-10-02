# STAT-17-C1-COMPARE-01

## Contract

2026-10-02の最新指示による固定C1由来の限定development実験。
PR83レビュー後MERGED、開始main/origin `faedde36b1aa31b2bbfb750cda91512c157d50cd`、cleanから
`feature/stat17-c1-compare-01`を作成。旧S/mean6の追加Gate NOT_PASSED、旧C1/正式成果物を保持する。

experiment `STAT-17-C1-COMPARE-01-v1`、candidate `C1_PLUS_METHOD_DIVERSITY`、
model `STAT17-C1-METHOD-DIVERSITY-SEQUENTIAL-POSITION-v1`。
追加は `HIST_TOP2_METHOD_DIVERSITY_120D_PRE_MEETING` 一つだけ。
HistoryAggregator::FEATURESの逃げ/捲り/差し/マークの順を維持する。
開催開始前120日 `[T-120日,T)`・別開催の公式1/2着として観測された回数であり、
試行回数・成功率・PJ0315集計・S回数ではない。履歴再集計なし。

## Derivation

非負int4個をoverflow検査付きで合計しNとする。AVAILABLEかつN>0ならp_i=n_i/N、
`D=(8/3)*(p1*p2+p1*p3+p1*p4+p2*p3+p2*p4+p3*p4)`。
既存Neumaier補償加算・上記6積順・binary64、予測/bin生成前の表示丸めなし。
境界補正は事前固定1e-12以内だけ、clampedを監査。非有限/範囲超過は例外。

N=0はNULL/NO_TOP2_METHOD_OBSERVATIONS。元history利用不能は全NULLと元history_statusを保持。
有効D=0とNULLは別、N=1も有効0。型/部分NULL/status矛盾を補正せず拒否する。
高D有利の単調制約、N閾値、平滑化、能力ラベル、N/状態/理由の追加特徴量化は行わない。
観測構成の指数であって、真の能力・自在性・ライン役割を断定しない。

## Sources And Model

sourceは二束のみ。outcome-free C1:
`/home/shinya/neo-keirin-artifacts/stat35-c1-input-02/run-20260930-054458-8739da4b/result`、
manifest `7f4356b93f90203dc727dc95d6215a8d8ce3f44869c4441c3981087ff1099c26`。
保存Outer C1/教師:
`/home/shinya/neo-keirin-artifacts/tactical-history-01-review-fix-20260916-01`、
export `4268b801b74b77cfb3a94b64832ff483e9eb5e3221ee8e75ceb467e5a5cf92e6`、
parent contract `5db3b57b08fb8b91dbe6c057b459e518252c454b0a4ff60531c2d671b7ce506a`。
manifest/COMPLETE/実読取り子sealを検証。S束・mean6 sidecar・Raw・DB/HTTPなし。

元C1の12 signalsと4 historyの値/型/対象/順序を照合し、実験projectionだけDを17番目に加える。
元historyへ5番目を保存せず、NULLで対象を除外しない。Projectorを監査と学習で共用する。
派生監査はyear/race/entry/bike・原4値・元状態・D/state/N/clampedだけをstream保存する。
保存run-01 Outer C1はforward照合だけ、基準再学習0。最終全年度fitを基準へ使わない。

専用Layout/Objective/Optimizer/Trainer/Loaderは既存final/具体型依存を適応し、数値演算を維持。
conditional categorical NLL、race等重み、異常/同着適格性、training-local EffectBinBuilder/support、
Euclidean射影/group prox、L2/group/smoothness、warm start/restart/line search、E06 decoderは不変。
固定grid8候補、200 accepted updates、係数1e-7・目的1e-10・reference step1の近接/中心化1e-7。
全3順位収束だけOne-SE、選択refit非収束はNOT_EVALUATEDで停止。Dも全順位の17項目新規fitへ接続。

Inner A=2022→2023、2024 refit=2022/23。双方予測seal・cohort・保存forward後に2024教師開放。
Inner B=2022/23→2024、2025 refit=2022～24、2025教師は同じ照合後の評価のみ。
両Outer固定後にmetrics。byte hash確認はteacher利用の開放と分離して記録する。

主比較は候補-C1、補助は候補-STAT01。Primary 1着/2着/3着/位置Hit@3とSupportingを分離。
同一race-clusterの年層別paired bootstrap2000回、seed20260812、Type7、年等重み。
追加Gateは既存incrementalGateのstrict/inclusive境界を維持。補助PASSは主Gateの代用にならない。

## Execution

```bash
php -d memory_limit=512M artisan keirin:stat17:c1-compare plan
php -d memory_limit=512M artisan keirin:stat17:c1-compare execute --output-dir="$RUN/result"
```

新規root `/home/shinya/neo-keirin-artifacts/stat17-c1-compare-01/`、今回run:
`run-20261002-142352-f32cdb4f`。隔離runnerがargv/stdout/stderr/開始終了/exitを保存する。
execute1回内部のrun-01/02は独立再学習・予測・評価。Inner A重複fitなし。
実読取りsource/code START/ENDと公開前seal、再現、manifest/COMPLETEを検証する。
独立128M人工試験と実処理512Mを区別し、共有設定・旧成果物は変更しない。

HISTORICAL_EVENT_RECONSTRUCTION / BACKFILLED_FINAL_RESULT / DEVELOPMENT_ONLY。
historical_as_of_available=false、points=null、formal_adoption=false、LIVE/2026禁止。
STAT-17全体・戦法有効性・ライン役割自在性は未完成。結果を見た再試行・式/Gate変更はしない。

## Measured Results

execute1回で実学習・予測・評価・独立再現まで完了。**追加Gate NOT_PASSED、今回方式不採用・C1維持**。
非劣性/年別条件/integrityは成立するが、位置Hit@3差のCI下限が0を超えず優越条件は未達。
補助STAT01 Gate `PASS / GO_TO_FREEZE`は追加効果の承認ではない。コード/結果レビュー待ちで停止する。

以下は候補C1_PLUS_METHOD_DIVERSITY対保存Outer C1。率は表示だけ丸め、差はpercentage point(pp)。
Hit@3は一意な公式1/2/3着の位置一致数/(3×適格レース数)で、3連単や集合一致率ではない。

| 年 | 指標 | 候補 分子/分母(率) | C1 分子/分母(率) | 差pp |
| --- | --- | --- | --- | --- |
| 2024 | 1着 | 10442/25158 (41.5057%) | 10424/25158 (41.4341%) | +0.071548 |
| 2024 | 2着 | 6058/25106 (24.1297%) | 6041/25106 (24.0620%) | +0.067713 |
| 2024 | 3着 | 4702/25094 (18.7375%) | 4701/25094 (18.7336%) | +0.003985 |
| 2024 | 位置Hit@3 | 21129/75120 (28.1270%) | 21091/75120 (28.0764%) | +0.050586 |
| 2025 | 1着 | 9879/24789 (39.8524%) | 9886/24789 (39.8806%) | -0.028238 |
| 2025 | 2着 | 5735/24727 (23.1933%) | 5743/24727 (23.2256%) | -0.032353 |
| 2025 | 3着 | 4695/24739 (18.9781%) | 4677/24739 (18.9054%) | +0.072760 |
| 2025 | 位置Hit@3 | 20241/73989 (27.3568%) | 20241/73989 (27.3568%) | +0.000000 |

年等重み差と95%CI。指定の年層別paired race-cluster bootstrap 2000回、seed20260812、Type7。
同じ抽選を両モデルへ適用し、独立runを追加標本にしない。未丸めの全11指標・分子/分母はcomparisons.jsonへ保存。

| 指標 | 候補-C1 差pp [95%CI] | 候補-STAT01 差pp [95%CI] |
| --- | --- | --- |
| 1着 | +0.021655 [-0.060613,+0.108098] | +2.544087 [+2.198996,+2.899929] |
| 2着 | +0.017680 [-0.112465,+0.146404] | +0.404927 [+0.023289,+0.800282] |
| 3着 | +0.038372 [-0.075583,+0.152518] | +1.188886 [+0.823669,+1.549899] |
| 位置Hit@3 | +0.025293 [-0.047207,+0.098214] | +1.381546 [+1.129528,+1.630072] |

NIは4CI下限> -0.15ppで成立、各年Hit@3>=0/4主指標>= -0.3ppも成立。
優越はHit@3 CI下限>0が必要だが-0.047207ppのため不成立。
小幅な正の点推定・学習完了を、追加効果が実証されたことや正式採用へ読み替えない。

## Input And Convergence Verification

| 年 | レース/出走 | D数値 | NULL | 有効0 | N=0 | 元履歴利用不能 |
| --- | --- | --- | --- | --- | --- | --- |
| 2022 | 24394/170835 | 111126 | 59709 | 13747 | 3970 | 55739 |
| 2023 | 25197/179007 | 171278 | 7729 | 21567 | 5778 | 1951 |
| 2024 | 25212/179089 | 170002 | 9087 | 20617 | 6528 | 2559 |
| 2025 | 24866/177120 | 168683 | 8437 | 20770 | 6030 | 2407 |
| 合計 | 99669/706051 | 621089 | 84962 | 76701 | 22306 | 62656 |

各年D範囲[0,1]、境界補正0件。N=0のNULLは元履歴欠損と別理由、有効0はNULLと区別する。
元履歴利用不能はLEFT_TRUNCATED/INVALID_HISTORY/PARTIAL_HISTORY/NO_HISTORYを保持。
全対象・順序・元12+4値/型の照合成功、基準C1の保存モデルforward一致、基準再学習0回。
原4値とD/state/N/clampedの対応はderived-auditの4年JSONL/子manifestへ保存。報告には最小対応例を収録。

両runのInner A/Bでlambda=1と0.1が3順位ともCONVERGED。
0.01/0.001/0.0001/1e-5/1e-6/0はP1が200 accepted updatesでNUMERICALLY_NON_CONVERGEDとなり選択対象外。
既存One-SEによる両Outerの選択は0.1。強制選択/閾値緩和/別grid/同条件execute再試行なし。
Outer 2024は17 active group/146係数、P1/P2/P3更新141/79/107、2025は17 active group/147係数、132/86/89。
独立runでもbin/support、候補状態、選択、係数、入力projection、予測、分母/寄与/CI/Gateを含む76意味ファイルがbyte/SHA一致。
両Outerの再読込みforwardも一致。source33ファイル/コード355ファイルSTART/END・公開前seal成功。

## Execution Evidence And Tests

成果物: `/home/shinya/neo-keirin-artifacts/stat17-c1-compare-01/run-20261002-142352-f32cdb4f/result`。
manifest SHA-256 `f99c7d0dbed5e1fda0e387d2877cb181629e0236953a1317f9ab76716283b69f`、135587 bytes、COMPLETE一致。
開始2026-10-02 14:28:46 JST、終了19:19:46 JST、17460.504秒(4時間51分)、exit0、stderr空。
実処理memory_limit=512M、PHP peak=35651584 bytes(34MiB)。重要成果物・argv/時刻/終了コード/stdout/stderrは同じ永続run配下。
実行時の依存コード355ファイルとsealをexecution-codeへ保存し、未コミットコードの実行証跡とする。
小型報告ZIPは同run直下の `STAT-17-C1-COMPARE-01-review.zip`、サイズ/SHA/全収録ファイル照合はreview-zip-verification.jsonへ保存。
全学習データ・全予測・全派生明細をZIPへ重複収録しない。元資料/旧モデル/旧結果の上書きなし。

新規試験はStat17DiversityProjectorTest、Stat17C1ComparisonNumericsTest、Stat17C1ComparisonTestと人工fixture/独立process helper。
固定算術・0/NULL/status/overflow、元16項目維持・cohort/識別・改変/上書き拒否、教師時系列境界、旧16数値bit一致、
17項目の独立勾配/prox確認・training-local bin、Gate境界、独立再学習・END drift拒否を検証した。
独立128M人工試験は100MiB超の資料をstream処理、旧共有processのpeakに依存しない。

最終PHPコードで関連128M 235 tests/1914 assertions成功、通常全体1回2684 passed/9既存PostgreSQL skip/25129 assertions。
既存skipを成功件数へ含めず、新しいskipなし。変更PHP19ファイル構文・限定Pint成功。
plan exit0、git diff --check成功。DB/HTTP/Raw/2026実データ/Migration/LIVEは未実施。
branch `feature/stat17-c1-compare-01`、開始/終了HEAD `faedde36b1aa31b2bbfb750cda91512c157d50cd`、未コミットでレビュー待ち。
