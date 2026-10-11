# C1-RACECARD-PRESTART-EVIDENCE-CONTRACT-01

## 1. 状態・根拠・境界

- 設計日: 2026-10-11。
- 開始main/ローカルorigin/main: `637ca013774ed5b9186aa4f8aedd152b712031e1`。cleanを確認し、同コミットの `Merge pull request #100` とユーザーのレビュー完了・受入を確認した。fetchは実施していない。
- 作業branch: `design/c1-racecard-prestart-evidence-contract-01`。
- 提案契約識別子: `C1-RACECARD-PRESTART-EVIDENCE-DRAFT-v1`。
- 状態: **DESIGN_REVIEW_REQUIRED**。以下の保存形式・状態・検証規則は設計案であり、承認済み仕様、設計freeze、実装済み機能ではない。
- 今回の変更は本書とMASTER PLANだけ。DB SELECTを含む接続、HTTP、Raw再解析、実データ再監査、収集、学習、予測、評価、テスト、Migrationは実施しない。

現行実装の根拠は開始SHAのソースコード・Migration・テストを読んだ結果。DBの実schemaや外部サイトの現在の公開仕様を確認した結果ではない。対象サイトの利用条件、公式初回公開時刻、時計精度は未確認。

旧工程の確定記録は次のとおり。今回の実測へ読み替えず、元成果物・文書を変更しない。

| 受入済み工程 | 当時の確認結果 | 残る制約 |
| --- | --- | --- |
| PR99 / C1-TACTICAL-INPUT-READINESS-01 | 固定99,669レース/706,051出走の本人対応成立。登録脚質は全出走に保存値、ラインは全NULL。PARTIAL_READINESS | 属性固有の歴史的観測時刻未確認。脚質はライン役割ではない |
| PR100 / C1-RACECARD-ASOF-PREFLIGHT-01 | JSJ017対応21,203ログ、PJ0315対応125,130ログは対象開催後。発走前候補0、NO_PRESTART_FETCH_EVIDENCE | 現在のencp/request/batch targetに限定したmetadata確認。Phase B・Raw本文/実在/body SHA確認未実施。他の歴史資料の証拠不存在は断定しない |

旧preflight manifest SHA-256は `d0cf1d4d424094a462d5a55ef705b181a101e7085c35441aa8470cd67eec2467`、旧readiness candidate manifest SHA-256は `f3b00f73843427da4b57d1827c877992423da77d2104cbe00c187fd552a82ab3`。本工程では再読取り・再ハッシュしていない。詳細は [前提監査](c1-racecard-asof-preflight-01.md) と [入力準備](c1-tactical-input-readiness-01.md) の当時の記録を参照する。

`historical_as_of_available=false`、`prediction_use=NOT_AUTHORIZED`を維持する。本契約が実装されても既存706,051出走を自動的に発走前入力へ昇格しない。2026年開催はmetadata/Rawを含め閉鎖、BT-04/BT-05/LIVEは未承認、旧C1/C2・solver・係数・decoder・Gate・seal契約は不変。

## 2. 5W3H

| 観点 | 要件案 |
| --- | --- |
| Why | 現在値から観測時点を推定せず、対象と属性を含む取得時の原Rawにより後日検証できるようにする。予測精度改善の主張とは分離する |
| What | JSJ017/PJ0315の取得イベント、原Raw、脚質・予定時刻のfield証拠、レース/車番/登録番号対応、不変な検証履歴 |
| Who | 明示承認された取得処理が観測を記録し、ネットワーク非接続の検証器が照合する。ユーザー/レビューが対象・運用・予測利用を別途承認する |
| When | 発走予定時刻より前の取得を目指すが、先行時間・再取得頻度は未決。比較はUTC、表示はAsia/Tokyo。実発走・公式初回公開と区別する |
| Where | 既存の許可済み保存領域内の専用namespaceを提案する。実Rawはprivate、証跡は既存 `/home/shinya/neo-keirin-artifacts/` 配下を前提に詳細配置を別途承認する。今回は領域を作らない |
| How | 許可対象を固定し、取得→原Raw排他保存→log関連→整合性→本人対応→時点判定→manifest/sealの順。現在値upsertとは独立する |
| How many | 1 HTTP試行を1 captureとし、JSJ017の共有Rawは複数対象への参照で表す。対象件数・頻度・回数・timeoutは承認待ち。ストリーム/有限チャンクで処理する |
| How much | 容量・負荷・保持費用は未測定。保存量/取得失敗/候補率を別集計し、予算上限・保持期間を決めてから収集する。根拠のない容量/費用数値を固定しない |

