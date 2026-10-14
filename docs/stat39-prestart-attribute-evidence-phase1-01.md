# STAT39-PRESTART-ATTRIBUTE-EVIDENCE-PHASE1-01

## 範囲と実装前技術ゲート

開始HEAD: `45a47dcadc4d1ee823be7125d137efbad42ae587`。cleanなmainとPR #106〜108のマージ履歴を確認し、`feature/stat39-prestart-attribute-evidence-phase1-01`を新規作成した。MASTER PLAN全文と8つの工程制御項目を照合した。旧MVPレビュー待ちの現在地は過去記録として維持し、最新ユーザー承認による人工Phase 1だけを実装する。凍結契約との実質的矛盾なし。

PHP製造前に採用方式を次の通り記録する。

- G1: `json_validate()`でJSON文法を検査し、構造を走査して各nodeにJSON Pointer、token開始位置・長さ・原字句を結び付ける。数値は字句文字列のまま扱い、decimal正規化も文字列演算のみ。floatへの変換後に原表記を復元しない。HTMLはDOMのscriptを選択し、文字列・コメント外の文書代入だけを抽出する。
- G2: 追加PHPを`PrestartEvidence/Attributes/`に限定する。既存直下のPHP globと旧captureのファイル集合・code sealは変えない。新版は旧直接依存と新規PHPの相対path・bytes・SHAを独立してsealする。
- G3: 人工時計は`SIMULATED_SCENARIO`として別構造・純粋関数で判定する。実保存・検証・公開時計は別記録とする。人工cutoff成功を歴史的利用可能性と解釈しない。
- G4: 外部期待hashで旧captureを検証し、別bundleを排他保存する。manifest検証、COMPLETE公開、独立検証の後に実時計を観測してreceiptを排他保存する。receiptはmanifest hashを参照し、receipt hashは呼出し元へ返す。manifestへ追記しない。独立Verifierには元manifest・派生manifest・receiptの期待hashを外部入力する。

固定: `historical_as_of_available=false`、`prediction_use=NOT_AUTHORIZED`、`formal_adoption=false`。公式枠適用はUNKNOWN。Phase 2以降、DB、HTTP、実Raw、2026実データ、モデル・STAT計算・学習・予測・評価は対象外。旧文書とMASTER PLANは変更しない。

## 実装・検証結果

状態: **STAT39_PRESTART_ATTRIBUTE_EVIDENCE_PHASE1_READY_FOR_REVIEW**。

人工属性証拠の保存・独立検証と指定の人工回帰を実施した。実データでの発走前証拠確認・予測利用承認・Phase 2への移行を意味しない。開始・終了HEADは同じ`45a47dcadc4d1ee823be7125d137efbad42ae587`。commit/push/PR作成/mergeなし、11新規ファイルを未コミットで保持する。

### 追加クラスと責務

PHPはすべて`app/Domain/Keirin/Scraping/PrestartEvidence/Attributes/`配下。

| ファイル | 責務 |
|---|---|
| `JsonNode.php` | 型・原字句・document内byte位置・Pointerを持つ不変node |
| `LosslessJson.php` | JSON文法検査と構造走査。重複keyはescape展開後も拒否 |
| `RawDocuments.php` | DOMのscriptから独立したJSON object literal代入を抽出。文字列/comment内の偽代入、重複文書、条件式内の代入、式suffixを拒否 |
| `AttributeProjector.php` | JSJ017/PJ0315の属性、race識別原値、PC0201の本人照合証拠を投影 |
| `ScenarioTiming.php` | 明示した人工時計・誤差区間・cutoffの純粋判定。未知/不正時計は合格にしない |
| `BundleFiles.php` | 新契約・旧直接依存と新PHPの独立code seal・有限JSONL表現・利用禁止の固定値 |
| `AttributeEvidenceStore.php` | 元capture検証、別namespaceへの排他保存、公開前再検証、COMPLETEと独立receiptの保存 |
| `AttributeEvidenceVerifier.php` | 外部期待hash、旧Verifier、原Rawからの再投影・再計算、marker/receipt/code sealを独立検証 |
| `StoredAttributeEvidence.php` | bundle path/ID、元・派生manifest hash、receipt hash、検証結果を返すDTO |

