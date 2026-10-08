# C1-MARGINAL-P23-DECODER-01

## 契約と範囲

開始main/originは `85c411960241749d3285c72543f06a96d01bf05c`、PR88はレビュー後マージ済み。
作業branchは `feature/c1-marginal-p23-decoder-01`。旧順位別lambda不採用・元C1維持、
旧7比較の否定記録、score gap P3残件、旧E05/E06の結果は変更しない。
同じ現在C1のE05対E06比較は、関連コード・文書・指定成果物rootで見つからなかった。
旧E05は旧E03の確率を使った比較であり、今回とは別である。

- experiment: `C1-MARGINAL-P23-DECODER-01-v1`
- candidate: `C1_WINNER_FIXED_MARGINAL_P23`
- artifact_role: `FIXED_MODEL_DECISION_POLICY_COMPARISON`
- 学習モデル版: 元の `TACTICAL-HISTORY-SEQUENTIAL-POSITION-v2`。新モデルを作らない
- 候補識別: 元モデルSHA + 既存E05 decoder版 + 今回の契約
- 対象: 使用済みdevelopment年2024/2025。新しいholdoutではない
- 学習・lambda探索・入力生成・全確率再計算: 0回
- `historical_as_of_available=false`, `formal_adoption=false`, `live_use_authorized=false`, `points=null`
- DB/HTTP/Raw/2026実データ/LIVE/正式方式の置換: 禁止

## 固定資料

outcome-free入力:
`/home/shinya/neo-keirin-artifacts/stat35-c1-input-02/run-20260930-054458-8739da4b/result`
manifest: `7f4356b93f90203dc727dc95d6215a8d8ce3f44869c4441c3981087ff1099c26`。
読む本文は `c1-2024.jsonl` と `c1-2025.jsonl` のみ。2022/2023本文やSTAT35 sidecarは読まない。

保存Outer C1:
`/home/shinya/neo-keirin-artifacts/tactical-history-01-review-fix-20260916-01`
export: `4268b801b74b77cfb3a94b64832ff483e9eb5e3221ee8e75ceb467e5a5cf92e6`、
frozen contract: `5db3b57b08fb8b91dbe6c057b459e518252c454b0a4ff60531c2d671b7ce506a`。
保存run-01の各年 `C1-fit-YYYY/predictions.jsonl`、対応model/layout/selection/refit-path、
labels/sidecarだけを参照。子sealは固定exportのincluded/omittedから得る。
原C1 parent manifestとの参照関係、入力contract/COMPLETE、所属年/modelを照合する。
最終fit、PR88の新形式モデル、run-02、S/mean6/D等の候補を基準へ置き換えない。

## 検証とdecoder

実保存schemaを専用Reader/Decoderで検証する。固定入力は既存AgariC1Input Validatorに従い、
ID/車番/保存順序、raw/anchor/stat01_rankを保存確率とstrict照合する。
未知field・結果混入・型/有限性/範囲/分布異常・重複・不足/余分は拒否する。
IDは非単調でもよく、並べ替えない。重複索引はディスクSQLiteへ置く。

保存確率から既存E06を呼び、数学出力を保存decisionとstrict照合する。
`prediction_origin=EXPERIMENTAL_REFIT` と `reconstruction_verified=false` は元runの監査値であり、
今回の数値照合成功とは分離する。utilitiesによる条件付き分布の照合は行うが、
モデルfitや元の周辺確率の再生成は行わない。

候補は既存E05を変更せず使用する。保存P1を固定した上で、異なる非勝者の全順序対から
`P2(b)+P3(c)` 最大を選ぶ。E06も全組合せ探索であり、貪欲方式からの修正ではない。
primary exact tieは共通 `BT03E05-DECODER-TIE-v1`、Supportingは旧規則のまま。
epsilon、丸め、車番順、P1の後付けコピーは使わない。
P1と全Supporting/MAP/tieの一致を必須とし、原確率を変更しない。
候補の周辺確率を旧q2/q3 fieldへ偽装しない。

`marginal_score_old/new` は同じ保存周辺確率から計算し、各raceでnew >= oldを検査する。
このモデル期待一致数差は、実測の的中数差や未来の精度向上を意味しない。

## 正解開放・評価・再現

