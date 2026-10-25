# PRESTART-CAPTURE-APPROVAL-PREFLIGHT-01

## 1. 範囲・開始確認

2026-10-26、ユーザーの限定製造指示による承認前のオフライン検証基盤。fetch、clean main確認、main switch、pull --ff-only後のHEAD/origin/mainは `10e8f11d10e7c7ef91c2eba85288afcc1ec4c1db`。GitHubでもPR122 MERGED、merge SHA一致、merged_at `2026-10-25T21:04:46Z`（2026-10-26 06:04:46 JST）を確認した。未使用の `feature/prestart-capture-approval-preflight-01` をmainから作成。終了HEADも開始値のまま、今回差分は未コミット。

状態は `OFFLINE_APPROVAL_PREFLIGHT_READY_FOR_REVIEW`。根拠は[運用契約案](prestart-capture-operational-contract-01.md)のD1〜D8と工程別受入、および今回の限定製造承認。PR122のmergeは文書案の受入であり、運用値・取得先許諾・実送信の承認ではない。過去のレビュー待ち・実測・SHAは履歴として保持する。

```yaml
contract_version: PRESTART-CAPTURE-APPROVAL-PREFLIGHT-v1
operational_decisions: PROPOSED_NOT_APPROVED
real_http_capture: NOT_RUN_NOT_AUTHORIZED
production_storage: NOT_RUN_NOT_AUTHORIZED
historical_as_of_available: false
prediction_use: NOT_AUTHORIZED
formal_adoption: false
official_publication_at: null
points: null
confidence: null
2026_holdout: FROZEN_NO_ACCESS
```

B1監査BLOCKED/方法未承認、親lambda未選択、正式B1受入NOT_RUN、H1-U保留、旧不採用判断・C1/C2/BT/STAT正式計算・LIVE禁止は不変。今回は実HTTP Transport/Fetcher、実収集Command、予約・保存器を製造していない。

## 2. PHPと責務

新規namespaceは `App\Domain\Keirin\Scraping\PrestartCapture\Operational`。旧Bridgeのcode sealは直下 `*.php` と直接依存の列挙で、子directoryを走査しない。旧24ファイルはGit開始HEAD、実行START/ENDでSHA一致。旧v1・既存Evidence/Raw/Parser/STAT/安全helperは変更しない。

| 新規PHP | 責務 |
| --- | --- |
| Contract.php | 専用版・D1〜D8・型/日時/参照の検査、固定禁止値 |
| DecisionStatus.php | PROPOSED_NOT_APPROVED/UNRESOLVED/UNVERIFIED/APPROVED/EXPIRED/REVOKED/CONFLICT |
| DecisionRecord.php | proposal/approved value、承認/証拠参照・SHA・確認フラグ、承認者/承認日時/期限/未決理由のreadonly型契約 |
| ApprovalEvidence.php | manifest外の期待SHAカタログと証拠bytesを照合。人工証拠以外を承認根拠にしない |
| ApprovalPreflight.php | 全8判断を個別評価し、入力状態・検査結果・理由・不足証拠・次の判断・元契約版を返す |
| TargetManifestPreflight.php | PJ0315固定1レース・内部/外部ID・全本人・カテゴリー・revision/previous SHA・期限・D1承認/本人証拠の照合 |
| BudgetPreflight.php | 注入された人工上限・全試行snapshot・重複slot・間隔/同時数/timeoutの純粋検査。予約/消費なし |
| SourcePolicyPreflight.php | D3承認・自動取得根拠・source/route/parameter/binding・期限の検査。応答取得元の実証ではない |
| TimingPreflight.php | 人工UTC誤差区間・分精度予定・各完了時計・cutoffの検査。実時計を認定しない |
| PreflightReport.php | 全軸と不足をJSONへ集約、正式利用禁止値を固定 |
| Preflight.php | 入力/全判断/各軸を接続し、対象・source・時計・予定試行のbindingを検査する純粋な入口 |

追加Commandは `app/Console/Commands/Keirin/PrestartApprovalPreflightCommand.php`。Laravelの既存Command discoveryを使用し、bootstrap設定・Provider・Scheduler・Queueを変更しない。

