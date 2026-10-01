# STAT-36-START-COUNT-01

## Scope and Status

- Started: 2026-10-01, main/origin `212d0196663f3720b4d2a1865f8918a26c9a8e47`, PR80 merged.
- Branch: `feature/stat36-start-count-01`; implementation, generation and independent reproduction completed; awaiting review.
- Saved PJ0315 aggregate S counts only. Not a per-race start event, initial position or prediction feature.
- Preserve C1, C2 incremental Gate NOT_PASSED, StartObservation v3 / DisplaySignature v2, and all prior artifacts. Old observation v1 execution facts are not remeasured here.

## Input Contract

`STAT36-START-COUNT-v1` uses the accepted PR64 ledger (manifest bytes 8875, SHA-256 `bd7ea209724bb1848ad4a44b1c9930bb0a5bb9c23806b0183eb0c6c07f9710a1`). Only race/date/track/race-number, entry/bike/external ID and referenced fetch IDs are projected. No result-body re-analysis.

The ledger lacks disp/encp. The user explicitly authorized one dedicated READ ONLY / REPEATABLE READ export of the listed `races` and `scraping_fetch_logs` metadata, scoped to fixed 2022-2025 targets. No result, payout, profile, business writes or batch writes. Current race request and ledger-referenced historic request identifiers form candidates; all matching PJ0315 fetch versions are retained. Server-side cursor pages of 100 avoid PDO result buffering; the parameter scope has a 24 MiB bound. Connection loss fails closed without reconnect/retry. The connection is closed before Raw access.

`PC0201.C0201data` date/track/number and `C0201racedtl.C0201sensyu` carNum/numPlayer are checked against the ledger and PJ0315 syaban/sensyuRegistNo. Individual faults hold affected values, not all valid rows; race mismatch holds all. Missing/extra and duplicate entries are audited. External IDs remain six-character strings, including leading zeroes.

## Value and Timing Contract

`displayed_start_count` is taken only from `PJ0315.sensyuTypeInfo[].stTori`. Integer or ASCII digit string 0-9999 is accepted, including observed zero. This is the existing Parser's technical range, not a real maximum or prediction threshold. Missing field, null, empty string, fullwidth dash `－`, invalid format and out-of-range are distinct. No aliases, float/exponent conversion, rate or event inference.

Original presence/type/value, source pointer, fetch ID, fetched_at, original/converted hashes and all response versions remain available. Semantic signature sorts only object keys; list order, missing/null and raw types remain distinct. No shared canonical or existing signature contract changes.

S-specific aggregation period, statistical baseline and correction time remain UNKNOWN/null unless supported by the saved page. The four-month race-score explanation is not applied to S. System fetch time is not official publication or historical observation time. Always `historical_as_of_available=false`, `prediction_use=NOT_AUTHORIZED`, `points=null`.

Official static sources (only these GETs authorized, both HTTP 200 on 2026-10-01):

- https://www.keirin.jp/pc/static/beginner/basics/racecard.html
- https://keirin.jp/pc/dfw/portal/guest/news/2007khn/12/news20071213_01.html

They describe the S start count; no S-specific aggregation window was established. These sources do not prove every historical Raw field or historical availability. Bodies/headers are preserved in the new run directory; no race HTTP is performed.

## Commands and Artifacts

Command: `php -d memory_limit=128M artisan keirin:stat36:start-count`.

- `plan`: no DB/HTTP/Raw body access.
- `export --output-dir=...`: one scoped metadata export, after tests.
- `inspect --source-dir=... --output-dir=...`: first two fixed targets per year, lowest fetch ID; no outcome/value-based selection.
- `build --source-dir=... --output-dir=...`: offline full generation once after the sample check.
- `reproduce --source-dir=... --original-dir=... --output-dir=...`: new independent parsing pass, no re-export.

New outputs only under `/home/shinya/neo-keirin-artifacts/stat36-start-count-01/`. Each bundle is sealed, source/code are checked again before publication, Raw is hash checked on each read, and existing paths are not overwritten. Original/converted Raw is referenced, not copied. DB and HTTP are denied in offline commands.

Yearly snapshots/unresolved, coverage JSON/CSV, first examples, fetch audit, source inventory, contract and verification are deterministic artifacts. Elapsed time/peak memory/exit code belong to separate execution logs. Occurrence rows and distinct entries are separately counted; reason counts can overlap.

## Verification and Execution

最終コードの通常全体: **2550 passed / 9既存skip / 24223 assertions**（2559 tests、exit 0、183.943秒）。関連回帰230 tests / 4502 assertions。限定Pint成功、変更PHP13件と外部専用スクリプト3件の構文成功。独立128M試験は100MiB超の人工台帳から15,000行を生成。既存テストの削除・緩和・新規skipなし。

接続確認の修正前にも通常全体を1回実行し成功したが、その結果は初期コードの記録として分離した。最終コードでの実行は `full-suite-final/`。実行前の接続確認SQLを既存経路と同じ `host(inet_server_addr())` に統一し、人工テストでもそのSQLとREAD ONLY失敗時の停止を検証した。

## 実データ生成・独立再現

保存root（実在確認済み）:

```text
/home/shinya/neo-keirin-artifacts/stat36-start-count-01/run-20261001-nK8sZXFb/
```

開始・終了HEADは `212d0196663f3720b4d2a1865f8918a26c9a8e47`、変更は未コミット。

