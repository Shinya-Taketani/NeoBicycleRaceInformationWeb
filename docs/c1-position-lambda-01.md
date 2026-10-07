# C1-POSITION-LAMBDA-01

## 限定契約 / 2026-10-07

PR87はレビュー後マージ済み。開始mainは `634d5743e148b6a44de53040466213829d62aee1`。
作業branchは `feature/c1-position-lambda-01`。旧人数×車番方式は不採用、元C1と旧成果物を維持する。
今回のみ `SHARED_ONE_SE_POSITION_EQUAL_YEAR_EQUAL` から `PER_POSITION_ONE_SE_YEAR_EQUAL` へ変更する。
既存共有lambda契約を全モデル共通で解除しない。

版: experiment `C1-POSITION-LAMBDA-01-v1`、model `C1-POSITION-LAMBDA-SEQUENTIAL-POSITION-v1`、
selector `C1-PER-POSITION-ONE-SE-v1`、path `C1-PER-POSITION-CONVERGENCE-PATH-v1`。
候補 `C1_PER_POSITION_LAMBDA`、基準は保存済みrun-01のOuter2024/2025 C1（再学習0）。

入力は指定の2束のみ。outcome-free C1 manifest `7f4356b93f90203dc727dc95d6215a8d8ce3f44869c4441c3981087ff1099c26`、
Outer/教師 export `4268b801b74b77cfb3a94b64832ff483e9eb5e3221ee8e75ceb467e5a5cf92e6`、
旧契約 `5db3b57b08fb8b91dbe6c057b459e518252c454b0a4ff60531c2d671b7ce506a`。
正確なpath・子sealは専用Contract/Sourcesと固定manifestで検証する。

12 STAT（07/08/10/11/12/23/24/26/31/32/39/42）と決まり手4回数を順序・型・NULL/0ごと保持。
raw/anchor/stat01_rank/対象を変更しない。S/mean6/D/gap/人数×車番を追加せず、STAT10も除外しない。
TacticalHistoryの16項目Layout/Objectiveを再利用し、一順位の修正版FISTAを型・公開範囲だけ最小適応。
200 accepted updates、係数/近接/中心化1e-7、相対目的1e-10、reference step1を維持する。

lambda grid `[0,1e-6,1e-5,1e-4,1e-3,1e-2,1e-1,1]`、各順位strong-to-weak。
直前の同順位・同poolの収束済み係数だけをwarm startに使う。
一順位の非収束で他順位を省略しない。generic例外を非収束へ変換しない。

順位別conditional NLLをレース等重み・検証年等重みで集計。Outer2024はInner A、
Outer2025は同順位のInner A/B共通収束候補だけを使用する。
最小損失完全同値はgrid昇順先頭、`loss <= best + SE_best` の最大lambdaを選ぶ。
選択用SEは年層別race bootstrap 2000/seed20260812の標本SD（sqrt2000で再除算しない）。
全候補同じマスク・抽選を使用し、replicate分母は抽選された適格レースの重み。
分母0/候補なし/非有限値は再抽選せず停止する。

T22は全24位置候補、T2223も全24をOuter2024教師開放前に一度だけfitする。
T2223はOuter2024 refitとInner B検証に同一run内で再利用。run間のfit cache共有は禁止。
Outer2024をseal・再読込みforward・cohort照合後に2024教師を開放。
T2224は各順位の選択lambdaまでfit、Outer2025固定後だけ2025教師を開放する。
選択lambda非収束はfallbackせずNOT_EVALUATED。最大72試行/run、独立2runで最大144。

専用Fit/Loaderは3順位のlambda・係数・診断・選択証跡を対応付ける（旧単一lambda DTOの偽装禁止）。
確率は旧sequential softmax/厳密周辺化、Primaryは既存E06 decoder/tie。
比較は候補-C1主、候補-STAT01補助。4主指標・既存paired CI/Gateを維持。
Hit@3は一意公式1/2/3の位置一致数/(3×適格レース数)、3連単率ではない。
性能差CIはyear-stratified paired race-cluster 2000/20260812/Type7/year-equal。
Gateは4指標CI下限>-0.0015、Hit@3下限>0、年別Hit@3>=0、年別4指標>=-0.003と独立再現。

## 実行・保護

`keirin:c1:position-lambda plan` / `execute --output-dir=...`。
1 execute内部で独立2run。root `/home/shinya/neo-keirin-artifacts/c1-position-lambda-01/` 内へ新規保存。
実学習512M、独立人工streaming128Mを区別。source/code START/END、pool/予測seal、
実列挙した意味ファイル再現を公開前検証する。DB/HTTPをprocess-local拒否する。
用途 `LIMITED_DEVELOPMENT_EXPERIMENT_ONLY`、historical_as_of_available=false、formal_adoption=false、
live_use_authorized=false、2026_access=FORBIDDEN、points=null。
旧pilot保留/E08否定/旧不採用/score gap P3未解決を維持する。

