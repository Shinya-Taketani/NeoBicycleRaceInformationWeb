# GROWTH-TREND-ANALYSIS-01

## 結果閲覧前契約

2026-09-19、main `eef27c9e80d80a733a4c0c3c82e18151259e8f87` から開始。
前回は実発走判定に結果状態が必要で、全trend固定前の結果参照禁止と衝突したため実装前に停止した。
今回の更新指示に従い実発走の定義を廃止し、出走表の **SCORE OBSERVATION** を使う。
欠場・中止を結果から除外しない。過去公開時刻はUNKNOWN、
`DEVELOPMENT_BACKFILLED_SCORE_OBSERVATION / DEVELOPMENT_DIAGNOSTIC_ONLY` とする。

## Source

captureだけProduction READ ONLY。`races/race_days/race_meetings/race_entries` の許可列を明示する。
結果テーブル・結果状態・着順は照会しない。SQLの対象日は2022-01-01から2025-12-31。
run-01のoutcome-free inputでtarget IDを固定し、DBでID/車番/選手を照合。
得点は整数hundredths、fetched_atは監査専用。Soft deletedを含む保存行も結果によって除外しない。
START/END一致後にsourceをLOCKEDとし、以後の分析・再現はDBを接続しない。

## Trend

同一選手・同一開催から最も早い日付・予定時刻の得点を代表値にする。
最初の順序が不明で候補得点が同値なら利用可能、異なればPARTIAL_TIME_ORDER。
NULLは得点0でも未来の得点でも補わない。対象と同じ開催、対象開催以後の観測は除く。
meeting.starts_onがない場合は観測日による開催順序だけに使い、day trendには使わない。
欠損代表も開催順序から黙って飛ばさず、必要区間内の欠損を明示する。
同一開始日の異なる開催の順序は推測せずPARTIAL_TIME_ORDER。
FIRST_SCORE_OBSERVATIONは同開催の過去観測有無で決定し、予定時刻不明時はUNKNOWNを許す。
実発走・LIVE時点保証とは呼ばない。

固定gridは41候補:
- MEETING_DELTA: lag 1..6
- MEETING_OLS: K 2..12（S0を含むK点）
- MEETING_THEIL_SEN: K 3..12
- DAY_OLS / DAY_THEIL_SEN: 30,60,90,120,180,240,365日（境界を含む、S0+過去2点以上）

OLSは中心化した積和、Theil-Senは全有効pairの中央値。同一day pairを除き、偶数中央値は中央2点平均。
score変更level・継続開催数・日数・符号streakは診断のみ。target開催は継続過去開催数へ含めない。
2022境界前の経歴は不明。必要履歴不足をNO_CAREER_HISTORYと表現しない。

## 時系列と選択

全targetのtrend、固定grid、C1 probability、source/codeをsealしてから初めて両年の結果を読む。
前案のH1/H2/2025順次選択は採用しない。2024/2025は両方development selectionでありholdoutではない。
target score / C1 P1は年別Type7分位点のdecile（同値は分断しない）。P1 marginはrace別quartile。
正常完走は既存FINISHED/TIED、FP=(車立て-公式順位)/(車立て-1)。相関は同順位平均rankのSpearman。
conditional rhoはn>=100かつrho定義可能なbinを正常有効件数加重。NULLを0にしない。

各年でfamily最大coverageの80%以上、正常有効>=10,000、overall/score条件付き/C1条件付き/first観測rho>0、
全対象とfirst観測のpositive-minus-negative FP>0を両年満たす候補だけ適格。
8個のrhoの最小値ROBUST_RHO降順、両年最小coverage降順、candidate ID辞書順で1件選択。
適格なしはNO_STABLE_GROWTH_GRANULARITY_SELECTED。結果後にgrid/閾値を変更しない。
隣接grainの同方向性は診断のみ。更新周期P25..P75の範囲外は参考warningとし再選択しない。

## 保護・保存

