# PRESTART-CAPTURE-PJ0315-OBSERVATION-RUNNER-01

## 1. 範囲と現在地

2026-10-29、fetch / clean main確認 / main switch / pull --ff-only後のHEAD・origin/mainは `69c3abbd5c826c4673d3b86345021c53c9055893`。ローカルmerge履歴でPR125 MERGEDを確認し、未使用の `feature/prestart-capture-pj0315-observation-runner-01` を作成した。旧PR125のレビュー待ち・未コミット記録は当時の履歴である。

新契約 `PJ0315-OBSERVATION-RUNNER-v1` のPHP・Command・人工テスト、および指定された2025年実Raw 2版のread-only比較を製造・検証した。状態は `OBSERVATION_RUNNER_OFFLINE_VERIFIED_AWAITING_REVIEW`。**実運用契約の全受入完了ではない**。第7節の未完了項目を残し、追加実HTTPは0。

```yaml
operational_acceptance: NOT_COMPLETED
operational_decisions: PROPOSED_NOT_APPROVED
permission_status: UNVERIFIED
real_send_allowed: false
production_storage: NOT_RUN_NOT_AUTHORIZED
historical_as_of_available: false
prediction_use: NOT_AUTHORIZED
formal_adoption: false
method_b1_status: PROPOSED_NOT_APPROVED
method_selected: NONE
selected_lambda: NONE
formal_b1_data_acceptance_status: NOT_RUN
official_publication_at: null
points: null
confidence: null
2026_holdout: FROZEN_NO_ACCESS
```

既存Pj0315Probe v2・消費済み技術scope・Operational・Bridge v1の24依存sealは変更しない。新しい運用許諾・実trust storeを製造したとは扱わず、人工証拠を実承認へ昇格しない。未来年でも年だけでは送信できない。

## 2. PHP構成と呼出し順序

新規namespace: `App\Domain\Keirin\Scraping\PrestartCapture\Observation`。

| ファイル | 責務 |
| --- | --- |
| ObservationContract.php | 新版、固定禁止値、新規/直接依存/旧24ファイルのcode seal、排他JSON保存 |
| ObservationManifest.php | authorization/scope/revision、日場race/外部identity、PJ binding、本人snapshot SHA、予定出典/slot、明示容量/root/有効期間 |
| SlotPlanner.php | 60/15分前のslot計画、strict期限、未承認slot/取り返し取得の拒否 |
| ObservationPreflight.php | 既存Preflightの全D1〜D8/source/人工時計検査、新manifest・D1/2/7・対象・予定の結合、実運用の常時拒否 |
| AttemptLedger.php | scope全体のflock、排他予約/送信/結果/STOP、hash chainと別append witness、未知・中断・改訂での予算復活禁止 |
| Pj0315ObservationTransport.php | 注入Laravel Factoryの固定HTTPS form POST、retry0/redirect0/connect5/total20/4MiB上限 |
| ObservationCaptureService.php | 送信前検証から保存後解析/差分/STOP/終端検証までの接続 |
| ObservationStore.php | 0700/0600、既存安全API、専用capture/原bytesの排他保存/fsync、seal/FAILED/partial保持 |
| ObservationParser.php | 既存Converter→RawカテゴリーPolicy→既存RaceDetailParserの内部照合→対象race照合、原値の存在/型投影 |
| RosterDifferenceEvaluator.php | 車番/登録番号集合、既知の枠差分、未知枠の分離、項目別の存在/NULL/型/値比較 |
| ObservationVerifier.php | 保存束だけの再解析、承認/slot/台帳snapshot/時刻/Raw/code seal/終端の再検証 |
| HistoricalComparison.php | 指定2025年1件の旧baseline/旧Probe v1を検証し、2版比較と独立再計算。旧版を現Probe v2と混同しない |

Commandは `app/Console/Commands/Keirin/ObservePj0315Command.php`、既存discoveryを使用。新規Unit/Featureと人工Fixtureは第8節に記載。既存HTTP client/Fetcher/Parser/Repository/STAT46 Calculator/設定/安全helperは変更しない。

順序はmanifest/承認/slot検査→scope排他→消費済み予約→private要求とRaw sink確保→dispatch追記→単一POST→原bytes flush/fsync→サイズ/SHA→source検証→Rawカテゴリー→Parser内部本人照合→対象race→基準本人差分→scope STOP判定→結果/台帳snapshot→manifest→公開前Verifier→COMPLETEまたはFAILED→終端Verifier。失敗を正常値で補完しない。