## 3. 現行実装の確認結果

| 根拠ファイル・処理 | 確認した現行動作 | 証拠契約との差 |
| --- | --- | --- |
| `Fetchers/RaceEntryListFetcher.php`、`Services/RaceListSyncService.php` | 開催日の `encrypted_parameter` でGET、`type=JSJ017`。日別JSJ001→JSJ017取得/Raw保存、JSJ017→JSJ001解析→整合性→男子同期 | 1取得に複数レース。現在のday encpだけでは歴史的対象を確定できない。専用captureとRaw内識別が必要 |
| `Parsers/RaceEntryListParser.php` | `keirinCd/kaisaihi/reqprm`を照合。`rInfo[].raceNo`、`sInfo[].syaban/senNo/kyaku`、`stTime`を読む。登録番号は数字文字列を保持、男子5〜9車・車番重複拒否、非連番を許可 | DTOの脚質はHtmlTextNormalizer経由。原fieldの欠落/null/型/空文字をそのまま証拠にする機能とは別 |
| `Fetchers/RaceLiveFetcher.php`、`Services/RaceResultSyncService.php` | race encpでPOST `disp=PJ0315`、詳細更新後にPJ0326を取得・結果import。通常は `result_available` を対象条件に使用 | PJ0315取得が発走前である保証はない。新しい取得経路は結果同期/結果の取得可否から独立させる |
| `Parsers/RaceDetailParser.php` | HTML内PC0201の `selKaisai/selKjyoCd/selRaceNo`、`C0201racedtl.aftStartTime`。`sensyuTypeInfo[].syaban/sensyuRegistNo/kyakusitu`とPC0201 `carNum/numPlayer`を照合 | 原Raw内の2表の本人対応を保持できる。後日のDB本人情報で原文の欠落を補わない |
| `Http/KeirinHttpClient.php` | HTTP処理が返り、status/body/headerを取得した後、DTO作成時に `fetchedAt=new DateTimeImmutable('now')`。接続失敗用DTOも失敗後のnow。request keyはソートしたパラメータ・元URL・methodのSHA | request開始/受信完了の厳密な境界、時計同期証拠はない。リトライは最終応答とretry_countであり、中間試行の独立証拠を保証しない |
| `Services/RawResponseStorageService.php::store()` | 原bodyのSHA/bytes→`Storage::put()`→UTF-8変換→ScrapingFetchLog作成。パスは取得日/request key一部/取得時刻/原SHA一部から生成 | 名前の衝突を減らすが、既存パス拒否・no-replace公開・不変履歴を保証しない。専用Raw保存日時もない |
| 同 `store()` / `logFailureWithoutBody()` と `ScrapingFetchService` | HTTP異常でもbodyがあれば保存・log後に例外。接続失敗/bodyなしはRaw path/SHA nullのlog。変換失敗はRaw/log後に例外。put失敗はlog前に例外 | 保存失敗時のlog欠損、log作成失敗時の孤立Rawがあり得る。ファイル/DBの一体原子性は保証されていない |
| `RaceRepository::syncRaceDay()/updateRaceDetail()` | 両方が `riding_style` と汎用 `fetched_at` を更新。呼出し側が同期時のnowを渡す。JSJ017 `stTime` / PJ0315 `aftStartTime`由来の現在 `scheduled_start_at` も更新 | 汎用時刻は属性専用取得時刻でもHTTP応答時刻でもない。再同期で過去の属性/予定時刻が変わり得る |
| `Models/ScrapingFetchLog.php`、`Race.php`、`RaceEntry.php` とMigration `2026_07_18_000001/000002` | fetch logにrequest_parameters/fetched_at/status/size/SHA/path/parser版、raceに予定時刻、entryに脚質/汎用時刻。logのbatch IDはnullable | capture ID・Raw保存日時・取得code版・属性→特定logの参照・immutable制約はこれらの定義にない。timestampsを保存完了時刻に転用しない |

