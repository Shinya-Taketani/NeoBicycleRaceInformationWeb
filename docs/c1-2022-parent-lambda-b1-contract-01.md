# C1-2022-PARENT-LAMBDA-B1-CONTRACT-01

## 1. 目的・承認境界

2026-10-17。PR #114に続き、2022年内の開催単位前進検証B1を製造する前の実行契約案を記録する。**B1は第一推奨候補であり未承認。本書の作成・PR114のmergeは、方法の選択、境界のfreeze、製造、実学習、lambda選択を許可しない。**

2026-10-20 PR115レビュー修正：正式なB1の学習・検証・選択・受入は、保存済み2022年実競輪データだけを根拠にする。人工的な異常入力は第8.2節の安全性専用テストに限定し、実データ受入の代替にしない。今回も実行許可ではなく、実データの実物受入は `NOT_RUN`。初回のGit/作業終了記録は第2・12節、今回の確認時点は第13節へ分離する。

```yaml
current_phase: C1-2022-PARENT-LAMBDA-B1-CONTRACT-01
current_engine_state: PARENT_LAMBDA_B1_CONTRACT_DOCUMENTED_AWAITING_REVIEW
next_allowed_action: REVIEW_C1_2022_PARENT_LAMBDA_B1_CONTRACT_ONLY
method_b1_status: PROPOSED_NOT_APPROVED
method_selected: NONE
contract_frozen: false
candidate_artifact_state: 2023_CANDIDATES_GENERATED_PARENT_SELECTION_PENDING
selected_lambda: NONE
parent_selection_independent_of_2023_outcomes: NOT_ESTABLISHED
final_selected_2023_oof: NOT_ESTABLISHED
h1_u_training_use: PENDING_PARENT_SELECTION_REVIEW
historical_as_of_available: false
formal_adoption: false
prediction_use: NOT_AUTHORIZED
2026_holdout: FROZEN_NO_ACCESS
```

既存の最終development C1のlambda=0.1は保存済みモデルの事実であり、新しい2023年親lambdaの選択根拠ではない。今回も未選択であり、2023候補2系列を最終OOFと認定しない。的中精度の改善は未評価。

## 2. 初回設計時のGit・確認範囲・工程ゲート（2026-10-17）

開始cwdは `/var/www/NeoBicycleRaceInformationWeb`、clean main、HEADは `4298c3883c81d907bf13285f002893f6f93f77db`。originのfetch/push先は対象repositoryのHTTPS URLと一致した。`git fetch origin` 後のorigin/mainと一致し、`git switch main` / `git pull --ff-only` は各exit0、pullはAlready up to date。2026-10-17T16:03:35+09:00に同期結果を記録した。mainの件名はPR114 merge、親は `0d57393c10ff046505bf85ab232e104dcbe8d6d3` と `4ee1ddb89ea673f5cccc82277e05968e1f6ea481`、commit日時は2026-10-17T15:43:55+09:00。GitHubの現在のPRメタデータを再照会したという意味ではなく、fetchしたmainの実merge履歴とユーザーの報告の照合である。

指定branchのlocal/remote追跡参照が未使用であることを `git branch --list` / `git branch -r --list` で確認し、`git switch -c design/c1-2022-parent-lambda-b1-contract-01` はexit0。mainを編集せず、以後のHEADは開始SHAのまま。これらは今回確認時点の値であり、将来の最新mainを保証しない。

AGENTS.md全文、全文確認済みMASTER PLANとその後の全差分を照合し、現行の現在地・履歴・8工程ゲートを再確認した。[親選択成立性](c1-2023-parent-lambda-selection-feasibility-01.md)、[2023候補生成](c1-2023-oof-recovery-01.md)、[H1/H2成立性](c1-low-rank-calibration-feasibility-01.md)、[C1実験](tactical-history-01.md)、[最終fit](tactical-history-final-01.md)を参照した。保存成果物の再hash・対象再集計・モデル復元は今回行っていない。

| 制御項目 | 今回の扱い |
| --- | --- |
| current_engine_state | B1実行契約案の文書レビュー待ち。選択・学習完了ではない |
| next_allowed_action | 本書とMASTER PLAN差分のレビューだけ |
| completed_phases | PR113候補生成とPR114成立性文書のmergeを確認。既存の生成・学習・評価を再実行しない |
| superseded_phases | 旧BT-03D・粗整数点数化を復活させない |
| blocked_phases | B1製造/実行、親選択、H1-U、正式採用、BT-04/BT-05-LIVEは未承認 |
| frozen_contracts | 固定C1/STAT39/Solver/Decoder/Evaluator/Gate、時系列・欠損・異常結果・128M・artifact契約を維持 |
| unfrozen_contracts | 今回の案・境界・予算は未承認。既存最終C1の固定bin/係数/lambdaを未決へ戻さない |
| holdout_status | development=2022〜2025（既使用）。本選択手続の入力は2022だけ、2026は一切参照しない |

Category 1 MVPの未レビュー事項、旧C1_PLUS_FIELD_BIKE不採用、historical as-of未証明を独立して維持する。PR114の過去のOPEN・未コミット・検証実績は当時の履歴であり、本工程の実行実績へ読み替えない。

