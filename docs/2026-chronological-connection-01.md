# STAT-2026-CHRONOLOGICAL-CONNECTION-01

## 1. 範囲と開始点

2026-11-01。PR133はマージ済み。開始時のclean main/HEAD/ローカルorigin/mainは `124afa68ce2acbd52384d38a7e510ad7a0121c1d`、merge親はea37ec91/7c6d0e5、treeはe31e0bbf92616b7a972c78409d549c8ab975d487。未使用branch `feature/stat-2026-chronological-connection-01` を作成した。fetch/pull/GitHub照会は行わず、確認したローカル履歴とユーザー提示のマージを根拠とする。mainへの編集、add/commit/push/PR/mergeは行わない。

今回の受入はG1〜G5のオフライン製造・限定検証であり、契約は **ACCEPTED_FOR_LIMITED_IMPLEMENTATION**。実2026実行はfalse、O1実初期履歴はNOT_ESTABLISHED、O2の具体案は実装基準としてのみ受入済み。historical_as_of_available=false、prediction_use=NOT_AUTHORIZED、formal_adoption=false、points/confidence=NULL、B1/親lambda/H1-U/LIVE未承認を維持する。人工2026日付と実2026資料を区別する。

PR133の終了時点（HEAD827582a・文書3差分・PHPUnit/全体/Pint NOT_RUN）、後続ユーザー全体4,534 passed /46,563 assertions /155 warnings /9 skipped /391.67秒、ユーザー7c6d0e5 commit/push、124afa68 mergeを区別する。391.11秒/393.73秒/390.28秒は別の過去実行。警告全原因・明示exitは未確認であり今回の成功へ読み替えない。

## 2. 製造した接続

実装namespaceは `app/Domain/Keirin/Backtest/ChronologicalValidation/`。通常同期、旧BuildService、旧DB HistoryReader、旧ArtifactStore/ResultStore/Pipeline::reproduceは呼ばない。

| gap | 実装ファイル | 入力・実処理・保存先 |
| --- | --- | --- |
| G1 | Scope.php / RacecardSource.php | 固定候補・元ID対応とseal済みPJ0315を既存encoding/Parser/category policyで検証し、名簿順・得点・開催等のallowlistへ投影。既知不足と技術未処理を分離 |
| G2 | HistoryReader.php / StatEngine.php / PredictionAdapter.php | 固定epochのイベント/全出走者得点から13既存Calculator＋Registry/Operational＋4履歴を実計算。Layout/Predictor/scorer/E06とrankBaselineで保存C1/基準を固定 |
| G3 | Coordinator.php | 候補固定→全T×両方式終端→日別lock→開放receipt→当日結果→評価→翌日epoch。中断再開時は元終端Refを検証再利用 |
| G4 | ResultSource.php / FileResultSource.php / ResultMatcher.php / PrimaryEvaluator.php | receipt検証後だけ当日結果を読む。元本人/結果状態を照合し、保存Primaryによる5指標・方式別E・共通E_pair・coverageを算出。保存結果だけで再現 |
| G5 | Json.php / Store.php / BundleRules.php / RunVerifier.php | objectキー正規化・排他/不変公開・seal/正確inventory/依存/状態/件数/結果評価coverageを独立検証。失敗stageも保持 |

薄い入口は [ChronologicalValidationCommand.php](../app/Console/Commands/Keirin/ChronologicalValidationCommand.php)。--planはデータ/モデル/Storeを開かない。verify/reproduceはModelLoaderを解決せず、再推論しないことをテストする。

共有境界の限定変更:

- HistoryAggregatorの旧aggregate()は2022〜2025制約を保持し、aggregateChronological(...Scope)だけが明示scopeを通して同じ120日pre-meeting算術を使う。
- Batch02/03/04BuildOptionsDtoにArtifactCalculationSourceの代替を追加。DB run IDとARTIFACTの排他を必須とし、仮の数値IDを入れない。旧呼出しは既定NULL sourceで従来どおり。
- Batch04PositionBiasRepositoryのbucket加算/summaryだけをPositionHistoryBucketへ抽出。SQL選択と算術/feature版は変えない。新Readerも同じscoreContextとbucket算術を使う。
- 旧Dataset/Optimizer/Objective/Decoder/Contract::plan、STATの式・係数・bin・Registry順序・モデルbytesは変更しない。

