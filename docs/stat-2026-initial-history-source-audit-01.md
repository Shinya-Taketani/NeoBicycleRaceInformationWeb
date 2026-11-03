# STAT-2026-INITIAL-HISTORY-SOURCE-AUDIT-01

> 2026-11-04 後続状態: 本監査はPR135でマージ・受入済み。最新ユーザー指示による固定集合のREAD ONLYメタデータ補完を[後続実装記録](stat-2026-initial-history-metadata-supplement-01.md)で完了した。以下の実測値・結論C・DB未実行/別承認待ちは本監査終了時の履歴として保持する。後続でもO1実初期epochは未確立、正式投影・実2026実行は未実施。

作成日: 2026-11-03 JST。状態: `SOURCE_AUDIT_COMPLETE_EXTRACTION_REQUIRED` / レビュー待ち。

## 1. 結論と範囲

**C: 指定資料だけでは構成できず、限定的な追加抽出が必要。** 次の1工程は `STAT-2026-INITIAL-HISTORY-METADATA-SUPPLEMENT-01` とする仕様案（第8節）。今回そのDB抽出は実行・承認していない。

保存メタデータは2025-12-31まで存在する。B/Cの101,326レース・717,709出走は一致し、同日の63レース・432出走も確認した。一方、全対象の独立した保存時刻、内部文脈ID等は不足する。少数Rawの成功は全量投影・原本完全性・実世界の網羅性を証明しない。`initial_history_epoch=NOT_ESTABLISHED` / **O1未確立**、`historical_as_of_available=false`、`prediction_use=NOT_AUTHORIZED` を維持する。

- 開始clean main/HEAD/ローカルorigin/main: `ad869b915f0e1088ae20ee7c8ba2eb7eb441bc7a`。
- PR134 MERGED / 限定オフライン接続実装受入済みをローカルmerge履歴で確認。親 `124afa68ce2acbd52384d38a7e510ad7a0121c1d` / `cec44e3cc0e2957b4e217912a8711a5952aa3b2b`、tree `5ee6a231e39be5bed5a0b6aa7180b9d9f0c7cfcb` 一致。fetch/外部照会なし。
- 新branch: `docs/stat-2026-initial-history-source-audit-01`。本工程は調査スクリプトと2文書だけ。アプリ・既存テスト・設定・計算式・モデルは不変。
- 純粋Parser/変換処理だけを使用。DB接続する既存builder/exporter/SourceVerifierの入口、Laravelのアプリ起動は実行しない。
- 業務DB/Redis/新規HTTP/実2026レース/学習/実予測/性能評価/正式epoch・validation_run作成は各0。保存SQLiteのread-only調査は業務DB接続と区別する。
- 原本はbwrapでread-only、ネットワーク遮断。新rootだけ書込み可、umask077。既存資料の変更・複製・移動・削除なし。新しい集計SQLiteはメタデータだけであり、5.7GB cacheの複製ではない。

## 2. 証跡と4資料の由来

証跡root（以下 `R`）:

`/home/shinya/neo-keirin-artifacts/stat-2026-initial-history-source-audit-01/run-20261103-9WpE3ttB`

原本rootは次の4件に限定した。入口・直接参照は `read-plan.json`、入口sealは `entry-seals.json`、全量JSONLの宣言値と実測値・schemaは `metadata-seals.json`。`source-inventory.json` には小型原本24件の開始終了seal一致・完了marker・未確認範囲を保存した。全量読取り前に計画を、Raw本文前に対象ID/取得版を保存した。