既存 `DeterministicJsonHasher` を新契約のdigestに再利用する（objectキーを正規化し、配列順/型を保持）。日時解析は既存 `ScenarioTiming::instant()`、request route/parameter検査は `SourceValidator`、カテゴリーは `RaceCategoryPolicy`、本人の車番/6桁登録番号/枠/重複検査は `RaceRosterSnapshotDto` を使用する。既存の共通JSON/hash仕様は変更しない。

## 3. 承認証拠と人工/実運用の境界

### 3.1 DecisionRecordと独立した証拠

`APPROVED`という入力文字列や `evidence_verified=true` は証拠ではない。次を全て検査する。

1. decision ID/専用契約版、型、重複なし、全D1〜D8を固定順で評価。
2. approved value、承認参照/証拠参照/承認者が存在すること。
3. 証拠参照に対する別カタログの期待SHA、DecisionRecordのSHA、実際の証拠bytesのSHAが一致。
4. 証拠のscope/kind/status/契約版と、decision ID・approved value・各参照・承認者/日時/期限を含むpayloadが一致。
5. offset付きの有効な日時で `approved_at <= evaluation_at < expires_at`、かつ承認開始が期限より前。EXPIRED/REVOKED/CONFLICT等は通さない。

期待SHAカタログと証拠JSONは対象manifestとは別に渡す。本人証拠はtarget SHAとrequest binding、D1承認はmanifest SHA/revision/previous SHA/target SHAと証拠参照を固定する。D3はpolicy SHAとrequest bindingへ固定する。自己申告だけでは不足し、未知値・不整合の補正はしない。

本工程の証拠カタログは **SIMULATED_SCENARIO専用**。Command入力の `simulation_evidence` に含まれる人工カタログを、実運用のtrust anchorに転用しない。OPERATIONAL_READINESSではカタログを利用せず、持込み自体も `SIMULATED_EVIDENCE_NOT_OPERATIONAL` として阻止する。実際のユーザー承認記録や独立した実運用trust storeは作成していない。暗号署名/署名者認証を実装したという意味でもない。

### 3.2 D1〜D8で確認すること

| ID | このPreflightの検査と限界 |
| --- | --- |
| D1 | 固定1レースの日・場・番号/内部ID・外部identity・全出走者・MENを照合。2026は本人証拠を参照する前に拒否。2027以降も年だけで通さず、独立した人工承認と本人証拠が必要。revision/previous SHAはD1承認値へ照合するが、過去改訂manifestの実在走査は行わない |
| D2 | scenarioの上限とrequest settingsを入力として比較。全run相当のsnapshotでFAILED/RESERVED/INTERRUPTEDも消費相当へ数え、重複slot、時刻不明、停止/台帳不明は阻止。実予約/予算消費は常に0 |
| D3 | 根拠なしPOLICY_UNVERIFIED、未承認sourceはSOURCE_NOT_APPROVED、binding不一致SOURCE_BINDING_MISMATCH、期限切れPOLICY_EXPIRED。公開サイト/既存Fetcher/robots存在を許諾へ代用しない。実HTTPのeffective URI/redirectは本工程では観測しない |
| D4 | PJ0315単独、JS未承認、SECOND_SOURCE_NOT_CAPTUREDを要求。二源整合はNOT_ESTABLISHED固定。JS取得・共有Raw・複数race投影なし |
| D5 | UNKNOWN保持/append-only/追加取得なしという人工承認値を検査。実際の中止・順延・変更検出/再取得制御は未製造 |
| D6 | 人工clock policyとtarget SHAを承認値へ固定し、予定の日付を同targetへ照合。予算上の予定試行とrequest開始も同一瞬間であることを検査。実測同期証拠の取得・認定は未実装 |
| D7 | 保存/復旧参照と自動削除禁止の人工承認値を検査するだけ。root作成、実容量・権限・保持/backupの実証は行わない |
| D8 | prediction_use=NOT_AUTHORIZEDを要求し、出力でも固定。最終予測適格性Gateやモデル接続は製造しない |

