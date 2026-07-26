# 統計エンジン共通基盤・STAT-01 実装仕様

## 1. 対象範囲

この製造単位は、統計特徴量の実行・時点値・出典を監査する共通基盤と、STAT-01「競走得点による基礎実力評価」を実装する。

STAT-01の入力は、対象レースのPJ0315出走表から`race_entries.race_score`へ保存された値だけである。競走得点の観測日時には`race_score_fetched_at`だけを使い、JSJ017でも更新される汎用`fetched_at`は使わない。`players`の現在値、`player_stat_snapshots`、`race_results`、`race_payouts`は参照しない。

## 2. ER関係

```text
statistic_calculation_runs
  --< statistic_run_feature_snapshots >-- stat_feature_snapshots
  --< statistic_run_feature_snapshot_occurrences
                    |                         |
                    |                         `-- race_entry_snapshot_occurrences
                    |                                      |
                    `-------------------------- stat_feature_snapshots
                                              |--< stat_feature_values
                                              `--< stat_feature_sources
                                                        |
race_entries --< race_entry_snapshots --< race_entry_snapshot_sources
       |               |                `-- race_entry_snapshot_source_heads
       |               `--< race_entry_snapshot_occurrences
       |                                          |
       `------------------------------------------+-- scraping_fetch_logs

stat_feature_definitions
  (stat_code + feature_code + definition_version)
```

計算実行IDは特徴量本体へ保存しない。実行と再利用可能な特徴量snapshotは`statistic_run_feature_snapshots`で関連付け、各実行が実際に使用した時間上の入力は`statistic_run_feature_snapshot_occurrences`で関連付ける。

## 3. テーブル構成

### statistic_calculation_runs

STATコード、計算バージョン、対象期間・レース、実行パラメータ、状態、開始・終了日時、対象・品質・エラー件数を保持する。

### race_entries

`fetched_at`はJSJ017/PJ0315を含む出走行全体の最終取得日時、`race_score_fetched_at`はPJ0315競走得点の専用観測日時である。PJ0315で初めて観測した場合、または得点が数値的に変わった場合だけ専用日時を更新する。`100.0`と`100.00`のようなdecimal表現差は変更とみなさない。NULLと値ありの遷移は変更として扱う。

既存行の`fetched_at`は後続JSJ017で更新された可能性があるため、Migrationで`race_score_fetched_at`へコピーしない。専用日時がNULLの既存得点は`UNKNOWN_SOURCE_TIMING`となり、STAT-01で`VALID`にしない。

`race_entries.id`は同一レース・同一車番の出走スロットとして再利用する。選手同一性は`player_id`だけでなく`external_player_id`でも判定し、JSJ017で外部選手IDが変わった場合は、旧選手のPJ0315由来の`frame_number`、`grade`、`race_score`、`race_score_fetched_at`をクリアする。同じ外部選手IDがソフトデリート後に再出現した場合は、同じIDとPJ0315情報を維持して復元する。

`race_entries`はsoft deleteする。JSJ017から消えた車番は`deleted_at`を設定し、統計監査FKが参照する行を物理削除しない。同じ`race_id + bike_number`が再出現した場合はtrashed行をロックして更新・restoreし、同じ`race_entries.id`を再利用する。通常のレース入力、結果完全性検証、STAT-01対象にはactive行だけを使う。

### race_entry_snapshots

`race_entries`の時点値を保持する。競走得点は元表現を`race_score_raw_text`、検証済み値を`race_score`へ分離する。

- 数値かつ0より大きい: `VALID`
- `NULL`または空: `MISSING`
- 数値形式でない: `INVALID_FORMAT`
- 0以下: `NON_POSITIVE`
- `numeric(12,4)`へ保存できない: `OUT_OF_STORAGE_RANGE`
- 情報源間競合: `SOURCE_CONFLICT`

`0.00`はraw textを保持し、数値列は`NULL`とする。ドメイン上の固定上限は設けず、DB保存範囲だけを検査する。外れ値判定は今回未実装のため`NOT_CHECKED`である。

