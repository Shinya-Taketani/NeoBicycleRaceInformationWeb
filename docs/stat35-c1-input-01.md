# STAT-35-C1-INPUT-01

開始main: `029309f2cb0434348a53cd30b74d0419d2f8b6d2`。PR #74マージ後、今回のユーザー指示で入力準備だけを許可。

## 実装開始時の契約

`STAT35-C1-INPUT-v1`: 年別のoutcome-free C1本体と `stat35_mean6` 一列のsidecarを別保存する。
本体のrace/entry/bike、行順、配列順、非結果値・型を保持し、旧history4値へ追加しない。旧Loaderへ渡さない。
2022/2023の既知training schemaからlabels/rank/statusだけを無条件に取り除くSource Projectorと、
結果列を拒否するValidatorを分離する。2024/2025評価labelsを開かない。未知schemaは拒否する。

保存された本人・開催・classの証拠を要求し、内部ID一致だけで本人を補完しない。
6観測開催を先に選びNULL開催も枠を消費、有効値1件以上で部分窓平均、有効0件はNULL。
既存Historyの最大13候補・窓外重複検査とExactを再利用する。分数→12桁HalfEven→有限float、値域[0,1]。
`historical_as_of_available=false`。この入力生成許可は学習・比較Gate・予測利用・2026・LIVEの許可ではない。

## 実行経路と保存仕様

`keirin:stat35:c1-input plan|build|reproduce`。buildは`--c1-dir`、`--history-dir`、`--output-dir`を使用し、
reproduceは更に`--original-dir`を要求する。新しい出力親は事前に存在し、出力自身は未存在でなければならない。
source配下への生成・既存出力の上書きを拒否する。出力に新規workspace.sqliteを作り、旧workspaceは開かない。

- 年別`c1-YYYY.jsonl`: 既知結果3列だけを除いたC1。順序・型を含む投影のSHAを保存ファイルと照合。
- 年別`stat35-YYYY.jsonl`: 同じrace/entry/bike順のmean6だけ。監査状態や件数を数値入力に混ぜない。
- 年別`audit-YYYY.jsonl`: 本人/開催/class接続、原本field・record・観測時点、source seal、NULL理由、6開催参照、分数/decimal/float。
- `summary.json`と`invariance.json`: 年別/全体件数、非排他的理由、順序付き集合と非結果値と追加値のsemantic hash。
- `manifest.json`: 契約、必要なsourceのみのseal、直接依存codeとBrick Math/composer.lock、生成時ファイルseal。

有効値がある入力はINPUTS_PREPARED、空集合はEMPTY_INPUT、全追加値NULLはDIAGNOSTIC_ALL_NULL。
後2者はDIAGNOSTIC.jsonで封印しCOMPLETE.jsonを作らない。途中失敗はFAILED.jsonとpartialを残す。
入力準備の完了はモデルへの接続・性能評価の完了を意味しない。一部接続不可の場合も元集合から削除しない。
再現は同じ固定資料を別ディレクトリへ再処理し、全14データ/監査ファイル・manifestの一致と原本の前後sealを検査する。
runtimeとworkspaceは意味上の比較外。sourceの全体hashと、将来行に依存しないsemantic input hashは分離する。

## 保存証拠と不足の扱い

固定C1は`/home/shinya/neo-keirin-artifacts/tactical-history-01-review-fix-20260916-01/inputs-v2/`。
v2 manifestは3,823 bytes、SHA-256 `d5158a7671f8c453afc77dccc1ab0b089c940198f58f388c038ad0d4fad684cf`。
2022/2023のtraining原本にはlabels/rank/statusがあり、今回物理的に読むが値を利用・ログ出力しない。
2024/2025の評価labels・結果照合資料は読まない。C1と年別historyの先頭行schema、InputBuilderの投影定義を確認した。
history.targetにはrace_id/race_date/meeting_id/meeting_start/meeting_end/entry_id/player_id/bike/input_as_of/feature_input_hashがあるが、
観測external_player_idとrace classはない。内部player_idだけの接続は禁止。history cacheの結果混在行から本人を補わない。