Transportは通常のLaravel `asForm()->post()` に接続しているが、現在は注入HTTP fake以外のstray要求を常に禁止する。CommandのexecuteもOPERATIONAL_READINESSで評価し、現trust未成立のため台帳/出力作成より前にNOT_AUTHORIZED/HTTP0。実送信の有効化は別工程であり、force/unsafe/assume-approved等はない。

## 3. 対象・slot・台帳

現在受け入れるprofileは明示的な `SYNTHETIC_ACCEPTANCE_ONLY` だけ。1場1日/最大2男子race、60・15分前/2slot、最大4試行、同時1、開始間60秒以上、retry0、connect5/total20、開始window60秒をmanifestに明記する。これらは人工受入値であり、実運用の既定承認ではない。人工Fixtureの開始空き条件16MiBも実運用D7の承認値ではない。

計算例: 人工予定 `2025-01-04T10:45:00+09:00` → slot1 `[09:45:00,09:46:00)`、slot2 `[10:30:00,10:31:00)`。期限等値は拒否。UTC整数microsecondsで予定・期限・全raceの開始間隔を比較し、未使用slotを移動・追加しない。Rawの予定改定/不明は旧manifestを改変せず全scope STOP。

台帳はrun rootでなくauthorization ID＋approval scopeで固定する。実運用候補の固定registry名は `ObservationContract::LEDGER_ROOT` にあるが、**今回その実運用台帳は作成/初期化しない**。人工テストでだけ明示 `initializeForSimulation()` を実行し、既存scope/witnessの再初期化と実運用registryでの人工初期化を拒否する。execute/restart時に未初期化をAVAILABLEへ推定しない。

予約・dispatch・outcome・STOPは排他的/fsync付き追記。各予約にrace/slot/attempt ID・時刻・revision・消費状態、結果にHTTP状態・Raw SHA、scope headerにauthorization/manifest SHAを保存する。最大32イベントに有界化し、履歴を読み切って検証する。別ファイルのappend witnessを先に記録するため、イベント末尾を丸ごと失った場合やwitnessだけの途中中断もUNKNOWNとして停止する。

RESERVED/送信中断/UNKNOWNは未送信と推定せず消費済み。FAILED/TIMEOUT/HTTP異常・本人差分・予定改定・保存失敗では全scope停止。別run・別Command/Service・別PHPプロセス・改訂でも予算を戻さない。scope directory flockをcapture終了まで保持する。台帳とwitness双方を失った媒体の復旧/実運用再provisionは今回の受入外であり、実承認・backupと別検証が必要。

## 4. Raw・本人・保存・独立検証

原Rawを変換前のまま保存してから解析する。HTTP成功とParser成功を分け、200だけではCOMPLETEにしない。受信失敗の時計はfailed_atで、存在しないresponse_received_atを生成しない。bodyの完全性とbytes/SHAも別に保持する。source/effective URI不明は元URIで補わない。Retry-After/Locationはprivate応答だけに保持し、自動再開・追従しない。

新しい観測の本人を固定基準へ登録し直さない。車番/登録番号の一致、既知の枠差分、枠未確認を区別する。未知枠を本人変更と誤認しない。真の本人差分はRaw/観測値を保持し `ROSTER_CHANGED`・公式変更理由UNKNOWN・全scope STOP。研究用A→B→A比較では3版とも保持するが、STOP後の追加HTTPを許可するものではない。

STAT46の既存本人/枠DTO検証とカテゴリーPolicy、Parserを再利用する。新captureにはDB fetch-log IDがないため、IDを捏造して既存差分Calculatorへ渡さず、新Evaluatorで同じく枠UNKNOWNを分離する。旧Calculator自体と既存差分回帰は不変。

署名用投影はrace9項目とentry21項目。存在/NULL/空文字/型を区別し、object keyだけを固定順化、list順は保存する。Parserで受理不能な型/内部本人矛盾はFAILEDとして原Rawを残す。公式変更理由や公表時刻をHTML差分から推定しない。

