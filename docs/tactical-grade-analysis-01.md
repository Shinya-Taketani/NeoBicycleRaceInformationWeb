# TACTICAL-GRADE-ANALYSIS-01

## 実データ集計前の固定契約（2026-09-18）

PR #58 merge `67d6795fde722cae96536b54d8894b761bd8fd16` から開始。
ユーザー確認によりPR #58のコード・成果物レビューと旧PR #57報告ZIP照合は完了。
過去文書のレビュー待ちは当時の履歴として保持する。

### 対象・原本

`/home/shinya/neo-keirin-artifacts/tactical-history-01-review-fix-20260916-01/` の
修正版run-01、C1 Outer 2024（25,212レース）と2025（24,866レース）だけ。
model-run.json・完了記録・report-export-manifest.json・入力manifestから参照を解決し、
モデル版 `TACTICAL-HISTORY-SEQUENTIAL-POSITION-v2`、solver版
`TACTICAL-HISTORY-CONSTRAINED-EUCLIDEAN-FISTA-v2`、training streamの年集合
2022-2023 / 2022-2024を確認する。run-02を標本へ加算しない。
最終モデル・63件の接続確認・C0・E06へ置換しない。
既存予測の内訳分析であり、再学習、推論、Gate、bootstrap、2026参照は行わない。

### 当該出走級班の根拠と正規化

RaceDetailParserはPJ0315.sensyuTypeInfo[].kyuhanをgrade、prevKyuhanをpreviousGradeに分離する。
PC0201の車番/登録番号と照合し、RaceRepository::updateRaceDetailは競輪場・日付・レース番号、
既存出走の車番集合とexternal_player_idを検証して当該race_entries.gradeへ保存する。
JSJ017のRaceEntryListParser/Repository::syncRaceDayはgradeを更新しない。
現在プロフィールや競走得点から推定しない。保存済み評価入力にはgradeがないため、
不足属性は対象IDと2024/2025日付をSQLで限定したREAD ONLY DBから取得する。
DBから結果・着順は取得しない。対象全出走のID/車番/件数を照合し、START/ENDを比較する。

grade_rawはDB保存文字列そのもの（元HTTP原文ではない）。全角英数を半角化し空白だけを除去後、
次の明示対応表だけで分類する。曖昧な文字列の部分一致・補間は行わない。

| 正規化前（幅・空白正規化後） | 区分 |
|---|---|
| SS, S級S班 | SS |
| S1, S級1班 | S1 |
| S2, S級2班 | S2 |
| A1, A級1班 | A1 |
| A2, A級2班 | A2 |
| A3, A級3班 | A3 |
| NULL/空/その他 | UNKNOWN |

S3/A4/L1/B1/B2および各「級・班」表記は既知だが今回の区分外として別記録しUNKNOWNへ集約。
UNKNOWNも元母集団へ保持する。識別不一致、重複、race欠落はUNKNOWNではなく整合性エラー。
`publication_time_verified=UNKNOWN`。当該過去出走への対応確認を発走前公開保証とは呼ばない。
年、race/entry ID、車番、player_id、日付、車立て、級班原文/正規化/出典/確認状態をsnapshot保存。
レース段階は保存race_typeの明示的な「予選」「準決勝」「決勝」だけを補助分類し、
それ以外はUNKNOWN。レース番号から推定しない。

### 集計・不確実性

保存decisionのprimary_position_1/2/3_bikeを使用し選び直さない。
Bt03e05MetricEvaluator::raceComparisonのPOSITION_1/2/3_ACCURACY寄与を各予測選手の級班へ割当。
公式順位が一意でない場合はNO_UNIQUE_OFFICIAL_POSITIONとしてその位置だけ除外。
正常同着・異常結果の既存解釈を維持し、不正rank/statusは拒否する。

A: 年×着順×級班、B: 年×5-9車×着順×級班、C: 両年を件数加重合算した着順×級班。
補助: 年×レース段階×着順×級班。空セルも出力する。
選択=適格+除外、適格=的中+不的中。全級班合計は保存済みレース別寄与の分子/分母と厳密一致。
2024分母25,158/25,106/25,094、2025分母24,789/24,727/24,739。
分子を丸めた率から逆算しない。各位置の合算率をHit@3と呼ばない。

