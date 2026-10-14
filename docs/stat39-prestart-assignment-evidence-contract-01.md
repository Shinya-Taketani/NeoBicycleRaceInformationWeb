# STAT39-PRESTART-ASSIGNMENT-EVIDENCE-CONTRACT-01

- 作成日: 2026-10-15
- 文書状態: **未承認の設計案 v0.1 / DESIGN_REVIEW_REQUIRED**
- 開始main / 作業開始HEAD: `85c454aefa79129be87e81c1e2c2737afe14dc3d`
- 作業branch: `design/stat39-prestart-assignment-evidence-01`
- 対文書: [公式制度の出典台帳案](stat39-official-rule-source-ledger-01.md)
- 維持: `historical_as_of_available=false`、`prediction_use=NOT_AUTHORIZED`、`formal_adoption=false`、2026 holdout閉鎖

## 1. 工程の目的と範囲

発走前競走得点、車番・枠番と出走者変更、公式割当制度を、現在値ではなく観測時点の保存証拠から検証できる追加契約を設計する。コード製造、収集、DB接続、Migration、Raw本文の新規監査、学習・予測・評価は行わない。変更対象は本書と対文書の2 Markdownだけ。

開始時のmainは指定SHAでclean。ローカル履歴のPR #107 mergeと最新ユーザー指示を開始根拠とする。MASTER PLAN v1.70/5.0/15.77の古い工程現在地は当時の履歴として読み、本工程の状態へ書き換えない。旧C1、STAT-01、STAT-39-existing-db-v1、C1_PLUS_FIELD_BIKE不採用、BT-02/BT-03/Gate、既存run・モデル・manifest/hashは不変。

本書の「確認済み」はコード・保存文書の確認、「過去記録」は旧工程で報告された実測、「案」は未承認仕様、「未確認」は証拠未取得を意味する。設計提出完了を実装完了・予測利用承認と扱わない。

## 2. 既存資料・実装の確認結果

### 2.1 根拠資料

- [AGENTS](../AGENTS.md)、[MASTER PLAN](statistical-engine-master-plan.md) の現在地、凍結契約、holdout、15.59/15.71〜15.74/15.77。
- PR #107の[拡張要件](stat39-extension-requirements-01.md)、[PR #106研究と監査修正](stat39-bike-frame-probability-research-01.md)、[Batch04契約](statistical-engine-batch04-existing-db.md)、[旧カテゴリ追加比較](stat39-c1-field-bike-01.md)。旧研究の保存得点は発走前観測を証明せず、追加カテゴリは主Gate未達・NOT_ADOPTEDという記録を維持する。
- [既存証拠契約](c1-racecard-prestart-evidence-contract-01.md) Sections 3/5/6/9（D1〜D8）、[as-of監査](c1-racecard-asof-preflight-01.md)、[readiness](c1-tactical-input-readiness-01.md)。旧監査の発走前候補0・Phase B非実施は当時の範囲限定の結論で、全歴史資料の不存在証明ではない。

残タスク原本を実在・SHA・該当節全文で照合した。

`/home/shinya/ダウンロード/スクレイピング残タスク_統計エンジン連携_STAT-01-46確定_v1.0(2).md`

SHA-256: `963ae1d64b6f04f1eccbefc039c7228710b950d7d22bcb413cdf17ea921471c3`（指定値と一致）。SCR-STAT-01-01は12〜28行、SCR-STAT-39-01〜04はSTAT-39関連節。ファイル名v1.0(2)と本文v0.1を混同せず、5項目とも原本のOPENを維持する。

### 2.2 現行の証拠部品と不足

| 確認した現行ソース | 再利用する責務 | 今回の追加契約との差 |
|---|---|---|
| [CaptureStore::save](../app/Domain/Keirin/Scraping/PrestartEvidence/CaptureStore.php)、[SyntheticCapture](../app/Domain/Keirin/Scraping/PrestartEvidence/SyntheticCapture.php)、[StoredCapture](../app/Domain/Keirin/Scraping/PrestartEvidence/StoredCapture.php) | 人工1取得1capture、排他保存、同bodyも別ID、Raw/capture/target/private source/検証/manifest/marker | 実HTTPは行わずfetch_log_id=NULL。raw_saved_atはRaw書込み後の実時計。request開始・検証/公開完了時計・変更履歴はない |
| [ObservationProjector::project/classify](../app/Domain/Keirin/Scraping/PrestartEvidence/ObservationProjector.php) | race/本人/脚質/予定時刻、受信とRaw保存の独立時点分類 | 得点・枠の属性投影はない。`C1-RACECARD-PRESTART-TIMING-v2`は取得候補の契約で、予測cutoff適格性ではない |
| [RawObservationDecoder::object/embedded](../app/Domain/Keirin/Scraping/PrestartEvidence/RawObservationDecoder.php)、[RaceTarget](../app/Domain/Keirin/Scraping/PrestartEvidence/RaceTarget.php) | JSON object/listの区別、DOM内の埋込JSON、日/場/番号と数字文字列登録番号、人工2026入力拒否 | 正確な数値token・属性単位の履歴は追加要件。現行targetは固定登録対応/5〜9人であり、変更後集合の自動受入器ではない |
| [SourceValidator::validate](../app/Domain/Keirin/Scraping/PrestartEvidence/SourceValidator.php) | 明示人工allowlist、method/parameters、実送信URIとeffective URI、200/3xx/転送不明の区別 | 実クライアントの追従停止・各試行計測は未接続 |
| [CaptureVerifier::verify/evaluate](../app/Domain/Keirin/Scraping/PrestartEvidence/CaptureVerifier.php)、[EvidenceFiles](../app/Domain/Keirin/Scraping/PrestartEvidence/EvidenceFiles.php) | 外部期待manifest hash、全file seal、再投影、公開marker、絶対path・link/mount防御、排他生成 | 既存bundleのファイル集合とcode sealは厳密一致。追加sidecarを旧captureへ書き足せない。公開完了時計の専用receiptはない |
| [PrestartEvidenceTest](../tests/Unit/Domain/Keirin/Scraping/PrestartEvidenceTest.php) | 人工JSJ017複数race/PJ0315、同時刻/TZ、改変、本人/取得元、再取得、保存時点、安全退避、独立128M | 今回は読取りだけ。既存の成功実績を今回の再実行結果へ読み替えない |

既存実装は `C1-RACECARD-PRESTART-EVIDENCE-PHASE1-SYNTHETIC-v1`。COMPLETEは保存手続きの完了であり、発走前・全属性有効・予測利用許可を意味しない。

## 3. 取得属性とRaw内フィールド

