# STAT-36-OBSERVATION-01

> PR #80修正: 現コードは署名専用正規化v2。以下の実データ件数・16成果物一致・manifestは旧v1の履歴であり、今回再実行していない。v2の人工検証は末尾に分離して記録する。

## 範囲

開始main/origin・開始HEAD: `04429ec9a2df9a56fc37d57cd15f8dd1b6371411`。PR #79はレビュー後マージ済み。
cleanなmainから `feature/stat36-observation-01` を新規作成した。
今回のユーザー指示による、固定台帳内の2022-2025保存Rawのoffline観測抽出だけを行う。
既存production Parser/DTO/Repository、C1、STAT35 mean6、旧成果物は変更しない。
旧C2の追加Gate NOT_PASSEDとC1維持は不変。STAT35全体を不採用とは扱わない。

DB/HTTP、2026レース、学習/予測生成/評価/CI/Gate、Migration/backfill/新規取得は禁止。
取得日時が2026であっても、台帳上2022-2025のレースRawだけが今回の対象。
`historical_as_of_available=false / prediction_use=NOT_AUTHORIZED / points=null`。
共有要件のSTAT36抜粋だけを引き継ぎ、未取得の要件書全体を確認済みとはしない。

## 固定資料と確認根拠

既存root: `/home/shinya/neo-keirin-artifacts/stat35-data-readiness-audit-01-20260921-01/`。
固定bundle: `stat35-agari-readiness-2022-2025-pr64-review-fix-01`。
旧失敗runや任意の最新runへ切り替えない。
契約 `STAT35-DATA-READINESS-v3-PR64-REVIEW-FIX`、既存LOCKED/manifestを確認。
manifestは8,875 bytes / `bd7ea209724bb1848ad4a44b1c9930bb0a5bb9c23806b0183eb0c6c07f9710a1`。
この受入済みsealをpinとし、database/raw-source inventoryと各sidecarを既存検証器で照合する。
新たなhash計算だけを受入根拠にせず、旧agari抽出・as-of計算・DB executeは再実行しない。

保存済みpreinspection 52ページ、header-preinspection 71ページを参照した。
今回の小標本は台帳順の各年先頭2import、計8件。勝者/着順/性能から選択していない。
import: 12547、12814、38077、38085、63692、89246、114816、114825。
各Rawのoriginal/converted hashを照合し、PC0201識別、PJ0326候補field、HTML見出しを確認した。
サンプルの参照・hash・最小断片は今回rootのpreinspection.jsonに保存する。

確認できた構造:

- PC0201.C0201dataのselKaisai / selKjyoCd / selRaceNoがレース識別。
- `#rrDispTyakuJyun`の12見出しには「H/B」「個人状況」がある。
- `#rrTableTyakuJyunBody`は空。結果行はPJ0326.tyakujyunItemSubData内のnamed object。
- 候補fieldはBH、inLineJyuni、kojinStateItemSubData[].kojinState。原値と存在状態を保持する。
- 小標本ではBHの空欄/H/B/HB、inLineJyuniの空欄/3、個人状況の空配列/空文字/落車棄権/失格を観測。
- HTMLは外部JavaScriptを参照しているが、その表示処理・Sの定義は受入済み台帳にない。外部scriptは取得しない。

このためfield名や列名だけからスタート取得・初手位置との対応を認定しない。
人工FixtureのSは文字列抽出の検証用であり、実サイトでSの意味を確認した根拠ではない。

## 抽出契約

`STAT36-OBSERVATION-v1-DISPLAY-ONLY`。専用namespaceはStatistics/StartObservation。
既存RawReader・文字コード変換・EmbeddedJsonExtractor・seal/writerを再利用する。