旧Growth各版、C1、scorer/decoder/evaluatorを変更しない。再学習・推論・weight calibration・正式Gate・bootstrapなし。
2026/DB書込み/Migration/scraping/LIVE/正式STAT採用は禁止。旧w=+0.03は不採用を維持。
source: `/home/shinya/neo-keirin-artifacts/growth-trend-score-source-01-20260919-01/`
analysis: `/home/shinya/neo-keirin-artifacts/growth-trend-analysis-01-20260919-01/`
重要成果物は最初から上記root、旧成果物は不変。128MB、SQLite spoolとstreamingを使う。
生成時seal・公開前照合・END source/code照合・DB不要byte-exact再現を必須とする。

## 実行状態

DEVELOPMENT_SELECTED_GRANULARITY_AWAITING_REVIEW。READ ONLY capture / DB無効verifyは成功。
50,078レース・356,209出走・2,285選手を照合し、2022-2025の704,202得点観測をLOCKEDした。
source START/END一致、PHP peak 34MiB。公開時点UNKNOWNは維持する。

初回execute-01はwinner gapのSQLite実行計画が、candidate全行同士の不要な直積を作るため停止した（SIGTERM、exit 15）。
旧stage・旧コード・停止ログを永続rootへ保持。結合順だけCROSS JOINで固定し、
race_idとcandidate+entry_idのindex lookupへ変更した。式・grid・入力・選択条件は変更しない。
200レースの独立winner gap参照値を含む58 tests / 301 assertionsが成功。
修正版execute-02はDB無効・128MBで成功し、31生成物をLOCKEDした（PHP peak 32MiB）。
初回trendと両年candidate-resultsはSHA-256一致。DB無効reproduceも全31生成物がbyte-exact一致（PHP peak 32MiB）。
次工程の自動開始、commit/push/PR/mergeは行わない。

## Development選択結果

選択は **MEETING_DELTA_LAG_1 = S0 - 直前の別開催代表得点**。
`DEVELOPMENT_SELECTED_GRANULARITY_AWAITING_REVIEW`、適格9候補、ROBUST_RHO=0.009639846634467018。
適格候補はDELTA lag 1/2/3、OLS K 2/3/4、Theil-Sen K 3/4/5。
代数的に同等な候補を含むため、9件の独立した発見ではない。

| 指標 | 2024 | 2025 |
|---|---:|---:|
| overall rho | 0.016814352 | 0.017429850 |
| target-score条件付きrho | 0.009957655 | 0.010034744 |
| C1条件付きrho | 0.009639847 | 0.012011709 |
| first観測rho | 0.022167226 | 0.025118129 |
| FP positive-minus-negative | 0.015374120 | 0.014376947 |
| coverage | 99.754312% | 99.769648% |
| zero率 | 1.465443% | 1.399452% |

旧race growthのrhoは0.016585616 / 0.016974873、zero率は66.897357% / 66.990922%。
zero massは減ったが、相関の増加は小さく、予測精度改善を実証したものではない。
overall rho最大の365日OLSは0.064436303 / 0.060178504だが、C1条件付き相関が負で不適格。
短いlagの近傍はLOCAL_STABILITY_PRESENT、観測された更新間隔IQR内。

選択候補の欠損は2024: MISSING_PREVIOUS_SCORE=432、PARTIAL_TIME_ORDER=8、
2025: 同398、10。必要履歴不足の扱いは全候補別coverage.jsonに保存。
観測監査は同得点継続・更新間隔の中央値が1開催、更新間隔11日、開催内drift 0 /236,747 player-meetings。
得点の公開時刻UNKNOWN、境界で切れた履歴、能力帯ごとの方向差に留意する。

winner trend - C1候補trendの平均は0.007589709 /0.015326403、中央値は両年0。
F2 A_CHALLENGEのrhoは0.047068018 /0.033062138だが、部分集団別のgrain再選択は行わない。
score-change-event、C1 confidence、開催grade/競走区分の詳細は固定bundleに保存。
結果は両年を用いた関連診断であり、独立holdout検証・C1への増分効果・因果効果ではない。

