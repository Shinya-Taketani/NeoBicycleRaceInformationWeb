# STAT-2026-INITIAL-HISTORY-METADATA-SUPPLEMENT-01

2026-11-04 JST。状態: **INITIAL_HISTORY_METADATA_SUPPLEMENT_COMPLETE_AWAITING_REVIEW**。

## 1. 到達点

不足メタデータの限定抽出・全件照合・別PHPのオフラインverifyを完了した。次の1工程案は **STAT-2026-INITIAL-HISTORY-PROJECTION-01**（第8節）。今回、正式history_event/history_epoch/validation_run、全Raw解析、実2026取得・評価は実行していない。**O1=NOT_ESTABLISHED**、historical_as_of_available=false、prediction_use=NOT_AUTHORIZEDを維持する。

開始clean main/HEAD/ローカルorigin/mainは `62d3af9960ff57079cc3cfb5672a5e64aa6db285`。PR135 merge親 `ad869b915f0e1088ae20ee7c8ba2eb7eb441bc7a` / `5bae9b83f926b6fa4cf9184ecca079befe1cfeae`、tree `efe3a7eb86309d570ad50a7c03660b6ab20045f0` が一致した。fetch/外部照会なし。新branch `feature/stat-2026-initial-history-metadata-supplement-01` を作成した。最初のsandbox内branch作成は.git read-onlyで拒否され、許可された同じ操作を権限承認後に実施した。reset/clean/既存branch上書きなし。

PR135の[監査報告](stat-2026-initial-history-source-audit-01.md)は受入・マージ済み。そこでのDB未実行/別承認待ちは過去記録であり、今回は最新ユーザー指示による**固定2022〜2025年メタデータだけのREAD ONLY抽出**を行った。一般のDB操作や実2026実行の許可へ拡張しない。

## 2. 実装とCLI

| 実装 | 責務 |
| --- | --- |
| SupplementInitialHistoryMetadataCommand | 排他的mode/必要・禁止引数の検査と呼出し。エラーに接続情報/SQL bindingsを出さない |
| InitialHistoryMetadata/Planner | 受入済みRの最終sealと必要B/Cを確認、固定scopeを作成。業務DB/HTTPなし |
| Scope / Queries | 新規専用ローカル索引で元キー/重複/期間/本人/import-log-Raw参照を検証し、200件単位のSQLを構成 |
| MetadataSource / PostgresSource | 専用READ ONLY/REPEATABLE READ接続、接続・snapshot・timeout検査、固定8表の指定列、finallyでROLLBACK/disconnect |
| Parameters / Reconciliation / Inspection | 要求値の型・欠損保持hash、旧値/現在値/新列の区別、同一瞬間比較、版別時刻根拠と内訳の集計 |
| Evidence / Writer | 排他的生成・stream・seal・COMPLETE/FAILED。部分出力を保持、削除/再試行なし |
| Verifier | 保存bundleだけを別ローカル索引へ読込み、キー/型別digest/結合/比較/日時/理由/追加/summaryを再検算 |

新しいScopeはこの専用namespaceのローカル索引である。**既存ChronologicalValidationのScope/HistoryReader/容量/欠損経路、STAT式、旧Json契約、C1モデルは変更していない。** 既存Jsonの純粋な正規化/書込みとConnectionGuardを再利用した。

```text
keirin:backtest:initial-history-metadata
  --plan   --audit-root=R          --output-root=NEW_SCOPE
  --export --scope-manifest=FILE   --output-root=NEW_EXPORT
  --verify --bundle=EXPORT        --output-root=NEW_VERIFICATION
```

modeは必ず1つ。output-rootと当該modeの入力だけが必須、他mode用入力は拒否する。plan/verifyは業務DB・HTTP接続0。exportもscopeの全件seal/期間/結合検査を終えるまで接続しない。成功/失敗とも既存出力rootを再使用せず、別attemptでのみ再実行できる。

## 3. 入力と固定scope

前工程R:
`/home/shinya/neo-keirin-artifacts/stat-2026-initial-history-source-audit-01/run-20261103-9WpE3ttB`

