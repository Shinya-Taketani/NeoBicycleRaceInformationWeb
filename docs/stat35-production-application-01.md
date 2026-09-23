# STAT-35-PRODUCTION-PREFLIGHT-01 / Application Procedure

## Current Result / STAT-35-PRODUCTION-BACKFILL-2022-2025-01

2026-09-22 JST: **ALL_INTERVALS_PROCESSED_AND_VERIFIED_AWAITING_REVIEW**。
PR #68 MERGED、pilot結果レビュー完了後、今回のユーザー明示許可で残りの2022-2025を処理した。
全48区間の正式保存・READ ONLY保存照合・保存後dry-runが成功し、未着手0。次は今回の結果レビューだけ。
**処理範囲の完了と欠損のない完全データは別であり、NO_IMPORT / CANCELLED skip / MISSING等は残る。**

### Authorization And Fixed Intervals

- 開始main / 実行SHA: `88ac8ff4677dde5c53a40ef028e5d83923671e07`。cleanを確認して `ops/stat35-production-backfill-2022-2025-01` を作成。
- 対象接続: `pgsql / 127.0.0.1:5432 / neo_keirin_prediction_db / public`、source=`keirin_jp`。実IPをhost()で正規化して照合。認証情報は出力・保存しない。
- 範囲: 2022-01-01～2025-12-31、ただし保存済み2024-12-31を除外。暦月48区間、2024年12月だけ12-01～12-30。
- `intervals.json` は日付ライブラリで作成。48区間/1,460日、重複・期間外日付・除外日以外の欠落なしをDBアクセスなしで検査した。
- 今回の許可は既存コマンドによるimport別観測追加、対応currentのagari3列補完、BatchRun/Item記録・採番・ロックと、保存前後照合/保存後dry-run。**本番業務値・監査履歴の書込みがある。**
- コード/Migration/設定は不変。補助PHPはリポジトリ外へ保存しphp -l成功後に使用。既存Artisan呼出しとREAD ONLY照合に限定し、Parser・補完保存ロジックは再実装していない。
- 既存pilot BatchRun 120、観測490/current490、VALID477/MISSING13は別実績として保持。pilotのコマンド・Raw解析・保存前dry-runを再実行していない。

### Executed Commands And Timing

各区間を昇順・逐次で、保存前snapshot → 正式実行1回 → 保存照合 → 保存後READ ONLY dry-run1回 → 不変確認の順に実行。
以下は実行済み最初の区間。後続は保存した `intervals.json` のfrom/toだけを変更した。再実行を指示するものではない。

```bash
DB_CONNECTION=pgsql \
PGOPTIONS='-c default_transaction_read_only=off -c lock_timeout=5s -c statement_timeout=5min' \
php -d memory_limit=512M artisan keirin:stat35:backfill-agari \
  --execute --from=2022-01-01 --to=2022-01-31 --chunk=100

DB_CONNECTION=pgsql \
PGOPTIONS='-c default_transaction_read_only=on -c lock_timeout=5s -c statement_timeout=5min' \
php -d memory_limit=512M artisan keirin:stat35:backfill-agari \
  --execute --dry-run --from=2022-01-01 --to=2022-01-31 --chunk=100
```

環境変数は子プロセス限定。全stdoutは逐次ファイル出力し、PHP自身の終了コードと実argvを保存。
子プロセス上限30分は各SQLのstatement_timeout=5minとは別。再試行・並列処理・独自DML・rollbackなし。

- 全体開始/終了: 2026-09-22 15:58:43.894883～17:07:00.259958 JST。
- 照合・pilot終端確認を含む所要4,096.365072123秒（約68分16秒）。文書更新時間は含めない。
- 正式コマンド48回の所要合計1,943.007422218秒、保存後dry-run48回の所要合計1,653.220918562秒。
- 全96コマンドexit 0、stderr 0 bytes、タイムアウト0。各開始/終了時刻・所要・exitは月別証跡に保存。
- 最大peak memoryは37,748,736 bytes（36 MiB）。peakは合計していない。ラッパー終了コードも0。

### Monthly Batch IDs

全48 BatchRunがSUCCEEDED。表の月は各年の暦月で、2024-12だけ12-31を含まない。
各月のsummary、実DB差分、status、gap/skip、対象ID、照合結果は `report.json` のmonthsと各月ディレクトリに保存。