## 人工検証

今回専用52 tests / 434 assertionsが実128Mで成功。元16値・型・順序/教師全値照合、
順位別warm start/非収束分離、選択refit失敗停止、generic例外伝播、pool改変拒否を確認。
旧一順位solverへの同初期値/同lambda照合はテスト内Reflectionのみ（productionには使用しない）。
全係数から旧確率・decoder・metricへのbit一致、5/7/9人/極端utility/同値を確認。
One-SEの順位独立・A/B共通候補・年等重み・境界・適格マスク/NULL抽選分母・標本SDを独立参照で検証。
実service経路で2024教師変更時のOuter2024不変、2025変更時の両Outer不変、
開放後2024変更時のOuter2025係数変化、独立2runの意味ファイル一致を確認。
不正modeでもNOT_EVALUATED/実peakを記録する。

準備段階の関連回帰430 tests / 4129 assertionsも128Mで成功。
初回通常全体確認は出力照合テストの同一行二重消費で1件失敗（数値処理の不具合ではない）。
同一行を一つの期待文字列で照合して修正し、初回ログを保持。最終コードの通常全体確認は別証跡へ保存する。
PHP19ファイル構文・限定Pint・512M planは成功。
独立128M人工streamingは113,450,000 bytes / 50,000 races / 250,000 entries、peak10,485,760 bytes / exit0。
最終コードの通常全体確認は2,880 passed / 27,349 assertions / 既存9 skipped、exit0（新規skipなし）。

## 実測結果 / 2026-10-07

1 execute内部の独立2runを完了。主Gate `NOT_PASSED`、今回方式は採用せず元C1を維持する。
全4主指標の候補-C1差は年別・年等重みとも正確に0 pp、paired 95%CIはすべて[0,0] pp。
非劣性・年別条件・integrityは成立、Hit@3 CI下限が0で厳密な `> 0` を満たさない。
技術的未評価を0へ置換した結果ではなく、学習・予測・評価成立後の実際の一致である。

### 年別Primary

候補と保存Outer C1は以下の分子・分母・率が完全一致。差は各行0 pp。
率だけ表示丸め、計算・Gateには未丸め値を使用する。

| 年 | 指標 | 候補/C1分子 | 分母 | 候補/C1率(%) | 候補-C1差(pp) |
|---|---|---:|---:|---:|---:|
| 2024 | 1着 | 10,424 | 25,158 | 41.434136 | 0 |
| 2024 | 2着 | 6,041 | 25,106 | 24.061977 | 0 |
| 2024 | 3着 | 4,701 | 25,094 | 18.733562 | 0 |
| 2024 | 位置Hit@3 | 21,091 | 75,120 | 28.076411 | 0 |
| 2025 | 1着 | 9,886 | 24,789 | 39.880592 | 0 |
| 2025 | 2着 | 5,743 | 24,727 | 23.225624 | 0 |
| 2025 | 3着 | 4,677 | 24,739 | 18.905372 | 0 |
| 2025 | 位置Hit@3 | 20,241 | 73,989 | 27.356769 | 0 |

Hit@3適格/除外レースは2024:25,040/172、2025:24,663/203。
独立run-02を追加標本へ合算しない。Supportingを含む旧11指標も候補-C1で一致する。

補助比較は候補-STAT01。以下は年等重み差とpaired 95%CI、単位pp。

| 指標 | 差 | 95%CI |
|---|---:|---|
| 1着 | +2.522432 | [+2.181796, +2.877100] |
| 2着 | +0.387247 | [+0.000109, +0.784484] |
| 3着 | +1.150514 | [+0.796412, +1.528833] |
| 位置Hit@3 | +1.356253 | [+1.113567, +1.608788] |

補助Gateは `PASS / GO_TO_FREEZE` だが主Gateの代用は禁止。
これは元C1のSTAT01に対する成績差であり、順位別lambda選択による追加改善ではない。
年別のSTAT01分子/率/差、全Supporting、未丸めCIは `comparisons.json` と `report/primary-comparisons.csv` に保存。

### 収束・選択・再利用

両runともT22の収束候補はP1/P3:1,0.1、P2:1,0.1,0.01。
T2223は全順位1,0.1,0.01が収束。残る候補は位置別 `NUMERICALLY_NON_CONVERGED` として保存し除外。
Outer2025のA/B共通候補はP1/P3:0.1,1、P2:0.01,0.1,1。
Outer2024/2025の最小損失lambdaと選択lambdaは、全順位0.1。