## 3. オフライン入力契約

実行manifestは `run_id`、`material_kind`、`scope:{kind,from,to}`、`history:{history_event_from,sources}`、`candidates`。SYNTHETIC_TESTはAPP_ENV=testing、既存安全helperの作成記録付き専用root `fixture_root` を必須とし、capture/historyもその配下に限定する。LEGACY_COMPATIBILITYは2022〜2025のみ。実2026や不明scopeをforceで解除する入口はない。

| 区分 | 必要field / NULL・型 |
| --- | --- |
| 固定候補 | external_race_key=場:YYYYMMDD:2桁番号、race_date、track_code文字列、race_number正整数、original_race_id正整数または未解決NULL、entrant_count、meeting_id/start/end、day_number、context、entries、capture |
| 元本人対応 | original_entry_id/original_player_id正整数または未解決NULL、external_player_id登録番号文字列、bike整数。重複とRaw不一致は技術失敗。元IDを再付番しない |
| context | racetrack_id、race_day_id、meeting_grade、meeting_day_kind。現在DBプロフィールから補わない。day_kindを開催時間帯へ推定変換しない |
| capture | capture_id、source=PJ0315、method=POST、material_kind、source_status=VERIFIED、parser_version、private_ref、raw:{bytes,sha256}、content_type、fetched_at、saved_at。独立したオフライン証拠記述子であり実HTTP許諾receiptではない |
| 投影 | 固定race/meeting、Rawのrace_type/start/sales_close、元本人/車番/枠/級班/競走得点。結果・rank/status・払戻・オッズ・表示決まり手等はmodel inputへ渡さない |
| 時点 | input_as_ofは発売締切優先・予定発走fallback。予定発走を構成できない場合MISSING_SCHEDULE。実fetched/saved、過去input_as_of、logical_available_fromは別field |

epochのsourcesは `{path,seal:{bytes,sha256},material_kind}` の明示列挙。JSONLは各行1イベント、改行必須・1行1MiB上限、全sourcesを通した予定発走瞬間＋race IDの昇順・1race1固定版。自動探索や旧target別4集計値の使い回しはしない。

| 履歴イベント | 契約 |
| --- | --- |
| race | 元race ID/外部key、race_date、場/番号、entrant_count、meeting ID/start/end/day_number、race_type、offset付きscheduled_start_at/sales_close_at |
| context / entries | 同じ文脈と全出走者の元ID・登録番号・bike/frame/grade/race_score_raw。本人だけで得点percentileを作らず、相手欠損を0にしない |
| 結果field | race_status既知Enum、本人rank/status/winning_technique。正常着順整数と異常NULLを区別。source_id、verified bool、material_kind、入力版/結果版はepochのsource sealとresult Refで固定 |
| 時刻・由来 | input_fetched_at、fetched_at、saved_at、confirmed_at（不明NULL）、logical_available_from日付。日時はoffset必須、保存が取得より前なら拒否 |
| 適格性 | event_day < target_day、logical_available_from <= target_day、固定epochの列挙版、予定発走 < input_as_of、確定/訂正・男子。各STAT固有cutoffとC1の120日/同開催除外はさらに適用 |

Readerは対象本人のDTOだけを保持し、STAT39は母集団全出走、STAT42は当日coentrant同士のpairを集計する。本人4,096履歴・監査8,192件・日別256候補・bundle4MiB・payload cache1MiB・履歴処理96MiBの明示上限を超えると停止し、勝手に履歴を切り詰めない。実期間の容量/速度は未測定であり、全1月を実行可能と保証しない。

結果資料は `<result-root>/YYYY-MM-DD.json` の `{material_kind,date,results}`。存在/サイズ/hash/parseはその日のreceipt検証**後**だけ。最大32MiB。rowは固定race/key/date、result_version、source_id、official_status、fetched_at/saved_at、元本人＋rank/status/winning_technique。結果本文のsealを保存し、対象外候補は評価しない、候補外/重複は停止する。未取得は負け0へ変換しない。

## 4. STAT・モデル・母数