表中のFetcher/Parser/Http/Servicesは `app/Domain/Keirin/Scraping/` 配下、Repository/Modelsは `app/` 配下。現行コードを改修せず読み取った所見であり、上書きや時計異常が実際に起きた件数は未測定。

既存 `AutomatedRaceParserTest` は5〜9車・非連番・先頭ゼロ登録番号・重複/不正番号・中止/順延・PJ0315本人照合等の回帰を持つ。`KeirinHttpClientTest`、`RawResponseStorageServiceTest`、`ScrapingFetchServiceTest` は成功/HTTP異常/空応答/接続失敗/put失敗を検証している。今回はテストコードを読んだだけで、実行成功の新しい記録はない。発走前証拠保存の受入テストの代わりにはしない。

## 4. 現行フローと将来フロー

```text
現行出走表同期:
  meeting PJ0301 → day JSJ001/JSJ017 → Raw/log → Parser/整合性 → 現在race/entry更新
現行結果同期:
  race PJ0315 → Raw/log → 詳細/現在entry更新 → PJ0326 → 結果import

将来案（初期無効、承認対象だけ）:
  許可対象seal → capture/attempt IDを予約・記録 → JSJ017またはPJ0315
  → 変換前Rawの新規排他保存 → fetch logとの関連を新規記録
  → 原bytes/SHA再検証 → Raw内識別/脚質/予定時刻の投影
  → 対象・車番・登録番号照合 → 時点分類 → 検証版append → manifest/marker公開
  → 別承認の入力適格性Gate（今回は未設計freeze・未実行）
```

将来経路は `syncRaceDay()` や `updateRaceDetail()` を証拠保存の代用として呼ばない。既存result同期の取得・保存・状態遷移は変更せず、新経路は結果label・払戻・予測成績・result_availableを対象選択や時点証明に使わない。取得HTTPに結果関連内容が同居しても、原Rawを保持することと、それを入力へ投影することは別。投影は識別/予定時刻/脚質のallowlistだけとする。

## 5. 保存単位と関連

以下は論理構造の案であり、新テーブル/クラスの作成許可ではない。Phase 1ではDBなしの人工証跡束を推奨し、DBを使う場合のMigration/権限は別途レビューする。

```text
許可対象manifest（対象・request binding・版・seal）
  └─ capture（1 HTTP試行、source、取得時刻、code版）
       ├─ fetch log ID（既存ログの参照。失敗/未成立はNULL+理由）
       ├─ 原Raw blob（path/bytes/SHA。bodyなしは無し）
       └─ capture race observation [1..N]
            ├─ Raw pointer、外部race identity、観測予定時刻
            ├─ 取得時内部race/day ID（解決できる場合だけ）
            └─ entrant observation [1..N]
                 └─ bike + 外部登録番号 + 原脚質 + pointer

validation revision → capture/race/entrant observation と source/code seal
identity resolution revision → observation と後日確認した内部race/entry/player ID
```

JSJ017は1 capture/1原Rawから複数race observationへ参照を張り、レース数だけ原Rawを複製しない。PJ0315は通常1レース。internal race/entryがまだ未登録でも外部identityで原証拠を保持できるが、固定対象との照合完了までは未適格とする。capture全体の整合性と個々のrace/entryの分類を分離し、別レースの発走時刻を流用しない。

### 5.1 取得イベント