| 記号 | 正確なroot / 由来 | 今回の確認 |
| --- | --- | --- |
| A | `/home/shinya/neo-keirin-artifacts/tactical-history-01-review-fix-20260916-01` | inputs-v2 manifest、年別inputs/history各4、索引、report-export-manifest、source-before/after等の直接参照。旧対象別集計を新履歴に転用しない |
| B | `/home/shinya/neo-keirin-artifacts/stat35-data-readiness-audit-01-20260921-01/stat35-agari-readiness-2022-2025-pr64-review-fix-01` | manifest / LOCKED一致、database-inventoryとraw-source-inventoryの2 JSONL・sidecar一致 |
| C | `/home/shinya/neo-keirin-artifacts/stat36-start-count-01/run-20261001-nK8sZXFb/source-cursor-plan` | manifest / COMPLETE一致、targets/race-requests/historical-requests/sources一致。中断source-final不使用 |
| D | `/var/www/NeoBicycleRaceInformationWeb/storage/app/private/backtest/bt02/outcome-context/a4b1800095b22fe0ae40216ce90243c7e80a0cf652a96e328c45223160c3dad9` | Aのreport-export-manifestが列挙するinput-run-result.jsonのoutcome_manifest参照と既存Builderの保存base規則で確定。4年partition全量sealと純粋Artifact::openのmanifest identity検証 |

Aの由来入口 `/home/shinya/neo-keirin-artifacts/tactical-history-final-01-20260917-01/fit/parent-start.json` と、inputs-v2 manifestの明示元 `/home/shinya/neo-keirin-artifacts/tactical-history-01-20260915-01/input-attempt-03/inputs/manifest.json` を確認した。Aは入力manifest/report台帳を確認した範囲であり、root全成果物の完了再検証ではない。Dは専用COMPLETEではなくcanonical manifest identityで公開を表す既存形式である。

| manifest | bytes実測 | 既記録SHA-256と今回測定（別に計算して一致） |
| --- | ---: | --- |
| A/inputs-v2 | 3,823 | `d5158a7671f8c453afc77dccc1ab0b089c940198f58f388c038ad0d4fad684cf` |
| B | 8,875 | `bd7ea209724bb1848ad4a44b1c9930bb0a5bb9c23806b0183eb0c6c07f9710a1` |
| C | 5,405 | `691717e4a95c7912c5fcfff23c3da8a1e1df13194ccd87bd5cc4dde1d3f41e7a` |
| D | 920 | 実ファイルSHA `afbcbf16c6c35f91d694b8e65d8875a41b71b280633f1bfae503c48e44e32b17`。semantic identity `a4b18000…3dad9` とは異なる概念 |

全18 JSONLは各1回のstreamで行数/bytes/SHA/対象集合を計測し宣言sealと一致。親文書の宣言、現在測定hash、Rawの現在実在確認は別欄で保持した。全媒体探索・PR132有限snapshot再実行・全Raw確認は行わない。

## 3. 全量メタデータの実測

各年の最小/最大日は、A対象・B/C対象・Dとも1月1日/12月31日。これは固定保存集合の範囲であり、実世界全レースの取得完全性を意味しない。月別48区分は `metadata-summary.json` の `months`、cache索引の月別は `sqlite-readonly-audit.json` に保存した。

| 年 | B/Cレース | B/C固有出走 | A固定C1レース | A対象出走 | D結果行 |
| --- | ---: | ---: | ---: | ---: | ---: |
| 2022 | 24,868 | 174,152 | 24,394 | 170,835 | 173,847 |
| 2023 | 25,561 | 181,548 | 25,197 | 179,007 | 181,392 |
| 2024 | 25,624 | 182,004 | 25,212 | 179,089 | 181,847 |
| 2025 | 25,273 | 180,005 | 24,866 | 177,120 | 179,751 |
| 計 | 101,326 | 717,709 | 99,669 | 706,051 | 716,837 |

| 範囲 | B/Cレース / 出走 | A対象レース / 出走 | Dレース / 結果行 | PJ0315版 / PJ0326版 |
| --- | --- | --- | --- | --- |
| 2025年12月 | 2,057 / 14,603 | 2,024 / 14,377 | 2,057 / 14,589 | 2,057 / 2,057 |
| 2025-12-31 | 63 / 432 | 63 / 432 | 63 / 432 | 63 / 63 |

結合と版の実測（SQLは `metadata-queries.json` / `supplementary-queries.json` / `consistency-queries.json`）:

- B/C: race101,326・entry717,709全件結合、日付/場/番号/人数・entry/race/bike/登録番号の不一致0。名簿人数とentrant_countの不一致0。race/entry/本人/車番/登録番号の重複グループ0。
- 固定metadata上の開催日/場/番号の複合キー衝突0、fetch ID254,281件はすべて一意。既存CategoryPolicyでBのrace_typeは全101,326件MEN（Raw全件カテゴリーを再確認した意味ではない）。`source-final-checks.json` に保存。
- A対象706,051とBのrace/entry/player/bike、A入力とのentry/race/bike、cache索引717,709とBのentry/player/dayは全結合・不一致0。A対象外のBは1,657レース・11,658出走であり、A対象だけへ狭めない。
- Aの3,491開催キー `(meeting_id, meeting_start)` からmeeting_endを結合でき、B全101,326レースで不成立/終了日競合0。新たな推測日数ではない。非NULL日付検証/F11の不明NULL保持は維持する。
- Bの127,121 importは101,297レース。75,473レースが1版、25,824が2版。SUCCEEDED/RESULTS_AVAILABLE127,008版、SUCCEEDED/CANCELLED113版。RESULTS_AVAILABLEだけで確定と断定しない。
- B現行結果716,837行のimport参照は全成立し、同一race内のimport混在0。現行未参照25,921版、5/6/7/8/9行から参照される版は各1,636/6,789/83,243/566/8,966。未参照版は不要資料ではない。
- 現行結果のrace_entry_id/player_idは**各716,837行すべてNULL**。元entries側の本人ID/登録番号は欠損0。Rawのrace/bike/登録番号から厳密照合する必要があり、DBのNULLを補正しない。登録番号717,709件はすべて6桁・先頭0を持つ文字列。
- C PJ0315は127,160版/101,326レース、HTTP200127,159＋DNS_FAILURE1（Raw参照なし1）。各raceに成功版の参照あり。取得版数は75,494レースが1版、25,830が2版、2が3版。
- C PJ0326は127,121版/101,297レース、全HTTP200。Bのimport/log/path/SHA/bytesとの不一致0、取得時刻の同一瞬間比較も127,121版で不一致0。encpは非公開hashのみで対応確認、固定集合内の別レース衝突0。
- DはCONFIRMED101,200 / CANCELLED97 / UNAVAILABLE29。Bに現行結果なし126レース＝中止97＋未取得29。結果importなし29。これらを履歴不存在へ読み替えて捨てない。
- Bの旧Raw監査分類はPARSED127,008、EXPLICIT_CANCELLED_NO_RESULT_ROWS65、EXPLICIT_CANCELLED_PARTIAL_ROWS_NO_AGARI48。**旧台帳の分類件数**であり、今回は全Rawを再検証していない。
- D/B現行結果716,837行の車番/状態対応は一致。D/B予定時刻101,326件は文字列表現が異なるがoffset付き日時として同一瞬間（不一致0）。文字列差を競合にしない。
- Cの取得は2026-07-21〜08-04の**2022〜2025開催の事後取得**。実2026開催を読んだものでも、当時発走前の証明でもない。imported_atと取得時刻の差は0〜5秒/負値0だが、import処理開始と保存完了・commitを同一視しない。

## 4. 旧cacheの確認範囲

既存 `A/inputs-v2/history-cache.sqlite` は5,748,555,776bytes。宣言SHA `af4ccd5601a838d9c177b5bee7d718ce91beb847409323bdae6666ba69304199` は**今回全ファイル再hashしていない**。完全な原本検証済みとはしない。

PDO `sqlite:file:…?mode=ro`、`query_only=ON`、読取りtransactionで確認。journal_mode=delete、隣接WAL/SHM/journalなし、読取り前後のsize/mtime/dev/inode一致。`immutable=1` 等でWALを無視していない。DDL/index追加/VACUUMなし。