13STATは01、07、08、10、11、12、23、24、26、31、32、39、42。12学習信号は既存順序/Operational適格性でNULLを保持し、31整合検査と42の件数加重変換を既存Registryで行う。4履歴は公式結果由来の逃げ/捲り/差し/マーク、開催前120日。同開催除外・観測なしNULL・観測した0を区別する。stat_sourcesには全13結果/品質/理由/版/hash、source_ref/row_ref/execution_uuid、元IDを保存。DB由来run/result IDはNULL。history_used/excludedも記録する。

固定保存C1はTACTICAL-HISTORY-SEQUENTIAL-POSITION-v2、lambda0.1、anchor1.0、16信号・既存bin/係数/同点/E06。全員の正の有限raw、得点順位・母標準偏差・anchorを検証し、一人でも不適格なら名簿を縮めず両方式NO_PREDICTION。正しいanchor0/全員同点0は正常である。基準も同rawから結果開放前に保存する。新originはSAVED_MODEL_REUSE_NO_FITとし旧PredictorのEXPERIMENTAL_REFITラベルを新学習の証拠としない。

Primary指標は完全順序、1/2/3位置一致、上位3集合一致。方式別に同じE_method、比較は同じresult版のE_pairで再集計。全体は分子/分母を合計し日別率を平均しない。coverage=P/T、T=P+N+U。上位同着/上位欠け/中止を理由別除外し、下位のみ同着や異常な敗者だけを理由に除外しない。分母0はNULL＋UNEVALUABLE_ZERO_DENOMINATOR、未処理/未確定/本人不明はis_final=false。

## 5. 不変保存・再開

7種はvalidation_run/day_manifest/input/prediction/result/evaluation/history_epoch。各束はdata.json・manifest.json・LOCKEDの正確な3ファイル。objectキーだけ整列し、list順序・値の型・空object/listを保持する新正規化v1。排他的xb/0600、flush/fsync、永続flock、同filesystem非上書きrenameで公開する。失敗stage/診断は保持し永久削除しない。同ID同内容は検証再利用、相違はCONFLICT。

HEADERは固定scope/model/code/input identity、後続RUN_INDEXはday参照を持つ別ID。日別候補→全対象×2終端→lock→開放receiptは別の不変版。不能理由索引だけでは終端にならず、PREDICTEDのinput=NULLは禁止。既知不足の不能も両方式bundle・capture/不足理由を残す。結果は両終端を参照し、独立Verifierは日別receipt/結果/評価/次epochと件数を確認する。

技術未処理はUとして候補に残り結果Sourceを開かない。別request ID/input/model/code/contractで再予測へ差し替えない。訂正は新result/evaluationと未処理将来日のepochであり、旧予測/初回評価/既に固定した後続入力は不変。`correct()`は限定APIで、通常同期や訂正自動探索は未接続。

## 6. CLIと安全な実行条件

以下は隔離環境内の例であり実2026実行の指示ではない。入力manifestは既存helperの人工root、outputは新しい専用rootを使う。

```bash
php -d memory_limit=128M artisan keirin:backtest:chronological-validation --plan
php -d memory_limit=128M artisan keirin:backtest:chronological-validation \
  --execute-offline --input-manifest=/tmp/RECORDED_FIXTURE/input.json \
  --result-root=/tmp/RECORDED_FIXTURE/results --output-root=/tmp/NEW_STORE \
  --artifact=/home/shinya/neo-keirin-artifacts/tactical-history-final-01-20260917-01/fit/run-01/final/artifact.json
php -d memory_limit=128M artisan keirin:backtest:chronological-validation --verify --output-root=/tmp/NEW_STORE
php -d memory_limit=128M artisan keirin:backtest:chronological-validation --reproduce-evaluation --output-root=/tmp/NEW_STORE
```

4modeは相互排他。--plan以外にoutput-root、executeにはinput-manifest/result-root/artifactが必須。実executeは指定固定model/artifactのSHA/サイズと旧published構造検査を要求する。DB/HTTP fallbackはない。

検証環境は `/usr/bin/php` PHP8.5.4、128M。bwrapの原本read-only・ネットワークnamespace遮断・人工comment-only.env・tmpfs storage/bootstrap/cache・専用/tmpを使用した。実アプリ.env/storage/旧成果物を変更しない。FeatureではDB/Redis/HTTPへ到達すると例外になる依存を設置。CLI補助実行もネットワーク遮断・DB無効設定を維持する。通常利用環境そのものの再現や実運用許諾の確認ではない。

