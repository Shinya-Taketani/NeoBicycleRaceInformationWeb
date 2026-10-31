# STAT-SCHOOL-PROFILE-CONNECTION-01

## 1. 到達点と開始状態

2026-10-31 JST。開始main/HEAD/ローカルorigin/mainは `3e52289f08940dd9a9e131bf72302690dd4bc4ef`、cleanとPR129 merge履歴を確認した。新しい `feature/stat-school-profile-connection-01` で製造し、未コミット `READY_FOR_REVIEW`。外部GitHub照会/fetchは行っていない。

今回の有限な完了条件は、封印済み学校資料集合の全取得版説明、代表版の明示固定、同一run内の複数本人接続、保存/再読込/反復と既存STAT20v3接続である。下記実資料で成立したため、この資料集合の「明示接続機能」は完了とする。学校資料接続の「対象拡大」を曖昧な未実装残件として残さない。全選手・全期間への統計再計算/網羅検証は対象と目的を別途固定する後続作業である。

```yaml
current_phase: STAT-SCHOOL-PROFILE-CONNECTION-01
state: READY_FOR_REVIEW
fixed_school_set_connection: COMPLETE
historical_as_of_available: false
data_role: RETROSPECTIVE_ONLY
prediction_use: NOT_AUTHORIZED
formal_adoption: false
points: null
confidence: null
method_b1_status: PROPOSED_NOT_APPROVED
method_selected: NONE
selected_lambda: NONE
formal_b1_data_acceptance_status: NOT_RUN
production_db_access: 0
production_write: 0
http: 0
2026_race_access_this_phase: 0
```

## 2. 読取元と契約

