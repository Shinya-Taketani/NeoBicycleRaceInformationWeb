# STAT-35-C1-CONTEXT-01

## Scope / 2026-09-29

PR #75 merge `e61f7c19dfd7b32d9a0ff1218fc068b7756b1fd5`のclean main/originから
`feature/stat35-c1-context-01`を作成。固定C1対象の限定READ ONLY抽出1回とoffline候補生成/独立再現が今回の許可。
旧AgariC1Inputの全NULL診断/14ファイル一致/hash、旧C1/STAT35成果物を変更・再生成しない。
版: `STAT35-C1-CONTEXT-01-v1`。候補形式: `STAT35-C1-ENTRY-CONTEXT-v1`。
COMPLETEは生成/seal完了であり、人手レビュー・正式採用・予測利用許可ではない。
`REVIEW_PENDING`、`historical_as_of_available=false`、`observed_at=null`。
`AgariC1Input\Sources::$reviewedContextPins`へ自動登録しない。実mean6生成は行わない。

## Source / Read Only

固定元: `/home/shinya/neo-keirin-artifacts/tactical-history-01-review-fix-20260916-01/inputs-v2/`。
manifest 3,823 bytes / SHA-256 `d5158a7671f8c453afc77dccc1ab0b089c940198f58f388c038ad0d4fad684cf`。
inputs/history各2022-2025のsealを検査し、history.targetの識別情報だけを投影する。
内部player_idは補助照合、feature_input_hashは識別根拠として残す。aggregate/cacheは対応に使わない。
2022/2023同居labels/rank/statusは既存SourceProjectorで隔離。2024/2025評価labelsは開かない。
非結果値の投影SHA・対象/出走順は保存し、DB欠落行も全対象監査に残す。

専用接続の業務SELECT前にsession READ ONLY、endpointを照合。
REPEATABLE READ/READ ONLYを設定しsnapshot識別を取得、同一transactionで500出走ずつ取得する。
終了時rollback/disconnect。失敗時はpartial/FAILED/完了出走数を残し自動リトライしない。
plan/build/reproduceはDBに接続しない。DB書込み拒否テストは人工SQLiteだけで行う。

| Table | Allowed Columns |
|---|---|
| race_entries | id, race_id, player_id, external_player_id, bike_number, fetched_at |
| races | id, source, external_race_id, race_day_id, race_date, race_number, race_type |
| race_days | id, race_meeting_id, race_date |
| race_meetings | id, source, starts_on, ends_on |

SQL内で固定entry/race ID、2022-01-01〜2025-12-31、source=keirin_jpを制限。
対象CTEからLEFT JOINし、出走/レースが欠落または範囲外の場合をNULLとして監査する。原因は推測で細分化しない。
結果/払戻/import/players/Raw/2026は読まない。プロセス環境・認証設定を記録/変更しない。

## Mapping

- JSJ017のsenNo → RaceListEntryDto.externalPlayerId → RaceRepository::syncRaceDay → race_entries.external_player_id。
- race_typeはJSJ017 syumokuを保存し、PJ0315詳細syumokuから更新される経路もある。選手gradeを使わず既存Classification::raceClassで分類。
- 外部IDは保存された6桁文字列だけ。先頭ゼロ維持、数値化/ゼロ埋め修復なし。
- 固定race/entry/bike/date、day/meeting対応、meeting ID/開始/終了、source、既知内部player_idを照合。
- 内部ID欠損は固定側/保存側、不一致とは別理由。本人根拠不足は保留し、最新プロフィール等で補わない。
- 個別不備は当人を保留。正しく帰属・開催確認された証拠同士のclass/開催矛盾はレース全体を保留。
- 外部ID重複は本人照合済み行で検査。キー順ではなく固定fieldの組で比較する。
- fetched_atは保存出走表の取得時刻として別監査。全fieldの発走前観測を証明しない。races.last_fetched_atは読まない。
- source_record_idは実在table:PKの列挙。抽出SQL/bind・元レコード・snapshot・field対応・seals・依存コードを保存。
- summaryのcandidates/heldは排他的出走実数、reasonsは非排他的。全年度と全体を監査へ照合。

## Commands

