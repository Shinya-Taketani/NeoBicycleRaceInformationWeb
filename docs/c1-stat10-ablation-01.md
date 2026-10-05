# C1-STAT10-ABLATION-01

## Fit-Before Contract

開始main/origin `db94280fbab7434ccf34043dcef40b6deb7401df`、PR85マージ済み。
専用branch `feature/c1-stat10-ablation-01`。今回だけ固定C1からSTAT-10を除く15項目の
新規学習・Outer2024/2025比較・独立2runを許可する。旧C1は保存Outer run-01をforward照合するだけで再学習0。
既存コード/工程記録/永続root一覧に同一比較の完了証拠は確認していない。
BT02単項目評価・旧係数診断・ゼロ係数化は今回の再学習比較と区別する。

experiment `C1-STAT10-ABLATION-01-v1`、candidate `C1_MINUS_STAT10`、
model `C1-MINUS-STAT10-SEQUENTIAL-POSITION-v1`。
原12 STAT+history4を既存Validatorで検証し、原本/教師の全16値・型・順序・識別子を照合後、
特徴量名で固定したmapによってSTAT-10だけを除く。末尾historyを保持し、0/NULLも不変。
anchor1/raw/stat01_rank/全対象を維持。監査原値とモデルspool15値は別物。
共有Contract/Layout/数値仕様は変更せず、具体型依存だけを専用15項目型へ適応する。
bin/support/M/G/edgeは除外後構造から新規作成する。正則化分母の変化も含むモデル比較であり因果効果ではない。

## Fixed Sources

outcome-free C1: `/home/shinya/neo-keirin-artifacts/stat35-c1-input-02/run-20260930-054458-8739da4b/result`。
manifest `7f4356b93f90203dc727dc95d6215a8d8ce3f44869c4441c3981087ff1099c26`。
保存Outer/教師: `/home/shinya/neo-keirin-artifacts/tactical-history-01-review-fix-20260916-01`。
export `4268b801b74b77cfb3a94b64832ff483e9eb5e3221ee8e75ceb467e5a5cf92e6`、
contract `5db3b57b08fb8b91dbe6c057b459e518252c454b0a4ff60531c2d671b7ce506a`。
Sourcesはmanifest/COMPLETE/実読取り子seal/原資料契約を確認する。mean6/S/D/gap資料を入力にしない。
DB/HTTP/Raw/2026へのfallbackなし。

## Numerical And Temporal Rules

修正版TH Euclidean constrained FISTA v2、conditional NLL、レース等重み、
training-local bin/support、中心化、L2/group/smoothness、E06 decoder/固定tie/補償加算を継承。
lambda `[0,1e-6,1e-5,1e-4,1e-3,1e-2,1e-1,1]`、strong-to-weak、収束候補warm start。
200 accepted updates、係数/prox/中心化1e-7、相対目的1e-10、reference step1は不変。
全3順位収束One-SE、全非収束/選択refit非収束はNOT_EVALUATED、緩和・fallbackなし。
InnerA 2022→2023、Outer24 refit2022/23。両予測seal/cohort/保存forward後に2024教師開放。
InnerB 2022/23→2024、共通適格One-SE、Outer25 refit2022–24、同じ照合後2025教師開放。
両Outer固定後に評価。バイト検査と教師値の利用開放を別監査する。

主比較候補-C1、補助候補-STAT01。P1/P2/P3/位置Hit@3を同一指標別分母で比較。
Hit@3=位置一致数/(3×公式1/2/3着一意race数)、3連単率ではない。
年層別paired racecluster bootstrap2000/seed20260812/Type7/年等重み。
主Gate: 全4CI下限>-0.0015、Hit3CI下限>0、各年Hit3差>=0、各年4差>=-0.003、integrity/独立再現。
補助PASSを主Gateへ代用せず、項目削減だけでGateを緩和しない。

## Execution And Restrictions

`keirin:c1:stat10-ablation plan/execute`。1 execute内部で独立run-01/02を各1回。
保存root `/home/shinya/neo-keirin-artifacts/c1-stat10-ablation-01/` の新規run/result。
source/code START/END、公開前seal、実際の意味ファイル一覧、argv/log/time/peak/exitを保存する。
LIMITED_DEVELOPMENT_EXPERIMENT_ONLY、historical_as_of_available=false、formal_adoption=false、
live_use_authorized=false、2026_access=FORBIDDEN、points=null。
使用済みdevelopment22–25の結果であり未観測holdoutではない。STAT10要件・集計器は削除しない。
主Gate未達ならC1維持、通過なら開発候補としてレビュー提出だけ。正式置換/LIVE/次ablationへ進まない。

PR85 P3（全同小数得点のgapにbinary64残差）は未解決。今回はgap不使用、旧コード/版/成果物不変。
将来再利用前の対応事項として残し、旧約5時間比較を再実行しない。

## Status

COMPLETED_DEVELOPMENT_COMPARISON_AWAITING_REVIEW。主Gate NOT_PASSED、今回の15項目方式は採用せず現行C1を維持する。
STAT-10の恒久廃止・無効性・単独因果効果を結論しない。補助STAT01 Gate PASSは主比較の採用根拠ではない。

## Measured Performance

率は表示用%、差は候補-C1のpercentage point (pp)。未丸めの分子/分母・率・CIはreview CSV/JSONへ保存。

