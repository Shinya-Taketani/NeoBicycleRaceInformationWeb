# STAT-36-C1-COMPARE-01

## Scope And Contract

2026-10-02の最新ユーザー許可による固定source限定development比較。
開始main/origin: `42afdfceb40c049159df6b53d07d2b45066a7fcb`、cleanから
`feature/stat36-c1-compare-01` を作成した。

experiment `STAT-36-C1-COMPARE-01-v1`、candidate `C1_PLUS_S`、
model `STAT36-C1-PLUS-S-SEQUENTIAL-POSITION-v1`。
保存run-01のOuter C1を基準に、12 STAT、既存戦法4回数、表示S回数
`STAT36_DISPLAYED_START_COUNT` の17項目を全順位で新規推定する。
STAT01 anchor係数1。mean6・alpha・整数配点・単調性・時刻や品質の特徴量化なし。

固定sourceはContractの3ディレクトリ。S manifest
`f175deff20fe8905b40e92ffa2d9ff16de71f432584c9e13e129d47045306437`、
outcome-free C1 manifest
`7f4356b93f90203dc727dc95d6215a8d8ce3f44869c4441c3981087ff1099c26`、
旧C1 export manifest
`4268b801b74b77cfb3a94b64832ff483e9eb5e3221ee8e75ceb467e5a5cf92e6`、
旧frozen contract
`5db3b57b08fb8b91dbe6c057b459e518252c454b0a4ff60531c2d671b7ce506a`。
実読取り依存だけを固定manifest/COMPLETE/子sealからSTART/END検証する。
元候補のNOT_AUTHORIZEDと旧Bundleの一般学習/LIVE拒否は変更しない。
専用readerだけがこの固定manifestを限定実験へ接続する。人工pinはtestingかつ/tmp内だけ。

historical_as_of_available=false、formal_adoption=false、live_use_authorized=false、
2026_access=FORBIDDEN。Sの期間/基準日/訂正時点はUNKNOWNのまま。
この許可と性能GateはS時点適格性・発走前実運用性能の証明ではない。
旧pilot BLOCKED_INPUT_SEMANTICS、C1正式モデルと全旧成果物は維持する。

## Input And Numerics

C1レース行とS出走行をC1順にstreaming接続。year/race/entry/bike、
原C1非結果値・型・順序、候補provenanceのC1 seal/semantic/order digestを照合。
S原行の既知metadataを検証し、学習には識別子とint|nullのSだけを渡す。
0～9999、0有効、NULL非active・対象保持。不正値をNULLに丸めない。
C1 history4値は原本のまま、実験projectionだけ17要素とする。player_id補完なし。
候補coverageを検証し、mean6側のnumeric/null件数は使わない。

Stat35C1ComparisonのLayout/LayoutBuilder/Objective/Optimizer/Trainer/
LoadedModel/ModelLoaderを、finalかつ具体Layout/Contract型依存のため専用型へ適応。
数値演算順、目的関数、正則化、射影/prox、restart、line search、定数は不変。
Sources/Dataset/Contract/Experiment/EvaluationはS接続、用途、比較名、分子保存へ適応。
EffectBinBuilder、DTO、One-SE、Predictor/E06 decoder、metrics/bootstrap/Gateは再利用する。
旧正式クラスは変更しない。16項目時のbit-exact、17項目勾配と独立prox最適性を人工検証する。

grid8候補、strong-to-weak、収束済みだけwarm start、200 accepted updates、
係数変化1e-7、相対目的変化1e-10、reference step1残差/中心化1e-7。
training-local bin/support、M/G/edgeは新しいactive構造から算出する。
非収束診断を保存しOne-SEから除外、選択refit失敗はfail closed。

Inner A=2022→2023、Outer2024 refit=2022/2023。
双方予測seal/cohortと保存モデルforward確認後2024教師開放。
Inner B=2022/2023→2024、Outer2025 refit=2022～2024。
2025は同じ確認後に評価用だけ開放、両Outer固定後に集計する。
旧C1はforward照合のみ、再学習0。各runのInner Aは1回のみ。

## Evaluation And Execution

主比較C1_PLUS_S-C1、補助C1_PLUS_S-STAT01。
Primary 1着/2着/3着/位置Hit@3とSupporting MAP/marginalを区別。
paired同対象/同分母、年層別race-cluster2000回、seed20260812、Type7、年等重み。
Gateは旧incrementalGateを再利用: 4CI下限>-0.0015、Hit@3下限>0、
各年Hit@3>=0、各年4差>=-0.003、integrityと独立再現。
分母0・入力異常・非収束はNOT_EVALUATED。補助Gateで主Gateを代用しない。

専用command:

```bash
php -d memory_limit=512M artisan keirin:stat36:c1-compare plan
php -d memory_limit=512M artisan keirin:stat36:c1-compare execute --output-dir="$RUN/result"
```

固定root `/home/shinya/neo-keirin-artifacts/stat36-c1-compare-01/`、新規保存だけ。
execute1回内でrun-01と独立再学習run-02を各1回。
教師開放、途中診断、モデル・予測・寄与・CI、source/code不変と公開前seal、
manifest/COMPLETEを保存。DB/HTTP拒否、process-local offline環境、共有設定変更なし。
独立128M人工試験と実処理512Mを区別する。重要成果物を/tmpだけに置かない。
実データ生成・評価・再現の実績は以下に記録する。

## Regression

P3は実改行で未知fieldを検証し、理由Unexpected object fields.までassertする。
改行不正と正常の対照を別テスト化。旧Rows/Artifactsは無変更。
新人工試験はentry/race形式差、欠落/余分/重複/順序/識別/未知field/版/型/範囲、
0/NULL/metadata隔離、元Bundle拒否、時系列依存、保存forward、独立再学習、
Gate境界、終了source drift非公開、独立128M streamingを検証する。
旧テスト削除・緩和・新規skipなし。

最終コード関連試験は128M指定で214 tests/1280 assertions成功（新比較37 tests/
251 assertionsを含む）。P3は修正前に改行理由との不一致で失敗し、修正後成功。
変更PHP19件の構文、限定Pint、diff check成功。
通常全体1回は2632 passed/9既存skip/24661 assertions、195.80秒、exit0。
実行証跡rootは
`/home/shinya/neo-keirin-artifacts/stat36-c1-compare-01/run-20261002-hSnkpCBN/`。
512Mのexecuteを1回実行し、内部の初回run-01と独立再学習run-02を完了した。

## Development Results

主Gateは `NOT_PASSED`。既存C1を維持し、今回のS追加方式は採用しない。
2024では1着/2着/位置Hit@3の点推定が微増、3着は悪化。2025は4指標とも悪化した。
年等重みでは1着だけ微増、2着/3着/位置Hit@3は悪化し、4指標すべての差分95%CIは0を含む。
技術的な実行・再現成功を、予測性能向上とは扱わない。

### Primary Counts And Rates

率は%、差はpercentage points（pp）。表示だけ丸め、Gate/CIは未丸めの寄与から計算した。
位置Hit@3は公式1～3着すべて一意のレースの位置一致数 / (3 x 適格レース数)。
着順別率の平均や3連単的中率ではない。両モデルで同じ指標別分母を使用した。

| 年 | 指標 | C1 分子/分母 | C1 % | C1_PLUS_S 分子/分母 | C1_PLUS_S % | 差 pp |
|---|---|---|---|---|---|---|
| 2024 | 1着 | 10424/25158 | 41.4341 | 10435/25158 | 41.4779 | +0.043724 |
| 2024 | 2着 | 6041/25106 | 24.0620 | 6047/25106 | 24.0859 | +0.023899 |
| 2024 | 3着 | 4701/25094 | 18.7336 | 4680/25094 | 18.6499 | -0.083685 |
| 2024 | 位置Hit@3 | 21091/75120 | 28.0764 | 21092/75120 | 28.0777 | +0.001331 |
| 2025 | 1着 | 9886/24789 | 39.8806 | 9879/24789 | 39.8524 | -0.028238 |
| 2025 | 2着 | 5743/24727 | 23.2256 | 5697/24727 | 23.0396 | -0.186031 |
| 2025 | 3着 | 4677/24739 | 18.9054 | 4652/24739 | 18.8043 | -0.101055 |
| 2025 | 位置Hit@3 | 20241/73989 | 27.3568 | 20162/73989 | 27.2500 | -0.106773 |

補助STAT01比較の候補分子/分母は上表と同じ。

| 年 | 指標 | STAT01 分子/分母 | STAT01 % | C1_PLUS_S - STAT01 pp |
|---|---|---|---|---|
| 2024 | 1着 | 9713/25158 | 38.6080 | +2.869862 |
| 2024 | 2着 | 5876/25106 | 23.4048 | +0.681112 |
| 2024 | 3着 | 4436/25094 | 17.6775 | +0.972344 |
| 2024 | 位置Hit@3 | 19959/75120 | 26.5695 | +1.508253 |
| 2025 | 1着 | 9336/24789 | 37.6619 | +2.190488 |
| 2025 | 2着 | 5714/24727 | 23.1083 | -0.068751 |
| 2025 | 3着 | 4369/24739 | 17.6604 | +1.143943 |
| 2025 | 位置Hit@3 | 19349/73989 | 26.1512 | +1.098812 |

### Year-Equal Paired Differences

