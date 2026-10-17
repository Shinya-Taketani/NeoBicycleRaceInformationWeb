# C1-2023-PARENT-LAMBDA-SELECTION-FEASIBILITY-01

> Section 1〜11はPR114初回設計時の記録。今回のP2レビュー修正で確認したGitHubリモート状態と同期結果はSection 12へ分離する。初回のfetch/外部照会なしや未コミット終了を、今回の操作・現在HEADと混同しない。

## 1. 目的・開始状態・結論

2026-10-17。2023年結果を使わないC1親lambda選択の成立性と仕様案を確認した。これは設計レビュー用であり、選択規則の承認・凍結・選択実行ではない。的中精度の改善は未検証。

開始時はclean main、HEADとローカルorigin/mainはともに `0d57393c10ff046505bf85ab232e104dcbe8d6d3`。同commitの親は `447b096` と `bc53fb5`、件名はPR #113のmerge。ユーザーのマージ報告とローカル履歴が一致した。fetch・外部照会はしていない。mainから未使用の `design/c1-2023-parent-lambda-selection-feasibility-01` を新規作成し、HEADは開始SHAのまま。

| 判定 | 範囲・条件 |
| --- | --- |
| PARENT_SELECTION_METHOD_A_FEASIBLE | A1は2022年モデル・固定grid・収束状態だけを入力に決定的な規則を定義可能。実際のlambdaは未選択、性能上の優位性は証明しない |
| PARENT_SELECTION_METHOD_B_FEASIBLE | 保存metadataから開催単位の暦日分割を設計可能。新しい年内検証の編成・One-SEの解釈・安全なspool経路の承認が必要で、実装/学習の成功確認ではない |
| PARENT_SELECTION_REQUIRES_NEW_2022_FITS | 推奨するBを採る場合。既存の全2022学習モデルを年内foldの学習済みモデルとして流用できない |

開催日・開催IDの対応が確認できたため、暦日・開催単位の案について `BLOCKED_TEMPORAL_METADATA` とはしない。ただし、発走予定日時の証明が必要な日内分割は未成立。固定ソースや凍結計算契約を変更する必要があると判明した場合は別途STOPし、今回の成立性判定を包括的な製造許可にしない。

```ini
workflow_status=C1_2023_PARENT_LAMBDA_SELECTION_FEASIBILITY_READY_FOR_REVIEW
current_engine_state=PARENT_LAMBDA_SELECTION_FEASIBILITY_DOCUMENTED_AWAITING_REVIEW
next_allowed_action=REVIEW_C1_2023_PARENT_LAMBDA_SELECTION_FEASIBILITY_ONLY
candidate_artifact_state=2023_CANDIDATES_GENERATED_PARENT_SELECTION_PENDING
selected_lambda=NONE
parent_selection_independent_of_2023_outcomes=NOT_ESTABLISHED
final_selected_2023_oof=NOT_ESTABLISHED
h1_u_training_use=PENDING_PARENT_SELECTION_REVIEW
historical_as_of_available=false
formal_adoption=false
prediction_use=NOT_AUTHORIZED
2026_holdout=FROZEN_NO_ACCESS
```

## 2. 確認範囲と8工程ゲート

AGENTS.mdとMASTER PLANを全文確認した。関連報告は[2023候補生成](c1-2023-oof-recovery-01.md)、[H1/H2成立性](c1-low-rank-calibration-feasibility-01.md)、[Tactical History](tactical-history-01.md)、[最終fit](tactical-history-final-01.md)、[車番残差](stat39-c1-bike-residual-diagnostics-01.md)、[能力条件付き残差](stat39-c1-bike-ability-conditional-diagnostics-01.md)。既存の性能数値は今回の選択規則の材料にしない。

| 制御項目 | 今回の適用 |
| --- | --- |
| current_engine_state | 候補生成完了・親選択未確定から、選択方法の設計報告レビュー待ちへ。候補の認定状態は変えない |
| next_allowed_action | この設計報告とMASTER PLAN差分のレビューだけ |
| completed_phases | PR113の候補生成・検証・工程同期はmerge済み。今回の設計確認を追記し、学習・選択完了にはしない |
| superseded_phases | 旧BT-03D等を復活させない |
| blocked_phases | 親選択実行、新規fit、H1-U、正式採用、BT-04/BT-05-LIVEは未許可 |
| frozen_contracts | 固定C1/STAT39/Solver/Scorer/Decoder/Evaluator/Gate、時系列・欠損・128M・artifact契約を維持 |
| unfrozen_contracts | 将来統合の未決事項を維持。今回の規則/境界/年内SEは未承認。既存最終C1のlambda/bin/係数を未決へ戻さない |
| holdout_status | 2022-2025は既使用development、2026は一切参照しない |

Section 15.78のPR113 OPEN・未コミット記述は当時の履歴として保持する。今回の許可は設計・オフラインmetadata確認だけで、過去のレビュー待ちだけを理由に停止せず、新規学習の許可にも拡大しない。Category 1 MVPの成果・正式保存未確認・未レビュー事項は独立して維持する。

