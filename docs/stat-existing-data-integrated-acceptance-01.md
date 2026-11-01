# STAT-EXISTING-DATA-INTEGRATED-ACCEPTANCE-01

## 1. 目的と状態

2026-11-01 JST。既存固定入力と確定済みMVP仕様の20上流STAT＋品質監査STAT20 v3について、2025-01-01～01-04の全候補を固定し、統合生成・隔離保存・検証する。新しい計算式・Calculator・Migration・モデルは追加しない。

状態: **READY_FOR_REVIEW**。**既存固定入力・確定済みMVP仕様のSTAT統合生成／保存／検証は、この対象範囲で完了**。INVALID_INPUTを利用可能にする受入ではない。

開始clean main/HEAD/ローカルorigin/mainは `a25fcede1b9ba71ffa8b2ff293613340042ca779`。PR131 MERGEDの件名、親 `0de4bdd85d8df0ccd15ad835d360a5d1a5e57fdd` / `8faa7fe18d1b8e4756c70bf69bf37114e0d21bcb`、tree `92987cfa97f76ddca90413e0f02f77423aba8113` を照合した。未使用の `verify/stat-existing-data-integrated-acceptance-01` を作成。fetch/pull/外部GitHub照会は行わず、最新リモートの独立再確認とはしない。Git add/commit/push/PR/mergeなし。

今回の有限な完了範囲はこの固定対象と既定MVPである。全46原本要件・全期間・占術の完成、当時公開時点の証明、精度改善、予測採用とは区別する。B1未承認、method/selected_lambda=NONE、正式B1受入NOT_RUN、historical_as_of_available=false、prediction_use=NOT_AUTHORIZED、points/confidence=NULL、2026開催アクセス禁止を維持する。

## 2. 入力・原本と隔離

| 固定入力 | bytes | SHA-256 |
| --- | ---: | --- |
| 元snapshot manifest | 4,393 | 83942a1f25e39e6801c5d54223c267f29651812091536011852c433921a7d338 |
| PR131派生入力manifest | 19,823 | 4465e2656d3d72ff8768c8d041248eec62248b396634e314b7d862ab9b6a425a |
| PR130親manifest | 69,391 | 0c220b6ea0e406f426f7a0d8387a6aa60db2db64d4ccfc322f93552e71f7f273 |
| manifestのoriginal_sqlite＝親working_sqlite | 196,718,592 | 6ae8c40b468374d593d01da3d0fe38eb464bec55a8fe883735e98a9dbe790605 |
| 親manifest指定の学校selection.json | 1,826,814 | 725a4036244dac02519d1b05ee2b0e644036d9dc437c8e2b51ae7151276f4bb3 |

元snapshotは `/home/shinya/neo-keirin-artifacts/stat-existing-data-completion-01/snapshot-20261029-225509-adc95366/`。COMPLETE→manifest→7元JSONLのbytes/SHAを確認した。資料全体の25,976 race/184,353 entry/184,196 resultと、今回の352候補を区別する。
PR131派生入力は[天候修正記録](stat21-saved-weather-batch-01.md)のderive-logs-SzXZG2Gc/input/manifest.jsonから参照し、COMPLETEと親/SQLiteの対応を検証。天候用追加25,908 import/logの再抽出・再投入・天候再生成は0。
学校は[固定学校接続記録](stat-school-profile-connection-01.md)の親manifestから選択表を特定し、既存SchoolProfileSelectionService::loadで2,436本人/2,475版・全候補/取得版/参照sealを検証した。代表版再選択・catalog再生成・Raw全量再解析は0。

要件・残タスク・4分類は既存source-references.jsonの実在パス/bytes/SHAを照合。要件/残タスクのファイル名v1.0(2)と本文v0.1、4分類ドラフトv0.1を区別する。ADOPT_WITH_CHANGEや「既存データから集計可能」は、全要件実装完了・予測採用の承認ではない。原本のOPENを変更しない。

証跡root: `/home/shinya/neo-keirin-artifacts/stat-existing-data-integrated-acceptance-01/run-20261101-JvJB0moW/`。
元SQLiteを同rootのacceptance.sqliteへ**1回だけ**排他新規複製。初期153 run/1,177 result/153 itemを保持し、新しい統計行だけ追加した。bwrapの原本read-only/ネットワーク遮断/人工.env・storage・cacheと、既存隔離方式を再利用。元DB接続0、HTTP0、実2026開催参照0、Migration/Schema変更0。後片付けを必要とする処理を追加せず、永久削除・旧資料移動・提出用SQLite/Rawコピー・ZIPなし。

