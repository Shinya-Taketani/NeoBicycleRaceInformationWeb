# STAT39-EXTENSION-REQUIREMENTS-01

- 作成日: 2026-10-14
- 文書版: 設計レビュー案 v0.1（未承認・未凍結）
- 開始main / 開始HEAD: `5c3db6779f5ca9ae471cac1e9131a411a317901f`
- 作業branch: `design/stat39-extension-requirements-01`
- 工程状態: `STAT39_EXTENSION_REQUIREMENTS_READY_FOR_REVIEW`
- 維持する制限: `historical_as_of_available=false`、`prediction_use=NOT_AUTHORIZED`、`formal_adoption=false`、2026 holdout閉鎖

## 1. 目的・工程範囲・前提

既存STAT-39の重複機能、分母・粒度の不足、発走前証拠と公式意味の不足を切り分け、次工程の判断材料を作る。未補正の車番勝率を直接加点する目的ではない。

**確認済み事実**は開始HEADのコード・文書の内容、**過去実測記録**は既存文書が報告した当時の実行、**設計案**は今回の未承認提案、**未確認**は根拠未取得を表す。過去実測記録を今回再検証した実測値と扱わない。

ローカル履歴でPR #106のmergeを確認した。MASTER PLANの5.0/15.77は区分1MVP作業当時の現在地で、後続PR #105/#106の受入・本工程の限定許可は最新ユーザー指示と開始履歴を根拠とする。MASTER PLAN自体は今回変更しない。

本工程は設計と資料照合だけ。DB接続、HTTP、Raw本文監査、再集計、学習、予測、性能評価、既存成果物の再hash検査、2026実データ/Fixture参照を行わない。変更は本書だけであり、製造・収集・採用の許可を含まない。

## 2. 確認したソースと資料の一覧

以下の参照は今回読取り確認したコード・文書。保存run・モデル・外部証跡の内容は再読込みしていない。

| 根拠 | 資料・ソース | 確認内容 |
|---|---|---|
| S01 | [AGENTS](../AGENTS.md)、[MASTER PLAN](statistical-engine-master-plan.md) 5.0/12.6/12.7/15.59/15.74/15.77 | 工程制限、cohort依存、過去の不採用・人工検証 |
| S02 | [車番・枠番研究](stat39-bike-frame-probability-research-01.md) 3/4/7/8/10/12/16、[監査SQL v2](sql/research_stat39_bike_frame_2024_2025.sql) | 既存実測、時点・本人・公式意味の限界、NULL安全監査 |
| S03 | [Batch04契約](statistical-engine-batch04-existing-db.md)、[区分1MVP台帳](stat-category1-mvp-completion-01.md) | 現行限定MVPと未完成事項 |
| S04 | [Stat39Calculator](../app/Domain/Keirin/Statistics/Calculators/Stat39Calculator.php)、[PositionBiasRepository](../app/Domain/Keirin/Statistics/Repositories/Batch04PositionBiasRepository.php) | target、scope、分母、累積履歴・残差 |
| S05 | [TargetRepository](../app/Domain/Keirin/Statistics/Repositories/Batch04TargetRepository.php)、[BuildService](../app/Domain/Keirin/Statistics/Services/Batch04FeatureBuildService.php)、[Support](../app/Domain/Keirin/Statistics/Support/Batch04CalculatorSupport.php) | STAT01 run受入、cutoff、保存・hash、bounded処理 |
| S06 | [Target DTO](../app/Domain/Keirin/Statistics/DTO/Batch04TargetEntryDto.php)、[History DTO](../app/Domain/Keirin/Statistics/DTO/Batch04PositionHistoryContextDto.php)、[Batch04Stat](../app/Domain/Keirin/Statistics/Enums/Batch04Stat.php)、[Normalizer](../app/Domain/Keirin/Statistics/Support/HistoricalResultStateNormalizer.php)、[状態Enum](../app/Domain/Keirin/Statistics/Enums/HistoricalResultState.php) | 粒度・版・正常/異常/未発走 |
| S07 | [Stat01InputRepository](../app/Domain/Keirin/Statistics/Repositories/Stat01InputRepository.php)、[Stat01Calculator](../app/Domain/Keirin/Statistics/Calculators/Stat01Calculator.php) | 得点、観測時計、input_as_of、BACKFILL |
| S08 | [RaceDetailParser](../app/Domain/Keirin/Scraping/Parsers/RaceDetailParser.php)、[RaceEntryListParser](../app/Domain/Keirin/Scraping/Parsers/RaceEntryListParser.php)、[RaceScheduleParser](../app/Domain/Keirin/Scraping/Parsers/RaceScheduleParser.php)、[RaceRepository](../app/Repositories/RaceRepository.php)、[RaceEntry](../app/Models/RaceEntry.php) | PJ0315/JSJ017のfield、本人、現在値更新、day_kind |
| S09 | [RaceLiveFetcher](../app/Domain/Keirin/Scraping/Fetchers/RaceLiveFetcher.php)、[RaceResultSyncService](../app/Domain/Keirin/Scraping/Services/RaceResultSyncService.php)、[ScrapingFetchService](../app/Domain/Keirin/Scraping/Services/ScrapingFetchService.php)、[RawStorage](../app/Domain/Keirin/Scraping/Services/RawResponseStorageService.php)、[HTTP client](../app/Domain/Keirin/Scraping/Http/KeirinHttpClient.php) | 得点取得経路、Raw/log、取得時刻と保存処理 |
| S10 | [元schema Migration](../database/migrations/2026_07_18_000001_create_keirin_scraping_tables.php)、[feature Migration](../database/migrations/2026_08_06_000005_create_statistic_feature_tables.php) | nullable枠・得点、汎用時刻、run/result保存構造 |
| S11 | [Bt02SignalRegistry](../app/Domain/Keirin/Backtest/Services/Bt02SignalRegistry.php)、[Bt03e03Contract](../app/Domain/Keirin/Backtest/Services/Bt03e03Contract.php)、[C1 Layout](../app/Domain/Keirin/Backtest/Experiments/TacticalHistory/Layout.php) | 既存12 STAT信号、anchor、学習表現・評価契約 |
| S12 | [旧カテゴリ比較](stat39-c1-field-bike-01.md)、[比較Contract](../app/Domain/Keirin/Backtest/Experiments/Stat39C1FieldBikeComparison/Contract.php)、[比較Evaluation](../app/Domain/Keirin/Backtest/Experiments/Stat39C1FieldBikeComparison/Evaluation.php)、[C1 Evaluation](../app/Domain/Keirin/Backtest/Experiments/TacticalHistory/Evaluation.php) | 不採用、時系列・seal・増分Gate |
| S13 | [MetricEvaluator](../app/Domain/Keirin/Backtest/Calculators/Bt03e05MetricEvaluator.php)、[PairedBootstrap](../app/Domain/Keirin/Backtest/Calculators/Bt03e05PairedBootstrap.php) | 11指標、同着適格性、paired/year-equal CI |
| S14 | [as-of監査](c1-racecard-asof-preflight-01.md)、[入力readiness](c1-tactical-input-readiness-01.md)、[証拠契約](c1-racecard-prestart-evidence-contract-01.md)、[Projector](../app/Domain/Keirin/Scraping/PrestartEvidence/ObservationProjector.php)、[Verifier](../app/Domain/Keirin/Scraping/PrestartEvidence/CaptureVerifier.php)、[SourceValidator](../app/Domain/Keirin/Scraping/PrestartEvidence/SourceValidator.php) | 過去ログの限界、人工Phase1、取得元・時刻の分離 |
| S15 | [Batch04 Unit](../tests/Unit/Domain/Keirin/Statistics/Batch04CalculatorsTest.php)、[Batch04 Feature](../tests/Feature/Console/Keirin/BuildBatch04FeaturesCommandTest.php)、[Normalizer Test](../tests/Unit/Domain/Keirin/Statistics/HistoricalResultStateNormalizerTest.php) | 非連続車番、枠欠損、hash、cutoff・run、異常分母の既存検査。今回未実行 |

