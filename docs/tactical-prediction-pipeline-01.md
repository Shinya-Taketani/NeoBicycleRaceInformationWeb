# TACTICAL-PREDICTION-PIPELINE-01

2026-09-18。状態: **VERIFIED_AWAITING_REVIEW / DEVELOPMENT_REPLAY_ONLY**。
PR #56マージ後のmain `e559155d703bf8384c035a82e890c3abcf24ff38` から、`feature/tactical-prediction-pipeline-01` で実装。

## 許可範囲

- 2022-2025の対象race_idから固定STAT-01/既存12 STATと開催初日00:00 JSTより前の120日履歴を取得し、保存済み最終C1で予測する。
- モデルSHA-256は `e3cc1f7f10af60bb22f97a2172ca52ef2c09a3393222ee46c25619de752d43a1` に固定。
- 学習InputBuilder::build、STAT生成、OOF、lambda選択、fit、bootstrap、精度/Gate再評価は呼ばない。旧solver/モデル/decoder/特徴量順序は変更しない。
- 対象自身の結果・着順・結果状態はSELECTしない。結果表へのアクセスは指定選手の過去120日窓・別開催に限定する。
- `BACKFILLED_FINAL_RESULT / DEVELOPMENT_ONLY`、`publication_time_verified=UNKNOWN`。2026、LIVE、DB書込み、新規取得、Migrationは対象外。

## コマンド

`keirin:backtest:tactical-prediction-pipeline` は `--plan` / `--execute` / `--reproduce` のいずれか一つを指定する。
`--plan` はDBに接続せず、ファイルも生成しない。

次は実際に成功した依頼の例。同じコマンドの再実行は新規生成ではなく、固定済み成果物の検証・再利用になる。

```bash
php -d memory_limit=128M artisan keirin:backtest:tactical-prediction-pipeline --plan

PGOPTIONS='-c default_transaction_read_only=on -c statement_timeout=120000' \
php -d memory_limit=128M artisan keirin:backtest:tactical-prediction-pipeline \
  --execute --mode=DEVELOPMENT_REPLAY_ONLY --race-id=37750 \
  --input-as-of='2025-12-31 22:07:00+09' \
  --artifact=/home/shinya/neo-keirin-artifacts/tactical-history-final-01-20260917-01/fit/run-01/final/artifact.json \
  --request-id=development-2025-race-37750-schema-fix-01-status-fix-01 \
  --output-root=/home/shinya/neo-keirin-artifacts/tactical-prediction-pipeline-01-20260918-01

DB_CONNECTION=disabled-offline-verification DB_URL='' \
php -d memory_limit=128M artisan keirin:backtest:tactical-prediction-pipeline \
  --reproduce --request-id=development-2025-race-37750-schema-fix-01-status-fix-01 \
  --output-root=/home/shinya/neo-keirin-artifacts/tactical-prediction-pipeline-01-20260918-01
```

出力rootは `config/tactical_prediction_pipeline.php` の既存合意base配下の専用ディレクトリを事前に用意する。base自体・リポジトリ内・原本FINAL-01/v2配下・symlinkは拒否する。
PostgreSQLのsession/transactionのREAD ONLYがともにonでなければ入力照会前に停止する。開始/終了取得はそれぞれ独立したREPEATABLE READ READ ONLY transactionをrollbackで終了する。SQLiteは人工テストのインメモリDBだけを許可する。

## 入力・時点・完全性

- メタデータ、5-9車の出走者、固定run UUID/version/集計件数、各STATの行ID・player/bike・status・input_hashを照合する。latest run/profileへのfallbackはない。
- STAT時点は指定input_as_of以下であることを要求する。STAT-01の時点は現在の発売締切、なければ予定発走時刻と一致を要求し、その由来を監査に残す。
- source_fetched_atがNULLの場合はそのまま記録する。input_as_of/calculated_atが不明なら時刻を創作せず拒否する。計算時刻や後日取得時刻を発走前公開時刻の証拠にしない。
- STAT-01のraw/rank/anchor、12 STATの順序とOperational eligibilityは既存経路を再利用する。既知の利用不能状態はNULL、未知の状態や出走者集合の矛盾は拒否する。
- HistoryReader/HistoryAggregator v2をそのまま利用する。AVAILABLEの観測0とNO_HISTORY等のNULLを区別し、履歴の原文・識別情報検証を維持する。
- 予測入力はModelLoaderの許可fieldのみ。run/feature/timing/履歴参照は別のaudit.jsonに保存する。
- 固定runの同一性と**今回の対象行・必要な履歴窓**を開始/終了で照合する。全4年・52 STATの全行fingerprint再計算やDATA-AUDITの再実行ではない。

## 保存と再現