全8記録がなくてもMISSINGと各不足を返す。実運用の現在の承認状態をDBや文書から自動取得した結果ではなく、渡された入力/証拠の不足を説明するもの。未知・期限切れ・取消・矛盾を0や既定値へ補わない。

### 3.3 予算と時刻

1場/1日/最大2レース・2slot/race・最大4試行・同時1・間隔60秒・retry0・connect5秒/total20秒は `tests/Support/ApprovalPreflightFixture.php` の **未承認人工シナリオ値**。実運用configや許可数の既定値は追加しない。BudgetPreflightは注入値で検査し、結果の `reservation_performed=false`、`budget_consumed=false` は不変。

時刻はUTCの整数microseconds区間で比較し、原offset表記が違っても同一瞬間は同じ結果。人工のSTART_OF_MINUTE規則では予定HH:MMを `[分の先頭, 分末59.999999秒]`＋明示誤差として扱い、秒00の実発走を捏造しない。これは公式丸め方式の確認ではない。

- 開始→受信はstrict順序。受信→Raw保存→検証→公開→input_as_ofは各区間で順序成立が必要。
- 受信区間の上端とas-of区間の上端が予定区間下端よりstrictに前であること。保存・検証・公開の完了はas-of以前であること。
- 時計証拠/TZ/分精度規則/誤差/完了時刻が不明、時計逆転、区間重なりはUNKNOWN。保存や公開が遅い場合も通さない。
- 人工成功でも実取得時刻、実Raw保存、公式公表、実発走、過去時点利用可能性を証明しない。actual_start_at/official_publication_atはNULL。

## 4. JSON入力とCommand

```bash
php -d memory_limit=128M artisan keirin:prestart:approval-preflight
php -d memory_limit=128M artisan keirin:prestart:approval-preflight \
  --input=/absolute/path/to/synthetic-input.json \
  --scenario=SIMULATED_SCENARIO --at=2025-01-01T00:00:00Z
```

後者は操作書式であり、そのpathにファイルが実在するとの記録ではない。テストでは新規人工rootのinput.jsonを排他生成した。URL、非正規/相対/解決不能path、symlink、1MiB超、JSON不正を拒否し、ファイル内容/秘密値をエラーへ表示しない。入力以外の資料をCommandが読みに行くことはない。

| 入力section | 内容 |
| --- | --- |
| decisions | DecisionRecordのlist、最大8・重複不可。snake_caseの型付きfield |
| manifest | contract_version/revision/previous_manifest_sha256/expires_at/source/target、approval_reference、identity_evidence_reference/SHA、request_binding_sha256 |
| budget | ledger_known/stopped/active_attempts/attempts/planned_attempt/request_settings。上限はD2のapproved_value |
| source | PJ0315、request_uri/parameters/binding SHA、policy参照/SHA。完全URI/parameterはレポートへ転記しない |
| timing | 人工時計・予定・各完了/基準時刻と誤差。出典不明/実時計を装うfieldは通さない |
| simulation_evidence | anchors:参照→期待SHA、documents:参照→人工証拠JSON bytes。実運用証拠としては使用不可 |

証拠JSONはscope=SIMULATED_SCENARIOで、kind=DECISION_APPROVAL/TARGET_IDENTITY/SOURCE_POLICYを区別する。完全な入力生成仕様の実装例は人工Fixtureに限定し、実承認ファイルの雛形として自動保存しない。

既定はOPERATIONAL_READINESS、入力なしなら全8判断と各軸の不足を説明する。`--at`は人工シナリオだけで使用可能。実運用の評価時計を過去へ戻して承認を救済しない。`--execute/--force/--output/--reserve/--fetch`は存在しない。

| 結果 | exit | 意味 |
| --- | ---: | --- |
| OFFLINE_SIMULATION_VALIDATED | 0 | 人工承認/対象/予算/policy/時刻の条件成立のみ。実取得/保存/予測利用は不可 |
| NOT_AUTHORIZED | 0 | 未承認・不足を正常に説明。実運用入力は常にこの状態 |
| INPUT_ERROR | 1 | 型/JSON/option/path等のコマンド入力不正。失敗を業務未承認と区別 |