要件原本は次の実在ファイルのSTAT-36/39/40節を確認した（STAT-39: 4969〜5089行）。ファイル名はv1.0(2)、本文はv0.1で一致せず、今回どちらかへ修正・推定しない。

`/home/shinya/ダウンロード/%E7%B5%B1%E8%A8%88%E3%82%A8%E3%83%B3%E3%82%B8%E3%83%B3%E8%A6%81%E4%BB%B6%E5%AE%9A%E7%BE%A9_STAT-01-46%E7%A2%BA%E5%AE%9A_v1.0(2).md`

SHA-256: `73b4846b7cdeeb090dbb1c4410e12cb8ba717020f07c77de15e1fee28b7bc4ab`。

追加提示された残タスク原本は以下で存在・本文を確認した。STAT-39関連の4項目を全文照合し、Section 18へ転記する。こちらもファイル名v1.0(2)/本文v0.1。

`/home/shinya/ダウンロード/スクレイピング残タスク_統計エンジン連携_STAT-01-46確定_v1.0(2).md`

SHA-256: `963ae1d64b6f04f1eccbefc039c7228710b950d7d22bcb413cdf17ea921471c3`。
併記された `/home/shinya/neo-keirin-reference/` 配下の同名ファイルは存在確認できなかった。確認したダウンロード側を出典とし、原本の状態を変更しない。

## 3. 現行STAT-39機能棚卸し

「保存」はServiceの保存経路とS02の当時の保存監査を区別する。今回DBへ接続して現在の保存状態を再保証していない。「モデル利用」は固定元C1の直接入力を指す。

| 項目 | 計算・保存 | 元C1での直接利用 | 発走前検証 | 拡張の必要性 |
|---|---|---|---|---|
| 絶対車番 | TARGET_POSITION_CONTEXT/bike_number、FIELD_BIKEに実装・保存経路あり | 生カテゴリでなく残差信号の条件として利用 | 未成立 | 同じ生カテゴリの重複追加は不要。時点・変更履歴は不足 |
| 参加車番内の相対順 | 昇順集合のindex/percentileを保存、非連続車番を許容 | 専用信号としては未使用 | 未成立 | 既存表示で対応。物理的内外/初手位置へ解釈しない |
| 枠番・NULL | FIELD_FRAME/TRACK_FIELD_FRAME、NULLはMISSING_FRAME_NUMBER | 未使用 | 適用意味と時点が未確認 | 非適用・欠損分離と枠イベント候補が不足 |
| 宣言人数 | races.entrant_countをtargetへ保存 | 残差scopeに利用 | 当時値の証明なし | 取得版・人数変更の証拠が必要 |
| 登録人数 | actual_entry_countはSTAT01 target行数 | 元C1入力cohort、旧追加候補のNも登録人数 | 実発走人数ではない | 名前でactual-startedと誤解しない |
| 実発走人数 | 現行STAT39は独立した分母・coverageを保存しない | 専用入力なし | 対象レースの実発走人数は事後情報 | 新版の履歴分母候補。対象の事後人数を入力にしない |
| 全場の過去成績 | FIELD_BIKE/FIELD_BASELINE/FIELD_BIKE_DELTA | 残差deltaのみ | 履歴cutoffと取得証拠は別 | 主なscopeは既存で十分 |
| 場別の過去成績 | TRACK_FIELD_BIKE/BASELINE/DELTA | 専用信号としては未使用 | 同上 | 場分類は既存。バンク構造補正は未実装 |
| P1/Top2/Top3 | win/top2/top3のcount/rateを保存 | これらの率自体は未使用 | 後日最終結果による累積 | P2/P3表示は同一分母なら導出だけで十分 |
| 得点期待残差 | residual_sample_count/mean_score_expectation_residual、baseline差を保存 | FIELD_BIKE_DELTAの残差を直接使用 | 原得点の発走前証明なし | 重複新設不要。独立効果の証明とは別 |
| 時点・lineage | target input_as_of、history_from、history hash、STAT01 run/hashを保存 | source snapshot・信号へ影響 | cutoffは証拠時計でない | 属性観測/capture/版履歴が不足 |
| 変更・割当・ライン・初手 | unavailable_componentsに明記 | 接続なし | 未成立 | 取得基盤が前提。値を推測しない |
| score/配点 | POSITION_BIAS_SCORE/raw_points/confidence/effective_pointsはNULL | 正式配点なし | 採用未承認 | 今回もNULL維持 |