初回の別root `run-20261101-coiterB9/preflight-byoN7k77/` は、補助runnerが複製前の作業SQLiteへread-only接続しようとしてexit1で停止した。作業コピーは未作成、アプリ不具合ではない。失敗版を保持し、外部runnerの接続先だけを修正した新識別子が上記root。失敗や初回を成功へ読み替えない。実行中のrunner編集なし。

報告版review-V90LOgpuは、報告用隔離がstorageの追跡済み4つの.gitignoreも隠したため、差分範囲検査でexit1。実体の変更/削除ではない。失敗版を保持し、新しい報告wrapperではstorageを隠さずread-onlyに保護する。報告の再実行だけで、実資料の保存・再計算は繰り返さない。

## 3. 事前固定集合

| 日付 | 候補数 |
| --- | ---: |
| 2025-01-01 | 105 |
| 2025-01-02 | 89 |
| 2025-01-03 | 79 |
| 2025-01-04 | 79 |
| 合計 | 352 |

18場、候補352＝適格R352＋対象外0、対象出走N2,349・固有本人1,232。既存STAT01の全角Ａ級/Ｓ級・5～9車・日付条件と独立した元JSONL選別を照合し、結果の有無/着順/計算成功で対象を削っていない。target-scope.jsonlは401,622bytes / `50c4282d82f624675b57587b69cd8e0ab5c086979a8c1c9da969140abf932d10`。対象時刻欠落、本人未解決、重複車番、車立て/entry数不一致の事前診断は0。

history_from=2024-01-01は下限であり、2025年の適格な過去履歴を一律除外しない。対象input_as_ofより前、同一対象除外、16/27の同開催除外・開催開始cutoff、STAT33の専用診断契約を維持する。事後取得資料を当時取得済みとは認定しない。

## 4. 既存経路の統合保存

新STAT01親は**run154**。旧1/42/87等を再利用していない。既存Artisan CommandをConsole Kernel::callで実行する外部runnerを使い、各保存段階は別PHP・memory_limit=128M・chunk10とした。計算式や保存処理をrunnerへ移植していない。実wrapper/Artisan名・引数/stdout/stderr/時間/peak/exitは各段階のstage.php・common.php・runner.sh・artisan-command.jsonに保持。

全runはR352/N2,349、errors0・finished_atあり。STAT39だけSUCCEEDED、他20 runはPARTIALLY_SUCCEEDED。後者は下記結果状態によるもので、技術失敗とは混同しない。STAT41だけRACE粒度で352物理行、その他20 STATは各2,349出走者行。

| STAT | run | calculation_version | 保存行 | 結果状態 |
| --- | ---: | --- | ---: | --- |
| 01 | 154 | STAT-01-existing-db-v1 | 2,349 | VALID2230 / PARTIAL101 / INVALID_INPUT18 |
| 07 | 160 | STAT-07-existing-db-v1 | 2,349 | VALID1983 / NO_HISTORY366 |
| 08 | 161 | STAT-08-existing-db-v1 | 2,349 | VALID2277 / NO_HISTORY72 |
| 09 | 169 | STAT-09-acquired-career-grade-breakdown-v3 | 2,349 | PARTIAL2348 / NO_HISTORY1 |
| 10 | 155 | STAT-10-existing-db-v1 | 2,349 | VALID2344 / PARTIAL_HISTORY4 / NO_HISTORY1 |
| 11 | 156 | STAT-11-existing-db-v1 | 2,349 | VALID2347 / PARTIAL_HISTORY1 / NO_HISTORY1 |
| 12 | 157 | STAT-12-existing-db-v1 | 2,349 | VALID2348 / NO_HISTORY1 |
| 16 | 170 | STAT-16-six-condition-performance-v3 | 2,349 | PARTIAL2348 / NO_HISTORY1 |
| 20 | 174 | STAT-20-acquired-history-v3 | 2,349 | PARTIAL2331 / INVALID_INPUT18 |
| 23 | 162 | STAT-23-existing-db-v1 | 2,349 | VALID2330 / NO_HISTORY19 |
| 24 | 158 | STAT-24-existing-db-v1 | 2,349 | VALID2340 / PARTIAL_HISTORY8 / NO_HISTORY1 |
| 25 | 171 | STAT-25-disqualification-history-v2 | 2,349 | VALID2347 / PARTIAL_HISTORY1 / NO_HISTORY1 |
| 26 | 159 | STAT-26-existing-db-v1 | 2,349 | VALID2348 / NO_HISTORY1 |
| 27 | 172 | STAT-27-six-condition-experience-v3 | 2,349 | PARTIAL2348 / NO_HISTORY1 |
| 31 | 163 | STAT-31-existing-db-v1 | 2,349 | VALID2348 / NO_HISTORY1 |
| 32 | 164 | STAT-32-existing-db-v1 | 2,349 | VALID2284 / NO_HISTORY65 |
| 33 | 165 | STAT-33-existing-db-v1 | 2,349 | VALID1367 / NOT_APPLICABLE779 / NO_HISTORY201 / PARTIAL2 |
| 39 | 166 | STAT-39-existing-db-v1 | 2,349 | VALID2349 |
| 41 | 168 | STAT-41-existing-db-v1 | 352 | VALID334 / PARTIAL18 |
| 42 | 167 | STAT-42-existing-db-v1 | 2,349 | VALID2193 / NO_HISTORY151 / PARTIAL5 |
| 44 | 173 | STAT-44-acquired-longitudinal-v2 | 2,349 | PARTIAL2348 / NO_HISTORY1 |