## 3. 既存成果物の存在・seal・現在状態

以下をB/Rと表記する。既存rootへは一切書き込んでいない。

```text
B=/home/shinya/neo-keirin-artifacts/tactical-history-01-review-fix-20260916-01
R=/home/shinya/neo-keirin-artifacts/c1-2023-oof-recovery-01/run-20261016-212706-e8690386
```

| ファイル | bytes | SHA-256 |
| --- | ---: | --- |
| B/report-export-manifest.json | 221711 | `4268b801b74b77cfb3a94b64832ff483e9eb5e3221ee8e75ceb467e5a5cf92e6` |
| B/frozen-experiment-contract.json | 53460 | `5db3b57b08fb8b91dbe6c057b459e518252c454b0a4ff60531c2d671b7ce506a` |
| B/inputs-v2/manifest.json | 3823 | `d5158a7671f8c453afc77dccc1ab0b089c940198f58f388c038ad0d4fad684cf` |
| B/inputs-v2/inputs-2022.jsonl | 75218174 | `cbfac43e1e398aea4a031ff87a63397ed03fd5cc7cd3fc5dc8fb641107b31b95` |
| B/inputs-v2/history-2022.jsonl | 510307079 | `148b5269e827a98e88ad4bfcd755e21bcde9cc0b7dc5f64f62e8b8032c79e680` |
| B/run-01/C1-inner-A/path.json | 219289 | `62d90c8f0ce56177bbadf9e9b0786bf7646a77b464e5dbfccd62f69dab8efdf5` |
| B/run-01/C1-inner-A/layout.json | 50983 | `3860fc5fc3cc8bf817b7f25d155650bdc7df4fcf8d6b3d8498d9be29c23dba74` |
| B/run-01/C1-inner-A/training.jsonl | 40409595 | `24f6148def4a70da8daca0ff048e96c93068a50ed03fa5073f7fa4d595a6cd79` |
| R/manifest.json | 15042 | `1f4e53cc10381451efc6eb56c2ae7cbbab045137a338a6d03c8bcdbf131d963f` |
| R/COMPLETE.json | 105 | `ee7ba44402010685d6ff54f091b2e164ef24a12dc4a03ed0e79e5834fcfff82b` |
| R/projection/input-2023.jsonl | 67675312 | `419e3a5568c39891fa4d47e589820e332aa2ad570a4ab0123ecaab5aff619168` |
| R/models/lambda-0.1/model.json | 80407 | `1b1d62eb9eac8c87480aa9f1cf92bb5525d4f99a793f2c9be794a8038e696dcb` |
| R/models/lambda-0.1/provenance.json | 5897 | `1665b18c176636561bfff42c4794378d20408b04b523a72654dec0cbc2733ee8` |
| R/models/lambda-1/model.json | 75451 | `ff82e05c6a4f0fb7e551de01c4bc984dde263ba9d868af4f9244d1f1192bc10b` |
| R/models/lambda-1/provenance.json | 5854 | `308edb32277b8e9ee6b832d395301efad36fd242792f3c7e2010abc2d761de5f` |

Bのexport manifestに記録されたsource seal、inputs-v2 manifestの2022入力/history sealを照合した。Rの固定manifest SHAとCOMPLETEの参照を検証し、列挙83ファイルのうち主要23子ファイルを再hashした。両モデル・provenance、投影入力/manifest/COMPLETE、prepare、終了検査、候補別verification、両generationのprediction/joint/候補sealを含む。Bの10ファイルとRの25ファイル、合計35ファイルのSTART/ENDが一致した。

| lambda | 各generationのファイル | bytes | SHA-256 |
| --- | --- | ---: | --- |
| 0.1 | predictions.jsonl | 221660142 | `4a7cba05c7c66bc5ca05ec0a9bdf8725fb31cdedd6c3be8348b2c5088cf6d158` |
| 0.1 | ordered-top3.jsonl | 312960536 | `5cf13ef33d552e9a5e4266026ef0e4f861bef5eeb375ff4ac0e3e499ab63dcc8` |
| 1 | predictions.jsonl | 221653286 | `ebc131e79dc71e5c4d5bd09520a7c1adca15d342fdbc8ac6ad6c0b145e5b7ccc` |
| 1 | ordered-top3.jsonl | 312982874 | `bfff3c57e94a8fb2d5e19523d0490e7d9ff11035c77f6387933bb9b17bd0bb3b` |

上記は `generation-01/lambda-*` と `generation-02/lambda-*` それぞれの実hashが一致した今回の確認。各25,197レース・179,007出走、確率/Primaryの全行検証、独立2生成成功、C1再学習0は前工程のseal・検証記録にある実績であり、今回は再生成・全行確率計算・損失照合をしていない。全83ファイル/全元資料/全コードの再監査とも記載しない。