履歴はPR73修正版の`stat35-player-history-01/pr73-review-fix-20260927-210230-b2d48a1f/result/`。
manifestの固定SHA `844440cb08b06b0e69195a6384689bc545e63806ff85237ca36a81f5dd08507e`とCOMPLETEを照合し、
必要なplayer-meetings.jsonl（371,640,449 bytes、`521b9218e4ddcedd10cf7c8b34e212ccb5f9ddfcf88dbc5af17e1d7fc4aea7e7`）だけを検証・索引化する。
entry-history.jsonl、race-relativeの対象結果ファイルを接続fallbackとして開かない。

新規の任意`--context-dir`は`STAT35-C1-ENTRY-CONTEXT-v1`という**今回定義した証拠交換形式**であり、
既存実資料にこの形式が存在すると主張しない。現存する結果非依存のentry/開催snapshotを根拠に用意する必要がある。
manifestはversion/origin=`SAVED_ENTRY_AND_MEETING_METADATA`/historical_as_of_available=false/filesを持ち、
COMPLETEとentry-context.jsonlのsealを検査する。各行の許可fieldは次だけ:

```text
source, external_player_id, race_id, entry_id, bike, race_date,
meeting {meeting_id, starts_on, ends_on}, race_type, observed_at, source_record_id
```

sourceはkeirin_jp、外部IDは文字列の6桁。観測時刻不明はNULLのまま、発走前公開保証へ変換しない。
元の保存recordを識別し、auditに証拠ファイルの絶対パス・全体seal・採用fieldとrecordを保存する。
重複証拠、異なる本人/車番、開催文脈矛盾、UNKNOWN classを別記してNULLとする。
証拠ファイルがない場合はMISSING_IDENTITY_CONTEXT_EVIDENCE。未提供資料の重複/矛盾が存在しないと断定しない。
この交換形式の人工試験成功は、実在しない本人台帳の存在や接続成功を証明しない。
新DB取得や新snapshot作成の権限は今回追加しない。

## 安全境界

固定入力pin・版・サイズ・SHAを開始/終了時に確認し、codeも前後一致、公開直前に生成時sealを再検査する。
値は既存Historyの6窓だけを採用し、3/12・median/variance/trendを出力特徴量にしない。
順序不明はblock、本人確認済みNULL開催は一枠、有効な0は0.0であり欠損ではない。
本番DB設定は書き換えず、実行processはtesting/SQLiteと空認証、存在しないconfig cacheを指定。
更に今回の外部ラッパーはアプリDB呼出しとHTTPを例外で拒否する。ローカル索引PDOだけを使用する。
旧C1/STAT35コード、正式成果物、2026 holdout、モデル、bin、lambda、Gateに変更なし。

## 試験結果

新規35ケース: 年別schema/既知結果の非利用、strict outcome-free allowlist、leading zero、別本人/重複/矛盾、
逆順ID・順序保持、NULL/0/部分窓、同日/同開催/未来/class分離、窓外重複、分母/値域/HalfEven、
UNKNOWN/未対応、空集合/全NULL、source/出力改変、上書き拒否、plan/build/reproduce、DB/HTTP拒否を検証。
初回30ケース105 assertions、4ケース追加後34ケース121 assertionsが成功。独立メモリ1ケースも成功。
既存履歴/helperを含む最終関連試験119成功/1,136親assertions、exit0、121.139433秒。

最終PHPで通常全体を1回実行: **2,197件、2,188成功、既存PostgreSQL専用9skip、失敗/エラー0、18,279親assertions**。
`php artisan test`、exit0、158.510479秒（Artisan158.36秒）。9skipは今回成功と数えない。新規skip/閾値緩和なし。
限定Pint成功、変更PHP11本の構文成功、git diff --check成功。
独立メモリ登録18件（旧17維持）、全高ピーク親の回帰も成功。
新規は100MiB超の人工JSONL・11,000レース/55,000出走を実builderで処理し、実効128M・peak44,564,480 bytes。
full中の子PID192172、親190690、子16 assertions/exit0。子assertionsは全体親集計に足さない。

## 固定入力の実測

状態: **CODE_VERIFIED_MAPPING_BLOCKED_DIAGNOSTIC_REPRODUCED_AWAITING_REVIEW**。
branch: `feature/stat35-c1-input-01`。開始/終了HEAD: `029309f2cb0434348a53cd30b74d0419d2f8b6d2`。
実在する永続証跡root:

