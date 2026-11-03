# STAT-2026-CHRONOLOGICAL-VALIDATION-CONTRACT-01

> 2026-11-01 限定実装への移行: PR133は124afa68でマージ済み。最新ユーザー指示により具体案を **ACCEPTED_FOR_LIMITED_IMPLEMENTATION** として受入。実2026実行は `real_execution_authorized=false`、O1実初期履歴はNOT_ESTABLISHEDのまま。実装・限定試験は[接続実装記録](2026-chronological-connection-01.md)と第13節を参照。以下の文書のみ/未実装/レビュー限定はPR133終了時点の履歴であり、今回の製造を禁止する現在状態ではない。

> PR133 P2修正（2026-11-01）: 既存branchのclean HEAD `827582af4bb311b1e78e3fa0b5b5504ae30d5e0f` からF1/F2を修正。不能時もprediction_bundleを正本とし、先行する候補固定版から結果保存までの参照と、JST日付の履歴境界を統一した。第7.1・8.2・12節参照。初版の実物確認/検証は当時の履歴として保持し、今回再実行していない。

## 1. 状態・根拠の優先順位

PR133設計終了時点（履歴）: 2026-11-01 JST。**READY_FOR_REVIEW_WITH_OPEN_ITEMS**。当時の契約案は **PROPOSED_FOR_REVIEW**、実行許可はfalse。当工程は文書とJSONだけであり、実2026資料の閲覧・取得・STAT計算・予測・評価はすべて0。性能改善・正式採用・LIVE・発走前可用性の証明ではない。現在の限定実装受入は第13節で区別する。

条件値の正本は[機械可読契約v1](contracts/stat-2026-chronological-validation-v1.json)、工程状態の正本は[MASTER PLAN](statistical-engine-master-plan.md) 5.0/15.98。以下の型・例外・接続案とJSONは一体としてレビューする。後続実装の受入ケースは未実装・未実行であり、成功済みとはしない。

開始時clean main/HEAD/ローカルorigin/mainは `ea37ec91dc42dd0c517b444a26c2ef3b5cc89f48`。PR132 merge件名、親 `a25fcede1b9ba71ffa8b2ff293613340042ca779` / `1df749d643477ecd59e6f19af11549c22721bb6d`、tree `259c256b7969cefb81dd8c2cbad330b6d7c93702` が指示と一致した。未使用の `docs/stat-2026-chronological-validation-contract-01` をmainから作成。fetch/pull/外部GitHub照会なし、最新remoteを独立照会したという意味ではない。add/commit/push/PR/mergeなし。

| 区分 | 今回の扱い |
| --- | --- |
| 合意済み | PR132の固定352レース/2,349出走・20上流＋品質20の統合生成/保存/検証は有限完了。終了済みレースを時系列で扱い、予測保存後に結果照合する方針。2026結果でモデル/係数/使用STATを選ばない |
| 今回の具体案 | 初回2026年1月、保存最終C1対raw得点STAT01、日別の予測lock→結果開放、同じ保存Primary三つ組を共通母数で測る。値は未承認案でありTBDへ戻さない |
| 未確認・未承認 | 初期履歴の生イベント/得点文脈の完全な接続、2026 source map、実取得/評価の許可・利用許諾・運用D1〜D8。O1/O2参照 |

PR132のCodex終了時は文書のみ変更、PHPUnit/通常全体/Pint NOT_RUN。その後の**ユーザー**全体は4,534 passed /46,563 assertions /155 warnings /9 skipped、393.73秒、失敗表示なし。明示exit code・155警告の全原因は未提示。1df749dでユーザーcommit/push、ea37ec91でmerge。PR131後の390.28秒は別実行であり書き換えない。

## 2. 初回の検証条件案

JSONのperiod/model/baseline/daily_protocol/metricsを参照する。期間は2026-01-01〜01-31のJST両端含む、瞬間の区間は `[2026-01-01T00:00:00+09:00, 2026-02-01T00:00:00+09:00)`。候補レース数/出走数はNULL＋NOT_ACCESSED_NOT_MEASURED。2025年の受入件数を予定値へ転用しない。

モード案は **HISTORICAL_CHRONOLOGICAL_REPLAY / POST_EVENT_CAPTURE**（終了済みレースの事後取得入力による時系列再生検証）。当該結果の非入力化と、当時の発走前資料の完全再現は別である。現在取得する得点/名簿/予定時刻も事後訂正の可能性がある。historical_as_of_available=false、historical_publication_time_verified=UNKNOWNを保持し、実取得/保存日時を過去input_as_ofへ書き換えない。BT-04の正式な未観測holdout評価やLIVEを自動開始しない。

## 3. 限定した実物確認

最終root F=`/home/shinya/neo-keirin-artifacts/tactical-history-final-01-20260917-01/fit`、親root H=`/home/shinya/neo-keirin-artifacts/tactical-history-01-review-fix-20260916-01`。以下は今回read-onlyで実在/サイズ/hashを確認した値。ディレクトリ内全資料・全媒体の監査ではない。

| 実在ファイル | bytes | SHA-256 / 確認範囲 |
| --- | ---: | --- |
| F/run-01/final/artifact.json | 3,524 | abdb0038b1fecbc2821c38f6dae08875876a4aa0f39c02abf60c23ca9b34bd6f |
| F/run-01/final/model.json | 80,928 | e3cc1f7f10af60bb22f97a2172ca52ef2c09a3393222ee46c25619de752d43a1、artifactとfit/resultの両参照に一致 |
| F/run-01/final/layout.json | 51,189 | eb84b687ac6064fd107ceaa7e3659a83b9637452ea579c307c8f3eb4f0c7289e、fit/resultのsemantic_files.final/layout.jsonと一致 |
| F/result.json | 133,288 | 71e6de55803b18e5b82e1f90a399f747e688cf1eba5695b53c463be16cff302e |
| F/parent-start.json | 62,518 | f97472db24231564244f54b9e3c4b524e438abf621f5b7fc9d2529dc3c977c18 |
| H/inputs-v2/manifest.json | 3,823 | d5158a7671f8c453afc77dccc1ab0b089c940198f58f388c038ad0d4fad684cf、parent-start参照と一致 |
| H/inputs-v2/inputs-2025.jsonl | 66,942,915 | a6a6a79303c5debe9c3b3b5cb1b4e37f86197c49afc7b3fc2a5f5cb444f1f0d4、親/年別manifest/sidecar一致 |
| H/inputs-v2/history-2025.jsonl | 731,358,814 | 96b7b05ffa589cfc65d621caba316cbb7b55a6f3726006ef4f7abf21f607932f、親/年別manifest/sidecar一致 |