現行計算版は `STAT-39-existing-db-v1`（S03〜S06）。履歴は男子A/S、CONFIRMED/CORRECTED、history_from以後で、`historical scheduled_start_at < target.input_as_of`。NULL cutoffはrun開始前に拒否し、対象レース自身を使わない。後日取得した最終結果を含む `BACKFILLED_FINAL_RESULT` であり、履歴結果が当時公開済みだった証明ではない。

正常履歴だけをbucketへ加え、250履歴rowの前進cursor・累積hash chain・5 target race working batchを使う。現行のsample_countは正常状態の行数で、rankがNULLでも増える一方、分子・mean_rankの有効数は別である。このコードの挙動と、新版で不明rankを負けへ扱わない要件を混同しない（S04）。今回修正しない。

## 4. 既存C1／BT-02での実利用状況

確認済みのBt02SignalRegistryのSTAT39入力は次の1信号（IDENTITY変換、EntryIncremental）である。

```text
FIELD_BIKE_DELTA_MEAN_RESIDUAL
features.FIELD_BIKE_DELTA.mean_score_expectation_residual
```

元C1は12 STAT（07/08/10/11/12/23/24/26/31/32/39/42）と開催前120日の4決まり手回数による16学習項目。STAT01のRACE_SCORE_Zは各順位の係数1固定anchorとして別に使用する（S11/S12）。区分1MVPの16 STAT一覧とは別の16項目である。

S02の既存モデル検査記録では、最終C1のSTAT39 active係数が10個、3順位それぞれ10個非ゼロ。今回保存モデルを開いて再確認していない。raw P1/Top2/Top3、枠イベント、相対車番の専用係数があるとは言わない。

MASTER PLANはBT-02での知見とBT-03のcohort依存を記録する。STRICT/OPERATIONALのthresholdやbin_indexを無条件統合しない。既存残差をもう一つの「新しい車番効果」として加算せず、能力anchor・場適性・ライン評価との重複を監査する。

## 5. 過去の車番追加実験の評価と不採用理由

S12/MASTER PLAN 15.59の**過去実測記録**。`STAT-39-C1-FIELD-BIKE-01`は元C1の16値・型・順序を固定し、17項目目 `C1_ENTRY_COUNT_BIKE_CATEGORY` を追加した。Nは出走表登録人数5〜9、Bは元車番1〜9（欠番再採番なし）。学習時に正supportの観測カテゴリだけを使用し、観測35カテゴリ、最大schema45。

| 年等重み・候補−元C1 | 差 (pp) | 95%CI (pp) |
|---|---:|---|
| 1着 | -0.7166 | [-0.9548, -0.4843] |
| 2着 | +0.2925 | [-0.0308, +0.6047] |
| 3着 | -0.8733 | [-1.2041, -0.5650] |
| 位置Hit@3 | -0.4389 | [-0.6336, -0.2544] |

主Gateは `NOT_PASSED`、候補は `NOT_ADOPTED`、元C1を維持。非劣性・年別条件・Hit3優越はfalse、integrityはtrue。2着の点推定は正でもCIが0を跨ぎ、改善確定とは言わない。補助STAT01 Gate PASSは元C1に対する増分合格ではない。

1 execute/独立2run/78意味ファイル一致という旧記録を保持し、今回再実行しない。S02の未補正車番率を根拠にこの不採用を覆さず、同じカテゴリ・実質同義の再符号化をparameter調整で再試行しない。

## 6. 個別1着・2着・3着率の定義

以下は**設計案**。対象scope g、母集団D、履歴窓W、cutoff t、制度版Vを同じに固定する。正常な公式保存rankを持つ本人行について `Ck = Σ I(rank=k)`、`Ctop2=C1+C2`、`Ctop3=C1+C2+C3`。

```text
P1 = C1 / |D|
P2 = C2 / |D|
P3 = C3 / |D|
Top2 = (C1 + C2) / |D|
Top3 = (C1 + C2 + C3) / |D|
```

分母0はNULL、正の完全に確認された分母で観測入着0件なら0。未確認行を0件として埋めない。率と整数分子・分母、母集団/窓/cutoffを併記する。これらは履歴頻度であり、個別対象レースの校正済み予測確率・配点ではない。

同着は保存公式rankをそのまま使う（2人のrank1はそれぞれC1）。rankの再採番、同着の任意按分、欠けたrank2の補完をしない。複数車番のP1を合計して1になることは同着時に要求しない。

## 7. 新規情報か、既存率からの導出か

**同じ分母・母集団・scope・履歴窓・cutoff・状態/同着契約**なら、選手行のrank事象は排他的なので `P2=Top2-P1`、`P3=Top3-Top2`。既存STAT39のwin/top2/top3 countからexact2/3 countを導ける。整数count差を優先し、丸めた表示率の差を正本にしない。

| 候補 | 情報判定 | 提案 |
|---|---|---|
| 同じ正常分母のP2/P3 | 既存countの表現変更、情報増加なし | 表示/読取りprojectionで十分。Calculator新版・新信号の増分実験は不要 |
| 異なる分母/期間の率同士の差 | 上記恒等式を適用不能 | 比較を拒否し定義を合わせる。年間事後率と各target累積率平均も別 |
| 実発走分母と異常・未知coverage | 現行STAT39保存率にない情報/契約 | 別版候補。ただし追加予測価値は未測定 |
| 枠内少なくとも1人の入着イベント | 選手平均率から一般に復元不能 | race×frame事象として別仕様 |
| 既存残差信号の別名 | 既存C1との重複 | 新設・二重加算しない |
| 制度版/変更履歴/検証済み文脈 | 現行入力で不足 | 発走前証拠取得・定義確認後に初めて候補判定 |

