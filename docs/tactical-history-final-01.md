# TACTICAL-HISTORY-FINAL-01

## 実学習前の契約

2026-09-17、PR #55 merge `5071339125a5423cc37327953512634c0910cf42` を起点とする。
対象はTACTICAL-HISTORY-01 v2 C1だけ。これは新しい性能評価ではなく、評価済み方式の最終developmentモデル候補を作る工程である。
保存先は従来の `/home/shinya/neo-keirin-artifacts/` 配下で今回専用の新規ディレクトリを使い、旧成果物は移動・上書きしない。

### 数値契約

- STAT-01 RACE_SCORE_Z anchor係数1.0、既存12 STAT、`HistoryAggregator::FEATURES` の固定順4回数。開催初日00:00 JST前120日窓・同開催除外・欠損/観測0の区別を維持。
- solver `TACTICAL-HISTORY-CONSTRAINED-EUCLIDEAN-FISTA-v2`、model `TACTICAL-HISTORY-SEQUENTIAL-POSITION-v2`。順位別conditional NLL、正規化L2/group/smoothness、制約付きユークリッドproxを変更しない。
- 200 accepted updates、係数変化1e-7・相対目的関数変化1e-10・近接勾配/中心化残差1e-7を維持。lambda `[0,1e-6,1e-5,1e-4,1e-3,1e-2,1e-1,1]` をstrong-to-weakで検証し、収束済み候補だけからwarm startする。
- OOF-1: Train 2022 → Validate 2023。修正版run-01/C1-inner-Aを再利用。
- OOF-2: Train 2022～2023 → Validate 2024。修正版run-01/C1-inner-Bを再利用。
- OOF-3: Train 2022～2024 → Validate 2025。全8候補pathを新規生成。2025をこのfoldのbin/support/係数生成には使わない。
- 3fold共通の適格候補だけで既存 `Bt03e03OneSeSelector` を実行。順位等重み・検証年等重み・年層別race bootstrap 2000回・seed20260812を維持。最終lambdaは未決定で、的中率や採否Gateを再選択に使わない。
- 最終fitは選択lambdaのpath refitを2022～2025全体で行う。bin/category/support/全3順位の係数を新規生成し、選択lambda非収束ならfallbackせず停止。
- E06型decoder、Primary/Supporting区別、固定tie規則を維持。alpha/channel scale/追加判定閾値はNOT_APPLICABLE（順位別確率モデルにはalpha混合・RACE_CENTERED_RMS・追加校正を使わない）。

### 再利用・読込・安全性

再利用元は `/home/shinya/neo-keirin-artifacts/tactical-history-01-review-fix-20260916-01/` の実ファイル。
run-01を唯一の検証標本とし、run-02は再現性の証拠としてのみ確認する。入力・履歴・固定source・コード・候補状態・学習年・bin/support・損失順序・hashを照合する。
消去済みbinary spoolはvalidation-losses.jsonlから復元する。必要ファイル欠損は停止し、別run/modelへのfallbackはしない。

保存モデルはmanifest/hash、solver/model/入力版、feature順序、bin、係数数・有限値、中心化制約を検証後に復元する。予測入力は許可fieldだけを受理し、当該raceのrank/status/labelsを拒否する。
旧Outer 2024/2025 C1予測の一致を新しい読込経路で確認するが、学習・精度評価は再実行しない。最終fitでも保存前後の予測一致を確認する。
OOF-3と最終fitは独立に2回実行し、数値モデル・選択・bin/support・予測・semantic hashを比較する。日時/PID/出力pathは別監査情報とする。
入力・参照元・実行コードのSTART/END hash、既存SourceIntegrityによる固定STATと履歴の両検査を要求する。DB検査はREAD ONLY確認後のみ。128MiB、streamingを維持する。

2026・LIVE・本番DB書込み・scraping・Migration・C0/E03/E06/E08再学習/再評価・DATA-AUDIT再実行は禁止。
BACKFILLED_FINAL_RESULT / DEVELOPMENT_ONLY、過去公開時刻UNKNOWNを維持。学習集合での成績を未知データ改善として評価しない。
成功時も `FINAL_FIT_REPRODUCED_AWAITING_REVIEW` で停止する。技術的なモデル固定はユーザーによる最終採用承認ではない。

## 実行状態

2026-09-17に `FINAL_FIT_REPRODUCED_AWAITING_REVIEW` で正常終了した。
成果物: `/home/shinya/neo-keirin-artifacts/tactical-history-final-01-20260917-01/`。
元PR #55の旧成果物は参照のみで変更せず、OOF-1/2のbinary loss spoolだけを新しい出力先へ復元した。

### lambda選択

| 候補 | 3組の順位/年等重みNLL | bootstrap標準誤差 |
|---|---:|---:|
| 0.1 | 1.599191892441363 | 0.0018466380597159295 |
| 1 | 1.6203242631802581 | 0.0019149902269534685 |

