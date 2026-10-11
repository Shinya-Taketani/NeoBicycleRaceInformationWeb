# C1-RACECARD-ASOF-PREFLIGHT-01

## 結論と工程

2026-10-11、**NO_PRESTART_FETCH_EVIDENCE**。今回確認した保存取得ログでは、固定C1対象の発走前取得候補は0件だった。Phase Aの早期終了条件に従い、Phase B・Raw本文解析・追加Extractor・候補再生成は実施していない。すべての歴史資料に証拠が存在しないという結論ではない。

開始時はclean main / ローカルorigin `daca2371e091ae9b01b3128d66c4c4c151dfeb47`。同コミットの `Merge pull request #99` とユーザーの受入を確認し、`audit/c1-racecard-asof-preflight-01` を新規作成した。前工程の **PARTIAL_READINESS** は受入済み。fetch・commit・push・PR操作は行っていない。

`historical_as_of_available=false`、`prediction_use=NOT_AUTHORIZED` を維持する。精度改善実験ではなく時点の前提監査であり、登録脚質の予測利用・ライン役割推定・STAT-17/18/28/29計算・LIVEは承認しない。

## 対象とseal

前工程の保存snapshotを読取り専用で再利用した:

`/home/shinya/neo-keirin-artifacts/c1-tactical-input-readiness-01/run-20261010-224221-fb937a1d/snapshot/`

- manifest: 3,478 bytes / SHA-256 `2332bebaa235385843e4d6d4a308c1a31090b7f151c3f18b7871d19e08964afd`。既存COMPLETEとも照合。
- targets.jsonl: 200,818,809 bytes / SHA-256 `ae0e617d50f5f7c3708ccc9878c8bb6d0d5768be89dac6967a3335264757e9bb`。開始・終了でseal一致。

targetから固定race ID・日付だけをDB照合へ使用し、結果や履歴値は利用しない。保存targetの年別レース件数と出走行件数は旧manifestに一致した。706,051出走は旧targetの記録件数であり、今回race_entriesの706,051行を再auditした結果ではない。

## 対応規則とREAD ONLY

JSJ017は `RaceEntryListFetcher` が開催日のencpとtypeをGET `/pc/json`へ送る。PJ0315は `RaceLiveFetcher` がレースのencpとdispをPOST `/pc/racelive`へ送る。両者を別ソースとして扱った。
`KeirinHttpClient` と同じ、パラメータキーをソートしたメソッド・URL・パラメータのSHA-256からrequest_keyを算出。`RawResponseStorageService` は原bodyのSHA・サイズ・取得時刻・Rawパス・parser版を保存している。

今回のメタデータ対応は次の積み重ねに限定する:

1. SQL VALUESの固定race ID・日付と、2022〜2025年のrace/race_dayを照合。外部race IDの場・日・番号も確認。
2. 現在のrace_day/race encpから期待request_keyを算出。固定対象内の別day/raceへの再利用・衝突を検査。
3. ログのsource/request_key、メソッド、URLの基部、type/disp、encpのSHA、パラメータ集合が一致。
4. 同じbatch_run_idに、固定dayの `RACE_ENTRY_LIST / race-day:{id}`、または固定raceの `RACE_RESULT / race:{id}` が存在する。

encp生値・接続URL全文・認証情報・Raw全文は出力しない。現行のencpとBatchRunItemによるメタデータ対応であり、Raw本文で歴史的な対象を証明したという意味ではない。

既存 `AgariC1Context\ReadOnlySession` を使用。`127.0.0.1:5432 / neo_keirin_prediction_db / public`、session/transaction/snapshotともREAD ONLY、REPEATABLE READ、snapshot `485061:485061:` を確認後にSELECTした。lock timeout5秒、statement timeout5分。固定レース500件・リクエスト100件単位、SQL側の日付限定、終了時rollback/disconnect。
DB照会は固定レースmetadata200チャンク・取得ログ1104チャンク。全Eloquent Collection・DBへの一時テーブル作成は使用していない。新規証跡内のSQLite索引だけに限定メタデータとハッシュを保存し、索引も削除せず保持した。本番DBへのINSERT/UPDATE/DELETE/DDLは0件。

## Phase A結果

各ソースの対象は同じ固定99,669レース。JSJ017は開催日単位のログを当日の固定対象レースへ対応させるので、レース数とログ数を混同しない。

| 対象年 | 固定レースmetadata照合数 | JSJ017:現在発走時刻より後のログのみ | PJ0315:現在発走時刻より後のログのみ | 発走前候補（各ソース） |
| --- | ---: | ---: | ---: | ---: |
| 2022 | 24,394 | 24,394 | 24,394 | 0 |
| 2023 | 25,197 | 25,197 | 25,197 | 0 |
| 2024 | 25,212 | 25,212 | 25,212 | 0 |
| 2025 | 24,866 | 24,866 | 24,866 | 0 |
| 合計 | 99,669 | 99,669 | 99,669 | 0 |