同一`race_entry_id + snapshot_hash`は入力内容として再利用する。snapshot本体は期間を表さず、`is_current`、`effective_from`、`effective_to`を持たない。同じ内容の再観測では内容行を増やさず、`last_observed_at`だけを最新の`race_score_fetched_at`へ進める。専用日時不明時の`first_observed_at`と`last_observed_at`はNULLであり、汎用`fetched_at`で補完しない。

`input_snapshot_type`はcontentではなくsourceと入力基準時刻から計算する分類なので、`race_entry_snapshots`へ保存せず、snapshot hashにも含めない。分類はDTO、STAT-01入力、`stat_feature_snapshots`へ保存する。

時刻は用途ごとに分離する。`scoreObservedAt = race_score_fetched_at`は競走得点の`first_observed_at`、`last_observed_at`、入力時点判定、source取得時点監査に使う。`stateObservedAt = fetched_at`は出走行の属性変更を観測した時刻として、occurrenceの開始と変更前current occurrenceの終了に使う。`stateObservedAt`はsnapshot hashへ含めないため、同一内容のJSJ017再取得では内容行・occurrenceを増やさず、期間も変更しない。

状態変更時刻がcurrent snapshotの得点観測時刻や既存の終了済みoccurrenceより古い場合、日時を補正せず整合性例外にする。current occurrenceと監査期間はtransaction rollbackで維持する。

snapshotは作成時点の`external_player_id`を保持し、`snapshot_hash`の構成要素にも含める。これにより、`player_id`が未解決のまま同じ車番・同じ得点を持つ別選手へ交代しても、異なる入力として監査できる。既存snapshotへ現在の`race_entries.external_player_id`をバックフィルしない。

### race_entry_snapshot_occurrences

snapshot内容が有効だった連続期間を表す。`race_id`、`race_entry_id`、内容を指す`race_entry_snapshot_id`、`effective_from`、`effective_to`、`is_current`、`state_observed_at`を保持する。source state IDは保持しない。source-only変更ではoccurrence行と期間を一切更新せず、run監査だけが今回使用したsource stateを関連付ける。

状態AからBへ変わった後にAへ戻った場合、Aのsnapshot内容行は再利用するが、Aの2回目のoccurrenceは必ず新規作成する。最初のA occurrenceの`effective_to`は消去せず、終了済みoccurrenceを再current化しない。

`race_entry_snapshot_occurrences_current_unique`は`is_current = true`の行だけを対象とする部分UNIQUE INDEXであり、1つの`race_entry_id`にcurrent occurrenceが最大1件であることをDBでも保証する。PostgreSQL CHECK制約により、currentは`effective_to = NULL`、終了済みは`effective_to IS NOT NULL`、`effective_to >= effective_from`、`state_observed_at = effective_from`を保証する。同じsnapshotを参照する終了済みoccurrenceは複数保持できる。

永続化はtransaction内で行い、`race_entries`の対象行をID順に`lockForUpdate`してからcurrent occurrenceもロックする。旧current occurrenceの終了、snapshot内容の作成または既存hashの再利用、新occurrenceの作成を同じtransactionで完了する。並列実行は`race_entry_id`単位で直列化され、サービス外からの競合も部分UNIQUE INDEXが拒否する。

### race_entry_snapshot_sources

出走snapshotへ寄与したページとFetch Logを保持する。`scraping_fetch_log_id`は出典参照であり、Fetch Log行の現在値をfingerprint再現に使わない。作成時点の`source_fetched_at`、`parser_version`、`source_url`、`raw_file_path`、`raw_sha256`をsource stateへ複製し、append-onlyな固定証跡として保持する。Fetch LogのFKは`RESTRICT ON DELETE`を維持する。

既存`race_entries`からの移行元は次の値を使う。