派生列の追加だけで、線形/非線形表現が変わることを「新しいデータ情報」と呼ばない。今回はその表現探索も行わない。

## 8. 分母・同着・異常状態・枠イベントの仕様

### 8.1 選手行の分母案

`NORMAL_FINISH` と `VERIFIED_STARTED` を別集計にする。登録人数、実発走人数、正常完走人数、rank判定可能人数を別fieldにする。予測対象の実発走人数は事後判定であり、登録人数から補完・入力利用しない。

| 保存状態 | 正常完走分母 | 確認済み実発走分母 | rank1/2/3分子 |
|---|---|---|---|
| FINISHED/TIED・有効rank | 含む | 含む | 公式rankで判定 |
| FINISHED/TIED・NULL/不正rank | 状態件数は保持、率の判定は未完 | 実発走件数は保持、率の判定は未完 | 勝敗0を補完しない。該当scopeの率はNULL＋理由 |
| DISQUALIFIED/CRASHED/DID_NOT_FINISH | 除外 | 確認した状態として含む | 異常による非入着。仮rank0/最下位を作らない |
| DID_NOT_START/WITHDRAWN | 除外 | 除外、別件数 | 加えない |
| レースCANCELLED | 対象外、別件数 | 実発走とみなさない | 加えない |
| NULL・未知状態・結果欠損 | 未確定件数 | 未確定件数、startedへ既定追加しない | 不明を非入着0にしない |

未知行を除いた率を提示する場合は「確認済み部分母集団条件付き」と明記し、未知件数とcoverageを併記する。未知を含む全登録者率とは称さない。本人対応不一致は隔離理由と対象件数を保持し、黙って正常群から消さない。

現行共通EnumはUNKNOWN_ABNORMALをstarted=trueとするが、現行STAT39 bucketはNORMAL_FINISHだけ。S02研究の実発走分母は明示された5状態だけで未知は別管理。この3契約を混同せず、新版案の未知扱いを旧版へ遡及適用しない。

### 8.2 枠の選手率とイベント率

`FRAME_RIDER`は、同じ枠に属する選手行を分母にした率。現行FIELD_FRAMEがこの粒度。複数人枠の人数により分母が増える。

`FRAME_EVENT`はrace×frameを一度だけ数える。設計案の実発走基準分母は、枠対応が確認でき、その枠から少なくとも1人が実発走したrace×frame。`Ek = I(その枠にrank kの正常選手が少なくとも1人)`。同枠同着2人も1イベントとする。枠全員DNSなら分母に入れず別件数。

枠対応、所属全員の状態/必要rankが不明でイベントを判定できないrace×frameは、確認済みイベント分母から分離しcoverageを残す。NULL枠を一つの実在枠へまとめない。7車枠NULLは欠損/非適用UNKNOWNのままで、車番から埋めない。公式根拠がある場合だけNOT_APPLICABLEを許す。

**枠イベントではE1とE2が共存し得る**。したがって `P(E1∪E2)-P(E1)=P(E2かつ非E1)` であり、一般にP(E2)ではない。Top3−Top2も同様。exactイベント、union、必要なintersectionをrace粒度で数え、選手別率の加算や独立仮定の積で補完しない。

同じ枠の人数・登録/実発走基準・制度版を保存する。枠番号の大きさを物理的外側や枠自体の因果効果へ直結しない。

## 9. 発走前競走得点の取得可能性

### 9.1 確認済み取得・保存経路

S08/S09: RaceLiveFetcherがPJ0315を取得し、Raw/log保存後にRaceDetailParserが `sensyuTypeInfo[].heikinTokuten` をraceScoreへ解析する。車番syaban/登録番号sensyuRegistNoとPC0201のcarNum/numPlayerを照合する。RaceRepository::updateRaceDetailはレース日・場・番号、全車番集合と登録番号を検査し、race_entries.race_scoreと汎用fetched_atを更新する。

JSJ017同期はrace_scoreを解析・更新せず、同じentryの汎用fetched_atを更新する。結果同期経路はdetailを取得してからPJ0326を読む。DBへ渡す更新時刻とFetchedResponseDtoの取得時刻は別生成である。これらは発走前専用収集・得点観測履歴を保証しない。

現行RaceEntry/Stat01InputRepositoryは汎用fetched_atを使い、専用得点時刻/capture参照を持たない。S02当時のschema確認でも専用列なしと記録される。今回本番schemaを再確認・変更しない。

STAT01のinput_as_ofはsales_close_atを優先し、なければscheduled_start_at。これは予測cutoffで、原得点がその時刻に取得・公開済みだった証明ではない。後日BACKFILLのVALIDやhash整合も時点保証ではない。

### 9.2 既存証拠の限界

S02の**過去実測記録**: 年間STAT01 run1/27の研究対象は2024年170,949行、2025年170,480行。得点利用可能170,441/170,004行、source_fetched_atは対象全行で発走時刻以後。発走前取得の証拠は増えていない。`score_adjustment=NOT_ESTIMATED_NO_VERIFIED_PRESTART_STAT01`。

S14の以前の固定C1監査は99,669レース/706,051出走について、JSJ017 21,203ログ、PJ0315 125,130ログを対応付け、発走前候補0でPhase A終了した。Phase BのRaw実在/hash/属性在否検証は実施していない。これは調べた保存ログに証拠がなかった事実で、全歴史資料の不存在証明ではない。研究の47,471レース母集団とも区別する。

### 9.3 将来の必要条件（設計案）

属性原値・存在/型・Raw内pointer、race/date/track/number・bike/登録番号・entryの対応、専用capture IDとRaw/logのsealを結び付ける。取得完了時刻、保存完了時刻、検証/予測に使用可能となった時刻、明示TZ・時計根拠を分離する。

HTTP 200、許可取得元・実効URI・method/parameter識別、リダイレクトなし、原Raw bytes/size/SHA、Parser/投影版、manifest/公開markerを独立検証する。取得元不明、時刻不明、不一致、同時刻・発走後は適格にしない。秘密値やencp生値を共有証拠へ露出しない。