| 年 | 指標 | C1分子 | 候補分子 | 共通分母 | C1率% | 候補率% | 差pp |
|---|---|---:|---:|---:|---:|---:|---:|
| 2024 | 1着 | 10424 | 10442 | 25158 | 41.434136 | 41.505684 | +0.071548 |
| 2024 | 2着 | 6041 | 6044 | 25106 | 24.061977 | 24.073927 | +0.011949 |
| 2024 | 3着 | 4701 | 4719 | 25094 | 18.733562 | 18.805292 | +0.071730 |
| 2024 | 位置Hit@3 | 21091 | 21131 | 75120 | 28.076411 | 28.129659 | +0.053248 |
| 2025 | 1着 | 9886 | 9828 | 24789 | 39.880592 | 39.646617 | -0.233975 |
| 2025 | 2着 | 5743 | 5764 | 24727 | 23.225624 | 23.310551 | +0.084927 |
| 2025 | 3着 | 4677 | 4677 | 24739 | 18.905372 | 18.905372 | 0 |
| 2025 | 位置Hit@3 | 20241 | 20200 | 73989 | 27.356769 | 27.301356 | -0.055414 |

| 指標 | 年等重み候補-C1差pp | paired 95%CI pp | 年等重み候補-STAT01差pp | paired 95%CI pp |
|---|---:|---|---:|---|
| 1着 | -0.081213 | [-0.213322, +0.052774] | +2.441219 | [+2.103605, +2.803982] |
| 2着 | +0.048438 | [-0.119022, +0.221927] | +0.435685 | [+0.062741, +0.820065] |
| 3着 | +0.035865 | [-0.119662, +0.198496] | +1.186379 | [+0.825278, +1.560440] |
| 位置Hit@3 | -0.001083 | [-0.104312, +0.100788] | +1.355171 | [+1.113747, +1.602075] |

補助比較のSTAT01分子は2024:9713/5876/4436/19959、2025:9336/5714/4369/19349。
上表と同じ指標順・分母。STAT01率は2024:38.607997/23.404764/17.677532/26.569489%、
2025:37.661866/23.108343/17.660374/26.151185%。年別候補-STAT01差ppは
2024:+2.897687/+0.669163/+1.127760/+1.560170、2025:+1.984751/+0.202208/+1.244998/+1.150171。

主Gateの非劣性未達は1着差CI下限 -0.0021332192747531668 が -0.0015 を超えないため。
年別安定性未達は2025 Hit@3差 -0.00055413642568491461 < 0。
優越未達はHit@3差CI下限 -0.0010431205500121209 <= 0。各年4差>=-0.003は満たした。
integrity=true。補助GateはPASS / GO_TO_FREEZEだが、今回の主Gateや正式freezeへ読み替えない。

## Training And Integrity

1 execute内部でrun-01/02を各1回独立学習・予測・評価し、実列挙した76意味ファイルが完全一致。
両runの選択lambdaは2024/2025とも0.1。Inner Aの収束候補1/0.1、Inner Bは1/0.1/0.01。
共通適格1/0.1だけをOne-SE対象とした。その他はNUMERICALLY_NON_CONVERGEDとして診断保存し、閾値は不変。
各Outer refitは1→0.1を全3順位収束、保存モデル再読込みforward一致。

| fold | 名目特徴量 | M | G | edge |
|---|---:|---:|---:|---:|
| Inner A | 15 | 126 | 15 | 107 |
| Outer 2024 | 15 | 126 | 15 | 107 |
| Inner B | 15 | 126 | 15 | 107 |
| Outer 2025 | 15 | 127 | 15 | 108 |

入力実測:2022は24394/170835、2023は25197/179007、2024は25212/179089、2025は24866/177120（レース/出走）。
合計99669/706051、全16→15値・型・順序・識別子・cohort照合済み。STAT10 NULL6270、有効0は2266、float699781。
NULL出走を除外せず、原値は監査だけへ保存し学習ベクトルへ渡さない。
旧Outer C1は両年forward完全一致、再学習0。source33/code355のSTART/END不変・公開前seal検証成功。

新規人工43 tests/444 assertions、最終関連322/2910を128Mで成功。
独立128M・100MiB超入力ストリーミング試験を含む。通常全体テスト1回は2771 passed/26125 assertions、既存9 skipped。
変更PHP19ファイルの構文・限定Pint・git diff --check成功。既存テストの削除/緩和/新規skipなし。
実処理は512M設定、実測peak33554432 bytes（32MiB）、exit0、stderr空。
2026-10-03 15:57:58–20:37:23 JST、16765.001394秒（4時間39分25秒）。

## Saved Evidence

run: `/home/shinya/neo-keirin-artifacts/c1-stat10-ablation-01/run-20261003-065046-66fb55f5/`。
結果は`result/`、保存学習時コードは`execution-code/`、比較CSV/JSON・収束診断・実行ログは`review/`。
manifest SHA-256: `671bbcd97463123076846c4dcd6f7273e6e6aa0d9da8dabba1dc205ef2dca339`（137573 bytes）。
COMPLETEはmanifestのサイズ/hashに一致。全学習JSONL/予測はresultに保持し小型ZIPへ重複コピーしない。
開始/終了HEADは`db94280fbab7434ccf34043dcef40b6deb7401df`、未コミットのレビュー待ち。
DB/HTTP/Raw/2026/LIVE/正式置換/旧候補再実行は0、点数null。次ablationへ進まない。