各独立runで両年候補を生成・seal・検証してからlabels本文を開く。
正解は予測生成器へ渡さない。labelsの完全な固定非結果fieldを入力と照合し、
既存Matcherでrank/status/同着群を検証、評価用raw等は固定C1から保持する。
専用候補JSONLは原行番号、cohort、元モデルSHA、確率semantic SHAを保存する。

既存Bt03e05MetricEvaluator/Bt03e05PairedBootstrapを再利用する。
主4指標はWINNER_HIT_AT_1/P2/P3/POSITION_HIT_RATE_AT_3。P1 aliasを重複した主指標にしない。
Hit@3は公式1/2/3着が全て一意の場合の位置一致数/(3×適格race数)。
Primaryの3人完全順序一致は、SupportingのMAP順序一致と別に出力する。
分母0は `NOT_EVALUATED` とし、0%や性能不合格へ置き換えない。

性能差CIは2000回/seed20260812/Type7/年層別paired race-cluster/年等重み。
FULLと比較基準を別抽選せず、既存bootstrapを変更しない。
主GateはTacticalHistory Evaluation::incrementalGateのまま:
全4 CI下限 > -0.0015、Hit@3 CI下限 > 0、各年Hit@3 >= 0、各年全4差 >= -0.003、integrity/再現成立。
補助の候補-STAT01は旧E05 Gateを別に報告し、主Gateの代用にしない。

1 execute内で2回、原資料から独立decode/評価する。学習0回、run-01候補のコピー0回。
実際の意味ファイルを列挙してbytes/SHAを比較し、時刻/PID/保存先/経過時間は意味比較しない。
直接依存codeと参照sourceのSTART/END、生成時seal、公開前検証を満たした場合だけCOMPLETEを作る。
技術失敗は証跡を残してNULL/NOT_EVALUATED。原本・過去失敗・旧正式成果物を上書きしない。

## 実行と結果

1 execute内の独立2runが完了。両年とも原資料を読み直してdecode/評価し、実列挙11意味ファイルの
bytes/SHAが一致。原資料23ファイル・依存code38ファイルのSTART/END、公開前照合とCOMPLETE成功。
対象2024:25,212race/179,089出走、2025:24,866race/177,120出走、合計50,078/356,209。
P1変更0、原モデル/入力/確率/utilities/Supporting不変、学習0回。

実行: 2026-10-08 07:08:20～07:13:00 JST、280.041657秒、peak32MiB、exit0、stderr0bytes。
出力:
`/home/shinya/neo-keirin-artifacts/c1-marginal-p23-decoder-01/run-20261007-220655-d861af4f/result`
manifest: `3e3a3f59bf841e661303e44bf625f5497d20443a24a3a3faba7308989d3c80de`。
ディレクトリ名はホストのUTC日時、実行記録の時刻はJST。

### 候補対C1: 年別の実測

率と差は表示だけを丸め、元の分子/分母から計算した。Hit@3の分母は3×全上位一意race数。

| 年 | 指標 | C1分子/分母 | 候補分子/分母 | C1率% | 候補率% | 差pp |
|---|---|---:|---:|---:|---:|---:|
| 2024 | 1着 | 10424/25158 | 10424/25158 | 41.434136 | 41.434136 | 0 |
| 2024 | 2着 | 6041/25106 | 6030/25106 | 24.061977 | 24.018163 | -0.043814 |
| 2024 | 3着 | 4701/25094 | 4754/25094 | 18.733562 | 18.944768 | +0.211206 |
| 2024 | Hit@3 | 21091/75120 | 21133/75120 | 28.076411 | 28.132322 | +0.055911 |
| 2025 | 1着 | 9886/24789 | 9886/24789 | 39.880592 | 39.880592 | 0 |
| 2025 | 2着 | 5743/24727 | 5725/24727 | 23.225624 | 23.152829 | -0.072795 |
| 2025 | 3着 | 4677/24739 | 4736/24739 | 18.905372 | 19.143862 | +0.238490 |
| 2025 | Hit@3 | 20241/73989 | 20282/73989 | 27.356769 | 27.412183 | +0.055414 |

### 年等重み差・paired 95% CI

| 指標 | 候補-C1差pp | 95% CI pp | 候補-STAT01差pp | 95% CI pp |
|---|---:|---:|---:|---:|
| 1着 | 0 | [0,0] | +2.522432 | [2.181796,2.877100] |
| 2着 | -0.058305 | [-0.182218,0.063783] | +0.328942 | [-0.058300,0.717246] |
| 3着 | +0.224848 | [0.089635,0.372148] | +1.375361 | [1.003344,1.756331] |
| Hit@3 | +0.055662 | [-0.011518,0.123724] | +1.411916 | [1.168545,1.658208] |