**model SHAとartifact.json自身のSHAは別**。モデルのbin順16、各順位係数137、lambda0.1、anchor1.0、全順位CONVERGEDを保存JSONで確認。2022〜2025学習はartifact契約/fit結果の保存記録。今回restore/fit/OOF/推論/損失照合は起動していない。新コードで過去の数値再現を再実行したとの主張ではない。

### 3.1 初期履歴候補と不足

| 候補 | 期間・source・用途 | 今回確認 / 不足 |
| --- | --- | --- |
| H/inputs-v2/inputs-2025.jsonl | 2025年対象24,866行は既存manifest記録。旧C1入力、結果付きの場合もある | seal一致。2026入力/生結果履歴への転用不可 |
| H/inputs-v2/history-2025.jsonl | 2025年対象177,120行は既存manifest記録。対象ごとの120日集計・参照ID | sealと先頭1行のschemaを確認。target/cache_key/source_sha256/aggregate形式で、生の全結果イベント・全競走得点ではない。年末までの履歴の完全性は未検証 |
| H/inputs-v2/history-cache.sqlite | parent-start/manifest指定の旧キャッシュ | 実在・5,748,555,776bytes一致。manifest記載hash af4ccd5601a838d9c177b5bee7d718ce91beb847409323bdae6666ba69304199。今回はDBとして開かず全hashも再計算しない。初期履歴に使えるschema/イベント範囲/全本人/Raw対応は未確認 |
| H/inputs-v2/history-entry-index.json | 旧historyキャッシュの索引 | 137bytes、cab8a40261ff1ddf5c76bd7084c8d0ae7de16e5eddb179faae6c453861ae1262、parent参照一致。索引単独を完全履歴とはしない |
| PR132のsnapshot | 2024-01-01〜2025-01-04、[統合受入](stat-existing-data-integrated-acceptance-01.md) | 受入済みの限定資料。2025年末や全学習履歴の代用不可。今回SQLite再コピー/再計算なし |

**O1: initial_history_epoch.ref=NULL / NOT_ESTABLISHED**。必要なのは、過去各レース/開催/本人/車番、予定時刻、当該時点の得点文脈、公式rank/status/決まり手、import/取得・保存版とsealを持つオフラインイベント投影。対象レースの古い集計4値を新レースへ使い回さない。推奨は上記既知キャッシュ/元exportに限定した次工程の由来・schema・範囲照合であり、本工程でDB接続・全Raw走査・別モデルfallbackは行わない。存在しない資料とは断定しない。

### 3.2 原本の再特定

学校工程の既存 `source-references.json` から次の実在名を特定し、bytes/SHAを照合した（原本変更なし）。

- `/home/shinya/ダウンロード/%E7%B5%B1%E8%A8%88%E3%82%A8%E3%83%B3%E3%82%B8%E3%83%B3%E8%A6%81%E4%BB%B6%E5%AE%9A%E7%BE%A9_STAT-01-46%E7%A2%BA%E5%AE%9A_v1.0(2).md`: 185,440bytes /73b4846b7cdeeb090dbb1c4410e12cb8ba717020f07c77de15e1fee28b7bc4ab。
- `/home/shinya/ダウンロード/スクレイピング残タスク_統計エンジン連携_STAT-01-46確定_v1.0(2).md`: 78,572bytes /963ae1d64b6f04f1eccbefc039c7228710b950d7d22bcb413cdf17ea921471c3。
- `/home/shinya/ダウンロード/STAT-01-46_データ取得可否4分類_ドラフト_v0.1.md`: 15,295bytes /c0508fe4d9f1bd050d0b42611c5cbbbce5d757f1a3979d6783ef8c94cd9ab085。

ファイル名v1.0(2)と要件/残タスク本文v0.1、4分類ドラフトv0.1を区別する。原本のADOPT_WITH_CHANGE/OPEN/区分1等は本契約の予測利用承認ではない。BT-03E-02の確定したSTAT/時系列/結果開放/同点/指標はMASTER PLAN 15.1〜15.18/18.8と現行Contractを確認し、旧数値/Gateを変更しない。別単独原本は指定候補内で未特定、架空pathを作らない。四柱推命等の別資料は対象外。

## 4. モデルと21STATの役割

主方式案は保存最終C1 **TACTICAL-HISTORY-SEQUENTIAL-POSITION-v2**。lambda0.1、STAT01 RACE_SCORE_Z anchor1.0、16信号/bin/係数/欠損表現/decoderを固定する。構成B・STAT35 mean6・FIELD_BIKE追加・旧不採用方式を混在させない。[B1契約](c1-2022-parent-lambda-b1-contract-01.md)は2023 OOF親選択の別工程。B1未承認/NONEを維持しつつ、本案の保存最終C1までB1再実行待ちへ戻さない。

01以外の学習信号はJSON statsのorder=0〜11の順。以下の12行の計算版は各 **STAT-xx-existing-db-v1**、Registry **BT02-SIGNAL-REGISTRY-v1**、適格性は **BT02-OPERATIONAL-v1**。

| STAT / order | 実feature pathまたは変換 | Operationalで追加許容するquality reason |
| --- | --- | --- |
| 07 /0 | features.DELTA.mean_score_expectation_residual | なし |
| 08 /1 | features.DELTA.mean_score_expectation_residual | なし |
| 10 /2 | features.SUMMARY.mean_residual_3_minus_10 | IN_MEETING_RESULT_CONFIRMATION_NOT_RECONSTRUCTED |
| 11 /3 | features.COUNT_WINDOWS.10.abnormal_rate | なし |
| 12 /4 | features.HISTORICAL_PRE_MEETING_GAPS.current_gap_empirical_percentile | IN_MEETING_RESULT_CONFIRMATION_NOT_RECONSTRUCTED |
| 23 /5 | features.DELTA.mean_score_expectation_residual | なし |
| 24 /6 | features.PRE_MEETING.COUNT_WINDOWS.10.residual_stddev_pop | IN_MEETING_RESULT_CONFIRMATION_NOT_RECONSTRUCTED |
| 26 /7 | features.DAY_WINDOWS.30.started_race_count | なし |
| 31 /8 | features.STAGE_EXPERIENCE.SEMIFINAL_OR_FINAL.mean_score_expectation_residual /STAT31_AGGREGATE_INTEGRITY_V1 | なし |
| 32 /9 | features.DELTA.mean_score_expectation_residual | なし |
| 39 /10 | features.FIELD_BIKE_DELTA.mean_score_expectation_residual | MISSING_FRAME_NUMBER |
| 42 /11 | HEAD_TO_HEAD_BY_COENTRANT各DIRECT_HISTORYのsample_count加重mean_relative_expectation_residual /PAIR_RESIDUAL_WEIGHTED_MEAN_V1 | なし |