| 月 | 2022 batch | 2023 batch | 2024 batch | 2025 batch |
|---|---:|---:|---:|---:|
| 01 | 121 | 133 | 145 | 157 |
| 02 | 122 | 134 | 146 | 158 |
| 03 | 123 | 135 | 147 | 159 |
| 04 | 124 | 136 | 148 | 160 |
| 05 | 125 | 137 | 149 | 161 |
| 06 | 126 | 138 | 150 | 162 |
| 07 | 127 | 139 | 151 | 163 |
| 08 | 128 | 140 | 152 | 164 |
| 09 | 129 | 141 | 153 | 165 |
| 10 | 130 | 142 | 154 | 166 |
| 11 | 131 | 143 | 155 | 167 |
| 12 | 132 | 144 | 156 | 168 |

### Formal Summary And Actual DB Deltas

success/skipped/failedはimport単位、NO_IMPORT系はrace単位。
観測はimport-version行、現在結果は採用importに対応するcurrent行であり、両者の件数一致は要求していない。
下表の追加/補完は正式summaryと実DB差分が各区間・各importで一致した**実保存件数**。dry-runやpilotの件数は混ぜない。

| 年（今回分） | success | skipped | failed | 観測実追加 | 現在結果実補完 | NO_IMPORT | NO_IMPORT_UNSUPPORTED |
|---|---:|---:|---:|---:|---:|---:|---:|
| 2022 | 24,824 | 41 | 0 | 173,847 | 173,847 | 3 | 0 |
| 2023 | 51,076 | 32 | 0 | 362,784 | 181,392 | 7 | 0 |
| 2024（12-31除外） | 25,526 | 14 | 0 | 181,357 | 181,357 | 9 | 0 |
| 2025 | 25,507 | 26 | 0 | 181,518 | 179,751 | 10 | 0 |
| 合計 | 126,933 | 113 | 0 | 899,506 | 716,347 | 29 | 0 |

### Persisted Agari Status

| 年 | 観測VALID | 観測MISSING | 観測INVALID_FORMAT | 観測OBSERVED_ABNORMAL_RESULT | current VALID | current MISSING | current INVALID_FORMAT | current OBSERVED_ABNORMAL_RESULT |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| 2022 | 169,678 | 4,168 | 0 | 1 | 169,678 | 4,168 | 0 | 1 |
| 2023 | 354,086 | 8,698 | 0 | 0 | 177,043 | 4,349 | 0 | 0 |
| 2024（12-31除外） | 177,154 | 4,202 | 0 | 1 | 177,154 | 4,202 | 0 | 1 |
| 2025 | 178,266 | 3,251 | 0 | 1 | 176,530 | 3,220 | 0 | 1 |
| 合計 | 879,184 | 20,319 | 0 | 3 | 700,405 | 15,939 | 0 | 3 |

MISSINGや異常結果観測を有効タイムへ補正せず、NULLと0を区別。decimal比較は文字列正規化で行いfloat化していない。
origin=BACKFILLED_FINAL_RESULT、publication_timestamp=UNKNOWN、historical_as_of_available=falseを維持する。

### Gaps And Skips

- skipは全113 importが既存契約のCANCELLED（2022:41、2023:32、2024:14、2025:26）。`formal-skips.jsonl` にimport ID/既存理由を保存。失敗importは0。
- NO_IMPORT 29 raceの月別内訳は2022-07:3、2023-01:7、2024-01:2、2024-02:7、2025-02:6、2025-03:4。他の月0、NO_IMPORT_UNSUPPORTEDは全月0。
- `gaps.jsonl` にrace ID/日付/race_type/既存カテゴリ/理由を保存。分類は既存RaceCategoryPolicyを使用し、SQLで別分類や固定期待件数を作っていない。
- 対象import 0の区間は0。既存の中止・対象外・未確定の全行へ観測保存を要求せず、import欠落やskipを成功件数に加算していない。

### Read-Only Verification

全48区間で保存前後23確認項目が成功。各snapshotは対象source/dateで限定し、主キー順・同列順・分割取得/ストリーミング、READ ONLY/REPEATABLE READで保存。