STAT01の年別分子は2024:9713/5876/4436/19959、2025:9336/5714/4369/19349（1/2/3/Hit@3順）。
分母は主比較と同一。全11指標の未丸め値・年別率・差はcomparison.jsonとreviewのmetrics.csvを参照。

### 選択変更と的中の入替り

| 年 | P1変更 | P2変更 | P3変更 | P2両的中/C1のみ/候補のみ/両不的中/除外 | P3同左 |
|---|---:|---:|---:|---|---|
| 2024 | 0 | 1210 | 1984 | 5795/246/235/18830/106 | 4381/320/373/20020/118 |
| 2025 | 0 | 1206 | 1993 | 5491/252/234/18750/139 | 4347/330/389/19673/127 |

合計P2変更2416/P3変更3977、P2的中差-29/P3+112、適格Hit@3一致位置差+83。
Hit@3改善/悪化/同数/除外race:2024=547/506/23987/172、2025=556/519/23588/203。
Primary完全順序一致は2024=1195→1188/25040、2025=1179→1191/24663。
Supporting MAP順序一致は1236/25040・1198/24663のまま。他のSupporting差/CIも全て0。

モデル上の周辺確率和gain合計:2024=20.787638947115923、2025=20.752076378727793。
1race平均0.000824513682/0.000834556277、全raceでgain>=0。
これは50,078raceでのモデル期待一致数差であり、適格raceだけの実測+83位置と同じ量ではない。

### 判定

主incremental Gate: `NOT_PASSED`。
非劣性=false（P2 CI下限-0.182218pp <= -0.15pp）、優越=false（Hit@3 CI下限-0.011518pp <= 0）。
年別条件=true、integrity/独立再現=true。今回方式は不採用・現行C1 decisionを維持する。
補助STAT01 Gateは `PASS / GO_TO_FREEZE` だが、主Gateを代用せず、今回の追加採用許可ではない。
P3の実測増加だけで採用しない。未来性能や正式C1の改善を主張しない。

### 実行記録と共有

実行済みコマンド（今回executeは1回。再実行の指示ではない）:

```bash
php -d memory_limit=128M artisan keirin:c1:marginal-p23-decoder plan
php -d memory_limit=128M artisan keirin:c1:marginal-p23-decoder execute \
  --output-dir=/home/shinya/neo-keirin-artifacts/c1-marginal-p23-decoder-01/run-20261007-220655-d861af4f/result
```

runner.phpが環境をtesting/SQLite/共有config cacheなしに分離し、CommandがアプリDB/HTTPを明示拒否した。
stdout/stderr/argv/開始終了/所要/exitは同じrun配下のexecuteに保持する。
共有ZIP:
`/home/shinya/neo-keirin-artifacts/c1-marginal-p23-decoder-01/run-20261007-220655-d861af4f/C1-MARGINAL-P23-DECODER-01-review.zip`
実在・size/SHAの確認記録は同じrunのreview-zip.json。原確率・学習本文はZIPへ重複コピーしない。
契約・比較/変更数・再現・seal・検証ログ・差分を収録し、保存寄与の独立再集計も照合する。

## 人工検証

専用49 tests/372 assertions、関連270 tests/1551 assertions成功（128MB指定）。
独立128MBプロセスで100MiB超の人工JSONLをstream処理し、exit0/全18000race/P1変更0を確認。
同値全対列挙、近接値非同値、5/7/9車・欠番、非単調ID、保存E06/Supporting照合、
不正予測/labels/版/改変拒否、正解変更でdecision不変、両年seal前の開放拒否、
終了時driftによるCOMPLETE拒否、分母0の未評価、既存Gate境界と独立再現を検証する。
最終コードの通常全体は1回、2929 passed/27721 assertions/既存9 skipped、256.48秒、exit0。
変更9PHP構文・限定Pint・128MB planが成功。git diff --checkも確認する。
旧テスト削除/緩和/新規skipは行わない。DB変更/Migration/正式モデル変更なし。
次は未コミットのコード・否定結果レビューのみ。commit/push/PR作成/merge/次工程の自動開始なし。