```bash
php -d memory_limit=128M artisan keirin:stat35:c1-context plan
php -d memory_limit=128M artisan keirin:stat35:c1-context extract --authorize-read-only-extract --output-dir="$RUN/extraction"
php -d memory_limit=128M artisan keirin:stat35:c1-context build --extraction-dir="$RUN/extraction" --output-dir="$RUN/mapping"
php -d memory_limit=128M artisan keirin:stat35:c1-context reproduce --extraction-dir="$RUN/extraction" --original-dir="$RUN/mapping" --output-dir="$RUN/reproduction"
```

RUNは指定root下の新規ディレクトリ。既存出力は拒否。extract後は保存済み抽出だけを再利用する。
成果物: extraction/{targets,db-records,queries}.jsonl, connection.json, manifest/COMPLETE。
mapping/{candidate/entry-context.jsonl, mapping-audit.jsonl, samples.jsonl, summary.json, provenance.json, manifest/COMPLETE}。
samplesは固定C1順の各年度・各理由（正常候補含む）先頭1件。全データの本文貼付はしない。
ローカルSQLite indexはbounded-memory用、対象DBへの書込みではない。索引自体は非正本で、offline再現はsealed JSONLだけを読む。

## Verification / Execution

人工試験と通常全体テスト完了後、実抽出1回・生成1回・独立再現1回を実施し、すべてexit0。
実行証跡root: `/home/shinya/neo-keirin-artifacts/stat35-c1-context-01/run-20260928-215453-d152edab/`。
run名は作成プロセスのUTC時刻、各実行ログはJSTの開始/終了日時を保存する。
受入前候補を生成した事実と、過去公開時点の保証・予測利用可能性を混同しない。

### Tests

- 関連 `php artisan test --filter='AgariC1ContextTest|AgariC1InputTest|AgariPlayerHistory|AgariRaceRelative|ConnectionGuard'`: 247成功/3,075 assertions。
- 最終PHPの通常 `php artisan test`: 2,274件中2,265成功、既存PostgreSQL専用9skip、19,216親assertions、失敗0。163.683742秒、exit0。
- 新規人工回帰をAgariC1ContextTestへ追加し、高ピーク親プロセスからの分離検証にも登録。
- 6桁/先頭ゼロ、ID形式/欠損/重複、race/entry/bike/player/date/meeting不一致、JOIN欠落/重複、非単調順序、UNKNOWNと真の競合、個別不備、キー順、時点NULL、SQL許可列・READ ONLY設定順、書込み拒否、2026事前拒否、改変/未知版/上書き拒否、全件監査/排他的件数と非排他的理由、DBなし再現を確認。
- 19独立128Mケースと19高ピーク親ケースが成功（既存18を維持）。今回の8,000レース/40,000出走は抽出・生成・再現まで実行しpeak44,564,480 bytes、15子assertions。
- 限定 `pint --test` と変更PHP10本の `php -l`: 成功。既存PHPの意味変更はなく、MemoryLimitedTestProcessへの今回ケース追加のみ。
- 最初のfocused実行ではテスト側のArtisan呼出し文字列とReflection取得方法を修正。最終全体成功で未解決の失敗はない。

### Read-only Execution

接続: 127.0.0.1:5432 / neo_keirin_prediction_db / public。
session_read_only=on、transaction_read_only=on、snapshot_read_only=on、isolation=repeatable read。
snapshot=`338862:338862:`、抽出時刻2026-09-29T07:03:07+09:00。
許可20列だけを1,413チャンク（最大500出走）で取得。業務DML/DDL・batch_runs書込みなし。
抽出後rollback/disconnect。build/reproduce/独立照合はDB非接続。

| 処理 | 開始〜終了 JST | command秒 | peak bytes | exit |
|---|---|---:|---:|---:|
| extract | 07:02:41〜07:03:15 | 33.856763 | 35,651,584 | 0 |
| build | 07:03:37〜07:03:48 | 10.614323 | 31,457,280 | 0 |
| reproduce | 07:04:03〜07:04:15 | 11.328261 | 31,457,280 | 0 |

各`*-execution/`へ正確なargv/stdout/stderr/開始終了/所要時間/終了コードを保存。stdoutはpeakを含む。
`preflight.json`に固定seal・SQL範囲・認証を除いた設定・空き容量、`execution-code/`に20依存ファイルを保存。
固定sourceの8ファイル+manifestは抽出前後と独立照合終了時に不変を確認。評価labelsは開いていない。
2022/2023の結果列同居原本は物理的に読むが、既存Projectorで非利用にし、本人照合へ使わない。