各年差の等重み平均。CIは同じ抽選を両モデルへ適用した年層別paired
race-cluster bootstrap 2000回、seed20260812、Type7。独立runを標本へ加算しない。

| 指標 | 対C1 差 pp | 対C1 95%CI pp | 対STAT01 差 pp | 対STAT01 95%CI pp |
|---|---|---|---|---|
| 1着 | +0.007743 | [-0.092707, +0.115941] | +2.530175 | [+2.184243, +2.883554] |
| 2着 | -0.081066 | [-0.221008, +0.070031] | +0.306181 | [-0.086448, +0.691869] |
| 3着 | -0.092370 | [-0.210904, +0.023789] | +1.058143 | [+0.694444, +1.422020] |
| 位置Hit@3 | -0.052721 | [-0.129355, +0.030178] | +1.303533 | [+1.061802, +1.553460] |

主Gate: integrity=true、non_inferiority=false、superiority=false、temporal=false。
2着/3着のCI下限が-0.15ppより大きい条件を満たさず、位置Hit@3のCI下限は0以下、
2025の位置Hit@3差も負である。年別4差>=-0.30ppの条件は満たした。
補助STAT01 Gateは全条件PASS (`PASS / GO_TO_FREEZE`) だが、主Gateの代用ではなく、
正式採用・LIVE許可・S時点適格性の承認ではない。SupportingとPrimary別比較、
全指標の未丸め値・分母・寄与はresult内のJSON/JSONLを正本とする。

## Fits And Integrity

両runで同じ候補状態・bin/support・係数・モデル・予測・CI・Gateになった。
Inner Aの全3順位収束候補は1/0.1、その他6候補は1着200更新で非収束。
Inner Bは1/0.1/0.01が全3順位収束、その他5候補は1着200更新で非収束。
A/B共通候補からの2024/2025選択はともに0.1。両Outerの選択refitも全3順位で収束。
選択refitのaccepted updatesは2024が125/76/110、2025が107/94/109。
非収束を選択から除外しただけで、200更新上限・閾値・grid・採用基準の変更なし。
各runのInner Aは1回、旧C1再学習は0回。

| 年 | レース | 出走 | S数値 | S NULL | 有効0 |
|---|---|---|---|---|---|
| 2022 | 24394 | 170835 | 170835 | 0 | 47139 |
| 2023 | 25197 | 179007 | 179007 | 0 | 43924 |
| 2024 | 25212 | 179089 | 179089 | 0 | 42750 |
| 2025 | 24866 | 177120 | 177120 | 0 | 43716 |
| 合計 | 99669 | 706051 | 706051 | 0 | 177529 |

元C1の集合・出現順・非結果値/型は不変、両Outer旧C1モデルforward一致。
UNKNOWN_RACE_CLASSだった1003出走を除外しない。S NULL非active/対象保持は人工回帰で確認。
2024教師は2024予測seal/forward/両モデル対象照合後、2025教師は2025の同じ確認後に開放。
byte hash確認と教師開放は別監査。S固有の時点不確実性を解消したとは扱わない。

実読取りsource44ファイル・直接依存code362ファイルのSTART/END一致と公開前sealを確認。
独立2runの76の意味上の成果物がbyte/SHA一致。日時/PID/経過時間/出力先は比較対象外。
manifest 148433 bytes、SHA-256
`bd51871d8b0598bb5665517847e6400532f577092f03fe3f735b04583e218585`、COMPLETE照合成功。
実行は2026-10-02 06:58:14～12:08:11 JST、18597.176秒（5時間9分57秒）、
peak35651584 bytes（34MiB）、process-local512M、exit0、stderr空。

保存先は上記run rootの `result/`。`execution-code/` に実行時コードを保存し、
`execute-execution/` に正確なargv・stdout・stderr・時刻/exit、
`full-final-execution/` に最終通常全体試験の記録を保存。
小容量 `STAT-36-C1-COMPARE-01-review.zip` は契約/比較/収束/再現/ログ/差分用で、
全学習入力・全予測・Rawの重複コピーなし。実在/サイズ/SHAは同rootの
`export-verification.json` に記録する。

Git変更は専用13クラス、CompareStat36C1Command、人工Feature/数値Unit/fixture/
独立memory runner、P3既存テスト、本文書、MASTER PLANの計21ファイル（PHP19）。
旧production/S候補生成器/Parser/schema/モデル/成果物は無変更。
DB/HTTP/Raw/Migration/2026実データ/LIVE/正式採用は0、commit/push/PR操作なし。
状態は `COMPLETED_NOT_ADOPTED_AWAITING_REVIEW`。次は今回差分・結果のレビューのみ。
S期間・基準日・訂正時点は引き続き未確認であり、未来精度や未来情報非混入の証明ではない。