- 固定した対象import集合、summary、BatchRun/Item集合・件数が一致。BatchRunはSUCCEEDED、FAILED/RUNNING item 0。
- 観測追加/現在結果補完は実DB差分と一致。既存観測変更0、import＋bike重複0、出典不一致0。
- 補完currentは自身のimport＋bikeの観測とagari3列が一致。不一致0、非NULL既存値上書き0、import参照付替え0。
- race_resultsのagari3列以外は全列不変（ID、着順、状態、import参照、fetched_at、updated_atを含む）。非agari変更0。
- races/entries/payouts/importsと対応fetch metadataは全列不変。追加観測のRawパス・source/converted hash・URL・取得時刻・Parser versionは対応出典と一致。

保存後READ ONLY dry-runは全48区間でexit=0、failed=0、dry_run=true、batch_run_id=null、observations=0、current_updates=0。
正常照合successの合計126,933、CANCELLED skip113、NO_IMPORT29/NO_IMPORT_UNSUPPORTED0は正式実行と一致するが、正式件数へ二重加算しない。
dry-run後の対象業務行・観測・fetch metadata・当該BatchRun/Itemは全区間不変。対象import集合も不変。
状態は **ZERO_PLANNED_CHANGES_ALL_EXECUTED_INTERVALS**。正式書込みを再実行した検証ではない。

終端でpilotの旧 `after-snapshot.json` と対象9種の保存行をREAD ONLY比較し全件一致。
BatchRun 120・既存490観測/490補完・VALID477/MISSING13は不変。Raw再解析やpilotコマンド再実行はない。
pilotを1回だけ加えた統合実増分は **観測899,996 / 現在結果716,837**。今回分899,506/716,347とは区別する。

### Evidence And Stop Boundary

実在する今回の証跡ディレクトリ（既存証跡とは別、umask 077）:

```text
/home/shinya/neo-keirin-artifacts/stat35-production-backfill-2022-2025-01/run-20260922-155146-60eeb526/
```

`monthly-backfill.php`、`execution-contract.json`、`intervals.json`、`report.json`、runnerログ、`pilot-verification.json`/`pilot-end/`、月別証跡を保存。
各月にはbefore/after/postの全列snapshot・manifest、正式/dry-runのstdout全文・stderr・argv・SHA・時刻・exit・summary、検証JSON、追加/変更/gap/skip明細、完了状態を保持。
既存pilot証跡・Raw・旧正式成果物・backupを変更していない。backup取得/一覧確認成功・復元試験未実施という記録を維持し、再取得・再hash・復元なし。

今回の本番書込みは許可範囲の観測/current/batch監査のみ。Migration・構造28項目再監査・cron調査・旧監査・新規取得・同期は未実施。
STAT計算・学習・予測評価・2026開催レースのDB/Raw参照は未実施。2022-2025レースの2026年取得Rawは今回の読取り許可に含む。
全テスト/Pintは再実行せず、許可2文書だけの差分とgit diff --checkを確認。as-of回復・STAT採用・予測精度改善は主張しない。
次は今回の結果レビューだけであり、追加書込み・次実装は未承認。commit/push/PR作成/mergeせず、未コミットで停止する。

---

## Historical Pilot Record / PR #68

以下はpilot実行時の記録。PR #68マージでレビューは完了し、保存行は今回の終端照合でも不変。
他期間未承認・レビュー待ち等の過去記録を残し、最新の許可範囲・結果は上のCurrent Resultに記載する。

2026-09-22 JST: **2024-12-31だけの正式保存・READ ONLY保存照合・保存後dry-runが成功。次は結果レビュー。**
PR #67 MERGED、Migration結果レビュー完了。Migration batch 14の再適用・構造28項目再監査は行っていない。
開始main / 実行SHA: `15bb52de18eb5908a01d181d8177f33c0b1ca583`。
cleanなmainから `ops/stat35-production-backfill-pilot-01` を作成し、アプリコードは変更していない。

今回の最新ユーザー許可は、当日対象importの観測追加、currentのagari3列補完、BatchRun/Item記録と必要な採番・ロックに限定。
以前のNOT_AUTHORIZEDとwrite=0は当時の履歴である。**今回は業務値と監査履歴の本番書込みがある。**
他期間の書込み、正式STAT計算、学習・予測評価、2026レース参照は未承認のまま。

### Reviewed Dry-Run And Before Snapshot

前回証跡: `/home/shinya/neo-keirin-artifacts/stat35-production-backfill-dryrun-01/run-20260922-103047-97c266fc/`。
2026-09-22 10:33:10 JST、exit 0、0.753224769秒、peak 37,748,736 bytes、stderr空。
success/skipped/failed=75/0/0、予定observations/current_updates=490/490、NO_IMPORT/NO_IMPORT_UNSUPPORTED=0/0、dry_run=true、batch_run_id=null。
ユーザーがこの結果を確認し、同日保存を許可した。保存前dry-runは再実行していない。

