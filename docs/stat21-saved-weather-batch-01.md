# STAT21-SAVED-WEATHER-BATCH-01

> 2026-11-01後続記録: ユーザー提示の全体実行は4,534 passed/46,563 assertions/155 warnings/9 skipped、390.28秒、失敗表示なし。明示的exit code・環境変数状態は未提示で、Codexの限定169件/全体NOT_RUNと区別する。その後8faa7feでユーザーcommit/push、PR131はa25fcede1b9ba71ffa8b2ff293613340042ca779でmainへmergeされたことをローカル履歴・親・treeで確認。以下の未マージ/未コミット/NOT_RUNや初版FAILEDは当時の記録として保持する。天候の再build/reproduce/Raw解析は行わず、後続は[固定STAT統合受入](stat-existing-data-integrated-acceptance-01.md)。

## 0. PR131修正記録 / 2026-11-01

開始cleanな既存 `feature/stat21-saved-weather-batch-01`、HEAD `efa3cc2212382457776f480bba70ec0c84d7fcc5`、ローカルorigin/main `0de4bdd85d8df0ccd15ad835d360a5d1a5e57fdd` を確認。fetch/外部PR照会なし。PR131は未マージとして扱い、add/commit/push/PR操作は行わない。以下1～7節は初版当時の履歴であり、旧BLOCKED/FAILED・数値・sealは変更しない。

### 0.1 修正範囲と契約

| 指摘 | 修正と直接回帰 |
| --- | --- |
| F1 | `SavedWeatherResultFixture` で未設定/空のみ既知の封印fixtureへ解決。明示パス優先、存在/読取り/SHA検証。不在/異なるSHAでfallbackせず失敗。環境変数はfinallyで復元 |
| F2 | `Snapshot::scope` は元JSONLの主キー集合を正本とし、余剰/欠落/重複/同数入替え/保存内容の差を件数付きで拒否。LEFT JOINの対応欠落も分母から落とさず拒否。NULL/省略/既存数値・日時規則は維持 |
| F3 | `Repository` は参照importをIDで取得してからrace所属を検証。別raceに実在すれば準備失敗、本当の欠落のみ通常未採用。既存import故障分岐をDataProviderへ追加 |
| F4 | `Prepare::links` の参照比較例外は元NULL/省略行だけ。混在レースの既知参照変更でSOURCE_ROW_DRIFT、補完0。安定した混在正常補完、A単独旧版保持も検証 |
| F5 | `insertMissing` は双方のJSONをdecodeし、抽出契約のdispを型込みで比較。他キー/キー順/空白のみの差は許容、旧完全JSONは不変。disp・SHA等の実質差は拒否。比較/新規投入まで隔離SQLiteで検証 |
| F6 | `Writer` の小さい書込み境界を導入。計算件数/calculated、レース出力まで確定したprojected/saved、部分的なprojection_rows_writtenを分離。保存障害は型で検出し、失敗後の同stream再書込みなし。全固定分母・失敗・後続未処理を返し、部分ファイルを保持。投影初回/正常後/台帳/レース出力/出力全体障害を注入して検証 |

`OfficialResultEvidence\Verifier` に旧Readerの取得元・固定本人・Raw・結果一致・確定/訂正・時刻・取消照合を抽出した。FINISHED/TIEDはrank1～9必須、非完走等はrank=NULLの構造条件も維持する。旧Readerの公開引数と取消/latest/証拠不足は維持し、共通照合後も**未変更の** `Contract::outcome()` を呼ぶ。天候Serviceだけは決まり手意味判定を使わない。DB/Rawの着順・状態・決まり手不一致を許容する変更ではない。

外側は `STAT-21-SAVED-WEATHER-BATCH-v2-PR131`、内側の天候v1・Raw原値型・0/0.0/文字列0/欠落/NULLの意味は不変。単位未検証、気象実観測時刻NULL、RETROSPECTIVE_ONLY、asof=false、予測利用禁止、points/confidence=NULLを維持。Parser/RawReader/Hasher/schema/Model/設定/他STATの式や保存形式は変更しない。

### 0.2 旧資料検証と新入力

新証跡rootは `/home/shinya/neo-keirin-artifacts/stat21-saved-weather-batch-01/pr131-fix-20261101-VvLc1pOD/`。コード変更前に旧manifest20,285bytes / `4199553e3cc591e8e6ad51acd86fb2d2117602e2ec518acf1dbf413c84a91dff`・COMPLETE・依存データ/旧コードを検証し、開始commitのGit blobと一致する旧コードを `baseline/code/` に保存した。

`LegacyInput` / `Derive` と薄い `derive-saved-result-weather` Commandは今回指定された全A資料専用。旧sealと固定Git blobを再検証し、全親業務/統計行・旧メタデータ45件・export/sidecarによる追加import/log各25,908件を照合する。B/C実補完0。旧SQLiteはmode=ro/query_onlyで再利用し、DB再抽出・コピー・旧manifestの再封印なし。新scope/候補を再生成し、旧コード証拠と現在87依存コードを分離して記録。通常Store::inputも現在コードsealを必須にする。

