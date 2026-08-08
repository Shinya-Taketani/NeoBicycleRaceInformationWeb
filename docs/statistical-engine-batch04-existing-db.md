# 統計エンジン集計バッチ04: 車番・枠番と直接対戦

## 目的と責務

Batch04は完了済みSTAT-01 runをtarget snapshotとし、既存DBだけから次のMVP特徴量を生成する。

| STAT | calculation version | 責務 |
|---|---|---|
| STAT-39 | `STAT-39-existing-db-v1` | 車立て・競輪場・競走得点期待残差を分けた車番・枠番と結果の観測上の関連 |
| STAT-42 | `STAT-42-existing-db-v1` | 現在の全co-entrantとの過去直接対戦を方向付きpair metricsとして保存 |

2 STATは独立したfeature runを作成し、`parameters.batch_execution_uuid`を共有する。1 target race entryにつき各STAT 1 resultであり、STAT-42も相手ごとのDB行を作らない。`raw_points`、`confidence`、`effective_points`、`POSITION_BIAS_SCORE`、`MATCHUP_ADJUSTMENT`は常にNULLである。

## 入力と時点条件

targetはSTAT-01 resultの`race_id`、`race_entry_id`、`player_id`、`bike_number`、`input_as_of`、`input_hash`、競走得点特徴量を使用する。枠番、車立て、競輪場、発走時刻はsource tableをREAD ONLYで参照する。競走得点はsourceの現在値ではなくSTAT-01 snapshotを優先する。

Batch04ではSTAT-01 resultの`input_as_of`をprediction cutoffとして必須にする。選択targetにNULLが1件でもあれば、全体をfeature run開始前に拒否する。NULL targetはskipせず、`scheduled_start_at`や現在時刻へfallbackしない。STAT-39の累積履歴とSTAT-42の直接対戦履歴に曖昧なcutoffを持ち込まないためである。

履歴は男子A/S級、結果CONFIRMED/CORRECTED、`historical scheduled_start_at < target.input_as_of`に限定し、target race自身を除外する。結果状態には`HistoricalResultStateNormalizer`を使用する。NORMAL_FINISHだけを通常着順成績へ使い、異常完走を最下位へ変換しない。DID_NOT_STARTとWITHDRAWNは直接対戦分母から除外する。

履歴結果には後日取得した最終結果を含むため、`history_result_mode=BACKFILLED_FINAL_RESULT`を保存する。`races.result_confirmed_at`はアプリが確定状態を初めて観測した時刻であり、公式確定時刻ではない。その値がtarget input as ofより後であることだけを理由に履歴を除外しない。

## STAT-39

### Target context

`TARGET_POSITION_CONTEXT`には車番、枠番、宣言車立て、実entry数、競輪場、実際の参加車番集合を保存する。相対順は参加車番を昇順にした`participating_bike_order_index`と`participating_bike_order_percentile`である。非連続車番を許容し、`1..entrant_count`を仮定しない。この値は公式な初手位置、ライン位置、物理的な内外位置ではない。INNER/MIDDLE/OUTERの閾値も定義しない。

### Population scopes

- `FIELD_BIKE`: 同じ車立て・車番、全競輪場
- `FIELD_BASELINE`: 同じ車立て、全車番・全競輪場
- `FIELD_BIKE_DELTA`: FIELD_BIKEからFIELD_BASELINEを引いた率・平均値
- `TRACK_FIELD_BIKE`: 同じ競輪場・車立て・車番
- `TRACK_FIELD_BASELINE`: 同じ競輪場・車立て、全車番
- `TRACK_FIELD_BIKE_DELTA`: TRACK_FIELD_BIKEからTRACK_FIELD_BASELINEを引いた率・平均値
- `FIELD_FRAME`: 同じ車立て・枠番
- `TRACK_FIELD_FRAME`: 同じ競輪場・車立て・枠番

各scopeは標本数、勝率、2着内率、3着内率、平均着順、平均finish strength percentile、競走得点期待残差を保存する。枠番NULLは推測せず`MISSING_FRAME_NUMBER`を記録し、車番計算が可能ならSTAT全体を失敗させない。

`finish_strength_percentile=(entrant_count-rank)/(entrant_count-1)`、`score_expectation_residual=finish_strength_percentile-subject_score_percentile`である。historical raceの全entryに正の競走得点がある場合だけcompetition rank方式のscore percentileを作る。score contextが不完全でも通常着順成績は残し、残差だけNULLにする。これは配点でも因果効果でもなく、`OBSERVED_ASSOCIATION_NOT_CAUSAL_EFFECT`として監査する。