| 実schema | 件数 / 確認範囲 |
| --- | --- |
| history_entries(id, player_id, race_date) | 717,709件、2022〜2025。既存player/date indexあり。順序付き索引digest `ffe11080c9462954373e75aaadcac717804e3b3815d1da26a47b6ad26503c68a` は137bytesの索引台帳と一致 |
| windows(cache_key, audit) | 221,559窓。開催開始年別39,587 / 60,970 / 60,951 / 60,051。2022開始は05-01、他年は01-01、各年末まで |
| windows.audit.source_rows | 各年先頭/末尾キーの8窓だけ展開、191延べ本人行、サンプル結果日2022-01-07〜2025-12-19。全窓の固有履歴件数/最古最新はNOT_MEASURED |

source_rowsは本人1名のrank/status/決まり手・import/hash等であり全名簿/全員得点を収録しない。fetched_atは生成コード上 `race_results.fetched_at`（アプリ保存処理時刻）で、fetch logの実取得時刻ではない。少数窓の値が存在することと全量保存時刻の取得可能性は別である。

旧InputBuilder/HistoryReaderの開催前120日・同開催除外、meeting start<=2025-12-31かつrace date<meeting startの生成規則により、**この経路には2025-12-31結果を含められない**。これは実schema/生成元コードからの制約であり、全windows展開による発見とはしない。索引に12月31日があることを窓の同日結果収録と混同しない。旧4集計値や重複窓の合計を新初期履歴にしない。

## 5. 固定少数Raw

`fixed-race-samples.json` / `fixed-raw-plan.json` を本文読取り前に保存。各年を日付・予定発走時刻・元race ID順で選んだ先頭/末尾8レース（置換なし）:

| 年 | 先頭race ID | 末尾race ID |
| --- | ---: | ---: |
| 2022 | 89132 | 113830 |
| 2023 | 63539 | 88840 |
| 2024 | 38010 | 63386 |
| 2025 | 12716 | 37754 |

各sourceの取得順先頭/末尾（同版は1件）でPJ0315 **11版**、PJ0326 **11版**、計22本文。上限32以内、8レース/各source73延べ出走行・52固有出走を検証した。

- 全22版で現在存在/bytes/原SHA、レース日/場/番号、全名簿/車番/登録番号が一致。PJ0326はimportの元/変換後hashも一致。原bytesを複製せず必要field evidenceだけ保存。
- 既存CharacterEncodingConverter→RaceDetailParser / RaceLiveResultParserを使用。原hashとUTF-8変換後hashを別欄に記録し、source parser versionと今回解析コードを分離した。
- PJ0315の `sensyuTypeInfo[].heikinTokuten/kyuhan/wakuban` は得点/級班/枠。得点と級班は73/73行あり、枠は73/73行NULL（52固有出走）。未読717,657出走の得点等の欠損件数は未確認。今回の少数例は5/7車で9車全体の確認ではない。
- PC0201.C0201data.C0201racedtlのaftStartTime/aftBetTimeを予定/締切として確認。対象日とAsia/Tokyoを合わせ、存在しない時刻を補わない。
- PJ0326のtyakujyunItemSubDataのtyaku/kimarite/kojinStateItemSubDataを既存Parserで解釈し、11版はCONFIRMED。上位2名の決まり手欠損0。Dと73延べ結果行のrank/status・ページ状態も一致。
- 3レース×2sourceの6先頭/末尾ペアでは、原bytesは異なるが確認した出走者投影値は一致。全Raw全項目の版不変性や新初期履歴の採用版を確定したものではない。
- 本文/氏名/encp/接続秘密は出力せず、`raw-field-evidence.json` にID・hash・必要field・欠損・不一致を保存。未読Rawの現在実在/hash一致を参照件数から推定しない。

## 6. history_eventの36項目対応