## 7. 受入対応

| 実装試験 | 契約ケース | 実際の確認対象 |
| --- | --- | --- |
| T01 | A01/A04/A05/A07/A08 | 人工2日・同日同時刻・5〜9車35名、13STAT/全12接続・4履歴・両終端・lock/receipt/結果/評価/次epoch |
| T02 | A01/A02、R3 | spyでlock前/技術失敗時読取り0、当日/未来結果だけ変えて同日input/予測Ref一致、FileResultSource偽receipt拒否 |
| T03 | A07/A08、R1/R2/R3/R4 | input保持/NULLの不能、証拠なし[]、schema/hash/本人異常U、不能のcoverage0・率NULL |
| T04 | A03/A05/A06 | Girls/Unknown/範囲外、全員anchor/全員同点0、禁止field・元本人不一致 |
| T05 | A03/A07 | 全員得点context、39母集団、42pair、Operational変換、120日/同開催、旧集計算術 |
| T06 | A04、R5 | 前日/同日/未来・利用開始境界・固定epoch・固有cutoff、旧年ガード |
| T07 | A04/A12、R6 | lock前/receipt後/評価前の中断、壊れたモデルを渡しても保存終端再利用、別run ID拒否 |
| T08 | A08/A12/A14、R1/R3 | inventory/破損/前方循環参照/同ID競合/別race-method/null Ref/receipt不足/未完了index/別PHP flock |
| T09 | A09/A10、R4 | 説明3例、同着/中止/異常/片方式/分母0/未取得/日別母数差 |
| T10 | A11/A12、R6 | 訂正append/将来epoch/旧Ref不変、保存評価の推論なし再現 |
| T11 | A13/A14 | 旧2026拒否、旧published load、下記実3入力のutility/確率/Primary/同点一致と原本前後seal |
| T12 | A03/A14 | 年偽装なし、実scope拒否、fakeのDB/HTTP・再bin拒否、fit0、verify/reproduceはModelLoader未解決 |

新規3クラスはChronologicalValidationTest / ChronologicalStoreAndEvaluationTest / ChronologicalValidationCommandTest。Support人工Fixtureは固定係数を構成するだけで学習しない。safe既存8クラス（Stat01、Batch02/03/04、HistoryAggregator、Probability、Metrics、Decoder）も限定回帰する。旧危険cleanupを持つ全体はNOT_RUN、通常全体回帰は未確認。旧テストの削除/緩和/skip追加や安全helperの一括変更はしていない。

## 8. 限定実測・証跡

証跡root: `/tmp/chronological-connection-20261101-4kK5MTlZ/`。人工root/stage/成功資料も保持し、Trash整理や永久削除は行わない。大型ZIP/モデル/元資料コピーは作らない。最終実測値と小型review-packetは本節末尾へ追記する。

| 最終コードの検証 | 件数 / assertions | exit | PHP peak bytes | warning / skip / failure / error |
| --- | --- | --- | ---: | --- |
| 新規Unit/Feature 3クラス | 58 /538 | 0 | 下記と同じ独立PHP | 0 /0 /0 /0 |
| 安全な既存8クラス | 80 /681 | 0 | 下記と同じ独立PHP | 0 /0 /0 /0 |
| 上記限定同時実行 | 138 /1,219 | 0 | 71,827,456 | 0 /0 /0 /0 |
| Artisan execute-offline（人工資料＋実保存C1） | 2日・5レース・35名、両方式T5/P5/N0/U0/E5 | 0 | 56,623,104 | stderr空 |
| Artisan verify | 36束・2日、inference0 | 0 | 50,331,648 | stderr空 |
| Artisan reproduce-evaluation | 保存評価5件、inference0 | 0 | 50,331,648 | stderr空 |
| 旧3入力の算術互換 | 3レース・16名、完全一致 | 0 | 10,485,760 | stderr空 |
| 通常全体 | NOT_RUN | 未実行 | 未計測 | 全体回帰未確認 |