Registryは31の準決勝/決勝加重集計整合、42の対戦相手加重集計を検査する。全信号共通でstatus=VALID、primaryValue非NULL、EntryIncremental、quality_status≠PARTIAL、qualityReasonsが上記許容集合の部分集合であることを要求する。NO_HISTORY/PARTIAL等の既知の不適格状態は**理由を残しNULL**。全STAT VALIDを新たなレース条件にしない。不明Enum/本人不一致/hash不一致は技術失敗。Layout::assignの欠損/未知binはNULL index・学習調整なしであり、観測された値0への補完ではない。

| 残る9STAT | 現行版と役割 / 初回での扱い |
| --- | --- |
| 01 | STAT-01-existing-db-v1。基準raw得点とanchor、比較基準。全員VALID/FULL・正の有限raw・有効得点順位・本人対応が必要 |
| 20 | STAT-20-acquired-history-v3。品質/source監査だけ。能力点/予測confidenceへ加算しない。2026制限があるため既存CLIの実行は前提にせず、新source監査adapterで必要事項を検査 |
| 33 | STAT-33-existing-db-v1、DIAGNOSTIC_ONLY。主入力外 |
| 41 | STAT-41-existing-db-v1、RACE_STRATIFIER。レース文脈/層別だけ、entry係数なし |
| 09 | STAT-09-acquired-career-grade-breakdown-v3、限定MVP/事後分析、C1係数なし |
| 16 | STAT-16-six-condition-performance-v3、同上 |
| 25 | STAT-25-disqualification-history-v2、同上 |
| 27 | STAT-27-six-condition-experience-v3、同上 |
| 44 | STAT-44-acquired-longitudinal-v2、同上 |

役割合計=anchor1＋signal12＋品質1＋診断1＋層別1＋MVP5=21。初回C1に不要な8STATの2026計算やSTAT20v3全上流の再製造を前提にしない。原本要件の全完成と、固定MVP受入と、予測入力を区別する。

### 4.1 C1に必要な追加4履歴値

`HistoryAggregator::FEATURES` の順を維持する。

```text
HIST_OBSERVED_ESCAPE_TOP2_COUNT_120D_PRE_MEETING
HIST_OBSERVED_MAKURI_TOP2_COUNT_120D_PRE_MEETING
HIST_OBSERVED_SASHI_TOP2_COUNT_120D_PRE_MEETING
HIST_OBSERVED_MARK_TOP2_COUNT_120D_PRE_MEETING
```

公式過去結果の逃げ/捲り/差し/マーク。開催初日00:00 JSTをMとして `[M−120日,M)`、本人・別開催・対象以外、公式CONFIRMED/CORRECTEDとimport/race/bike/本人の整合を要求する。日別epochの制限に**追加して**適用する。実発走を確認したFINISHED/TIEDの上位2着の決まり手を数え、マーク1着はINVALID_HISTORY、欠場/取消は非出走、失格/未完走等は決まり手加算なし。予定時刻/結果/由来不備や上位決まり手欠損は既存のPARTIAL/INVALID/MISSING_METHODを維持。

AVAILABLEかつ観測実発走ありで当該戦法なしなら0。履歴なし/左打切り/不整合等は4値すべてNULLと既存理由。取得範囲がないだけで0にしない。PJ0315のnigeCnt等の表示回数、STAT19/30の別窓、現在プロフィールで代用しない。

### 4.2 STAT01比較とidentity

比較案は `Bt03e05MetricEvaluator::rankBaseline` のraw降順、完全同点時 `SHA256("BT03E02-TIE-v1|" + original_race_id + "|" + bike)` 辞書順で異なる3人を固定する。BT01のRANK1_SET/TOP3_SETを順位三つ組と解釈しない。

C1のE06同点keyもrace IDを含む（PRIMARY_WINNER_P1/PRIMARY_SECOND_THIRD）。外部race key=`track_code:YYYYMMDD:zero-padded-race-number` と元race/entry/player IDの対応を入力台帳に固定する。派生SQLiteの付番をモデルへ渡さない。新規識別子は結果開放前のidentity台帳で一度だけ確定し再開時に再利用、対応未確定ならNO_PREDICTION。車番同点規則へ勝手に変更しない。

基準方式も同じ保存入力/全名簿から結果前に保存する案。必要なraw/本人が不適格なら方式ごとのNO_PREDICTION。比較方式だけ不正得点を0補完して成立させない。C1のanchorは一人でも無効なら**レース全体**NO_PREDICTIONで、選手を除いた縮小レースを作らない。平均と同得点の正当なz=0、全員同得点のZERO_VARIANCE anchor0は既存処理を維持する。

## 5. 入力集合・取得の境界

| 事前に確認する条件 | 台帳/処理 |
| --- | --- |
| 指定JST日付・場・番号・永続race対応 | 候補C。結果の存在/resultFlg/実順位/予測成功で選別しない |
| 既存RaceCategoryPolicyで男子A/S、出走表5〜9車 | 対象T、本人/得点等の入力検査へ。7車だけで性別を推定しない |
| 既知のGirls/Lまたは範囲外車立て | 対象外X、SKIPPED_UNSUPPORTED_CATEGORY等の理由を保存 |
| カテゴリー不明・予定時刻/開催/本人/得点等の既知不足 | 候補から消さずTの理由付きNO_PREDICTION。カテゴリーを男子へ昇格しない |
| identity競合、未知schema/Enum/hash矛盾、I/O/計算例外 | FAILED/技術未処理U。都合のよいNO_PREDICTIONへ変換せず日別開放を停止 |

検算はC=X+T、各方式mでT=P_m+N_m+U_m。対象とは、事前の既知対象外を除いた検証台帳集合であり、全件入力適格という意味ではない。カテゴリー未知数も別列で示す。結果取得済み=評価可能＋評価除外＋結果整合失敗、取得未了を別列で残す。方式差の主比較は後述E_pairだけ、単独coverage/不能理由も省略しない。

許可列はJSON input_projectionの列挙に限定。レース/開催/出走者対応・予定時刻・締切、当該Rawの車番/登録番号/枠/級班/競走得点を投影し、source run/row/version/hash/statusと4履歴を別監査へ結ぶ。model input自体は旧ModelLoaderのrace3field、entry9field形式。rank/status/labels/resultFlg/result_available/result_status/払戻/オッズ/事後プロフィール・表示回数を入力へ渡さない。Raw保存→許可投影→余分/禁止field検査→入力sealの順。秘密パラメータはprivate参照、共有manifestへ生encpを残さない。

input_as_ofは既存発売締切優先・欠落時予定発走のfallback。予定発走以下・同日JSTを検証し、欠落時に0時等を創作しない。day epochのlogical時刻、実取得/保存/計算時刻、公式公開時刻は別列。POST_EVENT_CAPTUREでは実取得がinput_as_of後である事実を保持し、当時利用可能と認定しない。

## 6. 日別順序・履歴公開の案

