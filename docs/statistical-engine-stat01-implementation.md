# 統計エンジン共通基盤・STAT-01 実装仕様

## 1. 対象範囲

この製造単位は、統計特徴量の実行・時点値・出典を監査する共通基盤と、STAT-01「競走得点による基礎実力評価」を実装する。

STAT-01の入力は、対象レースのPJ0315出走表から`race_entries.race_score`へ保存された値だけである。`players`の現在値、`player_stat_snapshots`、`race_results`、`race_payouts`は参照しない。

## 2. ER関係

```text
statistic_calculation_runs
  --< statistic_run_feature_snapshots >-- stat_feature_snapshots
                                              |--< stat_feature_values
                                              `--< stat_feature_sources
                                                        |
race_entries --< race_entry_snapshots --< race_entry_snapshot_sources
                       |                          |
                       `--------------------------+-- scraping_fetch_logs

stat_feature_definitions
  (stat_code + feature_code + definition_version)
```

計算実行IDは特徴量本体へ保存しない。実行と再利用可能な特徴量snapshotは`statistic_run_feature_snapshots`で関連付ける。

## 3. テーブル構成

### statistic_calculation_runs

STATコード、計算バージョン、対象期間・レース、実行パラメータ、状態、開始・終了日時、対象・品質・エラー件数を保持する。

### race_entry_snapshots

`race_entries`の時点値を保持する。競走得点は元表現を`race_score_raw_text`、検証済み値を`race_score`へ分離する。

- 数値かつ0より大きい: `VALID`
- `NULL`または空: `MISSING`
- 数値形式でない: `INVALID_FORMAT`
- 0以下: `NON_POSITIVE`
- `numeric(12,4)`へ保存できない: `OUT_OF_STORAGE_RANGE`
- 情報源間競合: `SOURCE_CONFLICT`

`0.00`はraw textを保持し、数値列は`NULL`とする。ドメイン上の固定上限は設けず、DB保存範囲だけを検査する。外れ値判定は今回未実装のため`NOT_CHECKED`である。

同一`race_entry_id + snapshot_hash`は再利用する。内容変更時は新snapshotを作り、以前の`is_current`をfalseにする。

### race_entry_snapshot_sources

出走snapshotへ寄与したページとFetch Logを保持する。既存`race_entries`からの移行時は次の値を使う。

- `snapshot_type`: `LEGACY_BACKFILL`
- `input_snapshot_type`: `HISTORICAL_RACE_CARD_BACKFILL`
- `source_page_type`: `RACE_DETAIL`
- `context_verification_status`: `VERIFIED_LEGACY_RECONCILED`
- `historical_backfill_scope`: `STATIC_RACE_CARD_FIELDS_ONLY`
- `eligible_fields`: `race_score`

既存行からFetch Logを一意に決定できないため、`scraping_fetch_log_id`は推測せず`NULL`とする。`context_evidence.source_link_status`へ`SOURCE_LINK_MISSING`を保存する。

### stat_feature_snapshots

統計評価単位のヘッダーである。`scope_type`は`RACE`、`RACE_ENTRY`、`PLAYER_PAIR`を持ち、scopeごとのFK組合せをPostgreSQL CHECK制約で検証する。STAT-01は`RACE_ENTRY`を使う。

主な監査項目:

- `input_as_of`と決定policy
- `input_snapshot_type`
- レース全体の入力ハッシュ
- 計算バージョン
- 特徴量状態とデータ品質
- sample count、coverage rate、最大取得日時

レース全体の入力JSONは保持せず、順序正規化した`race_entry_snapshots.snapshot_hash`の集合からSHA-256を作る。

### stat_feature_values

特徴量をfeature code単位で縦持ちする。`INTEGER`、`NUMERIC`、`TEXT`、`BOOLEAN`、`JSON`に対応する値列のうち、value typeに対応する1列だけを非NULLにする。NaNと正負InfinityはアプリケーションとPostgreSQL CHECKの両方で拒否する。

窓なしは`snapshot + feature_code`、窓ありは`snapshot + feature_code + window_type + window_value`で一意にする。window type/valueは両方NULLまたは両方非NULLである。

### stat_feature_sources

特徴量snapshotから入力元を追跡する。対象選手自身は`PRIMARY_INPUT`、レース内比較に使った他選手は`CONTEXT_INPUT`とする。各行から`race_entry_snapshot_id`、必要なら`scraping_fetch_log_id`、URL、Raw path、SHA-256、parser versionまで追跡できる。

レガシー移行でFetch Logがない場合は`source_timing_status = SOURCE_LINK_MISSING`とし、Raw情報を捏造しない。

### stat_feature_definitions

feature code、型、単位、説明、definition versionを保持する。`StatFeatureDefinitionSeeder`がSTAT-01-v1を冪等登録する。定義不足・型不一致では計算を開始せず、runを`FAILED`で終了する。

## 4. STAT-01-v1特徴量