AGENTS、MASTER PLAN 5.0/15.94/引継ぎ、[全46台帳](stat-existing-data-completion-01.md#10-全46statの現在地stat-20統合工程2026-10-30)、[PR129記録](stat09-grade-breakdown-01.md)、[STAT20記録](stat20-acquired-history-integration-01.md)、要件原本STAT02/03/44・残タスク対応節・4分類ドラフトv0.1を確認した。要件のファイル名v1.0(2)と本文v0.1を区別する。正式同期人数/分布・測定基準日・減衰/補正係数・キャリア局面は未確定、SCR原本のOPENは変更しない。原本の実在パス/bytes/SHAは新証跡 `source-references.json`。

旧学校束の親は `/home/shinya/neo-keirin-artifacts/stat-existing-data-completion-01/`。個々のprofiles/replay直下にmanifestがあるとは推測せず、`review-20261029-232559-4c19a7f2/manifest.json` → seal済み `executed-artifacts.json` → profiles/profile_replayの参照を使った。親COMPLETEとの一致と各参照のサイズ/SHAを照合した。

| 資料 | 今回確認したSHA-256 |
| --- | --- |
| 学校親review manifest | 00eea837cb6eef329479a080dde31f7bb23ff286052bf050ab91d6739d763e6a |
| profiles-20261029-231457-8cd88660/projections.jsonl | 3782e24460ef42ddb738d56c1b1c655b160eb123e8e0032d46d23b27c44929a7 |
| 同sources.jsonl | a3528d084603aced446421c30a44822a7ddda0ba1947414de1e0000dd1be5195 |
| profile-replay-20261030-081537-uZACw1m8/profiles-20261029-231538-8b54bdfe/projections.jsonl | 3782e24460ef42ddb738d56c1b1c655b160eb123e8e0032d46d23b27c44929a7 |
| PR129 manifest | 31513378c46c6c07316f09eb04439d55ce5d8cb850be5c0fdc96f3d3bc2c10eb |
| PR129 manifest指定の保存後SQLite | 160c06eeaeaaa53e0d3d86c5476ac1f82ca963553ba4c323e44e1078755a7c07 |

保存済み学校source一覧は取得ログ由来の外部ID/URL/Raw path/bytes/SHA/取得日時/Parser版を持つ。投影とのID・取得版・Raw seal・同一取得瞬間を全件照合した。今回は元Raw本文の2,475件再走査や新規Parser再投影をしていない。旧再投影一致の記録と、今回の投影・出典metadataの再検証を区別する。取得日は2026年でも学校資料であり、2026年開催のレース資料を参照したものではない。当時の公開/取得可能性は未証明。

## 3. 実装と保存形式

| 追加/変更 | 責務 |
| --- | --- |
| BuildSchoolProfileCatalogCommand / SchoolProfileCatalogService | 既存review manifestから入力一覧を確認、JSONL逐次読取、取得版不変保存/索引/診断/成功終端・独立再読込 |
| SelectSchoolProfilesCommand / SchoolProfileSelectionService | 全候補検証、デビュー日だけの固定選択、項目別状態/競合、選択表読込時の規則再照合 |
| SchoolProfiles/Files / Projection | 限定的な排他保存・seal・有界読取、v1本人/取得版/出典/制限検証。汎用Hasherは不変 |
| SchoolProfileIndexOptionsDto | 選択表の絶対パス/SHAをServiceへ明示的に渡す |
| BuildAcquiredHistoryFeaturesCommand / AcquiredHistoryFeatureBuildService | STAT44専用の新入力入口、本人ごとの読取と既存本人照合、接続分母/理由とrun metadata。transaction/lineage/失敗監査を維持 |

台帳は `SCHOOL-PROFILE-CATALOG-v1`。`versions.jsonl` は元投影JSON文字列・行参照/SHAと出典recordを各取得版で保持し、`index.json` の検索順序/offsetとは分離する。原投影2,475行すべての値・型・キー順・source配列が保存/再読込で一致した。整数/浮動小数や原文を並替え/合成しない。同内容重複と同fetch logの本人/Raw/投影矛盾を区別し、後者はFAILED診断と途中spoolを残して停止する。余分なFAILED/欠落/不正ファイルを成功台帳として読まない。既存出力は上書きせず、dry-runは台帳/選択/統計DBとも書込み0。

選択表は `SCHOOL-PROFILE-STAT44-SELECTION-v1`、用途は `STAT44_DEBUT_ONLY`。catalog manifest SHA、本人、候補/選択fetch log・Raw SHA・投影SHA、理由を固定する。全候補の完全性/日時を確認後、次の規則を適用する。

| 条件 | 選択/未選択 |
| --- | --- |
| 有効なデビュー日が1版 | SINGLE_VALID_DEBUT |
| 複数版の有効日付が全て同じ | CONSISTENT_DEBUT_EARLIEST_FETCH_THEN_ID。取得瞬間が最も早い版、同時刻はfetch_log_id昇順 |
| 異なる有効日付 | DEBUT_DATE_CONFLICT、未選択 |
| 有効日付なし・原文欠損のみ | MISSING_DEBUT_DATE、未選択 |
| 非欠損の解析不能原文あり（有効版との混在も含む） | INVALID_DEBUT_DATE、未選択。日付競合もある場合は競合を先に記録 |
| 有効日付と単なる項目欠損 | 欠損候補を保持し、有効候補から選択可 |
| 順位/200m/卒業期だけが相違 | 項目別競合を残すが、一致デビュー日のSTAT44選択は止めない |

取得日時は比較用コピーだけを既存AcquiredHistoryTimestampで解析する。offset/JST・microsecond・同一瞬間を尊重し、欠損/不正時刻は拒否。原値は保持する。最早取得は事後資料整理の代表固定であり、予測時点の取得済み証明ではない。卒業期の原文は投影v1に存在せず、全版で `NOT_RECORDED_IN_PROJECTION_V1` と明記する。NULL原文同士の一致とは扱わない。

新オプションは `--school-profile-index` / `--school-profile-index-sha256`。旧単一 `--school-profile` / `--school-profile-sha256` と未指定は維持する。方式の同時指定、孤立SHA、未知契約、新方式の他STAT指定を書込み前に拒否し、Service直接呼出しも検証する。
本人の選択済み投影だけを既存matchedSchoolProfileへ渡す。本人/取得版/SHA違いはfallbackしない。未選択は学校なし経路のNULLを保持。接続件数/未接続理由は対象分母付きでrun metadata・実行報告に保存し、結果のdebut_evidenceの従来sourceから取得版を追跡する。レースtransactionのcommit後だけ接続累計を加算する。

STAT44 v2のCalculator/式/features/evidence/hash、投影v1、保存Service v3、lineage v1、09旧/新版・他STAT/6条件/STAT20 v1/v2/v3/既定値は変更しない。新方式のcatalog/selection SHAや別人・パス・処理順は本人のhashへ追加しない。同じ投影を渡せば単一/一括の全結果が一致する。STAT02/03をSTAT20の20項目へ増やしていない。

## 4. 学校全取得版の実受入

証跡root: `/home/shinya/neo-keirin-artifacts/stat-school-profile-connection-01/run-20261031-31MQOE3K/`。
`catalog-qRf3ccnx/logs/` のアプリCommand実行で全入力を処理した。旧報告値を今回値に流用せず、実測して一致した。

| 項目 | 実測 |
| --- | --- |
| source行/投影行/処理した固有取得版 | 各2,475 |
| 固有本人 | 2,436 |
| 同一取得版の重複行/対象外/不正/識別競合 | 各0 |
| 複数版を持つ本人 | 18人、本人ごとの追加取得版は計39。1版2,418人/2版8人/3版1人/4版7人/5版2人 |
| 学校順位 | 有効1,693/欠損782/不正0 |
| 200m | 有効654/欠損1,821/不正0 |
| 卒業期 | 数値有効2,475、原文未記録2,475 |
| デビュー日 | 有効2,475/欠損0/不正0 |
| 順位/200m/卒業期/デビュー日の値競合本人 | 各0 |
| STAT44選択済み/未選択 | 2,436/0 |

順位/タイムの欠損は、処理できなかった取得版ではなく、原投影に記録された項目欠損である。39追加版を「同一取得版重複」と誤表示しない。実資料では日付競合等を観測しておらず、合成境界テストの検証と区別する。
固定選択表SHAは `725a4036244dac02519d1b05ee2b0e644036d9dc437c8e2b51ae7151276f4bb3`。統計計算前に生成し、全反復/再現で同じ表を使用した。

## 5. STAT44/20の隔離保存と再現

PR129 manifestのworking_sqlite参照から実体を特定し、サイズ192,643,072bytes・SHAを確認。`acceptance.sqlite` へ1回だけ作業用複製した。実開始は143 run/1,099結果/143 item。主2レースの旧44は20v3 run138/142のsource mapからrun120/132を使用し、追加race61724は実レコードから学校接続済みv2 run110/111を確認、run110の条件を使用した。history_fromは3件とも2024-01-01、input_as_ofと本人はそれぞれ指定STAT01 run/resultで照合した。

| race・対象日 | 元01 | 出走/固有本人/接続/未接続 | 新44 run（各2回） | 新20v3 run（各2回） | 20の固定44 source |
| --- | --- | --- | --- | --- | --- |
| 12773、2025-01-04 | 1 | 7/7/7/0 | 144,145 | 146,147 | 144 |
| 12901、2025-01-04 | 42 | 9/9/9/0 | 148,149 | 150,151 | 148 |
| 61724、2024-12-06 | 87 | 7/7/7/0 | 152,153 | 対象外 | 対象外 |

今回実測は23出走・23固有本人、接続23/未接続0。新44は6 run/46結果、新20は4 run/32結果、計10 run/78結果・10 item。全PARTIAL、error0、最終153 run/1,177結果/153 item。各run/item/resultの件数・終端/finished・失敗数を照合した。20の他19 sourceは既存mapのまま、2回目の44 sourceを差し替えていない。
学校なし旧44の16結果、学校単一接続済み旧v2 run110/111の計14結果を従来経路で再現した。同じ選択投影で単一方式との全23本人のfeatures/evidence/status/quality/hash一致、新44/20の反復一致、旧20v3の16結果も再現した。比較専用runは作っていない。

| 工程 | 内容 | 128Mでのpeak / 所要時間 / exit |
| --- | --- | --- |
| 学校台帳/選択 | 2,475版処理/再読込/元投影一致 | 48,238,592bytes / 0.296442秒 / 0 |
| 隔離受入保存 | 上記10 run/78結果、旧互換・反復 | 125,833,216bytes / 3.103712秒 / 0 |
| A 別PHP同実装再計算 | 44の46行/20の32行一致、最終Service dry-run44=23/20=16、台帳2,475版/選択2,436人dry-run | 94,375,936bytes / 0.908690秒 / 0 |
| B 独立算術 | 元投影/出典/対象JST日時から23本人・46行照合 | 10,485,760bytes / 0.146594秒 / 0 |

Aは `reproduce-ZnrP3sTG/logs/`、Bは `recount-vtUAr2eo/logs/`。両方PDO mode=ro＋query_only、書込み0/SQLite SHA不変。Aを独立算術とは呼ばない。Bは新選択処理/Stat44Calculatorを使わず、元投影の全候補から同日/取得瞬間/ID順を別計算し、本人/元STAT01/source/投影SHA・デビュー日・経過日数（JST秒差の完全日数）・年数（暦年と記念日）を照合した。

保存時のコードsealは `acceptance-BOiaVicd/logs/code-seals.json` として保持する。その後のSchoolProfileCatalogServiceの有界index読取・失敗診断/入力END検査・Pint整形は旧生成sealへ上書きしない。最終コードでは台帳/選択/統計Serviceのdry-runと全78行の別PHP再現を行い、`reproduce-ZnrP3sTG/logs/final-code-seals.json` のSTART-END一致を確認した。保存runを増やさず、最終コードで追加保存したと偽らない。
元SQLite/旧manifest/学校JSONL・再投影束/単一投影のSTART-END seal、コピー内既存統計行のID上限prefix digestとIDを持つ全業務表digestは不変。Rawは原本read-only/本物storageをnamespaceで不可視化して保護し、全Rawの新しい内容hash監査を実施したとはしない。保存後作業SQLiteは196,718,592bytes、SHA `6ae8c40b468374d593d01da3d0fe38eb464bec55a8fe883735e98a9dbe790605`。

## 6. テストと実コマンド

最終限定14ファイルは `tests-DfQbiCiC/logs/`。225 passed/1,888 assertions（新規41/178＋既存184/1,710）、failure/error/warnings/skipped0、exit0、7.49秒。PHP8.5.4・Artisan/Collision→PHPUnit12.5.31、本体/子とも128M、子shutdown計測peak78,118,912bytes。旧テスト削除/緩和・新規skip・警告抑制なし。通常全体は未実施。

全候補後判定、日付競合/不正原文混在、項目だけの競合、同内容重複/取得版衝突、source/投影/選択SHA・本人の誤り、UTC/JST/microsecond/年境界、同run複数本人、単一/未指定互換、別人追加/順序/パスのhash非混入、未来日付、孤立/交差オプション、dry-run、rollback/失敗item、20v3接続/親誤りを検証。旧09/新09/16/25/27/44・20v1/v2/v3・6条件・共有保存の途中失敗回帰も含む。
限定Pintの整形前後コードとSHAは `format-q4wEDlT3/`、最終構文/Pint --test/diff/文書検証はquality記録。途中の34/155・224/1880やAの旧計測は上書きせず、最終値と区別する。

アプリCommand例（今回の封印済み入力と隔離SQLite用。本番実行承認ではない）:

```bash
php -d memory_limit=128M artisan keirin:statistics:build-school-profile-catalog \
  --source-manifest=<旧review/manifest.json> --source-manifest-sha256=<SHA> --output=<新規catalog>
php -d memory_limit=128M artisan keirin:statistics:select-school-profiles \
  --catalog=<catalog/manifest.json> --catalog-sha256=<SHA> --output=<新規selection.json>
php -d memory_limit=128M artisan keirin:statistics:build-acquired-history \
  --stat=STAT-44 --stat01-run-id=1 --race-id=12773 --history-from=2024-01-01 --chunk=10 \
  --school-profile-index=<selection.json> --school-profile-index-sha256=<固定SHA> --dry-run
php -d memory_limit=128M artisan test <command.txt記載の限定14ファイル> \
  -d memory_limit=128M --bootstrap tests/Support/stat38-memory-bootstrap.php \
  --display-all-issues --do-not-cache-result
```

実際の引数/終了コードはcatalog各command JSON、testsのcommand.txt、各stageのrunner.sh/stage.php/stdout/stderr/時刻/resourcesが正本。新規機能は外部runnerだけで代替せず、アプリCommand/Serviceとして製造した。実統計保存は同Service/Optionsをrunnerから呼んだ。
bwrapの原本read-only/ネットワーク遮断/人工.env/storage/cache、隔離SQLite、人工Desktop/Trashと既存rename-only helperを維持。永久削除なし。新Migration/Model/業務DB変更なし、書込みは新成果物と作業SQLite内の新統計行だけ。

## 7. 残件と引継ぎ

今回の固定資料集合の接続未解決/本人競合は0。実データに現れなかった日付競合・不正等は合成テストであり、全選手・全期間で無競合という結論ではない。正式同期人数、200m分布/測定基準日、卒業期の未記録原文、時点公開証明、補正/減衰/キャリア局面の方式は未確定。原本SCR全条件やSTAT02/03/44全要件の完成ではない。
次の具体的残件はSTAT21の既存天候原項目投影の一括実行・対象拡大。W45以外は完全な結果/import/log/Raw対応を先に確認してから扱う。本工程へ混在させず、次工程の承認/範囲固定前に実行しない。
後続ユーザー方針「終了済み2026レースを時系列に情報取得→予測固定→結果取得→正解率確認」を維持する。今回その取得/学習/予測を開始せず、全46/B1/占術完成を新しい永久条件にしない。B1/親lambda/H1-U・予測利用/正式採用/LIVEは未承認のまま。

root直下のsummary/manifest、school-selection-summary.csv、限定テスト終了集計、区分照合CSVとprivate台帳/選択表・ログ・差分を提出する。大きなSQLite/Rawの再コピー・提出用ZIPなし。レビュー側が実体確認済みとは記載しない。次は今回コード・文書・証跡レビューのみ。Git add/commit/push/PR/mergeなし。
