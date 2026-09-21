# STAT-35-PRODUCTION-PREFLIGHT-01 / Application Procedure

## Status And Scope

2026-09-22 JST、本番適用前確認と手順作成を実施。**手順レビュー待ち。本番書込みはNOT_AUTHORIZED。**
PR #65は実装・レビュー・マージ完了であり、本番適用完了とは区別する。
本書の将来コマンドは承認後の手順例であって、今回の実行記録ではない。

- 開始main / origin/main: `adb847c5b0a2b0778ecb02a57c37bffbecf2d926`。
- merge subject: `Merge pull request #65 from Shinya-Taketani/feature/stat35-storage-backfill-01`。
- 作業branch: `ops/stat35-production-preflight-01`。開始時clean。
- 変更は本書とMASTER PLANだけ。PHP・Migration・テスト・設定は変更しない。
- 許可範囲: Git確認、コード/文書/起動定義確認、DBのREAD ONLY構造照会、DB/Raw非参照plan。
- 本番Migration / backfill / backfill dry-run / DB write / Raw参照 / 新規取得 / 実2026レース参照は全て0。
- historical_as_of_available=false、2026 FROZEN_FOR_MODEL_SELECTION、Growth不採用、既存C1、Goal 4/5未着手を維持。
- 本確認によるSTAT-35の計算・予測利用・学習・精度評価・LIVEの許可はない。

## Evidence

永続証跡ディレクトリ（既存ファイル上書きなし）:

```text
/home/shinya/neo-keirin-artifacts/stat35-production-preflight-01/run-20260922-Jtc8st/
```

接続先の実値・role・DBバージョン全文・照会結果・実環境設定の識別情報は、この外部証跡だけに保存する。
認証情報・接続URL全文・.env全文は表示/保存していない。

- `preflight.php`: アプリproviderを起動せず設定を解決する確認スクリプト。
- `db-metadata.json` / `read-only-queries.jsonl`: 接続同一性、READ ONLY確認、Migration履歴、構造照会。
- `runtime.json` / `runtime-detail.json`: 起動定義・可視プロセス・関連crontabの確認と観測限界。
- `plan.json` / `plan.stdout.txt` / `plan.stderr.txt`: 正確なargv、終了コード、経過時間、出力。
- 最終Git差分、本書のコピー、証跡一覧/サイズ/SHA-256も同じディレクトリへ保存。

## Confirmed Facts

### Database And Schema

アプリのdefault接続はpgsql、config cacheなし。既存設定から解決した接続先と実接続先のDBを照合した。
schemaはpublic、PostgreSQL 18.6。具体的接続情報は外部 `db-metadata.json` のconfigured_connection/identityを正本とする。

接続開始パラメータで `default_transaction_read_only=on` を設定し、最初にSHOWでdefault/transaction双方onを確認。
その後 `BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY` と再確認を行い、最後はROLLBACK。
statement timeout=15秒、lock timeout=3秒。権限変更や試験INSERTは行っていない。
許可した読取りはMigration管理表・pg_catalog・information_schema・セッション設定だけで、業務行は0件。

| 確認対象 | 今回の確認事実 |
|---|---|
| Migration履歴 | 16件適用済み |
| 未適用Migration | `2026_09_21_000013_add_agari_storage` のみ |
| 他の未適用Migration | ローカルMigration一覧との照合で0件 |
| `race_results.agari_time_seconds` | 未作成 |
| `race_results.agari_raw_text` | 未作成 |
| `race_results.agari_status` | 未作成 |
| `race_result_agari_observations` | 未作成 |
| agari CHECK / 観測unique・FK・index・trigger / immutable function | 未作成。適用後の構造適合性は未検証 |
| 履歴とschema | どちらも未適用で整合。履歴だけ適用済みという不整合は検出なし |

対象MigrationファイルSHA-256:
`91f516f8e9a43138166ffd816635d5b9b7161343d64ef2f65cf6fca8f06eed90`。
未作成は想定内であり、本確認を中断する理由にはしていない。**現コードによる通常結果保存の実行条件は未充足**。
既存agari値・観測件数を業務SELECTで確認したわけではない。未作成schemaとデータ件数0を混同しない。

### Existing Result Synchronization

`SyncRaceResultsCommand -> RaceResultSyncService -> RaceResultImportService::importStoredResponse()`、
手動経路は `ImportRaceResultsCommand -> RaceResultImportService::importRawFile()`。
両者のResultsAvailableは `syncAvailableResults()` 内で `AgariObservationService::record()` を必ず呼び、
観測テーブルをSELECT/INSERTし、現在結果へagari3列を保存する。schema存在によるfallbackはない。

**未適用schemaでこの保存経路を通ると失敗する。** 結果/払戻のtransactionはロールバックされるが、
既に保存したRaw・fetch log・import失敗記録・batch履歴まで全て戻るわけではない。
自動同期ではそれ以前のPJ0315詳細更新も別処理である。未掲載・中止経路まで全て同じ失敗になるとは断定しない。
本確認では同期コマンドを実行していないため、実稼働の失敗件数は未確認。