Verifierは外部期待manifest SHAを必要とし、file seal/正確なinventory/code/固定禁止値、private入力とD1〜D8の再評価、同じRawの再解析・本人/差分・HTTP/時刻順、台帳snapshotのhash chain/slot/attempt/Raw対応・必要なSTOP、COMPLETE/FAILEDを検査する。部分資料をVERIFIEDへ昇格しない。DB/ネットワークは不要。新束は別PHP128Mでも検証成功した。実時計同期/単調時計/検証・公開完了receiptの全仕様はまだ未完了。

## 5. 保存済み実Raw 2版の確認

対象は **2025-01-04・場28・1R・race_id12901、男子9名** だけ。原資料は[旧実装記録第2・4節](prestart-capture-pj0315-real-http-01.md)に記載されたファイル。旧manifest/COMPLETE/要求binding・全file sealと固定baseline SHAを照合し、両Rawを保存資料だけから解析した。

| 項目 | 旧baseline | PR125の実取得v1 |
| --- | --- | --- |
| bytes | 94,103 | 93,721 |
| SHA-256 | `2041ed47a61c47de039cfc23f81f81240022e734d2cf5154fc76a2a532b710b7` | `e46900fe80a06c67e989d666d7a6eefcfac8c08508542df9ea1a3a48c71f7e36` |
| 取得時計UTC | 2026-07-21T12:24:25.000000Z | 2026-10-27T21:09:03.733211Z（受信） |
| 保存時計UTC | 元fixtureには未収録・UNKNOWN | 2026-10-27T21:09:03.735250Z |

取得間隔は8,498,678,733,211 microseconds。双方とも2025開催を2026年に事後取得した資料であり、発走前変更/当時の予測利用可能性を証明しない。

レースidentity・車番/登録番号9名・既知枠は一致。race9 field＋9名×21 entry field = **198項目で存在・型・値が一致、差分0**。Rawの差は382bytesで、分類は `HTML_ONLY_FOR_PROJECTED_ATTRIBUTES`。未投影のHTML全属性まで同一/意味変更なしとは断定しない。公式理由はUNKNOWN、二源照合はNOT_ESTABLISHED。

新規保存先:

`/home/shinya/neo-keirin-artifacts/prestart-capture-pj0315-observation-runner-01/comparison-20261029-075855-E3j3L0Cr/`

`private/comparison.json` に198項目の比較結果、`private/manifest.json`・COMPLETE、private source参照、logs/verify-logsに実コマンド/exit/128M peakを保持。新manifest SHAは `1161b25ca00129efc14aedface914e1e5c89f39507c38068489b0e7708f31be4`。比較・別PHP独立再計算は各exit0、各peak31,457,280bytes。private encp/HTML全文は本書・共有manifest・stdoutへ出していない。

旧v1 manifest SHA `9819156e418680565563b7ad05ddec2d1f9f17662411d79bb10aa1eb689d158a` と当時のcode sealは変更しない。旧Probeの実POST成功は旧版の実績であり、新Runnerの実HTTP成功には読み替えない。

## 6. Command

```bash
php -d memory_limit=128M artisan keirin:prestart:observe-pj0315
# plan: --manifest=<private JSON> --manifest-sha=<external SHA>
# preflight: --action=preflight --manifest=... --manifest-sha=... \
#   --preflight=<private input> --preflight-sha=<external SHA> --race=... --slot=...
# execute: --execute と上記入力を指定しても現段階ではNOT_AUTHORIZED/HTTP0
# read-only compare: --action=compare --baseline=... --baseline-sha=... \
#   --probe=<旧v1 private directory> --probe-sha=... --output=<新規空private directory>
# independent verify: --action=verify --bundle=... --bundle-sha=<external SHA>
```

正確な実比較/独立検証コマンドは第5節のlogs/command.txt・verify-logs/command.txtへ記録した。Commandに人工承認で実送信を有効化するoptionはない。比較出力も既存資料のあるroot・sourceとの重複/親子を拒否してから書く。Scheduler/Queue登録・自動取得・旧scopeの予約は行わない。

## 7. T01〜T26受入台帳