- `snapshot_type`: `LEGACY_BACKFILL`
- `source_page_type`: `RACE_DETAIL`
- `context_verification_status`: `VERIFIED_LEGACY_RECONCILED`
- `historical_backfill_scope`: `STATIC_RACE_CARD_FIELDS_ONLY`
- `eligible_fields`: `race_score`

`snapshot_type`は「既存race entryから復元した」というsource originであり、発走前後を表さない。`input_snapshot_type`は後述の`input_as_of`、観測日時、source監査情報から別に決定する。

既存行からFetch Logを一意に決定できないため、`scraping_fetch_log_id`は推測せず`NULL`とする。`context_evidence.source_link_status`へ`SOURCE_LINK_MISSING`を保存する。

current occurrenceが参照するsnapshotの`external_player_id`と現在の出走行が一致する場合だけ、source headが示す入力sourceを再利用する。異なる選手へ変更された場合は、旧選手のFetch Log、parser version、Raw path、SHA-256を新選手へ継承せず、新しい汎用sourceの`context_evidence`へ`race_id`、`race_entry_id`、`external_player_id`を記録する。

source状態はrace card値のsnapshot hashへ混在させず、決定的な`source fingerprint`としてSTAT-01入力ハッシュへ渡す。fingerprintは次を固定キー順のJSONにしてSHA-256化する。

- source role、Fetch Log ID、page type、race context key
- context match method、context verification status、historical backfill scope
- contributed fields、eligible fields
- Fetch Log IDから導出したsource link missing
- source stateへ固定保存したRaw SHA-256、parser version、URL、Raw path、UTC正規化した取得日時

field配列は文字列だけに絞り、重複を除去して辞書順にソートする。`raceScoreEligible`、`input_snapshot_type`、`input_as_of`、`source_reference_at`、`context_verified_at`のような計算時または可変の値と、snapshot hash由来で循環する`source_identity_key`は含めない。`sourceLinkMissing`は最終templateのFetch Log IDがNULLかどうかだけから導出する。source stateの一意キーは`snapshot + source role + source fingerprint`、identity keyは`race-entry-source:{snapshot_id}:{fingerprint}`である。

source stateはappend-onlyであり、page type、context、eligibility、Fetch Log、固定証跡、fingerprint、identity keyを更新しない。sourceが変化した場合は`RaceEntrySnapshotSourceFactory`が共通fingerprint実装を使って一致stateを再利用するか、新stateを追加する。過去stateを削除・上書きしない。現在選択中のsourceだけは`race_entry_snapshot_source_heads`に分離し、同じfingerprintへ戻った場合もsource state本体を複製せずheadだけを切り替える。Fetch Log行が後から更新されても、source state、DTO、STAT入力、feature sourceは複製済み固定証跡を使用するため変化しない。

### stat_feature_snapshots

統計評価単位のヘッダーである。`scope_type`は`RACE`、`RACE_ENTRY`、`PLAYER_PAIR`を持ち、scopeごとのFK組合せをPostgreSQL CHECK制約で検証する。STAT-01は`RACE_ENTRY`を使う。

主な監査項目:

- `input_as_of`と決定policy
- `input_snapshot_type`
- レース全体の入力ハッシュ
- 計算バージョン
- 特徴量状態とデータ品質
- sample count、coverage rate、最大取得日時

レース全体の入力JSONは保持せず、順序正規化した各entryの`race_entry_snapshots.snapshot_hash`、`source fingerprint`、`input_snapshot_type`、`sourceLinkMissing`、`raceScoreEligible`からSHA-256を作る。後ろ3項目は計算時コンテキストであり、source evidenceが同じでも分類が変われば入力hashを分離する。

### stat_feature_values

特徴量をfeature code単位で縦持ちする。`INTEGER`、`NUMERIC`、`TEXT`、`BOOLEAN`、`JSON`に対応する値列のうち、value typeに対応する1列だけを非NULLにする。NaNと正負InfinityはアプリケーションとPostgreSQL CHECKの両方で拒否する。