以下は現行コードで確認した経路。HTTP先は設定routeの意味であり、今回接続・現サイト確認を行っていない。JSJ017はday encpのGET、PJ0315はrace encpのPOST。encpは秘匿request bindingであり本人IDではない。

| 属性 | JSJ017の場所・現行受理型 | PJ0315の場所・現行受理型 | 解析後DTO / 保存先 |
|---|---|---|---|
| 車番 | `rInfo[i].sInfo[j].syaban`、整数/数字文字列1〜9 | `PJ0315.sensyuTypeInfo[j].syaban`、整数/数字文字列1〜9 | RaceListEntryDto/RaceDetailEntryDto.bikeNumber → race_entries.bike_number |
| 登録番号 | 同行 `senNo`、数字文字列 | 同行 `sensyuRegistNo`、数字文字列。PC0201の `C0201data.C0201racedtl.C0201sensyu[k].carNum/numPlayer` と照合 | externalPlayerId → race_entries.external_player_id、player_idは現在のsource/登録番号で解決 |
| 枠番 | 現行DTOで取得しない | 同行 `wakuban`、nullable整数1〜9。欠落/null/空文字/全角ハイフンは現行ParserでNULLへ集約 | RaceDetailEntryDto.frameNumber → race_entries.frame_number |
| 競走得点 | 現行DTOで取得しない | 同行 `heikinTokuten`、string/int/floatの非負decimal表記。欠落/null/空文字/全角ハイフンはNULL | RaceDetailEntryDto.raceScore（?string）→ race_entries.race_score（decimal 6,2） |
| 予定発走時刻 | `rInfo[i].stTime`、HH:mm文字列、null/空はNULL | `PC0201.C0201data.C0201racedtl.aftStartTime`、同様 | RaceListRaceDto/RaceDetailPageDto.startTime → races.scheduled_start_at |
| 日/場/番号 | `kaisaihi/keirinCd/rInfo[i].raceNo`、reqprm.kday/bkcdと照合 | `PC0201.C0201data.selKaisai/selKjyoCd/selRaceNo` | raceDate/trackCode/raceNumber → race/entryとの対応検査 |
| 開催種別原値 | JSJ017由来としない | PJ0315由来としない | 日程HTML `a[data-pprm-href]` の `data-pprm-dkbn` → RaceScheduleItemDto.dayKind（?string）→ race_meetings.day_kind |

PJ0315はHTML内の `jsonData['PC0201']` / `jsonData['PJ0315']` 等のJSON object。上表の先頭名はデコードした文書区分であり、原HTML全体に対するJSON Pointerではない。将来のpointerは `document_key + JSON Pointer + decoder_version + raw_sha256` で再解決する。schedule HTMLは対象リンクのDOM locator・属性名・開催対応を保持する。

JSJ017の登録人数はsInfo件数。宣言/登録/実発走の人数を同一視せず、結果から実発走人数を予測入力へ補完しない。登録番号は先頭ゼロを維持し、車番も欠番を詰めない。

## 4. 取得・保存経路の追跡表

| ファイル・メソッド | HTTP/Raw → Parser → DTO → 保存 | 旧値・属性時計・証拠対応 / 不足 |
|---|---|---|
| [RaceEntryListFetcher::fetch](../app/Domain/Keirin/Scraping/Fetchers/RaceEntryListFetcher.php)、[RaceListSyncService](../app/Domain/Keirin/Scraping/Services/RaceListSyncService.php)、[RaceEntryListParser::parse](../app/Domain/Keirin/Scraping/Parsers/RaceEntryListParser.php) | GET race_json、type=JSJ017、day encp → Raw/log → rInfo/sInfo → [RaceListEntryDto](../app/Domain/Keirin/Scraping/DTO/RaceListEntryDto.php)/[RaceListRaceDto](../app/Domain/Keirin/Scraping/DTO/RaceListRaceDto.php) | Raw1件に複数race。Parserは本人・日/場/番号を検査するが、DTOから原fieldの欠落/NULL/型を完全復元できない |
| [RaceLiveFetcher::fetchDetail](../app/Domain/Keirin/Scraping/Fetchers/RaceLiveFetcher.php)、[RaceResultSyncService](../app/Domain/Keirin/Scraping/Services/RaceResultSyncService.php)、[RaceDetailParser::parse](../app/Domain/Keirin/Scraping/Parsers/RaceDetailParser.php) | POST race_live、disp=PJ0315、race encp → Raw/log → 2出走表照合 → [RaceDetailEntryDto](../app/Domain/Keirin/Scraping/DTO/RaceDetailEntryDto.php)/[RaceDetailPageDto](../app/Domain/Keirin/Scraping/DTO/RaceDetailPageDto.php) | detail保存後にPJ0326を取得する結果同期経路。発走前収集ではなく、予測用に結果同期を起動しない |
| [RaceRepository::syncRaceDay](../app/Repositories/RaceRepository.php) | race日/場を検査。race×bikeでentry upsert、外部登録番号/汎用fetched_at、race人数/予定時刻を更新 | 枠・得点はこの経路で更新しないが、同じ汎用時計は変わる。消失entryはdelete。以前の出走表状態・属性専用時計・capture参照は保持しない |
| 同 `updateRaceDetail` | race日/場/番号、車番集合、各外部登録番号を検査。枠/得点/汎用fetched_at、予定/締切時刻を更新 | 現在値上書き。渡す時刻はServiceのnowでHTTP受信完了時刻と別。以前の値・変更日時/理由は残らない |
| [RaceScheduleParser::parse](../app/Domain/Keirin/Scraping/Parsers/RaceScheduleParser.php)、同Repository `upsertScheduleItem` | 日程HTMLのリンク属性 → [RaceScheduleItemDto.dayKind](../app/Domain/Keirin/Scraping/DTO/RaceScheduleItemDto.php) → race_meetings.day_kind | NULL時は既存値を維持する現在値経路。原field専用観測時計/規則版なし。raw1を通常/深夜に変換できない |
| [KeirinHttpClient::request](../app/Domain/Keirin/Scraping/Http/KeirinHttpClient.php)、[FetchedResponseDto](../app/Domain/Keirin/Scraping/DTO/FetchedResponseDto.php) | response body/status/header取得後にfetchedAt=now、request keyはmethod/元URL/ソートparameters、urlはeffective URIまたは元URL | request開始・正確な受信完了・時計誤差を保存しない。中間retry証拠なし。URI fallbackで実効先を検証済みとしない |
| [RawResponseStorageService::store](../app/Domain/Keirin/Scraping/Services/RawResponseStorageService.php)、[ScrapingFetchLog](../app/Models/ScrapingFetchLog.php) | 原body bytes/SHA→Storage::put→UTF-8変換→logのrequest_parameters/fetched_at/status/size/SHA/path/parser版 | [元Migration](../database/migrations/2026_07_18_000001_create_keirin_scraping_tables.php)にcapture/属性参照・immutable契約なし。putの既存path拒否/保存完了時計は保証しない。put失敗でlog前停止、log失敗で孤立Rawの可能性 |
| [Stat01InputRepository::raceInputs](../app/Domain/Keirin/Statistics/Repositories/Stat01InputRepository.php)、[Stat01Calculator::calculate/inputAsOf](../app/Domain/Keirin/Statistics/Calculators/Stat01Calculator.php) | race_scoreと汎用fetched_at → Stat01EntryInputDto → BACKFILLのfeatures/evidence。cutoffは締切優先/予定時刻fallback | 取得版へ直接結合しない。input_as_of・source_fetched_at・VALID・hashだけでは得点の当時利用可能性を証明できない |
| [Stat39Calculator::calculate](../app/Domain/Keirin/Statistics/Calculators/Stat39Calculator.php)、[Batch04PositionBiasRepository](../app/Domain/Keirin/Statistics/Repositories/Batch04PositionBiasRepository.php) | targetのSTAT01 snapshotと車番/枠/人数、target cutoff以前の正常履歴/得点期待残差 | 過去の得点は履歴entry現在値、履歴結果は後日最終結果。割当/変更履歴/初手/ラインはunavailable。残差は因果効果ではない |