- 粒度はimport版×race×Raw行。正常対応時のbike/外部6桁IDは文字列の先頭0を保持。
- 個人状況field内の完全一致`S`だけを表示観測として識別。S1/S2/S_CLASS、全角、前後空白、名前/任意文章のSは受理しない。
- `observed_s_display`は保存された候補fieldの文字列観測であり、`start_acquired`ではない。
- 空配列にS表示がないことを記録しても、スタート取得なしと確定しない。空文字/NULL/field欠落/未知表現/schemaを分ける。
- 現版の`start_acquired`はすべてNULL、interpretation_statusはUNKNOWN_POSITION_DEFINITION。
- 初手はMISSING_INITIAL_POSITION。BHやinLineJyuniからFRONT/MIDDLE/REAR・隊列・初手順位を作らない。
- レース/本人不一致、車番重複、部分車番集合、未知schema、取消空結果/非空部分行、未掲載を別監査に保持。
- FINISHEDで絞らず事故・失格の表示も保持。rank、winner、払戻は計算変数/正規化出力に使用しない。
- importを統合・latest選択しない。同一race/bikeのrevision_keyと表示signatureで版差を追跡。
- 取得日時は保存fetchの時刻、公式公開日時は不明。処理日時はexecution.jsonへ分離する。

台帳の年をRaw読取り前に検証。原bytes・converted hash・import/raw台帳対応・終了時台帳/codeを検査する。
破損JSON/Raw/seal・範囲違反はfail closed、失敗証跡を残しCOMPLETEを作らない。
未知表示は原値・理由とともに未解釈とし、欠損に偽装しない。
ローカルSQLiteは重複/版数の索引専用。アプリDB/HTTPはCommandでprocess-local拒否。

## 出力と実行

root: `/home/shinya/neo-keirin-artifacts/stat36-observation-01/`。
今回run: `run-20261001-PWIOV1BU`。既存資料・旧Rawを上書きせず、新規ディレクトリだけへ出力。

```bash
php -d memory_limit=128M artisan keirin:stat36:observations plan
php -d memory_limit=512M artisan keirin:stat36:observations build --output-dir=RUN/result
php -d memory_limit=512M artisan keirin:stat36:observations reproduce --output-dir=RUN/reproduction --original-dir=RUN/result
```

RUNは上記root/runの絶対パス。runner.phpはtesting/SQLiteと独立config-cacheパスを子processへ設定し、
stdout/stderr、正確なargv、開始/終了時刻、所要時間、終了コードを保存する。共有.env/cacheは変更しない。
実データ512Mと独立bounded-memoryテスト128Mは別条件。

contract、年別observations/unresolved、import-audit、source-references/source-manifest、coverage JSON/CSV、
固定順examples、verification、manifest/COMPLETEを保存する。Raw本文は複製しない。
生成時seal・公開前検証、元資料から再解析する独立再現を行い、コピーを再現とは扱わない。
表示保存が成功しても、全値未解釈ならSTART_SEMANTICS_UNCONFIRMED_NOT_USABLE_STAT36とする。
履歴窓・率の分母・最新訂正版採用・C1投入は未決定。次工程へ自動移行しない。

## 検証・実行記録

### テスト

- 新規39ケース。表示の完全一致/不存在/空欄/NULL/未知schema、ID/車番/別レース/重複、全import版、訂正表示を検証。
- 中止の空/非空、未掲載、事故/失格、順位/払戻非依存、JSON破損、原/変換hash、CP932/不正UTF-8を検証。
- 2026レースのRaw読取り前拒否、固定manifest改変、DB/HTTP遮断、上書き拒否、独立再現・再現元改変拒否を検証。
- 独立128M PHPで100MiB超の人工入力、12,000レース/60,000観測を実処理。exit0、別PID、実memory_limit=128M、peak<128MiBをassert。
- 関連155 tests / 886 assertions PASS。
- 最終PHPコードの通常 `php artisan test --colors=never` 1回: **2,359 passed / 9既存skipped / 19,883 assertions**、exit0、184.975669秒。新規skipなし。
- 限定Pint9 PHPファイルPASS、同9ファイルのphp -l PASS。production Parser/DTO/Repository/旧テストの変更なし。
- 最初の人工fixture再seal準備で2件失敗したログは保持。上書き保護を緩めず人工準備だけ修正し、最終関連/全体成功を別ログとして保存。

関連コマンド:
```bash
php -d memory_limit=128M vendor/bin/phpunit tests/Feature/StartObservationTest.php tests/Feature/Stat35DataReadinessAuditTest.php tests/Unit/Domain/Keirin/Scraping/AutomatedRaceParserTest.php tests/Unit/Domain/Keirin/Scraping/RaceResultParserTest.php --colors=never
php artisan test --colors=never
```

限定Pintの対象はObserveStat36Command、StartObservation/{Contract,Ledger,Parser,Index,Builder}、
StartObservationTest、StartObservationFixture、start-observation-memoryの9ファイル。
証跡はRUNのrelated / full-suite / pint / syntax / planを参照。