今回の接続は `pgsql / 127.0.0.1:5432 / neo_keirin_prediction_db / public` と一致。
保存前後照会は接続開始からREAD ONLY、REPEATABLE READ transaction内。認証情報は表示・保存していない。
source=`keirin_jp`、race_date=`2024-12-31`だけを選び、主キー順で全列を外部snapshotへ記録した。
実schemaの列metadataも記録し、存在しない列を仮定していない。対象race ID/import IDを固定した。

| 保存前対象 | 行数 |
|---|---:|
| races | 75 |
| race_entries | 490 |
| race_results | 490 |
| race_payouts | 525 |
| race_result_imports | 75 |
| 対象importのagari観測 | 0 |
| 対応fetch metadata | 75 |

前回stdoutの75 import ID・Rawパス・source_hashは全件一致。前回記録にない項目の一致を推定していない。
現在結果490行のagari3列はすべてNULLで、既存保存の形跡なし。今回snapshotにはconverted_hash等の出典も保持した。
backup取得・一覧確認済み／復元試験未実施は維持し、backup再取得・再hash・一覧再取得・復元はしていない。

### Formal Command And Persisted Verification

次を1回だけ実行。環境変数は子プロセス限定で、設定ファイル・role・DB全体の設定は変更しない。

```bash
DB_CONNECTION=pgsql \
PGOPTIONS='-c default_transaction_read_only=off -c lock_timeout=5s -c statement_timeout=5min' \
php -d memory_limit=512M artisan keirin:stat35:backfill-agari \
  --execute --from=2024-12-31 --to=2024-12-31 --chunk=100
```

- 開始/終了: 2026-09-22 11:12:13.507981～11:12:14.689292 JST。
- PHP終了コード0、所要1.181310603秒、peak 37,748,736 bytes（36 MiB）、stderr 0 bytes。
- summary: success=75、skipped=0、failed=0、observations=490、current_updates=490、NO_IMPORT=0、NO_IMPORT_UNSUPPORTED=0、dry_run=false、batch_run_id=120。
- BatchRun 120はSUCCEEDED。AGARI_IMPORT 75 itemはすべてSUCCEEDED、FAILED/RUNNING=0。対象import集合・summary・batch/item件数が一致。
- skip理由・失敗importなし。success/skipped/failedはimport単位であり、レース数の定義ではない。
- import単位transactionの既存実装を使用。再試行・独自補完SQL・rollback・履歴削除・trigger無効化なし。

| 件数照合 | 前回dry-run予定 | 正式summary | 保存前後の実DB差分 |
|---|---:|---:|---:|
| 観測追加 | 490 | 490 | 490 |
| agari3列が変化した現在結果 | 490 | 490 | 490 |

観測/現在結果のagari_statusは、ともに **VALID 477 / MISSING 13**。490件すべてをVALIDタイムとは扱わない。
NULLと0を区別し、NUMERICは文字列のdecimal正規化で照合してfloat化していない。
各現在行自身のrace_result_import_id＋bike_numberに対応する観測との不一致0、import＋bike重複0。
レースの最新importへの付替えはない。各import別の保存増分も正式イベントと一致。

保存前後20確認項目は全て成功。以下を含む。

- race_resultsのagari3列以外の全列が不変。ID、着順、結果状態、import参照、fetched_at、updated_atも一致。
- 対象races/race_entries/race_payouts/race_result_importsの全列と参照fetch metadataは不変。
- 既存観測は保存前0件。上書き・削除はない。
- 追加観測のsource_hash・converted_hash・Rawパス・source_url・fetched_at・source_parser_versionが対応出典と一致。出典不一致0。
- origin=BACKFILLED_FINAL_RESULT、publication_timestamp=UNKNOWNを保持。

対象2024年レースのRaw取得日時が2026年であることは今回の許可範囲内。2026年開催レースの業務行・Rawは参照していない。

### One Post-Save Dry-Run

上記の実保存照合成功後だけ、次を1回実行した。2回目の正式書込みではない。

```bash
DB_CONNECTION=pgsql \
PGOPTIONS='-c default_transaction_read_only=on -c lock_timeout=5s -c statement_timeout=5min' \
php -d memory_limit=512M artisan keirin:stat35:backfill-agari \
  --execute --dry-run --from=2024-12-31 --to=2024-12-31 --chunk=100
```