窓なしは`snapshot + feature_code`、窓ありは`snapshot + feature_code + window_type + window_value`で一意にする。window type/valueは両方NULLまたは両方非NULLである。

### stat_feature_sources

特徴量snapshotから入力元を追跡する。対象選手自身は`PRIMARY_INPUT`、レース内比較に使った他選手は`CONTEXT_INPUT`とする。各行から`race_entry_snapshot_id`と`race_entry_snapshot_source_id`、必要なら`scraping_fetch_log_id`、固定済みURL、Raw path、SHA-256、parser versionまで追跡できる。PostgreSQL CHECKによりsnapshot/source IDは両方NULLまたは両方非NULLで、`RACE_ENTRY_SNAPSHOT`では両方必須である。

レガシー移行でFetch Logがない場合は`source_timing_status = SOURCE_LINK_MISSING`とし、Raw情報を捏造しない。

`source_reference_at`はsource evidenceではなく計算時コンテキストであるため、`race_entry_snapshot_sources`には置かない。各feature snapshotが使用した`input_as_of`を`stat_feature_sources.source_reference_at`へ保存し、同じsource stateを異なる基準時刻で再利用しても過去の参照時刻を上書きしない。`raceScoreEligible`もsource state属性ではなく、入力種別、page type、eligible fields、backfill scope、context verificationから計算時に導出する。

### statistic_run_feature_snapshot_occurrences

各STAT run、再利用可能なfeature snapshot、実際に入力したrace entry occurrence、content snapshot、source stateを関連付ける。対象entryは`PRIMARY_INPUT`、同一レース内の比較入力は`CONTEXT_INPUT`として全件保存する。

特徴量本体と値が同じためfeature snapshotを再利用する場合でも、このrun別関連は毎回作成する。A→B→Aではrun1、run2、run3がそれぞれA1/S1、B1/S2、A2/S3を参照する。同じoccurrence中にsourceだけが変化した場合も、runごとに異なるsource stateを追跡できる。

複合FKによりfeature、occurrence、source stateのrace/content/entry ID混在を拒否する。`PRIMARY_INPUT`はfeature entryとsource entryの一致、`CONTEXT_INPUT`は同一race内の異なるentryであることをCHECKと複合FKで保証する。

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

`StatInputAsOfResolver`が次の優先順位で一度だけ決定する。

1. `races.sales_close_at`: `SALES_CLOSE`
2. `races.scheduled_start_at`: `START_TIME`
3. どちらもない: `INPUT_AS_OF_UNAVAILABLE`

3の場合、日時を捏造せず`input_as_of = NULL`、特徴量statusとqualityを`BLOCKED`にする。レース日23:59:59は使用しない。同じ`StatInputAsOfDto`をrace entry snapshot生成、STAT-01計算、feature snapshot保存、source timing判定へ渡す。新しい日時列はPostgreSQL `timestamptz`である。

## 6. 入力種別と品質

`input_snapshot_type`は次の順で決定する。

1. `source_page_type = PLAYER_PROFILE`: `CURRENT_PLAYER_PROFILE`
2. `input_as_of = NULL`: `UNKNOWN_SOURCE_TIMING`
3. `race_score_fetched_at <= input_as_of`: `LIVE_PRE_RACE_CARD`
4. `race_score_fetched_at > input_as_of`で、レース固有ページ、context検証、backfill scope、`race_score`対象項目をすべて確認できる: `HISTORICAL_RACE_CARD_BACKFILL`
5. それ以外: `UNKNOWN_SOURCE_TIMING`

`race_score_fetched_at`がNULLの場合も`UNKNOWN_SOURCE_TIMING`である。`CURRENT_PLAYER_PROFILE`と安全性を確認できない`UNKNOWN_SOURCE_TIMING`は`raceScoreEligible = false`である。前者はSTAT-01で`LEAKAGE_RISK`、as-of不明の後者は`BLOCKED`になる。後日取得した検証済みレース固有出走表はhistorical backfillとして利用できる。