JSONには専用契約版/scenario、D1〜D8の個別結果、manifest/budget/source/timing、missing_decisions/missing_evidence/blocking_reasons、固定禁止値を出力する。`LIVE_AUTHORIZED`等は返さない。固定した同じ入力/評価時計なら再出力JSONは完全一致。Commandはstdoutだけで、レポートファイルも本番rootも作成しない。

## 5. 新規・関連回帰と128MiB

新規PHPテストはUnit `ApprovalPreflightTest.php`、Feature `PrestartApprovalPreflightCommandTest.php`。全入力・承認者・証拠・時刻は人工。実Rawや実2026資料を使っていない。

| 最終コード検証 | cases | assertions | failures/errors | warnings | skipped | exit | PHP peak bytes |
| --- | ---: | ---: | --- | ---: | ---: | ---: | ---: |
| 新規Unit＋Command Feature | 101 | 364 | 0/0 | 0 | 0 | 0 | 52,953,088 |
| 既存Bridge Unit＋STAT46差分Unit | 104 | 660 | 0/0 | 0 | 0 | 0 | 29,884,416 |
| 計（別プロセスの件数合算） | 205 | 1,024 | 0/0 | 0 | 0 | 全0 | peakは合算しない |

入力APPROVEDの自己申告/SHA改変/別判断の証拠流用/期限/取消/競合、revision/本人/カテゴリー、人工2026・年だけの将来対象・JS拒否、4試行/重複/停止/台帳不明/timeout、時計未知/境界/遅延/同offset、未承認予測接続、同入力のJSON一致を検査した。無承認JSと片源未取得の条件は初回必須として確認。実測clockや本番保存の受入ではない。

FeatureはHTTP fakeの送信0と、DB/Redis/filesystem/HTTP取得・Raw保存・旧Bridgeサービスを解決すると即失敗するbindingを併用。対象Commandはこれらを呼ばない。原input SHA/専用rootのファイル集合も不変。モデル更新経路は持たず、出力の更新数0と予測禁止値をassertした。

初回は83 passed/321 assertions、errors=1/failures=0（exit2、JUnitで確認）。テストの `Artisan::all()` が対象外Commandまで解決し、I/O禁止bindingが停止した。禁止ガードを解除せず対象Commandだけの定義確認へ修正した。失敗証跡も保持し、既存テストの削除・緩和・skip追加はしていない。

旧Bridge/STAT46回帰は人工FixtureのUnitだけを実行し、実Raw正常系Featureの再生・保存は今回行わない。ユーザーの過去全体4000 passed/42068 assertions/155 warnings/9 skippedを今回の実行結果へ読み替えない。通常全体・旧監査・全量build/reproduceは未実行。

## 6. 実コマンド・隔離・証跡

最終新規実行（隔離内）のコマンド:

```bash
/usr/bin/php -d memory_limit=128M artisan test \
  tests/Unit/Domain/Keirin/Scraping/ApprovalPreflightTest.php \
  tests/Feature/PrestartApprovalPreflightCommandTest.php \
  -d memory_limit=128M --bootstrap tests/Support/stat38-memory-bootstrap.php \
  --display-all-issues --do-not-cache-result \
  --log-junit /tmp/evidence/junit.xml --log-events-text /tmp/evidence/events.log
```

関連回帰は上記2pathを `tests/Unit/Domain/Keirin/Scraping/PrestartCaptureOfflineTest.php` と `tests/Unit/Domain/Keirin/Statistics/Stat46RosterDifferenceCalculatorTest.php` へ置き換えた同条件。PHP `/usr/bin/php` 8.5.4・PHPUnit12.5.31、Artisan/Collision経路。

既存bwrap方式で `--unshare-net`、root read-only、home/run/mnt/mediaを隠し、原.envを人工コメント.envでマスク、storage/bootstrap cacheをtmpfsへ隔離。DB設定はSQLite in-memoryでサーバー接続不能、FeatureはDBサービス自体も禁止。書込みは新規test logs・人工runtime・人工Desktop/Trashだけ。旧Raw/モデル/予測束はマウントしていない。