| field案 | 型/要否 | 定義・検証 |
| --- | --- | --- |
| `capture_id` | 一意文字列/必須 | 取得前に予約。request keyやbody hashから同じIDを再生成しない |
| `source` | JSJ017/PJ0315/必須 | route/method/typeまたはdispの組合せと照合 |
| `fetch_log_id` / `batch_run_id` | 内部ID/nullable | 実在した関連だけ。未成立/バッチなしの理由を区別し架空IDを入れない |
| `request_key` / `request_binding` | SHA/参照/必須 | method、route、秘匿パラメータのdigest、許可target manifestとの対応。encpだけを本人/対象証明にしない |
| `http_method` / `http_status` | 文字列/整数nullable | 応答なしはstatus null。既存FetchErrorTypeと失敗原因を別fieldに保持 |
| `request_started_at` / `fetched_at` | offset付日時 | 開始と受信完了を別記録。成功時fetched_atは原body受信完了時点。接続失敗では受信時刻null、別の `failed_at` を持つ |
| `raw_saved_at` | offset付日時/nullable | 原bodyの永続保存完了を確認した時刻。未確認はnull。file mtimeやlog created_atから補わない |
| `raw_path` / `raw_bytes` / `raw_sha256` | 参照/整数/SHA/nullable | 変換前bodyの参照。bodyなしを「正常な空Raw」に偽装しない。root内相対参照を検証し、path traversal/リンクを拒否 |
| `content_type` / `detected_encoding` / `conversion_status` | 原値/状態 | 原bodyと派生UTF-8を区別。変換版・派生hash・原Raw参照を残し原bodyを改変しない |
| `parser_version` / `capture_code_version` | 版/必須 | 取得時版と検証時版を区別。Git SHAだけでなく直接依存コードのpath/bytes/SHAと設定契約sealを持つ |
| `attempt_group_id` / `attempt_number` | 識別/整数 | 再試行の関連。HTTP試行ごとに新capture、途中失敗も保持。現行retry_countから存在しない試行証拠を作らない |
| `clock_evidence` | 参照/nullable | 時計同期方法、確認時刻、確認できた誤差/ジャンプ等。証拠なしはUNKNOWN、誤差0としない |

encp生値・認証・Cookie・URL全文を共有manifest/Git/報告へ保存しない。秘匿request bindingはprivate領域だけに限定し、共有側は識別子・digest・routeで追跡する。原Raw自体に含まれる値は改変せずprivate保存/アクセス制限とする。

### 5.2 レース・出走者

| field案 | 定義・検証 |
| --- | --- |
| `race_id` / `race_day_id` / `external_race_id` | 内部IDはnullable、外部IDはsource・場・日付・番号の対応を確認。現在encpだけ/開催名/多数決で補完しない |
| `race_date` / `track_code` / `race_number` | 実在日付、外部場コード文字列、正のレース番号。Rawと取得前固定targetの全項目一致が必要 |
| `observed_scheduled_start_raw` / `observed_scheduled_start_at` | 当該Rawの原時刻表記と解釈済み日時。欠落/null/空/不正型を別状態で保存 |
| `schedule_source` / `schedule_pointer` / `schedule_timezone` | JSJ017なら `rInfo[i].stTime` と `kaisaihi`、PJ0315ならPC0201 `C0201data.C0201racedtl.aftStartTime` と `selKaisai`。同じRawのSHAとpointer、日時解釈版・TZ根拠を保持 |
| `current_db_scheduled_start_at` | 後日照合を行うなら独立revisionの参考値。元観測時刻の代用品や旧判定の上書きには使用しない |
| `actual_start_at` / `official_first_published_at` | 別証拠と参照がない限りnull/UNKNOWN。取得時刻から初回公開時刻を推定しない |
| `entry_id` / `player_id` | 取得時の解決済み内部IDだけnullableで保存。後日の解決は別revision、原観測は更新しない |
| `bike_number` / `external_player_id` | 車番1〜9、一意。外部登録番号は数字文字列で先頭ゼロ保持。車番連番を要求しない。同車番の別登録番号は別観測/変更理由 |
| `riding_style_raw` / `raw_presence` / `raw_type` | 原field値と存在/型を保持。欠落/null/空/未知/型不正を統合しない |
| `riding_style_normalized` / `attribute_status` | 案は確認済み `逃/追/両` の完全一致だけを有効値へ対応。未知値は原値+UNKNOWN、欠損はnull+理由。現行DTOの空白正規化を原文保存に流用しない |
| `attribute_source` / `attribute_pointer` | JSJ017 `rInfo[i].sInfo[j].kyaku`、PJ0315 `sensyuTypeInfo[j].kyakusitu`。raceNo/bike/登録番号でも照合し配列添字だけを本人証拠にしない |
| `identity_status` / `timing_status` | 本人/対象と時点の別軸。未知脚質、未解決内部ID、識別不一致、時刻不明を混同しない |

日時のAsia/Tokyo解釈は現行Repositoryの設定によるもの。外部の時刻表記のTZ根拠を承認・版管理するまでは、設定値だけで `schedule_timezone` を確認済みにしない。原fieldが同じでも異なる解釈版なら別validation revisionとする。