- 開始/終了: 2026-09-22 11:13:22.343443～11:13:23.423929 JST。
- PHP終了コード0、所要1.080487291秒、peak 37,748,736 bytes、stderr 0 bytes。
- summary: success=75、skipped=0、failed=0、observations=0、current_updates=0、NO_IMPORT=0、NO_IMPORT_UNSUPPORTED=0、dry_run=true、batch_run_id=null。
- 対象75 importは不変。successは保存済みimportの正常照合を含み、0を要求しない。
- 追加・更新予定0件を確認。DB書込みを伴う再実行で冪等性を試したものではない。

### Evidence And Review Boundary

実在する証跡ディレクトリ:

```text
/home/shinya/neo-keirin-artifacts/stat35-production-backfill-pilot-01/run-20260922-110730-e4408a8c/
```

`pilot.php`、保存前後snapshot、前回入力照合、正式/保存後dry-runのstdout全文・stderr・argv・実行SHA・時刻・終了コード・summary、保存照合JSONを保持する。
既存証跡・Raw・正式成果物は上書きしない。保存前snapshotは同じ対象日の比較用で、全DB/全年backupではない。
状態は **SAVED_AND_VERIFIED_AWAITING_REVIEW（2024-12-31のみ）**。
2022-2025全期間完了ではない。他期間書込みと次実装はNOT_AUTHORIZED。
historical_as_of_available=false、PUBLICATION_TIME_UNKNOWN、既存C1・2026凍結・Goal 4/5未着手を維持する。
Migration、構造28項目再監査、cron調査、同期起動、新規取得、学習・精度評価は未実施。
2文書だけを更新し、全テスト/Pintは再実行せず、差分範囲とgit diff --checkを確認。未コミットで結果レビューを待ち、他期間へ進まない。

---

## Historical Migration Record / PR #67

以下はMigration実行時の記録。未承認・未実施・レビュー待ちは当時の状態として保持し、
pilot時点の1日分の限定許可・保存結果は上のHistorical Pilot Record、最新の結果はCurrent Resultを正本とする。

2026-09-22 JST: **APPLIED_AND_SCHEMA_VERIFIED。次は適用結果レビュー。backfill/dry-runは未実施・未承認。**
PR #66はMERGED。cleanなmain/origin `2715757a6952dbf32fc97f881945d94721a08282` から
`ops/stat35-production-migration-01` を作成し、最新ユーザー指示による対象Migration 1本のDDLとLaravel履歴登録だけを実行した。
今回の限定許可は旧NOT_AUTHORIZEDより優先するが、他Migration・業務DML・backfill・同期起動・2026分析を許可しない。

### Backup And Immediate Checks

取得済みbackup:

```text
/run/media/shinya/KEIRIN_BKUP/neo_keirin_prediction_db-20260922-083530-TI9oyc/neo_keirin_prediction_db.dump
```

- サイズ: 5,583,650,971 bytes。
- 記録済みSHA-256: `74918382a77ea243e9af1a21e2af1fe834d35c3d39c49fa46e62a0f5e6bbccbe`。
- 取得: 2026-09-22 08:56:08～09:01:39 JST。pg_dump / pg_restore --listともexit 0、警告なし。
- 今回は存在・読取り可否・サイズと既存report/checksumだけを照合。再取得・全hash再計算・一覧再取得・展開・復元はなし。
- **復元試験は未実施**。一覧確認成功を復元成功とは扱わない。先行する全DB保全許可は2026も含むが、データの表示・分析・モデル利用は禁止のまま。
- 対象接続: Laravel `pgsql`、`127.0.0.1:5432 / neo_keirin_prediction_db / public`。認証情報は表示・保存していない。
- 対象Migrationは未適用、agari3列・観測table未作成をREAD ONLYで確認。
- Migration SHA-256: `91f516f8e9a43138166ffd816635d5b9b7161343d64ef2f65cf6fca8f06eed90`、期待値と一致。
- DB/結果tableはpg_default。ローカル18/mainの格納先 `/var/lib/postgresql/18/main` は `/dev/nvme0n1p1` 上。実行直前の空き容量は `prerequisites.json` に記録した。
- 初回の確認はIPが `127.0.0.1/32` と表記される比較差で停止し、PostgreSQLのhost()で一致を確認した。次の確認ではdata_directory表示権限不足を記録し、既存ローカルクラスタmetadataで格納先を確認。権限変更・DB設定変更はなく、初回証跡も保持する。
- ユーザーは結果同期・手動importを起動していないと明示。cron/systemd再調査、サービス操作、他セッション終了はしていない。