同じRawで観測した予定時刻に `fetched_at < observed_scheduled_start_at` を要求し、予測cutoffまでに取得・利用可能だったことも別判定する。現在DBの更新済み予定時刻を当時の確定時刻へ代用しない。公式初回公開時刻は根拠がなければUNKNOWN。

得点の再取得は別captureに残し、値が同じでも取得履歴を消さない。値の不変内容と観測期間・予測に使った版を区別する。汎用時計の無条件copy、後日値の遡及、発走時刻からの逆算は禁止。

既存PrestartEvidenceはSYNTHETIC契約でrace/bike/登録番号/予定時刻/脚質を独立検証し、TIMING-v2で取得とRaw保存の前後関係を分離する。**実収集の完了でも、得点/枠の属性検証実装でもない**。その専用投影への追加、実HTTP接続、保存設計は別途承認が必要（SCR-STAT-01-01も原本OPEN）。歴史証拠が得られない期間は不明を保持し、将来の前向き収集でのみ改善可能な部分を分ける。

## 10. 車番・枠番・変更履歴の取得可能性

JSJ017はsInfoのsyaban/登録番号senNoを取得する。PJ0315はsyaban/sensyuRegistNoとnullable wakuban（1〜9）を解析する。Repositoryの本人検査は現在保存行との対応検査であり、変更前の当時本人・値の証明ではない。

syncRaceDayは人数と出走行を更新し、消えた行を削除する現在値経路。detailは枠を更新する。元schemaはrace×bike一意の現在行であり、車番/枠/登録人数の変更前後・理由・有効期間を保存する専用履歴ではない。既存コードへ修正を加えない。

設計案は、取得ごとの出走集合/本人対応、車番・枠・登録人数の原値と適用状態、変更前後、観測日時と公式変更日時、理由（不明可）、取消/補充との対応、Raw/pointer/source/capture版を保持する。公式変更日時が不明なら「観測間に変化」を記録し、正確な変更時刻を作らない。

同じraceでもbike変更や選手交替を同一人へ誤結合しない。内部entry IDだけの継続・現在登録番号の逆引きだけでは足りず、対象時点の証拠からrace/date/場/番号/登録番号を検証する。予測で使った最新の利用可能版を固定し、予測後の変更で旧入力を上書きしない（SCR-STAT-39-01/02）。

枠の少数非NULL分布や多人数枠はS02の保存観察に留める。7車NULLはNOT_APPLICABLEと断定せず、公式制度・適用対象が確認されるまで枠成分だけを未確定とし、車番成分を巻き添えにしない。

## 11. 開催種別・車番割当方式の確認状況

S02では全47,471対象レースのday_kind原文は1。RaceScheduleParserはHTMLのdata-pprm-dkbnをそのままdayKindへ渡す。通常/モーニング/ナイター/ミッドナイトとの公式対応表は未確認。時刻、開催名の部分一致、得点順・枠分布から推定しない。

設計案は `NORMAL / MORNING / NIGHT / MIDNIGHT / OTHER / UNKNOWN` を文書上の独立候補区分とし、公式原語・出典・適用日・対象レース区分を確認してから対応付ける。OTHERにも公式根拠を要求し、不明の受け皿はUNKNOWN。DB Enumを今回追加しない。開催grade、race class、session、assignment mechanismは別軸。

公式車番割当方式は無作為/規則割当/不明を識別できる制度マスタが必要（SCR-STAT-39-03）。車立て・枠制度・規則文面/版・公式source/Raw・開始終了日・対象競走区分を持ち、制度跨ぎの履歴を分離する。今回公式原資料を入手していないため値はUNKNOWN。番号と得点の相関だけで規則割当を確定しない。

一般的な9車枠対応として説明されるものも、本件では保存分布観察と公式制度証明を分離する。未確認の規則を遡及適用しない。

## 12. ライン・初手位置との重複管理

要件原本の責務は以下。原本のADOPT_WITH_CHANGEは要件上の位置付けであり、実装完成・正式採用を意味しない。

| STAT | 原本の責務 | 本工程で必要な証拠・制限 |
|---|---|---|
| 36 | スタート〜本格的仕掛け前の個人/ライン位置確保と結果変換 | 過去レースごとの観測定義/時刻/隊列/本人。表示S回数から初手順位を作らない。対象レースの実初手は事前入力禁止 |
| 39 | 能力・車立て・場・役割・初手等と区別した車番/枠の構造的関連 | 割当制度と変更履歴、total/direct/mediatedの区別。車番順を物理初手と解釈しない |
| 40 | 同一prediction_as_ofのライン全体事前戦力と個人の支援/役割負荷 | 構成/人数/単騎/役割、個人評価snapshot、継続/変更。人数だけの加点・個人能力の二重加算をしない |

S14の脚質readinessは本人接続・原値は確認した旧記録だが、属性観測時計UNKNOWN、line_textは全706,051行NULLという記録。逃/追/両からライン先頭/番手や単騎を推測しない。STAT36の表示観測でstart_acquired=NULLという旧状態も初手の観測証明にしない。

SCR-STAT-39-04はライン・初手統合を要求する。STAT39入力をSTAT36の位置取り能力として重複評価せず、STAT40の個人点へ同じ効果を二重に戻さない。共通prediction_as_of・source版で依存関係を明示し、初手予測とライン戦力の循環参照を避ける。

## 13. データ適格性マトリクス

以下は資料照合時点の**暫定分類**で、DB Enumではない。複数軸の理由は併存可。POSTSTART_ONLYは調査済み記録の範囲限定で、歴史資料全体の断定ではない。VERIFIED_PRESTARTは原値・cutoff・Raw/log・本人・属性存在・取得元・時計が全て確認された場合だけ。NOT_APPLICABLEは公式適用根拠がある場合だけ。