| Outer | 位置 | 最小loss | SE_best | One-SE閾値 | 選択lambda |
|---|---|---:|---:|---:|---:|
| 2024 | P1 | 1.5533386969019967 | 0.005821740801730326 | 1.559160437703727 | 0.1 |
| 2024 | P2 | 1.6232173301252042 | 0.00499951723680545 | 1.6282168473620096 | 0.1 |
| 2024 | P3 | 1.6098466807766043 | 0.004610331411587908 | 1.6144570121881923 | 0.1 |
| 2025 | P1 | 1.5492666591601352 | 0.004240574269245829 | 1.553507233429381 | 0.1 |
| 2025 | P2 | 1.619880678891547 | 0.0035916402262214445 | 1.6234723191177685 | 0.1 |
| 2025 | P3 | 1.6087816365450562 | 0.003106267752045065 | 1.6118879042971013 | 0.1 |

lambda=1の損失は各閾値を超える。P2の0.01も0.1より最小損失を改善しない。
全順位同値になったためにlambdaを手動変更したり、grid/solver/許容差/Gateを調整したりしていない。

| pool（各run） | 一順位×lambda試行 | 収束 | 非収束 |
|---|---:|---:|---:|
| T22 | 24 | 7 | 17 |
| T2223 | 24 | 9 | 15 |
| T2224 | 6 | 6 | 0 |
| 合計 | 54 | 22 | 32 |

独立2run合計108試行、収束44/非収束64。各runのT2223は24試行を一度だけ学習し、
Outer2024モデル組立てとInner B検証へ再利用（これらの新規fit0）。run間の学習cache共有0。
T2224は各位置の選択0.1までの1→0.1だけを実行。基準C1再学習0。
両Outerは教師開放前に予測seal・保存モデルforward・基準cohort照合を完了。

### 入力・係数・独立再現

| 年 | レース | 出走 |
|---|---:|---:|
| 2022 | 24,394 | 170,835 |
| 2023 | 25,197 | 179,007 |
| 2024 | 25,212 | 179,089 |
| 2025 | 24,866 | 177,120 |
| 合計 | 99,669 | 706,051 |

元16値/型/保存順序/raw/anchor/全対象を照合。5/6/7/8/9車・欠損/有効0を理由とした追加除外なし。
各run・両年の全係数配列は旧Outer C1とstrict一致、最大絶対差0、予測はbyte/SHA一致。
新モデルの版/3lambda metadataは専用版のまま。旧モデルhashを書き換えた一致ではない。
実列挙116意味ファイルのsize/SHAが独立run間で一致（旧76/78件を流用しない）。
実読取りsource33ファイル/直接依存code356ファイルのSTART/END不変、manifest/COMPLETE照合も成功。

成果物root:
`/home/shinya/neo-keirin-artifacts/c1-position-lambda-01/run-20261006-214301-bd2607dd/`

`result/manifest.json` SHA-256:
`ee0c20e19f41673d0d68d47c439e04e928587ebd7dd7af774e20bf07f15961c4`（149,876 bytes）。
主/補助比較は `result/comparisons.json`、再現対象実列挙は `result/reproduction.json`、
位置別pool/選択は `report/pool-and-selection-audit.json`、旧モデルとの実配列照合は
`report/baseline-model-identity.json`、実行/全体検証は各 `*-execution/` に保存。
実行時未コミットコードと356ファイルのsealは `execution-code-final/`、
Git基準/契約/argvは `execution-provenance-final.json` に保存する。

実コマンド（hermetic runnerから、業務DB/HTTP拒否・資格情報除外で1回）:

```bash
php -d memory_limit=512M artisan keirin:c1:position-lambda plan
php -d memory_limit=512M artisan keirin:c1:position-lambda execute \
  --output-dir=/home/shinya/neo-keirin-artifacts/c1-position-lambda-01/run-20261006-214301-bd2607dd/result
```

実測は2026-10-07 06:55:03～14:40:39 JST、27,936.119241秒（7時間45分36秒）、
peak35,651,584 bytes（34MiB）、exit0、stderr0 bytes。実binaryは `/usr/bin/php8.5`。
独立128M人工試験の10MiBとは別の測定。通常全体テストは最終コードで2,880 passed/27,349 assertions、
既存PostgreSQL限定9 skipped。今回52/434、関連430/4129、PHP19構文/限定Pint/plan/diff検査も成功。
初回全体テストの出力照合失敗ログは保持し、最終成功へ読み替えない。

共有用 `C1-POSITION-LAMBDA-01-review.zip` は同rootに新規保存し、契約/比較/選択/収束/再現/実行ログ/
全21変更ファイルと未コミット差分を収録する。全学習・全予測JSONLは重複収録しない。
実在/サイズ/SHAとmember検証は同rootの `review-zip.json` に保存する。

次はコードと否定結果のレビューだけ。本固定比較に追加改善がないことと、
順位別選択一般の有用性・未来レースの性能を証明したことは別である。
2022～2025は使用済みdevelopment corpus、当時の発走前公開時点は保証できない。
正式C1置換/2026開放/LIVE/点数設定/追加試行は実施せず、未コミットのまま停止する。