### 年別実測

|年|台帳レース|importなし|Rawありuniqueレース|import版数|延べ観測行|unique対応出走|
|---|---:|---:|---:|---:|---:|---:|
|2022|24,868|3|24,865|24,865|173,863|173,863|
|2023|25,561|7|25,554|51,108|362,804|181,402|
|2024|25,624|9|25,615|25,615|181,854|181,854|
|2025|25,273|10|25,263|25,533|181,528|179,761|
|合計|101,326|29|101,297|127,121|900,049|716,880|

import版は統合しない。2023は25,554レース/51,108版であり重複処理ミスではない。
台帳分母とRaw台帳127,121行に一致、台帳外探索0。29レースはNO_IMPORT_IN_ACCEPTED_LEDGERとして残す。

|年|個人状況空配列|意味不明の空表示|落車棄権/失格のみ|未知表現|候補field内の完全一致S|
|---|---:|---:|---:|---:|---:|
|2022|145,945|25,112|1,779|1,027|0|
|2023|303,224|53,850|3,652|2,078|0|
|2024|151,173|27,938|1,769|974|0|
|2025|150,818|28,136|1,601|973|0|
|合計|751,160|135,036|8,801|5,052|0|

表示上のSなしを判別できた行は759,961、表示判別保留140,088。
ただし全900,049行でstart_acquired=null、UNKNOWN_POSITION_DEFINITION。
この0は「確定スタート値の件数」であり、実際のスタート取得イベントが0件という意味ではない。
候補fieldの欠落/NULL、非対応HTML/行schema、本人/レース対応不一致・重複車番は今回実測0。
BH空欄747,539行、inLineJyuni空欄896,665行はそのまま保存し、初手へ変換しない。

import単位の全行表示を観測可能な範囲ではS表示0人39,012版、1人0版、複数人0版、
空欄等で計測不能88,109版。未知値を0人へ加算しない。
中止空結果65版、中止非空48版、通常結果表示127,008版。
中止状態・部分車番集合はimport-auditで別々に追跡する。
候補表示signatureが複数あるrace/bikeは今回実測0。元import版はすべて保持。
複数の品質理由件数は排他的な観測総数とは別である。

### 具体例

選択規則は台帳順の年/状態/本人対応ごとの先頭2件。成績で選ばない。
`PJ0326.tyakujyunItemSubData[].kojinStateItemSubData`の例:

|import|race|bike|原表記|扱い|
|---|---|---|---|---|
|38077|37815|2|`[]`|空配列でS表示なし。ただしstart=falseではない|
|38077|37815|6|`kojinState=""`が2要素|空表示の意味不明、表示値NULL|
|38085|37816|3|`落車棄権`|観測された事故表示、除外せず保持|
|38150|37843|7|`事故入`|UNKNOWN_EXPRESSION、原文保持、意味を補完しない|
|38979|38762|なし|結果配列なし/空|CANCELLED_EMPTY|
|51740|51458|import単位|部分車番集合|CANCELLED_PARTIAL_OR_NONEMPTY、部分集合理由保持|

対応Raw path、original/converted hash、正確なJSON pointerはexamples.json/source-references.jsonlに保存。
候補field内のSは実資料で0件。人工S fixtureを実資料の意味確認根拠に読み替えない。

### 成果物・再現性

RUNの実在する絶対パス:
`/home/shinya/neo-keirin-artifacts/stat36-observation-01/run-20261001-PWIOV1BU`。

|pass|開始/終了JST (2026-10-01)|経過秒|memory_limit|peak bytes|exit|
|---|---|---:|---|---:|---:|
|build 1回|06:25:34 / 06:32:20|405.279369|512M|33,554,432|0|
|独立reproduce 1回|06:33:01 / 06:39:29|388.389617|512M|33,554,432|0|
|出力のみの独立照合|06:39:43 / 06:39:51|7.544195|128M|10,485,760|0|