1. Dの**全候補**を結果を使わず固定、C/X/Tと本人名簿をsealする。
2. D開始時に単一history epochをbind。初日はイベント日が2025-12-31以前の別途検証済み履歴だけ。取得可能範囲の下限案2022-01-01を明記し、生涯完全性としない。
3. 結果を呼ばないPJ0315専用入口で得点/出走表を取得・許可投影、実取得版と構成可能な正常input_bundleを保存する。既知不足で構成不能ならダミー入力を作らず、取得済み証拠を不能記録へ結ぶ。
4. 必要13STAT＋4履歴を計算、**両方式それぞれのprediction_bundle**をPREDICTEDまたは既知NO_PREDICTIONとして固定する。候補版は先行するCANDIDATES_LOCKED、day_manifestの理由索引だけで終端としない。
5. U=0・全T×両方式の実在/検証済み終端bundleへの非NULL Ref・全seal確認後に日別DAY_PREDICTIONS_LOCKEDを公開。これを満たすまでPJ0326結果取得器を呼ばない。対象外Xには終端bundleを要求しない。
6. 当日の結果取得/照合/保存、評価を行い、検証済み版だけを次の日のepochへ追加する。

日内順は予定発走JST→場コード→番号→永続race key。時刻不明のNO_PREDICTIONは既知時刻群の後に外部key順で台帳固定するだけで、架空の発走時刻を付けない。同時刻/再開時も同じepoch、同日先行結果を後続へ渡さない。各STATの既存input_as_of未満、pre-meeting等のより厳しいcutoffを緩めない。

履歴の論理的releaseはDの結果を検証後、**D+1のJST00:00から利用可能とする再生規則**。target_day/history_event_day/logical_available_fromはすべてAsia/Tokyoの**日付型**であり、次の全条件を要求する（JSON daily_protocol.history_conditionsが正本）。

```text
history_event_day < target_day
AND logical_available_from <= target_day
AND 当日開始に固定したhistory epochに含まれる検証済みの版
AND 各STAT/履歴feature固有のcutoffを満たす
```

1月1日の結果をlogical_available_from=1月2日として検証済みepochへ含めた場合、1月2日の対象日に日付境界は成立する。同日/未来イベント、利用開始日が対象日より後、固定epoch外の版は不可。成立してもinput_as_of未満・C1 pre-meeting120日/同開催除外等の追加条件は省略しない。初日も別途検証済み初期epochに同じ境界を適用するが、O1のNOT_ESTABLISHEDは維持する。実取得/保存は現在日時、当時の公式公開時刻は不明ならNULLのまま。「当時公開済みと証明した」とは呼ばない。必要な未確定結果は次epochで保留し、正式結果に架空補完しない。

結果訂正は新result version＋新evaluation ID＋新epoch。旧予測・初回評価・既に確定した後続入力は変更せず、以後の未処理日だけへ反映する。現行キーは `storage.reprediction_after_result_open=FORBIDDEN_EVEN_WITH_NEW_REQUEST_ID`。結果開放後は別request IDでも予測/不能記録を作り直して元の成績へ差し替えない。元epochと元PREDICTED/NO_PREDICTION bundleの検証再利用による**結果取得・評価の再開は許可**し、新規予測と区別する。

## 7. 保存schemaと参照・一意性

専用private artifactが正本。実在未確認のrace_predictionsテーブルを前提にしない（今回限定Migration検索で同名作成定義なし）。後続作業DBは固定束から作る派生領域であり、通常同期の最新行から過去入力を復元しない。

JSON schemasがfield一覧の正本。全列必須、型末尾`?`のみ明示NULL可（省略とは別）。`Id`=非空ASCII識別子120文字以下/パス成分不可、`Hash`=小文字hex64桁、`Instant`=offset必須RFC3339/μs精度・UTC整数μs比較、`Ref`=`{bundle_id,manifest_sha256}`、`Seal`=`{bytes>=0,sha256}`。可変長配列は順序をsealし、オブジェクトkeyだけを版付き正規化。有限数のみ。NULLの理由はReason(code/source_ref?/detail)で追跡する。

| 保存単位 | 必須内容 / NULL条件 | 一意性と参照 |
| --- | --- | --- |
| validation run | 契約版/契約hash、期間、model/コードSHA、比較版、初期history Ref、用途/承認receipt、実作成時刻、day参照 | run_id。実runには承認receipt/初期履歴Ref必須、本案JSONのNULL不足とは区別 |
| day manifest | 全候補identity/対象外理由、集合hash、history epoch、先行候補版Ref、全T×両方式の終端bundle Ref、不能理由索引、未処理/件数、状態/lock時刻/結果開放receipt | run_id+JST日付。候補固定版にはpredictionへの前方参照なし。予測lock版は同候補版/集合/epochと全終端の非NULL Ref必須、U=0。lock前のNULLは未終端、理由だけで開放不可 |
| input bundle | candidate_ref、race/meeting/entry/player/bike、採用capture/Raw seal、実取得/保存時刻、予定/input_as_of、許可列、STAT source、4履歴/参照・品質 | run+race+input_id、entry ID/bike/登録番号の重複不可。正常入力の必須条件は維持し、構成不能時にダミー束を作らない |
| prediction bundle | candidate_ref、input Ref?と可用性/不足理由、capture_refs/証拠不足理由、model hash、history Ref、方式、Primary3人/C1確率/utility/decision/同点監査、実保存時刻、状態/理由 | run+race+方式の唯一の正本。PREDICTEDは正常input非NULL、NO_PREDICTIONのinput=NULLは既知不足による正常入力構成不能時だけ。既存の正常inputがあればRef保持。不能時のPrimary/確率/utility/decision/同点監査はNULL、詳細7.1 |
| result bundle | 取得版/Raw参照・SHA/bytes、capture/log、実取得/保存時刻、公式状態/rank/status、本人対応、両方式の既存終端bundle/day lock hash | race+result_version、bike一意。両方式Refはday lockと同じ非NULL Ref、NO_PREDICTIONも省略しない。初版parent_result=NULL/訂正版は親Ref必須。公式公開不明NULL＋理由、中止行0可。本人/名簿の照合成功は別検証 |
| evaluation bundle | prediction hash＋result版＋metric版、各レース寄与、分子/分母、率、除外/失敗、共通集合hash、is_final | evaluation_idとprediction/comparison prediction hash・result版・metric版。比較表はcomparison_prediction Ref/hash・同result版・E_pair hash必須。単独表のcomparison/paired hash=NULL。率は分母0時のみNULL＋理由 |
| history epoch | 親Ref、採用結果版/実在する過去入力Ref、JST日付の論理利用開始日、イベント範囲、実取得順/論理release順/公開時刻、除外理由、実作成時刻 | epoch_id。genesisのみ親NULL。公開不明はNULL＋UNKNOWN。input=NULLに架空のRefを作らず、不足理由とfeature別利用可否を残す。訂正版も既存epochを変更しない |