限定PHPUnitは48.328秒、実PHP8.5.4 /memory_limit=128M。新規内訳26/370、28/156、4/12、既存80/681。PHP構文26件と限定Pint、JSON/Markdown・git diff --checkも確認した。最終stdout/stderr・JUnit・イベント・resources.json・実行時刻/exitは `acceptance-final-v2/`、Artisanの完全な引数/時刻/exitは `cli-synthetic-cli-final-v2/*.execution.json`、互換の原本START/ENDは `legacy-compatibility-legacy-final-v2/` に保持。これらはsandbox内/tmpを証跡rootへbindしており、報告する実パスには証跡rootを付ける。

実行した限定ランナーは次のとおり（script内でbwrap/人工.env/read-only/128Mを固定）。

```bash
bash /tmp/chronological-connection-20261101-4kK5MTlZ/run-tests.sh acceptance-final-v2 \
  tests/Unit/Domain/Keirin/Backtest/ChronologicalValidationTest.php \
  tests/Unit/Domain/Keirin/Backtest/ChronologicalStoreAndEvaluationTest.php \
  tests/Feature/ChronologicalValidationCommandTest.php \
  tests/Unit/Domain/Keirin/Statistics/Stat01CalculatorTest.php \
  tests/Unit/Domain/Keirin/Statistics/Batch02CalculatorsTest.php \
  tests/Unit/Domain/Keirin/Statistics/Batch03CalculatorsTest.php \
  tests/Unit/Domain/Keirin/Statistics/Batch04CalculatorsTest.php \
  tests/Unit/Domain/Keirin/Backtest/TacticalHistoryAggregatorTest.php \
  tests/Unit/Domain/Keirin/Backtest/Bt03e03ProbabilityTest.php \
  tests/Unit/Domain/Keirin/Backtest/Bt03e03MetricsTest.php \
  tests/Unit/Domain/Keirin/Backtest/Bt03e06DecoderTest.php
bash /tmp/chronological-connection-20261101-4kK5MTlZ/run-script.sh cli-final-v2 cli-synthetic.php
bash /tmp/chronological-connection-20261101-4kK5MTlZ/run-script.sh legacy-final-v2 legacy-compatibility.php
```

途中の新規試験/補助実行の失敗もrootに保持する。最初のE2Eは過剰な再帰検証を中断（exit130）し、1検証呼出し内のbounded memoへ修正。新規アサーションの0/0.0差、補助メソッド名とPHPUnit final methodの衝突、旧JsonlArtifactの必須sidecar不足を修正し、旧テストを緩和せず最終版で再実行した。これらの過去失敗ログを最終成功へ書き換えない。実運用の全期間性能は未測定。

限定旧入力互換の対象は、契約第3節のinputs-2025.jsonlを先頭から読み、許可入力が揃う最初の3完全レースと事前固定した。race ID12542/12543/12544、5/5/6名。結果を選択・予測・比較に使わず、旧Dataset::raw→binned→Predictorと新明示scope adapterのutility/確率/Primary/同点を比較する。許容1e-12より厳しいsemantic JSON完全一致で確認する。これは入力3件の算術互換であり2025性能再評価ではない。

| 原本 | bytes | SHA-256 |
| --- | ---: | --- |
| final/artifact.json | 3,524 | abdb0038b1fecbc2821c38f6dae08875876a4aa0f39c02abf60c23ca9b34bd6f |
| final/model.json | 80,928 | e3cc1f7f10af60bb22f97a2172ca52ef2c09a3393222ee46c25619de752d43a1 |
| final/layout.json | 51,189 | eb84b687ac6064fd107ceaa7e3659a83b9637452ea579c307c8f3eb4f0c7289e |
| inputs-v2/manifest.json | 3,823 | d5158a7671f8c453afc77dccc1ab0b089c940198f58f388c038ad0d4fad684cf |
| inputs-v2/inputs-2025.jsonl | 66,942,915 | a6a6a79303c5debe9c3b3b5cb1b4e37f86197c49afc7b3fc2a5f5cb444f1f0d4 |

model/artifact/layoutのrootは契約第3節F、旧入力rootはH。原本前後sealを照合し、5.7GB cache/全Rawを走査しない。

## 9. 未完了と次のレビュー

O1実初期epochは未確立。次に必要なのは、明示source一覧とseal、race/meeting/date/time、全出走者の当該得点・本人/車番/級班、公式状態/着順/決まり手、入力/結果版の取得保存証拠、logical_available_fromを持つ実イベントJSONLである。旧history-cacheに索引があるだけでは満たさず、全媒体に存在しないとも断定しない。