新規21 run /47,332 result（20N＋R）/7,392 item（21R）。保存後は計174 run /48,509 result /7,545 item。Batch05にhistory-fromを追加せず、新5の09だけgrade-breakdown-v3、44だけ固定学校indexを指定した。20v3へ親以外の19 runを全て明示し、最新run自動検索・他期間/他STATの混在なし。保存Service v3/lineage v1、6条件等重み/時間減衰なし、09独立2軸、44デビュー日の仕様は不変。

### 入力不正と品質状態

元競走得点の非正値18出走は、既存契約の `RACE_SCORE_NON_POSITIVE_UNRESOLVED` のままSTAT01 INVALID_INPUT。STAT20も同じ18出走を `UPSTREAM_INVALID_INPUT:STAT-01` でINVALID_INPUTとして保持する。計36結果行だが本人・出走の母集団は18であり、36出走と数えない。補完・DB補正・正値への変換・対象台帳からの除去は0。**当該入力を利用可能とは認定しない**。残る101のSTAT01 PARTIALや18のSTAT41 PARTIALも分母から消さない。
NO_HISTORY/PARTIAL_HISTORY/NOT_APPLICABLE/DEGRADED等の意味を維持し、率やconfidence、全46要件の完成率と混同しない。状態別件数と複数理由の内訳は小型JSON/CSVへ保持する。

## 5. 保存後検証・別PHP再計算・独立検算

保存後の全件再読込は完了。全run/item/resultの終端・件数・状態累計、固定race/entry/player/bike/input_as_of、20 source map、新5の親run/STAT01 hash/lineageを照合した。技術失敗・未処理・未説明欠落0。学校接続はN2,349＝接続2,349＋未接続0で、全候補から事前に接続成功を仮定していない。

別PHPの同実装再計算は全21 STAT・47,332行のfeatures/evidence/status/quality/input_hashと本人が一致。各段階のService dry-runもexit0、SQL書込み0、作業SQLiteのSHA不変。新runは追加しない。保存済み結果を読むだけ、またはdry-runの件数だけで全値一致としたものではない。

再計算は保存時と同じ親/source map/history_from/09profile/学校表を使い、Repository→Calculatorを直接実行。新5 evidenceにAcquiredHistoryLineage::attachを同じ親/hashで適用する。保存batch_execution_uuidを復元し、features/evidenceの保存JSON・status/quality/hash・本人を比較する。既存Modelの配列保存castを通した表現を比較対象にし、比較器独自の型変換・丸め・NULL置換はしない。日時/PID等の新しい検証実行識別子だけは別記録とし、本人・版・履歴ID・cutoff・lineageは比較から除かない。

独立検算はCalculator/履歴Repositoryを使わず、元JSONLと元SQLiteの原行・保存行から集合/件数/本人/source対応を検査する。09/25/44のhistory_reference_idsも全IDの本人・元日時を直接照合する。16/27の個別history_referencesは採用/除外を分け、採用分に開催cutoff/同開催除外を検査する。この検算を全20計算式・全Raw Parserの独立再実装や、当時の公開時点の証明としない。

