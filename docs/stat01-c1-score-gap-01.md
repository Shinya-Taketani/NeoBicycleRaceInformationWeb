# STAT-01-C1-SCORE-GAP-01

## Contract

2026-10-03のユーザー指示による固定C1由来の限定development比較。
PR84レビュー後MERGED、clean main/origin `89544d70eb1c6e74f24ff0dcb2a2d5e76cb4a5f7`から
`feature/stat01-c1-score-gap-01`を作成。旧STAT17/S/mean6の不採用・C1維持を変更しない。
開始時にMASTER PLAN、既存コード/実験文書、永続rootの実験一覧では今回と同じ比較の完了記録を確認していない。
STAT01基礎集計、最大/ペア点差、z-score、D/S/mean6比較を今回の実施済み証拠とは扱わない。

experiment `STAT-01-C1-SCORE-GAP-01-v1`、candidate `C1_PLUS_SCORE_GAP`、
model `STAT01-C1-SCORE-GAP-SEQUENTIAL-POSITION-v1`。
追加は `RACE_SCORE_CENTERED_RAW` 一つだけ。元rawの別表現であり、新原情報や因果効果ではない。

## Derivation

純粋なScoreGapProjectorが最初に既存AgariC1Input Validatorを通す。
5～9人の固定全出走者について、保存順に `(float) raw`、`mean=array_sum(scores)/n`、
`gap[i]=scores[i]-mean`。共有Validatorと同じbinary64算術経路、表示丸めなし。
結果/完走/教師適格性/履歴AVAILABLEで母集団を選別しない。
rawのNULL/文字列/NaN/INF、合計/平均/gapの非有限、識別/対象不整合は拒否する。
負値は正常、全員同得点は有効0.0、負のゼロだけ正の0.0へ統一。epsilon/clamp/順位化/SD除算なし。
元anchor係数1・raw・stat01_rank・12 signals・4 history・status・順序・型は変更しない。
モデル投影だけ元16項目の末尾へgapを加え、mean/人数/監査状態を学習へ渡さない。
監査と各runの入力は同じProjectorを使いyear/race/entry/bike/raw/mean/gap/人数を保存する。

## Fixed Sources

outcome-free C1は
`/home/shinya/neo-keirin-artifacts/stat35-c1-input-02/run-20260930-054458-8739da4b/result`。
manifest `7f4356b93f90203dc727dc95d6215a8d8ce3f44869c4441c3981087ff1099c26`。
2022～2025のc1だけを使い、STAT35 sidecar/S/D/Raw/DB/HTTPを追加しない。
保存Outer C1/教師は `/home/shinya/neo-keirin-artifacts/tactical-history-01-review-fix-20260916-01`。
export `4268b801b74b77cfb3a94b64832ff483e9eb5e3221ee8e75ceb467e5a5cf92e6`、
parent contract `5db3b57b08fb8b91dbe6c057b459e518252c454b0a4ff60531c2d671b7ce506a`。
COMPLETE/manifest/実読取り子sealと原本非結果値/型/対象/順序を検証する。
基準は保存run-01 Outer C1、保存モデルforward照合のみ・再学習0。全年度fitの最終C1へ置換しない。

## Learning And Evaluation

専用17項目Layout/Objective/Optimizer等は既存final/具体型依存を最小限適応。
追加group非active時の旧16項目数値bit一致を人工検証する。条件付きNLL/race等重み/
異常同着適格性/training-local EffectBinBuilder/support/中心化Euclidean prox/
L2・group・smoothness/補償加算/確率/E06 decoderを維持する。
固定8lambda候補・strong-to-weak・収束候補warm start・3順位収束One-SE。
200 accepted updates、係数1e-7、目的1e-10、prox/中心化1e-7、reference step1は不変。
全非収束/選択refit非収束は診断保存・NOT_EVALUATED。条件緩和/fallback/同条件再試行なし。

Inner A=2022→2023、Outer24 refit=2022/23。モデル/予測seal・保存forward・両cohort照合後2024教師開放。
Inner B=2022/23→2024、A/B共通適格候補から選択、Outer25 refit=2022～24。同じ照合後2025教師を評価へ開放。
両Outer固定後に指標集計。元資料のbyte検査と教師利用開放を別に記録する。
Primaryは1着/2着/3着/位置Hit@3。Supportingと分離し、指標別共通分母を使う。
Hit@3は位置一致数/(3×公式1/2/3着一意レース数)、3連単/集合一致率ではない。
年層別paired race-cluster bootstrap2000、seed20260812、Type7、年等重み。
主Gateは候補-C1で4CI下限>−0.0015、Hit@3 CI下限>0、各年Hit@3差>=0、
各年4差>=−0.003、integrity/独立再現。補助候補-STAT01 Gateは主Gateの代用にしない。