| データ項目 | 保存元 | 観測時刻 | 本人対応 | 公式意味 | 発走前証明・暫定分類 | 採用可否 |
|---|---|---|---|---|---|---|
| 車番 | JSJ017/PJ0315→race_entries、STAT01/39 target | 汎用fetched_at、属性版時計なし | Parser/現在行の検査あり。当時版は未証明 | fieldは確認、割当理由は未確認 | TIMING_UNKNOWN、調査済み取得ログはPOSTSTART_ONLY | 旧development維持、新版事前利用未承認 |
| 枠番 | PJ0315 wakuban→frame_number/FRAME scope | 汎用fetched_at | 現在race/bike/登録番号検査 | 適用/非適用・制度版未確認 | TIMING_UNKNOWN / OFFICIAL_DEFINITION_UNKNOWN、NULLも残す | 枠成分未承認。NOT_APPLICABLEは現在判定不可 |
| 競走得点 | PJ0315 heikinTokuten→race_score→STAT01 | 専用時計なし。旧対象保存source時刻は発走以後 | 旧STAT01照合は確認済み記録、Raw属性版は未接続 | score field確認、当時版・公式公開時刻未確認 | POSTSTART_ONLY（保存汎用時計）/ TIMING_UNKNOWN（属性時計） | 発走前能力補正BLOCKED |
| 開催種別 | day_kindの原値、ScheduleParser | 汎用開催取得時計のみ | 現在開催の識別、当時版未証明 | raw1の対応表なし | OFFICIAL_DEFINITION_UNKNOWN / TIMING_UNKNOWN | UNKNOWN、分類補正BLOCKED |
| 車番割当方式 | 公式制度版未接続 | 未取得 | レースへの制度適用対応未確認 | 未確認 | SOURCE_UNAVAILABLE / OFFICIAL_DEFINITION_UNKNOWN | BLOCKED |
| ライン構成 | line_text・readiness旧記録で値なし | 未確認 | 構成員/役割の証拠なし | 脚質をラインへ代用不可 | SOURCE_UNAVAILABLE / IDENTITY_UNVERIFIED / TIMING_UNKNOWN | BLOCKED |
| 初手位置 | 定義済みレース別初手履歴未接続 | 観測区間/時刻未確認 | 隊列・本人未確認 | S/表示値を初手に対応不可 | SOURCE_UNAVAILABLE / IDENTITY_UNVERIFIED / OFFICIAL_DEFINITION_UNKNOWN | BLOCKED |

今回の確認範囲でVERIFIED_PRESTARTへ昇格する行はない。結果側race_entry_id/player_idはS02で341,429行全NULLと記録され、race＋bikeの一意結合と本人ID独立照合完了を区別する。必要な保存原資料の対応が確認できなければIDENTITY_UNVERIFIEDを別理由として残す。

## 14. 独立効果の検証設計

**比較A（記述統計）**はS02の2024/2025結果を参照するだけ。47,471レース/341,429登録/340,952実発走という旧研究母集団を固定C1全cohortへ置換しない。未補正率、同着、少数枠、Wilsonの相関未補正などの限界を保持し、再集計しない。

**比較B（交絡補正後の関連）**の候補は車立て、場、選手能力、session、assignment rule、競走種別/級、ライン役割、初手。各変数の当時値・定義・観測根拠をSection 13で先に受け入れる。本人対応、制度期間、欠損選択、共通supportを監査し、必要な変数が揃わない現在は `INDEPENDENT_EFFECT_VERIFICATION=BLOCKED`。

設計レビューでは次を別のestimandとして指定する。今の資料からどれも推定済みとはしない。

- 車番と結果の補正後関連（因果効果とは呼ばない）。同一選手内比較・時期/場/競走条件の層別等は候補で、共通supportなしなら比較不能。
- 初手を介したtotal/indirect effectと、初手を条件付けたdirect association。初手は媒介となり得るため、全変数を無条件に補正へ投入しない。対象の実初手を予測入力へ利用しない。
- ライン/割当方式の時間順序・役割（交絡/媒介等）を根拠付きで確定する。観測関連から因果性を主張しない。

能力補正に現在得点や後日取得値を流用せず、UNKNOWN sessionを通常へ埋めない。縮約、時間減衰、最低標本、内中外threshold、interaction形式は未決で、今回数値を設定しない。欠損で比較できない群・制度と番号のsupport不足を可視化し、独立効果不明を効果0/優劣判定へ変換しない。

## 15. 予測モデル増分比較の設計

**比較Cは将来の未承認案**。主比較は凍結済み元C1と、元C1＋本当に新しい証拠/候補情報。STAT01は補助比較だけ。P2/P3の同分母導出と旧C1_PLUS_FIELD_BIKE同義候補には新しい増分実験を設けない。

### 15.1 時系列と入力固定

2022/2023は学習・内部検証、2024/2025は既に使用済みdevelopment Outerであり未使用holdoutではない。旧契約はInner A: train2022→validation2023、Inner B: train2022〜2023→validation2024、Outer2024 refit2022〜2023、Outer2025 refit2022〜2024。Outer2024は当時許されるInner A、Outer2025はA/Bの既存One-SE選択契約に従う（S11/S12）。

元16項目とanchorの値・型・順序、cohort、入力定義、欠損を維持し、追加bin/transformはtrainingだけで決定。target outcomeは使わず、2024の教師は2024予測固定後にだけ2025のtrain/validationへ開放する。各評価年の教師を評価用に読む前に両モデルの予測をsealし、順序・race/entry集合・分母を検証する。source START/END、直接依存コードseal、model hash、prediction semantic manifestを保持する。

基準C1は保存済みモデル/予測が契約に一致する場合にforward照合で再利用し、今回再学習しない。入力証拠が不十分なcohortを黙って除去したり別モデルへ置換しない。未知/欠損の扱いで比較対象が変わる場合は別途事前仕様レビューが必要。

### 15.2 指標・paired CI・既存Gate

主指標は `WINNER_HIT_AT_1`（POSITION_1_ACCURACYと同義）、POSITION_2_ACCURACY、POSITION_3_ACCURACY、POSITION_HIT_RATE_AT_3。保存されたPrimary decisionを使い、対象を見てdecoderを選び直さない。位置別は該当公式rankが一意のrace、位置Hit@3は公式rank1/2/3全て一意のraceだけで次の定義。