### Counts

| 年 | 対象レース | 対象出走 | DB対応/有効外部ID/本人一致/開催一致（各） | class確定/候補（各） | 保留 |
|---|---:|---:|---:|---:|---:|
| 2022 | 24,394 | 170,835 | 170,835 | 170,739 | 96 |
| 2023 | 25,197 | 179,007 | 179,007 | 178,849 | 158 |
| 2024 | 25,212 | 179,089 | 179,089 | 178,992 | 97 |
| 2025 | 24,866 | 177,120 | 177,120 | 176,468 | 652 |
| 合計 | 99,669 | 706,051 | 706,051 | 705,048 | 1,003 |

保留はすべてUNKNOWN_RACE_CLASS。保存DB欠落・本人/開催不一致・外部ID重複・検証済み文脈競合は今回対象で0。
本人player_id_statusは全件MATCH。未取得を0としたのではなく、固定対象全行の実測照合結果。
保留を候補へ埋めず、mapping-audit.jsonlには706,051出走すべて保持。

### Deterministic Examples

各年・各理由の固定C1順先頭1件。samples.jsonlには元レコード/target/対応field/監査行番号を収録。

| 年/状態 | race / entry / day / meeting ID | 外部ID | 保存race_type |
|---|---|---|---|
| 2022 候補 | 89012 / 633053 / 9758 / 3183 | 012742 | Ａ級予選 |
| 2022 保留 | 98833 / 701914 / 10524 / 3430 | 015624 | Ａ級男予２ |
| 2023 候補 | 63439 / 451431 / 6943 / 2263 | 012097 | Ａ級チ予選 |
| 2023 保留 | 73801 / 524770 / 7841 / 2558 | 015704 | Ａ級男予１ |
| 2024 候補 | 37815 / 269427 / 4290 / 1399 | 013452 | Ａ級チ予選 |
| 2024 保留 | 47927 / 341110 / 5068 / 1656 | 015812 | Ａ級男予１ |
| 2025 候補 | 12542 / 89422 / 1414 / 461 | 014985 | Ａ級予選 |
| 2025 保留 | 20135 / 143414 / 2080 / 681 | 015005 | Ａ級ア予１ |

例えば2022候補はtargetのrace_date=2022-01-01、meeting=3183、期間2022-01-01〜03、player_id=1655が保存行と一致。
source_record_id=`race_entries:633053;races:89012;race_days:9758;race_meetings:3183`。
fetched_at=2026-08-03 04:38:46+09を監査に残すが、context.observed_at=null。
2026に取得された過去対象のメタデータを読んだのであり、2026開催レースの参照ではない。

### Artifacts / Reproduction

上記実在run配下のmanifest:

| パス | bytes | SHA-256 |
|---|---:|---|
| extraction/manifest.json | 6,331 | 1e1975b5d2c0b598870bb3b860a1cfdbe9bbfbcbaba035e3d878be5928f9a021 |
| mapping/manifest.json | 3,564 | 5facd83259a5b2b147115ff1632632a6f7a11f96e07f9f4d3078286743eb839b |
| reproduction/manifest.json | 3,564 | 5facd83259a5b2b147115ff1632632a6f7a11f96e07f9f4d3078286743eb839b |
| mapping/candidate/manifest.json | 248 | 7800bc94ed7a1d89e6bf1aee5a3bbd22d3d979dea01d511d4133214a08981268 |

候補JSONL・全件監査・標本・集計・provenance・候補manifest/COMPLETEの7ファイルと外側manifestが一致。
`verification.json`は別スクリプトで候補/全監査の順序・年別件数・理由・チャンク範囲・元C1/コード終了時sealを照合した記録。
検証7.373684秒、peak27,262,976 bytes、DB再接続なし。旧診断14ファイル一致とは別の今回の実績。

## Remaining Review

1. 現在保存値の再構成という制約付きで、この固定manifestの対応候補を入力準備の証拠として受け入れるか。
2. UNKNOWN_RACE_CLASSの1,003出走を保留維持するか。既存略記を追加解釈するなら別途根拠と許可が必要。今回推測分類しない。
3. 受入後の許可リスト登録・実mean6生成は次の明示指示が必要。本工程では実施しない。

過去の公開時点保証、予測利用、学習、性能評価、2026、LIVEは未承認のまま。DB再抽出を自動的に行わない。
