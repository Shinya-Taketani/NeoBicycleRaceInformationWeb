# 統計エンジン集計バッチ02: 選手履歴・直近状態

## 目的と入力

Batch02は既存DBだけを読み取り、次の選手履歴特徴量を生成する。

| STAT | 責務 |
|---|---|
| STAT-10 | 正常完走時の直近成績水準、期待残差、短中期差、streak |
| STAT-11 | 異常結果の件数・率・直近性 |
| STAT-12 | 前走間隔と過去の出走間隔分布 |
| STAT-24 | 正常完走時の変動性、残差変動、top3切替 |
| STAT-26 | 短期の日程密度、開催数、活動日、競輪場変更 |

対象race entryと`input_as_of`は、完了済みの指定STAT-01 runに保存された`RACE_ENTRY` resultを正本とする。STAT-01が`INVALID_INPUT`でも除外せず、`player_id`がない場合だけ各STATを`MISSING_INPUT`とする。`players`の現在級班・現在能力は過去へ遡及適用しない。

履歴は明示された`history_from`以上かつ`historical_race.scheduled_start_at < target.input_as_of`で、対象race自身を除く。男子の`Ａ級%`・`Ｓ級%`かつ結果状態`CONFIRMED`・`CORRECTED`だけを使用し、race entryとresultは`race_id + bike_number`で結合する。

## 履歴区分

- `PRE_MEETING`: targetと異なる`race_meeting_id`。STAT-10/24の主特徴量に使う。
- `IN_MEETING`: 同一開催かつtargetより前。同開催内の補助特徴量として別保存する。
- target meetingが不明なら取得可能な履歴をPRE扱いにし、`MEETING_CONTEXT_MISSING`で`DEGRADED`とする。
- IN_MEETINGがある場合、当時の結果確定時刻を完全再構成できないため`IN_MEETING_RESULT_CONFIRMATION_NOT_RECONSTRUCTED`を残す。

履歴結果は後日取得された確定結果を含むため、evidenceの`history_result_mode`は常に`BACKFILLED_FINAL_RESULT`である。これは予測時点に結果が既知だったことを保証しない。

## 結果状態

| DB状態 | 内部状態 | started denominator |
|---|---|---|
| FINISHED | NORMAL_FINISH | 含む |
| TIED | NORMAL_FINISH (`tied=true`) | 含む |
| DISQUALIFIED | DISQUALIFIED | 含む |
| CRASHED | FALL_DNF | 含む |
| DID_NOT_FINISH | OTHER_DNF | 含む |
| DID_NOT_START | DID_NOT_START | 除外 |
| WITHDRAWN | WITHDRAWN | 除外 |
| 未知状態 | UNKNOWN_ABNORMAL | 含む |

STAT-10/24の着順・変動集計は`NORMAL_FINISH`だけを使う。STAT-11/12/26のstarted raceは上表に従い、DID_NOT_STARTを実出走扱いしない。

## 共通計算

正常着順強度は`(entrant_count - rank) / (entrant_count - 1)`で、1着を1.0、最下位を0.0とする。異常結果と`entrant_count <= 1`はNULL。

過去レースの競走得点期待残差は、当該過去raceの全`race_entries.race_score > 0`が揃う場合だけ計算する。

```text
historical_score_percentile = (valid_count - score_rank) / (valid_count - 1)
score_expectation_residual = finish_strength_percentile - historical_score_percentile
```

他選手を含む`race_entry_id, player_id, bike_number, grade, race_score`をID順に正規化し、`historical_score_context_hash`へ含める。一人でもNULL/0以下なら残差はNULL。これは確率モデルではない。

`history_input_hash`は履歴を`scheduled_start_at, race_id, race_entry_id`順に正規化し、結果状態、着順、得点、残差、context hash、entry/result取得時刻を含む。各resultの`input_hash`はSTAT code/version、STAT-01 input hash、history_from、history_input_hashから決定する。

## Feature JSON

走数窓はSTAT-10が3/5/10/20、STAT-11が3/5/10、STAT-24が3/5/10/20/30。日数窓はSTAT-10が30/60/90/180/365日、STAT-11が30/90/180日、STAT-24が60/90/180/365日、STAT-26が7/14/21/30/45/60日である。

各窓には`sample_count`, `history_start_at`, `history_end_at`, `window_complete`を保存する。走数不足、または日数窓開始がhistory_fromより前なら不完全。算出可能な値は保存するが、欠損を0で埋めない。

- STAT-10: mean rank/finish percentile、win/top2/top3件数・率、残差件数・平均、3-10/5-20差、top3/outside streak、IN_MEETING水準。
- STAT-11: normal/abnormal/disqualified/fall/other/unknown件数・率、DID_NOT_START件数、ACQUIRED_HISTORY、最終異常時刻、経過日数、以後のstarted数、異常streak。
- STAT-12: 直近started/normal/abnormal、PRE/IN直近、hours/days gap、PRE started間隔のmean/median/Q25/Q75、現在差、経験percentile。
- STAT-24: 母標準偏差、MAD、IQR、残差変動、upside/downside件数・率・平均、top3 switch、IN_MEETING変動。
- STAT-26: started数、distinct meeting、active/inactive calendar days、active day当たり出走、track change、不明track比較、最大連続active days。

quantileは昇順で`position=(n-1)*p`とし、floor/ceil間を線形補間する。標本1件の母標準偏差は0.0、0件はNULL。率は分母0ならNULL、分母ありの観測0件は0.0。

## Statusと監査

- `MISSING_INPUT`: player_idなし。
- `NO_HISTORY`: target以前の取得履歴なし。
- `PARTIAL_HISTORY`: 履歴はあるが要求窓または必須分布が不完全。
- `VALID`: 必須入力と全候補窓が完全。

5 STATは1 commandで別々のrunを作り、`parameters.batch_execution_uuid`を共有する。1 STAT x 1 raceを独立処理し、他STAT・他raceへ失敗を波及させない。resultは1 target race entryにつき1行で、`raw_points`, `confidence`, `effective_points`は常にNULL。

## 実行

```bash
php artisan keirin:statistics:build-batch02 \
  --stat01-run-id=1 \
  --history-from=2023-01-01 \
  --from=2024-01-01 \
  --to=2024-12-31 \
  --chunk=200
```

書込みなしの単一race確認:

```bash
php artisan keirin:statistics:build-batch02 \
  --stat01-run-id=1 \
  --history-from=2023-01-01 \
  --race-id=<race_id> \
  --dry-run
```

検証は`docs/sql/validate_batch02_2024.sql`を使用する。

## 未実装・制約

長期休養/短期過密の分類閾値、最低標本数、時間減衰、配点、confidence、医学的疲労、事故原因、移動距離、所属地距離、ライン役割負荷、気象、オッズ、予測確率は実装しない。STAT-26 evidenceの`unavailable_components`で`TRAVEL_DISTANCE`と`ROLE_LOAD`を明示する。スクレイピング、Parser、Fetcher、Raw保存、既存source schemaは変更しない。