`snapshot_hash`には`input_snapshot_type`、source fingerprint、Fetch Log、input-as-of、観測時刻を含めない。source分類だけが変わった場合はcontent snapshotとoccurrenceを増やさず、source state、STAT入力hash、feature監査だけを更新する。`snapshot_type`は引き続きsource originを表し、`input_snapshot_type`とは混同しない。

過去出走表であることは`input_snapshot_type`で表し、品質状態へ`HISTORICAL_SNAPSHOT`を入れない。

特徴量status:

```text
VALID / MISSING_INPUT / DEGRADED / CONFLICTED_INPUT /
INVALID_INPUT / LEAKAGE_RISK / BLOCKED / ERROR ...
```

データ品質:

```text
VALID / PARTIAL / DEGRADED / BLOCKED / LEAKAGE_RISK / ERROR
```

Fetch Log未特定、またはplayer未解決の場合は`DEGRADED`とする。`LIVE_PRE_RACE_CARD`でも`SOURCE_LINK_MISSING`なら`VALID`へ昇格しない。一部得点欠損では有効選手を`PARTIAL`、欠損選手を`MISSING_INPUT`とする。PLAYER_PROFILEだけを過去レース得点へ使う入力は`LEAKAGE_RISK`である。入力の時間分類と情報源追跡品質は別の列で監査する。

## 7. 冪等性と再計算

RACE_ENTRY scopeの論理一意キー:

```text
race_entry_id
stat_code
input_as_of
calculation_version
input_hash
```

通常再実行は既存snapshot・values・sourcesを再利用し、新しいrun関連だけを追加する。JSJ017だけを再同期して汎用`fetched_at`が進んでも、競走得点専用日時、入力種別、snapshot hash、feature snapshotは変化しない。

同じrace card値でも、Fetch Log参照の消失やsource eligibilityの変更によって計算品質が変わる場合は`source fingerprint`と`input_hash`が変わる。既存feature snapshotは監査履歴として残し、新しいstatusとdata qualityを持つfeature snapshotを作成する。

同一current状態での再実行はsnapshot内容行とoccurrenceを増やさず、`effective_from`と`effective_to`も変更しない。一方、過去と同じ内容が時間を空けて再出現した場合はsnapshot内容だけを再利用し、新しいoccurrenceを作る。どちらの場合もrun-occurrence関連は新しいrunごとに保存する。

`--recalculate`は同じ入力を再計算し、保存済みfeature code、型、値、分子・分母、sample、statusと比較する。一致すれば再利用し、不一致なら過去値や`calculated_at`を上書きせず整合性エラーにする。計算式変更時はcalculation versionを上げる。

## 8. Migrationライフサイクル

`2026_07_25_000004_create_statistic_calculation_tables`はsnapshot内容、snapshot occurrence、run-occurrence監査を作成し、`first_observed_at`と`last_observed_at`をNOT NULLで定義する。`2026_07_26_000005_add_race_entry_audit_lifecycle_fields`だけが、専用得点時刻、soft delete、snapshot選手同一性を追加し、得点観測時刻をnullableへ変更する。したがって000005の安全なrollback後は000004時点のNOT NULL定義へ戻る。

000005のrollbackはDDL前に全保護件数を検査する。`race_score_fetched_at`あり、`deleted_at`あり、snapshotの`external_player_id`あり、または`first_observed_at`/`last_observed_at`がNULLの行が1件でもあれば、失われる監査項目と件数を示す`RuntimeException`で拒否する。列削除やNULL制約変更を一部だけ実行した状態にはしない。

## 9. 配点・confidence

特徴量基盤には`raw_points`、`confidence`、`effective_points`を置かない。配点、confidence、総合化は将来の`stat_score_snapshots`等で、特徴量生成とは別のバージョン・監査単位として実装する。

## 10. コマンド

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

## 11. 開発DBの旧派生結果

旧Migrationで生成した`statistic_entry_results`等は新スキーマと互換性がないが、元データではなく再生成可能な派生データである。