R manifestは24,093bytes / `d26b87f00ac29bf7ce4de1fd0bb2b5b52ce77cf50b69a6e099d8f8611f865b93`。COMPLETE、最終field map v3・specification v2、read-plan/source-inventory/metadata-seals/summary、SQL案、最終review/構文/文書検証とexitを確認した。旧草稿は使っていない。Rの監査用SQLiteをmode=ro/query_onlyで使い、既に確定した開催終了日とDの旧結果状態を再利用した。5.7GBの旧history-cacheは開かず、全18 JSONL再監査もしていない。

B/Cの実パスはRの明示参照に限定。manifest/完了markerとR記録が一致した。B manifestは8,875bytes / `bd7ea209724bb1848ad4a44b1c9930bb0a5bb9c23806b0183eb0c6c07f9710a1`、Cは5,405bytes / `691717e4a95c7912c5fcfff23c3da8a1e1df13194ccd87bd5cc4dde1d3f41e7a`。B database-inventory、C targets/historical-requests/sourcesだけをstreamして固定scopeを作成した。A/Dの生成入口・業務DB exportをplanで起動していない。

scopeはraces/entries/fetches/imports/resultsの5 JSONL＋専用SQLite、scope-manifest/COMPLETE。元ID、文字列登録番号の先頭0、NULL本人参照を保持する。結果台帳の元キーは **race_id+bike_number** であり、Bにない結果IDを生成していない。scopeとexportのcode sealはそれぞれ保存し、plan後の時刻集計・追加キー用SQLの限定修正をplan時コードへ遡及しない。

## 4. 接続・実行境界

実測接続先: `127.0.0.1:5432 / neo_keirin_prediction_db / public`。専用connectionでquery log無効、reconnectは例外。session default_transaction_read_only=on / transaction_read_only=on、transactionはrepeatable read / read_only=on。lock_timeout=5s、statement_timeout=15minを照合した。snapshotは **552827:552827:**。正常実抽出は**1接続・1snapshot・1回**。

使用表はraces/race_days/race_meetings/racetracks/race_entries/scraping_fetch_logs/race_result_imports/race_resultsの8表だけ。業務SQLは固定ID起点のLEFT JOIN、レース期間はON側で `>= DATE '2022-01-01' AND < DATE '2026-01-01'` とsource条件を課した。関連行不在をWHEREで消さない。期間外化/別source/消失を識別できない場合の状態名はNOT_FOUND_IN_FIXED_PERIOD_SOURCEとし、2026側を調べない。

1 SQLのscopeは最大200キー。業務SELECTは10,603 chunk（CONTEXT507/ENTRY3589/FETCH1272/IMPORT636/RESULT3585/追加entry507/追加result507）。追加検出は固定レース内のキー・参照IDだけで、新しいimport/logの内容へ展開しない。元結果のrank等は固定716,837キーだけから抽出した。importを結果人数分に増幅せず、未参照版をINNER JOINで消さない。

実取得日時は2026年であっても、対象開催日が2022〜2025年ならそのまま保存した。2025年レース98件はmeeting_endが2026年に及ぶが、対象外へ落としていない。2026年開催レースへの参照ではない。

実行launcherは原本をbwrap read-only、人工.env/bootstrap/storage・専用tmpを使用した。plan/verify/testsはネットワークnamespaceを遮断。exportだけは許可済みlocalhost PostgreSQLへの接続を行い、実HTTP処理を呼ばない。既存DB認証はprocess内メモリで使用し、実.env/設定/cacheを書き換えず、秘密値/生encp/完全URLをログへ出していない。業務DML/DDL・batch_runs書込み0。ローカル索引用SQLiteの生成は業務DB書込みと区別する。

## 5. 固定対象の実測結果

MATCHは「旧資料に存在する比較可能な値と一致」の意味で、新しい列を過去にも確認済みという意味ではない。各行にscope.expected/current/new_columns/differences/normalized_times/reasonsを別々に保存した。

| 型 | 期待キー | MATCH | CHANGED | 対象期間/source内で不在 | 追加キー |
| --- | ---: | ---: | ---: | ---: | ---: |
| CONTEXT | 101,326 | 101,326 | 0 | 0 | 対象外探索なし |
| ENTRY | 717,709 | 717,709 | 0 | 0 | 0 |
| FETCH | 254,281 | 254,281 | 0 | 0 | 固定外探索なし |
| IMPORT | 127,121 | 127,121 | 0 | 0 | 固定外探索なし |
| RESULT | 716,837 | 716,837 | 0 | 0 | 0 |