最良候補0.1のOne-SE上限は `1.601038530501079`。lambda=1は上限を超えるため、最終lambdaは0.1。
OOF-3の1/0.1は全3順位収束、残り6候補はNUMERICALLY_NON_CONVERGEDで診断を保存し選択対象から除外した。
旧OOF-1は0.1/1、旧OOF-2は0.01/0.1/1が適格。3組の共通適格候補だけを使用し、run-02の旧検証損失を標本として二重計上していない。

### 最終fit

2022: 24,394レース/170,835出走、2023: 25,197/179,007、2024: 25,212/179,089、2025: 24,866/177,120。
合計99,669レース/706,051出走、active係数数137。各順位の適格性・除外規則は既存Objectiveのまま。

| 順位 | 適格レース | 除外レース | accepted updates | 状態 |
|---|---:|---:|---:|---|
| POSITION_1 | 99,420 | 249 | 113 | CONVERGED |
| POSITION_2 | 99,190 | 479 | 78 | CONVERGED |
| POSITION_3 | 98,925 | 744 | 100 | CONVERGED |

最終model SHA-256: `e3cc1f7f10af60bb22f97a2172ca52ef2c09a3393222ee46c25619de752d43a1`。
近接勾配mapping最大値は順に1.2586484673948739e-8、6.073881758661415e-8、1.6347066200683713e-8。
中心化残差はいずれも1.4e-17未満。収束定数・grid・目的関数・decoderは変更していない。

### 再現性・保護

- 新規学習はOOF-3 full pathと最終selected pathの各2回だけ。C0・旧OOF-1/2・旧Outerモデルは再学習しない。
- 2回で26ファイルのbyte hashが一致。モデル・選択結果・bin/support・学習入力・検証損失・予測・診断を含む。公開packageの内容も一致。
- 旧C1 Outer 2024の25,212件、Outer 2025の24,866件は保存モデルから全予測を再現できた。これは精度再評価ではない。
- 最終fitの保存前後の予測に加え、公開artifactからのArtisan予測24,866件もhash一致。2025への最終モデル適用は技術検証のみで、未知データ精度として集計しない。
- 開始/終了のDB session/transactionはREAD ONLY。固定52 STAT run、履歴221,559窓、対象706,051出走、717,709出走IDを照合した。
- 参照元229ファイルと実行コードのSTART/END hash一致。2026参照・本番DB書込み・旧成果物上書きは0。
- 実行は128MiB制限、PHPピーク34MiB。重要成果物は最初から永続ディレクトリへ保存した。
- 学習開始時コードと契約は `execution-code/`、`fit/execution-contract.json` に保存。進捗・例外は `fit/state.json` と `execution.log`。終了検査失敗時はmodel.json等の診断を保持し、artifact.json/result.jsonを公開しない。

## 再利用可能なコマンド

```bash
php artisan keirin:backtest:tactical-history-final --plan
PGOPTIONS='-c default_transaction_read_only=on -c statement_timeout=120000' \
BT02_PSQL_BINARY=/usr/lib/postgresql/18/bin/psql \
php -d memory_limit=128M artisan keirin:backtest:tactical-history-final --execute \
  --source-bundle=/path/to/verified-parent-bundle \
  --output-dir=/path/to/new-persistent-final-fit-directory
php -d memory_limit=128M artisan keirin:backtest:tactical-history-predict \
  --artifact=/path/to/final-fit/run-01/final/artifact.json \
  --input=/path/to/sealed-outcome-free-input.jsonl \
  --output=/path/to/new-predictions.jsonl
```

実行済み最終fitを再実行する指示ではない。出力は新規のみで上書きを拒否する。
予測コマンドは学習・DB接続を行わない。現工程では2022-2025の固定形式入力だけを受け付け、2026を含む他年は拒否する。
通常のrace rank/status/labelsを受け取らず、STAT-01由来のstat01_rankは既存契約の入力として区別する。

## テスト・残制約

新規28テストを追加。実サービス経路の3fold/独立2回fit、spool復元一致、誤学習年・元成果物改変拒否、保存モデル契約/係数/bin/support/中心化/hash検証、欠損/非active bin、正解・2026入力拒否、終了検査失敗時の非公開、コマンドを検証した。
関連59 tests / 529 assertions成功。全件は1,061 tests、1,052成功・9 skipped、7,695 assertions（128MiB）。
変更PHP 11ファイルの構文検査、変更ファイルPint、git diff --checkは成功。
全件Pintは既存 `tests/Unit/Domain/Keirin/Backtest/Bt03e08BoundedMemoryTest.php` のstatement_indentationだけ失敗。今回の対象外なので変更していない。
旧E03/E06/E08/TacticalHistory数値クラス、Migration、DBを変更していない。最終採用、2026評価、LIVEは未許可である。