共通結論: Rawとlogの対応はStoredRawResponseDto.fetchLogId等で追跡可能だが、現在entryの個別属性がどのRaw版から来たかの不変参照が不足する。今回本番schema・実Rawとの対応を再検証していない。

## 5. 発走前証拠の日時契約

### 5.1 共通時計と辞書（追加案）

元表記、offset、正規化UTC、精度、clock_source/clock_evidence_ref、誤差区間、monotonic順序を別保存する。表示はAsia/Tokyo。TZなしをサーバ設定だけで補完せず、日時不正・時計jump・因果順序逆転・誤差不明はTIMING_UNKNOWN。精度と誤差0を混同せず、許容数値は未決（D6）。人工時計の誤差0は人工試験の宣言に限る。

人工試験は `time_basis=SIMULATED_SCENARIO` と実保存の時計を分離する。既存CaptureStoreのraw_saved_atは実時計なので、現在に保存した人工2025対象が物理的に当時利用可能だったとは判定しない。正常なcutoff条件の試験は、別に明示した人工時計event/receiptを純粋な判定器へ渡して行い、保存・独立再検証では実時計とscenario双方を保持する。旧capture時刻を人工過去時刻へ書き換えず、実収集のGateはSIMULATED_SCENARIO/SYNTHETIC_CLOCK_CONFIRMEDを受理しない。

| 日時 | 取得・保存元の案 | 意味 / 欠損時 |
|---|---|---|
| request_started_at | 専用adapterがHTTP送信直前に時計eventを採取、captureへ記録 | 試行ごとの開始。既存DTO時刻で代用不可。欠損は計測不備 |
| response_received_at | body受信完了を確認した時計event | 既存fetched_atとのaliasは新しい計測版でのみ宣言。応答なしはNULL＋failed_at、接続失敗後のnowを受信にしない |
| raw_saved_at | 原bytesのwrite/flush/fsync等を確認後、captureの保存receiptへ | 保守的保存完了境界。mtime/created_atから作らず、不明ならNULL |
| validation_completed_at | 対象・属性・seal検証完了後の新validation revision | 未検証/途中失敗は完了扱いしない。後日再検証は別日時/版 |
| evidence_published_at | 完成markerの公開・再読取り検証後、別の不変publication receiptで完了境界を記録 | manifest作成開始時刻ではない。存在するディレクトリ/COMPLETEだけから時刻を推定しない |
| prediction_as_of | 依頼で固定したcutoff、対象と版選択policyへseal | now/発走時刻への暗黙fallback禁止。未指定なら段階2を判定不能 |
| observed_scheduled_start_at | 同Rawの対象日＋stTime/aftStartTime＋確認済みTZ解釈版 | 現在DB予定/実発走ではない。欠落・未知TZはTIMING_UNKNOWN |
| official_changed_at | 公式変更日時の原文pointer・出典・TZが確認できた場合だけ | 不明はNULL。次capture時刻や観測区間の中点を公式変更時刻にしない |
| official_first_published_at | 別の公式公開時点証拠がある場合だけ | 受信時刻から逆算不可、通常UNKNOWNのまま |

request≤受信≤保存≤検証≤公開の順序を同じ試行/版のeventで確認する。後日の再検証・訂正で元順序を再構成しない。別時計なら同期/誤差根拠が必要。段階1は既存TIMING-v2を残し、下記の誤差・cutoff判定は追加版の別fieldにする。

### 5.2 二段階の判定案

**段階1: 取得候補**。既存の原Raw完全性、対象/本人、source VERIFIED、HTTP200、redirect=false、比較可能時刻を要求し、`response_received_at < observed_scheduled_start_at`（strict）を確認する。等値はAT_SCHEDULED_START、後はAFTER_SCHEDULED_STARTで候補外。実発走前・公式初回公開・予測投入前を証明したとは言わない。

追加の保守判定では、受信の誤差区間上端が予定時刻の区間下端よりstrictに前の場合だけ時刻を確認する。誤差境界が重なる/不明なら追加判定はTIMING_UNKNOWN。旧TIMING-v2の記録を上書き・昇格させない。

**段階2: cutoff時点の技術的利用可能性**。必要な各属性・本人・source・変更整合が成立し、取得/保存/検証/公開とその選択に必要な証拠がcutoffまでに利用可能であることを確認する。案は各完了時計の上端 `<= prediction_as_of` の下端、かつcutoffが観測予定時刻より前であること。境界重複はUNKNOWN。cutoff等値の扱いを含む最終policyはD6/D8の承認対象。

publication receiptは公開marker/manifest hashを参照する別記録とし、元captureへ追記しない。後から過去時刻を書くだけでは証明にならない。公開完了・receiptの記録順序と時計根拠を検証し、証拠がcutoff後にしか成立しないなら、過去の段階2を合格にしない。使用入力manifestはpublication receipt・validation/identity revisionのhashを固定し、保存済み予測はその入力manifestを参照する。実際に使用した記録がなければ「使用可能候補」と「実際に使用した」を分離する。