今回製造したReader/DTO/Calculator/Store/Coordinatorは人工統合で検証するが、実初期資料の完全性や全2026の容量・正解率は未確認。技術失敗stageの自動修復、運用HTTP/DB source、Scheduler、実観測承認・本番保存は実装/有効化していない。モデル再学習やλ選択は不要かどうかを本工程で評価せず、今回実行は0。次はコード/文書/限定証跡レビューのみであり、O1接続や実2026実行へ自動進行しない。

最終状態: **OFFLINE_CONNECTION_READY_FOR_REVIEW**。今回の限定完了とO1/実運用未承認は両立する。実データでの的中率/予測精度改善はNOT_MEASURED_THIS_CHANGE。

## 10. PR134 F1〜F10レビュー修正 / 2026-11-02

第1〜9節の138成功・全体NOT_RUNはCodex初回終了時の記録。その後ユーザーは全体20 failed /155 warnings /9 skipped /4,572 passed /46,817 assertions /389.89秒を報告した。明示exitと155警告の全原因は未確認。今回の限定成功を全体成功へ読み替えない。

修正開始は既存feature/stat-2026-chronological-connection-01、clean HEAD `639d1481e99ed8fe07ea9b66625edc17f1a16e62`、親/base/ローカルorigin/mainは124afa68。fetch/pull/main切替やブランチ作成、Git公開操作は行わない。原本・旧束・過去の不採用判断とO1/O2/実運用未承認は維持する。

### 10.1 原因と修正

| 指摘 | 原因 / 修正箇所 | 回帰の対象 | 実測結果 | 残件・適用範囲 |
| --- | --- | --- | --- | --- |
| F1 | HistoryReaderが共有全体の割当量を96MiBと比較。HistoryMemoryBudgetで実使用増分96MiB/実PHP上限と8MiB余裕を分離。rejectは理由と到達地点を検査 | 通常128M/先行100MiB・256M、真の既定96MiB超過、元20失敗 | 限定PASS、専用実測は10.4 | 通常全体は未実行 |
| F2 | seal確認だけの初期受入。Reader/StatEngineで公開前stream検証、BundleRulesで境界検査 | 当日/未来/未release行は技術失敗・予測/結果読取り0、前日release境界は通過 | 3拒否＋対照PASS | O1実資料未確立 |
| F3 | 不完全行を不足判定前に捨てる。Readerの通常STAT計数とC1証拠投影を分離 | 同本人・別開催・120日内はPARTIAL_HISTORY/4NULL、別本人/同開催/窓外の対照 | PASS | 実履歴接続はO1 |
| F4 | 39/42下限未適用とC1の2022固定。Reader/新Aggregatorへ明示取得開始を渡す | JST下限一致/直前、12月開始LEFT_TRUNCATED、取得開始不明は補完しない | 件数/値/4NULL PASS | manifestは明示開始必須、実完全性未確認 |
| F5 | 既知選手だけの平均等。HistoricalScoreContextを既存Batch03/04と共有 | 欠損/0入りは3値NULL、完全得点のみ算出。DTO/保存STAT31 COMPETITION_CONTEXT | 算術・保存値PASS | 新規実履歴の全量受入は未実施 |
| F6 | int/floatを?string DTOへ直渡し。Readerで既存raceScore()正規化 | 同一得点の文字列/int/floatでpair得点/計算値一致 | PASS、TypeError0 | source/bundle hashのbyte一致は要求しない |
| F7 | 無検査spread。InputManifest/Source/BundleRulesで構造別allowlist、本人4列＋Raw由来3列を投影 | 禁止列3ケースは技術失敗、正当NULL3ケースは不能、Raw表示項目を非投影 | PASS | 実2026入力未許可 |
| F8 | 元race IDのNULL同士比較/中止早期return。ResultMatcherで未解決と競合を分離 | NULL双方/片側、中止空行、既知競合、不正日時、翌日正常処理。未検証history_event=NULL | PASS | 本番DB照合なし |
| F9 | 固定1個の訂正epoch/通常epochへの巻戻り。Coordinator/EpochSelectionsで明示親/連番/hash版を保存 | 複数レース/同race v2→v3、4公開位置と候補固定後の中断再開、別系列/既固定日拒否、created_at保持 | 結合5ケース等PASS、旧Ref/bytes不変 | 人工checkpoint検証、実運用障害試験は未実施 |
| F10 | 既存IDで受領内容を無視/v>1をCORRECTED限定。ResultVersions/BundleRulesで内容・証拠比較と既存状態遷移 | 同版差/日別file seal差、暫定→確定/確定→訂正、全日固定後の追加、親/版飛ばし/退行拒否 | PASS、再現は旧/新版を個別確認 | 運用取得/実資料評価は未許可 |