## 検証

58 focused tests /301 assertions、関連263 tests /1467 assertions。
全体1,618 passed /9 skipped /12,124 assertions。
変更PHPのPint・構文検査とgit diff --checkは成功。
全体Pintは既存Bt03e08BoundedMemoryTest.phpのstatement_indentationだけ失敗し、対象外で未変更。
本番DB書込み・結果table参照によるsource生成・2026アクセス・再学習・Gate・bootstrapは行っていない。
生成時seal連番3、2024 outcome open=5、2025=7、seal前outcome access=0。

## 保存資料からの再現

今回のcapture/executeの完全な引数と終了コードは永続rootのexecution.jsonへ保存した。
固定済みIDをcapture/executeで上書きしない。再検証は以下を使用する。

```bash
DB_CONNECTION=growth_disabled DB_URL= php -d memory_limit=128M artisan keirin:backtest:growth-trend-score-source --verify --output-root=/home/shinya/neo-keirin-artifacts/growth-trend-score-source-01-20260919-01 --source-id=outer-c1-score-observations-2022-2025-01
DB_CONNECTION=growth_disabled DB_URL= php -d memory_limit=128M artisan keirin:backtest:growth-trend-analysis --reproduce --output-root=/home/shinya/neo-keirin-artifacts/growth-trend-analysis-01-20260919-01 --analysis-id=outer-c1-growth-trend-2024-2025-01
```

## PR #62 Review Fix

2026-09-20、branch `experiment/growth-trend-analysis-01`、開始HEAD
`c3d5145a3d0e565c8576992deb8e65321ee0975e`（clean、remote一致）。PRはOPEN、修正は未コミットレビュー待ち。
上記の初回結果・検証数・再現コマンドは当時の履歴として保持する。v3から旧bundleを新契約として読み直さない。

### 修正した5事項

1. **preseal provenance outcome identity**: labelのidentity解決はtrend seal後だけ。入力・固定C1予測の8ファイルとmeeting metadataの3ファイルだけをpreseal provenanceに含める。
2. **DAY missing start**: 過去start=NULLはDAY点から除外し、target start=NULLはMISSING_MEETING_STARTのまま。監査名は `prior_meetings_with_unknown_start` とし、対象より前のstart不明開催数であってwindow内除外数ではないと明記する。
3. **C1 normal denominator**: 正式診断率は `wins / normal_predicted_entries`。`all_predicted_entries` 分母の参考率も別欄に残す。
4. **same-date distinct meeting ambiguity**: SQLで同日別開催を保持し、全41候補を `raw=NULL / PARTIAL_TIME_ORDER` とする。`target_boundary_partial_time_order`、`ambiguous_meeting_ids/count` を保存。翌日以後は取得しない。同開催は除外し、古い開催へのfallbackやIDによる時間順推測をしない。
5. **preseal export registry whole-file access**: 実原本と照合したfixed bytes/SHA literalsを使用し、presealのcapture/analysisでは `report-export-manifest.json` とmeeting bundle全体manifestを開かない。seal後だけoutcome registryを開く。

新契約は `GROWTH-TREND-ANALYSIS-01-v3-PR62-REVIEW-FIX` と
`GROWTH-TREND-SCORE-SOURCE-01-v3-STRICT-OUTCOME-ISOLATION`。
Outer projection SHAは `0e8d516e0ad0349954276e911f4273932ef2c6198136cac6f432a979d9d94074`、
meeting projection SHAは `4b7cf990540583e98d4ff3431023f43b9048a92ee0642b36265a66233b463707`。

### 実行ID・保護・途中停止