## Execution

```bash
php -d memory_limit=512M artisan keirin:stat01:c1-score-gap plan
php -d memory_limit=512M artisan keirin:stat01:c1-score-gap execute --output-dir="$RUN/result"
```

root `/home/shinya/neo-keirin-artifacts/stat01-c1-score-gap-01/`内の新規runへ保存する。
execute1回内部のrun-01/02で独立再学習・予測・評価し、意味ファイルを照合して最終Gate/manifest/COMPLETE。
source/code START/ENDと公開前seal、argv/stdout/stderr/時間/peak/終了コードを保存。
DB/HTTPはprocess-local明示拒否、共有設定/旧成果物不変。独立128M人工試験と実処理512Mを区別する。

LIMITED_DEVELOPMENT_EXPERIMENT_ONLY、historical_as_of_available=false、points=null、
formal_adoption=false、live_use_authorized=false、2026_access=FORBIDDEN。
2022～2025は使用済みdevelopment資料。当時の公開時点・実運用性能を証明せず、正式登録/LIVEへ自動移行しない。

## Execution Status

人工確認後、executeを1回実行し、run-01/02の独立再学習・予測・評価まで完了。
`COMPLETED_DEVELOPMENT_COMPARISON_AWAITING_REVIEW`。主追加Gateは `NOT_PASSED`。
今回固定方式は採用せず既存C1を維持する。学習成功・再現成功を精度向上と呼ばない。
旧D/S/mean6の実績は当時の記録として維持し、以下は今回の実測だけ。

## Performance

主比較は候補-C1。率は百分率、差はpercentage point (pp)。丸めは表示のみ、Gateは保存未丸め値で判定。

| 年 | 指標 | 保存C1 分子/分母 (率) | 候補 分子/分母 (率) | 差 pp |
|---|---|---|---|---:|
| 2024 | 1着 | 10424/25158 (41.4341%) | 10478/25158 (41.6488%) | +0.2146 |
| 2024 | 2着 | 6041/25106 (24.0620%) | 5991/25106 (23.8628%) | -0.1992 |
| 2024 | 3着 | 4701/25094 (18.7336%) | 4719/25094 (18.8053%) | +0.0717 |
| 2024 | 位置Hit@3 | 21091/75120 (28.0764%) | 21112/75120 (28.1044%) | +0.0280 |
| 2025 | 1着 | 9886/24789 (39.8806%) | 9902/24789 (39.9451%) | +0.0645 |
| 2025 | 2着 | 5743/24727 (23.2256%) | 5653/24727 (22.8616%) | -0.3640 |
| 2025 | 3着 | 4677/24739 (18.9054%) | 4599/24739 (18.5901%) | -0.3153 |
| 2025 | 位置Hit@3 | 20241/73989 (27.3568%) | 20087/73989 (27.1486%) | -0.2081 |

補助比較は候補-STAT01。同じ候補、同じ指標別対象・分母を使う。

| 年 | 指標 | STAT01 分子/分母 (率) | 候補率 | 差 pp |
|---|---|---|---:|---:|
| 2024 | 1着 | 9713/25158 (38.6080%) | 41.6488% | +3.0408 |
| 2024 | 2着 | 5876/25106 (23.4048%) | 23.8628% | +0.4581 |
| 2024 | 3着 | 4436/25094 (17.6775%) | 18.8053% | +1.1278 |
| 2024 | 位置Hit@3 | 19959/75120 (26.5695%) | 28.1044% | +1.5349 |
| 2025 | 1着 | 9336/24789 (37.6619%) | 39.9451% | +2.2833 |
| 2025 | 2着 | 5714/24727 (23.1083%) | 22.8616% | -0.2467 |
| 2025 | 3着 | 4369/24739 (17.6604%) | 18.5901% | +0.9297 |
| 2025 | 位置Hit@3 | 19349/73989 (26.1512%) | 27.1486% | +0.9974 |

年等重み差と95%CI。2000回の年層別paired race-cluster bootstrap、seed20260812、Type7。
独立再現runを標本へ重複加算していない。

| 指標 | 対C1 差 pp [95%CI] | 対STAT01 差 pp [95%CI] |
|---|---|---|
| 1着 | +0.139594 [-0.004834, +0.288477] | +2.662027 [+2.310495, +3.011636] |
| 2着 | -0.281565 [-0.492260, -0.067794] | +0.105682 [-0.282652, +0.504701] |
| 3着 | -0.121781 [-0.429223, +0.190527] | +1.028733 [+0.615107, +1.447453] |
| 位置Hit@3 | -0.090092 [-0.232836, +0.056111] | +1.266162 [+1.024354, +1.519398] |