## 3. 実コードで確認した依存関係

以下は読取りによる実装確認で、実行成功の主張ではない。

| 根拠 | 確認した動作・B1での制限 |
| --- | --- |
| [Trainer](../app/Domain/Keirin/Backtest/Experiments/TacticalHistory/Trainer.php) | gridはlayout/train/pathを作り、検証raceごとの損失を保存するが、削除型loss spoolを直接newする。無条件再利用禁止。refitは予測も生成するので今回も将来のB1選択本体でも呼ばない |
| [Dataset](../app/Domain/Keirin/Backtest/Experiments/TacticalHistory/Dataset.php) | rawは年2022〜2025を受理するが暦日分割をしない。B1側で2022限定を追加保証。history=trueで12信号の後ろに4履歴を連結する |
| [LayoutBuilder](../app/Domain/Keirin/Backtest/Experiments/TacticalHistory/LayoutBuilder.php) / [Layout](../app/Domain/Keirin/Backtest/Experiments/TacticalHistory/Layout.php) | 渡されたsourceからbin/supportを作る。学習subsetだけを渡し、16 feature順と非active履歴groupを維持する |
| [Objective](../app/Domain/Keirin/Backtest/Experiments/TacticalHistory/Objective.php) | raceLossはteacher-forced条件付きNLL。制約付きprox、正規化L2/group/smoothness、順位別適格性を維持する |
| [Optimizer](../app/Domain/Keirin/Backtest/Experiments/TacticalHistory/Optimizer.php) / [SolverContract](../app/Domain/Keirin/Backtest/Experiments/TacticalHistory/SolverContract.php) | strong-to-weak、収束候補からだけwarm start、専用非収束例外だけ候補除外。generic RuntimeExceptionは伝播する |
| [OneSeSelector](../app/Domain/Keirin/Backtest/Calculators/Bt03e03OneSeSelector.php) / [Contract](../app/Domain/Keirin/Backtest/Services/Bt03e03Contract.php) | 年等重み/順位等重み、共有race重み、標本SD、固定gridとseed。private weightedAggregatesの引数が削除型spoolの具体型 |
| [ValidationLossSpool](../app/Domain/Keirin/Backtest/Support/Bt03e03ValidationLossSpool.php) | finalクラス。24値×8 bytes/race、欠損NLLはNaN符号化。cleanupとdestructorがunlinkするので、そのままのnew/継承adapterは不可 |
| [Experiment](../app/Domain/Keirin/Backtest/Experiments/TacticalHistory/Experiment.php) | 2023検証・Outer refit・labels開放へ進むためB1からrunしない |
| [SourceIntegrity](../app/Domain/Keirin/Backtest/Experiments/TacticalHistory/SourceIntegrity.php) / [SourceCheck](../app/Domain/Keirin/Backtest/Experiments/TacticalHistoryFinal/SourceCheck.php) | 前者は固定STATと履歴の両callbackを要求、後者は実DBへ接続する。今回も将来のオフラインB1もSourceCheckは起動しない。ファイルseal確認をDB現在状態の再検証と偽らない |

[旧外部bin provider](../app/Domain/Keirin/Backtest/Calculators/ExternalSortEffectBinBoundaryProvider.php)のtempnam/finally/unlink経路も確認した。[保持型bin provider](../app/Domain/Keirin/Backtest/Experiments/Stat35Trend6P1/RetainedBinBoundaryProvider.php)と[作成記録付きディレクトリ](../app/Domain/Keirin/Backtest/Experiments/Stat35Trend6P1/RetainedSpoolDirectory.php)、同namespaceの[保持型loss spool](../app/Domain/Keirin/Backtest/Experiments/Stat35Trend6P1/ValidationLossSpool.php)・[One-SE](../app/Domain/Keirin/Backtest/Experiments/Stat35Trend6P1/OneSeSelector.php)には既存の非削除例がある。ただし別実験の計算全体を呼ぶ許可ではなく、loss spool自体の所有権/path検査がB1で十分かも未検証。既存クラスを一括変更しない。

## 4. B1分割契約案

基準はAsia/Tokyoの暦日、begin=2022-01-01、cut=2022-07-01、until=2023-01-01。開催終了日はinclusiveな保存日付として、次のstrict比較を使う。発走時刻・input_as_ofで境界を代替しない。

| 割当 | 条件 | PR114の過去確認値: 開催 / race / entry |
| --- | --- | ---: |
| TRAIN | meeting_start >= begin かつ meeting_end < cut | 408 / 11926 / 83516 |
| VALIDATION | meeting_start >= cut かつ meeting_end < until | 421 / 12282 / 85958 |
| HELD_OUT_OF_FOLD | 上のどちらでもない、境界跨ぎの有効な開催 | 8 / 186 / 1361 |
| 全固定対象 | 3区分の和 | 837 / 24394 / 170835 |

**件数はPR114のmetadata確認値の引用であり、今回の再実測でも未来の学習成功件数でもない。** 保留内訳は年初跨ぎ2開催/29race/191entry、7月跨ぎ3/120/924、年末跨ぎ3/37/246。将来は同じsealの資料から独立に再照合し、不一致を黙って許容したり、この表に合わせて行を調整したりしない。