参照は[運用契約第12節](prestart-capture-operational-contract-01.md#12-受入テスト仕様)。以下の「確認」は今回の人工/オフライン実装だけで、実運用APPROVEDや全PILOT受入を表さない。PARTIAL/NOT_IMPLEMENTEDをskipで成功へ変換しない。

| ID | 今回の受入範囲 | 状態・残件 |
| --- | --- | --- |
| T01 | D1〜D8・予定期限・scope/manifest/revision/本人/source binding、自己承認の実送信0 | OFFLINE確認。実trust/実対象承認は未実装 |
| T02 | 人工2026指定をRaw/HTTP前に拒否 | 確認、実2026資料参照0 |
| T03 | PJ固定/JS・別URI・不正form/binding拒否 | OFFLINE確認。実許諾未確認 |
| T04 | 1場1日最大2race、範囲超過の全体拒否 | PARTIAL。3race拒否/2race台帳確認、全組合せの新Runner受入は未完了 |
| T05 | 全4slot/第3slot・第5試行・別run・別process/同時起動・中断予約拒否 | 人工台帳確認。実運用provisionは未実装 |
| T06 | 60/15分前/window、59.999999秒、期限等値、改定拒否/STOP | OFFLINE確認、取り返し/再計画なし |
| T07 | 429原Raw/FAILED保持、scope停止/retry0 | HTTP fake確認。Retry-After private保持、実429は未取得 |
| T08 | 500/503/timeout/未stub transport failure、次slot拒否 | HTTP fake確認、実送信0 |
| T09 | 301/302/307/308、allow_redirects=false/1要求 | HTTP fake確認。redirect先への実通信0 |
| T10 | 正常Rawでもeffective URI不明/不一致を拒否 | analyze単体/既存回帰確認。新Transport経由の全URI異常組合せはPARTIAL |
| T11 | 1byte/manifest改変、保存後collision、原Raw/FAILED/partial保持 | PARTIAL。fsync故障/全サイズ境界の専用注入は未実施 |
| T12 | Raw先行MEN/GIRLS/UNKNOWN、既存L級Policy回帰 | PARTIAL。新Runnerの全カテゴリー矛盾境界は未完了 |
| T13 | 既存Parser内部本人照合、対象race照合、原登録番号保持 | PARTIAL。新Runner全不正型/欠番組合せの個別受入は未完了 |
| T14 | JS共有Raw/複数race投影 | FUTURE_JSJ017_CONDITIONAL、別工程未承認・未製造 |
| T15a | SECOND_SOURCE_NOT_CAPTURED/二源NOT_ESTABLISHED、JS補完0 | 確認、予測禁止 |
| T15b | 二源矛盾/取得順反転 | FUTURE_JSJ017_CONDITIONAL、別工程未承認・未製造 |
| T16 | 本人差分/UNKNOWN/全scope停止、A→B→A研究比較・基準不変 | PARTIAL。全変更形態/継続観測の正式D5方針は未承認 |
| T17 | 解析不能/空を中止へ推定せず保存/停止 | NOT_IMPLEMENTED。公式中止/順延/欠場を分離する新契約は未製造 |
| T18 | 既存人工時計境界/保存束の実時計順序検査 | PARTIAL。実単調時計と同期証拠の接続は未実装 |
| T19 | 人工UNKNOWN/誤差区間/offset規則を既存Preflightで検査 | PARTIAL。公式TZ/丸め/実時計誤差TTLは未確認 |
| T20 | 既存人工strict cutoff/完了順序の回帰 | PARTIAL。実validation/publication完了receipt・予測適格性は未実装 |
| T21 | 新ID排他/改訂拒否/部分保持、旧原本START-END不変 | OFFLINE確認。旧bundle上書き/再seal0 |
| T22 | 原値の存在/型投影、NOT_AUTHORIZED、モデル更新0 | OFFLINE確認。Parser不受理型はRawをFAILED保持 |
| T23 | 未provision/中断/STOP/破損/末尾欠落を再開扱いにしない | PARTIAL。実取消・媒体復旧/実運用provisionは未実装 |
| T24 | 既存パス/owner/0700/link/mount安全API、容量検査、人工Trash | PARTIAL。新保存器の全permission/fsync/容量故障、backup/復旧受入は未完了 |
| T25 | 新版はSIMULATEDだけ、実運用常時拒否。既存PR121 mode混入回帰 | 新旧を別テストで確認、既存Bridge/実Raw9版も実行 |
| T26 | 新束の別PHP検証/外部SHA/再投影/旧seal・Verifier回帰 | PARTIAL。新公開完了receiptは未実装。実2版比較の別PHP再現は成功 |

## 8. テスト・隔離・実行記録

新規Unit `Pj0315ObservationRunnerTest`、Feature `Pj0315ObservationCommandTest`、人工Fixture `ObservationRunnerFixture` を追加。人工レース/時刻/承認/障害はsyntheticと明示し、実Rawの受入根拠へ転用しない。

| 最終コードの検証 | cases | assertions | failures/errors | warning付きtest | skipped | exit |
| --- | ---: | ---: | --- | ---: | ---: | ---: |
| 新規Unit＋Command | 39（37＋2） | 159 | 0/0 | 0 | 0 | 0 |
| 既存Probe/scope/Command | 41 | 326 | 0/0 | 0 | 0 | 0 |
| その他既存Bridge/Approval/Evidence/属性/STAT46/Parser/HTTP fake | 545 | 3,829 | 0/0 | 155 | 0 | 0 |
| 合計・単一限定実行 | 625 | 4,314 | 0/0 | 155 | 0 | 0 |

Artisan表示は **470 passed / 155 warnings**、JUnitでは625ケース全件に失敗・エラー・skipなし。warningは既存 `PrestartEvidence/EvidenceFiles.php:149` の `lstat(): Lstat failed`、155 test/2,125 event、原因箇所1分類。新Runnerの警告ではない。旧証拠コード/警告抑制設定を変更せず、過去の全体警告の完全解消とは報告しない。

実PHP `/usr/bin/php` → `/usr/bin/php8.5`、8.5.4 CLI、PHPUnit12.5.31、Artisan/Collision。PHPUnit本体/独立Verifierは128M、限定625ケースのPHP peak **71,827,456bytes**。AGENTS記載のPHP8.4で検証したとは扱わない。

最終ログ:

`/home/shinya/neo-keirin-artifacts/prestart-capture-pj0315-observation-runner-01/test-20261029-075852-sE8PO3Bb/logs/`

runner.sh / command.txt / PHP版 / stdout / stderr / JUnit / events / exit / memory / 旧seal・原本START-END比較を保持。最初の新Featureの人工.env作成API選択ミス（26 passed/2 failed/110 assertions/exit2）のログも保持し、旧APIを変更せず人工.envの排他作成へ修正した。テスト削除/緩和/skip追加なし。

実行形:

```bash
php -d memory_limit=128M artisan test <上記2クラスと限定既存13クラス> \
  -d memory_limit=128M --bootstrap tests/Support/stat38-memory-bootstrap.php \
  --display-all-issues --do-not-cache-result \
  --log-junit /tmp/evidence/junit.xml --log-events-text /tmp/evidence/events.log
```

正確な15 pathはcommand.txtに保持。bwrapでroot read-only・ネットワークnamespace分離・home/run/mnt/media秘匿、人工.env/storage/bootstrap cache、SQLite in-memory設定と本番サービス拒否、人工Desktop/Trashを維持。既存9版の資料だけread-only bindし、旧PrestartCaptureReplayTestの9束独立検証・STAT46保存値/2組差分照合も省略していない。DB接続・新規実HTTPは0。

後片付けは保持オブジェクトのpath()だけを既存helperのretireへ渡し、成功TEST_COMPLETE/失敗TEST_FAILURE、人工Trash内へrename-only。本物のDesktop/Trashを操作せず、永久削除/fallback0。通常全体テストは安全性未確認の対象外cleanupがあるため未実施。Storage::fakeのdeleteDirectoryを含むRawResponseStorageServiceTestも今回の限定実行へ追加していない。

旧Raw/fixture/旧Probe9ファイル/消費済みregistry/.envと旧24依存・Probe v2 sealはSTART-END一致。原本・旧モデル・既存結果/コードの再sealなし。新規16PHPの構文、限定Pint、diff checkを別途記録する。

## 9. 残件と停止

次は今回のPHP/限定テスト/実2版比較/未完了T台帳のレビューだけ。実運用trust/正当な具体対象・D1〜D8/時計/容量保持backup・公開完了receipt・公式中止順延契約・未完了受入が揃うまで実送信を有効化しない。JSは別承認のまま。問い合わせ回答を待つことを今回の製造停止理由にはしていないが、許諾確認済みとはしない。

通常全体・実HTTP・本番DB/正式保存・Migration・Scheduler/Queue登録・2026開催参照・学習・予測・性能評価・B1/lambda/H1-U・commit/push/PR/mergeは各0。性能影響 `NOT_MEASURED_THIS_CHANGE`。旧不採用/Gate・C1/C2/STATモデルは不変。変更を未コミットで保持し、レビュー待ちで停止する。