両provenanceのtraining_years=[2022]、selection_performed=false、model sealと元path内の親モデルの数値一致を確認した。今回実際に `TacticalHistoryFinal/ModelLoader::restore()` を両候補へ適用し成功した。予測メソッドは呼んでいない。

| lambda | P1/P2/P3 accepted updates | P1/P2/P3 prox-gradient mapping max | 状態 |
| --- | --- | --- | --- |
| 0.1 | 132 / 85 / 102 | 6.23667e-9 / 5.72332e-8 / 8.60864e-8 | 全3順位CONVERGED |
| 1 | 67 / 38 / 49 | 4.40083e-8 / 7.93720e-8 / 5.72266e-8 | 全3順位CONVERGED |

両モデルはM=136、G=16、numeric edges=116。中心化残差は全順位1.22e-17以下。残り6候補は元の全2022pathで全順位収束モデルとして利用できない。これは将来の年内subsetでの収束可否を意味しない。診断値をlambda選択に適用していない。

## 4. 2022年の日時・開催metadata

固定C1 `inputs-2022.jsonl` 自体はrace/entry/bike/year等を持つが、暦日・開催期間を持たない。seal済み `history-2022.jsonl` の `target` から次だけを参照した。

```text
race_id, entry_id, bike, race_date,
meeting_id, meeting_start, meeting_end, input_as_of
```

入力race/entry順にhistory targetを逐次突き合わせた結果は次のとおり。historyの集計値・source rowsや教師値は分割判定に使わない。元入力は教師を含むが、今回のmetadata集計は教師を利用せず、2023-2025の教師/検証損失ファイルを開いていない。

| 今回の実測項目 | 結果 |
| --- | ---: |
| race / entry / distinct meeting ID | 24,394 / 170,835 / 837 |
| race ID重複 / entry ID重複 | 0 / 0 |
| targetのrace/entry/bike不一致 | 0 |
| 開催ID/開催期間欠損出走 | 0 |
| 同じrace内の開催/日付矛盾 | 0 |
| 同一meeting IDの期間矛盾 | 0 |
| 対象日が2022年かつ開催期間内 | 全対象一致 |
| input_as_ofのoffset | 全170,835件が+09:00 |
| targetにscheduled_start_at fieldがある出走 | 0 |

同じ開催IDが複数レース・出走へ登場するのは正常な所属関係で、重複出走ではない。同日程の別開催IDも統合しない。レースID/JSONL順から日時は推測していない。

出典は `TacticalHistory/InputBuilder::enrichChunk()` のraces/race_days/race_meetingsとentryへの対応。targetのmeeting_start/endは開催暦日で、今回DBへ再接続していない。`Stat01Calculator`のinput_as_ofはsalesCloseAt優先・scheduledStartAt fallbackであり、targetだけではどちらかを識別できない。input_as_ofを発走予定日時と読み替えない。

本資料は後日保存された固定snapshot。暦日・IDの内部整合性と改変されていない保存版は確認したが、当時の発走前公開時刻や後日DB修正の有無は証明しない。発走予定日時は今回検証したtargetからは復元できず未確認。暦日の00:00:00 Asia/Tokyoを境界候補にする開催単位分割は可能だが、日内時系列分割・historical as-of利用は未認定。

| レース月 | races | entries |
| --- | ---: | ---: |
| 2022-01 | 1898 | 13354 |
| 2022-02 | 1942 | 13483 |
| 2022-03 | 1893 | 13347 |
| 2022-04 | 1967 | 13809 |
| 2022-05 | 2119 | 14885 |
| 2022-06 | 2184 | 15185 |
| 2022-07 | 2035 | 14246 |
| 2022-08 | 2072 | 14293 |
| 2022-09 | 2047 | 14341 |
| 2022-10 | 2044 | 14485 |
| 2022-11 | 2116 | 14881 |
| 2022-12 | 2077 | 14526 |

2022固定入力のhistory_statusはAVAILABLE=115096、LEFT_TRUNCATED=54577、INVALID_HISTORY=457、PARTIAL_HISTORY=705。正常値や0へ補完しない。年初の履歴不足と短い学習窓でのsupport減少を考慮するが、結果を見て境界を選び直さない。

## 5. 方法A: 2022年学習情報だけの決定規則案

| 案 | 使用情報・再現方法 | 合理性と限界 | 判定 |
| --- | --- | --- | --- |
| A1 正則化優先 | 固定8候補のうち2022年学習で全3順位収束し、版/入力/診断が有効な集合を作る。その集合で事前承認した単調な正則化優先規則を一度だけ適用し、空集合ならSTOP | 決定的で追加fit不要。強い正則化を優先するのは分散抑制の仮説であり、将来の的中率/NLL優位の証拠ではない | 規則の構成は可能。規則承認/選択は未実施 |
| A2 複雑度・収束診断 | 2022年のM/G、active bins、係数norm、中心化/最適性残差、更新数を監査可能にする | 残差は最適化成功の検査で性能指標ではない。lambdaごとにpenaltyが違う目的関数値の最小をそのまま選ばない。support・group制約下の有効自由度は単純な係数数と同じでなく、独立評価なしのAIC/BIC等も確定しない | 品質除外には使えるが、独立した性能選択基準は未確立 |
| A3 既存の独立規則 | frozen-experiment-contract、TacticalHistoryのrun/path/provenance、関連設計文書・実コードを照合 | 確認した範囲の元実験は2022学習→2023検証、および次年検証を用いるOne-SE。2022年だけで親を選ぶ事前の正式規則は確認できない | NOT_ESTABLISHED。未確認媒体まで不存在を断定しない |