### 対象・投影・照合

参照候補は従来root `/home/shinya/neo-keirin-artifacts/tactical-history-01-review-fix-20260916-01` の固定 `inputs-v2/inputs-2022.jsonl` と `history-2022.jsonl`。親manifest、年別子manifest、元の完了記録・export sealを検証してから使う。旧rootにCOMPLETEが元からあると仮定せず、保存契約上の実名を確認する。本工程ではこれらを開かない。

metadataはhistory.targetの `race_id, entry_id, bike, race_date, meeting_id, meeting_start, meeting_end, input_as_of` だけ投影する。source rows、履歴集計値、rank/statusを分割に使わない。開催IDは全行で一つの期間に対応し、一つのraceは一つの開催に属する。同日程の別開催を統合せず、開催IDの反復出現自体は正常な所属として扱う。

元入力とのrace/entry/bike・型・順序、同race内の日付/期間、race_dateが2022年かつ開催期間内、同meeting IDの期間一意性を検査する。非連続のrace再出現、entry重複、race内bike重複、欠落/未知field、期間逆転・欠損・帰属不一致はSTOP。race ID昇順を新たに要求せず、全体のID検査は専用の保持型disk索引等でboundedに行う。

fold割当を教師なしで先にsealし、その後固定2022入力を逐次分配する。各raceの全entryを同じ区分に置き、元の値・型・順序・NULLを保持する。train/validationのmeeting/race/entry集合の交差は空、両subsetと保留台帳は固定cohortを過不足なく一回ずつ覆う。欠損・不一致を単なる境界保留へ変換しない。同じ選手の両期間への登場は開催リークとは別で、除外しない。

2023年に跨る開催終了日のmetadataは2022対象に付随する期間情報に限る。2023年race/教師を追加で読むことを意味しない。日時は暦日比較の範囲であり、保存input_as_ofを当時の発走/公開時刻の証明としない。

### 実データ由来の受入契約（PR115修正）

唯一の正式なデータ源は、実際の2022年競輪の開催・race・出走者、そこから取得/計算して固定保存したSTAT・履歴特徴量、実際の公式着順/結果状態である。PR114の24,394race/170,835entry/837開催は過去の確認記録で、今回再計測していない。実データであることは当時の発走前利用可能性の証明と別であり、historical_as_of_available=falseを維持する。

正式対象の相対パスは上記root下の `inputs-v2/inputs-2022.jsonl` と `inputs-v2/history-2022.jsonl`。両方を同じ固定保存版へ結び付け、別のsnapshot/年度や現在DBの値で差し替えない。

将来の別途承認された受入では、上記2ファイルの実物存在/読取り可否/size/SHA/行数、対応する入力manifest・年別子seal・export・固定契約・元の完了記録を確認する。入力provenanceから取得元識別子/保存元/source seal/生成時コードと処理履歴をたどり、実際の開催ID/期間・race/entry/bikeに一致する保存元証拠との対応を検証する。自己申告のorigin文字列、文書に書かれた件数/hashだけで実データ由来と認定しない。元証拠の所在と照合範囲を記録し、必要な対応が確認できなければSTOPする。DB接続・HTTP/Raw再取得で自動補完しない。

ランダムrace/特徴量、架空選手/着順、人工NLL、欠損の推測補完、ダミーレコード、件数合わせの水増し、別年の結果の2022年への流用は禁止。契約上正当なNULL・順位別除外は保持するが、必須資料/識別/provenance/sealの欠落・不整合や評価成立条件不足は記録して停止する。資料不足を人工データへfallbackせず、復元・追加取得・対象変更等は必要な追加承認を確認する。今回、保存rootの実物は開かず、以上は未実施の受入要件である。

## 5. 学習契約案と方法Aとの差

方法A1は全2022学習済みモデル・収束情報だけを用いる事前の正則化優先規則案で、新規fitを要しないが性能優位を証明しない。B1は前半で**新規に**8候補pathを学習し、後半NLLで選ぶ。全2022の保存layout/係数を前半fitに流用しない。A/Bのどちらも今回選ばず、B1不成立後に結果を見てAへ切替えるfallbackも禁止する。

B1の学習は前半の固定実データと実教師だけ、検証NLLは後半の実際の公式着順・結果状態から既存Objectiveで算出する。人工ラベル/損失を使わず、検証教師をlayout/bin/support/係数学習へ混入させない。第4節の408/421/8開催等は過去metadata値として、将来の実物受入時に照合する。独立2runも同じ固定実データを使い、別途承認後にのみ実行する。

固定の追加16特徴は次の順序。STAT-01 RACE_SCORE_Zは別のanchor係数1であり、17個目の学習groupにはしない。

```text
STAT-07, STAT-08, STAT-10, STAT-11, STAT-12, STAT-23,
STAT-24, STAT-26, STAT-31, STAT-32, STAT-39, STAT-42,
HIST_OBSERVED_ESCAPE_TOP2_COUNT_120D_PRE_MEETING,
HIST_OBSERVED_MAKURI_TOP2_COUNT_120D_PRE_MEETING,
HIST_OBSERVED_SASHI_TOP2_COUNT_120D_PRE_MEETING,
HIST_OBSERVED_MARK_TOP2_COUNT_120D_PRE_MEETING
```