生成: RUN/result。独立再現: RUN/reproduction。
**16成果物のサイズ・SHA-256とmanifestが一致**。manifestは両方5,320 bytes、
`d3aba9fb2374fe17c4f360cbd74b9b7738bfbb794a3c751e30ed551d7868ae1b`。
各passで参照Rawの原/変換hashを照合、終了時台帳と直接依存コードに変化なし。
別のRaw全量再ハッシュは実行せず、独立照合は今回の出力だけを読む。
`independent-verification.json`で全900,049行の年・禁止出力field・用途制限・状態別件数を再集計し一致。
部分車番集合の理由は2022/2023/2024/2025で15/16/7/10版、合計48版。本人不一致は全年度0。
未知表示行の要素別原文件数も同JSONに保存。1行複数表示を含むため観測行数と混同しない。
処理の正確なargv・開始終了時刻・所要時間・exitはbuild/reproduce/verificationのexecution.json、
全stdout/stderrは同ディレクトリのログに保存する。失敗履歴や既存成果物を上書きしていない。

### 完了範囲と未解決

生成成功は **DISPLAY_OBSERVATIONS_GENERATED_START_SEMANTICS_UNCONFIRMED**。
利用可能なスタート取得STAT36基盤、STAT36全体完成、性能改善とは報告しない。
未入手の定義は外部表示JavaScriptとS/空欄の意味。受入台帳にない資料を取得・推測していない。
初手隊列・公式掲載訂正時点・履歴窓・機会分母・最新訂正版選択・予測用fieldは未確定。
全件historical_as_of_available=false、prediction_use=NOT_AUTHORIZED、points=nullを維持する。
DB/HTTP、2026レース、学習/予測/評価/CI/Gate、migration/backfill実行0。
旧成果物・旧hash・既存C1・STAT35比較診断の記録を保持。未コミットのコード/生成結果レビュー待ち。

## PR #80: 署名のキー順正規化（人工検証のみ）

開始/終了HEAD: `7cd7971bcc442feea92c198b7e9e863a0f08de64`、branch `feature/stat36-observation-01`、開始worktree clean。
旧実装では個人状況objectのキー順だけで署名が異なり、2importの複数表示版数が1になることを人工統合2ケースで再現した。
専用DisplaySignatureで署名用コピーのobjectキーだけを再帰的にSORT_STRING順へ固定する。
listの順序・要素数、field欠落/NULL/空文字/空配列、数値/文字列/真偽値の型、文字列内容は保持する。
数値キーobjectもJSON listへ変換しない。外部IDとpresence/rawを版識別子付きで署名化し、source pointerは署名に混ぜない。
Parserの観測原値・キー順、元Raw、original/converted hash、pointerは変更しない。
共通Files::canonical、RawReader、Index、production Parser、C1、STAT35計算は変更していない。

観測契約: `STAT36-OBSERVATION-v2-DISPLAY-ONLY`。
署名契約: `STAT36-DISPLAY-SIGNATURE-v2-SORTED-OBJECT-KEYS`。
生成するcontractと各観測行にdisplay_signature_versionを記録する。
旧v1 manifest/hash/版は書き換えず、v1とv2を同じ署名仕様として比較・再現したとは扱わない。
start_acquired=NULL、UNKNOWN_POSITION_DEFINITIONと予測利用禁止は不変。新しい意味付けはない。

回帰42ケースを追加。旧コードで2失敗したキー順差は修正後成功し、10観測/2importは保持、複数表示版0を確認。
kojinState/kojinStateClass/BH/inLineJyuniの実値変更・list順変更は別署名/複数表示版1になる。
5箇所の欠落/NULL/空文字/空配列の全30組合せ、型、空白、nested object、原値不変、版記録を確認。
新規42回帰、関連197 tests/2275 assertions、通常全体2401 passed/9既存skipped/21274 assertions、exit0。変更PHP4件の構文・限定Pint成功。
通常全体は最終PHPコードで1回、2026-10-01 07:07:28〜07:10:33 JST、185.201350秒。新規skip・既存テスト緩和なし。

証跡: `/home/shinya/neo-keirin-artifacts/stat36-observation-01/pr80-review-fix-20261001-nm9E2U/`。
redは旧コード2失敗、green/relatedは修正後の人工検証。full-suiteとpintの実行記録も別保存。
既存900,049行の誤集計有無は今回は未確認。旧実測0という記録もv2で再確認した値ではない。
実Raw build/reproduce、旧監査、DB/HTTP、学習/評価、2026実データ参照は実施していない。
旧成果物に触れず、未コミットのレビュー修正として提出する。