### 5.3 append-only・競合・不変性

- 同じ対象/同じbodyの再取得も別capture。旧日時・予定時刻・属性・検証結果を更新/削除しない。
- 同captureの保存処理再開は保存済みbytes/SHA/identityが完全一致する場合だけ再利用し、異なる内容はCONFLICTとして停止。上書き修復しない。
- 原Rawは新規排他作成とno-replace公開。作成済みpathを保持し、リンク/マウント越境/既存path/相対pathの解決失敗は操作前に拒否する。秘密情報をpath名へ入れない。
- 脚質A→B→A、予定変更、欠場/復帰、選手交代は3観測として残る。content hashの一致だけで以前の観測期間を再利用しない。
- current-tableのupsertは別責務。本契約で既存race/entryを更新・削除せず、外部identityの証拠が内部行の後日変更/削除に依存しないようにする。
- 後日の本人解決・再検証・訂正は、元captureと旧revisionを参照する新revision。訂正理由/版/実施日時/証拠sealを追加する。
- DBログとファイルは一体Transactionとみなさない。原Rawが保存済みでlogが未成立の場合、Rawを保持しFAILED/関連未成立の診断を保存。後の回復は新しい監査revisionで行う。
- 取得済み原Raw/証拠/失敗資料は後片付け対象ではない。人工stageの退避だけ既存安全helperの作成時保持オブジェクトpathで実施し、Trash移動失敗時は保持・停止する。永久削除/親の推測/コピー後削除fallbackは使用しない。

## 6. 時点と状態遷移

### 6.1 時刻の意味と候補判定

HTTP受信完了の `fetched_at`、永続保存確認の `raw_saved_at`、同Rawの予定発走時刻、後日DB予定時刻、実発走時刻、公式初回公開時刻は別物。全比較はoffset/TZ根拠の確認後UTCへ正規化し、報告はAsia/Tokyoとoffsetを表示する。同一瞬間の異なるoffset表記は同じ比較結果とする。

時刻・対象・原Rawの出典が確認された場合の単純な候補条件は:

```text
fetched_at < observed_scheduled_start_at
```

等値を候補に含めない。予定時刻より前だったことは実発走前/予測投入前/公式初回公開の証明ではない。保存が発走後になった場合も受信時刻の分類と保存時刻の分類を別に保持し、予測時に利用可能だった証明にはしない。

時刻出典・TZ・時計信頼性が不明ならTIMING_UNKNOWN。既知の時刻誤差区間が境界と重なる場合も確定候補にしない。許容誤差の数値や最終GateはD6/D8で承認待ち。後日DB予定時刻や結果から推定した発走時刻で旧候補を昇格/書換えしない。延期・中止・打切はページの利用不能理由を別記録し、通常出走表や予定時刻を捏造しない。

### 6.2 状態表と遷移案

次の状態名は大文字snake caseの専用契約案。既存 `FetchErrorType` の原因コード、`RaceResultStatus` の結果状態、BatchRunItemの状態とは別であり、既存Enumの値を変更しない。

| 状態案 | 条件/次の処理 |
| --- | --- |
| CAPTURE_NOT_STARTED | 許可対象・capture IDのみ。取得開始の記録をappend |
| FETCH_FAILED | 接続失敗、HTTP異常、空応答等。原因と実際に存在するbody/ログを保持し候補にしない |
| RAW_SAVED_UNVERIFIED | 原Raw保存済み、bytes/SHA/関連/耐久性は未検証。正常判定に利用しない |
| RAW_INTEGRITY_VERIFIED | 原bytes/SHA・許可path・取得logとの対応が一致。これだけでは本人/時点は未確定 |
| IDENTITY_MISMATCH | 場/日/番号/車番集合/外部登録番号/内部対応の検証済み証拠が不一致。該当race/entryの候補公開をブロック |
| TIMING_UNKNOWN | 取得/予定時刻・TZ・時計証拠等不足。識別合格や属性値ありでも時点は未適格 |
| POSTSTART_CAPTURE | strict `<` 不成立（等値を含む）。`AT_SCHEDULED_START`/`AFTER_SCHEDULED_START`を理由で区別。実発走後と断定する状態ではない |
| PRESTART_EVIDENCE_CANDIDATE | 受信時刻が同Raw予定時刻より前で、整合性/対象/時刻証拠を確認。属性欠損等は別軸で残す。予測利用は未承認 |
| VALIDATION_FAILED | 破損・SHA不一致・不正schema・公開時seal不一致等。原因/失敗証拠を残し適格候補として公開しない |