| 状況 | 段階1 | 段階2 |
|---|---|---|
| 受信は発走前、保存がcutoff後 | 取得候補は保持可 | CUTOFF_RAW_SAVE_LATE、不可 |
| 受信・保存は前、検証/公開がcutoff後 | 取得候補は保持可 | CUTOFF_VALIDATION_LATE / CUTOFF_PUBLICATION_LATE、不可 |
| 公開等の時計/receiptが不明 | 取得候補の判定とは独立 | TIMING_UNKNOWN、不可 |
| 全証拠・属性・cutoff条件が成立 | 取得候補 | TECHNICALLY_AVAILABLE_AT_CUTOFFという用途別追加判定案。ただし予測利用は未承認 |

いずれも `prediction_use=NOT_AUTHORIZED`、理由 `PREDICTION_USE_NOT_AUTHORIZED` を維持する。後日得点で過去データの発走前証明を復元しない。

## 6. 属性別の証拠データ辞書

1属性1観測を以下で記録する案。JSON object/list、list順、欠落とNULL、空文字、数値型/文字列を原証拠で保持する。原Rawは変換前bytes、投影は派生資料。

| field案 | 型・意味 |
|---|---|
| observation_id / attribute_name | 一意観測IDとbike_number/frame_number/race_score等の許可名 |
| presence / is_null / raw_type / raw_value | MISSING/PRESENT、欠落ならis_null=NULL/type=missing。存在NULLならis_null=true。値・型を強制変換せず保持 |
| raw_lexeme / raw_pointer | 必要な数値token原表記、document_key/JSON pointerまたはDOM locator。tokenと値を原Rawへ照合 |
| normalized_value / normalization_version | 属性専用の決定的変換、未確認/不正はNULL＋理由。原値は不変 |
| value_status / applicability_status | KNOWN_VALUE / NULL / EMPTY / MISSING / INVALID_TYPE / INVALID_FORMAT / UNKNOWN等。NOT_APPLICABLEは別の公式根拠参照が必要 |
| source / capture_id / raw_sha256 | JSJ017/PJ0315等、既存capture ID、Raw seal、log参照/未接続理由 |
| race_identity / entrant_identity | 日・場・番号・source内race ID/locator、登録番号/車番/枠。内部race/entry/player IDは解決revision経由でnullable |
| observed_at / available_at_evidence | 属性存在を確認したcapture受信時計の参照と、保存/検証/公開receipt参照。汎用entry時計は不可 |
| source_verification / identity_verification / timing_verification | 別軸の状態・理由・検証証拠。自己申告originだけではVERIFIEDにしない |
| validation_version / evidence_hash | 検証code/設定seal、当該revisionと依存証拠を含むhash。hashだけで意味・時点を保証しない |

属性固有の正規化案:

- 車番: JSON整数/数字文字列1〜9→整数、bool/float/objectを拒否。原`"01"`と1を原証拠では区別する。非連続を許し、登録人数を番号上限へ使わない。
- 枠番: 同様に数値1〜9を表記上の有効値候補とするが、7車/9車の公式対応の検証は別。欠落/NULL/空/全角ハイフンを区別し、いずれも適用外と推定しない。制度と矛盾ならRULE_CONFLICT、当該枠用途だけブロック。
- 登録番号: 数字文字列を先頭ゼロ込みで保持し、整数化/桁数の推測補完をしない。本人識別に必要で不正型は未解決。場コードも文字列表記と公式コード対応を保持する。
- 得点: 非負decimal表記を原値のまま保持し、正規化はexact decimal文字列。`90.10`と`90.1`は数値同一でも原表記/Raw型は別。float経由の丸め・DBの小数2桁丸めを証拠の正本にしない。JSON数値は元tokenを構造上のpointerで同定してdecimal化する必要があり、現行decoderで正確なtokenが得られなければNORMALIZATION_UNVERIFIEDとして利用しない。新投影/decoder追加の人工検証が前提。0は観測値として保持するが既存STAT01では非正値不正のため有効得点へ昇格させない。
- 予定時刻: 当該raceの有効日付とHH:mm、TZ根拠から日時化。別raceの日・時刻、現在DB、受信時刻から補完しない。元精度が分単位であることも残す。
- 開催種別原値: DOM属性の文字列・presenceを保持するだけ。`day_kind=1`を通常/ミッドナイトへ対応付けない。公式意味・適用版は対文書の台帳で確認する。

JSJ017に枠/得点が取得されていないことを「値0」や「非適用」にしない。別captureのPJ0315を結合する場合も全属性それぞれの本人・版・cutoff条件を満たす必要があり、遅い取得を早いcaptureへ移植しない。

## 7. 車番・枠番変更履歴の契約

### 7.1 変更種別と根拠

| 観測差分 | 記録案 | 禁止する推定 |
|---|---|---|
| 同一登録番号が別車番へ | BIKE_CHANGED、旧/新bike・枠・capture参照 | 元bikeのentry IDだけで別人扱い |
| 同じbikeが別登録番号へ | RIDER_REPLACED、旧/新登録番号を別本人として保存 | 同じrace_entry_idだから同一人物 |
| 同一人物の枠変化 | FRAME_CHANGED、NULL等の原状態も保持 | 車番から枠変更を補完 |
| 登録集合の人数変化 | CARD_COUNT_CHANGED、旧/新登録人数・集合 | 実発走人数や宣言定員との同一視 |
| 明示された欠場/取消 | WITHDRAWAL_CONFIRMED、明示field/告知の出典 | 一覧から消えたことだけで欠場確定 |
| 旧集合から消失 | ABSENT_FROM_OBSERVED_CARD、理由UNKNOWN | DNS/取消/中止の結果labelへの変換 |
| 補充の明示/新規登録番号 | SUPPLEMENT_CONFIRMED、根拠なしならADDED_TO_OBSERVED_CARD | 増加した行は常に公式補充 |
| 出走表再発表 | REPUBLISHED、公式版/告知があれば参照。不明は新captureのみ | Raw hashが違うだけで再発表確定 |
| 同じ内容を再取得 | OBSERVED_AGAIN、別capture | 取得履歴を省略・日時上書き |
| 取得元間の相違 | SOURCE_CONFLICT、両source/capture/時刻 | 多数決・DB最新値で片方を削除 |
| 理由が未掲載 | change_reason=UNKNOWN、確認できた差分だけ | 原因・正確な変更時刻の創作 |

部分/壊れた出走表では「消えた」を確定できない。race全体のschema/観測完全性が未確認ならCHANGE_HISTORY_INCOMPLETEとし、原Rawと不明理由を残す。中止/延期のページは独立した利用不能状態で、空の通常出走表へ変換しない。

### 7.2 content、occurrence、変更時刻

不変content（同一投影版の意味内容）と、時間上の出現occurrenceと、毎回のcaptureを分離する。10:00 A→10:30 B→11:00 Aはcontent2種、occurrence3件。A2はA1を再current化せず、A1の終了境界を消さない。連続してAを再取得するだけならcapture/観測関連は増えるがoccurrenceは増やさない。