新入力manifestは `derive-logs-SzXZG2Gc/input/manifest.json`、19,823bytes / `4465e2656d3d72ff8768c8d041248eec62248b396634e314b7d862ab9b6a425a`。25,976race /184,353entry /184,196result、元JSONL対SQLiteの余剰/欠落/重複/内容差0。新scope SHAは旧と同じ `6cb63c7bf7c287eca8ce35fd7772c78bc90f8a403243b8460f5463b47b5128b5`。旧統計153run /1,177result /153itemと全業務行は不変。元DB2接続は初版当時のみで、今回0接続・0書込み。

### 0.3 直接再現とテスト

- 旧コードは作業後に書き換えて再現せず、開始commitの保存済みGit blobを別PHPで実行。F1の未設定getenv式はTypeError、F2は余剰race/entry/result・JSONL重複を誤受入、F3は別race importをIMPORT_NOT_EXTRACTED、F4は既知参照変更を見逃してNULL行を誤補完、F5はdisp一致・他キーだけの差をEXISTING_ID_CONFLICTとして拒否した。F6の旧原因は保存前カウンタ加算のコード確認であり、旧版への障害注入実行とは称しない。
- race38320 / entry272931 / result272053 / import38550 / log104236を実物確認。Raw90,079bytes / `747a8a1d271616baff8a4851125aa99ef9aabcbe1750676b09d938d346d91a79`、DB/Raw双方1着・FINISHED・マーク。共通照合/天候投影は成功し、旧Readerは `Contradictory official rank/status/method.` で拒否を維持。公式ルールや原結果の正誤認定ではない。
- 最終の安全な限定回帰: **169 passed /4,084 assertions、failure/error/warning/skip0、exit0、5.10秒**。新専用81件/1,511 assertions＋Binding15件/58 assertions、既存決まり手/Parser等73件/2,515 assertions。Artisan/PHPUnitとも128M、peak31,457,280 /67,633,152bytes。STAT38_RESULT_FIXTURE未設定で実行し、既知の実fixtureパスを隔離環境にもread-only配置した。runnerのclearenvに同変数の設定はなく、解決経路を検証する一時的な変更はfinallyで復元。JUnitのdata providerを含む全81ケースはそれぞれ2以上のassertionsを実行し、hash_file(false)で共通停止していないことを確認した。これは対象クラスの確認であり、過去の通常全体34 failedの全解消を意味しない。
- その前の203件/4,501 assertionsも成功したが、対象外の旧AgariC1ContextTestにはunlink/rmdirによる後処理が残っており、人工tmpfs内で動作した。原本はroで書込み遮断。これを安全helper利用の証明や永久削除0と記録しない。以降の最終限定集合から外し、旧helperの無断一括改変は行わない。
- 通常全体は、旧GrowthTrendFixture等の永久削除経路とAGENTS.mdの衝突により **NOT_RUN**。当初は判断待ちだったが、最新ユーザー回答により、修正指示第9節の通常全体1回実行は**今回に限り保留承認済み**（USER_APPROVED_THIS_TASK_ONLY）。通常全体としての回帰は **NOT_CONFIRMED**。対象外helperの改変、新skipや警告抑制、対象を除外した実行による全体成功の代用は行わない。
- 初版限定161件/4,135 assertions → ユーザー通常全体34 failed /155 warnings /9 skipped /4,454 passed /45,098 assertions → 今回の限定結果、という順序を保持する。過去155 warningsの全原因・全解消は未確認。

実コマンドは新rootの各 `runner.sh` / `command.txt` / stdout/stderr / JUnit/events / measurementに保持。限定実行は `php -d memory_limit=128M artisan test` に対象7ファイル・子の `-d memory_limit=128M`・既存memory bootstrap・`--display-all-issues` を指定したArtisan/Collision経路。実資料は同梱phase.phpからServiceを呼び、各PHP128M・ネット遮断・原本roで実行する。

保留の根拠として既に確認した箇所と直接呼出し元（網羅調査ではない）:

| 対象外の旧処理 | 確認した呼出し元 | 残件 |
| --- | --- | --- |
| `tests/Support/GrowthTrendFixture.php:174` のdeleteDirectory | GrowthTrendAnalysisTest:42 / GrowthTrendScoreSourceTest:33のcleanupFixture | 通常全体を実行する前の安全性整理 |
| `tests/Support/StartObservationFixture.php:80` のunlink | StartCountSnapshotTest:346のseal | 同上。今回の修正・実行対象に追加しない |
| `tests/Support/C1Stat35RepackageFixture.php:31` のunlink | CompositionPredictionRequestTest:58 / C1Stat35RepackageCommitTest:51のmake | 同上。既存テスト・helperは変更しない |
| `tests/Feature/AgariC1ContextTest.php:45,47` のunlink/rmdir | 同クラスtearDown | 上記先行限定実行の事実を保持。安全な通常全体として扱わない |