残る2ファイルは新規Unit Testの`tests/Unit/Domain/Keirin/Scraping/Stat39PrestartAttributeEvidencePhase1Test.php`と本書。既存コード、既存テスト、安全helper、MASTER PLAN、前工程の契約を変更していない。

### 原値・識別・位置の契約

- 版は`STAT39-PRESTART-ATTRIBUTES-SYNTHETIC-v1`、decoderは`STAT39-LOSSLESS-JSON-v1`、投影は`STAT39-ATTRIBUTE-PROJECTION-v1`、人工時計は`STAT39-SIMULATED-TIMING-v1`。
- 数値nodeはfloatにもnative数値にも変換しない。`raw_value_encoding=JSON_NUMBER_LEXEME`と`raw_type=INTEGER/DECIMAL`を付け、`raw_value`と`raw_lexeme`に正確な字句を保持する。文字列はSTRINGとしてJSON escapeを復号した値と原字句を別保存する。不正型のobject/listは`JSON_FRAGMENT`として区別する。
- `90.10`、`90.1`、`"90.10"`は原字句/型が異なり、正規化値だけがexact decimal文字列`90.1`になる。大きな小数も末尾ゼロ以外を失わない。指数表記、負値、未知文字列、不正型等は推測変換せず`NORMALIZATION_UNVERIFIED`。得点0は原値/正規化値に残るが`stat01_usable=false`。
- MISSING、NULL、EMPTY_STRING、FULLWIDTH_HYPHEN、PRESENT、JSJ017のSOURCE_NOT_PROVIDEDを別に保持する。型不正と未知/未対応字句には別のnormalization reasonを付す。観測枠番号から公式適用/非適用を推測しない。
- 元captureの固定RaceTargetと日・場・番号・車番・登録番号集合を照合する。登録番号は先頭ゼロ込みの文字列。欠番を詰めない。PJ0315詳細とPC0201概要の車番/本人を両方検査し、概要側のPointerも残す。旧Verifierが不合格なら正常派生bundleを発行しない。
- Pointerはdocument key、RFC6901 JSON Pointer、decoder版、document内token offset/length、script indexを持ち、親race記録の元Raw SHA/capture ID/元manifest hashに結び付く。JSJ017のoffset basisはRAW_BYTES。HTMLはDOM_SCRIPT_TEXT_BYTESであり、DOMが扱ったscript textの位置であってHTML全体のbyte offsetとは称さない。原Rawから同じDOM/documentを再構成してtokenを照合する。
- JSON文法検査は`json_validate()`。数値を`json_decode()`した後に字句を復元する処理はない。上限はRaw/各派生ファイル8MiB、JSON深さ64、node数20,000、保持字句総量16MiB、Pointer総量8MiB。超過はfail-closedであり、実データ全量処理の性能保証ではない。

### 時計の判定とreceipt

| 人工条件 | 判定 |
|---|---|
| 受信誤差上端 < 同Rawの予定発走誤差下端 | PRESTART_EVIDENCE_CANDIDATE |
| 誤差0の同時刻 | POSTSTART_CAPTURE / AT_SCHEDULED_START |
| 受信誤差下端 > 予定発走誤差上端 | POSTSTART_CAPTURE / AFTER_SCHEDULED_START |
| 誤差区間の重なり・接触、予定欠落、TZ/時計根拠不明 | TIMING_UNKNOWN |
| 受信/保存/検証/公開の完了がcutoffより確実に後 | CUTOFF_*_LATE / UNAVAILABLE |
| cutoffとの誤差重複、receipt欠落、順序不正 | UNKNOWN。模擬利用可能へ昇格しない |
| 全完了の上端 <= cutoff下端、cutoff上端 < 予定下端、順序/receipt成立 | SIMULATED_TECHNICALLY_AVAILABLE |