append-onlyのopen/close eventを別行で保存し、終了は次の確認観測を参照する。currentはevent列から導出するviewで、原行のeffective_toをNULLへ戻す更新をしない。この期間は**観測系列の区切り**であり、実世界でその間ずっと変わらなかった証明ではない。

公式変更時刻不明なら、直前/直後のsourceと受信時計を持つ観測差分区間として残す。時計誤差・受信遅延も保持し、10:00〜10:30は異なる状態を観測した区間に過ぎず公式適用時刻を特定しない。推定中点も作らない。異なるsource間の差は同一sourceの時間的変更と区別する。

同時刻の異なる状態、取得時計と公開順序の逆転、後から届いた古い観測は自動順序付けしない。source別系列へ保持し、新しいreconciliation revisionで扱う。観測時刻順だけで過去cutoffへ遅着証拠を混入させない。

## 8. 本人識別・レース識別

raceはsource内の識別情報と日/場/番号を照合したstable race key。source内IDが原Rawにない場合はそのpresenceを記録し、request digestだけを一意なrace証明としない。順延で日付が変われば別観測identityで、同開催の別日に自動併合せず、公式対応証拠を持つ別resolution revisionを要求する。

本人は登録番号（source/登録体系を含む）を基本とし、各capture内でrace＋bike、race＋登録番号の一意性を検査。PJ0315の2表、JSJ017のreqprm/日/場/raceNoを照合する。外部登録番号とRawを独立保持し、現在の内部IDを当時の本人証明にしない。

固定C1へ接続する場合は受入済みtarget/mapping manifestとsealを使い、race/entry/bike/日/開催との全対応を確認する。人工変更履歴は同一raceの新しい登録集合として表現し、旧RaceTargetの固定registrationsを上書きして「一致」とはしない。現在の期待集合との不一致は保持し、正当な変更の確認は別revisionで行う。対象集合の変更承認なしに固定C1対象を増減しない。

同じ内容の登録番号でも資料の型や桁が違えば原値を残し、先頭ゼロを落とした自動joinを禁止する。未知本人はIDENTITY_UNVERIFIED、証拠の不一致は既存IDENTITY_MISMATCHを再利用する。

## 9. append-only保存案

### 9.1 既存Raw保存を再利用する境界

Phase 1では既存CaptureStoreの人工captureとRawを正本とし、拡張は別の派生bundleからcapture ID/期待manifest SHAを参照する。旧capture内へ新field/fileを足さない。汎用Storage::putをそのまま第二の証拠保存器として新設しない。実HTTP接続時は同じcapture保存責務へ専用adapterを設計するが、SYNTHETIC宣言を外して本物と扱う修正は別版・別承認。

派生bundle案はattribute-observations.jsonl、card-contents.jsonl、occurrence-events.jsonl、changes.jsonl、validation-revisions.jsonl、manifest.json、COMPLETE/FAILED。取得原Rawは参照だけで二重複製しない。publication receipt、入力選択manifestは別の不変記録とし、自分自身のhash/公開後時刻を元manifestへ書き戻さない。

### 9.2 DB採用時の論理テーブル・キー案（Migrationは未作成）

| 論理表候補 | PK/UNIQUE候補と関係 | append-only要件 |
|---|---|---|
| evidence_capture_references | capture_id PK、元manifest hash/Raw seal/log参照 | 既存ファイルcaptureへの索引。body hashをcapture UNIQUEにしない |
| racecard_observations | observation_id PK、UNIQUE(capture_id, race_key)、観測集合とsource pointer | 同じraceの再取得は別行。登録集合と完全性を保持 |
| entrant_attribute_observations | attribute_observation_id PK、UNIQUE(observation_id, entrant_locator, attribute_name) | 有効観測内race＋bike/登録番号一意を検査。重複schemaはFAILEDへ保持 |
| racecard_contents | content_id PK、UNIQUE(race_key, projection_version, semantic_state_hash) | normalized値と欠損状態の内容だけ。原型/原値/毎回の取得は観測側に保持 |
| racecard_occurrence_events | event_id PK、UNIQUE(series_id, sequence)、前event hash・open/close・content/capture参照 | 同hash再出現をUNIQUE制約で禁止しない。直列append/CASでopenは最大1、系列を後から巻き戻さない |
| racecard_change_revisions | change_id PK、UNIQUE(series_id, revision_no)、前後観測/旧新値・理由 | 変更区間/公式適用時刻を分離。訂正はsupersedes参照付き新revision |
| evidence_validation_revisions | validation_id PK、UNIQUE(subject_id, validator_version, revision_no) | identity/source/timing/用途別結果、実行時計/code/hash。旧判定を上書きしない |
| evidence_publication_receipts / prediction_input_selections | receipt/selection ID PK、参照manifestと各hash、cutoff/policy版 | 使用したoccurrence/属性/制度版を固定。後日の最新版へ差し替えない |

新しい証拠表間のFKはRESTRICTを候補とし、CASCADEで監査を消さない。既存race_entriesの削除/選手交替に監査保存を依存させず、内部IDは別のnullable解決記録にする。既存業務schemaに新FKを追加して同期を妨げない方針を別途レビューする。状態遷移/一意性・権限制約の実DB実装はPhase 2承認後であり、今回保証済みではない。

### 9.3 cutoff再構成とJSON契約

保存capture/manifest → 独立検証 → cutoffまでに利用可能なvalidation/identity/publication revisionだけ選択 → 承認policyでsource別観測系列を解決 → 内容/occurrence/各属性/制度版を入力manifestへ固定、の順にする。

「最新」は現在DBや後日訂正版でなく、そのcutoffで利用可能な版集合内での意味。source優先/鮮度/欠測区間はD4/D8で承認するまで保留し、単純MAX(fetched_at)やID順で矛盾を隠さない。観測間の変更がない完全履歴を、離散captureだけから保証しない。

全JSONに契約版・SYNTHETIC/実収集の区別・対象/出典seal・原記録参照を持たせ、list順・原型/欠落状態を維持する。新規semantic hashの投影順と数値表現だけ専用版で定め、共通canonical/hash仕様は変えない。旧版も新検証版から読めるように契約dispatchを行い、旧束の再sealは禁止する。

## 10. 情報源・Raw・SHAの検証

順序は原Raw/capture存在→source→bytes/SHA→race→本人→属性→時刻→cutoff→変更→制度→用途とする。sourceが不正でも、失敗した応答Rawを保存して監査することと有効入力として採用することは分ける。

