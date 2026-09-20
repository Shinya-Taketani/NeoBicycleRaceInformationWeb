# GROWTH-TREND-ADJUSTMENT-CALIBRATION-01

## 結果閲覧前契約

PR #62 MERGED、main `17cc492e077034a4ebab46594cb2a6e1d3c3642f`、開始時clean。
専用branch `experiment/growth-trend-adjustment-calibration-01`。旧実験・C1は変更しない。
signalはreview-fix-02のMEETING_DELTA_LAG_1（競走得点point）だけ。残る40候補はpredictionへ渡さない。
固定run-01 Outer C1の50,078レース・356,209出走、両Outer lambda=0.1。モデル再学習・bin再構築なし。

2024のVALID abs(raw)のみのType7 P99をSCALE_P99とする。0/非有限は停止。
`g=clamp(raw/SCALE_P99,-1,1)`、非VALIDはg=NULL、予測時だけNO_ADJUSTMENT。
`anchor'=anchor+(k/100)*g`、k=-50..50（101個）、全position共通。
NULL/0/k=0は元anchorをそのまま保持。相手が変わった場合の確率不変とは区別する。
odds参考値はexp(w)、exp(-w)、exp(2w)。確率の倍率ではない。

safe sourceと全signal/inputを固定し、2024 scalingをsealしてから2024 labels/寄与を解決する。
2024で各position差>=-0.003、Hit@3差>=0だけ適格。Hit@3最大、abs(k)最小、k昇順。
selectionとsealを保存してから初めて2025 labels/寄与のidentityを解決・参照する。
2025のselected-w固定評価後だけ全grid・pooledをDIAGNOSTIC_ONLYで生成し、再選択しない。
2024/2025を使ってsignal自体を選択済みなので2025はPOST_SELECTION_DEVELOPMENT_TRANSFER_DIAGNOSTIC。
HOLDOUT/UNSEEN_VALIDATION/REPLICATIONとは呼ばない。

selected w=0はNO_INCREMENTAL_ADJUSTMENT_SELECTED。
非zeroで2025 Hit@3差>0かつ各position差>=-0.003ならDIRECTIONALLY_CONSISTENT_POST_SELECTION_DEVELOPMENT_REPLAY、
それ以外NOT_TRANSFERRED_POST_SELECTION_DEVELOPMENT_REPLAY。boundary abs(k)=50はBOUNDARY_SELECTED。
旧SCORE_POINT_V2/旧w=+0.03は参考比較のみ。正式Gate・bootstrapなし。

## 診断の単位

主4指標は既存Bt03e05MetricEvaluatorと同じ公式一意順位の分母。w=0は保存確率・decision・寄与全件に完全一致を要求。
grade/classは保存meeting metadataを使用。年×grade、年×class、年×grade×classを保存しUNKNOWNも残す。
confidenceは固定C1 P1 marginの年別Type7 quartile。境界同値を分断しない。
growth sign/magnitudeは該当entry数と、その状態のentryを1人以上含むdistinct race数を記録。
各raceを同じgroup内では1回だけ評価するが、複数groupへの所属はあり得るためgroup間の率/件数を合算しない。
first observation診断はALL、全出走者TRUE、少なくとも1人TRUEを別のrace部分集合として示す。欠損をTRUEにしない。
これらの診断によるwの再選択は行わない。

## 保護と保存

Production DB NONE、2026実データ参照・新規取得・Migration・C1 fit/変更は禁止。
旧source・旧bundleを上書きせずSTART/END bytes/SHAを検証。大容量はstreaming/SQLite spool、128MB。
root: `/home/shinya/neo-keirin-artifacts/growth-trend-adjustment-calibration-01-20260920-01/`
ID: `outer-c1-meeting-delta-lag1-adjustment-2024-2025-01`
plan/execute/reproduceを独立実装し、生成時seal・公開前再検証・全生成物byte-exact再現を行う。
実集計・全39成果物のbyte-exact再現を完了。
状態はPOST_SELECTION_TRANSFER_NOT_CONSISTENT_AWAITING_REVIEW。学習済みモデルは変更せず、未コミットレビュー待ちで停止する。

## 固定済み実集計

SCALE_P99=3.03、2024 VALID n=178,641。2025の値はscale決定に使用しない。
選択はk=34、w=+0.34、INTERIOR_SELECTED。eligible kは73個:
`-27, -25..-15, -13..-6, -2..50`（各範囲は両端を含む）。
選択順位を保持した完全一覧は `selection.json` に保存した。