正本はRの **`history-event-field-map-v3.json`**。旧 `history-event-field-map.json` / `history-event-field-map-final.json` / `history-event-field-map-v2.json` は作成途中の草稿として保持し、nullable/meeting_day_kind/Dのresult_status pointerをコード・既存schema照合で訂正した。JSONには元pointer、join、変換、NULL、確認範囲、未解決数を個別保存。以下は対応の要約である。

記号: 可=固定メタデータで取得可、少数=Raw8レースだけ確認、不足=資料未収録、案=未割当。件数の母数はrace/entry/importで異なる。

| history_event項目 | 元資料・join・変換 | NULL / 不足・確認範囲 |
| --- | --- | --- |
| race.original_race_id | B /race/race_id、C/D照合、元ID保持 | 必須、可101,326 |
| race.external_race_key | B track_code:YYYYMMDD:ゼロ埋めrace_number | 必須、派生案。元ID再付番なし |
| race.race_date | B /race/race_date、D /race_date、Raw PC0201.selKaisai | 必須、可101,326、Rawは少数 |
| race.track_code | B /race/track_code、PC0201.selKjyoCd | 必須、外部文字列を内部IDにしない |
| race.race_number | B /race/race_number、PC0201.selRaceNo | 必須、正整数 |
| race.entrant_count | B /race/entrant_countと/entries全員 | 必須、717,709名簿で不一致0 |
| race.meeting_id | B /race/meeting_id、A /target/meeting_id | nullable DTO、固定資料は欠損0 |
| race.meeting_start | B /race/meeting_start、A /target/meeting_start | nullable DTO、固定資料は欠損0 |
| race.meeting_end | A /target/meeting_end、meeting_id+startで結合 | NULL許容F11、101,326結合/競合0 |
| race.day_number | races→race_days.day_number（今回未抽出） | NULL許容、不足101,326。日付差から推定しない |
| race.race_type | B/D、Raw C0201racedtl.syumoku、既存CategoryPolicy | 不明を男子へ変換しない。Rawは少数 |
| race.scheduled_start_at | B/D offset日時、Raw aftStartTime | Readerで必須、メタデータ欠損0、Rawは少数 |
| race.sales_close_at | D /sales_close_at、Raw aftBetTime | nullable DTO、D欠損0、Rawは少数 |
| context.racetrack_id | races.racetrack_id→racetracks.id（未抽出） | NULL許容、不足101,326 |
| context.race_day_id | races.race_day_id（未抽出） | NULL許容、不足101,326 |
| context.meeting_grade | B /race/meeting_grade、既存正規化 | NULL許容、NULL124レース |
| context.meeting_day_kind | race_days→race_meetings.day_kind（未抽出） | NULL許容、不足101,326。通常/夜間等へ推定しない |
| entries.original_entry_id | B /entries[]/id、race+bike+登録番号 | 必須、717,709可。結果DBのID NULLは別事実 |
| entries.original_player_id | B /entries[]/player_id、C/A照合 | 必須、717,709可。現在プロフィール不使用 |
| entries.external_player_id | B/C /entries[]/external_player_id | 必須文字列、6桁・先頭0保持 |
| entries.bike | B /entries[]/bike_number、Raw syaban | 必須、本人/車番重複0 |
| entries.frame_number | PJ0315 sensyuTypeInfo[].wakuban | NULL許容、少数73延べ/52固有がNULL、残り未確認 |
| entries.grade | PJ0315 sensyuTypeInfo[].kyuhan | NULL許容、少数52固有あり、717,657未確認 |
| entries.race_score_raw | PJ0315 sensyuTypeInfo[].heikinTokuten | NULL許容、少数52固有あり、717,657未確認。0補完なし |
| entries.rank | D /results[]/rank、PJ0326 tyaku | 非正常/不明のみNULL、正常/同着はReaderの順位契約 |
| entries.status | B/D /results[]/result_status、PJ0326 tyaku/個人状態 | 既知EnumのUNKNOWNを含め必須、716,837メタデータ対応 |
| entries.winning_technique | PJ0326 kimarite、A窓本人行のみ | NULL許容、Dには未収録。上位決まり手不足をC1根拠に保持 |
| event.source_id | 原import/fetch IDs＋sealの複合Ref | 必須、派生識別子とDB IDを区別 |
| event.material_kind | 実2022〜2025資料の用途を明示 | 案、実資料をSYNTHETIC_TESTと偽装しない |
| event.verified | Raw完全性＋全本人/名簿/入力版/確定結果対応 | bool必須、全量未検証101,326。hashだけでtrue不可 |
| event.race_status | D race_status＋選択import/Raw detectedStatus | 既知Enum必須、101,318レースは今回Raw未読 |
| event.input_fetched_at | C sources /fetched_at、選択PJ0315版 | offset日時必須、全raceに成功参照。保存証明とは別 |
| event.fetched_at | B import.fetched_at↔C historical log | offset日時必須、取得なし29レース |
| event.saved_at | A窓の結果fetched_atはアプリ保存時刻。B/C/Dの全量台帳には独立列なし | offset日時必須、127,121 import版で別列不足。Aから解決可能な全件数は未測定 |
| event.confirmed_at | 独立した公式確定時刻なし | NULL許容、101,326レースUNKNOWN |
| event.logical_available_from | 既存日次再生規則の翌JST日付案 | 実在日付文字列必須F12、今回未割当 |