補助型: candidate=`{external_race_key,original_race_id?,race_date,track_code,race_number,eligibility,reason_codes[],entry_set_seal?}`。原IDが未確認なら予測不可。CandidateRef=`{manifest:Ref,external_race_key:string}`、manifestは先行CANDIDATES_LOCKED版。capture evidence=`{capture_id,source,raw:Seal,private_ref,method,source_status,parser_version,fetched_at,saved_at}`。STAT source row=`{stat_code,calculation_version,source_run_id,source_run_uuid,source_result_id,subject_type,original_race_id,original_entry_id,original_player_id,bike,input_as_of,source_fetched_at?,calculated_at,input_hash,status,quality_status,reasons[]}`。4履歴は本人/target/window/version/values/status/参照IDとhashを持つ。参照先が実在しない/別race/別run/別版なら公開不可。

### 7.1 PR133 F1: 不能記録の正本と参照順

既存7種の保存単位のまま、**PREDICTEDもNO_PREDICTIONもprediction_bundleを正本**とする。入力束の必須時刻を緩めるのではなく、predictionのinputをRef?とし、JSONのcommon_rules/state_rulesで次を定める。

| 条件 | inputと証拠・出力の扱い |
| --- | --- |
| PREDICTED | 正常input Refを必須、NULL拒否。既存モデル/基準規則・history epoch・Primary/確率/utility/decision/同点条件を維持 |
| NO_PREDICTIONで正常inputが実在 | input_availability=AVAILABLE、Refを保持しinput_unavailable_reasons=[]。後から都合よくNULLにしない |
| NO_PREDICTIONで既知不足により正常入力を構成不能 | input=NULL、input_availability=NOT_CONSTRUCTIBLE_KNOWN_MISSING。input_unavailable_reasonsは非空・既知不足コード・全てreasonsに含む。理由だけでなく参照可能な証拠も結ぶ |
| NO_PREDICTIONの予測値 | primary/probabilities/utilities/decision/tie_auditはNULL。架空時刻/ID/0得点/縮小名簿・ダミーinputや同点値を作らない |
| 両状態のcandidate_ref | manifestの状態は先行CANDIDATES_LOCKED、同run/JST日付/対象Tの同external_race_key。後から作る予測lock版は参照しない |
| capture_refs | 既存capture evidence[]型の必須配列、NULL不可。取得済み証拠は保持。証拠自体がない場合だけ[]＋非空capture_unavailable_reasons（reasonsにも同じ理由）。取得証拠があれば不足理由は[]。PREDICTEDは正常入力に対応する1件以上が必要 |

input Refがある場合は正常入力を検証し、candidate_ref・capture_refs・本人/名簿・history epochが一致することを要求する。既知の欠損と、未知schema/Enum/hash矛盾・identity競合・I/O/計算例外は区別し、後者はFAILED/技術未処理のまま日別開放を停止する。

依存の向きは **日別予測lock → 両方式の終端prediction_bundle → 先行候補固定版**。inputがあるときだけprediction→input→同じ候補固定版も持つ。run_id+日付は論理的な日の一意性、候補固定版/予測lock版はそれぞれmanifest SHAで識別する既存append-only日別版であり、同じ論理日の上書きではない。候補固定版はpredictionへの前方参照を持たず、予測lock版も上書きしないのでhashは循環しない。resultは同じday lockと同じ両方式終端Refを持つ。

候補固定版のcandidate_manifestはNULL、prediction RefもNULLで未終端。予測lock版はcandidate_manifestに先行固定版Refを必須とし、同じrun/day/集合/epochと、全T×両方式の実在・検証済みPREDICTED/NO_PREDICTION Refを要求する。day_manifest.no_predictionは正本bundle.reasonsの一致索引であって独立正本ではない。NULL Refや理由索引だけでは終端にせず、U=0も確認して開放する。対象外Xにはこの終端義務を広げない。

予定時刻だけが欠け本人/名簿対応が確認できる場合は、input=NULLの不能束を参照して正常な結果照合/保存へ進める。一方、元ID/本人不明の場合は候補/不能記録の不明理由を保持し、結果本人照合まで成功とみなさず未照合/未評価/失敗へつなぐ。input=NULLの履歴に存在しないinput Refを作らない。候補/結果/不能理由を残し、得点contextや本人対応等、各STAT/履歴feature固有の要件ごとに利用可否/不足理由を判定する。不能だから一律に全履歴採用/全履歴拒否とはしない。

保存予測は有限0〜1の各順位/上位2・3着確率、順位別合計1、Ordered Top3合計1、同選手の順位重複なし、top3上限1、utility→E06 decision整合を既存1e-12許容で検査する。基準へ架空の確率分布を付けない。結果は別bundleだけに保存、prediction/inputには実rank/statusを入れない。

全bundleは排他的な新規stageへ期待bytes/SHAを保持して書込み、flush/fsync、全期待ファイルの完全一覧/通常ファイル検査、manifest、LOCKED、同filesystem renameで公開。COMPLETE/FAILED等の余分な終端を混在させない。run/dayとIDごとの永続flock、同一内容REUSED・同一ID相違CONFLICT、破損/中断は再生成せず証跡保持。新day lock/開放receiptはappend-only別イベントで、既存manifestへ追記上書きしない。day manifestのreceipt参照は結果開放後の新イベント版から辿り、予測lock時のmanifest自体を更新しない。

既存Pipelineの成功reproduce cleanupにはunlinkがある。後続adapterはそこを無条件に呼ばず成功stageも保持する。片付けが必要な場合だけ、作成記録済みpathを既存安全helperで `/home/shinya/Desktop/Trash/` 内へrename-only退避。失敗時は元位置保持、永久削除fallbackなし。通常RaceRepositoryのentry upsert/不要行削除、RaceResultImportServiceの最新結果更新/中止時行削除は版保存の代替にしない。

## 8. 指標・母数・例外

新 **PRIMARY-TUPLE-COMMON-DENOMINATOR-v1**。保存した `primary_position_1_bike/2/3` の**同じ3人**を用いる。旧EXACT_ORDERED_TOP3_RATEはmap_ordered_top3、旧EXACT_TOP3_SET_RATEはmap_top3_setの指標であり流用しない。旧POSITION_i_ACCURACYも各位置だけ一意なら母数へ入れるので、新名と共通母数adapterで区別する。

方式mのE_mは、結果開放前lock済みの**PREDICTED**＋本人/車番/状態/集合が整合するCONFIRMED/CORRECTED結果版＋公式1/2/3着が各一人の集合。NO_PREDICTION bundleは監査・件数集計の参照として保持するが、PrimaryがないのでE_m/E_pairの適格予測には数えない。C1対STAT01の主比較は**E_pair=E_C1∩E_STAT01**、同一result版で両方式を評価する。