人工一時領域は既存 `CompositionResultTemporaryDirectory` の保持オブジェクトpathだけをretireへ渡す。失敗理由はTEST_FAILURE、成功はTEST_COMPLETE、parent teardownも必ず実行。rename-onlyで人工Desktop内のTrashへ退避し、本物のDesktop/Trash操作・永久削除・fallbackは0。

永続証跡root（本番収集rootではなく、今回専用のテスト証跡）:

`/home/shinya/neo-keirin-artifacts/prestart-capture-approval-preflight-01-tests/`

| 実行 | root直下の専用directory |
| --- | --- |
| 最終新規101件 | validation-20261026-062458-FhlRJHOC |
| 最終関連104件 | validation-20261026-062501-jmH2QtJr |
| 初回失敗も保持 | validation-20261026-061939-1L0UrjZ9 |

各 `logs/` にrunner.sh、非秘密command/PHP版、開始終了時刻、stdout/stderr、events/JUnit、exit、resource使用量、measurement.json、legacy-start/end.jsonと比較結果を保持。最終新規の `logs/static-verification.json` に15PHPのphp -l/限定Pint --test/ソースSHA、旧Bridge依存24ファイルの開始HEAD一致を保存した。全て成功・exit0。原本の再sealは行わない。重要証跡の唯一の保存先を/tmpにはしていない。

新規文書の相対リンク・表・コード区切り、MASTER PLANの最新8ゲート/固定禁止値・旧工程履歴、変更範囲を確認し、git diff --checkはexit0。変更は新規15PHP＋本書とMASTER PLANの17ファイルで、既存PHP/設定/Migration/テスト/Fixtureの修正はない。

## 7. 未実装の運用受入と次工程

運用契約第12節の初回25仕様を全て製造・受入済みとはしない。今回の適用/不足は次のとおり。

| 仕様範囲 | 今回できた範囲 | 未実装・未受入 |
| --- | --- | --- |
| T01〜T06 | 承認/固定対象/人工予算の前検査 | 実slot計画、送信直前排他予約、永続AttemptLedger、再起動/全run予算の実証 |
| T07〜T10 | source/policy/binding不足の説明。旧Bridge人工応答回帰 | 新実HTTP Transport、429/5xx/timeout/redirect/effective URIの実取得証跡 |
| T11〜T13 | 固定本人/カテゴリーの前検査と旧人工保存の回帰 | 新版でのRaw排他保存・fsync・source/Rawの独立検証 |
| T14/T15b | 将来条件付きのまま維持 | JS別契約・別工程の承認、共有Raw/複数race/二源比較 |
| T15a | SECOND_SOURCE_NOT_CAPTURED/二源未成立を固定 | 二源の実観測・統合・予測採用は未承認 |
| T16〜T17 | D5方針の人工承認検査、旧STAT46純粋差分回帰 | 実変更/中止/延期検出、残slot停止と再確認制御 |
| T18〜T20 | 人工UTC区間・完了時計・cutoff境界 | 実時計同期証拠、公式TZ/丸め方式/実発走の証明、新receipt |
| T21〜T24 | 原本read-only、旧seal不変、人工helper/予算不明の拒否 | 新版capture履歴・容量/権限/故障予約・保持/backup/復旧の実装受入 |
| T25〜T26 | 旧mode guard/独立Verifierの人工回帰、新JSONの決定性 | 新実取得mode/出典契約・保存資料だけの新版独立Verifier/公開完了receipt |

ユーザー決定が必要なのは、具体対象年/日/場/レースと本人/route根拠、slot/上限/停止再開責任、利用許諾/負荷/共有、JS別承認、公式変更情報源、同期方式/TZ/誤差上限、private root/容量/保持期限/backup責任、独立した予測利用Gate。今回の人工値・参照や成功を実承認へ昇格しない。

次は `REVIEW_APPROVAL_PREFLIGHT_CODE_AND_LIMITED_TESTS_ONLY`。PHP/テスト/文書を未コミットでレビュー待ちとし、自動で取得・本番保存・モデル接続へ進まない。DB/Migration/HTTP/2026開催/Raw再生/学習/親lambda選択/性能評価、commit/push/PR作成/mergeは未実施。