確認したrepoのroutes/console.php・bootstrap・command/providerには結果同期のscheduler/queue起動登録がない。
現在ユーザーcrontabの関連行は別リポジトリのschedule:run。可視systemd定義/プロセスも本repoの結果同期起動を示さなかった。
Apache有効サイトのDocumentRootは本repoのpublicを指す。今回のplanは本repoから実行した。
一方、常駐プロセスのロード済みコードSHA、他ユーザーcrontab、別ホスト/手動terminal、将来の起動は未確認。
「結果同期は必ず手動だけ」「稼働停止済み」とは扱わない。サービス/cron/queueは変更していない。

### Plan

次だけを1回実行し、exit code=0、stderr空を保存した。

```bash
php -d memory_limit=512M artisan keirin:stat35:backfill-agari \
  --plan --from=2022-01-01 --to=2025-12-31 --chunk=100
```

```json
{"version":"STAT35-AGARI-BACKFILL-v1","from":"2022-01-01","to":"2025-12-31","chunk":100,"origin":"BACKFILLED_FINAL_RESULT","publication_timestamp":"UNKNOWN","historical_as_of_available":false,"network_access":false}
```

Commandのplan分岐はService::planだけを呼び、batch開始・DB・Raw処理へ進まない。
plan成功はschema適用/Raw完全性/backfill成功を証明しない。dry-runも観測テーブルを照会するため未適用状態では実行しない。

## Approval Prerequisites

以下は未実施/未確定の条件。確認担当者・承認者をユーザー側で決め、別途実施承認を得る。

1. 実行時の接続先とコードSHAを上記証跡へ再照合する。本書作成後の設定/schema driftを再確認する。
2. 通常の結果同期、手動import、同一DBへ書く他の処理の運用担当/実行中作業を確認する。担当者が停止・再開の手段と時間帯を承認する。
3. バックフィルのadvisory key `stat35-agari-backfill` は、通常同期の期間別key/手動import keyと別。race単位lockはあるが全体の競合防止ではない。dry-runにはこのbatch lockもない。運用側で単独実行を保証する。
4. バックアップと復旧の責任者・保管先・整合時点・復旧許容時間を確定し、別環境で復旧可能性を確認する。今回はdump/データ複製を行っていない。
5. 必要な保護範囲はschema/Migration履歴、処理対象2022-2025のrace_resultsと参照race/import、既存観測（あれば）、監査batch/item、整合比較用の順位/払戻/取得日時。原Rawと旧正式成果物も保持する。参照FK・sequenceの復旧整合性を含める。
6. 全DB dumpや2026業務行の抽出を本手順の確認用backupにしない。Migrationはtable全体のDDLなので、障害時に対象外年へ影響を与えず復旧する方法はDB管理者の別承認事項とする。2026の評価/行参照は引き続き禁止。
7. repoと成果物volumeの空きは確認したが、DB data/WAL volume、将来の観測・index・WAL増加量、backup容量、DDL lock待ち/所要時間は未確認。十分性を保証しない。
8. backfill対象の日付を承認する。最初の1日は2022-2025内で選び、件数や成功を今回の旧監査値から推測しない。

## Authorized Application Sequence (Not Executed)

### A. Before Applying

接続先・実行コードSHA・対象Migration hash・証跡root・担当・停止窓を固定する。
停止・再開は運用担当が承認した方法で実施し、当エージェントが無断で行うものではない。
業務値の比較も別途承認後に、SQL側で2022-2025/対象IDに限定して保存する。
追加3列以外の既存値、行ID、件数、rank/status、払戻、取得日時、import参照の前後digestを取得する。
現在値補完に無関係な既存値を改変しないことは、Migrationソースレビューとこの限定比較で検証する。

### B. Only the Target Migration

以下は未実行。承認済み接続設定の同一コードrootで、対象1ファイルだけを指定する。
`--force` は本番確認promptを省くため、別途承認済みであることが前提。`--graceful` やseedは付けない。

```bash
php -d memory_limit=512M artisan migrate --database=pgsql \
  --path=database/migrations/2026_09_21_000013_add_agari_storage.php --force
```

無指定の `migrate` で他の未適用Migrationをまとめて適用しない。履歴/schemaが既に揃っていた場合も再適用しない。
履歴だけ適用済み・部分schema等があれば自動修復せず、個別調査の承認へ戻る。

適用後はREAD ONLY接続で以下を再確認し、対象履歴とschemaを照合する。

