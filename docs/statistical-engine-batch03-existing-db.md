# 統計エンジン集計バッチ03: 競走条件・段階・開催内遷移

## 目的と責務

Batch03は完了済みSTAT-01 runをtarget snapshotとし、既存DBだけから次の特徴量を生成する。

| STAT | 責務 |
|---|---|
| STAT-07 | 同一競輪場と全場baselineの観測成績差 |
| STAT-08 | Asia/Tokyoの実発走時刻と同一hourの観測成績差 |
| STAT-23 | 過去開催における同一day numberの観測成績差 |
| STAT-31 | 取得済み履歴内の準決勝・決勝・raw meeting grade経験 |
| STAT-32 | 正規化stageが同じ履歴と全stage baselineの観測成績差 |
| STAT-33 | 同一開催内の前走と、過去開催で観測された隣接stage遷移 |

6 STATは独立したfeature runを作成し、`parameters.batch_execution_uuid`を共有する。`raw_points`、`confidence`、`effective_points`は常にNULLであり、最低標本数、平滑化、時間減衰、grade係数、配点は定義しない。

## 使用sourceと時点条件

`races`、`race_entries`、`race_results`、`race_days`、`race_meetings`、STAT-01 resultをREAD ONLYで使用する。履歴は男子A/S級、結果CONFIRMED/CORRECTED、`scheduled_start_at < target.input_as_of`に限定し、target自身を除外する。player照合は`player_id`だけを使用する。

履歴結果は後日取得された最終結果を含むため、evidenceへ`history_result_mode=BACKFILLED_FINAL_RESULT`を保存する。現在の選手値を過去へ適用しない。

## Stage normalizer

versionは`RACE-STAGE-existing-db-v1`。2023〜2024年実DBの`race_type`を棚卸しし、決勝、準決、一予、二予、予選、一般、特選、選抜、順位決等の明示語だけを正規化する。

- 入力欠損・空文字は`UNKNOWN`。
- 値はあるが明示語へ対応しない特殊名称は`OTHER`。
- 実DBの`races.name`は対象51,185件すべて`先固`でstage名称ではないため、raw監査値としてのみ保持する。
- `OTHER`を決勝等へ推測変換しない。

## STAT別MVP

STAT-07はsame trackとall trackについてacquiredおよび365/730/1095/1825日windowを保存する。window不足は個別の`window_complete=false`で示し、固定標本閾値にはしない。改修・layout履歴は未実装である。

STAT-08はscheduled startをAsia/Tokyoへ変換し、minute/hour、minute within hour、24時間周期sin/cosを保存する。モーニング、デイ、ナイター、ミッドナイト境界は定義しない。実DBの`day_kind`は全件`1`で意味を確定できないためraw値だけを保存し、`OFFICIAL_SESSION_CATEGORY_UNAVAILABLE`を記録する。

STAT-23はtarget meetingと異なる過去meetingだけをprofileに使い、同一day number、全day number、過去final dayを集計する。stage適性と同一開催内遷移は混ぜない。

STAT-31は`ACQUIRED_HISTORY`として準決勝・決勝の観測件数と成績、raw meeting grade分布、A/S級分布、race score contextを保存する。完全career、制度別grade順位、決勝進出機会分母は実装しない。高位stage 0件は意味のある観測0であり、能力不足へ変換しない。

STAT-32はtarget stageと同じ正常完走履歴をall stage baselineと比較する。current `UNKNOWN`は`MISSING_INPUT`、`OTHER`は計算可能だがquality reasonを残す。開催day効果は含めない。

STAT-33はtargetより前の同一meeting最新実出走を前走とする。開催初戦は`NOT_APPLICABLE`、異常前走は`PARTIAL`。過去meeting内の隣接実出走がともに正常完走の場合だけ、previous stageからcurrent stageと一致する観測遷移を集計する。rank changeは`previous rank - next rank`で、正値が改善を表す。

勝ち上がり成功、通過圏、ポイント、tie-break、補充分類、選手の意図は推定しない。`ADVANCEMENT_RULE`、`QUALIFICATION_CUTOFF`、`POINTS_RULE`、`TIEBREAK_RULE`、`SUPPLEMENTAL_ENTRY_CLASSIFICATION`をunavailable componentsへ保存する。

## Hashとメモリ

`target_context_hash`はtarget race/entry/player/bike、input as of、scheduled start、track、meeting/day、duration、meeting grade/day kind、raw type/name、normalized stage、normalizer version、STAT-01 input hashを含む。

`history_input_hash`は履歴を`scheduled_start_at, race_id, race_entry_id`順に正規化し、開催属性、raw/normalized stage、結果、得点、着順強度、期待残差、score context hash、取得時刻、normalizer versionを含む。各resultの`input_hash`はSTAT code/version、target hash、history from、history hashから決定する。

`--chunk`はtarget race IDの外側keyset page sizeである。履歴はBatch02で2024年実DB・128MB受入済みの設計を踏襲し、5 target raceのworking batch、250 history rowのkeyset、race単位cursorで処理する。target entry単位のhistory SQLは発行しない。

## 実行と2024受入

```bash
php -d memory_limit=128M artisan keirin:statistics:build-batch03 \
  --stat01-run-id=1 \
  --history-from=2023-01-01 \
  --from=2024-01-01 \
  --to=2024-12-31 \
  --chunk=200
```

本実行前は1日単位の`--dry-run`で確認し、`docs/sql/validate_batch03_2024.sql`を使用する。Migration適用と2024年全件実行はレビュー後に行う。

## 未実装高度版

競輪場改修・layout version、公式時間帯category、競走制度時点マスタ、完全career、高位競走weight、正確な進出機会、公式勝ち上がり・通過圏・ポイント・tie-break、補充出走分類、最低標本数、平滑化、時間減衰、配点、confidenceは対象外である。スクレイピング、Parser、Fetcher、source schemaは変更しない。