| 年 | race | entry | fetch | import | result |
| --- | ---: | ---: | ---: | ---: | ---: |
| 2022 | 24,868 | 174,152 | 49,733 | 24,865 | 173,847 |
| 2023 | 25,561 | 181,548 | 102,232 | 51,108 | 181,392 |
| 2024 | 25,624 | 182,004 | 51,239 | 25,615 | 181,847 |
| 2025 | 25,273 | 180,005 | 51,077 | 25,533 | 179,751 |

| 年 | CONFIRMED race | CANCELLED race | UNAVAILABLE race | CANCELLED import |
| --- | ---: | ---: | ---: | ---: |
| 2022 | 24,824 | 41 | 3 | 41 |
| 2023 | 25,538 | 16 | 7 | 32 |
| 2024 | 25,601 | 14 | 9 | 14 |
| 2025 | 25,237 | 26 | 10 | 26 |
| 計 | 101,200 | 97 | 29 | 113 |

各値は今回の実測であり、旧PR135の値もscope/summary.expectedとして別保持した。旧値との差はいずれも0。結果状態はFINISHED704,330/TIED2,513/CRASHED5,282/DID_NOT_FINISH258/DID_NOT_START1,085/DISQUALIFIED3,369。年別・状態別の詳細はreal-data-acceptance.jsonに保存した。

- 中止97レースの663出走は結果0行として保持。中止import113版の正常SUCCEEDED記録を残し、結果行の存在を要求していない。
- 取得証拠なし29レース・209出走は固定race/本人参照を保持。日時を創作せず、C1不足判定への引継ぎ対象とした。
- 現行結果未参照25,921 import＝CANCELLED113＋RESULTS_AVAILABLE25,808。全版保存し、「不要な旧版」としない。
- PJ0315は成功127,159＋DNS_FAILURE1、PJ0326は127,121。失敗版にRaw path/hashがないことを正常な既知不足として記録した。
- 旧/現在のrace_results.race_entry_id/player_idは各716,837行すべてNULL。補正・backfillなし。今回保存した元entriesへの参照と将来のRaw本人照合は、DBのNULL修復と区別する。
- unresolved.jsonlは26,172行。理由件数は重複可能で、MATCH/CHANGED/不在の排他的内訳やレース数へ足し合わせない。

## 6. 補完できた列と時刻の意味

racetrack_id/race_day_id/day_number/meeting_end/meeting_day_kindは全101,326レースで非NULL、race_days.race_dateとraceの日付矛盾0。meeting_gradeのNULL124は未確認値として残した。day_kindは元文字列「1」が101,326件であり、通常/ナイター等へ意味変換していない。現在の選手プロフィール/得点/級班/枠は読んでいない。

| 日時証拠 | 母数と今回件数 | 意味・残る制約 |
| --- | --- | --- |
| RAW_STORED_BEFORE_LOG_RECORD | FETCH254,281中254,280 | 実取得後のlog.created_at記録。Raw保存後にlogを作るコード契約に基づく記録上限候補。現在のRaw実在/hash一致や厳密な保存完了/commitは未検証 |
| IMPORT_STATUS_RECORD_TIME | IMPORT127,121中127,121 | SUCCEEDED importのupdated_at記録。汎用更新かつtransaction内部であり、結果保存完了やcommitに転用しない |
| RESULT_VERSION_PROCESSING_TIME | RESULT716,837中716,837 | 同じ固定import/logに対応するrace_results.fetched_atのアプリ保存処理時刻。HTTP取得時刻とは異なる |
| APPLICATION_PROCESSING_TIME_ALL_FIXED_RESULT_ROWS | import127,121中101,200版 | 当該版を参照する固定結果全行の処理時刻が確認できた版数 |
| UNESTABLISHED_NO_FIXED_RESULT_ROWS | import127,121中25,921版 | 現行結果に参照なし。現在版の処理時刻を古い版へコピーしていない。中止113版を含む |
| NO_RESULT_FETCH_EVIDENCE | race101,326中29 | 結果取得/importがない。業務行209出走の不足参照は保持 |