| STAT | 元行へ照合したhistory_reference_ids | 2025年の共通参照 | 個別参照の採用 / 除外 |
| --- | ---: | ---: | --- |
| 09 | 203,598 | 1,521 | 個別採否形式とは異なる |
| 16 | 203,598 | 1,521 | 200,847 /2,751 |
| 25 | 203,598 | 1,521 | 個別採否形式とは異なる |
| 27 | 203,598 | 1,521 | 200,847 /2,751 |
| 44 | 203,598 | 1,521 | 個別採否形式とは異なる |

件数は各対象に対する参照の延べ数で、固有レース数でも追加出走数でもない。16/27の共通参照には個別段階で除外されるものを含む。各々の採用参照200,847のうち2025年は32件で、開催cutoff以前・別開催を確認した。共通参照の最古2024-01-01 09:00/最新2025-01-03 23:30 JSTは元行照合の集約値であり、その2値だけで全履歴確認を代用していない。
STAT20の上流結果ID/run/版/hash/status/本人を全件照合し、STAT41の物理352行と、各出走者へ参照される監査上の2,349件を区別した。後者のSTAT41状態はVALID2,230/PARTIAL119で、物理行のVALID334/PARTIAL18と矛盾しない。
全業務表・既存153/1,177/153行のID上限付き全内容digest、schema、利用元manifest/JSONL/学校資料・SQLite・実行アプリコードのEND sealは一致。作業SQLiteは新統計行追加により1,745,739,776bytes / `3dbddfc6245410ab7226d04f99c2479f5967ebc72cc5191b5242464e46984148` へ変化しており、元SQLiteのSHA不変とは分ける。

## 6. 実行・テスト範囲

各保存・検証PHPは起動時128M、chunk10を維持。PHPは `/usr/bin/php` 8.5.4。成功した実資料25段階は各exit0、時間合計516.263秒、最大PHP peak92,278,784bytes（STAT20保存）。これは段階の実測合計で、調査/文書作業を含む総wall timeや所要時間の保証ではない。保存後全件再読込は13.291秒/peak16,777,216bytes、最後のSTAT20再計算＋dry-runは54.251秒/peak90,181,632bytes、独立検算は11.131秒/peak31,457,280bytes。全段階の資源表はexecution-resources.csvへ保存する。
アプリ/テスト/設定/PHP計算コード無変更の受入工程なので、新規PHPUnit・通常全体・PintはNOT_RUN。第8節に従う今回の検証仕様であり、PR131だけの保留承認を恒久承認へ拡張していない。外部runnerの構文、文書リンク/表/コードブロック、差分範囲、git diff --checkを確認する。既存の危険cleanupを起動せず、helper一括修正・新skip・assertion緩和・警告抑制なし。

PR131の時系列は分けて保持する。

1. Codex終了時は限定169 tests/4,084 assertions、通常全体NOT_RUN、未コミット。
2. 後続ユーザー実行の提示集計は4,534 passed/46,563 assertions/155 warnings/9 skipped、390.28秒、失敗表示なし。明示的exit codeと環境変数状態は未提示。
3. ユーザーの8faa7fe commit/push後、PR131がa25fcedでmainへmerge。
4. 今回は既存コードを変更せず、固定352レースの実資料受入を実行。上記全体テストをCodex実行や警告全解消へ読み替えない。

## 7. 有限な完了範囲・残件と提出

「既存固定入力・確定済みMVP仕様のSTAT統合生成／保存／検証は、この対象範囲で完了」と判定する。全46/生涯/全期間、全上流Rawの独立検証、公開時点、精度改善、正式採用は対象外。18非正値得点は未確認・利用不可を保持する。LINE/市場/公式時点等の入力待ち、適性式/補正/配点等の方式未確定、完了した機能、任意の追加網羅検証を分離し、対象を際限なく広げない。
別工程の02/03/17/19/21/30/35/36/37/38/46等は既存の限定受入記録を保持し、今回再実行したとはしない。C1/B等のモデル・既存不採用・B1未承認の状態を変更しない。

小型review-packet.txtには実数表・結論・未確認事項を含め、stat-run-summary.csv、execution-resources.csv、検証JSON、実行/比較script、変更差分とmanifest/COMPLETEを新規保存する。SQLite/Raw/全履歴の提出用コピーは作らない。レビュー待ちで未コミット停止する。
**受入レビュー後の次工程は「2026時系列検証の使用STAT・入力・予測方法・保存順序・評価指標の固定準備」**。その準備へ全期間受入や全46/全占術完成を新しい永久条件として追加せず、今回2026資料の参照・学習・予測・性能評価を開始しない。
