# STAT21-SAVED-WEATHER-BATCH-01

## 1. 状態と範囲

2026-10-31、PR130 MERGEDをローカルmerge履歴で確認。開始clean main/HEAD/ローカルoriginは `0de4bdd85d8df0ccd15ad835d360a5d1a5e57fdd`、レビューhead `44944f69e5adf243f3d71f3e08781beb13acb82f` とのファイル差分0。fetch/外部GitHub照会なし。作業branchは `feature/stat21-saved-weather-batch-01`。

**機能は実装・限定テスト済み。固定集合の実資料受入は未完了、BLOCKED。** 固定本人が既存Readerの結果契約で拒否され、保存処理はFAILEDで停止した。成功した857件を全件成功や正式成果物の公開と扱わない。旧学校接続/STAT09の確定2軸の完了は維持する。

原本STAT21は発走前の気象/風向/走路適性を要求する。本工程はPJ0326保存原項目の事後投影だけで、SCR-STAT-21-01/02/03（原本1245～1320行）のOPENは不変。原本の実在参照は前工程の `source-references.json` から照合した。単位、観測地点/実観測時刻、風向、路面、温湿度、適性式/配点は未完成。historical_as_of_available=false、prediction_use=NOT_AUTHORIZED、points/confidence=NULL、2026開催凍結、B1未承認を維持する。

## 2. 製造した機能

`app/Domain/Keirin/Statistics/SavedWeather/`:

- `Snapshot`: 元JSONL/生成SQL/SQLiteの記録列を照合し、天候読取り前に固定scopeを保存。省略と明示NULL、非NULL参照を分離。
- `MetadataExporter`: 既存ReadOnlySession/ConnectionGuardを再利用し、100レース単位の固定ID/source/日付/場/R/外部ID条件で不足メタデータを逐次抽出。encp探索なし、リクエスト引数はdispのみ保存、不透明なrequest_keyは実値を保持。
- `Prepare`: 必要時だけ隔離SQLiteへ補完、旧値/新値/出典をsidecarへ記録。Aは固定旧参照、B/Cは保存済み全項目/本人集合/件数の一致を要求し、SOURCE_ROW_DRIFTは補完しない。原本/統計行を更新しない。
- `Repository`: レース/import/logを取得版キーとし、全importと結果なしの枠も保持。複数参照は未採用。車番→entry ID順で本人を固定し、失敗時fallbackなし。
- `Batch`: 入力sealをSTART/END検証し、隔離SQLite query_onlyで既存v1 Serviceを呼ぶ。v1のrace/本人/import/log一致を保存前確認。dry-runは出力・DB保存0。
- `Store` / `Files`: 排他的新規保存、0/0.0/文字列/NULL/欠落を維持し、ファイルseal/終端/固定候補列挙/分母/余分行・欠落を検証。

新契約は `STAT-21-SAVED-WEATHER-BATCH-v1`、入力は末尾 `-INPUT`。内側は既存 `STAT-21-SAVED-PJ0326-RAW-FIELDS-v1` のまま。Reader、RawReader、Parser、v1 Service、汎用Hasher、20項目、他STAT、schema、Model、設定、安全helperは変更していない。

COMPLETEは全件分類済みかつ完全性/技術失敗・未処理0の場合のみ。今回の本処理/replayはFAILEDであり、Storeは成功束としての読込みを拒否する。人工正常系では保存/再読込まで成功した。

## 3. 固定入力とREAD ONLY補完

証跡root:

`/home/shinya/neo-keirin-artifacts/stat21-saved-weather-batch-01/run-20261031-Ry7iILJL/`

親manifest `0c220b6ea0e406f426f7a0d8387a6aa60db2db64d4ccfc322f93552e71f7f273`、親SQLite196,718,592 bytes / `6ae8c40b468374d593d01da3d0fe38eb464bec55a8fe883735e98a9dbe790605` を実測照合。親198ファイルのsealを検証した。