### 10.2 結果版・epoch・索引の補足

7種のまま、validation_runへEPOCH_SELECTIONイベントを追加する。日付＋連番の既知IDでparent_selection/base_epoch/selected_epoch/updated_resultを辿り、mtimeや任意ディレクトリ名で最新を推定しない。epochは親＋採用result Ref＋対象日のhash ID。sources/inputs/取得範囲を変えず、raceごとに1つの明示採用版だけを保持する。候補固定後はそのepoch系列を検証再利用し、新訂正を適用しない。

resultはsource_resultに正規化した受領内容（元ID/本人/状態/日時/source_id/source_seal）を保存する。同じID/版で証拠が変わっても黙殺せずCONFLICT。FileResultSourceのsealは日別ファイル全bytesであり、他レースの更新でも同版の証拠が変わるため、その影響行にも新versionが必要である。自動で証拠差を無視したり旧Rawを再sealしない。

version>1は連続版＋同race親Ref＋既存canTransitionFromを必須とする。PROVISIONAL→CONFIRMEDをCORRECTEDへ偽装しない。correct()は結果/評価保存だけ（futureDay/parentEpochなし）と、未処理将来epochへの明示採用を分離する。全日が固定済みでも前者は可能。実行時刻だけが変わった再試行では保存済みcreated_at等を保持する。

RUN_INDEXはselected_results/selected_evaluationsを持つ不変snapshot。異なる選択版は別hash IDで保存し、1race1結果/評価だけを母数へ入れる。correct()単独による追加結果/評価は旧run索引を更新せず、旧索引は当時の集計を表す。明示した新版をexecuteで選ぶ場合も予測/lock/旧epochは不変、新索引を追加する。reproduceは全保存評価版を個別再現し、版を混ぜて合計しない。

### 10.3 今回の検証記録

証跡root `/tmp/pr134-review-20261102-F01yJOOW/`。前回の/tmp runnerは現存しなかったため、同じ許可範囲のbwrap runnerを新規作成した。原本read-only/ネットワーク遮断/人工.env・storage/bootstrap-cache/隔離/tmp、生成物と失敗資料は保持する。

修正前のbefore-highは、有限256M PHPへ100MiBの先行割当を保持して新規3クラス58件を実行。38 passed /4 assertion failures /16 errors、exit2となり20失敗を再現した。内訳は直接memory例外14、予定中断前のmemory停止を旧rejectが受け入れた後の欠落ファイル2・予測件数4。ユーザー全体と同じ原因経路を限定再現した事実であり、全体の完全再現ではない。

before-regressions-v2は履歴/入力不備を人工再現。追加テスト自体の補助名run()とPHPUnit final methodの衝突、DTOプロパティ名の誤記も中間ログへ保持し修正した。これらを本体の不具合と混同しない。before-version-fixesはF9/F10の10ケースを実行し、同版の差替え黙殺4件と更新/訂正条件6件の破綻を記録した。

最終実測・コマンド・T/A/R対応は次節と小型review-packetに記録する。通常全体は旧GrowthTrendFixture/StartObservationFixture等のunlink/deleteDirectoryと永久削除禁止が衝突するためNOT_RUNとし、旧helperの一括改変やテストskipは行わない。

### 10.4 最終コードの限定検証

通常受入は `/usr/bin/php8.5` /PHP8.5.4 /128M、**183 passed /1,545 assertions /exit0**、177.490秒、PHP peak **71,827,456 bytes**。新規3クラス58/544、F修正追加45/320、既存安全8クラス80/681。failure/error/warning/skip0、全体テストの成功ではない。