A1の具体的な規則例は「最大正則化を優先し、候補集合内で一意に決める」。これは提案であり、今回はこの規則を実データに適用して選択値を算出・登録しない。2つの既存収束モデルだけにgridを縮める規則ではない。

A1/A2の入力を2022 diagnosticsへ限定すれば、アルゴリズムから2023年以降の結果への依存を除ける。ただし、既にdevelopment成績を知った後で規則を提案する研究者の選択バイアスは残る。非収束候補の除外による数値的な候補集合の偏りも性能優位とは別である。

## 6. 方法B: 開催単位の2022年内前進検証案

### 6.1 分割候補と件数（未承認・未凍結）

推奨候補B1は暦上の前半→後半、代替B2はexpanding quarterly folds。件数確認にだけmetadataを使い、教師・損失・性能による境界最適化はしない。以下の日付はいずれもレビュー用の候補であり確定ではない。

学習は `meeting_start >= 2022-01-01` かつ `meeting_end < cut`、検証は `meeting_start >= cut` かつ `meeting_end < until`。境界を跨ぐ開催はそのfoldの両側から保留し、片方に分割しない。同じ選手が両期間に登場すること自体は禁じず、同じ開催/対象教師の混入と区別する。「開催単位」は固定cohort内の全対象行を同じ側にする意味で、開催内の対象外レースを追加取得する意味ではない。

| 案 / cut / until（JST暦日） | 学習 meetings / races / entries | 検証 meetings / races / entries |
| --- | --- | --- |
| B1: 07-01 / 2023-01-01 | 408 / 11926 / 83516 | 421 / 12282 / 85958 |
| B2-1: 04-01 / 07-01 | 195 / 5704 / 39993 | 213 / 6222 / 43523 |
| B2-2: 07-01 / 10-01 | 408 / 11926 / 83516 | 209 / 6032 / 41964 |
| B2-3: 10-01 / 2023-01-01 | 620 / 18078 / 126404 | 208 / 6151 / 43313 |

B1の保留は年初跨ぎ2開催/29レース/191出走、7月境界跨ぎ3開催/120レース/924出走、年末跨ぎ3開催/37レース/246出走。学習+検証+保留=837開催/24394レース/170835出走。年末のend日付参照は2022対象の開催期間metadataだけで、2023年教師は参照しない。B2の7月cut跨ぎは3開催/120レース、10月cut跨ぎは4開催/99レース。各foldのuntil跨ぎ・将来期間・年境界分も保留/対象外として台帳化する必要がある。

B1を第一候補とする理由は、元の「前半学習→後半検証」に忠実で、年内fold重みの曖昧さと新規fit数が少ないため。B2は複数時期を確認できる一方、短い初期学習窓・履歴不足・収束不確実性、fold間の学習集合重複と誤差相関が増す。B1の時期依存も解消しない。どちらもレビュー前に採用しない。

### 6.2 学習・教師・停止条件

将来の実装では、targetだけの許可field投影とfold割当を先にsealし、元の入力順/値/欠損を保ったsubsetを別版で保存する。Dataset/Trainerはパス配列を受け取るだけで日付分割をしないため、新規の限定orchestrationが必要。既存Experimentの2023/2024検証やOuter処理は呼び出さない。

layout/bin/supportは各学習subsetだけから新規作成する。全2022学習済みlayoutやモデルをfold学習へ使わない。validation教師は2022年だけの独立NLL計算に渡し、学習や特徴量計算へ流さない。保存特徴量の履歴cutoffは対象開催開始前を維持し、検証対象自身や未来の結果で補完しない。学習期間より後の過去開催を使う検証時点のrolling入力と、未来/対象教師の漏洩を区別する。固定入力のhistorical as-of未証明は解消しない。

既存Objectiveのteacher-forced NLLを使用する。P1は公式1着一意、P2は公式1/2着一意で実1着を候補集合から除き、P3は公式1/2/3着一意で実1/2着を除く。FINISHED/TIED等の既存適格性を維持し、順位不明を負けに補完しない。順位ごとの適格/除外数を保存し、全3順位が評価可能でなければSTOP。選択目的は3順位NLLであり、的中率最大化やGate通過を直接意味しない。