```text
CAPTURE_NOT_STARTED → FETCH_FAILED
CAPTURE_NOT_STARTED → RAW_SAVED_UNVERIFIED → VALIDATION_FAILED
RAW_SAVED_UNVERIFIED → RAW_INTEGRITY_VERIFIED
RAW_INTEGRITY_VERIFIED → IDENTITY_MISMATCH
RAW_INTEGRITY_VERIFIED → TIMING_UNKNOWN / POSTSTART_CAPTURE / PRESTART_EVIDENCE_CANDIDATE
```

「遷移」は監査event/revisionの追加であり、旧行の状態を書き換える意味ではない。状態集計は最新版の評価を表示できるが、参照revisionを明示する。複数問題のある観測はintegrity/identity/timing/attribute各軸と全reasonを保持し、一つの代表状態で原因を消さない。取得候補でも未知/欠損脚質を有効値にしない。別Gate承認記録がない限り `prediction_use=NOT_AUTHORIZED`。

## 7. 取得・保存・検証・公開のシーケンス案

1. 明示許可対象のmanifestをsealし、開催年・日・場・番号・期待出走者対応を固定する。2026/対象外をHTTP前に拒否し、source/capture code/契約設定をSTART sealする。
2. capture ID、attempt、排他的保存先、開始日時を記録する。認証等の秘密を一般ログへ出さない。対象・URL制限・時計証拠・容量/権限不足なら未開始/失敗として停止。
3. 専用取得経路でHTTPを実行し、受信完了/失敗時刻・status・実応答を記録する。再試行は承認回数の範囲のみ、各試行別capture。現行の中間試行証拠の不足を解消できなければPhase 2を受入しない。
4. bodyがあれば変換前bytes/SHAを計算し、新規排他的stageへ書込み・flush/永続化確認・no-replace公開。実際に保存した時刻を記録する。失敗bodyも原文保持し、正常bodyとして解析しない。
5. 既存fetch logとの関連を記録する。保存失敗/関連未成立はFAILEDとして保持し、既存Rawやlogへ戻って上書きしない。取得観測を単にlog created_atから組み立てない。
6. 原Rawのbytes/SHAとlog/captureを再検証し、既存の変換/Parserを読取り専用で利用する範囲と専用原field投影を分ける。原Raw/source pointerを保持。結果/払戻等の投影は拒否する。
7. Raw内のcontextを固定targetへ照合し、車番と登録番号でentryを対応させる。PJ0315の二つの出走者表も照合。別ソース/再取得の変更は明示し多数決/現在値補完しない。
8. integrity、identity、timing、attributeを独立判定し、同Rawの予定時刻と比較する。対象件数と原因別coverageを、capture/Raw/race/entryの単位別に記録する。
9. END source/code sealと公開予定全manifestを検証後、排他的にmarkerを公開する。完了できなければFAILEDと実在ファイルを保持し、自動再試行・修復・削除しない。

提案する束は `capture.json`、原Raw参照、`race-observations.jsonl`、`entry-observations.jsonl`、`validation.jsonl`、`execution.json`、`manifest.json`、`COMPLETE.json`または`FAILED.json`。manifestは契約版・許可対象seal・直接依存code seal・全ファイルbytes/SHA・Raw参照・検証版を含む。COMPLETEは保存/検証手続きの完了であり、全件発走前/属性完備/予測利用承認を意味しない。異常状態も証拠として保持するが、Raw整合性不成立や公開seal不一致ではCOMPLETEを出さない。

保存資料だけの再検証はDB/ネットワーク/現在profileなしで可能にする。再検証時のcode版と元capture版を併記し、過去manifest/markerを再sealしない。共有成果物は必要な識別証拠・集計・sealのみとし、private原Rawや認証情報をGitへ入れない。

## 8. 次工程用テストケース案（今回は未作成・未実行）

