# 統計エンジン第1製造単位 実装仕様

## 1. 対象範囲

この製造単位では、統計計算の実行履歴・入力監査・冪等保存を行う共通基盤と、STAT-01「競走得点による基礎実力評価」を実装する。

STAT-01の入力は、対象レース自身の出走表からPJ0315 `heikinTokuten`として取得され、`race_entries.race_score`へ保存された値だけである。`players`の現在値、`player_stat_snapshots`、`race_results`、`race_payouts`は入力にも品質判定にも使用しない。

## 2. テーブル

### statistic_calculation_runs

STATコード、計算バージョン、対象期間または対象レース、実行パラメータ、実行状態、開始・終了日時、対象レース数、処理済みレース数、対象出走数、品質別件数、レース処理エラー数と概要を保持する。

実行状態は次のいずれかとする。

- `RUNNING`
- `SUCCEEDED`
- `PARTIALLY_FAILED`
- `FAILED`
- `NO_TARGETS`

### statistic_entry_results

レース・出走・選手・車番、入力競走得点、レース内特徴量、品質状態、取得モード、入力スナップショットとSHA-256、情報源・取得日時、計算日時を保持する。

`raw_points`、`confidence`、`effective_points`は配点方式が未決定のため常に`NULL`とする。未採点を0点として保存しない。

### statistic_run_entry_results

再実行時に既存の同一結果を再利用しても、どの計算実行がその結果を参照したかを保持する中間テーブルである。`statistic_entry_results.calculation_run_id`は結果を最初に作成した実行を示し、この中間テーブルは初回を含むすべての実行を示す。

## 3. STAT-01-v1計算式

計算対象は同一レース内の有効な`race_score`だけとする。`NULL`を欠損、0以下または数値形式でない値を無効とし、0、平均、最下位へ補完しない。

有効得点を \(x_1,\ldots,x_N\)、対象選手の得点を \(x\) とする。

- 標準競争順位: `1 + xより高い有効得点の人数`
- dense rank: `1 + xより高い異なる有効得点の個数`
- strength percentile: `N = 1`なら`1.0`、それ以外は`(N - 標準競争順位) / (N - 1)`
- 平均: `sum(x_i) / N`
- 最高値: `max(x_i)`
- 平均との差: `x - 平均`
- 最高値との差: `x - 最高値`
- 母標準偏差: `sqrt(sum((x_i - 平均)^2) / N)`
- z-score: `(x - 平均) / 母標準偏差`

percentileは最高順位を1.0、単独最下位を0.0とする。同点は同じ標準競争順位とpercentileを持つ。全員同点では全員1.0、1車だけの場合も1.0とする。母標準偏差が0の場合、z-scoreは`NULL`とする。

計算結果の意味を将来変更する場合は既存の`STAT-01-v1`を書き換えず、新しい計算バージョンを追加する。

## 4. データ品質

- `VALID`: 全出走の得点が有効で、発走前取得または取得時点不明のレース固有出走表
- `HISTORICAL_SNAPSHOT`: 全出走の得点が有効で、対象レース後に取得したレース固有の過去出走表
- `PARTIAL`: 同一レースに欠損または無効値がある中で計算できた有効選手
- `MISSING_INPUT`: 一部欠損レースの得点欠損選手
- `INVALID_INPUT`: 0以下または不正形式の得点
- `BLOCKED`: 全員欠損で相対特徴量を計算できない選手
- `LEAKAGE_RISK`: 将来の入力経路追加時に、対象レースと無関係な現在値しかない場合の予約状態
- `ERROR`: 結果単位エラーの予約状態

レース処理そのものの例外は計算実行の`error_count`と`error_summary`へ記録し、後続レースを継続する。DB接続・クエリ等の構造的な失敗は実行を`FAILED`で終了して再送出する。

## 5. 取得モード

- `LIVE_PRE_RACE`: `race_entries.fetched_at`が`races.scheduled_start_at`以前
- `HISTORICAL_RACE_CARD`: 発走後、または発走時刻不明で取得日がレース日より後
- `UNKNOWN_ACQUISITION_MODE`: 発走時刻がなく、レース当日以前のため前後を確定できない場合

2024年バックフィルは取得処理日時がレース後でも、入力値がそのレースに紐づくPJ0315出走表の値であるため、現在値の混入とは扱わない。`fetched_at`は取得日時であり、値が表す対象レース日時とは別に監査する。

## 6. 入力ハッシュと冪等性

入力スナップショットは次を含み、race_entry ID順で正規化する。

- レースID、情報源、レース日、発走予定日時
- 全出走のrace_entry ID、選手ID、車番、競走得点、取得日時、取得モード
- STATコードと計算バージョン

このJSONからSHA-256を作る。相対特徴量は他選手の得点にも依存するため、個人値だけでなくレース全体をハッシュ対象とする。

結果の一意制約は次の4項目である。

```text
stat_code
calculation_version
race_entry_id
input_hash
```

同じ入力の通常再実行は結果を再利用し、`statistic_run_entry_results`だけへ実行との関連を追加する。`--recalculate`も同じ一意行を更新し、同一スナップショットの結果行を増殖させない。レース内のいずれかの入力が変わった場合は全体ハッシュが変わり、新しい監査可能な結果を作る。

## 7. コマンド

2024年全体:

```bash
php artisan keirin:statistics:build-stat01 \
  --from=2024-01-01 \
  --to=2024-12-31 \
  --chunk=500
```

単一レース:

```bash
php artisan keirin:statistics:build-stat01 --race-id=12345
```

保存しない確認:

```bash
php artisan keirin:statistics:build-stat01 \
  --from=2024-01-01 \
  --to=2024-01-31 \
  --dry-run
```

同一スナップショットを再計算:

```bash
php artisan keirin:statistics:build-stat01 --race-id=12345 --recalculate
```

対象レースはIDベースでchunk処理し、各chunkで`race_entries`を一括Eager Loadする。全レースを単一Collectionへ保持しない。対象0件は`NO_TARGETS`として明示し、コマンドは失敗コードを返す。

## 8. 今回の対象外

- STAT-02以降
- 総合点、0から100への正規化、配点、confidence
- 閾値・機械学習・勝率・着順確率
- オッズ・買い目・資金配分
- UI・API
- スクレイピング変更
- 最終予測保存
- 2024年結果を説明変数にしたバックテスト

## 9. 次の製造候補

次の製造単位では、確定要件に従ってSTAT-02以降を1項目ずつ追加し、共通run/result監査基盤を再利用する。配点や総合化は、各特徴量のバックテスト結果とバージョン管理方針が確定してから別製造単位で実装する。