| ソース | 固有リクエスト/対象entity | 対応ログ | HTTP2xx | Rawパス＋SHA記録あり | 最初の取得（JST） | 最後の取得（JST） |
| --- | ---: | ---: | ---: | ---: | --- | --- |
| JSJ017 | 10,659開催日 | 21,203 | 21,203 | 21,203 | 2026-07-21 05:42:27+09 | 2026-08-02 18:27:20+09 |
| PJ0315 | 99,669レース | 125,130 | 125,129 | 125,129 | 2026-07-21 19:54:53+09 | 2026-08-04 13:08:49+09 |

両ソースとも対象レースの対応ログ欠損0、現在のencp欠落0、対象識別不備0、固定対象内encp競合0、リクエストmetadata不整合0、予定発走時刻不明0、時刻同値0。ログ欠損による判定ではなく、今回対応したログがすべて対象レース後の取得だった。PJ0315は1ログのみHTTP2xxとRawパス/SHAの記録がなく、応答サイズ0。それでも当該レースには他の対応ログがあり、レース単位のログ欠損とは別。
全対応ログのparser版は `2026-07-18-initial`。JSJ017応答サイズは6,894〜21,218 bytes、PJ0315は0〜106,922 bytes。

取得時刻と発走予定時刻はoffset付き日時として比較し、候補判定はstrict `<`。比較用の現在 `scheduled_start_at` は後日JSJ017/PJ0315同期で更新され得るため、当時の確定発走時刻の証明にはしない。取得日は2026年だが対象開催は固定2022〜2025年であり、2026年開催のmetadata/結果/Rawは参照していない。

## 未確認範囲

- 過去encp、歴史的に別URLから取得されたログ、対象BatchRunItemのないログは今回帰属させていない。ログ欠損0は上記の照合範囲内の値。
- encp衝突検査は固定対象内だけ。対象外・2026のmetadataへ広げておらず、対象外も含めた全期間の一意性は未確認。
- 現在DBの予定時刻とメタデータ対応を用いた限定判定。Raw内の対象日・車番・選手・脚質原値・現在値との一致は未検証。
- Rawパス/SHAはログ上の記録を確認しただけ。実Rawの存在・body SHA・破損有無は未確認。Phase B未実施のため `evidence-samples.json` は作成していない。
- 公式初回公開時刻はUNKNOWN。全歴史資料の発走前証拠不存在を断定しない。

## 証跡と実行

新規証跡:

`/home/shinya/neo-keirin-artifacts/c1-racecard-asof-preflight-01/run-20261011-095607-a40d23f1/`

`source-inventory.json`、`timing-summary.json`、`execution.json`をmanifestにsealし、COMPLETEを作成した。取得ログID/対象ID/Raw SHA等の代表メタデータを記録したが、Phase BのRaw検証サンプルではない。補助SQLite索引、スクリプト、stdout/stderr、完全argv/exitのprocess.jsonも同じ新規ディレクトリへ保持。

manifest: 523 bytes / SHA-256 `d0cf1d4d424094a462d5a55ef705b181a101e7085c35441aa8470cd67eec2467`。

```bash
/usr/bin/php8.5 "$RUN/preflight.php" launch "$RUN"
```

`RUN`は上記絶対パス。保存runner内の既存bwrapによるread-only filesystem隔離を使用し、実.env/storage/既存成果物は読取り専用、新規証跡と人工/tmpだけを書込み可能にした。実DB接続用のTCPは維持し、外部HTTPは呼び出していない。PGOPTIONSは当該プロセスだけREAD ONLY/timeoutを設定。既存設定は変更しない。

2026-10-11 09:56:17〜09:56:30 JST、処理本体12.824877秒、PHP8.5.4、memory_limit128M、peak29,360,128 bytes（28MiB）、exit0、stderr空。開始・終了で直接依存コード13ファイルと入力sealが一致。
監査スクリプトの構文検査成功。アプリコード/テストの変更はなく、テスト・Pint・以前の監査/生成/再現・学習/予測/性能評価・新規取得・2026参照は各0回。原本・モデル・既存成果物変更0、永久削除/退避処理0。

次は今回の文書と監査証跡のレビューだけ。将来の収集を検討する場合は、属性を含む原Raw・対象識別・取得時刻・当時の発走時刻根拠を直接結び付ける保存仕様が必要だが、本工程では実装しない。