固定16特徴量、STAT-01 anchor=1、grid `[0, 0.000001, 0.00001, 0.0001, 0.001, 0.01, 0.1, 1]` は変更しない。Solverは `TACTICAL-HISTORY-CONSTRAINED-EUCLIDEAN-FISTA-v2`、モデルは `TACTICAL-HISTORY-SEQUENTIAL-POSITION-v2`。strong-to-weak path、全3順位収束候補からのwarm start、200 accepted updates、係数/最適性/中心化1e-7・相対目的関数1e-10、reference step=1等の既存収束条件を維持する。

非収束は候補単位で除外し、generic RuntimeExceptionは握り潰さない。全fold共通の全順位収束候補が空、順位別適格0、source drift、入力不一致、未承認lambdaへの置換、容量/安全条件不成立ならSTOP。全2022で0.1/1が収束していても年内subsetの保証にはならない。収束閾値緩和、反復追加、都合のよいfoldの削除、grid変更、近いlambdaへのfallbackはしない。

### 6.3 One-SEの互換性と未決契約

`Bt03e03OneSeSelector`の既存仕様は、年ごとのspoolを各年等重み、年内は適格race平均、3順位等重みで集約する。年別race resamplingを2000回・seed=20260812で行い、同じrace重みを全候補/順位に共有する。SEはbootstrap集約値の標本標準偏差であり、2000の平方根で再除算しない。最小点損失の候補の `loss + SE` 以下にある最大lambdaを選ぶ。

B1は検証層を2022年後半の1つ（key=2022）にする案なら、選択器の数値実装を変更せず使用する設計が可能。ただし元の2年検証を単一年の半年へ置き換えるので、検証母集団の意味は同一ではない。B2でfoldを便宜的な別年keyへ入れると、fold等重み・fold内race resamplingとなり、元のyear-equal/year-stratifiedの解釈が変わる。「年内分割対応済み」とはしない。

承認が必要な仕様案は、B1なら1検証層/3順位等重みと既存race bootstrap、B2ならfold等重み（レース数加重と混同しない）・fold内paired race bootstrap・同じseed/回数/One-SE境界規則を別の年内検証契約で明記すること。fold間学習集合の重複、同一開催・選手の相関をこのrace bootstrapは補正しない。meeting bootstrap等へ無断で変更しない。既存Selector/Solver本体は変更しないが、新規orchestrationの版・適格性・SE解釈を先に承認する必要がある。

既存 `Bt03e03ValidationLossSpool` はdestructor/cleanupでunlinkし、旧外部bin providerにも削除経路がある。今回これらを生成・実行していない。将来のBはそのまま旧Trainer経路を起動せず、作成記録付きの保持spool/安全なadapterと数値互換性確認を別途承認すること。永久削除を許容したり、既存共通クラスを今回変更したりしない。

## 7. 方法比較と推奨

| 評価軸 | 方法A | 方法B |
| --- | --- | --- |
| 2023結果からの独立性 | 入力を2022モデル/診断に限定可能。後付け規則の研究者バイアスは残る | 学習/検証とも2022だけに隔離可能。後付け分割設計のバイアスは残る |
| 新規C1学習 | A1不要、A2診断だけなら不要 | 各foldの新規8候補pathが必要 |
| 予測性能上の選択根拠 | 正則化優先は仮説。性能検証なし | 学習より未来の2022 NLL。ただし的中率向上の直接証明ではない |
| 統計的妥当性 | 決定規則は監査可能、有効自由度/汎化性能は未確立 | 時系列・開催分離が可能、季節差/短窓/相関/非収束の制約あり |
| データ追加取得 | 不要 | 暦日開催分割はオフライン資料で可能、日内時刻は未確認 |
| 計算負荷 | 既存model/diagnosticsの読取りのみ | B1を第一候補、fit/検証/独立再現が必要 |
| 既存C1契約変更 | 本体不変、新しい選択規則の承認が必要 | 本体不変、年内編成/SE解釈/非削除spoolの承認が必要 |
| 監査・再現性 | 入力seal/規則/code/候補集合/選択台帳を固定 | 左記にfold台帳・training限定layout/support・損失/SEの再現を追加 |
| 主なリスク | 強い正則化/収束を性能優位と誤認すること | subset非収束、検証母集団変更、後付け境界、旧cleanupの実行 |

推奨はB1の限定設計承認を先に得ること。過去だけの独立した検証損失という選択根拠を作れるためである。ただし、これは追加fitの提案であり今回は実施しない。計算を行わないA1は手続上可能な代替案であり、Bが不調だった後に性能を見てAへ切り替えるfallbackにはしない。採る方法と停止時の扱いを実行前に固定する。

## 8. 新規fit数・時間・容量の概算

| 作業案 | 1実行の上限的な規模 | 独立2実行 |
| --- | --- | --- |
| A1/診断確認 | 新規fit 0、予測生成0 | 必要なら規則台帳の再検証だけ |
| B1 1fold | 8候補path、最大24順位fit試行/4800 accepted updates | 最大48順位fit試行/9600 updates |
| B2 3fold | 3path、最大72順位fit試行/14400 updates | 最大144順位fit試行/28800 updates |

