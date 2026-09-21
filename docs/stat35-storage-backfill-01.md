# STAT-35-STORAGE-BACKFILL-01

## Scope And Gate

- Start main/origin: `cefd6b4e777315f08cc10980369d5734196d01f7` (PR #64 MERGED)。
- Branch: `feature/stat35-storage-backfill-01`。
- 今回はコード・Migration・人工テストのみ。本番Migration・正式backfillは未許可、未実行。
- `STAT-35-DATA-READINESS-AUDIT-01 = COMPLETED_PR64_MERGED`。
- readinessは `BLOCKED_INSUFFICIENT_RAW_HISTORY`、identity_safe=true、primary=`INSUFFICIENT_RAW_HISTORY`、secondary=`RAW_GAP_POLICY` のまま。
- score、終盤速度変換、競輪場/距離補正、外れ値閾値、学習、予測評価、bootstrap、Gate、LIVEは対象外。
- 2026実レース・Raw・outcomeへのアクセスは0。2026 holdoutは `FROZEN_FOR_MODEL_SELECTION`。

## Source Semantic And Normalization

正式semanticは `PJ0326.agari = AGARI_TIME`。既存DTOの `finishTime` はこの値を運ぶが、全競走のゴールタイムではない。
大規模renameは行わず、末尾の任意プロパティ `agariRawText` で正規化前の表示文字列を保持する。
`AgariTimeNormalizer` (`AGARI-TIME-v1`) が唯一の数値正規化を行う。

| 原表示 | 正規化値 | 状態 |
|---|---|---|
| NULL、空欄、既存Normalizerの欠損記号 | NULL | MISSING |
| 正の十進整数/小数 | decimal string | VALID (FINISHED/TIED) |
| 正の十進値を持つ失格・未完走等 | decimal string | OBSERVED_ABNORMAL_RESULT |
| 0、負数、非数、指数表記等 | NULL | INVALID_FORMAT |

元文字列はそのまま保存し、浮動小数点へ変換しない。先頭0と小数末尾0だけを正規化する。
0を欠損と同一視せず、異常結果の数値も捨てない。異常結果を将来のSTAT入力に採用するかは未決定。
JSON `agari` が表示文字列でも整数でもNULLでもない構造ならParserException。実ページで確認した表示値を勝手に浮動小数で丸めない。

手動HTMLは `上り` の正確なヘッダーを使う。対応する `着`・`車番`・`選手名` を同じヘッダーから解決し、列順変更と余分な列に対応する。
不明な `Time` 等は別名として採用しない。上りヘッダーがなければ既存のrank/bike/player契約で解析し、上りだけMISSING。
曖昧な重複ヘッダー、ヘッダーとデータの列数不一致、重複車番、不明な順位/選手識別の拒否は維持する。

## Schema And Lifecycle

Migration: `2026_09_21_000013_add_agari_storage.php`。適用済みMigrationは変更しない。

`race_results` にnullableの `agari_time_seconds`、`agari_raw_text`、`agari_status` を追加。
既存行は未補完の3値NULLで残す。未知の取得時刻や過去の上がり値は埋めない。
PostgreSQLでは桁数/scale指定なしのNUMERIC、SQLite人工テストではNUMERIC affinityの丸めを避けるTEXTで同じdecimal stringを保持する。
PostgreSQLのCHECKは正数・状態との整合性・NaN/Infinity拒否を保証する。

`race_result_agari_observations` は以下を保存する。

- race/import ID、観測保存時に一致確認できた任意entry/player ID、Rawの登録番号、車番、選手結果状態。
- 上がり原文・decimal・状態、URL、取得日時、parser/storage version、作成日時。
- metadata: origin、AGARI_TIME、publication timestamp UNKNOWN、原Raw hash、UTF-8 hash、Rawパス、元Parser/Normalizer version。
- UNIQUE `(race_result_import_id, bike_number)`、検索index `(race_id, bike_number)`、任意identity列のindex。
- race/import FKはRESTRICT。観測はDB triggerでUPDATE/DELETEを拒否するappend-only。
- 現行出走表は訂正時に物理削除されるため、任意entry/player IDは観測作成時の補助識別値として保存し、可変な出走/選手テーブルへのFKは付けない。RESTRICTによる同期不能やSET NULLによる監査値改変を避ける。
- 補助IDの確認時点はmetadata `identity_link_timing=OBSERVATION_PERSISTENCE`。バックフィル時に見つかった出走IDが、過去Raw取得時にも存在したとの保証はしない。Raw登録番号がない手動HTMLでは補助IDを推測しない。
- 再読込時は、現在の出走表で当時の任意IDを上書きしない。Rawの登録番号とimport/bikeの同一性を維持する。

downは観測が1件でも存在する、または現在値が1つでも補完済みなら、削除前に例外とする。
空の場合のdown/upは既存race/result/import等を維持し、追加schemaだけを操作する。

## New Import And Corrections

`RaceResultImportService` の既存ResultsAvailable transaction内で観測insertと現在値upsertを実施する。
既存 `race_results.race_result_import_id` が現在値の正式出典であり、重複するsource import列は追加しない。
既存のURL・Raw行・fetched_at・import監査・result状態遷移・払戻・完全性検証は維持する。
観測のfetched_atは紐づくfetch logの実値。手動import等でlogがなければNULL/UNKNOWN。imported_atや処理時刻を取得日時へ代用しない。

import A=11.5、B=11.3なら、AとBの観測を別行として保持する。Bによる訂正が成功した現在行だけがB/11.3になる。
同じRawの新規同期でも新しいimport履歴とその観測を残し、現在result行は既存IDで更新する。
途中の観測・result・払戻・import成功記録の失敗は同じtransactionでロールバックする。
取消・未掲載・審議中のpageは観測0件。既存の取消時のcurrent result/payout処理は変更せず、過去観測は残す。

## Backfill Contract

Command: `keirin:stat35:backfill-agari`、契約 `STAT35-AGARI-BACKFILL-v1`。

```bash
php -d memory_limit=512M artisan keirin:stat35:backfill-agari --plan --from=2022-01-01 --to=2025-12-31 --chunk=100
# 以下は将来の承認後に限る。本工程では本番に対して実行していない。
php -d memory_limit=512M artisan keirin:stat35:backfill-agari --execute --dry-run --from=2022-01-01 --to=2025-12-31 --chunk=100
php -d memory_limit=512M artisan keirin:stat35:backfill-agari --execute --from=2022-01-01 --to=2025-12-31 --chunk=100
```

- modeはplanまたはexecuteを必須とする。dry-runはexecuteと組み合わせる。
- 日付は実在日かつ2022-01-01..2025-12-31、from<=to。範囲外はDB/Raw取得前に拒否。
- SQLでもsourceとrace_dateを限定する。各import IDをchunk単位で昇順走査。既定100、1..1000。
- 男子以外/不明カテゴリはRawを解析せず明示skip。NO_IMPORTは指定source/期間のraceでimportがない件数。既知29件をハードコードせず、日付範囲から再計数する。
- planはDB/Rawを開かない。dry-runは同じ検証と衝突検知を行うが、batchを含めDBへ書かない。
- executeはimport単位transactionとrace lock、バッチ用advisory lockを使う。1import失敗はロールバックしてFAILEDを記録し、後続importへ進む。外側例外でもBatchRun終了とlock解放を試みる。
- summaryのobservations/current_updatesは今回の追加/補完件数。dry-run時は予定件数。peak_memory_bytesも出力する。

## Raw And Version Safety

参照は既存importのRaw path、source metadata、fetch logのみ。ネットワーク再取得なし。
PR64のRawReaderを変更せず再利用し、原bytesのsource_hash・size、fetch logとのhash/size/path一致、UTF-8 converted_hashを確認する。
既存の1byte..4MiBの1Raw上限を維持。相対パスの逸脱、symlink（親経路を含む）、不存在、hash/size差、変換証拠不足を拒否する。
transaction終了前にもRaw sealを再確認する。元Rawには書き込まない。

PJ0326は元PC0201の日付/会場/レース番号を対象raceと照合する。各importは自分自身のRawだけを使う。
成功済みimportの保存結果件数をその版の期待人数に使い、それがなければ対象raceのentrant_countを用いる。
5..9車・件数一致・車番範囲/重複・結果解析/払戻解析完全性を確認し、後年の出走者構成で過去の版を置換しない。
現在resultの同じimportに属する行の車番/状態/順位を照合する。不整合はfail-visible。
出走IDの補助リンクはRaw登録番号と一致する既存行に限り、不明ならNULLとする。

CANCELLEDで行なし、または車番が一意でagariが空欄の部分行は通常観測0件。
中止非空値・不正車番等はFAILED。PR64の48imports/53blank rowsは通常履歴へ昇格させない。
未掲載・審議中・確定根拠不明も0件として区別してskipする。

現在値はrow自身のimport IDに一致する観測からだけ補完する。最新import検索はしない。
3値未補完なら上がり3列だけを更新し、既存provenance・updated_at等は変えない。同一値なら書かず、補完済み値との衝突は拒否する。
既存観測とRaw由来の内容やhashが違えば上書きせず失敗する。訂正は別importの別観測で表す。

## Historical As-Of Limitation

バックフィル観測のoriginは `BACKFILLED_FINAL_RESULT`、publication timestampはUNKNOWN。
fetched_atは実際の保存済みfetch logの日時であり、race_date/result dateへの偽装はしない。
構造化保存は発走前取得証跡を増やさない。2024/2025 historical model inputとしてSTAT-35を使うことは禁止のまま。
旧Growth不採用、E08不採用、旧TACTICAL-PILOT-01保留、既存モデル/成果物、2026凍結を変更しない。

## Verification

人工Rawはテスト内で生成し、実Raw全体や個人データは追加していない。
focusedではdecimal/異常値/同着/5・7・8・9車、ヘッダー、有無/順序/余分列、訂正/原子性/再実行、Raw改変/欠落/symlink、期間境界、取消部分行を検査する。
SQLiteと分離した一時PostgreSQLでMigration・exact decimal・append-only・unique/FK・down/upを検査する。
人工テストのPHPUnitは128M、本番用例は512M。128Mを本番の固定条件にはしない。

| 検証 | 最終結果 |
|---|---|
| AgariTime / AgariStorageBackfill / AgariStorageMigration (SQLite, 128M) | 63 passed / 385 assertions |
| AutomatedRaceParser / RaceResultParser / RaceResultPageParser / ImportRaceResultsCommand / SyncAutomatedRacesCommand (128M) | 105 passed / 1,248 assertions |
| AgariStorageBackfill / AgariStorageMigration (一時PostgreSQL 18.6, 128M) | 39 passed / 326 assertions |
| `php artisan test` 全体 | 1,859件中1,850 passed / 9 PostgreSQL限定skip / 14,160 assertions |
| 変更PHP 15ファイルの `php -l` | PASS |
| 変更PHP限定 `vendor/bin/pint --test` | PASS |
| `git diff --check` | PASS |

一時PostgreSQLのテストDB再構築でtrigger関数が残るケースを検出し、今回の新規関数定義をCREATE OR REPLACEに修正して再実行した。最終版でMigration全適用と制約テストが成功。
追加テストではCP932原bytesとUTF-8 hashの区別、Raw終了時改変、成功item記録失敗の原子性も確認した。
SQLite全体の既存PG限定skipは隠さず、今回の新規DBテストは別途PostgreSQLでも全件実行している。

再検証コマンド（人工テストのみ）:

```bash
php -d memory_limit=128M vendor/bin/phpunit tests/Unit/Domain/Keirin/Scraping/AgariTimeTest.php tests/Feature/Console/Keirin/AgariStorageBackfillTest.php tests/Feature/Database/AgariStorageMigrationTest.php
php -d memory_limit=128M vendor/bin/phpunit tests/Unit/Domain/Keirin/Scraping/AutomatedRaceParserTest.php tests/Unit/Domain/Keirin/Scraping/RaceResultParserTest.php tests/Unit/Domain/Keirin/Scraping/RaceResultPageParserTest.php tests/Feature/Console/Keirin/ImportRaceResultsCommandTest.php tests/Feature/Console/Keirin/SyncAutomatedRacesCommandTest.php
php artisan test
git diff --check
```

PostgreSQLテストでは独立した空DBとUnix socketを明示し、本番の.env接続先は使用していない。
本番DB write=0、本番Migration=0、正式backfill=0、新規取得=0、実2026アクセス=0。
実装完了状態は `STAT35_STORAGE_BACKFILL_01_IMPLEMENTATION_AWAITING_REVIEW`。
次は `REVIEW_STAT35_STORAGE_BACKFILL_01` のみ。次実装・本番Migration/backfillはNOT_AUTHORIZED。