独立Verifierは、外部から渡す信頼済みmanifest hash、全ファイルのbytes/SHA、capture ID/Raw path、原bytesと必要ならUTF-8派生hash/変換版、pointer再投影、取得/検証code seal、COMPLETE/FAILEDの整合を検証する。bundle自身のhash自己申告だけを信頼しない。Raw1byte/サイズ/対象/manifest改変、余分なmarkerは公開前に拒否する。

許可scheme/host/port/path、method、parameter集合、送信URIとeffective URIをSourceValidatorの契約で照合する。自動redirect禁止、3xxは失敗、200でも転送有無/URI不明はSOURCE_UNVERIFIED。不正な取得先から正しいrace情報が返っても救済しない。将来の実経路は送信前検査と転送先接続0をHTTP mockで検証する。今回現行HTTPクライアントを変更しない。

共有側はsource識別子・必要な対象ID・digest/状態とprivate参照のみ。完全URI、encp、認証/Cookie、Raw全文はprivateとし、Git/共有manifest/通常ログへ出さない。secretを消すために原Rawを加工しない。

排他生成、所有者/権限、絶対path、symlink/mount境界、既存path拒否を継承する。失敗資料はその場に保持し、永久削除・修復上書き・別FSへのコピー後削除をしない。人工一時領域だけ作成時保持オブジェクトのpathで既存helperへ渡し、`/home/shinya/Desktop/Trash/`内の一意退避を使う。今回は作成・退避処理そのものを行わない。

## 11. 適格性判定と状態遷移

以下は別軸の状態/理由候補で、既存Enumを変更しない。属性や用途の一つが未確認でも、別の観測証拠まで単一INVALIDに潰さない。

| 順 | 検査 | 結果・既存契約との対応 |
|---|---|---|
| 1 | 原Raw/capture存在 | 不足はRAW_UNVERIFIED。再取得で補完したふりをしない |
| 2 | 出典/取得元 | SOURCE_UNVERIFIED、既存SOURCE_NOT_ALLOWED/URI_MISMATCH等の理由を再利用 |
| 3 | bytes/SHA/manifest | 不一致は既存VALIDATION_FAILED、RAW_UNVERIFIED。証拠の有効公開なし |
| 4 | race識別 | 不足IDENTITY_UNVERIFIED、不一致IDENTITY_MISMATCH。該当raceをブロック |
| 5 | 選手対応 | 同上。同bike複数人等は同raceの対応完全性をブロック |
| 6 | 原値presence/type | ATTRIBUTE_MISSING、ATTRIBUTE_INVALIDとNULL/EMPTY/UNKNOWN等の詳細を併記 |
| 7 | 正規化 | 属性版で検証。型/表記不正、数値token未確認を0へ補正しない |
| 8 | 取得/保存/検証/公開時計 | TIMING_UNKNOWN、既存POSTSTART_CAPTURE（等値を含む）/RAW_SAVED_*分類をそのまま保持 |
| 9 | cutoff | 段階1合格はPRESTART_EVIDENCE_CANDIDATE、段階2は別fieldでLATE/UNKNOWN/技術的利用可能を区別 |
| 10 | 変更系列 | 欠測/部分/未解決交代はCHANGE_HISTORY_INCOMPLETE、source矛盾はSOURCE_CONFLICT |
| 11 | 公式制度 | OFFICIAL_DEFINITION_UNKNOWN / RULE_VERSION_UNKNOWN / RULE_CONFLICT。枠非適用には検証済み根拠 |
| 12 | 用途判定 | 観測車番、枠対応、割当補正、能力補正で必要条件を別管理。常にPREDICTION_USE_NOT_AUTHORIZEDを付す |

POSTSTART_ONLYは前工程の「確認した記録は同時/後だけ」という集約分類。単captureのPOSTSTART_CAPTUREと理由AT/AFTERを潰さず、実際の発走後と断定する名前として使わない。

遷移はcapture保存/独立検証→段階1候補→cutoff判定→用途別技術状態。UNKNOWN/不一致からの解決は新revisionであり、元の時点へ遡及昇格しない。COMPLETEでもPREDICTION_USE_NOT_AUTHORIZED、FAILEDなら失敗証拠として保持する。

公式割当不明でも、source/本人/時計が成立した観測車番は原値証拠として有効。割当方式を使う補正、公式frame mappingの確認はBLOCKED。同様に枠NULLは車番証拠を無効化しないが枠用途を適格にしない。受入済み最終入力集合の完全性が必要な場合は、必要属性の欠落を理由付きで保留しcohortを黙って縮めない。

## 12. STAT-01・STAT-39との接続条件

将来の接続は独立のreader/adapter案で、使用属性観測ID、capture/manifest、validation/identity/occurrence/制度revision、cutoffをsealする。現在race_entriesへ書き戻して旧Stat01InputRepositoryを呼ぶだけの案は、時点証拠を失うため採用しない。

STAT01には有効正値の得点と受入済み出走集合を同一cutoffで渡せることが必要。新証拠で旧BACKFILL resultの時計やhashを更新しない。STAT39は当該cutoffでの番号/枠/登録人数・制度版と、履歴の結果/能力の可用性を別監査する。対象の結果・実発走人数・実初手から補完しない。

得点証拠だけが揃っても履歴全体のhistorical as-of成立を宣言しない。ライン/初手・公式意味・制度適用・common support不足は依然BLOCKED。STAT36は位置取り能力、STAT39は番号構造、STAT40はライン事前戦力として責務を分離する。S回数や脚質でライン/初手を推定しない。

予測モデル連携・再学習・比較・新Gateは本書の対象外。P2/P3の同分母導出は新情報でなく、旧不採用C1_PLUS_FIELD_BIKEの再試行を今回許可しない。

## 13. 既存PrestartEvidenceとの互換性

| 既存契約 | 保存方針 / 追加案 |
|---|---|
| SYNTHETIC-v1のfile集合・manifest・COMPLETE/FAILED | 元bundleを不変参照。派生attribute/履歴bundleは別namespace・別契約版 |
| TIMING-v2のfetched_at/raw_saved_at独立分類 | 値・理由・意味を保持。時計誤差/公開/cutoffは別の利用可能性判定版 |
| RawObservationDecoder/RaceTargetの型・登録番号・年境界 | 再利用。数値token/変更集合の必要差分は専用投影/履歴契約で人工検証し、旧targetを書き換えない |
| SourceValidator/Verifier/EvidenceFiles | 出典・Raw/seal・path・独立検証を再利用し、重複Raw保存基盤を作らない |
| EvidenceFiles::codeSeal() | 同階層PHP globで全一致を要求するため、新機能は例として `PrestartEvidence/Attributes/` 等の追加namespaceと独立code sealへ分離する案。旧ファイル追加/変更で旧検証が通ると偽らない |
| 旧code sealと異なる検証runtime | 旧記録を再sealしない。検証対象版を明示し、保存時runtimeの参照または互換性検証済みの別検証revisionで扱う。無条件で現在codeを信頼しない |