同一path内の数値非収束で後続順位を試さない場合があり、表は全候補×3順位×200の概算。validation NLLとOne-SEも別に必要で、今回の実行回数ではない。最終的に0.1/1を選び元full-2022モデルを厳密に再利用できれば、新規full-2022 refitと2023予測生成は0を目指す。

保存run-stateのC1_INNER_A_FITは2026-09-15 22:42:17〜23:12:21 UTC、1804秒（過去のlayout/path学習と2023 validation処理を含む区間）。純粋な1順位fit時間でも、今回の新規計測でもない。損失値は参照せず、時間記録だけを概算の参考にした。

同程度の単一CPU環境を仮定し、B1の独立2実行は1〜2時間、B2は3〜6時間程度を初期計画の目安として提案する。短窓での収束・line search・I/O・One-SE負荷を実測しておらず、保証値/承認済み上限ではない。正式な時間予算と超過時STOP条件はレビューで決める。同条件を無断で繰り返さない。

元入力は約75MB、history metadata約510MBをread-onlyで参照できる。loss spoolは8候補×3順位×8 bytes=192 bytes/raceなので、B1検証分は2,358,144 bytes、B2検証3層合計は3,533,760 bytes（ヘッダ等を含めない）。これにsubset、binned training、path、保持bin spool、診断/再現ログが必要。暫定予約の目安は1fold/1実行あたり1GiB、B1独立2実行で2GiB、B2で6GiB。bin spoolの実サイズや保持方式は未確定のため、容量保証ではなく実行前の見積り・空き容量確認が必要。既存の大容量2023予測/jointを複製する必要はない。

将来もPHP本体128M・streaming・外部通信遮断・既存root read-only・新規専用出力のみ。今回のmetadata読取り成功を将来のfit/One-SEの128M成功保証にしない。

## 9. 2023年以降の結果との隔離・既存候補再利用条件

将来の選択器へ許可するのは、2022対象metadata、2022学習入力/教師、承認済み規則、2022内validation損失だけ。2023-2025のrank/status/損失/的中率、既存Outerの選択lambda、最終fitのlambdaは入力不可。既存Experiment全体を起動せず、結果rootを不可視にし、選択台帳をsealしてから選択値と保存2023候補を対応付ける。

今回のモデル復元・成果物seal確認は選択処理ではない。2023候補本体のhashや件数は完全性確認だけに使い、性能や親lambdaの優劣へ使わない。研究者は既に2023-2025のdevelopment情報を知っている。将来のコード入力を隔離しても、当時から結果を知らずに親を選んだという証明や、未使用holdoutへの格上げにはならない。

将来の再利用は、次を全て満たすときだけ。

1. 規則・fold・選択目的・停止条件を先に承認し、2023年以降の結果なしで選択を完了・sealする。
2. 選択lambdaが0.1または1で、対応する親が2022年だけで学習されている。
3. 元full-2022モデルの版・anchor・feature/bin/support/係数・入力SHA・provenanceを厳密に一致させる。年内foldモデルに保存2023予測を流用しない。
4. 結果fieldのない固定2023投影入力、元ID/順序、候補予測/joint/utility/Primaryとの対応を保存sealで証明する。
5. 元manifest/COMPLETEとSTART/ENDは不変のまま、新規の選択/再利用台帳から参照する。旧成果物を再sealしない。

上記成立なら原則2023再予測は不要。別lambdaが選ばれた場合、元2候補への混合/近似/置換は禁止し再利用不可としてSTOP。候補gridを0.1/1に事前縮小するのも禁止。別lambdaの適格なfull-2022モデルが存在しなければ追加fit・候補生成の別承認が必要。現時点では最終OOF未認定・H1-U利用保留を維持する。

## 10. 未解決事項・次工程の承認事項

未解決は方法A/Bの採否、Aの優先規則、Bの暦日境界と保留台帳、年内One-SEの層/重み/SE解釈、subset収束、非削除spool実行経路、時間/容量予算、結果を隔離する実行経路。発走予定日時・当時の公開/更新履歴・historical as-ofは未証明。確認したroot外の独立事前規則の有無も未確認。

次に必要な承認は上記仕様案のレビューであり、今回の報告だけで選択・製造・fitへ進まない。承認後の製造工程では、2023以降の結果変更が親選択へ影響しないこと、fold境界/開催隔離、training限定bin/support、非収束fail-closed、安全な保持spool、128M・独立再現を人工環境で先に検証する必要がある。今回その新規テストや実装は作成していない。

H1-Uは別工程で能力情報、補正自由度/制約/正則化、teacher、内部前進検証、2024/2025への更新適用、paired評価、位置別的中率・三連単完全一致・既存Gate/CIを確定する。既存C1本体は変更しない。既に見た2024/2025成績で補正を調整し、同期間を未使用holdoutと呼ばない。補正器の製造/学習・性能比較は未許可。

