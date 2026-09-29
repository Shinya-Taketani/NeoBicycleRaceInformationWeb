# STAT-35-C1-INPUT-02

## Scope / 2026-09-30

開始main / fetch後origin/main: `2ec9fd6a4755dab2c59127494748eafa8f98a986`、PR #76レビュー後マージ済み。
開始clean、作業branch `feature/stat35-c1-input-02`。commit/push/PR操作なし。
今回の限定許可は固定candidate pin登録・人工試験・既存入力生成1回/独立再現1回・監査照合のみ。
旧C1/履歴/平均/欠損/クラス/schema/Contract::VERSIONを変更しない。
現在保存された出走表メタデータの再構成として受入。observed_at=null、historical_as_of_available=false。
入力準備は学習・予測・精度改善ではない。DB/HTTP/Raw/context再抽出/再生成/2026/LIVEは対象外。

## Fixed Sources

| 資料 | manifest bytes | SHA-256 |
|---|---:|---|
| C1 `tactical-history-01-review-fix-20260916-01/inputs-v2` | 3823 | d5158a7671f8c453afc77dccc1ab0b089c940198f58f388c038ad0d4fad684cf |
| HISTORY `stat35-player-history-01/pr73-review-fix-20260927-210230-b2d48a1f/result` | 12049 | 844440cb08b06b0e69195a6384689bc545e63806ff85237ca36a81f5dd08507e |
| CONTEXT `stat35-c1-context-01/run-20260928-215453-d152edab/mapping/candidate` | 248 | 7800bc94ed7a1d89e6bf1aee5a3bbd22d3d979dea01d511d4133214a08981268 |
| 監査照合用の外側 `mapping` | 3564 | 5facd83259a5b2b147115ff1632632a6f7a11f96e07f9f4d3078286743eb839b |

全パスの接頭辞は `/home/shinya/neo-keirin-artifacts/`。4つとも実ファイルのbytes/SHA一致を確認。
allowlistはcandidateだけ。外側は監査用であり新code identityとの一致は要求しない。
旧REVIEW_PENDING、COMPLETE、診断件数、実行hashを上書きしない。

## Implementation / Checks

Sources::REVIEWED_CONTEXT_PINSを既定constructor引数へ指定。明示的空配列と人工pin injectionを維持。
既存の厳密pin/COMPLETE/body/schema/固定target照合・個別不備と真の競合の分離・終端検査は不変。
追加試験は既定new/DI/Builderへの注入、空allowlist、信頼pin下のbody/COMPLETE改変拒否、
部分contextの生成/再現と接続・有効0・NULLの区別。旧context試験は生成前後の固定allowlist不変・人工候補非自動登録を確認。
独立128M試験は維持し、実データ512Mと区別する。

- 関連 `php artisan test --filter='AgariC1InputTest|AgariC1ContextTest|AgariPlayerHistory'`: 159成功 / 1,834 assertions、exit0、113.302338秒。
- 最終PHPの通常 `php artisan test` を1回: 全2,279件中 **2,270成功 / 既存PostgreSQL専用9skip / 19,255親assertions / 失敗0**、exit0、165.791778秒。
- 新規5ケース、既存context試験を受入後の固定allowlist/非自動登録に合わせて強化。既存試験削除/緩和/新規skipなし。
- 変更3 PHPの `php -l` と当該3ファイル限定 `vendor/bin/pint --test` 成功、Pint stderr空。
- 全体の選択/順序/設定は不変。process-local testing/SQLiteを指定し、既存独立128Mと高ピーク親回帰を保持。子assertionsを親集計へ加算しない。
- 全体実行の独立128M子プロセス19件は全exit0、測定peak最大50,855,936 bytes（48.5MiB）。既存9skipは成功扱いにしない。

## Execution Evidence

今回専用の永続root:
`/home/shinya/neo-keirin-artifacts/stat35-c1-input-02/run-20260930-054458-8739da4b/`。
runner.phpは既存方式のprocess-local testing/空DB認証/未存在config cacheを使用。
offline.phpはアプリDB呼出しとHTTPを明示的に例外拒否。ローカルPDO SQLite索引だけを許可する。
各処理は新規executionディレクトリへargv、stdout/stderr、実exit、開始終了/所要時間/peak/空き容量を保存。
テスト成功後の生成1回、別ディレクトリでの独立再現1回、補助照合1回はいずれも成功。自動再試行なし。