```text
POSITION_HIT_RATE_AT_3 = Σ (I(P1一致) + I(P2一致) + I(P3一致))
                         / (3 * ordered_eligible_race_count)
```

同着/欠けたrankの除外理由と件数・分子/分母を保存する。単純な位置率平均、全レースの位置寄与合計、三連単全一致率と混同しない。S13の補助指標EXACT_ORDERED_TOP3_RATE、EXACT_TOP3_SET_RATE、TOP3_COVERAGE_AT_3、EXACT_TOP2_SET_RATE、TOP2_COVERAGE_AT_2、NDCG_AT_3も同じ契約を維持する。

CIは既存のyear-stratified paired race-cluster bootstrap（2,000回、seed20260812、Type7、年等重み）。同一raceを同一resampleへ両モデルから入れ、候補−C1の差CIを直接算出する。独立bootstrapの端点引算は禁止。年別の分子合算率・件数加重と、Gateの年等重みを分離して表示する。

S12の既存incremental Gateを変更しない（単位は率、ppではない）。

| 条件 | 凍結済みincremental Gate |
|---|---|
| 非劣性 | 主4指標の年等重み差CI下限がstrict `> -0.0015` |
| 優越 | Hit3差CI下限がstrict `> 0` |
| 年別Hit3 | 各年の点推定差 `>= 0` |
| 年別安定性 | 各年の主4指標差 `>= -0.003` |
| 整合性 | source/予測の検証と独立再現が成功 |

補助STAT01 GateはBt03e05AcceptanceGateの別判定で、主Gateの代わりにしない。数値閾値・solver/grid・decoderを成功目的で変えない。Gate合格が将来あっても正式採用/freeze/LIVE/2026開放を自動承認しない。現在は新情報と適格性が不足し、比較Cの実行は未承認。

## 16. STAT-39新版の候補仕様

以下のfield名・版識別は**設計案**であり、実装/schema/採用契約を確定しない。現行 `STAT-39-existing-db-v1` は不変。新版の正式名はレビューで決め、v1の同名fieldを別分母へ読み替えない。

| 区分・候補 | 採用/製造の前提 | 不足証拠・未決事項 |
|---|---|---|
| F1: 既存正常率のexact P2/P3表示 | 同scope/count/窓/cutoff、rank契約一致 | 表示projectionのみで足りる。新モデル投入・再学習不要 |
| F1: 場/車立て/相対車番・残差の表示 | 既存fieldをそのまま説明 | 初手/因果効果へ言い換えない。重複特徴量は作らない |
| F2: NORMAL_FINISH / VERIFIED_STARTEDの二分母 | 別namespaceの分子/分母・coverage、状態根拠の検証 | 欠損rank/unknownの率公開方針、必要履歴と版範囲 |
| F2: FRAME_RIDER / FRAME_EVENT | race×frameの所属・人数・同着/異常・判定可能性 | 7車適用根拠、intersectionと制度版、少数support |
| F2: 欠損/非適用/変更・制度混在coverage | 本人・属性・観測時点を独立監査 | 原本OPENの完了条件、UNKNOWNを除外しない契約 |
| F3: 得点capture・入力版 | 属性pointerと取得/利用可能時計、source/Raw/log、独立検証 | SCR-STAT-01-01、実前向き取得の許可と保存先/負荷 |
| F3: 車番/人数変更・枠制度 | 変更前後の履歴、公式規則と有効期間 | SCR-STAT-39-01/02/03 |
| F3: ライン/初手文脈 | 同一prediction_as_ofの本人・役割・隊列、定義・時点 | SCR-STAT-39-04、STAT36/40の責務と媒介設計 |
| F4: 未補正率の直接配点、数霊、内外の恣意threshold | 今回採用しない | 未補正関連を独立効果と扱う根拠なし、占術は別 |
| F4: 旧FIELD_BIKE同義再試行、既存残差の重複加算 | 今回採用しない | 既存不採用または新情報なし |
| F4: 後日値・現在値の発走前扱い | 採用しない | 原資料証拠欠如/リークのおそれ |

F2の保存構造候補は、`population_basis`、`scope`、`unit=ENTRY/FRAME_RACE`、`history_from/cutoff`、`rule_version`、`numerators`、`denominators`、`coverage`、`timing_evidence`、`identity_evidence`、`source_manifest_hash`。正常/実発走それぞれfirst/second/third/top2/top3、registered/started/normal/rank_unknown/state_unknown/DNS/abnormal/frame_missing等を別項目とする。

履歴最大時刻と「各属性が利用可能だった時刻」の最大値を分離し、後日確定履歴の運用モードを明記する。単一source_max_fetched_atやinput_hashだけで発走前保証しない。points/confidence/POSITION_BIAS_SCOREはNULL。新STAT番号は不要であり、別モデル・cohort・計算版を明示する。

## 17. 既存実装との互換性

v1 Calculator、Registryのfield path、旧run/result/model/hash/評価判定は変更しない。v1→表示projectionと新版計算契約は別版として識別する。新しい結果を旧manifest/コードsealへ書き戻さず、過去に成功した再現を修正後コードの実行結果と呼ばない。

既存feature保存はstat/version/run/subject、features/evidenceとinput_hashを持つ（S10）。拡張JSON/別runで表現可能か、専用観測・制度履歴schemaが必要かは将来レビューで決める。今回DB設計の適用/Migrationはない。旧同名scopeの分母・意味を黙って変えず、混在版の学習入力を拒否する受入契約を先に設計する。

bounded streaming、target/history順序、source/model seal、非連続車番、0とNULL、本人未解決・UNKNOWNの保持を要件として継承する。旧C1/BT02/BT03の構成・solver/decoder/Gateも不変。生成物を保持する場合は既存保存root・上書き禁止・安全退避規則へ従うが、今回は生成・退避を行わない。

## 18. ブロッカーと未決事項

### 18.1 残タスク原本との全文照合

出典はSection 2の残タスク原本STAT-39関連節（ユーザー指定2367〜2437行を含む）。4件とも優先度「高」、状態 `OPEN`。以下は項目群と完了条件の内容を省略せず対応付けたもの。関連実装が一部あるだけではDONEにしない。