ここでの候補パスは製造承認後に確定する。現行クラスの挙動・共有canonical/hash・Parser/Repository/Fetcher・旧モデルのcode sealを今回変更しない。実HTTPでSYNTHETIC時計を使うことは許されない。

## 14. 人工テストケース

**以下は次工程の試験仕様であり、今回はテスト未作成・未実行。** 基本人工日2025-12-31、予定10:00+09:00、cutoff09:55+09:00。正常例の受信09:50、保存09:50:01、検証09:50:02、公開09:50:03は人工時計であり実取得日時ではない。人工5〜9人の登録集合を用い、実Raw/DB/HTTP/旧モデル/実2026 Fixtureを読まない。

全ケースで旧capture/Raw/モデル/既存資料のbytes/hash不変、新規stageだけへ書込み、失敗証拠保持、永久削除なしを共通assertionにする。正常でもprediction_use=NOT_AUTHORIZED。

| ID | 入力・変化 | 期待状態・拒否理由 / 保持事項 |
|---|---|---|
| T01 | 正常人工JSJ017複数race・PJ0315、車番/枠/得点。cutoff条件は明示した人工scenario時計 | 原値/pointer/本人一致、段階1候補、scenario上の段階2技術条件成立。実保存時計の判定とは別で実利用承認なし。JSJ017にない得点/枠は別未取得状態 |
| T02 | 受信が予定時刻と同時 / 後 | POSTSTART_CAPTURE、AT / AFTER。候補外、記録保持 |
| T03 | 発走前受信、保存09:56 | 段階1候補保持、段階2CUTOFF_RAW_SAVE_LATE |
| T04 | 保存まで前、検証09:56 / 公開09:56 | 段階2CUTOFF_VALIDATION_LATE / CUTOFF_PUBLICATION_LATE |
| T05 | 予定時刻欠落/null/不正、別raceだけに予定あり | TIMING_UNKNOWN、他race/DB時刻へfallbackなし |
| T06 | TZなし/時計誤差不明/境界重複/順序逆転 | TIMING_UNKNOWN。UTC同一瞬間の別offsetは同じ比較、原表記は保持 |
| T07 | Raw1byte/サイズ/SHA、manifest/marker改変 | RAW_UNVERIFIED / VALIDATION_FAILED、適格公開なし、元bundle上書きなし |
| T08 | 登録番号不一致/先頭ゼロ脱落、別日/場/番号 | IDENTITY_MISMATCH、原値保持。内部ID一致だけで救済なし |
| T09 | 同bikeに複数人 / 同登録番号に複数bike | race対応不正を拒否、RawはFAILED保持、正常cardを捏造しない |
| T10 | Xがbike1→2、またはbike1がX→Y | BIKE_CHANGEDとRIDER_REPLACEDを区別、旧新本人・車番対応を保存 |
| T11 | 明示欠場＋明示補充 / 単なる消失追加 | confirmed変更と観測差分を区別、出走行物理削除なし |
| T12 | 枠変更/登録人数変更/明示再発表 | 旧新値・集合・出典を保持、実発走人数へ変換なし |
| T13 | この例は予定12:00。A10:00→B10:30→A11:00、各公開後のcutoff10:15/10:45/11:15 | content2種/occurrence3件、A1の終了境界保持、各cutoffの選択が該当occurrenceを参照 |
| T14 | 公式変更日時なしの2capture | official_changed_at=NULL、観測差分区間のみ。中点/次取得日時を公式時刻にしない |
| T15 | 枠field欠落/null/空/全角ハイフン/不正型 | 各原状態を区別、NOT_APPLICABLEへ既定変換なし、車番証拠は維持 |
| T16 | 人工の検証済み非適用規則＋対象一致 | applicability=NOT_APPLICABLE、rule証拠参照必須。原NULLはそのまま |
| T17 | 制度適用期間不明/未登録 | RULE_VERSION_UNKNOWN、割当補正のみBLOCKED、観測番号は保持 |
| T18 | 複数制度が同時一致/例外範囲不明 | RULE_CONFLICT、最新/多数決/近い日付で自動選択なし |
| T19 | 開催原値1、公式session未確認 | OFFICIAL_DEFINITION_UNKNOWN、UNKNOWN維持、時刻分類なし |
| T20 | 同Raw/同属性を時刻違いで再取得 | 別capture・観測関連、連続同内容occurrence再利用。既存日時上書きなし |
| T21 | JSJ017/PJ0315で同じraceの本人/予定が矛盾 | SOURCE_CONFLICT、両原本保持、優先未承認なら最終選択保留 |
| T22 | 人工2026 targetの指定 | 実資料I/O前に拒否、2026実ファイル参照0、拒否後の新規capture生成0 |
| T23 | 旧path/captureの再使用、symlink/mount、相対/空/`.` | 排他/path拒否、上書き/移動/削除0。他tmpへのfallbackなし |
| T24 | 数値文字列/int/decimal token、bool/object、0得点 | tokenと型保持、exact decimal比較。正規化不能/非正値の有効得点化を拒否 |
| T25 | 部分出走表/遅着/同時刻異状態 | CHANGE_HISTORY_INCOMPLETE/競合保留。消失を欠場にせずcutoff後証拠を遡及利用しない |
| T26 | 200だがURI不明、不許可先、3xx/redirect | SOURCE_UNVERIFIED/既存拒否理由、正しい内容でも候補にしない |
| T27 | 公開前source/code改変、保存/検証/公開途中失敗 | manifest/公開を拒否、FAILED/partial保持、自動修復/再試行なし |
| T28 | 保存資料だけの独立再検証・cutoff再構成 | DB/ネットワーク遮断で分類/参照/hash一致、元manifest不変。receipt不明は昇格なし |
| T29 | 継続観測・A→B→Aの反復と2保存者競合 | 1系列open最大1、close重複拒否。content hash一致で過去期間を再current化しない |
| T30 | 複数capture/raceを逐次保存・検証 | 独立PHPへ実128M、有限Raw上限/JSONL stream、exit0を要求。親の累積peakに依存しない |

T16/T18の制度資料も人工と明示し、公式規則の発見/承認とは扱わない。既存TIMING-v2、原型・list順、脚質/本人/安全helperの関連回帰は製造時に維持する。大容量実資料や全体テストを今回実行しない。

## 15. 段階別の製造計画

全Phaseは未開始・別承認。次表のファイルは候補で、今作成しない。個々のPhaseを終えても次へ自動移行しない。