|年|指標|固定C1 分子/分母|固定C1率%|補正 分子/分母|補正率%|差pp|
|---|---|---:|---:|---:|---:|---:|
|2024|1着|10424/25158|41.434136|10488/25158|41.688528|+0.254392|
|2024|2着|6041/25106|24.061977|6156/25106|24.520035|+0.458058|
|2024|3着|4701/25094|18.733562|4780/25094|19.048378|+0.314816|
|2024|Hit@3|21091/75120|28.076411|21353/75120|28.425186|+0.348775|
|2025|1着|9886/24789|39.880592|9867/24789|39.803945|-0.076647|
|2025|2着|5743/24727|23.225624|5690/24727|23.011283|-0.214341|
|2025|3着|4677/24739|18.905372|4733/24739|19.131735|+0.226363|
|2025|Hit@3|20241/73989|27.356769|20226/73989|27.336496|-0.020273|

正式判定ではなく、今回固定したdevelopment transfer条件による状態は
`NOT_TRANSFERRED_POST_SELECTION_DEVELOPMENT_REPLAY`。
2024で選択されたglobal adjustmentは2025 development replayへ方向転送しなかった。
2024は係数選択年であり、その改善を独立評価での向上とは呼ばない。
2025 full gridは診断専用。2025の別係数への差し替えは行わない。

|年|P1変更|P2変更|P3変更|Top3集合変更|いずれか変更|P1/P2/P3/Hit@3 net|
|---|---:|---:|---:|---:|---:|---|
|2024|1019|2481|2943|2709|4131|+64 / +115 / +79 / +262|
|2025|989|2444|3017|2794|4185|-19 / -53 / +56 / -15|

Hit@3は公式1～3着がすべて一意のレースの位置一致数で、3位置の各単独指標とは適格集合が異なる。
位置別分子を足してHit@3を作っていない。gained/lost/netは `changed-race-diagnostics.json` を参照。

## 診断結果

以下は同じglobal w=+0.34の診断であり、層別再選択ではない。

|growth状態|2024 entries|2025 entries|2024 Hit@3差pp|2025 Hit@3差pp|
|---|---:|---:|---:|---:|
|negative|88451|87541|+0.355642|-0.025968|
|zero|2618|2473|+0.444804|+0.114155|
|positive|87572|86686|+0.356246|-0.012297|
|missing|448|420|+0.918274|-0.282486|

表の指標は該当状態のentryを含むレース部分集合。group間は重複する。
zero/missingの本人anchorは不変でも、対戦相手の補正によりレースの予測は変わり得る。
same-date boundary ambiguousは8/12出走。既存の別要因を含むPARTIAL_TIME_ORDER全体は16/22出走で、全件無補正。
残る欠損はMISSING_PREVIOUS_SCORE=432/398出走。rawとnormalizedはNULLを保持する。

|診断部分集合|2024 Hit@3差pp|2025 Hit@3差pp|
|---|---:|---:|
|C1 margin Q1|+0.639386|-0.118835|
|C1 margin Q2|+0.021303|+0.032510|
|C1 margin Q3|+0.431310|-0.075708|
|C1 margin Q4|+0.303272|+0.080959|
|F1 × A1_A2|+0.458563|+0.126678|
|F2 × A1_A2|+0.288600|-0.113497|
|F2 × A_CHALLENGE|+0.558228|+0.142186|
|first observation ANY_ENTRY_TRUE|+0.364964|+0.088118|
|first observation ALL_ENTRIES_TRUE|+0.350920|+0.099813|

grade/class、10 magnitude区分、各positionの分子/分母/差は対応する診断JSONに全件保存。
少数区分や診断後の良好部分集合から一般化しない。CI、有意差検定、bootstrap、正式Gateは追加していない。

## 旧実験との比較

旧SCORE_POINT_V2のw=+0.03はNOT_REPLICATEDを維持する。今回のwとはsignal scaleが異なる。
旧Hit@3差は2024 +0.119808pp、2025 -0.014867pp。今回も2025で転送条件を満たしていない。
旧P1/P2/P3変更は2024 246/632/818、2025 241/603/834。旧いずれか変更は1088/1108レース。
旧全4指標・変更数との完全比較は `old-calibration-comparison.json` に固定した。

## 検証記録