Wilson 95%CI（連続性補正なし）、z=1.959963984540054。
center=(x/n+z²/(2n))/(1+z²/n)、half=z*sqrt(p(1-p)/n+z²/(4n²))/(1+z²/n)。
n=0はrate/CI=NULL、NOT_EVALUABLE。x=0/nでも幅を0にしない。
独立二項試行を仮定した参考区間であり選手/開催内相関は未補正。
年/車立て構成差、予測に選ばれた選手だけの条件付き分析である制限を明記する。
CIの重なりで有意差・採用を判断せず、小標本から一般化しない。

### 保存と検証

別namespace/Artisanでplan（DB/書込みなし）、execute、reproduceを提供。
128MB、JSONLストリーミング。原本・入力属性snapshot・定義・直接依存コードをsealする。
生成時の期待sealで公開前検査し、途中失敗/原本drift/生成物改変時は公開しない。
再現は保存済み分析入力のみから明細・集計を再生成しhash一致を要求し、DB/モデルは不要。
原本は不変。出力は既存合意root内の新しい`tactical-grade-analysis-01-20260918-01`配下。
再学習・新規取得・本番write・Migration・2026・LIVE・採用判断を行わずレビュー待ちで停止する。

## 実行結果

状態: `VERIFIED_AWAITING_REVIEW`。出力root:
`/home/shinya/neo-keirin-artifacts/tactical-grade-analysis-01-20260918-01/`。
評価束: `evaluations/outer-c1-2024-2025-01/`。

修正版run-01のmodel-run/完了/旧report-export-manifestから31原本を固定した。
訓練年の実行済みtraining streamは2024モデルが2022:24,394/2023:25,197、
2025モデルがそれに2024:25,212を加えた集合で、年集合・重複・元hashを確認した。
モデルを読み込んで再推論したわけではない。

| 年 | レース | 出走級班確認 | 1着 的中/適格 | 2着 的中/適格 | 3着 的中/適格 | 位置別除外 |
|---|---:|---:|---:|---:|---:|---|
| 2024 | 25,212 | 179,089/179,089 | 10,424/25,158 | 6,041/25,106 | 4,701/25,094 | 54/106/118 |
| 2025 | 24,866 | 177,120/177,120 | 9,886/24,789 | 5,743/24,727 | 4,677/24,739 | 77/139/127 |

級班確認率100%、UNKNOWN=0、識別不一致=0。位置別除外は合計621選択（同一raceの複数位置を含む）で、
NO_UNIQUE_OFFICIAL_POSITION。race数とは混同しない。UNKNOWNセルは0/0、率/CI=NULLで残した。
補助段階のUNKNOWNは2024:13,247、2025:13,116レース。保存属性から予選/準決勝/決勝を確定できないもので、主集計から除外しない。

### 観測率（%）

| 年 | 級班 | 1着 | 2着 | 3着 |
|---|---|---:|---:|---:|
| 2024 | SS | 43.01 | 16.89 | 9.09 |
| 2024 | S1 | 35.99 | 21.80 | 16.30 |
| 2024 | S2 | 34.93 | 21.53 | 17.42 |
| 2024 | A1 | 39.56 | 24.95 | 19.16 |
| 2024 | A2 | 43.08 | 22.62 | 19.52 |
| 2024 | A3 | 50.60 | 26.98 | 19.94 |
| 2025 | SS | 45.43 | 18.06 | 12.50 |
| 2025 | S1 | 35.88 | 20.94 | 16.64 |
| 2025 | S2 | 34.42 | 22.35 | 18.28 |
| 2025 | A1 | 37.45 | 23.99 | 19.08 |
| 2025 | A2 | 43.16 | 20.31 | 20.02 |
| 2025 | A3 | 47.14 | 26.13 | 19.42 |