| 項目 | 維持する契約 |
| --- | --- |
| Solver / Model | TACTICAL-HISTORY-CONSTRAINED-EUCLIDEAN-FISTA-v2 / TACTICAL-HISTORY-SEQUENTIAL-POSITION-v2 |
| lambda grid（格納・選択順） | [0, 1e-6, 1e-5, 1e-4, 1e-3, 1e-2, 1e-1, 1]、keyは既存sprintf('%.17g', lambda) |
| path順 | [1, 1e-1, 1e-2, 1e-3, 1e-4, 1e-5, 1e-6, 0]、全3順位収束候補からだけ次候補へwarm start |
| 更新・収束 | 200 accepted updates、係数変化/近接勾配mapping/中心化残差 <= 1e-7、相対目的関数変化 <= 1e-10、mapping reference step=1 |
| line search / restart | initial step=1、backtrack=0.5、既存MAX_LINE_SEARCH_STEPS=24と試行順を維持。restart後の有意な目的関数増加を受理しない |
| layout/bin/support | 前半だけ。未知category/NULL、空履歴group・support=0 binの非active扱い、中心化・active numeric edgeは既存Layoutと同じ |
| 適格性 | FINISHED/TIEDの公式rank1、rank1/2、rank1/2/3が各一意なraceをそれぞれP1/P2/P3に使用。P2/P3では実上位を条件付けの候補集合から除く |
| 教師・欠損 | training教師は前半、validation教師は後半だけ。未知・失格等を最下位/負けへ補完せず、観測0とNULLを区別し、順位別適格/除外数を残す |

保存特徴量の開催前120日窓・同開催除外と欠損処理は変更しない。後半入力がその開催より前の後半履歴を含むrolling観測であることと、対象自身・未来教師の流入を区別する。入力再計算/履歴補完はしない。対象一意性がない順位のraceLoss=NULLと、計算エラー/非有限値は別で、後者をNULLへ隠さない。

NUMERICALLY_NON_CONVERGEDは候補の診断を保存し、One-SEから除外して固定pathを続ける既存契約。全3順位CONVERGEDの候補が0なら全体STOP。generic例外・line search異常・入力不正は候補除外で握り潰さず全体STOP。反復追加、閾値緩和、grid縮小、候補置換、同条件自動再試行は禁止。全2022で0.1/1が収束した過去実績は前半subsetの収束保証ではない。

## 6. 単一層One-SE契約案

検証層keyは2022で一つだけ。元の複数年year-equal設計と数値集約の形式は共通にできるが、検証母集団は「2022年後半一期間」であり同一の検証設計ではない。境界や層を成績に合わせて変更しない。

後半の全raceを保存順にR、順位pで既存Objectiveが適格とした集合をE_p、候補lambdaのraceLossをl(r,lambda,p)とする。正則化項は検証NLLへ加算しない。

```text
L(lambda,p) = sum_{r in E_p} l(r,lambda,p) / |E_p|
L(lambda) = (L(lambda,1) + L(lambda,2) + L(lambda,3)) / 3
```

全3順位収束候補だけをcanonical grid順で扱い、nullの適格maskは候補間で同一であることを事前検査する。順位ごとの分母が0ならSTOP。順位の適格出走数やrace数を混ぜて加重しない。

bootstrapは2,000回、seed=20260812、既存 [DeterministicRandom](../app/Domain/Keirin/Backtest/Calculators/DeterministicRandom.php)。各回、Rから|R|回復元抽出し、**同じrace重みを全候補・全順位に共有**する。順位ごとに非NULL行の重み和で割り、3順位を等重み集約。Rには順位別不適格行も残し、順位別に別抽選しない。複数年層の平均は今回一層だけなので自明な一平均となる。

SEはbootstrapのLの標本標準偏差（分母1999）。2000の平方根で再除算しない。最小Lの候補をbestとし、完全同値なら既存の昇順grid・strict `<` 更新に従い最小lambdaをbestにする。閾値は **L(best)+SE(best)**。L(lambda)が閾値以下（inclusive `<=`）の候補の最大lambdaを選ぶ。各候補自身のSEを足す規則へ変えない。これはSE規則であり、Type7のCI計算ではない。

どれかのreplicateで順位別適格重みが0、非有限値、race行数/順序/候補mask不一致ならSTOP。replicateを捨てる・引き直す・seedを変える・回数を減らすことは禁止。浮動小数演算の加算順・varianceのcompensated sum・乱数の呼出し順も凍結実装と一致させる。

race-clusterは同race内の3順位・候補対応を保つが、同一開催内race間や同じ選手の反復相関を補正しない。単一の半年には年変動を推定する層もなく、短窓・季節差・少数の適格raceではSEや選択の安定性に限界がある。感度分析/開催bootstrapは別の設計候補にとどめ、今回は計算・採用しない。NLLでの選択は的中率/Gate通過の保証ではない。

## 7. 安全なspool・保存・cleanupの設計案