- race_results: nullable `agari_time_seconds=NUMERIC`（precision/scale未指定）、`agari_raw_text=TEXT`、`agari_status=VARCHAR(40)`。
- observations: Migration定義全列、PK、`agari_observations_import_bike_unique`、`agari_observations_race_bike_index`、entry/player index。
- race/importのRESTRICT FK。可変entry/playerにはFKを追加しない既存仕様。
- 両tableのagari CHECK、観測bikeの1..9 CHECK、制約validated状態。
- `agari_observations_immutable` が有効なBEFORE UPDATE OR DELETE triggerであり、`reject_agari_observation_mutation()` が例外を送出する定義。
- 既存業務列にはUPDATE/backfillをしないMigrationであること、限定した事前digestが変わらず追加3列だけ未補完であること。

### C. Small Backfill Before Expansion

schema適合性の確認後、承認した1日分をdry-runする。以下のDATEはその日へ置換し、承認なしに実行しない。

```bash
php -d memory_limit=512M artisan keirin:stat35:backfill-agari \
  --execute --dry-run --from=DATE --to=DATE --chunk=100
# 上の出力をレビューし、同一範囲の書込みを別途承認した後だけ:
php -d memory_limit=512M artisan keirin:stat35:backfill-agari \
  --execute --from=DATE --to=DATE --chunk=100
```

- stdout/stderr・正確なargv・コードSHA・終了コード・開始終了時刻/処理秒・summary・peak_memory_bytesを新規ファイルへ保存する。
- `NO_IMPORT`は男子gap、`NO_IMPORT_UNSUPPORTED`はGirls/Unknownのgap。どちらも成功import数ではない。
- CANCELLED、UNAVAILABLE、UNDER_REVIEW、RESULT_STATUS_UNDETERMINED、UNSUPPORTED_RACE_CATEGORYのskip理由とFAILEDを分ける。
- PJ0326の監査済みblank partialは観測0件。Manual中止の非空データ行はFAILED。29/48/53等は旧監査の数値であり今回の期待件数へ固定しない。
- **import単位transaction**。同じ範囲の1importが失敗しても後続へ進み、先行成功分は保持される。範囲全体のrollbackではない。
- Commandはsummary.failed>0または外側例外でexit 1。exit code、failed、batch終了状態を確認し、1つでも不正なら次の期間を自動開始しない。
- dry-runは観測照会/Raw検証を行うがbatchや業務値を書かない。全件ロールバックで試行するexecuteの代用品ではない。
- 小範囲の保存検証後、別承認で2022-2025を非重複の日付区間へ拡張する。resume optionはない。同じ範囲の再実行は不変性/冪等性検査を通して行う。
- streaming/chunk構造を維持し、実データは512Mを基本例として実測で調整する。独立bounded-memoryテストの128M制約を緩和する意味ではない。

### D. Validate Saved Data

承認後のREAD ONLY検証は対象ID/2022-2025に限定する。
現在値はrow自身の `race_result_import_id` の観測値と照合し、最新importへ勝手に付け替えていないことを確認する。
補完済み値の衝突はFAILEDであり上書きしない。旧順位・払戻・source/fetched_at・updated_at・IDと既存import履歴のdigestを照合する。
import/bike別観測を保持し、Raw hash/size/変換後hash/終了時sealが検証されていることを確認する。
再dry-runの `observations=0` / `current_updates=0` を確認する。新しい同期が混入していれば同条件とはみなさない。
元Raw、過去batch/FAILED、旧正式成果物を保持する。完了は保存基盤の適用完了であり、as-of回復・STAT採用・精度向上ではない。

### E. Failure And Recovery

観測が1件でも存在、または現在3値のいずれかが補完済みならMigrationのdownは拒否する。
`migrate:rollback` は標準復旧手順にしない。trigger無効化、履歴DELETE、TRUNCATEは行わない。
途中成功分・失敗監査・Rawを保持し、後続期間を止め、原因/schema/原本/現在値の整合性をREAD ONLYで調べる。
原因が解消し、原本とコード・保存済み観測を変更しない再開が可能な場合だけ、別承認で同一範囲を再実行する。
immutable conflictやschema不整合は無理に上書きしない。コードの修正も本工程の対象外。
復旧用backupの復元は隔離環境で検証し、稼働DB切替や補正は別の承認済み復旧計画に従う。
具体的な復旧計画・restore試験は未確定/未実施であり、**本番実行の未充足条件**として残す。

## Completion And Remaining Conditions

今回許可されたmetadata/起動経路確認とplanは完了。未適用はschema履歴と整合し、確認作業の阻害にはならなかった。
本番適用手順レビュー、運用担当と排他窓、対象1日、容量/lock・backup/復旧試験、個別の書込み承認が必要。
実際の全稼働経路の網羅確認とロード済みコード同一性は未確認であり、同期再開前に運用担当が確認する。
今回Migration・dry-run・backfillは未実施。次は手順レビューのみ、本番書込みはNOT_AUTHORIZED。
機能変更がないためテスト再実行なし。Gitのdiff-checkと変更範囲を確認し、未コミットで停止する。