| 新指標 | 分子 | 分母 |
| --- | --- | --- |
| PRIMARY_EXACT_ORDERED_TOP3_RATE | 保存3人と公式順序の完全一致レース | E_m、主比較ではE_pair |
| PRIMARY_TUPLE_POSITION_1/2/3_ACCURACY（各項目） | 保存した該当位置の一致レース | 3位置とも同じE_m、比較ではE_pair |
| PRIMARY_EXACT_TOP3_SET_RATE | 同じ保存3人の順不同一致レース | 同じE_m/E_pair |
| PREDICTION_COVERAGE | 当該方式のlock済みPREDICTED数 | 事前対象T |

| 例外 | 主母数・集計での扱い |
| --- | --- |
| 公式上位1〜3着の同着/欠落/正常完走3人未満 | 理由別除外。車番等で公式順位を一意化しない |
| 4着以下だけ同着・上位3着は各一意 | 含める |
| 中止 | 台帳/中止数、主率は評価不能 |
| 失格/落車/未完走/欠場等があるが公式上位3着一意 | 原則含める。予測選手が入らなければ不一致、結果を見て除外しない |
| 未確定/未取得/取得失敗/本人・結果集合不整合 | 未評価/失敗として保持。負け0へ補完しない。名簿を結果に合わせ縮小しない |
| NO_PREDICTION | 的中率母数外、T/coverage/理由件数には残す |
| 分母0 | rate=NULL＋UNEVALUABLE_ZERO_DENOMINATOR。旧Evaluatorの内部0を書き換えず新表示adapterで扱う |

全体は分子合計/分母合計で、日別率の単純平均ではない。Hit@3は任意補助の一致位置数/(3E)であり、3人全員一致率ではない。MAP等は別欄。候補/対象外/対象/方式別予測済み/理由付き不能/技術未処理/結果取得済み/評価可/除外/未取得/E_pairを必ず掲載する。未処理/取得未了が残る集計はis_final=false。新Gate/重いbootstrap/有意差検定を完了条件へ追加しない。

### 8.1 手計算による仕様検算（実レース保存なし）

保存予測を全て[1,2,3]とする3例: 公式[1,2,3]、[1,3,2]、[4,2,3]。E=3、完全順序1/3、各位置一致は各2/3、順不同2/3。補助の位置一致合計6/(3×3)=2/3であり完全順序1/3とは異なる。

この3例に「予測あり・公式1着同着」と「入力不足NO_PREDICTION」を各1対象追加すると、T=5/P=4/N=1/U=0、coverage4/5、E=3/同着除外1。主率の分子/分母は上のまま。両方式同予測ならE_pair=3。さらに基準方式だけ3番目を不能とする独立例では、C1単独E=3、基準E=2、E_pair=2で双方を再集計し、単独率との差を混同しない。T=0またはE=0の対応率はNULL。これらは人工**説明例**であり、新規Fixture/実資料評価ではない。

### 8.2 PR133修正受入例 R1〜R6

JSON hand_checks.pr133_review_examplesと既存A01/A04/A08/A09/A12を対応させる。すべて**メモリ内の記号的な仕様例**であり、実レース/実在証拠/実装成功の記録ではない。仮の元race/entry/player IDや架空のinput_as_ofを作らない。

| 例 | 条件と期待結果 |
| --- | --- |
| R1 /A08 | PREDICTED＋正常input Refは成立、PREDICTED＋input=NULLは拒否。NO_PREDICTIONでも正常inputがあれば保持 |
| R2 /A01/A08 | 予定発走/締切のみ欠損、race/本人対応は確認済み。両方式にMISSING_SCHEDULE・input=NULLの不能束を保存できる。先行候補固定版/capture証拠→両終端→day lock→結果保存が成立し、hash循環なし。証拠なしの場合は[]＋不足理由であり架空Refを要求しない |
| R3 /A01 | 技術例外/未処理が残れば結果開放拒否、不能理由へ変換しない。理由だけの台帳やNULL Refも終端ではない |
| R4 /A09 | R2の1対象だけなら各方式T=1/P=0/N=1/U=0、coverage=0/1=0、E_m=E_pair=0、的中率NULL。候補/結果/不能理由を保持、架空history input Refなし |
| R5 /A04 | history_event_day=1月1日、logical_available_from=1月2日、target_day=1月2日は日付境界を通過。同日/未来イベント・利用開始1月3日は拒否。日付を通過しても固定epoch外/固有cutoff不成立は拒否 |
| R6 /A04/A12 | 結果開放後、別IDでも予測/不能記録の作り直しは拒否。元終端束と元epochを検証再利用して結果取得・評価だけを再開することは許可 |

## 9. 次の最小接続実装（今回は未実装）

| gap | 再利用する現行file/class | 追加/限定変更案・入力→出力 | 受入ケース |
| --- | --- | --- | --- |
| G1 結果を呼ばない入力入口 | SyncRaceListCommand/RaceListSyncService/RaceEntryListParser、RaceLiveFetcher::fetchDetail、RaceDetailParser、PrestartCapture/Projection | 新OutcomeFreeRacecard入口（仮称）。許可期間/固定identity/request→private Raw/capture＋許可PJ0315得点/名簿bundle。Projectionの本人/category/取得元の低層検査を再利用し旧Bridge全体を2026へ開かない | A01/A02/A05、結果取得器呼出し0、禁止列混入拒否 |
| G2 固定C1/基準adapter | Stat01/Batch02〜05 Calculator、HistoricalRaceRepository、Registry/Eligibility、HistoryReader/Aggregator、Dataset/Layout/Predictor、Final ModelLoader::restore | 新ChronologicalInput adapter（仮称）。許可期間＋epoch＋明示STAT source map→旧順序16信号/anchor＋C1/基準の束。既存SQLの年ガード・固定runをそのまま使用不可。必要時はDataset/HistoryAggregatorの年検査と純算術の境界だけを限定分離し、旧公開入口の年拒否は保持 | A03/A06/A07/A13/A14。年を2025へ偽装せず、式/bin/support/NULL/同点不変 |
| G3 日別barrier | Pipelineの開始終了検査/保存順・ArtifactStore排他のパターン | 新Day coordinator（仮称）。候補台帳＋epoch＋各保存状態→day lock/結果開放receipt/次epoch。取得/計算/結果workerは分離し巨大transactionにしない | A01/A02/A04/A08/A11。技術未処理は開放停止、同日結果非参照 |
| G4 Primary共通母数 | Result Matcherのidentity/結果検証、Bt03e05MetricEvaluator::rankBaseline、E06 decision | 新PrimaryTupleMetric adapter（仮称）。保存両予測＋同一結果版→新指標の分子/母数/coverage/E_pair。旧EvaluatorのMAP意味や旧POSITION母数を変更しない | A09/A10/A11。基準も結果後再計算値ではなく保存正本を参照 |
| G5 不変保存/再開 | Pipeline ArtifactStore、ResultStore、Requestの瞬間比較、既存安全helperのパターン | 新契約専用store。run/day/input/pred/result/eval/epoch＋seal→不変bundle/LOCKED/再利用/CONFLICT。旧Contract/manifestを改版しない、旧削除cleanup不使用 | A04/A11/A12/A14、余分/不足ファイル拒否・中断保持・訂正版と初版分離 |