```text
/home/shinya/neo-keirin-artifacts/stat35-c1-input-01/run-20260929-IDHDYP/
```

実行前空き容量246,436,372,480 bytes、wrapperは2出力/索引用に最低16GiBを要求。
source必要ファイルをbuild内で開始/終了検査し、別の全量監査は実行しない。
`runner.php`が正確なコマンド・process-local環境・stdout/stderr・終了コード・時刻を各executionへ保存する。
`offline.php`はLaravelをtestingで起動後、アプリDB/HTTPを明示的に拒否する。

| 処理（JST） | 開始 | 終了 | 秒 | exit | peak bytes | stderr bytes |
|---|---|---|---:|---:|---:|---:|
| build（診断） | 2026-09-29 05:27:10 | 05:27:28 | 18.687463 | 0 | 31,457,280 | 0 |
| reproduce（独立診断再現） | 2026-09-29 05:27:56 | 05:28:16 | 19.187037 | 0 | 31,457,280 | 0 |

各1回、再試行なし。ログ日時はUTC。結果非依存の対象本人証拠が未確認のためcontextを捏造せず未指定で実行。

| 年 | 対象race | 対象entry | 接続成功 | 有効追加値 | NULL: MISSING_IDENTITY_CONTEXT_EVIDENCE |
|---|---:|---:|---:|---:|---:|
| 2022 | 24,394 | 170,835 | 0 | 0 | 170,835 |
| 2023 | 25,197 | 179,007 | 0 | 0 | 179,007 |
| 2024 | 25,212 | 179,089 | 0 | 0 | 179,089 |
| 2025 | 24,866 | 177,120 | 0 | 0 | 177,120 |
| 合計 | 99,669 | 706,051 | 0 | 0 | 706,051 |

件数は読み取った固定C1から実測し、過去文書から補完しない。C1重複race/entryは0（重複なら生成拒否）。
context_evidence_available=falseであり、未提供の本人証拠中の重複/矛盾件数は**未計測**。
summaryのduplicate_context/conflicting_context=0は読めた証拠内の検出0を意味し、未知資料の整合性確認ではない。
blocked window内のflagsは既存Historyが空選択へ返す機械的なフラグであり、本人不明者の実履歴不足/左端切れを確認したとは読まない。
履歴品質・件数は学習入力へ混ぜない。今回NO_HISTORYと断定せず、接続証拠不足を主理由として保持する。

## 不変性・再現・hash

全entry/bikeと行/出走者/配列順を維持。2024/2025のoutcome-free C1は原本とbytes/SHAも同一。
2022/2023は既知結果3列の除去以外の値と型が同一で、その投影hashを出力と照合。
ordered cohort hash/非結果hash/sidecarと合わせたsemantic hashは`invariance.json`に年別保存。
原本training結果値だけを変える人工ケースでも全投影/sidecar/semantic hash一致、原本hashだけは変化することを検証済み。

`result/`と`reproduced/`の14データ/監査ファイルとmanifestはbytes/SHA一致。
各DIAGNOSTIC.jsonも一致。`reproduced/reproduction.json`に独立照合を保存、COMPLETE.jsonは両方とも存在しない。

| ファイル（result/、reproduced/共通） | SHA-256 |
|---|---|
| manifest.json | `0e68d6adbcddc03fa8ab7b10904f2e0388cec32cc88a0ebe89a20db274f4dd71` |
| DIAGNOSTIC.json | `a1b8cb158c07cb439096221b62ae0bef53949fbd3bdc4106541559b5a3ec7450` |
| summary.json | `f8d6ca2116fb6127acaffd5f04dcc33df85d527bd48b137023d6fa1d3fe29617` |
| invariance.json | `44ec08f7eaa1db26fbf7afcb6827b6fad240242b3bd8ef2e010370a0986c9e37` |

実行例（rootのrunnerが固定pathと隔離環境を定義済み、既に実行済みなので再実行しない）:

```bash
php /home/shinya/neo-keirin-artifacts/stat35-c1-input-01/run-20260929-IDHDYP/runner.php plan
php /home/shinya/neo-keirin-artifacts/stat35-c1-input-01/run-20260929-IDHDYP/runner.php build
php /home/shinya/neo-keirin-artifacts/stat35-c1-input-01/run-20260929-IDHDYP/runner.php reproduce
```

## 結論と残件