実行したargvは各 `*-execution/execution.json` が正本。既存Artisan Commandを同じ引数で呼ぶoffline入口:

```bash
RUN=/home/shinya/neo-keirin-artifacts/stat35-c1-input-02/run-20260930-054458-8739da4b
C1=/home/shinya/neo-keirin-artifacts/tactical-history-01-review-fix-20260916-01/inputs-v2
HISTORY=/home/shinya/neo-keirin-artifacts/stat35-player-history-01/pr73-review-fix-20260927-210230-b2d48a1f/result
CONTEXT=/home/shinya/neo-keirin-artifacts/stat35-c1-context-01/run-20260928-215453-d152edab/mapping/candidate
/usr/bin/php8.5 -d memory_limit=512M "$RUN/offline.php" keirin:stat35:c1-input build \
  --c1-dir="$C1" --history-dir="$HISTORY" --context-dir="$CONTEXT" --output-dir="$RUN/result"
/usr/bin/php8.5 -d memory_limit=512M "$RUN/offline.php" keirin:stat35:c1-input reproduce \
  --c1-dir="$C1" --history-dir="$HISTORY" --context-dir="$CONTEXT" \
  --original-dir="$RUN/result" --output-dir="$RUN/reproduction"
```

実際にはrunner.phpが上記argvに隔離環境・ログ・時間測定を付与する。再実行を促す手順ではなく今回の実行記録。
補助verify.phpは外側固定manifestとmapping-auditのseal照合後、全出走を逐次照合する。
旧contextの生成code identityを現在のSources.phpへ置換/追随させない。

## Generated Counts

生成status: `INPUTS_PREPARED`。予定値ではなく今回のsummary実測。

| 年 | race | entry | 接続 | 未接続 | 数値あり | NULL |
|---|---:|---:|---:|---:|---:|---:|
| 2022 | 24,394 | 170,835 | 170,739 | 96 | 162,346 | 8,489 |
| 2023 | 25,197 | 179,007 | 178,849 | 158 | 175,010 | 3,997 |
| 2024 | 25,212 | 179,089 | 178,992 | 97 | 174,950 | 4,139 |
| 2025 | 24,866 | 177,120 | 176,468 | 652 | 173,413 | 3,707 |
| 合計 | 99,669 | 706,051 | 705,048 | 1,003 | 685,719 | 20,332 |

各年・全体でentry=接続+未接続=数値+NULL。全年度でduplicate_context=0、conflicting_context=0。
接続成功でも窓順序不明・履歴なし・有効値なしならNULLになり、接続705,048と数値685,719を同一視しない。

| NULL理由（非排他的集計） | 2022 | 2023 | 2024 | 2025 | 合計 |
|---|---:|---:|---:|---:|---:|
| MISSING_IDENTITY_CONTEXT_EVIDENCE | 96 | 158 | 97 | 652 | 1,003 |
| NO_OBSERVED_HISTORY | 7,596 | 1,565 | 1,066 | 960 | 11,187 |
| NO_VALID_HISTORY | 485 | 24 | 18 | 15 | 542 |
| AMBIGUOUS_MEETING_ORDER | 312 | 2,250 | 2,958 | 2,080 | 7,600 |

契約上は非排他的カウンタ。今回のNULL理由の合計はNULL出走数と一致する。
summary.flagsは保留/順序ブロック時の空窓にも付くため、insufficientやno_timing_historyを全て本人の履歴不足とは説明しない。
NO_OBSERVED_HISTORY、NO_VALID_HISTORYと保留1,003件は別理由のまま。

| 年 | 数値ありのうち観測開催6未満 | 数値ありのうち有効開催6未満 | 有効な0.0 |
|---|---:|---:|---:|
| 2022 | 37,955 | 50,857 | 239 |
| 2023 | 7,682 | 26,854 | 75 |
| 2024 | 5,290 | 22,962 | 53 |
| 2025 | 4,790 | 18,781 | 98 |

部分窓は上記の数値あり集合で独立集計（相互に重なる）。NULLを0.0へ変換していない。
本人参加確認済みNULL開催は枠を消費し、直近6観測開催の選択後に有効値の等重み平均を計算する既存規則を維持。