出力root内の `requests/<request_id>/` だけを公開領域とする。
`.staging/` に request/model/artifact/input/prediction/audit/source-end/code/manifestを作り、source・モデル原本・入力/予測・コードの終了検査後にLOCKED sealを付け、ディレクトリrenameで公開する。
実行コードの内容hashとPHPバージョンを保存し、過去の学習実行コードと区別する。generated_at/locked_atは実際の処理時刻であり、過去のinput_as_ofではない。

- 永続lockファイルのflockによりrequest単位で排他する。BUSY時にlockを削除しない。
- 同一requestと同一時刻/対象/モデル/契約は、全sealを照合してREUSED。DBから生成し直さない。
- 同一IDで異なる依頼はCONFLICT。破損は停止し、再生成しない。
- 新規試行は別ID。失敗stage/failure.jsonとeventsを残し、他のstageや既存束は削除しない。
- `--reproduce` は固定入力とコピー済みモデルから予測し、元のmanifestと完全一致を要求する。DBは使わず、元の固定束も変更しない。

## 実データ検証

保存先: `/home/shinya/neo-keirin-artifacts/tactical-prediction-pipeline-01-20260918-01/`。

1. 既存inputs-v2 manifestを検証し、2025入力24,866レースのメタデータだけから最終日を選定。
2. `targets.json` に2025-12-31の63レースを固定してから、固定STAT-01のinput_as_ofを取得。時刻の変更や結果による選別はない。
3. DBの固定STAT・限定履歴から新規入力を生成。既存JSONLは比較側にだけ使用。
4. 既存PredictionServiceと同じ最終モデルによる比較側予測、固定入力再現、再利用を確認。

| 確認 | 結果 |
|---|---:|
| 固定対象 | 63レース / 432出走 |
| 保存済み入力との全値・manifest一致 | 63 / 63 |
| 既存PredictionServiceとの予測・manifest一致 | 63 / 63 |
| DB接続先を無効にした再現 | 63 / 63 |
| DB接続先を無効にした再利用 | 63 / 63 |
| 最終不一致 / 拒否 / 未実行 | 0 / 0 / 0 |
| READ ONLY | session=on / transaction=on |
| 対象cutoff | 63件すべてSALES_CLOSE_AT |
| 履歴状態 | 432件すべてAVAILABLE |
| 最大RSS (実行・再現・再利用) | 73,980 KiB、全子プロセスmemory_limit=128M |
| 最終モデルと参照8ファイル | 開始/終了SHA-256不変 |

最終結果: `validation-status-fix-01/comparisons.json`。初回失敗と修正過程も保存している。
初回は新規実装が存在しない `race_entries.deleted_at` を参照し63件とも公開前に停止。現行Model/Migrationに合わせ、人工schemaからも架空列を除去した。
次の試行は39件成功、24件は新規検証が既存NO_HISTORY等を受理していなかったため停止。既存StatisticFeatureResultStatus Enumへ統一し、6種類の利用不能状態の回帰テストを追加した。
最終試行では39件を検証再利用し、残り24件を新IDで生成した。39件を新規生成と表現しない。旧記録と成果物は上書きしていない。

## 人工テストと停止位置

`tests/Feature/TacticalPredictionPipelineTest.php` は独立SQLite `:memory:` を使用する。
5-9車、既存入力経路との一致、結果の非参照、同一開催/未来履歴の除外、時点・状態・出走集合の拒否、0/NULL区別、発売締切優先、plan、2026拒否、モデルhash固定、再利用/CONFLICT/二プロセス排他、同サイズ改変、source/モデル/監査の終了drift、途中失敗、DB不要再現を検証する。

検証コマンドと結果:

- `php -d memory_limit=128M artisan test --filter=TacticalPredictionPipelineTest`: 33件成功、831 assertions。
- `php -d memory_limit=128M artisan test`: 1,149件中1,140成功、PostgreSQL専用9件skip、8,659 assertions。
- ArtisanからのPHP子プロセスにはini設定が継承されないため、`php -d memory_limit=128M vendor/bin/phpunit --no-progress --colors=never` でも全体を実行。同じ1,140成功/9skip/8,659 assertions。focusedも同様に直接実行し33件成功。ログは `checks-phpunit-direct-128M/`。
- `./vendor/bin/pint --test`: 未変更の `tests/Unit/Domain/Keirin/Backtest/Bt03e08BoundedMemoryTest.php` の既存 `statement_indentation` 指摘1件で失敗。範囲外なので変更していない。
- 変更PHPだけのPint: 成功。新規PHP10ファイルの `php -l`: すべて成功。
- `git diff --check`: 成功。未追跡ファイルのno-index差分にも空白エラー出力なし。
- `checks/` にコマンド・終了値・stdout/stderrを保存。no-indexの終了値1は新規ファイルとの差分があることを示す。

これは学習済みdevelopment corpusへの技術検証であり、未知データ精度やGateは算出していない。モデル数値・旧正式artifact・2026 holdout・本番DBは変更していない。
接続機能のレビュー待ちで停止し、自動的にLIVE・次の特徴量・holdout評価へ進まない。