`RaceResultImportService::syncAvailableResults()` は結果fetched_atへ取込み処理時刻を設定する。実取得はlog、imported_atはimport記録、Raw保存はRawResponseStorageServiceのstore-before-log、log.created_atはその記録上限候補であり、いずれも正確なcommit時刻の証明と混同しない。全量の日時証拠を取得後、選択版の取得<=保存を検証する。現在日時/mtime/レース時刻で補わない。

F11に従いmeeting_end不明はNULL、durationもNULLとし、履歴全体を落とさない。未確定/未検証/決まり手不足/中止/未取得も不足台帳と本人参照を保持し、通常STAT採用外でもC1のPARTIAL_HISTORYを失わせない。29件の取得日時不足は現行Reader必須条件を満たせず、nullable文脈不足とは別のブロックである。

## 7. 履歴期間と容量

候補 `history_event_from=2022-01-01` / end-exclusive `2026-01-01T00:00:00+09:00` は契約案と固定資料範囲が根拠。実初期epochへは未登録。全取得済み履歴・直近N走・開催前・対戦と39の全母集団を扱うため、120日へ絞らない。

| 窓日数 | 2026-01-01からの開始日 | 固定2022開始の限界 |
| --- | --- | --- |
| 120 | 2025-09-03 | 時間範囲内、完全性は別途 |
| 365 | 2025-01-01 | 同上 |
| 730 | 2024-01-02 | 同上 |
| 1095 | 2023-01-02 | 同上 |
| 1825 | 2021-01-02 | 左切断364日。2021新規取得を今回追加しない |

| 現行上限 | 実測・限界 | 次の容量対応案（今回未実装） |
| --- | --- | --- |
| 選手別4096 | B固定集合の最大501出走。生涯全履歴の上限ではない | 元ID・対象期間・欠損を保持してbounded person/pair処理 |
| history_used8192 | 全race候補101,326、CONFIRMEDメタデータ101,200。Raw適格全件数は未測定 | 全採用raceで増える明細を不変chunkへstream保存、件数/hash参照化。全件適格なら現状は超過 |
| history_excluded8192 | 全量project NOT_RUN、実除外数未測定 | 除外も別stream明細、理由/IDを落とさない |
| 1 JSONL行1MiB | 最大元メタデータ行7,835bytes、少数Raw最大97,864bytes。投影event最大は未生成・未測定 | 全名簿eventの実サイズを投影段階で測定、Rawサイズで合格扱いしない |
| 追加予算96MiB / PHP128M | 本調査peak最大10,485,760bytes。全量Readerのpeak/時間は未測定 | 上限引上げ・間引き・期間短縮で回避せず、既存算術と同値なstream処理を別実装/回帰 |