現行のままでは **BLOCKED_UNSAFE_LEGACY_SPOOL_PATH**。既存Trainer/grid・削除型Spool・その型に依存するSelectorをそのまま起動しない。凍結クラスは変更せず、将来の限定B1 orchestrationに、保持型loss I/Oと同じ演算の選択器接続を用意する案とする。finalクラスの継承や型チェック迂回を解決策にしない。既存Stat35Trend6P1の保持型実装は参考/再利用候補だが、型境界・所有権・再生契約の安全性専用テストと、2022年固定実データによる正式受入の両方が成立するまで **NOT_IMPLEMENTED_OR_VERIFIED_FOR_B1**。人工的な異常系テストの合格だけで受入済みにしない。

| 対象 | 製造前に満たす要件 |
| --- | --- |
| run root | 既存合意の `/home/shinya/neo-keirin-artifacts/` 配下の承認済みB1専用rootに一意な新run。具体的rootは承認時に確定し、今回は作らない。旧rootの子へ追加せず、umask077/dir0700/file0600 |
| 作成所有権 | 呼出し元が新規作成時にpath/uid/PID/device/inode/token/時刻を排他記録。空文字、`.`、`..`、相対/未解決path、repo/上位/home/Trash自体、symlink/hardlink/特殊file/未許可mountを操作前に拒否 |
| ファイル作成 | 検証済み専用dir内で排他的に生成、lstat/fstat・作成記録・device/inodeを照合。既存path/リンク拒否。tempnam/sys_get_temp_dir fallback、追記先推測、既存file上書きは不可 |
| bin spool | 記録済み専用bin dirへ保持型providerを接続。LC_ALL=C・外部sort・Type7境界・supportの数値仕様を維持。sortのspill先も専用dirに限定、外部sortの内部一時file処理も含め削除経路と保持要件の適合性を製造前に検証し、不成立なら起動しない |
| loss spool | 8候補×3順位のcanonical順・1race=192 bytes、既存の有限NLL/NULL符号化を維持。候補mask/row数/bytes/SHA/対応race順序のsidecarを保存。新I/Oはclose-only、cleanup/destructor/finallyからunlinkしない |
| 永続記録 | validation-losses.jsonlとbinary spoolを両方保持し、候補状態・position除外理由・行順と相互照合。train/subset/layout/path/model/診断/選択台帳/入力・コードsealも保持 |
| 完了・失敗 | 全書込みは新規専用stage内。flush/fsync、閉鎖、seal・独立検査・START/END一致後だけmanifest/COMPLETEで公開。例外時はpartialとFAILED/診断を残す。失敗やkillでFAILEDを書けなくてもCOMPLETEなしは未完了、再開で成功と推定しない |
| 再実行 | run ID/既存path衝突ならSTOP。完了/失敗runを上書きして再開しない。独立run-02は再現用であり標本の追加・不調runの置換ではない |

既存 [JsonlArtifact](../app/Domain/Keirin/Backtest/Experiments/TacticalHistory/JsonlArtifact.php)は新規partial/manifestの参考だが、それだけでroot所有権・symlink・競合時の公開保護が満たされるとはしない。新規stageの単独所有と公開前検証を別に保証する。数値互換と安全な保持のどちらかを省略して実行しない。

別processでのloss再生は、元pathをwriterのconstructorへ渡して再作成しない。保存manifest/作成記録/実体size・SHA・race順を検証するread-only readerを分離し、creator PIDが違うことを理由に元資料を再登録・再sealしない。将来の独立検証もこの保存資料だけで再生可能にする。

本実験run/spoolは監査資料としてその場に保持し、自動cleanupしない。安全性専用テストで作成した人工一時ファイルを退避する場合だけ既存 [CompositionResultTemporaryDirectory](../tests/Support/CompositionResultTemporaryDirectory.php)の作成オブジェクトを保持し、path()だけをretireへ渡す。人工Desktop/Trashを隔離環境で使用し、実Desktopの既存資料を操作しない。TEST_COMPLETE/TEST_FAILUREを区別し、parent teardownはfinallyで実行。退避は `/home/shinya/Desktop/Trash/` 内の一意な専用先への同一filesystem rename-only、記録一致が必須。失敗時は元を保持して停止し、削除/copy-deleteへfallbackしない。実データ原本への破損・改変・削除・移動試験は禁止する。

## 8. 実データによる受入検証・安全性専用テスト・独立2実行

以下は将来の別途承認が必要な検証計画。今回の全項目は `NOT_RUN` とし、テスト作成・実行、fit、NLL/One-SE計算を行わない。初回設計で参照した既存 [One-SE/Optimizer試験](../tests/Unit/Domain/Keirin/Backtest/Bt03e03OptimizerSelectionTest.php)、[保持I/O数値互換試験](../tests/Unit/Domain/Keirin/Backtest/Stat35Trend6P1NumericsTest.php)、[両source検査試験](../tests/Unit/Domain/Keirin/Backtest/TacticalHistorySourceIntegrityTest.php)はコード理解の根拠であり、B1実データ受入の代替ではない。旧cleanup/unlink経路を無検証に起動しない。

### 8.1 実データ受入（必須・別途承認後）