T01〜T12/A01〜A14/R1〜R6の元受入を維持し、F1をT01/03/07/08/12・A07/12・R3/6、F2をT02/06・A01/02/04・R3/5、F3〜F6をT05/06・A04/07/13・R5、F7をT03/04・A03/05/08・R1/2/3、F8をT03/09・A07/08/10・R2/4、F9/F10をT07/08/09/10・A04/09/10/11/12・R6へ対応させた。A13/14は別の限定実保存C1互換も併記する。重複するケース数を足し上げていない。

実行は `bash /tmp/pr134-review-20261102-F01yJOOW/run-tests.sh acceptance-final-v2 128M 0` に第7節の11ファイルと追加 `tests/Unit/Domain/Keirin/Backtest/ChronologicalReviewFixTest.php` を指定した。runner内部の実PHPUnit引数、全stdout/stderr/JUnit/events/開始終了/exit/resourcesを同名ディレクトリへ保持する。初回の最終候補179件中2件は厳密化した拒否理由の期待名が誤っており、実際のInvalid bundle directoryへ修正した後、限定6件と最終183件で再確認した。業務上の期待値や警告設定を緩和していない。

高ベースライン、既定作業予算の専用子PHP、実保存C1の限定互換、Artisan3mode、最終文書検証の個別実測はreview-packetとverification-summary.jsonを参照。通常全体NOT_RUN/全体回帰未確認、既存155警告の全原因・解消はNOT_CONFIRMED。実2026/業務DB/実HTTP/学習/予測性能評価0、旧原本/モデル/旧束は変更・移動・削除せず、未コミットでPR134差分レビュー待ちとする。

| 個別検証 | 実測 | PHP limit / peak bytes | 終了 |
| --- | --- | --- | --- |
| 同じ新規3クラスの高ベースライン | 58 passed /544 assertions、53.556秒。開始割当130,027,520 bytes（先行割当前25,165,824） | 256M /176,689,152 | exit0、failure/error/warning/skip0 |
| 既定96MiB作業予算の真の超過 | 専用子PHPでbaseline6,671,176 bytesの後に97MiBを割当。対象RuntimeExceptionを確認、PHP自体のexhaustionではない | 256M /112,201,728、作業上限100,663,296維持 | 親テストPASS、子exit0 |
| 限定実保存C1互換 | race12542/12543/12544、5/5/6名。確率/utility/Primary/同点完全一致、原本START/END不変、fit0 | 128M /10,485,760 | exit0 |
| Artisan人工E2E＋実保存C1 | 2日/5レース/35名、両方式T5/P5/N0/U0/E5、X0 | 128M /56,623,104 | exit0 |
| Artisan verify /reproduce-evaluation | 36束/2日検証、保存評価5件再現。両modeのinference0 | 128M /各50,331,648 | 各exit0 |
| F5旧Reader独立比較 | 旧HEADのReaderを隔離した別名クラスで実行。文字列得点100/90/80/70/NULLまたは0の旧mean85/max100/std11.180339887498949が、修正後はすべてNULL | 128M /10,485,760 | exit0 |

高ベースラインは `run-tests.sh after-high 256M 1` に元3クラスを指定した。F5独立比較は `run-script.sh f5-original-comparison f5-original.php`、旧入力互換は `run-script.sh legacy-final legacy.php`、Artisanは `run-script.sh cli-final cli.php`。全scriptは証跡rootに保持し、実コマンド/開始終了/exit/stdout/stderrを記録する。F5比較の旧コードSHAはdc707a8703adf5f2dfb4c3d410a10193e1a89b83265085b3bcb1b8a93ba0c217。現在の共有helperだけの自己比較でなく、旧Readerの不備をF6の型エラーと分離して確認した。

メモリ値の意味も分離する。runnerの開始割当/peakはPHP全体のmemory_get_usage(true)/peak(true)。機能予算はReader呼出し時のmemory_get_usage(false)からの実使用増分と実PHP上限/余裕であり、共有プロセスの開始割当を96MiBと比較しない。専用256M試験の成功は通常128Mの上限緩和ではない。

最終状態 **PR134_REVIEW_FIX_READY_FOR_REVIEW**。今回の限定修正受入であり、全体成功・155警告解消・O1確立・実2026精度改善を認定しない。