## 8. 次の1工程の仕様案

`STAT-2026-INITIAL-HISTORY-METADATA-SUPPLEMENT-01`。今回NOT_RUN、業務DB接続には別途ユーザー承認が必要。正本は `next-stage-specification-v2.json`（旧草稿も保持）。棚卸しのやり直しでなく、次の固定集合に不足する列だけを抽出する。

| 固定集合 | 必要列 / 結合 | 期待する整合確認 |
| --- | --- | --- |
| Bの101,326 race | races→race_days→race_meetings、racetracks。内部ID/日番号/meeting grade・day_kind・期間、date/track/number/category/schedule | 101,326キー維持、既存A/B開催crosswalk照合。内部IDと外部codeを分離。現在値との差異はdriftとして保留 |
| B/Cの254,281 fetch ID | source/method/request_key、request_parametersのdispと非公開hash、fetched_at/created_at/updated_at、HTTP/path/SHA/bytes/parser/error | ID/race/要求bindingを固定。生encp/完全URLを出力しない。取得とRaw保存前後関係を監査 |
| Bの127,121 import ID | race/log IDs、requested_result_status、parsed_page_status、import_status、imported_at/created_at/updated_at、元/変換hash/path | 全版維持、現行版と未参照25,921版を区別。RESULTS_AVAILABLEから確定を推定しない |
| Bの716,837現行結果 | race/bike→entry、import ID、結果ID、rank/status/method、fetched_at/created_at/updated_at | 同じimportを指す場合のみ保存時刻候補として照合。旧版へ現在版時刻を流用しない。NULL entry/player IDは修復しない |
| Bの717,709 entry | id/race/player/external/bikeの本人照合のみ | 現在選手profileの得点/級班を過去へ補完しない |
| importなし29 race | 固定race ID/dateと不足理由 | 0件履歴/予測可能へ変換しない。捏造timestampを作らず保留 |

抽出手順はREAD ONLY/REPEATABLE READ、接続default_transaction_read_only=on、transaction_read_only=onを実照合、lock_timeout=5s・有限statement_timeout。固定IDのbounded chunk、SQL側 `race_date >= DATE '2022-01-01' AND race_date < DATE '2026-01-01'` と対象ID双方で制限する。ログはCの固定race/log対応、importはBの固定race/import/log対応から結び、全期間ログ探索をしない。SQL案は `metadata-supplement-proposal.sql` に保存（実行なし）。結果/DBスナップショットが旧台帳から変わっていれば異版を記録し、自動修復・再sealしない。

出力schema:

- `context.jsonl`: race/date/元内部ID/開催/日番号/day_kind/期間/gradeと抽出由来。
- `fetch-persistence.jsonl`: 固定race/log/disp、実取得・記録時刻、Raw sealと照合状態。
- `result-persistence.jsonl`: record_type=IMPORT/RESULTを区別し、127,121 importレコードと716,837結果行を別件数/hashで監査。単純joinによる重複増幅や古い版への時刻補完を禁止。
- `unresolved.jsonl`: 不在・競合・変更版・時刻由来未確立・29取得なしをキー付き保持。
- `manifest.json` / COMPLETEまたはFAILED: 固定入力seal、schema/SQL/スナップショット・日時・件数・join/欠損/drift・出力seal。history_epoch/validation_runはまだ作らない。

受入は固定集合維持・全件内訳一致、欠損/重複/競合を明示でき、保存時刻の意味と取得後の順序を検証できること。DBに当時の正確な保存完了/旧版時刻が残らない場合は不足を確定し、O1完全受入としない。nullable文脈値はNULLと機能別coverageで保持する。

**その後の投影案であり今回・次の抽出では実行しないもの**: 結果はBの固定現行参照import（同race混在0）を軸に、取消は一意な中止証拠を要求し、他版を履歴参照へ残す。PJ0315は封印集合内の本人/全名簿/保存証拠が成立する最新版を、宣言済み投影cutoff・取得日時→log IDで決定的に選ぶ。同時刻の意味的競合をIDだけで解決しない。全員得点・級班・Raw状態・結果版の一致検証が前提で、旧S表示一致を全項目へ拡張しない。これは事後再生の版案であり、発走前入力承認ではない。