FETCH/IMPORT/RESULTのcreated_at・updated_atは全件offset付き日時として読めた。**183,159結果行ではcreated_atが現行版のfetch-log取得日時より古い**。updateOrCreateによる既存race/bike行の初回作成時刻なので、異常扱いしない。取得<=保存処理時刻やRaw保存後logという、その列の意味に合う順序だけを検証した。+09とUTCの同一瞬間はdriftにしない。

公式公開/確定時刻はUNKNOWN/NULL、history_event.saved_atの正式割当はNOT_RUN。厳密commit時刻を復元することを抽出完了の新条件にはしていない。今回のexport時刻を過去の取得/保存時刻に使わない。

## 7. 成果物・実行・検証

新規run（以下W）:
`/home/shinya/neo-keirin-artifacts/stat-2026-initial-history-metadata-supplement-01/run-20261104-WfXXHxz8`

| 成果物 | manifest SHA-256 |
| --- | --- |
| W/plan-01/scope | `996a44c086af517cb16ccc0cdb501e4af118ee79dcc4751a0c39dd04f7add487` |
| W/export-01/bundle | `c58e0ea87fbb3965289011114fe330a34cdb0c2188f31db4b3523de8dea95862` |
| W/verify-01/verification | `cbae2db73e1d254550c0385dc1ad932450d0809f816406821c945acfc5aedd46` |

exportにはcontext/entry-identities/fetch-persistence/result-persistence/unresolved、summary、日時契約、SQL、接続/snapshot、scope証明、code/source/output seal、実行記録、COMPLETEを保存。result-persistenceはIMPORT127,121とRESULT716,837の計843,958行で、型別のscope digest/件数を独立確認した。新規ローカル索引も保持し、大容量データをGitやreview packetへコピーしない。

実行は以下。run.phpが128M・read-only原本・人工環境を作り、child.phpから同じArtisan commandを起動する。各stage/command.jsonに正確なbwrap/PHP/Artisan引数、stdout/stderr、execution.jsonに実終了値・時刻・wall time、resources.jsonにPHP peakを保存した。

```bash
php W/run.php test tests-final \
  tests/Unit/Domain/Keirin/Backtest/InitialHistoryMetadataTest.php \
  tests/Feature/InitialHistoryMetadataCommandTest.php \
  tests/Unit/Domain/Keirin/Backtest/TacticalHistoryAggregatorTest.php \
  tests/Unit/Domain/Keirin/Backtest/Bt03e03ProbabilityTest.php
php W/run.php plan plan-01
php W/run.php export export-01 W/plan-01/scope/scope-manifest.json
php W/run.php verify verify-01 W/export-01/bundle
```

Wは上記実パスの略記。child側の実PHPは/usr/bin/php8.5 /8.5.4、memory_limit=128M。dotenv/既定環境は原本を保護する人工環境なので通常実行環境の完全再現とはしない。DB認証・非秘密の実接続先とSQL条件は既存設定から確認し、今回のreadonly条件を外していない。

| 段階 | 実終了コード | wall秒 / 内部秒 | PHP peak bytes |
| --- | ---: | --- | ---: |
| plan-01 | 0 | 24.866 /24.758 | 35,651,584 |
| export-01 | 0 | 99.853 /98.595 | 33,554,432 |
| verify-01 | 0 | 99.705 /99.585 | 33,554,432 |
| 保存物postcheck | 0 | 内部7.390 | 10,485,760 |
| tests-final | 0 | 2.250 /PHPUnit2.169 | 55,050,240 |

実抽出07:45:59〜07:47:39 JST、独立verify07:48:13〜07:49:53 JST。抽出接続を閉じた後に別PHPでverifyした。再抽出なし。verifyは正確なファイル集合・marker/seal、全固定キー/順序/型別digest、重複、旧値/current比較、版時刻、unresolved行とsummaryを検算し、業務DB/HTTP接続0。保存済み索引のread-only追加照合はpostcheck-queries.json/real-data-acceptance.jsonに記録した。