| 年 | 固定レース | 固定出走/unique一致 | 取得版 | 延べスナップショット | 数値0 | 正数 |
|---|---:|---:|---:|---:|---:|---:|
| 2022 | 24,868 | 174,152 | 24,868 | 174,152 | 48,597 | 125,555 |
| 2023 | 25,561 | 181,548 | 51,124 | 363,103 | 90,061 | 273,042 |
| 2024 | 25,624 | 182,004 | 25,624 | 182,004 | 43,991 | 138,013 |
| 2025 | 25,273 | 180,005 | 25,544 | 181,779 | 45,362 | 136,417 |
| 合計 | 101,326 | 717,709 | 127,160 | 901,038 | 228,011 | 673,027 |

各年とも候補なしレース0、対応不能レース0。観測行のstTori欠損・形式不正・本人/レース不一致は各0。期間・基準時点未確認の数値行は各年の延べスナップショット数と同じ、合計901,038行。同一出走で複数の意味上の値版を持つ件数は0だが、全取得版は保持した。

2023年の取得版1件（race 84651、fetch log 215551）は過去の `DNS_FAILURE`、Raw未保存。数値行を作らず `FETCH_NOT_SUCCESSFUL` として記録。同じレースの他取得版は照合成功しているため、対象レースの欠落ではない。これは今回の生成失敗ではなく、保存元の失敗履歴である。

### 少数資料の根拠

固定順の各年先頭2レース計8件を確認（race IDs: 12542, 12543, 37815, 37816, 63439, 63440, 89012, 89013）。すべてRaw hash、PC0201日付/場/レース番号、PC0201/PJ0315/固定台帳の車番・外部IDが一致。S欄の最小HTML断片は `sample-field-evidence.json` に保存。例: race 12542の1番 `stTori="4"` -> 4、2番 `"0"` -> 0（どちらも原型string）。

`PJ0315.lastUpdateTime` は存在し、例は `2025/01/01 07:00&nbsp;更新`。ただしS固有の集計基準や訂正時点と確認できていないため、Sの時刻に転用していない。公式資料のS説明と既存RaceDetailParserのfield対応を併記し、競走得点等の4ヶ月説明は転用しない。

### source抽出の試行と固定

- `export/ -> source/`: 4.199秒、exit 1。業務メタデータstream開始前にRuntimeException。元の失敗した接続値はログに未保存で、具体的な不一致値を実測済みとはしない。接続先文字列化が既存SQLと違うことを修正し、非秘密の接続検証証跡を追加した。
- `export-final/ -> source-final/`: READ ONLY確認成功後、進行が遅いため自分のCLIだけを668.700秒で中断（exit 15）。完成ファイル・partialを保持、COMPLETEなし、採用しない。
- `export-cursor-plan/ -> source-cursor-plan/`: 同じ最終コード・取得範囲で **6.639秒、exit 0、peak 46,141,440 bytes**。この完成台帳だけを採用。接続限定 `PGOPTIONS='-c enable_nestloop=off -c cursor_tuple_fraction=1.0'` を使用。DB/role/server設定変更なし。実行計画自体の採取はしていないため、内部planを確認済みとは記載しない。

したがって接続・抽出の試行は3回（失敗1、中断1、完成1）であり、「DB接続や部分メタデータ読取りまで1回だけ」とは扱わない。各試行の証跡を保持し、異なるスナップショットの途中データは混ぜていない。独立再現のためのDB再抽出は0回。

完成抽出の接続は `neo_keirin_prediction_db/public/127.0.0.1:5432`、session/transaction READ ONLY=on、REPEATABLE READ、snapshot `398797:398797:`。読取りは許可されたraces識別情報と固定対象に対応する取得ログメタデータのみ。接続を閉じた後にRaw処理を開始した。

### 完成成果物

- `source-cursor-plan/manifest.json`: 5405 bytes、SHA-256 `691717e4a95c7912c5fcfff23c3da8a1e1df13194ccd87bd5cc4dde1d3f41e7a`
- `build/manifest.json` と `reproduce/manifest.json`: 5297 bytes、SHA-256 `bcadbe02aecb3eaee510b2646fc38b305db08f11633d3bf397be1e193190386d`
- **14成果物とmanifestが完全一致**。原本Rawの再コピーではなく、固定台帳・保存Rawからの独立再解析。
- build: 2026-10-01 09:00:03–09:08:32 JST、509.335秒、peak 33,554,432 bytes、exit 0。
- reproduce: 2026-10-01 09:09:51–09:17:58 JST、487.024秒、peak 33,554,432 bytes、exit 0。
- 各Commandは `memory_limit=128M`。source/Raw/hash/対象集合・公開前照合成功。詳細は `report.json`、各 `execution.json`、`execution-notes.md`。Raw断片・静的資料以外のRaw複製なし。

実行経路は plan -> export（完成source固定）-> inspect -> build -> reproduce。全Commandの実引数・stdout/stderr・開始終了・終了コードはrun内のログへ保存。既存source、旧正式成果物、C1、STAT35、StartObservation v3/署名v2は不変。業務DML/DDL、新規レースHTTP、学習・性能評価、2026レース参照は実施せず。許可された静的説明2 GETのみ別途実施。

**表示S回数スナップショット取得は完了。期間・基準時点は未確認で、過去予測入力やレース単位S取得の完成とは扱わない。レビュー待ちで停止する。**
