# GROWTH-POINT-ANALYSIS-01-v2

## 結果閲覧前契約 / 2026-09-19 / PR #60

開始HEAD `8885dad598ac26f4730cc083d7735394305f5927`、既存experimentブランチで作業。
v1はQUANTILE_BASED_POINTとして正しい歴史記録。PHP・文書・固定bundle・報告ZIPを変更しない。
v2はSIGN_PRESERVING_POINT。意味付けの異なる独立版であり、v1の計算バグ修正ではない。

## 入力と再利用

固定v1 bundleのLOCKED/manifest/生成時sealと実行時コードをSTART/ENDで検証する。
cohort/history snapshotからv1 Workspace/Signalsをそのまま再利用し、v1 growth-inputおよび
growth-detailsの全行を未丸めのまま再生成結果と照合する。不一致なら公開しない。
対象は2024/2025の50,078レース・356,209出走。前走識別・残差式・raw/status・同一開催分類・対象正解は不変。
execute/reproduceとも本番DBを使わず、ディスクSQLiteは今回rootの作業領域だけに使用する。
再現にはv2 bundleとsources.jsonで参照する固定v1 bundleが必要。history原本を重複コピーしない。

## ポイントと閾値

A/Bそれぞれ、2024用は2022-2023、2025用は2022-2024のv1と同じraw training標本のみ。
負側abs(raw)、正側rawに分割し、0はどちらにも入れない。
Type7の確率を `0.3333333333333333` / `0.6666666666666666` に固定。
各側n<3ならthreshold=NULL、該当側rawのpoint=NULL・point_status=INSUFFICIENT_SIGN_TRAINING。
rawのstatusはv1と同じまま保存し、point化の利用不能状態とは別に記録する。

- raw欠損: point=NULL。0への補完なし。
- raw=0: trainingの件数にかかわらずpoint=0。
- raw<0: magnitude<NEG_P33は-1、NEG_P33<=magnitude<NEG_P67は-2、それ以外-3。
- raw>0: raw<POS_P33は+1、POS_P33<=raw<POS_P67は+2、それ以外+3。
- 同値境界はそのまま使用し、空区分を許容。epsilon/jitter/符号反転なし。
- CはA/B両方のpointが利用可能な場合のみ1:1加算。-6～+6。最適化なし。

## 集計・比較

v1 Analysisの集計、参考Wilson、平均同順位Spearman、診断分類を再利用し、数値基準を変更しない。
raw Spearmanは全層でv1未丸め値との完全一致を要求。targetの正解はraw/point算出に使用しない。
年・grade・競走区分・grade×競走区分・same_meeting_previous別に全pointと欠損を保存。
win/top3/mean FPについて、隣接全point（空区分はNULL）と正常30件以上の占有pointの差・違反を別記。
追加したmean FP単調性は補助診断であり、v1分類の条件に加えない。
v1→v2遷移、分布、0の割当、符号違反、欠損、同一開催A=0を保存する。
既存C1 P1候補/単独勝者/取り逃し/誤りraceのpoint差をv1/v2で比較する。
率は正常FINISHED/TIEDが分母。Wilsonは選手・レース・開催内相関未補正。
モデル精度改善・因果効果・STAT採用を結論しない。BACKFILLED_FINAL_RESULT/DEVELOPMENT_ONLY、公開時点UNKNOWNを維持。

## 保護・保存

新namespace GrowthPointAnalysisV2、新command keirin:backtest:growth-point-analysis-v2。
planはファイル/DB読込なし。execute/reproduceはDBを無効化して実施する。
root: `/home/shinya/neo-keirin-artifacts/growth-point-analysis-01-v2-20260919-01/`。
旧v1 rootへは要求されたreproduceの新規stage/event追加だけを行い、固定成果物は上書きしない。
128MBで人工テスト後に実集計。C1・solver・decoder・STAT・Gate・bootstrap・2026・DB・Migration・取得には変更なし。
commit/push/PR操作なし、未コミットでレビュー待ち。

## 実行結果 / レビュー待ち

2026-09-19、契約固定後に全50,078レース・356,209出走をDB無効で再集計。
入力/明細の全行一致、不一致0。raw Spearmanは全層でv1保存未丸め値と完全一致。
v1のPHP・文書・固定bundle・旧ZIPは開始/終了でbyte-for-byte不変。

|年|A raw=0件数|v1 point|v2 point|同一開催A raw/point=0|A欠損|B/C欠損|
|---|---:|---:|---:|---:|---:|---:|
|2024|119668|-2|0|118820|206|8249|
|2025|118519|-2|0|117729|202|7702|

負/正/0の符号違反0、sign training不足0。欠損を0で埋めずv1と同じ母集団を維持。

|年|signal|raw rho (v1=v2)|v1 point rho|v2 point rho|v1/v2年別分類|
|---|---|---:|---:|---:|---|
|2024|A|0.016585616|0.012970764|0.016604476|NON_MONOTONIC|
|2025|A|0.016974873|0.013309081|0.016911710|NON_MONOTONIC|
|2024|B|-0.072250623|-0.069132462|-0.069888664|NON_MONOTONIC|
|2025|B|-0.070198534|-0.068076967|-0.067532041|NON_MONOTONIC|
|2024|C|定義なし|-0.050278547|-0.056420884|NON_MONOTONIC|
|2025|C|定義なし|-0.048288565|-0.053350617|NON_MONOTONIC|

表示は丸め値、判定は未丸め値。Aは弱い正方向、Bは逆方向のまま、Cも非単調。
Bのmean FPは両年で隣接すべて減少しても、win/top3の違反が残り分類基準は変更しない。
F1/F2のA1_A2ではAの強弱が年次で逆転、F2 A_CHALLENGEは両年でA1_A2より正方向だが非単調。
pointの符号意味を保持できたことと、次走成績の単調性・モデル精度改善は別である。

`analysis-id=outer-c1-growth-v2-2024-2025-01`、生成16ファイルのsealがDB無効reproduceで完全一致。
v2 execute/reproduceはピーク50,335,744 bytes、v1 reproduceは44,040,192 bytes。すべてmemory_limit=128M。
v1も閾値/入力/明細/集計/全診断が一致し、旧固定成果物を上書きしていない。
新規31 tests/127 assertions、v1関連を含む72 tests/286 assertions。
全体1434 tests（1425成功、9 skip）/10858 assertions。変更PHPのPint/構文検査成功。
全体Pintは未変更の `Bt03e08BoundedMemoryTest.php` の既存statement_indentationのみ失敗。
実行・再現・plan・テストの正確なコマンドはroot/logs/*.json、全point表・遷移・C1診断はroot/report/REPORT.mdへ保存。
共有ZIP名は `GROWTH-POINT-ANALYSIS-01-V2-report.zip`。ZIP検証値はroot/report-zip-verification.jsonに記録。
新規テストの書式修正前後を区別し、実行時コード保存と最終テストコード保存を両方保持する。