元snapshotの実測: 2024-01-01～2025-01-04、25,976レース、184,353出走、184,196結果。生成は元主キーを含むSELECT r/e/v.*、直接insertで日時を変更していない。結果184,196行すべて非NULL import参照を保持（A）、省略/明示NULLはともに0。今回B/C実資料補完は0、人工テストで経路を検証した。

394,525業務行を元JSONLと照合。NUMERICの`87.20`→SQLite数値`87.2`等だけ明示的に正規化し、日時はUTC microseconds、NULLは厳密比較。原文は原JSONL/sealに保持。固定scope224,123,157 bytes / `6cb63c7bf7c287eca8ce35fd7772c78bc90f8a403243b8460f5463b47b5128b5`。

補完では本番PostgreSQLへ**2接続/各1つのREPEATABLE READ・READ ONLY transaction**を実行した。接続先127.0.0.1:5432/public/neo_keirin_prediction_db、default/transaction read_only=on、statement_timeout=5min、lock_timeout=5sを実測。自動再接続/リトライなし、各終了時rollback/切断、元DB書込み0。

最初の事前照合は小数の型差でDB接続前に停止。次の抽出は25,908 import/logを取得したが、request_key未抽出によるローカルNOT NULLエラーで投入をrollback。既定値で埋めず、抽出列を修正した新実行で同数を取得・補完した。これらの失敗ログ/未公開コピーも保持し、成功実行の1接続だけだったと報告しない。SQLite作業コピーは失敗版1個と受入入力版1個の計2個が残る。元入力/旧証跡は不変。

受入入力には旧45件を維持し、25,908 import＋25,908 logを追加した。結果参照/本人/時刻の変更0、旧153 run/1,177結果/153 itemは全行一致、新統計run/item/resultは0。追加表を「全行不変」と呼ばず、旧行不変・追加行をsidecarと照合する。

入力 `prepared-accepted/manifest.json` は20,285 bytes / `4199553e3cc591e8e6ad51acd86fb2d2117602e2ec518acf1dbf413c84a91dff`。入力準備のCOMPLETEと、本投影のFAILEDは異なる終端である。以降は元DB接続なし。

## 4. 実資料の停止結果

| 分母/状態 | 実測 |
| --- | ---: |
| 固定レース | 25,976 |
| 実import取得版 | 25,953 |
| importなしの説明枠 | 23 |
| 候補台帳合計（取得版＋説明枠） | 25,976 |
| メタデータ段階でRawが存在した取得版 | 25,953（v1適格性の確定ではない） |
| PROJECTED | 857（W45内3、追加854） |
| FAILED_INTEGRITY | 1 |
| NOT_PROCESSED_AFTER_FAILURE | 25,118 |
| EXCLUDEDとして処理済み | 0 |

準備時点の説明枠はCANCELLED_NO_CURRENT_RESULT_ANCHOR=14、NO_CURRENT_RESULT=9。これら23枠も停止後の未処理25,118に含まれ、未採用として処理完了したとは扱わない。857+1+25,118=25,976。

2025年352投影、2024年505投影/1失敗/25,118未処理。投影対象Raw累計75,188,119 bytes。tenki PRESENT857、husoku正の数値表記739/明示0表記118（単位未検証）。本処理128M/peak31,457,280bytes、6.430180秒、exit1。dry-runもexit1/実保存0。正常な追加全件の受入/全体coverage完成ではない。

停止対象は2024-01-07/race38320、entry272931/車番1、result272053、import38550、log104236。**DBとRawはともにrank=1、FINISHED、マークで一致**。不一致を検出したという初期仮説ではなく、既存 `OfficialMethodHistory/Contract::outcome()` が「マークは2着のみ」を要求し `Contradictory official rank/status/method.` を投げたことが直接原因である。Raw SHA `747a8a1d271616baff8a4851125aa99ef9aabcbe1750676b09d938d346d91a79`、90,079 bytesは一致。原データの誤りや訂正の要否は未確認。

この既存契約の緩和・本人切替・通常欠損への変換は今回行っていない。以降の全Raw走査/再学習/再評価へ進まず、部分出力とFAILEDを保持する。

## 5. 再現・独立照合・テスト