| ID・名称・関連STAT | 原本の確認/保存/管理/必要項目 | 原本の完了条件 | 現状と本書の対応 |
|---|---|---|---|
| SCR-STAT-39-01 車番・枠番履歴の完全性確認 / STAT39 | レース時点の車番、枠番、出走人数、着順、結果状態、取得日時、情報源 | 車立て別車番効果を再計算できる。枠番適用・非適用を区別できる。欠損を0や非適用と混同しない | **OPEN維持**。現行率と研究は一部対応するが枠適用・当時版証拠が不足。Sections 8/10/13 |
| SCR-STAT-39-02 車番・車立て変更履歴の保存 / STAT39 | 変更前後車番、変更前後車立て、変更日時、変更理由、出走取消・補充との関連、情報源URL、Raw原文 | 予測時点の車番を再現できる。発走後変更を過去予測へ混入させない。変更後に関連STATを再計算できる | **OPEN維持**。現在値更新だけでは完了条件を満たさない。Section 10、F3変更履歴 |
| SCR-STAT-39-03 車番・枠番割当制度マスタの調査・整備 / STAT39 | 車番割当方式、枠番制度、車立て、適用開始日・終了日、公式規則、情報源URL、Raw原文 | 無作為・規則割当・不明を区別できる。制度変更前後の車番効果を分離できる | **OPEN維持**。保存分布・day_kindから推定不可。Section 11、F3制度版 |
| SCR-STAT-39-04 ライン・初手情報との統合基盤 / STAT36・STAT39 | 発走前ライン構成、ライン内位置、ライン人数、単騎区分、初手位置、スタート取得、車番・枠番 | 車番の直接効果と初手位置を介した効果を分離できる。車番効果を選手の位置取り能力として二重評価しない | **OPEN維持**。脚質/S表示では代用不可。Sections 12/14、F3統合。STAT40との同期も別途整理 |

原本のURL/Raw保存要件は将来の非公開原資料保存の要求。共有manifestへ秘密URL/encp生値を露出する許可ではない。Raw再取得・統合実装は今回行わない。

### 18.2 判断待ち

- B1: 発走前競走得点・車番/枠の当時版、利用可能時計、当時予定時刻の証拠が未接続。専用時刻だけの追加では証明が完成しない。
- B2: 枠適用/割当制度、sessionの公式根拠と制度期間、対象競走区分が未確認。NOT_APPLICABLEや通常へ推定分類しない。
- B3: ライン/初手の定義・履歴・本人対応不足。対象の実初手利用なしに何を事前特徴量にできるかを先に決める。
- B4: 結果本人ID独立照合と変更時点の関係。race＋bikeの一意性と歴史的本人証明は別。
- B5: 新版二分母の欠損rank/未知coverageと枠イベントの適格性、元v1との差、履歴窓・common support、媒介/補正対象の事前仕様レビュー。
- B6: 新情報候補が元C1に対して何を増やすか、独立効果/増分の問い、実行cohort・承認範囲。閾値や新しい配点を今回仮決めしない。

公式規則の取得元・日付・公式意味は今回確認できていない。本書と原本の照合完了は上記ブロッカーの解消ではない。

## 19. 次工程への移行条件

1. 本書の設計レビュー。事実・既存表現・新情報候補・未確認の区別とSCR4件のOPENを確認する。
2. P2/P3表示だけなら新規学習/Calculator製造不要と判断する。二分母/coverage/枠イベントの仕様を製造する場合は別版・欠損規則・人工受入例を先に承認する。
3. 発走前得点、変更履歴、session/制度、ライン/初手のうち何を先に収集・検証するか選ぶ。取得元・期間・保存先・負荷・安全条件と完了条件の根拠を確定し、DB/HTTP等の実行許可は別に得る。
4. 原本の完了条件を満たす証拠を受入れ、適格性マトリクスを更新する。歴史証拠を遡及生成せず、不明・適用外・部分cohortを明示する。
5. 独立効果の検証に必要な共通supportと発走前説明変数が不足するならBLOCKEDを維持する。実験は新情報と事前仕様がある場合だけ、旧不採用再試行とは区別して別承認する。
6. 比較Cのsource/model/teacher解放順序・同一評価universe・paired CI・既存Gate・独立再現・正式採用とは別の判断をレビューし、実行を明示承認してから進む。2024/2025を新holdoutとは呼ばず2026は閉鎖する。

現時点で許可される次の作業は本書のレビューだけ。収集、製造、比較、正式freeze、LIVEへ自動移行しない。

## 20. 結論と推奨事項

既存STAT39には車番/登録人数/場/枠の正常成績・得点期待残差があり、元C1で残差deltaを使用している。同一分母のP2/P3は既存countから導出でき、追加データ情報ではない。新STAT番号、既存残差の重複追加、生率の直接配点は不要。

新版候補は実発走/正常二分母・coverage・枠イベントと、必要な証拠の取得版/制度対応。これらは仕様上の不足への候補であって、予測精度改善を確認したものではない。発走前能力、公式session/割当、変更履歴、ライン/初手が未確認なので独立効果検証はBLOCKED、増分実験は未承認。SCR4件は全てOPEN。

推奨はまず原資料時点・公式意味・本人対応の証拠受入仕様をレビューし、追加情報の有無を判定すること。旧FIELD_BIKE追加のNOT_ADOPTED、元C1維持、historical_as_of_available=false、prediction_use=NOT_AUTHORIZED、formal_adoption=false、2026閉鎖を保持する。

文書検証は20節構成・相対リンク/根拠ファイル名・引用数値/原本項目・Git変更範囲と空白エラーを対象とする。DB処理、PHPUnit/Pint、実データ/Raw/保存artifactの再監査、モデル実行は本工程の検証に含めない。Git commit/push/PR操作/mergeも行わない。

最終工程状態は **STAT39_EXTENSION_REQUIREMENTS_READY_FOR_REVIEW**。これは設計案の提出完了であり、計算新版・取得基盤の製造、予測モデルの正式採用の承認ではない。