| Phase | 前提 / 変更ファイル候補 | テスト・完了条件 | STOP条件 |
|---|---|---|---|
| 1 人工属性証拠 | 本契約の属性/時計/pointer/追加版を承認。既存PrestartEvidenceを参照する `PrestartEvidence/Attributes/` 配下の投影/Verifier/DTOと専用Unit候補 | T01〜09/15/20/22〜24/26〜28/30、元TIMING-v2回帰。得点/枠の保存・独立再検証が人工だけで成立 | DB/HTTP依存、旧bundle書換え、数値/時刻の根拠不足、保護領域I/O |
| 2 変更履歴保存 | Phase1受入、series/content/event/identity revision/並行性契約を承認。履歴部品と人工テスト候補。DB方式/Migrationは別許可 | T10〜14/20〜21/25/28〜30、A→B→A、本人交替・cutoff再構成、audit FK維持。ファイル版ならDB不要 | 欠測を完全履歴とする、履歴削除/再current、現在entryへの依存、無許可本番書込み |
| 3 公式制度マスタ | 対文書の取得計画を別承認、公式資料と時点・適用対象を受入後。OfficialRuleLedger/Matcher等の候補と人工制度テスト | T16〜19、境界/改正/例外/競合・不明を照合、全ruleから原文と審査へ追跡可能 | 未確認原文の正式マスタ化、期間推定、source class混同、自動競合解決 |
| 4 取得経路接続 | D1〜D8全ての未決解消、取得先/規約/対象年・列挙/量/保存/障害/時計/利用policy承認。専用HTTP/capture adapter・設定・mock候補 | 自動redirect0/3xx先接続0、1試行1capture、Raw/log/receipt、属性・本人・時点。限定実接続は明示許可範囲だけ | 2026、default有効、result同期依存、上限超、source不明、時計/保存失敗。自動範囲拡張なし |
| 5 STAT39連携可否 | 前Phaseの必要証拠受入、対象cohort/属性の段階2適格性・制度/履歴不足をレビュー。専用読取adapter/入力契約候補 | 適格な版だけを固定、旧STAT01/39/C1不変、欠損/競合/時系列の人工試験 | UNKNOWN救済、過去as-of遡及、旧不採用同義再試行、無許可学習/採用 |

Phase5は新版製造を当然とせず製造可否の再判断。実装承認と予測比較/正式採用承認は別である。

## 16. 未決事項とユーザー承認事項

### 16.1 原本への追跡（全てOPEN維持）

| 原本 | 必要項目と完了条件の照合 | 本契約で用意する経路 / 未解消 |
|---|---|---|
| SCR-STAT-01-01 発走前競走得点の監査情報保持 | 得点取得日時、発走/締切前後、URL/Raw/Fetch Log、再取得上書き履歴、予測入力固定。発走前監査・当時得点再利用・発走後混入検出が完了条件 | Sections 3〜6/9〜12、Phase1/4/5。人工拡張も実収集も未実装 |
| SCR-STAT-39-01 車番・枠番履歴の完全性確認 | レース時点の車番/枠/人数/着順/結果状態/取得日時/情報源。車立て別効果再計算、枠適用/非適用分離、欠損と0/非適用の区別 | Sections 3/6/8/12と対文書。着順/結果状態の取得・再集計は今回せず、将来の別許可接続が必要 |
| SCR-STAT-39-02 車番・車立て変更履歴の保存 | 旧新車番/車立て、変更日時/理由、取消/補充、URL/Raw。当時車番再現、発走後変更を混入させない、変更後の関連STAT再計算 | Sections 7〜9/12/14、Phase2/5。再計算は別の未承認処理 |
| SCR-STAT-39-03 車番・枠番割当制度マスタの調査・整備 | 方式/枠制度/車立て、開始終了、公式規則/URL/Raw。無作為/規則/不明の区別、改正前後の効果分離 | 対文書とSections 11/15。公式資料未確認、Phase3未開始 |
| SCR-STAT-39-04 ライン・初手情報との統合基盤 | 発走前構成/位置/人数/単騎/初手/スタート取得/番号。直接効果と初手媒介を分け、位置取り能力との二重評価を避ける | Section12、Phase5の前提。ライン/初手は未接続、今回計算・推定しない |

設計が対応していても完了条件を実行証拠で満たしたとはしない。原本や旧成果物の状態を更新しない。

### 16.2 実収集開始前のD1〜D8

| 未決ID | 承認・確定が必要な内容 |
|---|---|
| D1 | 年/日/場/レースの明示allowlist・対象数。2026は現契約で不可 |
| D2 | 取得先行時間・再取得頻度/上限・timeout/retry。具体数値未決 |
| D3 | 利用条件/robots、送信先/実効URI照合policy、method/parameter、転送禁止、429停止 |
| D4 | JSJ017/PJ0315の用途・版選択、予定/本人/属性の二源矛盾処理 |
| D5 | 延期/中止/欠場/補充/交替/再発表の再取得・確認手順 |
| D6 | 時計同期証拠、誤差/精度・順序・境界比較、公開receiptの計測と耐久性 |
| D7 | 既存合意root内の詳細配置/private権限/容量/保持・失敗回復。新rootの独断決定なし |
| D8 | 用途別最終適格性・集合完全性/鮮度・cutoff版選択と使用証拠、予測利用の別承認 |

追加の設計レビュー事項はexact decimal token取得、変更観測seriesの並行append、制度の適用粒度/例外、旧code sealとの版dispatch。これらは任意の正常値で埋めない。過去年のas-of不足は新契約だけでは解消できない。

## 17. 受入条件とSTOP条件

文書受入は、原本5項目とコード追跡表の対応、二段階時点、属性辞書、本人交替と番号変更、append-only/A→B→A、公式制度台帳、人工T01〜T30、Phase1〜5とD1〜D8の整合を確認すること。製造の成功条件ではない。

確認済み既存機能と追加案を区別し、未確認の公式URL/時刻/制度を作らない。旧STAT01/39/C1・Gate・不採用・holdoutを保持する。Git変更が指定2文書以外ならSTOP。相対リンク・根拠ファイル名/原本節・末尾空白/改行を検査し、未追跡の文書も別途検査する。

未解決の根拠・権限・期間・本人・時計・制度競合は該当用途を保留し、範囲拡張・現在値fallback・自動再試行/修復しない。今回の次の許可作業は文書設計レビューのみ。

最終工程状態: **STAT39_PRESTART_ASSIGNMENT_EVIDENCE_CONTRACT_READY_FOR_REVIEW**。実装・DB/HTTP・Raw新規監査・テスト・収集・学習・予測・評価・2026参照・既存資料変更/移動は未実施。commit/push/PR作成/mergeを行わず停止する。