## 11. 今回の実行・文書検証

実データへの処理は、読取り専用のオフライン確認用PHP2回だけ。他にGit読取り/新規branch作成、コード・文書読取り、文書の静的検証を行った。PHPUnit/Artisan/Selector::select/Trainer::grid/予測処理は実行していない。

PHPは `/usr/bin/php` 8.5.4、各 `memory_limit=128M`。次のbwrap起動条件のstdinスクリプトで、モデル復元/START-END seal/targetの逐次照合と、半期分割のmetadata補足をそれぞれ実施した。

```bash
/usr/bin/bwrap --die-with-parent --unshare-net --ro-bind / / \
  --dev /dev --proc /proc --tmpfs /tmp --tmpfs /run \
  --ro-bind /dev/null /var/www/NeoBicycleRaceInformationWeb/.env \
  --tmpfs /var/www/NeoBicycleRaceInformationWeb/storage \
  --tmpfs /var/www/NeoBicycleRaceInformationWeb/bootstrap/cache \
  --clearenv --setenv PATH /usr/bin:/bin \
  /usr/bin/php -d memory_limit=128M
```

1回目は2026-10-17 09:07:13〜09:07:17 JST、4.014890秒、peak=28,311,552 bytes（27MiB）、exit0。Rの主要23子ファイル/Bの10ファイル/manifest/COMPLETEの計35ファイルSTART/END、2モデルrestore、2022全入力/target整合と月別/四半期候補集計に成功。2回目の半期metadata補足は1.896791秒、peak=4,194,304 bytes、exit0、history-2022のSTART/END seal一致。実行結果の必要数値は本書へ保存し、別の生成runやCOMPLETEは作成していない。

文書検証の初回は未導入のSymfony Yamlクラス参照でexit255。依存追加や設定変更はせず、文書だけを読むPHPで再確認した（アプリ起動なし、exit0）。専用YAMLパーサーによる検査とはせず、現在地の状態行と新規報告の12値一致、8工程項目、相対リンク7件、UTF-8、fence/見出し、分割/spool件数計算、変更が指定2Markdownだけであることを確認した。Section 15.78/15.77/18/19/20/23とv1.71履歴は開始HEADとbyte一致。`git diff --check`はexit0。新規未追跡文書も `git diff --no-index --check -- /dev/null docs/c1-2023-parent-lambda-selection-feasibility-01.md` で警告なし（差分ありのexit1）を確認した。

DB/HTTP/Raw/2026/バックアップ操作/新規fit/予測生成/損失計算/性能比較/既存成果物書込み/移動/削除は0。新規のMarkdownとMASTER PLANだけの未コミット差分。テスト/Pintは設計限定のため未実施。

最終状態: `C1_2023_PARENT_LAMBDA_SELECTION_FEASIBILITY_READY_FOR_REVIEW`。commit/push/PR操作/mergeはせず、未コミットのままChatGPTレビューを待つ。

## 12. PR114 P2: リモート同期確認（2026-10-17）

### 指摘と原因