### Cumulative processing and hash

targetを`input_as_of, race_id`の複合keysetで処理し、population履歴cursorを時系列に一度だけ前進させる。履歴は250 rowずつ取得し、全履歴を同時保持しない。各bucketは集計値とcanonical event hash chainだけを保持する。event hashはrace/entry/player、発走時刻、競輪場、車立て、車番、枠番、正規化結果、同着、着順、得点、finish percentile、score percentile、残差、score context hash、source取得時刻、app確定観測時刻を含む。resultのhistory hashは対象bucketの`history_count`と`history_hash`を組み合わせるため、同じ集計値でもraw eventが変われば変化する。

ライン構成・役割、初手位置、戦法、バンク構造、車番割当方式と制度version、車番変更履歴、因果推定、固定的な内中外区分は未実装である。

## STAT-42

### Current co-entrants and pair

`CURRENT_FIELD_CONTEXT`はtarget本人を除くSTAT-01 snapshot上の全出走entryである。`relation_scope=ALL_CURRENT_COENTRANTS`を保存し、enemy、別ライン、同ライン、主要対戦相手とは判定しない。現在のラインを構造化して再現できず、STAT-40、STAT-14、正式確率も未提供のため、top-Kや得点閾値による主要相手選定は行わない。

player matchingは内部`player_id`だけを使う。canonical pair keyは`min(player_id):max(player_id)`であり、pair履歴を共有しつつsubject/opponent方向を計算時に反転する。同一過去raceで両者がstartedならdirect meeting、両者がNORMAL_FINISHならnormal direct meetingである。相対着順差は`opponent_rank-subject_rank`、finish差は`subject_finish_percentile-opponent_finish_percentile`で、正値がsubject方向を表す。

能力補正残差は次の式であり、期待先着確率ではない。

```text
score_percentile_difference = subject_score_percentile - opponent_score_percentile
observed_finish_difference = subject_finish_percentile - opponent_finish_percentile
relative_expectation_residual = observed_finish_difference - score_percentile_difference
```

同じ過去raceから複数pairが生じる依存性を隠さず、`sum_pair_direct_meeting_count`と`unique_direct_source_race_count`を別に保存する。A-BとB-CからA-Cを補完する推移律は使用しない。履歴なしを0相性へ変換せず、固定の最低標本数も定義しない。

### Pair loading and hash

5 target raceのworking batchごとにcurrent player IDsを集め、2人以上が同一履歴raceにいるcandidate raceを一括抽出する。race単位cursorからcanonical pair eventを作り、各targetの厳密なinput as ofでfilterする。target×opponent SQLは発行せず、次working batchへ進むとpair履歴を解放する。

pair event hashはrace、時刻、車立て、競輪場、canonical player/entry ID、車番、枠番、結果状態、着順、finish percentile、得点、score percentile、score context hash、取得時刻、app確定観測時刻を含む。pair eventを時系列・ID順にcanonical sortしてpair history hashを作る。current co-entrant contextはtarget context hashへ含まれるため、相手のplayer、車番、STAT-01 input hash等が変われば最終input hashも変わる。

現在・過去ライン関係、役割、主導権、主要相手選定、期待確率モデル、時間減衰、条件類似度、STAT-40/STAT-14重み、バンク構造、距離、戦法、心理的相性、総合相性scoreは未実装である。

## Result hash and bounded memory

最終`input_hash`はstat code、calculation version、target context hash、history from、history input hashから決定する。外側`--chunk`はtarget raceの複合keyset page、内側working batchは5 raceである。STAT-39は250 history rowの前進cursorとaggregate hash chain、STAT-42はworking batch限定のDB cursorとpair mapを使う。chunk変更で時点条件や結果を変えない。

## 2024受入

レビュー後の全件実行は次の形とし、本製造中には実行しない。

```bash
php -d memory_limit=128M artisan keirin:statistics:build-batch04 \
  --stat01-run-id=1 \
  --history-from=2023-01-01 \
  --from=2024-01-01 \
  --to=2024-12-31 \
  --chunk=200
```

先に1日単位と正常direct pairを含むraceの`--dry-run`を128MBで確認し、stored run後は`docs/sql/validate_batch04_2024.sql`で2-runの完全性と分布を監査する。Batch04はMigration、scraping、Parser、Fetcher、source schemaを変更しない。