PRマージ前の開発環境を新定義へ合わせる際は、環境とバックアップを確認した管理者が次の順で実施する。

1. 必要なら旧統計テーブルとMigration履歴をバックアップする。
2. 旧`000004`だけが未マージMigration由来であり、後続Migrationや依存処理がないことを確認する。
3. 元の`races`、`race_entries`、`race_results`、`race_payouts`等を対象にしない方法で、旧`000004`の派生テーブルを安全に戻す。
4. 修正版`000004`を通常Migrationで適用する。
5. `StatFeatureDefinitionSeeder`を実行する。
6. 対象範囲のSTAT-01を再生成し、件数と品質状態を照合する。

`migrate:fresh`、`db:wipe`、元レースデータ削除は不要であり、使用しない。共有・本番相当環境では具体的なrollbackコマンドを事前確認なしに実行しない。

## 12. 今回の対象外

- STAT-02以降
- 外れ値判定
- 配点、confidence、総合点
- バックテスト、勝率・着順確率
- オッズ、買い目、資金配分
- UI、API
- スクレイピング変更

## 13. PostgreSQL 18 Migration検証

開発DBとは別の`/tmp`配下一時クラスタをPostgreSQL 18.4で初期化し、専用ポート・空DBへ`php artisan migrate --force`を適用した。開発DBへのMigration、rollback、データコピーは実行していない。

`StatisticFeaturePostgreSqlMigrationTest`はPostgreSQL以外ではskipし、PostgreSQLでは次を実DBで検証する。

- `pg_indexes`: 6つの部分UNIQUE INDEXの定義
- `pg_index`: `indisunique`、`indisvalid`、`indpred`、3つのscope indexの`indnullsnotdistinct`
- source state: 固定Fetch Log証跡列の型、`source_reference_at`の不在、Fetch Log削除の`RESTRICT`
- `pg_constraint`: source IDのNULL整合性、PRIMARY/CONTEXTを含む指定CHECK制約の存在と`convalidated`
- 外部キー: source table/column、参照先、`CASCADE`、`RESTRICT`、`SET NULL`の削除規則
- 実INSERT: current重複、NULL as-of論理キー重複、value type不整合、window片側NULL、NaN、正負Infinity、不正scope/status/source roleの拒否
- 実INSERT: 非current occurrence複数、同じsnapshot内容の複数期間、異なるinput hashの許可
- occurrence期間: current/終了済み状態、期間逆転、観測時刻と開始時刻の一致
- run監査: occurrenceとsource stateをrun別に保持し、別race、snapshot、entryの混在を複合FKで拒否
- source-only変更: occurrenceを維持したまま別source stateを正常に関連付け
- `stat_feature_sources`: snapshot/source IDの片側NULLを拒否

PostgreSQL 18で`2026_07_26_000005_add_race_entry_audit_lifecycle_fields`を含むMigrationは成功し、専用テストは13 tests / 266 assertionsで成功した。`race_entries.race_score_fetched_at`、`race_entries.deleted_at`、snapshotの`first_observed_at`、`last_observed_at`がnullable `timestamp with time zone`であること、`race_entry_snapshots.external_player_id`が`race_entries.external_player_id`と同じ型・長さでnullableであることをcatalogで検証する。source stateの固定Fetch Log証跡列と`source_reference_at`の不在、Fetch Log FKの`RESTRICT`、occurrenceのcurrent部分UNIQUE INDEX、期間・状態CHECK、run-occurrence/source関連の複合FKも実DBで検証する。実DDLでは、000005と000004をrollback可能条件でrollback後に再適用できること、専用得点時刻、soft delete、snapshot選手同一性、NULL観測日時がある場合は000005 rollbackを全DDL前に拒否してデータと列を維持することを確認した。SQLite通常テストはアプリケーション動作とSQLite互換の部分indexを高速に確認するが、`NULLS NOT DISTINCT`、PostgreSQL CHECK、catalog、NaN・Infinity制約の代替にはしない。