完全な分子/分母・Wilson 95%CI・年/車立て/段階別および件数加重合算はsummary.json/summary.csvに記録。
両年ともA3の1着・2着が高めで、7車でも1着51.85%/48.02%、2着27.44%/26.33%と傾向が残る。
3着は2024がA3、2025がA2の観測率最大で、全位置を同じ傾向とは言えない。
7車の3着も2024のA1/A2/A3が19.06/19.21/19.48%、2025は19.07/19.88/18.99%と差は小さい。
SSの3着は7/77、8/64で少なく、5/8車や9車の一部A級セルも極小標本。
級班別優越性、CIの重なりによる有意差、因果効果、必ず当たりやすいという結論は出さない。

### 整合性・再現性

全位置・全レースで旧C1寄与と一致し、全級班合計の分子/分母と未丸め率は旧評価と完全一致。
READ ONLYのsession/transactionは開始・終了ともon/on。
2024/2025の対象race IDに限定したraces/race_entries必要列だけを照会し、results/playersは照会していない。
対象属性snapshotの開始/終了SHAは `9a89f05afa905bbb36b0935ab2d6a3ac8c517aaf41ba98664665709258a345d7`。
原本31ファイルと両Outerモデルは不変。2024モデルSHA:
`38a78da4efc01249d3ad579f620c9473e1c231d19dc5eeb2e0b9e5a6bf26c249`、2025:
`f37452a8fe5cef4108f5c0357c1b880222742f7610ea5700fb54caa636bdce43`。

DBを無効化した再現で150,234明細とsummary JSON/CSVが一致。
details SHA: `aec66640adc76f7d6d3f4558cb1d6e8538038b8c19ff269c6fa368f0983d68dc`。
summary SHA: `97baae93832e07da6a19e710b11b6850384c0812254dcddfcc7039ce57b0dc32`。
実行ピーク69,730,304 bytes、再現63,438,848 bytes（128MB設定）。
別namespaceの純粋なMatcher/Evaluator/生成時seal writerだけを共用し、最終モデル専用Sources/ModelIdentity契約は変更しない。

### 検証・コマンド

```bash
php -d memory_limit=128M artisan keirin:backtest:tactical-grade-analysis --plan

PGOPTIONS='-c default_transaction_read_only=on -c statement_timeout=120000' \
php -d memory_limit=128M artisan keirin:backtest:tactical-grade-analysis --execute \
  --source-root=/home/shinya/neo-keirin-artifacts/tactical-history-01-review-fix-20260916-01 \
  --output-root=/home/shinya/neo-keirin-artifacts/tactical-grade-analysis-01-20260918-01 \
  --analysis-id=outer-c1-2024-2025-01

DB_CONNECTION=disabled-grade-offline DB_URL='' \
php -d memory_limit=128M artisan keirin:backtest:tactical-grade-analysis --reproduce \
  --output-root=/home/shinya/neo-keirin-artifacts/tactical-grade-analysis-01-20260918-01 \
  --analysis-id=outer-c1-2024-2025-01
```

新規66テスト/1,174 assertions。型/UNKNOWN、予測選手による分類、当時級班変更、手計算、5-9車セル、
同着/異常結果、識別不整合、2026拒否、原本START/END drift、DB属性drift、READ ONLY書込み拒否、
13成果物の生成後改変、中断証拠保持、DB/原本なし再現を確認。
全体PHPUnit 128MB: 1,306件中1,297成功・PostgreSQL専用9skip、10,356 assertions。
変更9PHPの構文/Pint、git diff --check成功。全体Pintは未変更のBt03e08BoundedMemoryTest.phpの既知statement_indentationのみ失敗。
途中の人工テストでは、一時Generatorをiterator_to_arrayへ直接渡す例外ケースでPHP 8.5.4の終了139を再現。
Generatorを保持してrewind時の拒否を検証する形で成功し、テストを削除/無効化せず失敗ログも保存した。実データ分析コードはその前後で不変。
新規fit/推論/Gate/bootstrap/本番write/Migration/取得/2026参照=0。新規採用や予測精度改善の主張ではない。