時刻はoffset込みで比較し、原表記を保持する。人工2025-12-31の受信09:50、保存09:50:01、検証09:50:02、公開09:50:03、cutoff09:55、予定10:00という正常例は模擬判定だけに成功する。2026年に実保存した証拠が2025年当時存在した証明にはしない。実公開と人工cutoffの比較はAFTERで、物理的historical availabilityはNOT_ESTABLISHEDのまま。

`scenario-time.json`は明示したSIMULATED_SCENARIOの時計fieldだけを受理する。実時計basisや任意のrequest情報の混入は拒否する。人工receiptは実receiptではない。実収集の最終適格性Gateは実装していない。

### 保存・再検証手順

元captureは外部期待manifest hashで旧CaptureVerifierを通してから、不変参照として使う。原Rawを複製しない。別の既存0700専用rootにランダム32桁hex bundle IDで次を新規生成する。

```text
source-reference.json
attribute-observations.jsonl
scenario-time.json
validation.json
execution.json
manifest.json
COMPLETE.json
publication-receipt.json
```

`source-reference.json`にはcapture ID・元manifest hash・原Raw size/SHA・相対名だけを記録する。private-source、完全URI、encpを派生manifestへコピーしない。全書込みは既存安全APIによる排他生成・0600・flush/fsyncを使い、作成した出力ディレクトリのidentityと親rootを照合する。新規側はdirectory entryもfsyncする。不正path、symlink、mount、保護root、既存IDは拒否し、fallback/上書き/削除しない。

新code sealは旧直接依存とAttributes配下のPHPについて相対path/bytes/SHAと各版を保持し、処理開始時と公開前を照合する。旧codeSealの直下globは不変。

1. 属性・人工時計を投影し、派生ファイルを保存する。
2. manifestを排他保存し、外部期待SHAを固定する。
3. 新Verifierが元capture、元Raw、全派生seal、再投影/再計算、code sealを検証する。
4. COMPLETEを保存し、別Verifier呼出しで公開markerを独立検証する。
5. その後に実時計を観測し、manifest hashを参照する`publication-receipt.json`を排他保存する。
6. receiptのSHAをDTOで呼出し元へ返す。元manifestへ追記しない。最終Verifierは呼出し元の元manifest/派生manifest/receiptの期待hashを入力し、すべてを再検証する。

再検証APIは`verify(派生dir, 期待派生manifest SHA, 元capture dir, 期待元manifest SHA, 期待receipt SHA)`。これらの期待hashはbundleから自己申告値を拾って代用せず、生成時に受け取ったDTO等を呼出し元の信頼済み記録として保持する必要がある。receipt自体は第三者署名・時計同期証明ではない。

公開途中失敗はpartial/FAILEDを保持する。COMPLETE後のreceipt保存失敗でもFAILEDを追加し、正常Verifierはその集合を拒否する。失敗資料を修復上書きせず、正常公開済みと扱わない。

### テスト結果と証跡

最終PHPコードで実行。PHP `/usr/bin/php` 8.5.4、PHPUnit 12.5.31。bwrapでnetworkを遮断し、repoをread-only、実.envをコメントだけの人工.env、storage/cacheを人工領域に置換した。実homeの成果物・モデルは見えないfilesystem viewを使う。人工/tmpとDesktop/Trashは同じ専用filesystem viewに置き、作成時保持オブジェクトのpathだけを既存helperへ渡した。実Desktopへの生成・移動なし。

| 対象 | passed | assertions | failures/errors/skips/warnings | PHPUnit時間 | peak表示 | exit |
|---|---:|---:|---|---:|---:|---:|
| 新規専用Unit | 95 | 450 | すべて0 | 2.703s | 28.00 MB | 0 |
| 旧PrestartEvidenceTest（変更なし） | 75 | 444 | すべて0 | 0.917s | 30.00 MB | 0 |
| 合計 | 170 | 894 | すべて0 | 上記各実行値 | 親の累積値で合否判定しない | 0 |