人工focused: 50 tests / 608 assertions成功（128MB）。
関連: 331 tests / 2819 assertions成功（128MB）。
全体: 1700 tests、1691 passed、9 skipped、13368 assertions。skipはPostgreSQL限定検証。
変更PHP15ファイルの構文・Pint検査成功。
2025正解・sidecarを物理退避したままselection sealまで成功。
2025正解を変更してもscaling、両年prediction input、2024 curve、selection/sealはbyte-identical。
source/code/生成物/selection改変拒否、w=0完全一致、欠損・0・clip・選択境界・2026拒否を検証した。

実集計: DB無効、128MB、21分10秒、PHP peak 33,554,432 bytes、最大RSS 76,060 KiB。
39生成物を固定し、source/model/trend/seal/codeはSTART/END一致。
sequence: scaling seal=5 → 2024 outcome identity=6 → selection seal=13 → 2025 outcome identity=14。
全件再現: DB無効、128MB、21分13秒、PHP peak 33,554,432 bytes、最大RSS 76,140 KiB。
全39成果物（manifest/LOCKED含む）がbyte-exact。scaling・両年input・2024 curve・selection/seal・2025固定transferの9ファイルは中間段階でも一致を確認。

共有ZIPはroot直下の `GROWTH-TREND-ADJUSTMENT-CALIBRATION-01-report.zip`。
ZIPのサイズ・SHA-256・各memberのSHA/CRCはrootの `report-zip-verification.json`、`unzip -t` の結果は `evidence/zip-test.log` を参照。
重要成果物は永続root内に保存し、/tmpを唯一の保存先にしない。

## 実行経路

以下の3操作を実施した。実際の全引数・終了コードは `evidence/*-receipt.json` に保存。

```bash
DB_CONNECTION=growth_disabled DB_URL= php -d memory_limit=128M artisan keirin:backtest:growth-trend-adjustment-calibration --plan
DB_CONNECTION=growth_disabled DB_URL= php -d memory_limit=128M artisan keirin:backtest:growth-trend-adjustment-calibration --execute --outer-root=... --trend-bundle=... --output-root=... --analysis-id=outer-c1-meeting-delta-lag1-adjustment-2024-2025-01
DB_CONNECTION=growth_disabled DB_URL= php -d memory_limit=128M artisan keirin:backtest:growth-trend-adjustment-calibration --reproduce --output-root=... --analysis-id=outer-c1-meeting-delta-lag1-adjustment-2024-2025-01
```

`--execute`は新規bundleのみ、`--reproduce`は別stageへ生成して固定bundleを上書きしない。
今回の完了は実装・実集計・再現・報告の完了であり、予測精度の一般的向上や正式採用を意味しない。

## PR #63 Review Fix

開始HEADは `65a4cee3d987717e774b95b9381f26b6dd110eaa`、同じexperiment branch、clean。
PR #63はOPEN。旧v1実行・ZIPは当時の記録として保持し、新契約として読み替えない。
問題は `OUTCOME_SOURCE_SELF_SIGNED_SIDECAR_TRUST`。本文とsidecarを整合的に同時改変すると、
現在のsidecar自身を信頼元として受理できた。数値計算ではなく固定原本のprovenance不備である。

修正版は `GROWTH-TREND-ADJUSTMENT-CALIBRATION-01-v2-PR63-OUTCOME-SEAL-FIX`。
信頼元は `LITERAL_REVIEWED_PER_YEAR_SEALS`。実装前に下記8ファイルのrealpath/bytes/SHAと本文rowsをREAD ONLY照合し、全件一致。
基準rootは `/home/shinya/neo-keirin-artifacts/tactical-history-01-review-fix-20260916-01`。

|年・原本（rootからの相対パス）|rows|bytes|SHA-256|
|---|---:|---:|---|
|run-01/labels-2024.jsonl|25212|72960991|b297c567bb26aa4cbf5263f488ebc55efdd37634c99e60a9cd29a5842a1ccd29|
|run-01/labels-2024.jsonl.manifest.json|N/A|127|a0f1aae588edaabf64f3921f348f6e7936bccfbbae16f24b56ca83fc282923d4|
|comparison-run-01/contributions-2024.jsonl|25212|174358551|a481bfe1f3acaed22bae09244e0473183f513127cef56db4e43a9fa364313350|
|comparison-run-01/contributions-2024.jsonl.manifest.json|N/A|128|5b794154cc4dde3e1829c816d804b10896bcad012a2167dc8d9b53c42558c911|
|run-01/labels-2025.jsonl|24866|72086838|509cf6e4c823c07f73f11cbae31a6844a376ced6b48b4e09d3f750ea4058beca|
|run-01/labels-2025.jsonl.manifest.json|N/A|127|42cc7619b78344166c80c9ecc1ac0f84b39bb7a018de9b69b9a2914ea4da31ce|
|comparison-run-01/contributions-2025.jsonl|24866|171962784|71af1a23fed0defa8fee0506680d329ad84926666f74a7794fa711a0efbcc635|
|comparison-run-01/contributions-2025.jsonl.manifest.json|N/A|128|c957e329fafb93278db4ed248a093d0242969c6089305a90f0c325593a9f3829|

