# STAT-35-NEXT-USE-DESIGN-01

文書版: 0.1 / 作成日: 2026-09-28 / 状態: **DRAFT_AWAITING_REVIEW**。
確認基点: main `f0834202fcac19919bfa4b8dce0ef9043a457a0a`。
工程正本は [MASTER PLAN][MP]。本書は次用途の仕様案と確認時点の索引であり、別の工程正本ではない。
今回は3 Markdownの編集だけ。コード、入力、モデル、成果物を変更・再実行せず、DB・Raw・2026実データへアクセスしない。

## 1. 事実・提案・未決の境界

| 区分 | 内容 | 許可・判定 |
|---|---|---|
| A: 現行実装・既存記録 | PR #73の実装マージ、記述統計生成・修正版再現の過去記録、C1 v2/最終fitの固定契約 | 引継ぎ。今回再監査していない |
| B: 今回の設計案 | 固定C1とC1+STAT-35の限定development比較、入力adapter・特徴量部分集合・評価手順 | 未承認。実装・学習・評価を開始しない |
| C: 未決・根拠不足 | 出走時本人/競走区分の対応根拠、特徴量・変換・欠損・新比較Gate、実行許可 | Section 8の決定待ち。推測で補完しない |

[PR #73](https://github.com/Shinya-Taketani/NeoBicycleRaceInformationWeb/pull/73)はMERGED。
head `3a18de5bb08dd7f83cd8e35b176ce58dc7e49893`、mergeは上記main、
merged_at `2026-09-27T21:33:48Z` = 2026-09-28 06:33:48 JSTをGitHub読取りとローカルmerge commitで確認した。
取得したreviewsには旧head `4e95ddb1009feb5cf14fc22035d67fefe9979c65` へのCOMMENTEDがあり、reviewDecisionは空。
現headに対する明示的APPROVED・全成果物受入の**判定記録未確認**。マージを予測利用承認へ拡張しない。
[実行文書][PH]の修正・生成・再現・テスト成功記録は維持し、修正未完了や失敗へ戻さない。
2,152成功/既存9skip/18,129親assertions、6成果物一致等は過去記録の引用であり、今回の試験結果ではない。

## 2. A: 引き継ぐSTAT-35契約

```text
analysis_mode = FINAL_RESULT_DESCRIPTIVE_ONLY
history_time_basis = EVENT_DATE_BACKFILLED_FINAL_RESULTS
historical_as_of_available = false
prediction_use = NOT_AUTHORIZED
points = null
```

`STAT35-PLAYER-HISTORY-v1`と元成果物のdisclosure/hashは変更しない。[Contract][HC]・[PH]が根拠。
将来Bを承認する場合も、別契約のdevelopment再構成入力に限定し、既存データの予測利用可能フラグを書き換えない。

- 本人は `keirin_jp` + 観測された6桁文字列ID。先頭ゼロを維持する。現在/観測時内部IDは監査用で、本人を修復するfallbackではない。[Workspace::external / ingest][HW]
- 系列は本人・計測定義 `keirin-jp-final-back-half-lap-v1`・race class。UNKNOWNを他classへ寄せない。[Relative Contract][RC]・[Source::rows][HS]
- 3/6/12は**観測開催数**。`history.ends_on < target.starts_on`、対象開催全体除外、同日終了も不採用。日数/レース数へ変えない。[History::build / calculate][HH]
- COMPLETE比較のCALCULATEDかつ本人・文脈が適格な行だけを開催内等重み平均。その非NULL開催値を開催間等重み集計する。[Meetings::build / save][HM]
- 先に直近N観測開催を選ぶ。本人参加確認済みNULL開催は枠を消費し、数値だけをN個探し直さない。本人未確認群は通常の履歴開催を成立させない。
- 観測不足、有効値不足、欠損、入力期間端、順序不明、本人不明は別状態。窓境界をまたぐ重複期間も検知し、IDで順序を捏造しない。
- trendは直近3開催平均−その前3開催平均。6開催が順序確認済み・全て非NULLの場合だけ算出する。符号は能力向上や予測効果の証明ではない。
- PR73の開催文脈・平均へ採用する行・履歴を付与する対象行の分離を維持。混在群の正常行を巻き添えにせず、本人競合対象行へ正常履歴を付けない。[Builder::entries][HB]
- 事象日付の前後関係は公式公開時刻の証明ではない。`historical_as_of_available=false`を維持。構造・風・ライン・相手補正、速度補完を追加しない。

## 3. A/B: field対応と数値境界

表の`n`は3/6/12。現在fieldの根拠は[History::calculate][HH]、[Exact::statistics / value][HE]、[Builder::entries][HB]。
モデル用列名・input versionは未採番。以下は追加実装済みfieldを意味しない。

| 元ファイル/field | 意味・単位 | 現在の計算条件 | 次工程での扱い案 B | NULL/0/不足/blocked | 時点根拠 | 変換案 | 根拠path・記号 |
|---|---|---|---|---|---|---|---|
| entry-history.jsonl: history.windows[n].mean | 開催平均percentileの平均、無次元 | 選択窓の有効開催1件以上、開催等重み | 最初の限定候補はn=6のmean一つを推奨。他窓は監査 | 数値0は有効。部分窓の既存数値は保持、0件/blockedはNULL | 選択開催のends < target starts | 下記の分数→数値境界 | [HH] calculate / [HE] statistics |
| 同: history.windows[n].median | 同中央値、無次元 | 有効開催1件以上、偶数は中央2値平均 | 診断用に保持、初回モデルへ入れない案 | NULLと0を区別、部分窓を完全窓と呼ばない | 同上 | 同上 | [HE] statistics |
| 同: history.windows[n].population_variance | 開催値の母分散、無次元二乗 | 有効開催2件以上、分母は有効開催数 | 診断用。平均との同時追加は別の事前決定が必要 | 1件の分散はNULL、2件以上の同値は0 | 同上 | 同上 | [HE] statistics |
| 同: history.recent3_minus_previous3_meeting_percentile | 3対3の差、無次元 | 6開催全て有効・順序既知 | 診断用。初回は学習へ入れない案 | 真の差0は有効。理由付きNULLを0補完しない | 6開催とも厳密に過去 | 同上、符号維持 | [HH] calculate |
| 同: history.windows[n].observed_meetings / valid_meetings / adopted_races / requested_meetings | 観測開催/有効開催/採用レース/要求開催の件数 | NULL開催を除く前後の別計数 | 監査専用。信頼度係数や追加特徴量にしない案 | 観測0は件数0、値0ではない。理由別に保持 | 採用過去窓 | 整数のまま、非独立行数と区別 | [HH] calculate |
| 同: history.windows[n].flags / blocking_reasons / exclusion_reasons; history.trend_null_reason | 不足・期間端・競合・NULLの根拠 | 非排他的な理由、trend理由優先順あり | 監査専用、対象選別に使わない | 不足とblockを統合しない。理由合計を人数にしない | 過去窓と検証済み対象文脈 | 列挙/boolean/mapをそのまま保持 | [HH] calculate |
| 同: history.windows[n].meetings / oldest_history_date / latest_history_date / days_since_latest_end | 履歴参照・最古/最新日・経過日 | 窓選択後の参照。最新なしなら日付/経過日NULL | 時点監査専用 | 不明日を現在日や0日へ補完しない | Asia/Tokyo暦日 | 日付/整数を保持、モデルへ渡さない | [HH] calculate |
| player-meetings.jsonl: meeting_percentile_mean / history_context_eligible / evidence / context_flags | 本人×開催×classの値と根拠 | 識別済み行の採用、文脈・境界検証後 | 過去履歴adapterの正本候補、対象結果ではない | 本人参加済みNULLは枠を消費、識別未確認群と分離 | meeting.starts_on / ends_on | 分数は変更せず保持 | [HM] build / save |
| entry-history.jsonl: source / external_player_id / identity_status / meeting / classification / result_id / target_result_audit / provenance | 現在の結果行の識別・文脈・監査 | 結果/observation由来、内部IDは補助 | **モデル入力禁止**。対象接続の正本として無条件採用しない | 事後の本人/結果品質を対象選別・欠損処理へ流用しない | 当時公開時刻は未証明 | 原本監査領域に分離 | [HW] ingest / [HB] entries |

正確な`numerator`/`denominator`が正本、`decimal`は12桁HalfEven表示。[HE]の分数から表示小数を再生成できる。
モデル境界は「分数→12桁HalfEvenのdecimal→有限PHP float」の一意変換を推奨するが**未承認**。
分数・表示・モデル値と変換版を別保存し、表示値から分数を逆算しない。境界同値・負trend・循環小数を人工検証する。
別精度を採る場合は学習前に固定し、binや学習結果を見て変更しない。

mean6のみという提案は、既存の中間窓を一つに固定し、重なる平均/中央値/分散/trendの同時追加と事後窓選択を避けるため。
効果の証拠で選んだのではない。3/12・median・variance・trend・品質は初回学習に入れず、追加は別の事前承認対象とする。
現在の有効1件以上のmeanは不足flags付きで保持する案であり、新たな最低件数閾値は設けない。欠損を観測0へ変えない。

## 4. B: 対象集合・接続・allowlist案

### 4.1 接続上の確認済み差

[InputBuilder::predictionChunk / enrichChunk][IB]のC1予測入力はraceの`year,race_id,entries`、
entryの`id,bike,raw,stat01_rank,anchor,anchor_status,signals,history,history_status`。
[ModelLoader::validateInput][ML]はこの許可キー集合を検査し、12 signalsと4整数/NULL historyを要求する。
STAT-35値をこの`history`へ混ぜたり旧Loaderの制約を緩めたりせず、将来は別版adapter/loaderが必要。

`history-YYYY.jsonl`の`target`には`race_id,entry_id,player_id,bike,race_date,meeting_id,meeting_start,meeting_end,input_as_of,feature_input_hash`を保存するコードがある。
しかし観測6桁external ID、計測定義、race classはこのtargetのSELECTにない。
STAT-35の`entry-history.jsonl`は`result_id`粒度、本人は結果importのobservation由来。C1と同一粒度・本人証明とはいえない。
したがって保存済みresult行を単純inner joinして「接続済み」とはしない。今回原成果物は読んでおらず、対応の実充足件数は**未確認（根拠未取得）**。

### 4.2 推奨する境界

1. C1修正版run-01の固定race/entry/bikeと入力順を対象正本にする。2024=25,212、2025=24,866、計50,078レース/356,209出走は[TH]・[MP]の過去記録であり、今回の再計測値ではない。
2. outcome-freeな既存保存証拠から、対象entryと観測external ID、対象開催期間・classを対応させた別の文脈台帳を、学習/評価前に固定する案。現在プロフィール・最新DB・補助内部ID・対象result_idで補完しない。
3. 台帳の本人/開催/classを引数に、固定`player-meetings.jsonl`の過去群から[HH]と同じ計算を行う将来adapterを設計する。履歴原本や既存`entry-history.jsonl`を更新しない。既存entry-historyは一致確認用であって対象集合の決定源ではない。
4. 接続不明のentryもC1集合から落とさず、追加値のみNULL、理由は別監査とする案。台帳全体の信頼できる出典が未確認なら実装/実データ接続の判断を保留し、全行NULLを追加効果の確認成功とはしない。
5. 原本の本人競合・UNKNOWN class・曖昧な開催順序はblockとして維持。対象結果から得たidentity_status/context_flagsで対象を選別せず、検証済み事前文脈と採用過去履歴だけで追加値の可否を決める。

モデルに渡すallowlist案は既存C1の固定数値・順序に、承認されたSTAT-35数値一列を末尾追加するだけ。
race/entry IDとbikeは対応・decoderに必要な識別子であり、学習係数の対象ではない。過去履歴の参照ID/状態/件数/時点は別auditへ出す。
`target_result_audit`、対象rank/result_status/agari、対象自身のpercentile、result_id、事後本人情報・対象結果品質、payoutは一切渡さない。
余分なfield、未知版、矛盾は明示的に拒否する。許可fieldを拾うだけで余剰outcomeを黙って捨てない。
旧C1の`signals`/`history`配列順・出走者順・レース行順を変えず、JSONオブジェクトキー順だけは意味と区別する。

追加NULLは既存[Layout::assign][LY]と同じ非active寄与を推奨し、学習bin/supportからNULLを除外する。[LayoutBuilder::build][LB]
これは欠損の数値0化ではない。status/coverageを特徴量として学習させる案ではなく、NULLと有効0を入力・監査で残す。
source不一致/改変を「通常欠損」として処理してはならない。

## 5. A/B: C1との比較契約案

### 5.1 維持する基準

基準は[TH]の修正版run-01/C1 Outer 2024/2025。run-02は再現証拠だけで標本へ重複加算しない。
旧E06、接続確認用63レース、全期間fitモデルを比較基準に置き換えない。
最終developmentモデルは[TF]の `TACTICAL-HISTORY-FINAL-01-v1`、lambda=0.1、SHA-256:

```text
e3cc1f7f10af60bb22f97a2172ca52ef2c09a3393222ee46c25619de752d43a1
```

この固定lambda・係数・bin・モデルは未決定へ戻さず、原本を保持する。ただし2022～2025全体fitなので、2024/2025の未知データ比較へ使わない。
今回新候補のlambdaが0.1になるとは決めない。

| 契約 A | 次比較への引継ぎ案 B / 根拠 |
|---|---|
| solver/model | `TACTICAL-HISTORY-CONSTRAINED-EUCLIDEAN-FISTA-v2` / `TACTICAL-HISTORY-SEQUENTIAL-POSITION-v2`。[SolverContract][SC] |
| 入力順 | STAT-01 RACE_SCORE_Z anchor係数1、STAT-07,08,10,11,12,23,24,26,31,32,39,42、次に逃げ/捲り/差し/マークの開催前120日4回数。[Final Contract::plan][FC]・[HistoryAggregator::FEATURES][HA]。STAT35は別版で末尾追加案 |
| 目的・正則化 | 3順位conditional categorical NLL、training-local bin/support、正規化L2/group/smoothness、support加重中心化。特徴量増に伴うM/G/edgeは各layout通り記録し、基準値へ偽装しない |
| solver定数 | 200 accepted updates、係数/近接勾配/中心化1e-7、相対目的関数1e-10、reference step=1。候補不成立は診断し、成功目的で緩めない |
| lambda/選択 | `[0,1e-6,1e-5,1e-4,1e-3,1e-2,1e-1,1]`、strong-to-weak・収束候補のみwarm start。One-SE、順位等重み/検証年等重み、適格候補の共通集合。[FC] parentSettings |
| decoder | E06型Primary/Supportingとtie規則を維持。alpha/3-channel/RMS/新閾値は移植しない。[Predictor][PR]・[FC] |

保存済みOuter C1を再利用する条件はsource/input/model/code契約・feature順・学習年・bin/support・solver・選択・評価集合が全て一致し、sealを検証できること。
一致が立証できなければ別runへfallbackせず停止する。基準C1を再fitする必要が出た場合は別承認を求める。
追加候補には各innerのbin/supportと全3順位係数、lambda選択、Outer refitを新規生成する必要がある。既存係数に手動加点しない。
両候補の差は入力追加とそれに必然的な学習layout/parameterだけに限定し、目的・選択規則・decoder・評価標本を同時変更しない。

### 5.2 時系列と欠損

| 評価対象 | inner学習→検証 | Outer refit | 禁止 |
|---|---|---|---|
| 2024 | 2022→2023 | 2022～2023 | 2024 outcomeでbin/support/係数/lambda/窓を選ぶこと |
| 2025 | 2022→2023、2022～2023→2024 | 2022～2024 | 2025 outcomeで選択/refitすること |

全予測を固定してから当該Outer年の評価labelsを開く。[Dataset::releaseLabels][DS]の順序を引き継ぐ。
2024を2025学習へ開放する時点と、2024自身の評価時点を区別する。2022～2025は既に閲覧済みのDEVELOPMENT_CORPUSでありfinal untouched holdoutではない。
各対象の履歴cutoffは開催初日。評価年内でも終了済み別開催は後の対象の履歴になり得るが、これは評価labelsの一括先行開放とは別の**未承認な読取設計**。
推奨は対象開催単位の過去専用reader・列allowlist・アクセス台帳とし、対象/同開催/未来行を読ませない。既存全期間成果物の利用可否とこの境界は実装前承認が必要。
event時点を守っても後日最終結果の訂正・公開時点は再構成できない。開発再構成としてのみ報告し、Goal 4/5へ進めない。

学習bin/supportは学習年だけ、lambdaは上表innerだけ。3/6/12・統計量の部分集合は実行前に固定し、Outer成績や欠損率を見て良い窓へ交換しない。
C1集合の全entryを保持し、STAT35の欠損による除外なし。学習の順位別適格/除外は既存Objective、評価の異常/同着分母は既存MetricEvaluatorを維持する。
同一選手×開催の反復値はキャッシュ共有してよいが、開催群を追加の独立標本として水増ししない。レース単位評価とentry/開催/選手件数を別記する。

### 5.3 指標・Gate案

主比較は追加候補−C1。STAT-01比較は補助として別表示。年別と年等重みを併記する。
予測対象・正解原本・異常状態・同着分母を両候補で一致させ、既存[MetricEvaluator::raceComparison][ME]を利用する案。

| 指標 | 分母・解釈 A |
|---|---|
| WINNER_HIT_AT_1 / POSITION_1_ACCURACY | FINISHED/TIEDで公式1着が一意のレース数。同じ指標を二重にGate加算しない |
| POSITION_2_ACCURACY / POSITION_3_ACCURACY | 各公式順位が一意のレース数。他順位の同着を理由に一律除外しない |
| POSITION_HIT_RATE_AT_3 | 公式1/2/3着が全て一意のレースだけ。3位置の一致数合計 / (3×適格レース数) |
| EXACT_ORDERED_TOP3_RATE | ordered top3一意のレース数。既存SupportingはMAP ordered decision、Primary完全一致は別の診断 |
| EXACT_TOP3_SET_RATE / EXACT_TOP2_SET_RATE | 既存評価対象レース数。MAP集合と公式rank<=3/2集合の一致。公式同着集合を恣意的に切り詰めない |
| TOP3_COVERAGE_AT_3 / TOP2_COVERAGE_AT_2 | 同対象レース数×3/2。marginal decisionと公式上位集合の交差人数 |
| NDCG_AT_3 | 同対象レース数。expected-NDCG decisionと既存公式順位relevanceの計算を維持 |

Hit@3は3連単/3人全員一致/Top3集合一致でも、個別順位率の単純平均でもない。指標別除外理由と分母を必ず報告する。
分母0の実験は未評価と表示し、既存計算の0返却を改善0や性能FAILと解釈しない。
Supporting6指標はPrimary予測から作り直さず、[Evaluation][EV]の既存MAP/marginal区分で補助診断する。

95%CI案は既存の年層別paired race-cluster bootstrap、2000回、seed20260812、Type7、年等重み。
同じ抽選レースを両候補へ適用する差の分布であり、別々のCI端点差ではない。選手/開催を跨ぐ相関までは補正しないという制限を明記する。
追加効果Gateは[Evaluation::incrementalGate][EV]の閾値をC1基準へ適用する**未承認案**:
4主指標CI下限>-0.0015、Hit@3 CI下限>0、各年Hit@3差>=0、各年4主指標差>=-0.0030、integrity/reproducibility必須。
既存対STAT-01 Gateは別に報告し、過去のPASSを新比較のPASSへ転用しない。Supportingを新しい採用基準に追加する場合も事前承認する。
技術成立・数値Gate通過・設計採否・時点保証・正式モデル採用を別々に報告する。Gate通過は限定開発採用候補にすぎず、欠損/時点/受入未決なら保留。
成立した比較で不通過なら当該候補の不採用候補。非収束・seal不一致・接続根拠不足は未評価/停止であり、精度差0や性能不合格に変換しない。

## 6. B: 後続実装の受入条件案（今回テスト未作成・未実行）

| ケース | 要求する確認 |
|---|---|
| 時間方向 | 本人/開催/class/過去入力固定で対象自身・同開催・未来の結果値を変えても予測用過去履歴値/semantic hash不変。source/provenanceの変化は別監査 |
| allowlist | 対象rank/status/agari/percentile/result_id/品質/事後本人情報を入力へ加えると拒否。正解不要で予測まで可能 |
| PR73混在群 | 正常行の平均と一枠の履歴を保持し、競合対象だけblock。未確認のみの群と本人確認済みNULL群を区別 |
| 欠損 | 有効0、観測0、部分窓、有効値不足、NULL枠、順序不明、境界、UNKNOWN classを区別。窓を埋めるため古い数値へ遡らない |
| 接続 | leading zero・別本人・重複entry/bike・曖昧な対応を検証。C1の行を落とさず、事後resultからの補完を禁止 |
| 算術 | exact分数・12桁表示・モデル値の関係、同値/循環小数/負trend/NULLを独立小例で検証。入力品質を係数へ混入させない |
| 学習隔離 | 評価年outcome変更で対応Outerの選択/モデル不変。後年の学習へ正式開放された過去年変更は後年モデルへ影響し得る |
| 比較集合 | 同一race/entry集合、label seal、順位別・Hit@3分母と既存baseline寄与一致。欠損候補だけの除外なし |
| 完全性 | source/model/code不一致、同サイズ1byte改変、未知版、2026行を拒否。開始/終了sealと公開前検証、失敗でCOMPLETEを出さない |
| 保存・再現 | 新規専用出力だけ、原本不変。独立2回の予測・モデル・寄与・比較semantic hash一致、時刻/path/logは別扱い |
| メモリ | JSONL streaming/ディスク索引、独立PHPへmemory_limit=128Mを実適用。高メモリ親からも成立、OOM/nonzeroは失敗、共有peakに依存しない |

既存根拠は[AgariPlayerHistoryCommandTest][HT]と[synthetic fixture][HF]。
PR73混在識別・NULL枠・同開催/未来分離・改変・再現・独立128Mのケースを静的確認した。既存成功を新adapterのテスト成功と呼ばない。

## 7. B: 生成物・資源・読取範囲・停止条件案

承認後に作るものは別契約manifest、固定target/context台帳、outcome-free追加入力と時点/欠損audit、
inner/Outer model・bin/support・選択/非収束診断、固定予測、予測後評価の寄与/CI/Gate、独立再現比較、実行ログとcode写し。
これらは**提案上の生成物**で、現存fieldや既存ファイルと称しない。旧モデル・入力・runを上書きしない。

- 保存ルートは既存合意 `/home/shinya/neo-keirin-artifacts/` を維持し、承認後に新IDの専用ディレクトリを決める。今回ディレクトリ/ZIPを生成しない。
- 利用候補はPR73修正版の固定記述統計、TACTICAL-HISTORY v2 run-01のC1入力/Outer予測/モデル/label証拠、承認された事前文脈台帳のみ。実パス/各sealは後続許可時にmanifestから特定する。
- 推奨は保存資料のみのoffline実行。本番接続は不要とする設計を優先し、現時点では接続根拠の不足をDB照会で埋めない。追加snapshotが必要なら列・年・ID・read-only範囲を別承認する。
- 対象年は2022～2025だけ。2021を無断追加しない。2026開催へのアクセス禁止。2026年の実行日付/文書日付と開催年を混同しない。
- boundedなentry/開催単位処理とSQLiteディスク索引を案とする。既存Historyの最大13候補、Meetingsの4096根拠行上限を弱めない。メモリへ全JSONLを載せない。
- 将来実行前に実ファイルサイズから入力/出力/索引/独立再現2組/ログの容量予算と空きを照合。推定と実測を分け、実行後はpeak/RSS/所要時間/exitを保存。今回の件数・所要時間・使用量は未計測。
- 学習回数は承認された特徴量部分集合×既存fold/gridだけ。独立再現以外の同条件再試行、性能を見たwindow/閾値変更を禁止。
- source/model/直接依存code・契約・target集合をSTART/END検査。固定STATと履歴の双方を検証する実行経路を維持し、既存外部検査を未実施として重複計上しない。[SourceIntegrity::verify][SI]
- 原本seal/版/接続の矛盾、容量不足、timeout、候補全非収束、selected refit非収束、分母不一致、2026混入は停止。診断を残し、source再生成/DB修復/別run代用/solver緩和へ進まない。
- 再現は同じ固定ファイルとコードでDB不要。モデル・予測・評価のsemantic部分を比較し、元の作成時刻・code identityを現mainへ置換しない。

## 8. C: レビューで先に決める事項

| 決定 | 推奨案と理由 | 未決の影響 |
|---|---|---|
| D1: 用途と時点 | 開発用event reconstructionだけ。historical as-of未保証を維持 | 承認なしでは予測利用・実装を開始できない |
| D2: 対象接続 | 固定C1出走集合＋結果非依存の本人/開催/class台帳。原本result行とのinner join禁止 | external ID/classの既存証拠の実充足は未確認。入手可能性・未知行の明示NULL方針を確認する |
| D3: 部分集合/窓 | 初回は6開催mean一列、3/12・中央値・分散・trend・品質は監査のみ | 未承認。全投入や最良窓の事後採用はしない |
| D4: 数値/欠損 | 分数正本→12桁HalfEven→float。既存の部分窓mean保持、NULL非active、品質は監査 | 変換版・部分窓の可用性定義・新loader契約の承認が必要 |
| D5: 過去履歴読取 | 対象開催cutoffで限定し、当該Outer labels開放と分離して監査 | 評価年内の過去結果利用と全期間固定資料からの抽出境界を先に承認する |
| D6: 比較/Gate | 同一Outer C1、追加候補だけ新fit、既存修正版solver/選択/decoderと追加効果Gate閾値を継承する案 | source一致を証明できないC1再利用、別Gate、再fitは別判断 |
| D7: 実行と受入 | 人工検証→限定新生成/学習→予測seal→評価→offline再現を別指示で許可 | PR73全成果物受入の判定記録未確認を明示。今回の文書完成では開放しない |

## 付録A. STAT-01～46の対象範囲索引

確認基点は冒頭commit。名称と要件状態は今回指示付録の「統計エンジン要件定義_STAT-01-46確定_v1.0(2).md」転記による。
要件書本文全体は未入手で、詳細計算仕様まで確認済みではない。STAT-16と27を統合しない。
**ADOPT_WITH_CHANGEは要件への採用であり、実装完了/モデル採用ではない。** 以下のデータ・評価は既存文書の記録で、今回は棚卸し/再計測していない。
「C1入力採用」は限定development C1での採用、全エンジン最終承認ではない。「未確認（根拠未取得）」は未実装/不採用の断定ではない。
MPの12 EntryIncremental全体の評価を各STATの最終独立効果の承認と扱わない。旧83項目や整数配点を復活させない。

| ID/名称 | 要件上の状態 | 実装の有無・範囲 | データ状態 | 評価状態 | 最終採用状態 | 用途制約・残課題 | 根拠 |
|---|---|---|---|---|---|---|---|
| STAT-01 競走得点による基礎実力評価 | ADOPT_WITH_CHANGE | existing-db得点特徴量・品質基盤 | 固定baseline記録あり | BT-01 baseline・C1比較済み | baseline/anchor採用、全体最終未完 | 取得時点は当時公開の保証でない | [S01]・[MP]・[TH] |
| STAT-02 養成所順位による潜在能力補正 | ADOPT_WITH_CHANGE | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 個別計算仕様・実装/実行根拠が必要 | 指示付録のみ |
| STAT-03 養成所200mタイムによるスプリント能力補正 | ADOPT_WITH_CHANGE | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 同上 | 指示付録のみ |
| STAT-04 ライン先頭時の自力戦適合評価 | ADOPT_WITH_CHANGE | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | C1決まり手4回数は役割適合実装の証拠でない | 指示付録・[TH] |
| STAT-05 ライン2番手時の番手戦適合評価 | ADOPT_WITH_CHANGE | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | ライン役割の根拠が必要 | 指示付録のみ |
| STAT-06 ライン先頭・2番手の役割逆転不適合評価 | ADOPT_WITH_CHANGE | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 同上 | 指示付録のみ |
| STAT-07 同一競輪場適性評価 | ADOPT_WITH_CHANGE | Batch03同場/全場差MVP | 固定feature生成記録 | EntryIncremental評価対象 | C1入力採用、全体最終未完 | layout高度版とは別 | [B03]・[MP]・[FC] |
| STAT-08 夜間・時間帯競走適性評価 | ADOPT_WITH_CHANGE | Batch03同hour差MVP | 固定feature生成記録 | EntryIncremental評価対象 | C1入力採用、全体最終未完 | 公式session区分未定義 | [B03]・[MP]・[FC] |
| STAT-09 通算実績・高位競走経験評価 | ADOPT_WITH_CHANGE | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | STAT31から全career完成を推測しない | 指示付録・[B03] |
| STAT-10 直近成績・コンディション変化評価 | ADOPT_WITH_CHANGE | Batch02正常成績/残差/短中期差 | 固定feature生成記録 | EntryIncremental評価対象 | C1入力採用、全体最終未完 | 生理的コンディションを確定しない | [B02]・[MP]・[FC] |
| STAT-11 異常結果・事故発生傾向評価 | ADOPT_WITH_CHANGE | Batch02状態別件数/率/直近性 | 固定feature生成記録 | EntryIncremental評価対象 | C1入力採用、全体最終未完 | 原因推定なし | [B02]・[MP]・[FC] |
| STAT-12 出走間隔・長期休養・復帰過程評価 | ADOPT_WITH_CHANGE | Batch02間隔分布MVP | 固定feature生成記録 | EntryIncremental評価対象 | C1入力採用、全体最終未完 | 長期休養分類閾値・医学的評価なし | [B02]・[MP]・[FC] |
| STAT-13 ライン先頭・2番手の連携実績評価 | ADOPT_WITH_CHANGE | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 役割/連携実績根拠が必要 | 指示付録のみ |
| STAT-14 先行型競合・主導権争い評価 | ADOPT_WITH_CHANGE | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | C1戦法回数と同一視しない | 指示付録・[TH] |
| STAT-15 先行競合時の展開利得評価 | ADOPT_WITH_CHANGE | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 展開モデル根拠が必要 | 指示付録のみ |
| STAT-16 類似競走条件の経験・適応実績評価 | ADOPT_WITH_CHANGE | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | STAT27と独自統合しない | 指示付録のみ |
| STAT-17 戦法多様性・役割自在性評価 | ADOPT_WITH_CHANGE | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 4回数を自在性完成としない | 指示付録・[TH] |
| STAT-18 ライン継続・組み替え安定性評価 | ADOPT_WITH_CHANGE | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 時点付き編成根拠が必要 | 指示付録のみ |
| STAT-19 直近仕掛け積極性・主導権行動変化評価 | ADOPT_WITH_CHANGE | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 決まり手回数から行動を推定しない | 指示付録・[TH] |
| STAT-20 統計評価信頼度・データ品質評価 | ADOPT_WITH_CHANGE | STAT01共通品質evidenceまで確認 | coverage/理由を保存する契約 | 独立STAT20評価は未確認（根拠未取得） | 全体採用は未確認（根拠未取得） | confidence/点数はNULL、品質情報と信頼度モデルは別 | [S01] |
| STAT-21 気象・風向風速・走路状態適応評価 | ADOPT_WITH_CHANGE | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 上がり速度に風補正を仮定しない | 指示付録・[RR] |
| STAT-22 事前オッズ・市場人気バイアス評価 | ADOPT_WITH_CHANGE | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | MARKET_OVERLAY未凍結 | 指示付録・[MP] |
| STAT-23 開催日別着順戦略評価 | ADOPT_WITH_CHANGE | Batch03同day number観測差 | 固定feature生成記録 | EntryIncremental評価対象 | C1入力採用、全体最終未完 | 戦略意図の推定でない | [B03]・[MP]・[FC] |
| STAT-24 直近成績安定性・変動性評価 | ADOPT_WITH_CHANGE | Batch02母SD/MAD/IQR等 | 固定feature生成記録 | EntryIncremental評価対象 | C1入力採用、全体最終未完 | 最適閾値/減衰未定義 | [B02]・[MP]・[FC] |
| STAT-25 競技違反・失格発生傾向評価 | ADOPT_WITH_CHANGE | 独立STAT25は未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | STAT11の失格計数と要件全体を混同しない | 指示付録・[B02] |
| STAT-26 短期連戦・競走負荷蓄積評価 | ADOPT_WITH_CHANGE | Batch02日程密度/場変更MVP | 固定feature生成記録 | EntryIncremental評価対象 | C1入力採用、全体最終未完 | 移動距離/役割負荷/医学的疲労は未実装 | [B02]・[MP]・[FC] |
| STAT-27 類似競走条件の経験量・習熟度評価 | ADOPT_WITH_CHANGE | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | STAT16と独自統合しない | 指示付録のみ |
| STAT-28 ライン役割自在性評価 | ADOPT_WITH_CHANGE | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 役割時点/定義根拠が必要 | 指示付録のみ |
| STAT-29 発走前ライン変更・編成不安定性評価 | ADOPT_WITH_CHANGE | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 発走前の変更履歴根拠が必要 | 指示付録のみ |
| STAT-30 直近戦法転換・戦い方変化評価 | ADOPT_WITH_CHANGE | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 4回数は転換判定でない | 指示付録・[TH] |
| STAT-31 高位競走・決勝経験評価 | ADOPT_WITH_CHANGE | Batch03準決勝/決勝/raw grade履歴 | 固定feature生成記録 | EntryIncremental評価対象 | C1入力採用、全体最終未完 | 完全career/進出機会分母なし | [B03]・[MP]・[FC] |
| STAT-32 レース段階別成績適性評価 | ADOPT_WITH_CHANGE | Batch03同stage差MVP | 固定feature生成記録 | EntryIncremental評価対象 | C1入力採用、全体最終未完 | UNKNOWN/OTHERと制度不足を保持 | [B03]・[MP]・[FC] |
| STAT-33 開催内着順遷移・勝ち上がり適応評価 | ADOPT_WITH_CHANGE | Batch03隣接stage遷移/同開催前走 | 生成対象の記録あり | DIAGNOSTIC_ONLY | EntryIncremental/C1には入れない | 公式結果公開時刻/勝ち上がり規則は未再構成 | [B03]・[MP] |
| STAT-34 事前オッズ変動・直前支持評価 | ADOPT_WITH_CHANGE | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 時点付き市場入力根拠が必要 | 指示付録・[MP] |
| STAT-35 上がりタイム・終盤速度性能評価 | ADOPT_WITH_CHANGE | 保存/相対値/開催履歴まで部分実装 | PR73記述生成・再現記録あり | 予測追加効果は未評価 | prediction_use=NOT_AUTHORIZED | as-of不明、速度/風/ライン/相手補正なし、全体未完 | [PH]・[RR]・本書 |
| STAT-36 スタート・初手位置取り能力評価 | ADOPT_WITH_CHANGE | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 車番は初手位置の代用でない | 指示付録・[B04] |
| STAT-37 バンク形状・構造類似適性評価 | ADOPT_WITH_CHANGE | TrackContext版/期間/距離換算の部分基盤 | v2歴史距離14/10,660場日、他UNKNOWNの記録 | coverageのみ、予測効果未評価 | prediction_use=NOT_AUTHORIZED | 類似適性・歴史網羅/全体完成ではない | [TC]・[MP] |
| STAT-38 競走距離・周回数適性評価 | ADOPT_WITH_CHANGE | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | agari計測距離と競走距離を混同しない | 指示付録・[RR] |
| STAT-39 車番・枠番位置バイアス評価 | ADOPT_WITH_CHANGE | Batch04車立て/場別観測関連 | 固定feature生成記録 | EntryIncremental評価対象 | C1入力採用、全体最終未完 | 因果的位置効果や初手を推定しない | [B04]・[MP]・[FC] |
| STAT-40 ライン総合戦力・役割構成評価 | ADOPT_WITH_CHANGE | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | Batch05から明示的に保留 | 指示付録・[B05] |
| STAT-41 レース戦力拮抗度・波乱構造評価 | ADOPT_WITH_CHANGE | Batch05得点分布/境界差、race粒度 | 固定生成記録 | RACE_STRATIFIER | EntryIncremental/C1加点でない | 波乱score/entropy/候補閾値は未定義 | [B05]・[MP] |
| STAT-42 主要対戦相手・直接対戦相性評価 | ADOPT_WITH_CHANGE | Batch04全co-entrant方向付きpair MVP | 固定feature生成記録 | EntryIncremental評価対象 | C1入力採用、全体最終未完 | 主要相手/ライン/期待確率は未実装 | [B04]・[MP]・[FC] |
| STAT-43 地元・ホームバンク適応／遠征移動負荷評価 | ADOPT_WITH_CHANGE | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 現在プロフィールを過去へ適用しない | 指示付録のみ |
| STAT-44 年齢・キャリア局面変化評価 | ADOPT_WITH_CHANGE | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 旧Growth診断から正式採用を推測しない | 指示付録・[MP] |
| STAT-45 ギヤ倍率・機材設定変更適応評価 | ADOPT_WITH_CHANGE | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 設定時点/変更履歴根拠が必要 | 指示付録のみ |
| STAT-46 補充・追加あっせん・急な出走変更影響評価 | ADOPT_WITH_CHANGE | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 補充分類を観測出走から推測しない | 指示付録・[B03] |

## 付録B. 6エンジンの別索引

要件上の根拠は[AGENTS.md Section 2][AG]の将来エンジン一覧。STATの46行と合算した機能数・進捗率は作らない。

| ID/名称 | 要件上の状態 | 実装の有無・範囲 | データ状態 | 評価状態 | 最終採用状態 | 用途制約・残課題 | 根拠 |
|---|---|---|---|---|---|---|---|
| ENGINE-1 四柱推命エンジン | 将来構想に記載 | Neo実装は未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | FourPillarsOfDestinyWebの実績を転記しない | [AG] |
| ENGINE-2 九星気学・現代天文節気版 | 将来構想に記載 | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | Neo内の仕様/実装/評価根拠が必要 | [AG] |
| ENGINE-3 九星気学・天保暦準拠版 | 将来構想に記載 | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 同上 | [AG] |
| ENGINE-4 高島嘉右衛門式易占エンジン | 将来構想に記載 | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 未確認（根拠未取得） | 同上 | [AG] |
| ENGINE-5 戦略・レース構造評価エンジン | 将来構想に記載 | C1戦法履歴/STAT41構造の関連部分を確認、独立エンジンは未確認（根拠未取得） | 4回数/構造の生成記録 | C1追加効果/構造層別の限定記録 | 全体最終は未確認（根拠未取得） | 戦法回数追加を戦略エンジン全体完成としない | [AG]・[TH]・[B05]・[MP] |
| ENGINE-6 履歴統計エンジン | 現行開発対象 | STAT01/Batch02～05/C1/STAT35部分基盤 | 固定development成果あり、as-of未保証 | BT/C1開発評価、STAT35記述統計 | C1固定候補、全体最終未完 | Goal1/2 PARTIAL、Goal3未完、Goal4/5 BLOCKED | [AG]・[MP]・[TF]・[PH] |

## 根拠索引

参照は文書・静的コード・syntheticテストの読取りだけ。原本JSONL/SQLite/Rawは今回読んでいない。
リンク先の過去実行コマンドは資料であり、今回の実行指示ではない。

[MP]: statistical-engine-master-plan.md
[PH]: stat35-player-history-01.md
[RR]: stat35-race-relative-01.md
[TH]: tactical-history-01.md
[TF]: tactical-history-final-01.md
[TP]: tactical-prediction-pipeline-01.md
[TC]: stat35-37-track-context-02.md
[S01]: statistical-engine-stat01-existing-db.md
[B02]: statistical-engine-batch02-player-history.md
[B03]: statistical-engine-batch03-existing-db.md
[B04]: statistical-engine-batch04-existing-db.md
[B05]: statistical-engine-batch05-existing-db.md
[AG]: ../AGENTS.md
[HC]: ../app/Domain/Keirin/Statistics/AgariPlayerHistory/Contract.php
[HH]: ../app/Domain/Keirin/Statistics/AgariPlayerHistory/History.php
[HE]: ../app/Domain/Keirin/Statistics/AgariPlayerHistory/Exact.php
[HM]: ../app/Domain/Keirin/Statistics/AgariPlayerHistory/Meetings.php
[HB]: ../app/Domain/Keirin/Statistics/AgariPlayerHistory/Builder.php
[HW]: ../app/Domain/Keirin/Statistics/AgariPlayerHistory/Workspace.php
[HS]: ../app/Domain/Keirin/Statistics/AgariPlayerHistory/Source.php
[RC]: ../app/Domain/Keirin/Statistics/AgariRaceRelative/Contract.php
[IB]: ../app/Domain/Keirin/Backtest/Experiments/TacticalHistory/InputBuilder.php
[HA]: ../app/Domain/Keirin/Backtest/Experiments/TacticalHistory/HistoryAggregator.php
[SC]: ../app/Domain/Keirin/Backtest/Experiments/TacticalHistory/SolverContract.php
[LY]: ../app/Domain/Keirin/Backtest/Experiments/TacticalHistory/Layout.php
[LB]: ../app/Domain/Keirin/Backtest/Experiments/TacticalHistory/LayoutBuilder.php
[DS]: ../app/Domain/Keirin/Backtest/Experiments/TacticalHistory/Dataset.php
[PR]: ../app/Domain/Keirin/Backtest/Experiments/TacticalHistory/Predictor.php
[EV]: ../app/Domain/Keirin/Backtest/Experiments/TacticalHistory/Evaluation.php
[SI]: ../app/Domain/Keirin/Backtest/Experiments/TacticalHistory/SourceIntegrity.php
[FC]: ../app/Domain/Keirin/Backtest/Experiments/TacticalHistoryFinal/Contract.php
[ML]: ../app/Domain/Keirin/Backtest/Experiments/TacticalHistoryFinal/ModelLoader.php
[ME]: ../app/Domain/Keirin/Backtest/Calculators/Bt03e05MetricEvaluator.php
[HT]: ../tests/Feature/AgariPlayerHistoryCommandTest.php
[HF]: ../tests/Support/AgariPlayerHistoryFixture.php