## Independent Verification

`verification.json` のstatusは `VERIFIED`。次を元資料からストリーミングで独立確認した。

- 全99,669レース/706,051出走のyear/race/entry/bike・順序・非結果値・型が固定C1と一致。
- 各年の元C1非結果projectionと生成C1、cohort order、semantic入力hashを照合。原本ファイルSHAとprojection/semantic hashは別物として `invariance.json` に記録。過去の全NULL診断hashとの一致は要求していない。
- 14データ/監査ファイルとmanifestのbytes/SHAが独立生成物同士で完全一致（COMPLETEも一致）。runtime/workspace/再現レポートは値比較対象外。
- 原本のsource終端整合性、公開seal、選択履歴のends_on < target.meeting_start・対象開催除外・最大6開催を確認。
- 既知保留1,003件と今回未接続集合がyear/race/entry/bike単位で一致。年別96/158/97/652、全件残存・追加値NULL。以前の理由UNKNOWN_RACE_CLASSと今回のMISSING_IDENTITY_CONTEXT_EVIDENCEを混同せず両方記録。

照合に使用した `mapping-audit.jsonl` は758,692,976 bytes、SHA-256
`87507793cb6ffe25360766284f2ac656f359382a37a2a5eafdc6a053536faa86`。
外側固定manifestとこのbody sealを検証してから読み、平均計算入力へは使っていない。
小型 `held-context-verification.jsonl` は1,003件の識別子/理由/NULLだけを保存し、SHA-256は
`da466c2c8e3ed3d7c7fb9410ecbf4b2c846fb65a0a5134364199594ab6328845`。

## Measured Execution

| 処理 | 開始 JST | 終了 JST | 秒 | peak bytes | exit | stderr bytes |
|---|---|---|---:|---:|---:|---:|
| build | 2026-09-30 05:52:37 | 05:58:36 | 358.784000 | 31,457,280 | 0 | 0 |
| reproduce | 2026-09-30 05:58:55 | 06:04:56 | 360.750260 | 31,457,280 | 0 | 0 |
| verify | 2026-09-30 06:05:05 | 06:05:39 | 34.576113 | 12,582,912 | 0 | 0 |

生成/再現manifest SHA-256: `7f4356b93f90203dc727dc95d6215a8d8ce3f44869c4441c3981087ff1099c26`。
生成先: `/home/shinya/neo-keirin-artifacts/stat35-c1-input-02/run-20260930-054458-8739da4b/result`。
独立再現先: `/home/shinya/neo-keirin-artifacts/stat35-c1-input-02/run-20260930-054458-8739da4b/reproduction`。
生成開始時空き容量241,600,901,120 bytes。実行rootを新規作成し既存成果物は上書きしていない。
verify終端時のroot内454ファイル・論理サイズ6,590,513,961 bytes（報告ZIP作成前の測定）。
verifyプロセス終了後のファイルシステム空き容量235,009,486,848 bytes。共有ディスクの空き容量差と論理サイズは区別する。

## Review Delivery

リポジトリ変更は `AgariC1Input/Sources.php`、`AgariC1InputTest.php`、`AgariC1ContextTest.php`、
MASTER PLANと本書の5ファイルだけ。Builder/Index/History/Exact/Contract・C1モデル・旧成果物は変更なし。
補助offline/runner/verify/packageスクリプトと全実行ログは上記専用rootへ保存し、レビュー対象に含める。
小型報告は同rootの `STAT-35-C1-INPUT-02-review.zip` とし、summary/invariance/manifest、再現結果、
保留識別子集合、実行ログ、変更差分を収録する。大容量入力/監査JSONLとworkspaceは複製しない。
ZIPの実在・サイズ・SHAは作成後の `review-zip.json` と終了報告で確認する。

状態: `INPUTS_PREPARED_REPRODUCED_AWAITING_REVIEW`。次は生成入力のレビューのみ。
historical_as_of_available=false、observed_at=null、保留1,003件を維持する。
新規学習・係数選択・順位予測・性能評価・DB/HTTP/Raw・2026/LIVEは未実施。
入力準備と独立再現が成功したのであって、予測精度向上や過去時点入手可能性の確認を意味しない。