| ケース | 必要な確認 |
| --- | --- |
| 正常JSJ017・複数レース | 1 Rawを共有参照し、日/場/raceNo/bike/登録番号/脚質pointerが一致。レースごとに時点分類 |
| 正常PJ0315 | PC0201/contextとsensyuTypeInfoが一致、原脚質とaftStartTime出典保持、原bytes/SHAとlog照合 |
| 同時刻を異なるoffset表記 | UTC比較/候補判定一致、原表記は保持 |
| 前/同時/後 | strict `<`のみ候補、等値はAT_SCHEDULED_START、後はAFTER_SCHEDULED_START。現在DB時刻変更で旧結果不変 |
| 受信は前・保存は後 | 二つの時刻分類を保持し、予測時利用可能として自動承認しない |
| 欠落/NULL/空/未知/不正型の脚質 | 原値・存在・型が別、null/UNKNOWNを0や既知脚質に置換しない |
| 予定時刻/TZ/時計証拠なし・誤差境界重複 | TIMING_UNKNOWN、推定による候補昇格なし、原因別coverage |
| 別日/場/番号、encp再利用/衝突 | request digest一致だけでは通さずIDENTITY_MISMATCH |
| 重複bike/不正bike/登録番号、本人変更 | 不一致保持・候補ブロック。先頭ゼロを保持、非連番5〜9車は拒否しない |
| 原Raw欠損/1byte改変/size不一致 | VALIDATION_FAILED、COMPLETE/適格公開なし、既存束不変 |
| HTTP429/5xx/接続失敗/空応答/変換失敗 | 原bodyあり/なしを分離、失敗原因保持、架空Raw/正常DTOなし |
| Raw write/flush/log保存失敗・途中中断 | 孤立Raw/部分stageとFAILED証拠保持、成功扱いなし。既存ファイル不変 |
| 同capture再保存と競合・同body再HTTP | 再保存完全一致だけ再利用、競合停止、再取得は別capture/日時を保持 |
| 脚質A→B→A・予定変更・欠場/再出現 | 各観測/旧期間/原文/登録番号保持。以前の版の終了情報を消さない |
| 中止/順延/打切・未知ページ形式 | 利用不能理由と未知を別保持、Raw/logがあっても通常出走表/予定時刻を作らない |
| 直接依存code/source/manifestの公開前改変 | START/END・生成時seal・完全manifestの不一致で公開拒否 |
| 対象外/2026・結果field混入 | I/O前の年/対象拒否、投影allowlist、DB/結果fallbackなし |
| 保存資料だけの再検証 | DB/HTTP/原本current値不可でも分類一致、元manifest不変、再検証版追跡 |
| 大容量・保護/退避 | 独立128M PHPでストリーム完走、既存成果物不変、安全helper保持pathだけ人工Trashへ退避。実保存データ移動試験なし |

既存Parserの正常/異常回帰も次工程の必要範囲で維持する。テスト成功だけで実サイトの意味・公開時点・取得対象承認を認定しない。

## 9. ユーザー承認待ちの項目

| ID | 決めること・選択肢 | 推奨案と根拠（未承認） |
| --- | --- | --- |
| D1 | 取得年/日/場/レースの列挙、対象数上限。広い期間指定か限定allowlistか | 小さな明示allowlistから開始。2026は選択肢に入れず既存凍結を維持。将来レースという言葉だけで対象年を自動許可しない |
| D2 | 発走何分前か、再取得間隔/上限、timeout/retry | ユーザー決定の有限回数と負荷上限、候補成立前の取得を基本とする。具体分数/回数は未決、既存configを無検証で転用しない |
| D3 | 規約/robots/アクセス制限、許可route、429時の停止 | 最新利用条件を別途確認してから有効化。未確認/禁止/上限到達ならSTOP。今回は外部確認していない |
| D4 | JSJ017/PJ0315優先、予定時刻や脚質が異なる場合 | JSJ017はday一括、PJ0315は個別検証用という候補。両方独立保存し、矛盾は保留。最終入力に使う版/優先規則を承認し多数決しない |
| D5 | 延期/中止/欠場/交代の再取得規則 | 新captureと変更理由をappend、旧版を保持。中止を正常入力扱いにせず再取得も許可回数内だけ |
| D6 | 時計同期・証拠保存・許容誤差・境界判定 | 同期証拠と受信/保存時刻を保持し、不明/境界重複はUNKNOWN。誤差の数値は実測/承認後だけ |
| D7 | 保存root詳細、権限、容量/保持期間、障害回復 | 合意済みroot/private Rawと小型共有証跡、不変履歴を推奨。自動削除/既存束上書きなし。保持期限/復旧権限は別決定 |
| D8 | 予測入力の最終適格性、対象集合/完全性、版選択 | 取得候補から独立した明示Gate。実発走/保存完了/欠損/訂正/二源矛盾等の扱いを決めるまでNOT_AUTHORIZED。旧C1へ直結しない |