実行時はTemporalAccess承認、当年の固定seal取得、sidecar bytes/SHA検証、JSON解析、
固定本文rows/bytes/SHAとの完全一致、固定本文sealによる本文検証の順。
2024はscaling seal後、2025はselection seal後だけ実ファイルへアクセスする。
mixed-year `report-export-manifest.json` は使用しない。
固定seal契約と検証監査を独立artifactへ保存し、source-endも固定sealを正本として再検証する。

人工テストは両年・labels/contributionsごとのBODY+SIDECAR同時改変、same-length 1byte本文/sidecar改変、
rows変更を拒否。sidecar自体が検証を通ってもrows/bytes/SHAそれぞれの本文契約不一致を拒否する。
2025のlabelsまたはcontributionsを改変した実service実行は、11個のpreselection artifactが正常実行とbyte-identicalのまま、
selection seal後のoutcome解決で停止し、最終bundleを公開しない。物理退避・正常実行・完全再現の既存テストも維持。

review-fix ID: `outer-c1-meeting-delta-lag1-adjustment-2024-2025-pr63-review-fix-01`。
rootは旧実行と同じ。旧bundle/ZIPを上書きせず、DB無効・128MBで修正版の実集計を完了した。
SCALE_P99=3.03、k=34/w=+0.34、eligible 73個・順序、INTERIOR_SELECTEDは全て旧runと完全一致。
両年・pooledの各101候補、全6診断、selected明細、decision変更数を含む29成果物がbyte-exact。
selectionはcode sealだけ、transferはselection sealだけを除き完全一致。数値・選択規則は変更していない。
状態は `NUMERICALLY_UNCHANGED_AFTER_OUTCOME_SOURCE_TRUST_FIX`、2025は `NOT_TRANSFERRED_POST_SELECTION_DEVELOPMENT_REPLAY`。
固定reviewed outcome内容は不変だったため、source provenanceを修正しても数値は変化しなかった。正式採用しない。
実集計は21分09秒、PHP peak 33,554,432 bytes、最大RSS 76,284 KiB、終了コード0。
focused 70 tests / 788 assertions、関連401 tests / 3607 assertions（focusedを含む）、全体1711 passed /9 skipped /13548 assertions。
skipはPostgreSQL専用検証。変更PHP5ファイルのPint・構文検査は成功。旧bundle39ファイルと旧ZIPは開始時から不変。
新しい固定seal契約・trust auditを含む41生成物（manifest/LOCKED含む）のbyte-exact再現と外部照合が成功。
再現は21分07秒、PHP peak 33,554,432 bytes、最大RSS 76,444 KiB、終了コード0。
監査順序はscaling seal=5、2024 identity resolve=6/file open=8、selection seal=13、2025 resolve=14/file open=16。
旧bundle39ファイルと旧ZIPのSTART/END不変を確認。旧ZIP SHA-256は
`951097420488612aaabcec8452a031214a3c2a7c5e20577cc015c72b86399b0c` のまま。
証拠はroot内 `pr63-review-fix-01/` と `evidence/pr63-*`。旧実行のファイルは削除・上書きしていない。
新規共有ZIP名は `GROWTH-TREND-ADJUSTMENT-CALIBRATION-01-PR63-review-fix-report.zip`。
版ごとの数値比較・固定seal・temporal監査・テスト・全member SHA/CRC検証を記録し、大容量原本は絶対パス/bytes/SHAで参照する。
状態は `PR63_REVIEW_FIX_VERIFIED_AWAITING_REVIEW`。未コミットで停止し、次工程はNOT_AUTHORIZED。
2026アクセス0、正式採用・重み再選択・C1変更・次工程は許可しない。