### Executed Command And Result

次のコマンドだけを1回実行した。PGOPTIONSはこの子プロセスの接続限定で、role/DB/postgresql.confの設定は変更しない。

```bash
PGOPTIONS='-c default_transaction_read_only=off -c lock_timeout=5s -c statement_timeout=15min' \
php -d memory_limit=512M artisan migrate \
  --database=pgsql \
  --path=database/migrations/2026_09_21_000013_add_agari_storage.php \
  --force
```

- 開始: 2026-09-22 09:44:27 JST。終了: 09:44:28 JST。所要時間: 1.306743902秒。
- 終了コード: 0。stdoutは対象MigrationのDONEのみ（実行案内を含む）、stderrは0 bytes。警告なし。
- 適用履歴: `2026_09_21_000013_add_agari_storage`、id=17、batch=14。
- 前後履歴は16件から対象1件追加の17件のみ。他Migrationの履歴変更・追加なし。
- 対象DDLと当該履歴を書き込んだ。以前の本番write=0を今回の結果へ流用しない。
- 自動再試行、無指定Migration、seed、rollback、restoreは行っていない。

### Read-Only Schema Verification

接続開始時とtransaction内のREAD ONLYを確認し、schema metadataとMigration履歴だけを照合した。
`schema-verification.json` の全28項目は成功。完了判定はexit 0だけではなく、以下の一致を含む。

| 対象 | 照合結果 |
|---|---|
| race_results追加3列 | nullable NUMERIC（typmod=-1、precision/scaleなし）/ TEXT / VARCHAR(40)、defaultなし |
| 観測table全16列 | 名前・順序・型・nullable・defaultがMigrationと一致。idはbigint sequence、日時はtimestamp(0) with time zone |
| PK / UNIQUE | id PK、import＋bike UNIQUE、ともに有効 |
| INDEX | PK/UNIQUEを含む5本、列構成と定義一致、valid/ready |
| FK | race/importの2本、ON DELETE RESTRICT、validated。entry/playerへFK追加なし |
| CHECK | 両tableのagari状態・数値整合とbike 1～9の定義一致、全てvalidated |
| 不変trigger | agari_observations_immutable、有効O、BEFORE DELETE OR UPDATE、FOR EACH ROW |
| function | reject_agari_observation_mutation()、引数なし、RETURNS trigger、plpgsql、append-only例外の本文一致 |
| 既存構造 | race_resultsの既存14列と既存制約/index/triggerは前後一致 |

業務行の全件読取り・値digest作成・順位/払戻再集計・試験INSERT/UPDATE/DELETEは行っていない。
Migration内部の型変換・CHECK検証は許可DDLの実行であり、2026データの分析ではない。
本作業による通常同期や本番backfillの動作試験は未実施。schema適合と未実施の業務処理を区別する。

### Evidence And Stop State

```text
/home/shinya/neo-keirin-artifacts/stat35-production-migration-01/run-20260922-093636-fa14dd06/
```

before/after metadata、照会台帳、前提・容量・backup記録、実行コマンド・時刻・終了コード、stdout/stderr、構造照合を保存。
適用前の2件の確認停止と修正版スクリプトも別名で保持し、Migration自体は1回のみ。
backfill/dry-run/同期起動/2026業務データ分析は未実施。次は適用結果レビューだけであり、自動的にbackfillへ進まない。
historical_as_of_available=false、既存C1・旧成果物・Growth不採用・Goal 4/5・2026凍結は不変。
アプリ・Migration・テスト・設定は無変更。2文書の差分とgit diff --checkだけを確認し、未コミットで停止する。

---

## Historical Preflight Record / PR #66

以下はSTAT-35-PRODUCTION-PREFLIGHT-01当時の記録・手順を保持したもの。
当時の未適用・未承認・write=0は上記Migration実行前の事実であり、現在状態へ流用しない。
旧backup条件に対しては、後の明示許可による全DBbackup取得と今回限定のMigration承認を上記に記録した。
未実施のbackfill/dry-run・復旧手順の存在は、今回の実行許可を意味しない。

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