保留承認は安全ルールの緩和や旧helper改修の承認ではない。今回の承認反映ではコード・テスト・実資料処理を再実行せず、保存済み証跡を確認して文書と小型レビュー資料だけを同期した。

### 0.4 今回の実受入

**機能修正・安全限定テスト・固定資料受入はCOMPLETE。最新ユーザー指示で通常全体は今回に限り保留承認済みのNOT_RUNとし、今回の修正はREADY_FOR_REVIEW。通常全体としての回帰はNOT_CONFIRMED。** 保留承認前の全体検証不足は旧報告に保持し、全体成功や過去34失敗/155警告の全解消へ読み替えない。

| 固定分母/分類 | 今回の実測 |
| --- | ---: |
| race / entry / result | 25,976 /184,353 /184,196 |
| 実import / 説明枠 / 候補合計 | 25,953 /23 /25,976 |
| PROJECTED / EXCLUDED / FAILED / NOT_PROCESSED | 25,953 /23 /0 /0 |
| 未採用理由 | 中止14、結果なし9 |
| W45内 / 追加分の投影 | 45 /25,908 |
| 2024年 | 25,601投影、23未採用 |
| 2025年（固定範囲は1月4日まで） | 352投影 |
| tenki PRESENT | 25,953 |
| husoku 明示0 / 正の数値表記 | 2,715 /23,238（単位未検証） |

dry-runは保存0、build/replayは論理saved・物理projection_rows_writtenとも25,953。固定候補総数＝25,953+23+0+0、レースの分類分母も25,976。Raw累計2,319,604,683bytes（複製0）。同じ本人・同じ取得版を使い、固定全候補を説明できた。

| 工程 | 秒 | PHP peak bytes | limit / exit |
| --- | ---: | ---: | --- |
| 安定runnerのdry-run | 194.036 | 31,457,280 | 128M /0 |
| build | 193.649 | 31,457,280 | 128M /0 |
| Store再読込み | 0.782 | 25,165,824 | 128M /0 |
| 別PHP replay | 193.660 | 31,457,280 | 128M /0 |
| 独立監査＋END照合 | 30.887 | 44,040,192 | 128M /0 |

build/replayは別々のCOMPLETE・manifestを作成し、StoreでVERIFIED。投影42,030,747bytes / `96c547a7f0919a4ac9fae09b3c5ef001a6546b59e3343e693bc8474f6416d1e3`、候補台帳16,852,064bytes / `de2f68953c5ab9a6c0bebdc0e9d70227e951b3f1602ddab232e123d75b76ed70`、レース台帳3,870,286bytes / `68df64130ae3153ea35c8ec93d74aa9870314ba7fa0179ecb59555ff61ddcc87` の3 JSONLがbyte一致。時間・peak・実行先path等には無条件のbyte一致を要求していない。

旧857件は同じcandidate/固定本人/import/logのv1全体が857/857一致。旧FAILEDは保持した。旧W45は旧本人のまま45/45全v1一致（既知の派生numeric_tokenシリアライズ差だけを合わせ、Raw原値の型は変えない）。晴28/曇12/雨5、husoku正42/明示0が3も一致。
独立監査はBatch/fields()を使わず、採用25,953 Rawの51,906値について存在・原値・型・状態・numeric_token・単位未確認を一致確認。EncodingConverter/EmbeddedJsonExtractorは共有するため、HTML抽出まで独立とはしない。旧行/追加メタデータ/入力/87コード依存のEND seal一致、統計run追加0。現行コード一覧の識別hashは `c7e4f377c9e69e2f82857fb8356fb159abe3d141cf74054317b915068a72e4ec`。

初回dry-runはPHP実処理exit0だったが、実行中の外部runnerを編集したためshell終了記録127・stdout欠落となった。旧ログとsummary/resourceを保持し、安定runnerで再確認してPHP・shellともexit0を得た。PHP失敗や正常な初回終了記録へ読み替えない。

小型提出物は新rootの **`review-approved-hold-20261101/`** 内の `review-packet.txt`（実集計内容）、`review-summary.json`、`limited-test-summary.json`、`full-suite-status.json`、`static-and-diff.json`、`coverage.csv`、`changes.patch`、`review-manifest.json`。保留承認前の `review-final/` とさらに前の報告は上書きせず保持する。最初の報告用隔離ではbootstrap/cache/.gitignoreが隠れ、Git差分上だけ余分な削除を記録した。実ファイルは不変。当該ファイルのro bindを戻した別版から承認後の版へ記録を引き継ぐ。コード/実資料受入には影響しない。巨大JSONL/SQLite/Rawは提出用コピーへ入れない。実資料受入は完了したので曖昧な天候対象拡大を未実装残件とせず、通常全体の安全問題・固定範囲外検証・気象入力/方式/配点の未確定を分離する。次は未コミット差分と証跡のレビューだけ。本番/予測利用・正式採用・2026開催/B1は開始しない。

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