主Gate: integrity=true、non_inferiority=false、temporal=false、superiority=false。
2着/3着/Hit@3のCI下限が非劣性条件>-0.15ppを満たさず、Hit@3下限>0も未達。
2025のHit@3差が負、2着/3着差が-0.3ppを下回るため年別条件も未達。
1着点推定は両年とも改善したが、そのCIは0を含む。2着は年等重みCI全域で悪化。
追加効果を支持する結果とは判定せず `NOT_ADOPTED_RETAIN_C1_AWAITING_REVIEW` とする。
補助STAT01 Gateも `FAIL / REDESIGN_REQUIRED` (non_inferiority/supporting未達)。
STAT01比Hit@3改善をC1への追加採用へ読み替えず、条件を変更して再試行しない。

## Inputs And Convergence

| 年 | レース | 出走/数値gap | 負 | 0 | 正 | min | max |
|---|---:|---:|---:|---:|---:|---:|---:|
| 2022 | 24394 | 170835 | 87645 | 17 | 83173 | -20.142857142857153 | 18.314285714285717 |
| 2023 | 25197 | 179007 | 91794 | 13 | 87200 | -19.055714285714288 | 19.385714285714286 |
| 2024 | 25212 | 179089 | 91544 | 20 | 87525 | -17.848571428571432 | 24.27857142857144 |
| 2025 | 24866 | 177120 | 90843 | 15 | 86262 | -19.279999999999987 | 18.658888888888896 |
| 合計 | 99669 | 706051 | 361826 | 65 | 344160 | -20.142857142857153 | 24.27857142857144 |

全同得点レース0、不正入力0、gap NULL0。706051出走の数値変動で仮説を試せた。
元C1のraw/anchor/16値/型/対象/順序は全年照合済み。基準Outer C1の保存forwardは両年一致、再学習0。

両run・Inner A/Bとも8候補を固定順に実行し、lambda=1と0.1が全3順位CONVERGED。
残り6候補はP1で200 accepted updatesのNUMERICALLY_NON_CONVERGEDとなり、P2/P3へ進まずOne-SEから除外。
共通適格候補のOne-SEでOuter2024/2025ともlambda=0.1を選択した。過去値を強制していない。
選択refitのP1/P2/P3 accepted updatesは2024=135/107/104、2025=122/121/106。
全順位が既存の係数/目的/prox/中心化条件を満たす。診断は各runのcandidate/refit-path/modelおよびreview/convergence.csvに保存。
モデル/予測seal・再読込みforward・C1との対象一致後に各年教師を開放し、両Outer固定後だけ性能を計算した。

## Evidence And Verification

今回run:
`/home/shinya/neo-keirin-artifacts/stat01-c1-score-gap-01/run-20261002-223318-ae3cf831/`。
ディレクトリ名の日時はUTC。実行開始/終了は2026-10-03 07:38:32～12:42:27 JST、
所要18235.122882秒 (5時間3分55秒)、exit0、stderr0 bytes、peak35651584 bytes (34MiB)。
実処理512M、人工streaming試験は別の独立128M process。

独立2runの76意味ファイルがbytes/SHA完全一致。派生入力、bin/support、候補状態、選択、係数、
予測、教師開放、分母/寄与/CI/Gateを確認。日時/PID/elapsed/pathは意味比較に混入しない。
実読取りsource33ファイル、直接依存code355ファイルのSTART/ENDと公開前seal検査成功。
`result/manifest.json` は134783 bytes、SHA-256:
`214ba0fa3a144b7053457c5ad2d123e874256d0f34927a04700ce74186dc9ee1`。
`COMPLETE.json`が同sealを参照し、FAILED.jsonなし。

新規3テストクラス44 passed/552 assertions、最終関連128M 279 passed/2466 assertions。
最終通常全体1回は2728 passed/9既存PostgreSQL skip/25681 assertions、220.32秒、exit0。
新規skip/既存削除・緩和なし。変更PHP19ファイルの構文検査と限定Pint成功、git diff --check成功。
純粋計算・入力/教師境界・非active数値bit一致・17項目勾配/prox・Loaderforward・Gate・独立128Mを人工資料で検証。
plan/execute argv、stdout/stderr、実行時間/終了コード、code保存はrun直下の証跡にある。
小型review bundleは契約・比較表・診断・再現・実行ログ・コード差分を含み、全学習入力/全予測は複製しない。

DB/HTTP/Raw/2026実データアクセス0、Migrationなし、旧モデル/入力/成果物不変。
保存資料の比較であり当時の公開時点保証・未知holdout/実運用性能は未検証。
今回方式は不採用、コード/結果レビューだけを次とし、commit/push/PR操作/LIVE/次工程へ進まない。