- 別PHPもexit1、同じ停止対象・分類と857投影。candidate/race/projectionsの3 JSONLはbyte一致。成功束のreproduceとは区別する。
- Batch/fields()を使わない原項目照合は857 Raw/1,714値で一致。存在・原値・型・状態・numeric_token・未確認単位を検証。CharacterEncodingConverter/EmbeddedJsonExtractorを共有しており、HTML抽出まで独立とはしない。
- W45は旧本人/import/logをそのまま使い45/45全v1一致。旧JSONは派生numeric_tokenの0.0等を整数表記へ保存していたため、旧シリアライズ形式で比較し、旧ファイルを再封印していない。新batchはJSON_PRESERVE_ZERO_FRACTIONを使用。晴28/曇12/雨5、husoku正42/明示0が3を実測再確認した。
- 新機能と直接依存の限定161 passed/4,135 assertions、failure/error/warning/skip0、128M/exit0。新規50件/1,163 assertions、既存111件/2,972 assertions。Artisan peak31,457,280/PHPUnit peak67,633,152 bytes、最終時間は小型集計参照。人工境界と既存実6レース回帰は別であり、人工取得日時を実資料の時点証拠にしていない。
- 全体テスト/全STAT再計算/学習/予測/HTTP/2026開催参照0。過去の全体warnings解消は主張しない。最終メモリ/時間/テスト集計・構文/Pint/diff/文書検証の正本は小型レビュー資料を参照。

## 6. コマンドと証跡

再利用可能なアプリCommand（例示。元DB補完を既定で行わない）:

```bash
php -d memory_limit=128M artisan keirin:statistics:prepare-saved-result-weather \
  --parent-manifest=/absolute/parent/manifest.json --parent-sha256=SHA256 \
  --snapshot=/absolute/snapshot --existing-raw-root=/absolute/w45/raw \
  --raw-root=/absolute/private --output=/absolute/new-input
# 必要時のみ --supplement-read-only を明示する。
php -d memory_limit=128M artisan keirin:statistics:build-saved-result-weather \
  --input=/absolute/new-input/manifest.json --input-sha256=SHA256 --dry-run
php -d memory_limit=128M artisan keirin:statistics:build-saved-result-weather \
  --input=/absolute/new-input/manifest.json --input-sha256=SHA256 --output=/absolute/new-output
php -d memory_limit=128M artisan keirin:statistics:build-saved-result-weather \
  --verify=/absolute/new-output --manifest-sha256=SHA256
```

実受入は同梱runner.shから `php -d memory_limit=128M stage.php LOG_DIRECTORY prepare|dry-run|build|replay|audit` としてアプリServiceを呼んだ。prepare以外はbwrapネットワーク遮断、原本read-only。テストはArtisan/Collision経由、人工.env/storage・SQLite・人工Desktop/Trash・既存rename-only helper、PHPUnit子も明示128M。実際の全コマンド/stdout/stderr/時刻/exit/resourceは各ログに保存した。

`summary.json`、`coverage.csv`、`limited-test-summary.json`、`supplement-summary.json`、`reproduction-summary.json` と実内容を連結した `review-packet.txt` が小型提出物。大きいSQLite/JSONL/RawはGitや提出用コピーへ入れない。manifestは元入力・旧資料・コード・小型報告・差分を追跡する。

## 7. 残件と次のレビュー

固定集合の一括処理機能は製造済みだが、全件実受入は既存結果契約で停止したまま。未処理25,118を「入力不足」や「完了」へ置換しない。次は本差分と「1着・マークを拒否する固定Readerを天候投影へ適用する契約」のレビューのみ。今回その契約を変更する承認はなく、実処理を継続しない。

全46台帳の具体的残件4分類は[10.1/10.2](stat-existing-data-completion-01.md#101-既存資料確定仕様でなお製造可能な残件)を参照。学校/09の完了、20旧版、6条件式、既存モデルは維持。今後の終了済み2026レースの時系列検証というユーザー方針は維持するが、今回開始しない。未コミットでレビュー待ち。