- Source: `outer-c1-score-observations-2022-2025-pr62-review-fix-02`
- Analysis: `outer-c1-growth-trend-2024-2025-pr62-review-fix-02`
- rootは上記既存2rootのまま。ログ・比較・差分・小型報告はanalysis rootの `pr62-review-fix/` へ保存。
- 最初のexecuteはmeeting projectionのrowsキー欠落でstage作成前に停止。実bytes/SHAは一致し、既存projectionが含むrows=50078を維持するliteralへ修正した。
- 次のexecuteは旧source sealのrows付き配列とbytes/SHAだけの配列を比較したため停止。原本のbyte比較でDB driftでないことを確認し、bytes/SHAを比較するよう修正した。実比較経路の正常受入と1 byte改変拒否をテストした。
- review-fix-01の固定source・失敗stage・ログは保持し、両IDを02に変えて全工程を再実行。条件・grid・選択閾値を緩めた再試行ではない。
- 旧manifested成果物・manifest・LOCKED・ZIP計48ファイル、review-fix-01固定source/失敗stage計33ファイルはSTART/ENDでbytes/SHA不変。

### READ ONLY Source

50,078レース（2024=25,212、2025=24,866）、356,209出走、2,285選手、704,202得点観測。
許可4テーブルのみ、SELECT 2,836回、session/transaction read_only=on、statement_timeout=120000ms。
source START/END一致、本番write=0、結果table参照=0、2026実データaccess=0。
初回sourceと `score-observations.jsonl` / `targets.jsonl` はstreaming byte比較で完全一致した。
観測SHAは `7fcca14b68890aa5eaf1438c1b65c1536971cdb530a2f44b2746984d0392b73b`（242,130,373 bytes）。
targets SHAは `495e443a2c9c3fcb1d4612e82f61fa90adef237b8acc8e2427108bff7a693dd1`（26,167,993 bytes）。

### 同日・DAY影響と選択

| 年 | 対象出走 | 同日曖昧出走 | 別開催数 | 各候補の旧VALIDからPARTIAL_TIME_ORDERへの遷移 |
|---|---:|---:|---:|---:|
| 2024 | 179,089 | 8 | 4 | 8 |
| 2025 | 177,120 | 12 | 5 | 12 |

同日別開催IDは2024 `[1770,1781,2177,2185]`、2025 `[991,1000,1236,1282,1285]`。
全41候補で各年8/12件のraw/statusが変わり、計820の候補状態が変化した。対象出走は母集団から削除せず状態を残す。
全27 MEETING候補×2年=54、全14 DAY候補×2年=28の数値比較で差を検出。
source start=NULL観測0、distinct開催0、影響target0、DAY全28候補年の不明start監査数0。
`PRODUCTION_DAY_NULL_FIX_NUMERICALLY_INERT`。今回の数値差は同日境界修正によるもので、DAY NULLやDB driftとはしない。
入力hash差には境界監査項目の追加も含むため、provenance差と数値差を区別する。

selectedは旧/新とも **MEETING_DELTA_LAG_1**。
適格9候補も同一（DELTA lag1/2/3、OLS K2/3/4、Theil-Sen K3/4/5）。
`SELECTED_GRANULARITY_UNCHANGED_AFTER_PR62_REVIEW_FIX`。
ROBUST_RHOは `0.009639846634467018` から `0.00964758593957939`。

| 選択指標 | 2024 OLD | 2024 NEW | 2025 OLD | 2025 NEW |
|---|---:|---:|---:|---:|
| valid entries | 178649 | 178641 | 176712 | 176700 |
| normal entries | 176159 | 176151 | 174438 | 174426 |
| coverage | 0.9975431210180413 | 0.9974984504910966 | 0.9976964769647696 | 0.9976287262872628 |
| zero rate | 0.014654434113820957 | 0.014655090376789203 | 0.013994522160351306 | 0.013995472552348613 |
| overall rho | 0.01681435172089681 | 0.016818914942483613 | 0.017429850171727938 | 0.017423489109502702 |
| target-score conditional rho | 0.009957655433025169 | 0.009958391640029917 | 0.010034743641521814 | 0.01003207060685667 |
| C1 conditional rho | 0.009639846634467018 | 0.00964758593957939 | 0.012011709446469397 | 0.012002193068641713 |
| first observation rho | 0.022167226051511543 | 0.02215766110581462 | 0.025118128893760267 | 0.02509529728567305 |
| FP positive-minus-negative | 0.01537411969702851 | 0.015375363870042291 | 0.014376947416745112 | 0.014372323631059003 |
| first FP positive-minus-negative | 0.01950745070873955 | 0.019505596227860134 | 0.020243913249272993 | 0.02022805527224414 |