最終限定55 tests/349 assertions（新規Unit29/123＋Feature7/34＝36/157、既存19/192）、failure/error/warning/notice/skip0。scope改変・2026人工日付・binding/重複、接続/snapshot不適合時の業務SELECT0、追加/消失/版差替え、NULL/中止/未取得/DNS、再import/offset/時刻逆転、200件跨ぎ、SQL失敗のFAILED/切断、出力衝突、保存物改変・不足・余剰を検証した。人工正常系と実抽出結果を混同しない。

最初のtests-01は33/143・失敗0だがPDOStatement mockのnotice1。expectation/stubを修正して解消した。tests-02/03/04の55成功と当時のassertionsは各ログに保持。実plan/export/verifyの失敗attemptはない。新規PHP16ファイルの構文と限定Pint、文書/diff確認を実施。通常全体は旧StartCountSnapshotTest::tearDown等の永久削除経路を起動せずNOT_RUN。全体回帰・過去警告の全原因/解消は未確認であり、この55件から全体成功を推定しない。

全人工root・失敗資料・中間索引は保持。後片付けで永久削除・実Desktop移動はしていない。人工領域作成は既存CompositionResultTemporaryDirectory helperを利用し、旧helper/tearDownを一括変更していない。

最終静的検証の初回wrapperは、隔離済みbwrap内での二重namespace生成が許可されず、php-l起動前にexit255で停止した。final-checksのstderrを保持し、外側のread-only/通信遮断を維持した一重起動のfinal-checks-02へ分離した。業務コードや実抽出bundleの変更・再抽出はしていない。小型提出物はW直下のreview-packet.txt/review.diff/review-manifest.json/FINAL.jsonであり、大容量索引のコピーは含めない。

## 8. 次の1工程案と未解決

**STAT-2026-INITIAL-HISTORY-PROJECTION-01** を次のレビュー対象案とする。今回の実装/全Raw処理へ自動進行しない。

入力は上記export manifest/COMPLETE、固定scope、Rのfield map v3、B/Cの明示Raw参照とsource seal。現在DBへの再照会・再棚卸しを前提にしない。処理案/受入条件は次のとおり。

1. 固定レースごとに封印済みPJ0315入力版と結果import版を選択し、選択規則/版ID/親Refを固定。現行参照101,200版、中止97レース/113版、他の旧版を混同しない。同時刻の内容競合をID順だけで解決しない。
2. その選択Rawだけをstreamで存在/bytes/SHA、日付/場/番号、全名簿/車番/登録番号、全員得点/級班/枠、公式結果状態/rank/決まり手と照合する。今回のmetadata MATCHをRaw VERIFIEDと読み替えない。旧原本/モデル/予測を変更しない。
3. 元ID/NULL/取得日時/保存処理時刻/状態記録時刻を分離した投影へ接続。現在版の716,837処理時刻と25,921未参照版を分け、中止の状態記録時刻候補の扱いを明示する。saved_at割当は列の意味を根拠に別途レビューし、commit時刻の厳密復元を新条件にしない。
4. 29未取得・209出走等は時刻を創作したhistory_eventにせず、固定本人/開催/日付・不足Refを持つ経路でC1のPARTIAL_HISTORY等へ接続する。通常STAT採用外でも不足証拠を捨てない。現行Readerの必須timestampと両立しない行を黙って除外しない。
5. 全epochのused/excluded8192上限に対し、不変監査chunkと件数/hash参照によるstream化を限定製造・回帰する。単純上限増加、期間短縮、間引きは禁止。4096本人/1MiB行/96MiB追加予算/128Mと元算術・39母集団/42対戦を維持する。
6. 2022開始固定範囲と1825日窓の左切断、未知公式公開時刻、枠/得点等の欠損を保持し、別PHPの再検算と原本不変を確認してから投影を受入れる。2021新規取得・実2026参照・モデル再学習を追加条件にしない。

今回解消したのは指定内部文脈・版別の取得/記録列の全件抽出と対応確認である。全Raw本人/得点文脈の受入、当時の公開/発走前利用可能性、初期epochとReader全量の容量、実2026の予測/性能は未確立・未実行。これらは抽出工程の完了と区別する。歴史as-of=false/予測利用・正式採用禁止、B1未承認/方法・lambda未選択/H1-U保留を維持し、未コミットでレビュー待ちにする。