| feature_code | 型 | 単位 |
|---|---|---|
| RACE_SCORE_RAW | NUMERIC | SCORE |
| RACE_SCORE_AVAILABLE | BOOLEAN | NONE |
| RACE_SCORE_RANK | INTEGER | RANK |
| RACE_SCORE_DENSE_RANK | INTEGER | RANK |
| RACE_SCORE_RANK_PERCENTILE | NUMERIC | PERCENTILE |
| RACE_SCORE_MEAN | NUMERIC | SCORE |
| RACE_SCORE_MAX | NUMERIC | SCORE |
| RACE_SCORE_DIFF_FROM_MEAN | NUMERIC | SCORE |
| RACE_SCORE_GAP_TO_MAX | NUMERIC | SCORE |
| RACE_SCORE_STDDEV_POP | NUMERIC | SCORE |
| RACE_SCORE_Z | NUMERIC | NONE |

計算式:

- 標準競争順位: `1 + 対象より高い得点の人数`
- dense rank: `1 + 対象より高い異なる得点の個数`
- percentile: `N=1なら1.0、それ以外は(N-rank)/(N-1)`
- 平均: `sum(score) / N`
- 平均との差: `score - mean`
- 最高得点との差: `maximum - score`
- 母標準偏差: `sqrt(sum((score-mean)^2) / N)`
- z-score: `(score-mean) / stddev`

全員同点または1車の場合も0除算しない。標準偏差0では`RACE_SCORE_Z`を保存しない。欠損・非正値を0、平均、最下位へ補完しない。

## 5. input_as_of

次の優先順位で決定する。

1. `races.sales_close_at`: `SALES_CLOSE`
2. `races.scheduled_start_at`: `START_TIME`
3. どちらもない: `INPUT_AS_OF_UNAVAILABLE`

3の場合、日時を捏造せず`input_as_of = NULL`、特徴量statusとqualityを`BLOCKED`にする。レース日23:59:59は使用しない。新しい日時列はPostgreSQL `timestamptz`である。

## 6. 入力種別と品質

過去出走表であることは`input_snapshot_type = HISTORICAL_RACE_CARD_BACKFILL`で表し、品質状態へ`HISTORICAL_SNAPSHOT`を入れない。

特徴量status:

```text
VALID / MISSING_INPUT / DEGRADED / CONFLICTED_INPUT /
INVALID_INPUT / LEAKAGE_RISK / BLOCKED / ERROR ...
```

データ品質:

```text
VALID / PARTIAL / DEGRADED / BLOCKED / LEAKAGE_RISK / ERROR
```

Fetch Log未特定、またはplayer未解決の場合は`DEGRADED`とする。一部得点欠損では有効選手を`PARTIAL`、欠損選手を`MISSING_INPUT`とする。PLAYER_PROFILEだけを過去レース得点へ使う入力は`LEAKAGE_RISK`である。

## 7. 冪等性と再計算

RACE_ENTRY scopeの論理一意キー:

```text
race_entry_id
stat_code
input_as_of
calculation_version
input_hash
```

通常再実行は既存snapshot・values・sourcesを再利用し、新しいrun関連だけを追加する。

`--recalculate`は同じ入力を再計算し、保存済みfeature code、型、値、分子・分母、sample、statusと比較する。一致すれば再利用し、不一致なら過去値や`calculated_at`を上書きせず整合性エラーにする。計算式変更時はcalculation versionを上げる。

## 8. 配点・confidence

特徴量基盤には`raw_points`、`confidence`、`effective_points`を置かない。配点、confidence、総合化は将来の`stat_score_snapshots`等で、特徴量生成とは別のバージョン・監査単位として実装する。

## 9. コマンド

```bash
php artisan db:seed --class=StatFeatureDefinitionSeeder

php artisan keirin:statistics:build-stat01 \
  --from=2024-01-01 \
  --to=2024-12-31 \
  --chunk=500

php artisan keirin:statistics:build-stat01 --race-id=12345
php artisan keirin:statistics:build-stat01 --race-id=12345 --dry-run
php artisan keirin:statistics:build-stat01 --race-id=12345 --recalculate
```

raceはIDベースでchunk処理し、`race_entries`はchunkごとにEager Loadする。dry-runではrun、出走snapshot、特徴量、source、run関連へ書き込まない。

## 10. 開発DBの旧派生結果

旧Migrationで生成した`statistic_entry_results`等は新スキーマと互換性がないが、元データではなく再生成可能な派生データである。

PRマージ前の開発環境を新定義へ合わせる際は、環境とバックアップを確認した管理者が次の順で実施する。

1. 必要なら旧統計テーブルとMigration履歴をバックアップする。
2. 旧`000004`だけが未マージMigration由来であり、後続Migrationや依存処理がないことを確認する。
3. 元の`races`、`race_entries`、`race_results`、`race_payouts`等を対象にしない方法で、旧`000004`の派生テーブルを安全に戻す。
4. 修正版`000004`を通常Migrationで適用する。
5. `StatFeatureDefinitionSeeder`を実行する。
6. 対象範囲のSTAT-01を再生成し、件数と品質状態を照合する。

`migrate:fresh`、`db:wipe`、元レースデータ削除は不要であり、使用しない。共有・本番相当環境では具体的なrollbackコマンドを事前確認なしに実行しない。

## 11. 今回の対象外

- STAT-02以降
- 外れ値判定
- 配点、confidence、総合点
- バックテスト、勝率・着順確率
- オッズ、買い目、資金配分
- UI、API
- スクレイピング変更