全41候補の未丸めold/new、eligible、数値差、原因は `review-fix-comparison.json` と報告書に保存。
C1正常予測分母の診断勝率は2024が10,456/24,884=0.42018968011573704、
2025が9,934/24,557=0.40452824042024677。全予測分母の参考率はそれぞれ0.4147231477074409、0.3995013271133274。
margin bin別にも両分母を保存している。C1の予測やモデルは変更していない。

### Isolation・再現・テスト

保護した実アクセス経路のpreseal counterはexport manifest open / label identity resolve / label file openが全て0。
postsealは各2。これはguarded resolution/iteration pathの呼出し数でありOS syscall全体の回数ではない。
registry・labels・sidecars・meeting全体manifestが物理的にない状態でもsealまで成功し、復帰後の結果評価も成功。
2024/2025 label・sidecar・registryの変更でもpreseal9成果物がbyte-identical。
入力・予測・得点観測・metadata・meetingsの改変は拒否する。
temporal sequenceはsource verified=1、projection=2、trend started=3、sealed=4、
2024 source resolved=6/open=7、2025 resolved=9/open=10、selection=11。

capture/verify/focused/execute/reproduceは `memory_limit=128M` 指定で成功。
capture peak34MiB、execute/reproduce peak36MiB。analysis/reproduceは `DB_CONNECTION=growth_disabled`、本番DB NONE。
ローカルSQLite spoolは使用する。全36生成物がBYTE_EXACT、再現所要1,368.006秒。
旧C1/Growth/scorer/decoder/evaluator、grid41候補・採用条件は不変。学習・再推論・正式Gate・bootstrapなし。

- focused: 81 tests /937 assertions。
- 関連回帰128M: 263 tests /1467 assertions。
- `php artisan test`: 1,641 passed /9 skipped /12,760 assertions（全体を128MB単一process成功とは扱わない）。
- 変更PHPのPint・php -l、git diff --checkは成功。
- 全体Pintは既存 `Bt03e08BoundedMemoryTest.php` のstatement_indentationのみ失敗、対象外で未変更。

現在の再検証対象は以下。実際のcapture/execute/verify/reproduceの全引数・終了コード・ログSHAは `pr62-review-fix/*.execution.json` に保存。

```bash
DB_CONNECTION=growth_disabled DB_URL= php -d memory_limit=128M artisan keirin:backtest:growth-trend-score-source --verify --output-root=/home/shinya/neo-keirin-artifacts/growth-trend-score-source-01-20260919-01 --source-id=outer-c1-score-observations-2022-2025-pr62-review-fix-02
DB_CONNECTION=growth_disabled DB_URL= php -d memory_limit=128M artisan keirin:backtest:growth-trend-analysis --reproduce --output-root=/home/shinya/neo-keirin-artifacts/growth-trend-analysis-01-20260919-01 --analysis-id=outer-c1-growth-trend-2024-2025-pr62-review-fix-02
```

2024/2025 development corpusで現在得点・C1 P1 probability条件付きでも弱い正方向関連を維持した、という範囲の結論。
予測精度改善済み・正式growth feature/STAT・因果効果・holdout成功・LIVE readyとはしない。
MASTER PLAN v1.22、次工程 `NOT_AUTHORIZED`。2026 FROZEN、未コミットレビュー待ちで停止する。