T01〜09/15/20/22〜24/26〜28/30を検証。数値tokenの同名field/別選手/別object/文字列内衝突、escape/ネスト、object/list、重複key/文書、同内容別capture、NULL/空/欠落、真の本人不一致、HTTP3xx/URI不明、不正path/symlink/mount/ID競合、Raw/manifest/marker/receipt改ざん、公開前code/source drift・保存/公開失敗を含む。派生manifestを再sealした偽token/pointer/判定も、原Rawからの再計算で拒否した。

独立PHP子プロセスにも`-d memory_limit=128M`を実適用し、JSJ017/PJ0315計12 bundleを逐次保存・独立再検証。exit=0、実効limit=128M、peak_memory_bytes=10,485,760（10MiB）、stderr空。結果JSONを人工専用ディレクトリへ保存し、記録した同じディレクトリをTrashへ退避した。親プロセスの累積peakを判定に使用しない。

実行コマンド本体（全引数・隔離条件は各execution-start.jsonに保存）:

```bash
/usr/bin/php -d memory_limit=128M -d sys_temp_dir=/tmp vendor/bin/phpunit tests/Unit/Domain/Keirin/Scraping/Stat39PrestartAttributeEvidencePhase1Test.php --do-not-cache-result --display-all-issues --fail-on-warning --fail-on-risky --fail-on-skipped --fail-on-incomplete
/usr/bin/php -d memory_limit=128M -d sys_temp_dir=/tmp vendor/bin/phpunit tests/Unit/Domain/Keirin/Scraping/PrestartEvidenceTest.php --do-not-cache-result --display-all-issues --fail-on-warning --fail-on-risky --fail-on-skipped --fail-on-incomplete
```

証跡root（run名はUTC）:

`/home/shinya/neo-keirin-artifacts/stat39-prestart-attribute-evidence-phase1-01/`

- 最終新規: `20261014-215815-focused-945a2fad/`。開始2026-10-15 06:58:15 JST、終了06:58:18 JST。stdout/stderr、実行条件、exit、JUnit、events、人工Trash、子プロセスメモリJSONを保持。
- 最終旧回帰: `20261014-215818-legacy-30bdc1e8/`。同様にログを保持。
- 製造中に検出した新規Fixtureのescape不備、新規テストのプロパティ参照/import不備の失敗ログも保持。修正後の最終結果とは区別する。既存テストの削除・無効化・skip追加はない。
- 新PHP全10ファイルの`php -l`、新PHP限定`pint --test`はexit=0。tracked差分と新規ファイルの個別`git diff --no-index --check`で末尾空白等を確認する。既存trackedファイル差分は0。

### 互換性・未実装・次工程

人工旧captureについて、派生保存/検証前後のファイル集合・全SHA（Raw、manifest、markerを含む）が一致し、旧Verifierの結果も一致した。既存直下code sealの集合/値も一致。実成果物はテストから不可視・read-onlyにしており、実モデル・保存run・実Rawの全領域hash再監査は行っていない。未確認領域を全件検査済みとはしない。

Parser回帰の追加候補はLaravel起動と本工程に不要なFixtureを含むため実行せず、指定の純粋Unitと旧PrestartEvidence回帰に限定した。通常全体テストも指示どおり未実施。新規Unitの人工Rawだけで必要な埋め込みJSON/本人/時点を検証した。

未実装は意図したPhase 1対象外: occurrence/変更履歴、交替調停、公式制度/Matcher、実HTTP取得・実時計同期、DB更新、C1/STAT01/39への接続、モデル計算・評価。公式枠適用はUNKNOWN、原本の残タスクOPENと旧不採用判断を変更しない。実データでの発走前証明は未実施。字句抽出器は対応する静的JSON object代入に限定し、任意JavaScriptの評価器ではない。

次に許可される作業は、この人工Phase 1コードとテストのレビュー。未承認の実収集やPhase 2へ自動移行しない。DB/HTTP/実Raw/2026実データ/学習/予測/性能評価/全体テストは0回。永久削除、既存成果物の移動・上書き、commit/push/PR/mergeなし。