1. 第4節の固定2022入力/historyと対応manifest/export/契約/source sealの実物存在・size/SHA・保存版を照合する。
2. 実際の取得元・保存元・処理履歴/provenanceを追跡し、保存元証拠との対応を検証する。文書の宣言だけで済ませない。
3. 実際の開催ID/開始・終了日で第4節のfoldへ割り当て、年初/7月/年末跨ぎを保留台帳へ保存する。
4. 元入力とのrace/entry/bike・順序・型・期間の一意性と対応を検証する。偽のrace/選手/着順を作って正常系の代わりにしない。
5. train/validation/保留の交差0・全固定対象の被覆・各raceの全entry所属を確認し、元の値/順序/NULLを保持する。
6. 前半の実データだけでlayout/bin/supportと固定8候補pathを新規生成し、200更新・既存の最適性/中心化/全順位収束条件・非収束除外を検証する。
7. 後半の実際の公式着順/結果状態から順位別NLLを計算する。実データ由来の同じ保存loss列でI/O・候補mask・選択器のpoint/SE/best/threshold/selectedの数値互換を検証し、人工lossや結果合わせの改変はしない。境界/tieの実例がなければ未確認理由を記録し、人工の実績に置き換えない。
8. 実データの全対象を独立PHP本体128Mでstreaming処理し、外部sortを含む専用ディスクI/O・保持・例外経路の資源/保存制約を確認する。129Mへの緩和は禁止する。
9. 同じ承認済み契約と固定実データで独立2runを実行し、下記の数値/成果物の再現性を確認する。
10. 固定入力/履歴metadata・依存code・生成成果物のSTART/END seal、保存後の独立再生・公開前manifest整合を確認する。

検証記録には項目別status、実測の開催/race/entry件数、順位別適格/除外数、NULL件数、例外件数、処理時間、PHP peak memory/RSS、終了コード、入力・コード・成果物のSHAと照合範囲を残す。未実行はNOT_RUNでありPASSにしない。旧実績の件数やmemory値を今回の実測として転記しない。元の正当なNULL/異常状態/同着の適格規則は変えない。

後日の実データ独立2runは、同じコード/入力/契約seal・乱数・環境で新規processから開始し、fold台帳/部分入力/layout/bin/support/候補状態/係数/診断/NLL/選択結果の意味内容と固定直列化SHAを一致させる。日時・PID・絶対run path・spoolランダム名・inode等の実行証拠は別manifestとし、一致対象から外す項目を**実行前**に固定する。都合の悪い係数/診断を後から除外しない。各runの固有ファイルも全size/SHAを記録する。

### 8.2 異常系・削除安全性テスト（補助）

例外として、破損・途中切断・不正path・symlink/hardlink・mount境界・権限不備・file衝突・書込み/flush/rename失敗・削除経路到達・異常終了/後片付けは、実原本と隔離したテスト専用ファイル/人工的な異常入力だけで確認してよい。これ以外の正常な競輪race/選手/特徴量/教師/損失を人工生成して正式処理の代わりにしない。

安全性の判定は、拒否/停止、既存資料への操作0、spool保持、記録済み人工pathだけのrename-only退避、移動失敗時の元保持に限定する。`.`/repo/Trashを指定しても移動/削除0で拒否し、実repo・実保存資料を動かす試験はしない。合格を実データ受入・B1学習・予測性能の成功と称さない。正式な処理の検証は8.1の実データで別に行う。

### 8.3 実データが確認できない場合

確認対象file/必要な元資料/確認できたprovenance/不足・不整合の種類/確認済みと未確認の範囲/取得・復元等に必要な追加承認を保存してSTOPする。人工データ生成、ダミー/別年への置換、実績に合わせる暫定代替、対象の無断追加は禁止する。今回の文書修正を取得・復元の許可にしない。

## 9. 2023年以降の結果からの隔離案

既存Experiment/FinalFitService/SourceCheck/Artisan全体を起動せず、新しい限定runnerの依存をstatic allowlist化する。単に原本をread-only mountするだけでは読取り隔離にならない。

1. prepareはseal済み2022入力・2022 targetだけを個別ro-bindし、教師を見ないfold台帳を先に固定。入力のID/型/許可fieldを検査してtrain/validation教師を別ファイルへ分配する。既存root全体を子processへ公開しない。
2. fit processへは前半subsetと固定契約/codeだけを渡す。後半教師、全2022学習モデル、2023〜2025教師/損失/予測/Outer選択/最終fit選択は見せない。layout/model/pathをsealしてからvalidation processへ開放する。
3. validation processは保存された前半fit・後半入力/教師だけでraceLossを算出し、新loss束をsealする。選択processには新loss/適格candidate診断/規則だけを渡し、親lambda台帳をSTART/END検査後にsealする。
4. 既存root群・2023生成root・最終fit・.env/storage・DB socket・2026を不可視にし、network namespaceと環境変数/認証情報も遮断する。必要なcode/vendorのread-only allowlistと今回専用出力だけを公開し、実ファイルopen監査と拒否試験で確認する。コード/外部sort/乱数実装と起動argvもseal対象。
5. 2023保存候補の読取り/対応付けは選択seal後の別process・別承認範囲でのみ行う。結果教師はそのprocessへも渡さない。