### 9.1 旧CLIが単純連結できない根拠

- [RaceEntryListParser](../app/Domain/Keirin/Scraping/Parsers/RaceEntryListParser.php)のRaceListEntryDtoにraceScoreなし、RaceRepository::syncRaceDayはrace_scoreを保存しない。
- [RaceResultSyncService](../app/Domain/Keirin/Scraping/Services/RaceResultSyncService.php)はfetchDetail(PJ0315)→updateRaceDetail（ここでrace_score更新）→fetchResult(PJ0326)を連続実行し、通常はresult_availableも選別する。
- [RaceRepository](../app/Repositories/RaceRepository.php)と[RaceResultImportService](../app/Domain/Keirin/Scraping/Services/RaceResultImportService.php)は業務最新行の更新/不要行削除を持つ。専用不変束へ低層結果Parserを接続し、通常同期を丸ごと起動しない。
- よって「sync-race-list→STAT→予測→sync-results」は得点欠損/結果開放順を満たさない。今ある2022〜2025用コマンドをそのまま2026で実行可能とは記載しない。

### 9.2 年ガード・旧artifact互換

Pipeline Request/RaceInputSource、HistoryReader、HistoryAggregator、Dataset、ModelLoader::predictions、Result Contract/Matcherは2022〜2025限定。Bt01/Bt02SourceManifestも固定年別ID/UUID/hashであり、2026 source mapは未作成。AcquiredHistorySupport、STAT20 Repository/Options等にも年制約がある。全21STATを2026用に作り直すのではなく、必要13STAT/4履歴の明示mapと監査だけを新契約へ接続する。

**Contract::planを書き換えるだけでは旧束を壊す**。ModelLoader::publishedはFinal Contract全体、Pipeline ArtifactStore::verifyとResultStore::verifyも各planとの厳密一致を要求する。旧plan/学習年/モデルbytes/旧sealは不変のまま、新しい許可scopeのvalidation/input入口を別にする。Final ModelLoader::restoreによるモデル構造検査は利用可能だが、旧predictionsにはDatasetも含む年拒否があるため直接2026を流さない。境界分離が必要なら新メソッド/薄い明示scope引数と旧既定入口を分離し、旧2026拒否と限定2022〜2025の数値一致・旧artifact読込をA13/A14で必須検査する。新実行コードSHAは新しい識別、古い生成時code sealは書き換えない。

## 10. PR133終了時の未決事項と次の受入（履歴）

- **O1 初期履歴未確立**: 第3.1節の既知キャッシュ/元exportを限定検証して生イベント/得点文脈束を接続する案を推奨。選択肢は、別途承認した明示的な疎履歴/NULLでの実行。いずれも「当時公開済み」を作らない。影響は実行時の初期epoch bindingで、契約レビューやadapter製造全体を停止する理由ではない。
- **O2 具体案の承認待ち**: 1月/保存最終C1/STAT01基準/日別lock/Primary共通母数をレビューで決定する。実2026取得・評価/HTTP/保存の許可はさらに明示する。許諾UNVERIFIEDやD1〜D8を確認済みにしない。

次の許可作業は**この契約案のレビュー**。受入後の最小実装はG1〜G5とA01〜A14、原本read-only/ネットワーク遮断の限定テスト・既存development入力の算術互換から始め、実2026実行は別承認。全46/全占術/全期間再受入やB1再実行を永久条件に追加しない。H1-U/正式採用/LIVEへ自動進行しない。

## 11. 初版の実行と検証（履歴）

Git branch/status/HEAD/origin/main/merge親/tree照合、AGENTS全文、MASTER PLANの全文読取り・節索引と現行8制御/関係仕様、指定文書/直接コード/DTO/関連テストをread-only確認。モデル/入力は第3節の明示参照だけをhash確認し、全資料探索/全Raw解析/新SQLiteコピーは行わない。

外部補助検証は `/tmp/stat2026-contract-20261101-aOoBb0A9/` に保持。`php -d memory_limit=128M .../inspect.php` の最終実行はexit0、peak4,194,304bytes。最初は補助scriptがfit/resultのsemantic_files相対キーをrun-01/finalと誤解してexit255で停止した。実際のfinal/model.jsonキーに合わせて再確認し、原本は無変更。これは補助検証の失敗でありモデル生成/推論失敗ではない。

JSON構文/必須項目/日付境界/21STAT重複・役割合計/モデル16順/主・比較の母数と説明用算術、Markdown172相対参照/47表/コードブロック、過去15.97/v1.92/全46台帳本文の不変、許可4ファイルのみの差分を別補助scriptで検査し成功。限定参照のSTART/END hashも一致。結果を同rootに保持する。追跡済みgit diff --checkはexit0、新規2ファイルのno-index --checkは空白エラー出力なし/exit1（差分あり）。初回の文書検証補助scriptはこのexit1を失敗と扱いexit255で停止したため、差分ありと空白エラーを区別して再確認した。文書の空白エラーやアプリの失敗ではない。PHPUnit/通常全体/Pintは**NOT_RUN**（PHP計算/テスト無変更の本工程仕様）。説明用算術の検算を実モデル評価やPHPUnit成功とは表現しない。

本番/業務DB接続・書込み・Migration、実HTTP、実2026開催参照、学習/OOF/再予測/性能評価/年次生成/再packageは0。元.env/storage/モデル/Raw/既存artifactを変更・移動・永久削除していない。未コミット4ファイルでレビュー待ちとする。

## 12. PR133 P2文書修正 / 2026-11-01

修正開始branchは既存 `docs/stat-2026-chronological-validation-contract-01`、HEAD/ローカル追跡branchは `827582af4bb311b1e78e3fa0b5b5504ae30d5e0f`、clean。base/ローカルorigin/mainは `ea37ec91dc42dd0c517b444a26c2ef3b5cc89f48`。fetch/pull/GitHub照会・branch作成/切替なし。初版のmain開始SHA/未コミット終了と区別し、今回もcommit/push/PR/mergeなし。

F1は第6・7・7.1・8節/保存表/既存受入ケース、JSON prediction/input/day/result/history schemaと条件へ反映。F2は第6節とJSON history_conditionsのJST日付境界、storage.reprediction_after_result_openへ反映した。旧result_open_after_repredictionキーは**廃止済み**でaliasとして残さない。7種の保存単位と既存append-only日別イベントを維持し、新しいトップレベルbundle/アプリ/テストを追加しない。