これらの未決を推測で埋めない。Phase 1の人工Fixture実装を始めるにも、本案の保存構造/状態/受入条件と、その限定実装範囲の追加承認が必要。

## 10. 次の実装工程案と受入条件

| Phase | 変更対象・責務案 | 事前条件 | 検証/受入 | STOP条件 |
| --- | --- | --- | --- | --- |
| 1 人工証拠 | 専用Scraping DTO/validation・不変ファイル保存器・人工Fixtureテスト。既存汎用クラス変更は先に差分レビュー。DB/HTTP/Command/Scheduler不要 | 保存/状態/時刻/pointer契約とPhase 1範囲の承認。人工rootだけ書込み可 | 第8節正常/異常/競合・独立128M・保存資料だけの検証・原本不変。既存安全helper維持 | 不明なroot、既存path、seal不一致、未承認設定依存、秘密混入、I/O保護不成立 |
| 2 許可対象接続 | 専用capture orchestration、既存Fetcher/HTTP/Rawとのadapter。初期無効。設定/任意DB保存/運用起動の許可は別途明記 | D1〜D7、route/TZ/時計根拠、各試行観測、権限/保存先と有限負荷の承認 | mock HTTP全異常、I/O前対象/2026拒否、1試行1観測、独立Raw/log、結果同期を起動しない | default有効、対象外、期限/回数超過、時点根拠不足、保存失敗。自動retry拡張/本番修復禁止 |
| 3 観測監査 | 明示許可されたcaptureのsource/code/coverage・本人/時点監査、保存証拠だけのoffline再検証 | Phase 2受入と限定監査対象承認。結果/性能を監査対象へ加えない | capture数/Raw数/race数/entry数を分離、missing/unknown/mismatch/poststart等を集計、原本不変 | 帰属不能、時刻不足、Raw/seal不一致は保留/失敗、完全性を未知値で水増ししない |
| 4 適格性/比較 | 別契約の入力適格性Gate、必要なら別途限定予測比較。C1/model機能変更と収集を混在させない | D8・対象入力/結果開放/比較規則を別承認しfreeze。Phase 3受入だけでは開始不可 | 適格証拠と使用版の追跡、時系列漏洩防止、別承認された再現/精度評価 | UNKNOWNを適格化、未承認対象/結果アクセス、既存固定成果物改変、2026/BT-04/BT-05/LIVEへの移行 |

各Phaseは独立レビューを必要とし、本工程から自動開始しない。将来のcollector/validatorで具体ファイル名・DB制約を決めるのは当該Phaseの承認時。今ある結果状態Enum・Parser/Repository/Fetcher・全C1計算契約・共通canonical/hashを改変する包括許可ではない。

## 11. 今回の文書検証・引継ぎ

開始Git確認とPR100 mergeメッセージ、新branch作成、AGENTS/MASTER PLAN現在地・frozen/unfrozen/holdout・前工程文書・指定取得経路/モデル/Migration/関連テストの読取りを実施した。文書の相互参照、状態/時刻/保存単位/未決事項/Phaseの整合と、許可2Markdownだけの差分、`git diff --check`を確認する。

今回のテスト/Pint/DB/HTTP/Raw/監査再実行/学習/予測/評価/2026参照はすべて0。原本 `.env` / storage /モデル/保存予測/年次資料/旧監査成果物/安全helperの変更0、永久削除/退避処理0。commit/push/PR操作/mergeなし。

未決D1〜D8があるため **DESIGN_REVIEW_REQUIRED**。次はこの契約案の文書レビューと未決事項の決定だけ。実装・収集・予測利用は未承認のまま停止する。