SourceIntegrityの「固定STATと履歴の両方が必要」という保護趣旨は維持し、将来B1では固定入力/履歴metadataの**両保存seal**をSTART/ENDで確認する。SourceCheckによるDB現在値の再検証は許可しない。過去のDB照合成功は過去の記録として参照し、今回や将来のoffline確認を同じ保証範囲へ拡張しない。

研究者は既にdevelopment結果を知っている。技術的な選択器入力の2022限定は、当時から結果を知らずに手法を選んだ証明にはならない。2023候補は自動的に未使用holdoutにならず、historical_as_of_available=falseも解消しない。

## 10. 保存2023候補の再利用契約案

以下の全成立後だけ、新規の参照台帳による対応付けを検討する。今回は実行しない。

1. B1方法・境界・One-SE・spool/隔離・予算が事前承認され、2023以降の結果を使わず選択を完了・sealし、独立2runが一致する。
2. 選択lambdaが0.1または1である。これは8候補の探索結果に対する再利用条件であり、gridを既存2候補へ縮める事前制限ではない。
3. 再利用する親は元 `run-01/C1-inner-A` の**full-2022**モデル。前半foldモデルとは別物であり、fold係数やlayoutとの一致を要求/偽装しない。保存full-2022親同士で学習年/元入力/16 feature順/anchor/版/layout/bin/support/係数/全順位診断/provenanceが厳密一致する。
4. [PR113生成記録](c1-2023-oof-recovery-01.md)の2023投影入力、race/entry/bike順序、model/provenance、予測/全joint/utility/Primary、manifest/COMPLETEの全依存sealを実物で検証する。文書のhash引用だけを受入検証の代わりにしない。
5. 既存manifest/COMPLETE/model/予測は変更・再sealせず、新規selection/reuse台帳から親SHAと論理役割を参照する。run-02は再現証拠に限り、標本へ重複加算しない。

別lambda、適格full-2022親なし、seal/版不一致ならSTOP。既存0.1/1への置換・混合・近似、新規full-2022 refit、2023再予測へ自動移行しない。候補確率の再利用可能性と最終OOF/H1-U使用承認は別であり、本工程ではいずれも認定しない。

## 11. 実行予算とSTOP条件案（未承認）

PR114の1〜2時間/2GiBは独立2実行を想定した推定値で、今回の計測・保証・許可済み予算ではない。次をレビュー用の上限案とし、承認前に実機容量/安全性を確認する。

| 計画対象 | 1run案 | 独立2run案 |
| --- | --- | --- |
| fit | 1 path・固定8候補、最大24順位fit試行/4800 accepted updates | 最大48順位fit試行/9600更新 |
| validation / One-SE | 後半だけのNLL、2000 bootstrap。前半fitと合わせる | 同じ手順を別process/新出力で2回まで |
| 時間 | prepare/fit/validation/One-SE/終了検査込み最大60分 | 合計最大120分。1run目成功後だけ2run目へ。失敗・超過の自動再試行なし |
| 保存容量 | 目安1GiB。既存rootと同一の承認済み保存filesystem | 目安2GiB＋安全余裕。実計算のファイル見積りが超える場合は開始しない |
| PHPメモリ | 本体 `memory_limit=128M`（134217728 bytes）、streaming | 各独立processで適用、RSSも記録。sortには専用spill先・別途資源予算が必要 |

192 bytes/raceのloss本体は引用件数12282なら2,358,144 bytes/runという**計画上の算術値**。subset/train/path/保持bin spool/索引/log/manifestとsort作業領域は別であり、この数値だけで容量を保証しない。承認時には推定2GiBに加えて少なくとも1GiBの空き余裕を確保し、実見積りが大きければ大きい方を採用する案。今回は空き容量や負荷を計測していない。

開始前に許可・隔離・read-only・path/所有権・source/code seal・対象分割・必要容量を全て検証する。途中の時間/容量監視は親runnerで行い、上限到達時は自分の記録済み子processだけを停止しpartial/診断を保持する設計が必要。他process・サービスを停止しない。kill後にCOMPLETEを補わない。

即時STOPは、入力/metadata不一致、source drift、許可外year/path/通信、unsafe cleanup到達、非有限NLL/モデル、全候補非収束、順位別適格0、bootstrap不成立、出力衝突、書込み/flush失敗、時間/容量/128M超過、2run不一致。候補単位の数値非収束だけは第5節の固定path内除外であり、条件を緩める理由にしない。未評価を性能FAILや差0へ変換しない。

## 12. 初回設計終了時の検証・残る承認・終了（2026-10-17の履歴）

以下は初回作業終了時の記録。件数・静的検証・当時の人工受入計画・未コミット終了は当時の事実として保持する。正式受入の現行方針は第4・7・8節、PR作成後の修正記録は第13節を参照する。

今回の作業はコード/文書読取り、Git同期/新branch、2Markdown作成・静的検証だけ。実学習/refit/lambda選択/予測/損失・bootstrap計算/性能評価/保存原本再検証/DB/HTTP収集/Raw/2026/バックアップ操作/旧資料移動・削除は0。PHPUnit/Pint/アプリ起動は設計限定のため実施しない。