コード/人工検証完成、実データ接続未成立、全対象を保持した診断生成/再現完了、比較用入力未完成、性能未評価。
不足は固定C1 entryを結果非依存で観測6桁external_player_idへ対応し、同一開催期間とrace_type/classを裏付ける保存記録。
保存元・観測時点・record/field・sealを確認できる資料を指定するか、その最小取得範囲を別途判断する必要がある。
内部ID/result_id/対象結果/最新プロフィールによる補完をしない。全NULLを追加効果0や性能FAILと報告しない。
本番DB接続/書込み、Raw取得、Migration、旧生成、実学習/予測/評価、2026実レース参照は0。
未コミットでコード/診断のレビュー待ち。commit/push/PR操作・自動次工程なし。

## PR #75 接続・競合判定のレビュー修正

開始HEAD `05fd969d850ddde2e80f3016647d3bccdcbb94e9`、同じPR branchで開始時clean。
上記v1の実生成・14ファイル一致・hash・件数は当時の記録として保持し、修正後の実行結果へ読み替えない。
今回の版は `STAT35-C1-INPUT-v2-PR75-CONTEXT-VERIFICATION`。旧C1/履歴計算・共通canonical/hash仕様は不変。

- 固定C1 manifestのsealで年別`history-YYYY.jsonl`を開始/終了照合し、targetのrace_id/entry_id/bike/race_date/meeting_id/meeting_start/meeting_endだけをSQLite索引へ投影する。targetの集合・件数とC1入力を照合し、contextとの日付・開催期間不一致は値を公開しない。aggregate/cache/内部player_id/結果値を接続に使用しない。
- contextはoriginやsource_record_idの自己申告・自己sealだけでは受理しない。保存元とfield対応を確認済みのbundleに限定するため、manifestのbytes/SHAを呼出側の固定trust anchorと照合する。既定の確認済みリストは空、CLIから追加不可。人工Fixtureだけに独立したテスト用pinを渡す。実資料の本人/class根拠未確認は未解決のまま。
- 本人・開催・classの有効性を分離。帰属不一致・重複証拠を他出走者の本人競合へ伝播させず、不明class・不正開催を無条件にレース矛盾へ数えない。meetingは固定順scalarで比較し、objectのキー順だけを矛盾としない。本人未解決でも独立に確認できる開催/classの真の矛盾は全体ブロックする。
- `conflicting_context`はCONTEXT_IDENTITY_CONFLICTとCONFLICTING_RACE_CONTEXTの和集合に属する出走数。両方ある出走も1件だけ。理由別null_reasonsは非排他的なまま、年別/全体/監査行を照合する。

修正前の回帰19ケースは8成功/11失敗（145 assertions）。同年内の日付・開催の誤受理、自己申告証拠、
キー順、正常行の巻き添え、競合件数漏れを検出した。修正後の同ケースを含めて検証し、旧テストの削除・緩和・skip追加はしない。
本番DB/HTTP/Raw/Migration/学習/性能評価/2026実データ参照はなし。旧実入力・成果物は上書きせず、全NULL診断の全量再生成もしない。

検証: 最初の修正後は対象72件/570 assertions、履歴・独立メモリhelperを含む関連156件/1,583親assertions、
通常全体2,225成功/既存9skip/18,726親assertionsが成功。その後、配列型の不正開始日によるTypeErrorを
追加回帰で検出（7件中1 error）。履歴cutoffと窓計算の境界を固定target側へ統一し、正常行の維持を確認した。
最終PHPで対象**73件/582 assertions成功**（既存35から38ケース追加、既存の正常行assertionも強化）。
通常`php artisan test`を最終コードで実行し、**全2,235件中2,226成功・既存PostgreSQL専用9skip・失敗/エラー0、18,738親assertions、exit0、159.637秒**。
全体のテスト選択・順序・設定ファイルは変更なし。process限定でtesting/SQLite `:memory:`、空DB認証、存在しないconfig cacheを指定。
既存18件の独立128M経路と高ピーク親下回帰を維持し、C1ケースは100MiB超入力/11,000レース/55,000出走に固定target索引を追加して成功。
変更PHP6本の`php -l`・当該6本だけの`./vendor/bin/pint --test`・`git diff --check`成功。新規skip、Fixture縮小、閾値緩和なし。