今回提示された別ユーザー全体ログは4,534 passed/46,563 assertions/155 warnings/9 skipped/391.11秒。第1節のPR132後393.73秒・PR131後390.28秒とは混同せず、今回Codexの検証でも警告全解消の証拠でもない。PHPUnit/通常全体/Pintは今回**NOT_RUN**。`python3 /tmp/pr133-contract-review-20261101-8ctFpNtz/validate.py` でR1〜R6のメモリ内仕様検算、JSON構文/条件、Markdown155相対リンク/42表/コードブロック、差分範囲/git diff --checkを検証し、766チェック成功・exit0。これは記号的な仕様検算であり、実在bundle/RawやPHP実装の検証ではない。

状態はREADY_FOR_REVIEW_WITH_OPEN_ITEMSのまま、PR133再レビュー待ち。初期履歴NOT_ESTABLISHED/O1、具体案承認待ち/O2、期間/モデル/STAT/指標とPR132有限完了を維持。旧原本の再hash/DB/HTTP/SQLiteコピー/実2026参照/学習/予測/結果評価は0、app/tests/config/database/依存/AGENTS.md無変更、未コミットで停止する。

## 13. PR133マージ後の限定接続実装 / 2026-11-01

PR133の修正終了HEAD827582aと未コミット状態は当時の事実。その後ユーザーは全体4,534 passed /46,563 assertions /155 warnings /9 skipped /391.67秒（最終集計に失敗表示なし）を実行し、7c6d0e5をcommit/push、124afa68でmergeした。391.11秒/393.73秒/390.28秒はそれぞれ別記録である。明示exit・警告全原因は未確認のまま。本限定検証の結果に読み替えない。

O2は「1月/保存最終C1対STAT01/日別lock/Primary共通母数を限定製造の基準として受入済み」と「実2026実行未許可」に分離した。正本JSONのlimited_implementation_acceptanceとoffline_implementation_profileを参照。正確な承認時刻は未提示であり、架空のtimestampを登録しない。O1の由来・完全性・実資料接続は未確立である。

実装補足は7種類を維持する。validation_runの固定HEADERと後続RUN_INDEX、day_manifestのCANDIDATES_LOCKED/DAY_PREDICTIONS_LOCKED/RESULTS_OPENEDを不変イベントとして別IDで保存する。receiptは開放時刻と既存day lock Refを持つ。日別lockを後から書き換えない。candidate_refは必ず先行候補版へ向き、終端→候補→epochでhash循環を作らない。

### 13.1 PR134修正補足 / 2026-11-02

offline_implementation_profileはv2-PR134。期間/方式/13STAT/既存feature版/モデル/5指標/母数を変えず、入力schemaと取得範囲、結果/epoch版管理を補足する。初版v1の138成功と、その後のユーザー全体20失敗は別記録として[接続実装記録第10節](2026-chronological-connection-01.md#10-pr134-f1f10レビュー修正--2026-11-02)へ残す。旧束のbytes/manifest/code sealをv2へ書き換えない。

- 初期sourceはsealだけでなく、初日Dのevent day<D、logical release<=D、宣言end未満を公開前にstream検証する。当日/未来/未releaseの混入は技術失敗。
- history_event_fromは全STATのJST00下限。39母集団/42pairにも適用し、C1新入口へ宣言した取得開始を渡す。120日未充足/取得開始不明はLEFT_TRUNCATED/4NULL、旧aggregate()の2022固定と旧年制約は維持。実行manifestには明示的な開始を要求し、不明を2022へ補完しない。
- 正常STATの計数と、未確定/unverifiedを含むC1の不足証拠を分離する。全員の正の有限得点が揃わない競争文脈は平均/最大/母std/percentile/residualをNULLとし、既知選手だけで代用しない。pairの数値原値は既存raceScore()で正規化する。
- 対象manifestは構造別allowlist/型をhash前に検査。元本人4列とRaw由来3列だけを投影し、対象candidate/context/本人対応のrank/status等を拒否する。過去履歴と結果のrank/statusは禁止しない。元race IDは正整数一致だけverified、NULL双方/片側は未検証とし、既知競合/不正日時/未知Enumは隠さない。
- result.source_resultは受領内容と取得証拠を版単位で固定する。同版の着順/状態/本人/日時/source_id/取得seal差はCONFLICT。日別結果file全bytesがseal単位であり、他レース更新による証拠差も黙殺しない。v>1は同raceの直前parent Refと既存canTransitionFrom、別evaluationを要求。正当な暫定→確定をCORRECTEDへ偽装しない。
- 結果/評価の保存と、未処理の将来epoch採用を分離する。全日固定済みでも前者は可能。validation_runのEPOCH_SELECTIONへ日付連番/親selection/base/採用epoch/更新結果を明示し、epoch IDは親/結果/日付のhashで区別。親系列・範囲・sealを検証して再開し、同raceを1採用版に限定する。保存済みcreated_atは再試行で維持する。
- RUN_INDEXは方式別評価の元となる1race1版のselected_results/selected_evaluationsを持つ不変snapshot。別選択なら別ID、既存索引/予測/確定入力/epochは不変。結果更新だけの追加では旧索引を当時の状態として残し、保存評価の再現は旧/新を個別に行い二重合計しない。

受入は前工程のT01〜T12/A01〜A14/R1〜R6にF1〜F10の境界・欠損・版・再開試験を追加する。実2026未許可・O1未確立・予測利用禁止/B1未承認は不変であり、v2は実初期資料や予測精度を確立した承認ではない。

DB由来ではない計算はARTIFACT source_ref/row_ref/execution_uuid/calculation_versionを保存し、source_run_id/source_result_idはNULLとする。元race/entry/player IDは保持し、仮のDB run IDは作らない。入力構成不能のNO_PREDICTIONだけinput=NULLを許可し、既存input・capture証拠・理由を保持する。CLIの人工scopeはSYNTHETIC_TEST、旧資料互換はLEGACY_COMPATIBILITYで区別する。

新規JSON正規化はobjectキーだけ整列しlist順序と空object/listを保持する。専用Storeの各bundleはdata.json/manifest.json/LOCKEDの正確な3件であり、依存seal/状態/本人/終端/母数を検証する。旧Files::canonical、旧Contract::plan、モデルbytesと過去code sealは変更しない。

G1〜G5のPHP実装・人工受入・旧入力互換の実測とT01〜T12/A01〜A14/R1〜R6対応は[接続実装記録](2026-chronological-connection-01.md)。これらは実2026の成績でも、当時の発走前資料を確立した証明でもない。historical_as_of_available=false、prediction_use=NOT_AUTHORIZED、正式採用/B1/LIVE未承認を維持する。次は今回差分と限定証跡のレビューのみ。