文書検証は `python3 -B` の読取り専用stdinスクリプトと既存PyYAML 6.0.3でexit0。両文書のYAML49ブロック、現状態15項目の一致、旧YAML46ブロック/過去工程本文/全旧version履歴/記載済みSHA-256の保持、相対リンク34件、UTF-8/見出し/fence/末尾空白、引用件数の算術整合と許可2ファイル限定を確認した。`git diff --check`はexit0。新規文書は `git diff --no-index --check -- /dev/null docs/c1-2022-parent-lambda-b1-contract-01.md` で警告なし（新規差分があるためexit1）。これはB1の実行/数値/メモリ試験ではない。

コードを読んだことと、B1の安全性・収束・128M・再現性を実行検証したことを区別する。現状の実行可否は `BLOCKED_PENDING_APPROVAL_AND_SAFE_B1_IMPLEMENTATION`。契約案は記述済みでも、製造済み/設計凍結済み/学習成功ではない。

残る承認は、A/B1の方法選択、暦日境界・保留規則、単一検証層One-SEの統計的解釈、B1専用保持I/O・selector接続・sort内部一時file管理、隔離/公開/人工受入試験、独立2runの比較仕様、具体的保存root・時間/容量予算、将来の2023再利用/認定範囲。今回の指示をこれらの承認へ読み替えない。

次は本書とMASTER PLAN v1.73差分のユーザー/ChatGPTレビューだけ。未コミットで停止し、commit/push/PR作成/mergeや次工程への自動移行は行わない。

## 13. PR115レビュー修正：実データ利用とGit状態の時点分離（2026-10-20）

初回契約にあった「人工環境だけ」の製造受入を、2022年固定実データによる正式受入と安全性専用の異常系テストに分離した。Solver/Model/feature/grid/教師適格性/分割条件/One-SEの数値規則・予算案・第1節の凍結値は不変。方法/製造/実行/選択/評価の承認は追加していない。

| 時点 | 確認した状態 |
| --- | --- |
| 2026-10-17 初回設計終了 | 2Markdownの未コミット差分、Codexはcommit/push/PR作成をせず終了した（第12節） |
| その後のユーザー操作 | 文書は `675d34190dea806704156c9998a1e66d9e1a9992` としてcommit・pushされ、PR115作成済み。今回のlocal/remote HEADとGitHubメタデータに一致 |
| 今回の修正開始 | 同じ既存branch、HEADは上記675d341、clean。新branchを作らない |
| 2026-10-20T06:14:38+09:00 照合記録 | fetch/ls-remote/追跡参照/local mainは `4298c3883c81d907bf13285f002893f6f93f77db`。GitHub PR115はOPEN/merged=false、base=同main、head=上記675d341、head repo/branchも対象と一致 |
| 2026-10-20T06:15:04+09:00 同期後確認 | mainのpull --ff-onlyはAlready up to date、同じPR branchへ復帰、clean/HEAD不変 |
| 今回の修正終了 | 既存2Markdownの新しい未コミット差分で再レビュー待ち。今回のcommit/push/PR更新/mergeは行わない |

`pwd`、status/branch/HEAD、`git remote -v`、`git fetch origin`、`git ls-remote origin refs/heads/main refs/heads/design/c1-2022-parent-lambda-b1-contract-01`、追跡参照/local main/merge-base確認は各exit0。originのfetch/push URLは対象repoと一致し、merge-baseは同mainだった。GitHub `get_pr_info(..., pr_number=115)` はisError=false。記録日時は結果照合時点で、通信ごとの所要時間ではない。GitHubのmerge_commit_shaをmerge済みの根拠にしない。

Section 26の手順1〜5はclean状態で実施し、switch main/pull --ff-only/HEAD確認/既存branch復帰も各exit0。手順6は今回の既存PR修正指示に従い新branch作成でなく復帰とした。main編集、PR branchへのmerge/rebase/cherry-pickはない。上記SHAは確認日時に結び付く履歴値であり、将来の最新HEADとして使わない。

2022保存root・実入力/history・manifest/export/source sealの実物再確認は今回不要のため行っていない。今回確認したのはGitとリポジトリの指示/工程文書だけで、実物の存在・provenance・seal・元資料対応の正式受入はNOT_RUN。過去の24394/170835/837、408/421/8開催等を今回の測定値にしない。安全性専用テストもNOT_RUN。DB/HTTP再取得/Raw/2026、学習/refit/予測/NLL/One-SE/性能評価、既存モデル・成果物操作、テスト/Pint/アプリ起動は未実施。

今回の文書静的検査は読取り専用 `python3 -B` / 既存PyYAML 6.0.3でexit0。YAML49ブロック、指定凍結14項目、正式受入10項目、引継ぎ4時点、相対リンク33件、UTF-8/fence/末尾空白、旧数値規則・初回実行記録・15.79以前の工程本文・既存SHA-256の保持、既存branch/head/baseと許可2文書限定を確認した。`git diff --check`もexit0。保存実データの受入検証やテスト実行とは別の結果である。

次はPR115の2文書差分をChatGPTへ再レビュー提示し、ユーザーのマージ可否判断を待つだけ。親lambda未選択、H1-U保留、historical_as_of=false、prediction_use=NOT_AUTHORIZED、正式採用/2026/LIVE禁止を維持し、次工程へ自動移行しない。