[PR114 P2](https://github.com/Shinya-Taketani/NeoBicycleRaceInformationWeb/pull/114#discussion_r4300589448)は、ローカルorigin/mainの確認だけではGitHubの最新mainを証明できず、MASTER PLAN Section 26のfetch/main同期根拠が不足するという指摘。初回設計の「fetch/外部照会なし」は事実のまま保持し、今回の検証で遡及的に置換しない。初回文書はその後 `edcc5a75b8a6bed35dd0a9b0f74b4bf69aee540f` として保存され、今回の開始HEAD/確認時PR headになっていた。固定の将来最新HEADとは扱わない。

### 開始・GitHub照会・同期結果

開始cwdは `/var/www/NeoBicycleRaceInformationWeb`、既存branch `design/c1-2023-parent-lambda-selection-feasibility-01`、HEAD `edcc5a75b8a6bed35dd0a9b0f74b4bf69aee540f`、worktree clean。`git remote -v` のoriginは `https://github.com/Shinya-Taketani/NeoBicycleRaceInformationWeb.git`（fetch/pushとも）と一致した。

GitHubのPRメタデータを `get_pr_info(repository_full_name=Shinya-Taketani/NeoBicycleRaceInformationWeb, pr_number=114)` で読取り取得し、成功（isError=false）。PRはOPEN、merged=false、base branch=main、head repository/branchは上記の対象repo/既存branchと一致した。GitHubのmerge_commit_shaをPR114マージ済みの証拠にはしていない。

| 確認対象 | 実値・結果 |
| --- | --- |
| リモート照合の記録日時 | 2026-10-17T10:32:45+09:00（JST） |
| main同期・PR branch復帰の確認日時 | 2026-10-17T10:32:59+09:00（JST） |
| ls-remote main / fetch後origin/main / local main | 全て `0d57393c10ff046505bf85ab232e104dcbe8d6d3` |
| PR114 base SHA | `0d57393c10ff046505bf85ab232e104dcbe8d6d3` |
| PR114 head SHA（今回確認時） | `edcc5a75b8a6bed35dd0a9b0f74b4bf69aee540f` |
| ls-remote PR branch / origin追跡参照 / local HEAD | 全て同じ `edcc5a75b8a6bed35dd0a9b0f74b4bf69aee540f` |
| PR113 merge確認 | origin/mainの当該commit件名は `Merge pull request #113 from Shinya-Taketani/research/c1-2023-oof-recovery-01`、包含検査exit0 |
| local main同期 | `git pull --ff-only origin main`: `Already up to date.`、exit0 |
| 復帰後 | 同じPR branch、clean、HEAD不変、merge-base(main, HEAD)=上記main SHA |

PRメタデータ照会・fetchは同じ確認作業中に実施した。日時欄は照合結果を記録した時刻と同期後確認時刻であり、各ネットワーク要求の開始/終了時刻を個別計測した値ではない。

| 実行コマンド | 終了コード・主な結果 |
| --- | --- |
| `pwd` / `git status --short --branch` / `git branch --show-current` / `git rev-parse HEAD` / `git remote -v` | 各0、上記cwd/clean/branch/HEAD/originを確認 |
| `git fetch origin` | 0、stderrを含め出力なし |
| `git ls-remote origin refs/heads/main refs/heads/design/c1-2023-parent-lambda-selection-feasibility-01` | 0、上記main/PR headと一致 |
| `git rev-parse origin/main origin/design/c1-2023-parent-lambda-selection-feasibility-01 main` | 0、上記の順でmain/head/mainと一致 |
| `git log -1 --format='%H %s' origin/main` | 0、main SHAとPR113 merge件名 |
| `git show -s --format='%H%n%P%n%cI%n%s' 0d57393c10ff046505bf85ab232e104dcbe8d6d3` | 0、親は447b096/bc53fb5、commit日時2026-10-17T08:31:58+09:00、PR113 merge件名 |
| `git merge-base --is-ancestor 0d57393c10ff046505bf85ab232e104dcbe8d6d3 origin/main` | 0、mainにPR113 mergeが含まれる |
| `git switch main` / `git pull --ff-only origin main` / `git rev-parse HEAD` | 各0、main一致・同期確認のみ、編集/コミットなし |
| `git switch design/c1-2023-parent-lambda-selection-feasibility-01` / `git status --short --branch` / `git rev-parse HEAD` / `git merge-base main HEAD` | 各0、既存PR branchへ復帰、clean/HEAD不変、base一致 |

PR baseとremote mainに差はなかった。mainのfast-forward限定同期確認以外の統合、PR branchのrebase/merge/cherry-pick、新branch作成はしていない。Section 26の手順1〜5を実施し、手順6は今回の最新指示に従い新規branchではなく既存PR branchへ復帰した。将来の実際のremote状態は次回また確認する必要がある。

### 修正範囲・未実施・残件

変更は既存の本報告と `statistical-engine-master-plan.md` の2Markdownのみ。MASTER PLAN冒頭/metadata/5.0/15.79.1/PR履歴/v1.72内P2追記/26/27へ、今回の確認日時・根拠・PR対応を同期した。Version1.72、初回設計の本文1〜11・過去の変更履歴・以前のSHA・実行数値は保持する。

今回、既存artifact/model/manifest/COMPLETEの読取り再検証や再sealも不要のため実施していない。PHP/Migration/config/test/計算サービスは変更なし。PHPUnit/Pint、学習/refit、lambda正式選択、予測生成、2024/2025性能評価、DB/SQL、競輪HTTP/スクレイピング/Raw、2026参照、バックアップ操作、既存資料の削除/移動/退避は0。

Section 1の全状態値、C1凍結モデル、2023未選択候補、親選択独立性/最終OOF未認定、H1-U保留、historical_as_of_available=false、formal_adoption=false、prediction_use=NOT_AUTHORIZED、2026凍結を維持する。方法A/Bの選択、境界、年内One-SE、保持spool、H1-Uの未承認事項、Category 1 MVP未レビュー事項は未解決のまま。PR113 MERGEDはPR114設計承認を意味しない。

文書の静的確認は `python3 -B` の読取り専用stdinスクリプトと既存PyYAML 6.0.3でexit0。YAML46ブロック、現在地の全既存値/指定凍結12項目、UTF-8/コードフェンス、追加相対リンク2件と参照見出し、許可2ファイル限定、Git base/head対応を確認した。初回報告Section 1〜11、MASTER PLANの15.79初回本文/15.78/15.77/18/19/20/22/23とv1.72初回履歴/v1.71履歴は開始HEADと不変。`git diff --check`もexit0。アプリbootstrap/テストは実行していない。

次はPR114 P2の文書差分をChatGPTへ提示して再レビューを受けるだけ。commit/push/PR更新/mergeはせず、既存PR branchに未コミット差分を保持し、次工程へ進まない。