容量対応は全epoch投影の前提として第7節のstream audit/同値演算を別途製造・回帰する必要がある。今回はコード変更も、全量project可能という認定もしていない。

## 9. 実行・検証・未確認

環境: `/usr/bin/php8.5` 8.5.4、各調査子PHP `-d memory_limit=128M`、bwrap `--ro-bind / / --unshare-net --bind R R`。各段階の実コマンド・stdout/stderr・終了コード・開始終了・PHP peakはRに保存した。調査スクリプトだけの構文確認・集計整合・文書静的確認を行い、PHPUnit/Pint/通常全体はNOT_RUN（アプリ無変更、旧危険cleanupを起動しない）。過去PR134の123/967・183/1545等を今回のテスト結果としない。

| 段階（runner引数） | JST開始〜終了 | 秒 | 実PHP終了コード / peak bytes |
| --- | --- | ---: | --- |
| discover | 10:08:36〜10:08:36 | 0.015 | 0 / 2,097,152 |
| metadata | 10:09:51〜10:10:12 | 21.946 | **255** / 2,097,152。18stream/cache計測保存後の集計SQLがambiguous column: atで停止 |
| aggregate | 10:10:56〜10:11:00 | 3.362 | 0 / 2,097,152。新集計SQLiteだけからSQL回復、原本再走査なし |
| raw | 10:11:46〜10:11:46 | 0.083 | 0 / 10,485,760 |
| supplement | 10:13:24〜10:13:24 | 0.496 | 0 / 2,097,152 |
| specification | 10:15:22〜10:15:22 | 0.0003 | 0 / 10,485,760 |
| refine | 構文エラー、段階内timer未起動 | 未測定 | **255** / 未測定。旧ファイル/ログ保持 |
| refine2 | 10:18:06〜10:18:06 | 0.302 | 0 / 10,485,760。保存済み73結果行とDを独立照合 |
| polish | 10:19:13〜10:19:13 | 0.0002 | 0 / 2,097,152。nullable/取得元の対応表v2確定 |

実行形式は `php R/runner.php <段階>`。runnerがbwrap/128M子PHPを起動し実PHP終了値を `<段階>-exit.json` へ保存する。metadataの内部execution.exit=1は例外分類値であり、OS上の終了255とは区別した。失敗証跡を成功へ書き換えず、回復段階を別名で保持した。

最終文書確認はvalidateでexit0、その後のvalidate2は既存syntax-validation.jsonへの排他生成が拒否されexit255（既存ログ上書きなし）。出力名を分離したvalidate3で最終差分を再確認する。これらも各command/stdout/stderr/exit/executionとともに保持し、前の結果を現在値へ書き換えない。最終正本は `document-validation-v2.json` / `syntax-validation-v3.json` / `review-final.diff` / `review-packet-final.txt`。構文不正の旧refine.phpは失敗再現資料として保持し、修正版refine2.phpと区別する。

code SHAは開始HEADと `code-seals.json` に保存。ChronologicalValidation::CoordinatorのcodeHashは `b633efe088090e09e9462195543047f17cbd971207c9755ad96ba1f62816dda2`。最終 `manifest.json` はスクリプトbytes/hash/出力対応、全証跡sealを列挙し、COMPLETEは**本調査の完了だけ**を示す。

未確認: cache全ファイルSHA/全windows、未読Raw全版の現在存在/hash/全員得点・確定状態、当時の公式公開/保存完了時刻、実世界の取得完全性、Reader全量の実適格件数/行サイズ/peak/時間。バックアップ検証・DB抽出・全量投影・実2026検証はNOT_RUN。O1/正式採用/2026実行を自動で開放せず、次は本報告と限定追加抽出仕様のレビューで停止する。
