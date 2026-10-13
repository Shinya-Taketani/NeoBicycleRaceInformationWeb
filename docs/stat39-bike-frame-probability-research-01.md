# STAT39-BIKE-FRAME-PROBABILITY-RESEARCH-01

## 1. 目的と対象期間

2026-10-14、PR105 mergeをローカル履歴で確認。開始clean main/ローカルorigin/mainは
`dc6d06ccbfe444d9228d4b3763a4f51860ba956e`、作業branchは
`research/stat39-bike-frame-probability-2024-2025-01`。fetch/GitHub照会は行っていない。
MASTER PLAN v1.70/5.0/15.77の旧DB未承認記録は当時の工程記録であり、今回の最新指示による
2024/2025限定READ ONLY研究を許可根拠とする。正式採用・2026・LIVEは開放しない。

対象は2024-01-01〜2024-12-31と2025-01-01〜2025-12-31を別々に、保存race_typeが
`Ａ級%`/`Ｓ級%`、宣言車立て7/9、race結果CONFIRMED/CORRECTEDのレース。
7/9は登録車立てであり、欠場があるレースの実発走人数まで7/9と断定しない。
目的は車番・保存枠番と結果の観測関連。新しい予測実験・因果効果・配点計算ではない。

最終状態は `STAT39_RESEARCH_READY_FOR_REVIEW`。
判断A: **未補正率の差を実測確認**。判断B: **実力・番組編成補正後の独立効果は判断不能**。
判断C: **新STATを作る根拠はなく、既存STAT39の拡張を提案**。
研究全体の結論は `INSUFFICIENT_EVIDENCE`、拡張案の位置付けは `STAT39_EXTENSION_CANDIDATE`。
「差がない」や「独立効果が証明された」という結論ではない。

## 2. DB・カラム・既存STATと根拠

実接続はPostgreSQL **18.6** / `127.0.0.1:5432` / `neo_keirin_prediction_db.public`。
既存pgsql設定・認証を使用し、Kernel/providerは起動せず、設定ファイルを変更しない。
利用role `keirin_admin` 自体には書込み権限があるため、専用読取りroleによる権限制限とは言わない。
PGOPTIONSと各transactionでREAD ONLYを設定し、`default_transaction_read_only=on`、
`transaction_read_only=on`、REPEATABLE READ、statement_timeout=60s、lock_timeout=2s、
TimeZone=Asia/TokyoをSELECT/SHOW相当の照会で確認してから業務テーブルを読む。
許可されたSQLはSELECT/EXPLAINと接続限定BEGIN/SET/ROLLBACKだけ。書込みを試して安全性を検証していない。

| 保存元 | 使用項目・意味 |
|---|---|
| races | id/date/race_type、entrant_count、result_status、scheduled_start_at、race_day_id、racetrack_id |
| race_entries | id/race_id/bike_number/player_id、frame_number。現在のrace_scoreは補正に使わない |
| race_results | race_id/bike_number/rank/result_status、race_entry_id/player_idのNULL/矛盾検査 |
| race_days/race_meetings | 同じ対象raceの開催対応、day_kind/gradeの保存原値。時刻から分類しない |
| statistic_feature_runs/results | 明示STAT01/39 run、件数・版・本人対応・hash・cutoff・観測時刻 |
| pg catalog/information_schema | 許可テーブルの実列型、SELECT権限、INDEX/UNIQUE |

実schemaではrank/frame/entrant_countはsmallint、race_dateはdate、時刻はtimestamptz、
features/evidenceはjsonb。race_entriesには得点専用時刻列とdeleted_atは存在しないことも確認した。
今回そのschema差を修復せず、汎用fetched_atを列専用発走前証拠へ読み替えない。

AGENTS、MASTER PLAN現在地/禁止事項/STAT39・C1記録、Batch04文書、区分1MVP台帳、
validate_batch04_2024.sql、Calculator/PositionBiasRepository/TargetRepository/BuildService、
Normalizer/Enum/DTO、source/feature Migration、Batch04 Unit/Featureの分母・cutoff・hash検査を確認。
要件原本はユーザー指定のダウンロード配下URLエンコード名ファイルのSTAT39節。
ファイル名v1.0(2)と本文v0.1の不一致は既存記録のまま。因果効果・割当制度・初手補正の要件は未完成。

追加参考資料 `競輪でのコースによる優位性レポート.md` はダウンロード配下で読取り確認した。
本文がサンプル代替・例示値を明記し、枠番説明も今回の保存1〜6分布と整合しないため、数値・公式仕様の根拠へ採用しない。
`競輪のコース優位性の調査.txt` はrepo/docs、ダウンロードとドキュメント/codexの限定探索で未発見。
すべての保存場所に存在しないとは断定しない。

## 3. 取得・除外条件と再現SQL

[実行した最終SQL](sql/research_stat39_bike_frame_2024_2025.sql) は
`STAT39-BIKE-FRAME-PROBABILITY-RESEARCH-01-v1`、SHA-256
`22913344ebb77b598abd332dae1f4247268f7f514edd351e0446122c8fff1365`。
全業務SELECTにSQL側の2024/2025範囲を指定。対象行数を事前確認し、各集計前に
EXPLAIN(FORMAT JSON)を保存。EXPLAIN ANALYZE、無制限の明細読込み、DDL/一時tableは使用しない。
PHPへ返すのは集約行だけ（主集計1,546行）、128M制限、最終DB処理のpeak16MiB。

主分母はFINISHED/TIED/DISQUALIFIED/CRASHED/DID_NOT_FINISHの実発走者。
DID_NOT_START/WITHDRAWNを除外。未知/結果欠損は分母へ既定追加せず別件数。
成功分子はFINISHED/TIEDかつ保存rank=1/2/3のみ。異常結果のNULL rankを0/最下位へ変換しない。
補助率は正常完走者FINISHED/TIEDだけを分母にする。両分母を混用しない。
同着は保存rankをそのまま使う。現行LiveResultParserも原tyakuを再採番せず、同じrankが複数ならTIEDとする。
Raw自体を今回再解析して公式制度を再証明したわけではない。

## 4. 対象件数とデータ品質

| 年 | DB内当年race | 男子A/Sと識別不能 | 7/9以外 | 7/9だが結果状態除外 | 対象race | 登録entry | 実発走 | 正常完走 | 異常実発走 | 未発走 | 未知 |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 2024 | 25,624 | 0 | 1,836 | 19 | 23,769 | 170,949 | 170,711 | 168,577 | 2,134 | 238 | 0 |
| 2025 | 25,273 | 0 | 1,535 | 36 | 23,702 | 170,480 | 170,241 | 168,261 | 1,980 | 239 | 0 |

合計47,471レース/341,429登録出走/340,952実発走。固定C1 cohortや予測評価件数へ置換していない。
男子判定は既存STATの保存race_type契約であり、現在playerプロフィールによる性別再調査はしていない。
race＋bikeのentry重複、孤立結果、欠損結果、宣言車立て不一致、範囲外bike、正常rank不正は全て0。
対応UNIQUE INDEXも確認した。非NULLの結果entry/player識別矛盾は0だが、
**結果側race_entry_id/player_idは341,429行すべてNULL**。entry側playerは全件設定済み。
race＋bikeの一意結合は確認できるが、結果の本人ID独立照合完了とは扱わない。
quality初版のIS DISTINCT FROMの件数はNULL差も含むため、linkage照会で未設定と矛盾を分離した。

| 年 | 車立て | race | 登録 | 発走 | 正常 | 異常 | DNS | TIED出走 | TIEDを含むrace |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 2024 | 7 | 21,486 | 150,402 | 150,176 | 148,429 | 1,747 | 226 | 472 | 236 |
| 2024 | 9 | 2,283 | 20,547 | 20,535 | 20,148 | 387 | 12 | 76 | 38 |
| 2025 | 7 | 21,419 | 149,933 | 149,713 | 148,114 | 1,599 | 220 | 532 | 264 |
| 2025 | 9 | 2,283 | 20,547 | 20,528 | 20,147 | 381 | 19 | 90 | 44 |

同着のため各順位の人数はrace数と一致しない。
年×車立て順で1着数は21,537/2,286/21,483/2,293、2着数は21,480/2,287/21,406/2,281、
3着数は21,482/2,283/21,422/2,281。欠ける順位・複数人の順位を品質SQL/ties.jsonへ保存した。
正常rank NULLと未知状態は0だが、異常/DNSのrankはNULLのまま。

## 5. 車番別の実測1〜3着率

単位%。各行の3率は同じ実発走者分母。分子、正常分母の補助率、top2/top3、同着・異常・DNS、
未丸め率と参考95%CIは証跡の `bike.csv`。年・車立て・車番別の全32行を保持した。

| 年 | 車立て | 車番 | 実発走者分母 | 1着率 % | 2着率 % | 3着率 % |
|---|---|---|---:|---:|---:|---:|
| 2024 | 7 | 1 | 21455 | 26.7164 | 19.2869 | 14.3230 |
| 2024 | 7 | 2 | 21447 | 16.1468 | 16.8275 | 15.1070 |
| 2024 | 7 | 3 | 21450 | 13.7716 | 15.1981 | 14.9790 |
| 2024 | 7 | 4 | 21466 | 13.2489 | 14.8141 | 16.3887 |
| 2024 | 7 | 5 | 21450 | 14.0326 | 14.5455 | 15.4126 |
| 2024 | 7 | 6 | 21456 | 3.6307 | 5.5602 | 10.0298 |
| 2024 | 7 | 7 | 21452 | 12.8426 | 13.8915 | 13.8915 |
| 2024 | 9 | 1 | 2279 | 22.6415 | 18.7802 | 12.0667 |
| 2024 | 9 | 2 | 2282 | 13.6284 | 12.7958 | 12.4890 |
| 2024 | 9 | 3 | 2281 | 11.6616 | 12.1000 | 10.8724 |
| 2024 | 9 | 4 | 2280 | 3.9912 | 5.9211 | 8.5526 |
| 2024 | 9 | 5 | 2283 | 14.4547 | 13.4034 | 12.7464 |
| 2024 | 9 | 6 | 2283 | 1.4455 | 3.0223 | 6.7893 |
| 2024 | 9 | 7 | 2282 | 15.3374 | 13.9351 | 14.3295 |
| 2024 | 9 | 8 | 2282 | 2.6731 | 4.6889 | 8.5890 |
| 2024 | 9 | 9 | 2283 | 14.3671 | 15.5935 | 13.6224 |
| 2025 | 7 | 1 | 21383 | 25.0199 | 19.1367 | 14.5443 |
| 2025 | 7 | 2 | 21386 | 17.3571 | 17.2215 | 15.0706 |
| 2025 | 7 | 3 | 21390 | 13.8382 | 14.7218 | 15.2454 |
| 2025 | 7 | 4 | 21381 | 14.1808 | 14.6392 | 15.7991 |
| 2025 | 7 | 5 | 21387 | 13.2510 | 14.5275 | 15.7339 |
| 2025 | 7 | 6 | 21397 | 4.1828 | 6.3560 | 9.8799 |
| 2025 | 7 | 7 | 21389 | 12.6233 | 13.4882 | 13.8903 |
| 2025 | 9 | 1 | 2280 | 20.3509 | 16.3596 | 12.7632 |
| 2025 | 9 | 2 | 2279 | 15.7964 | 11.7595 | 12.1983 |
| 2025 | 9 | 3 | 2280 | 12.1930 | 12.1491 | 10.7018 |
| 2025 | 9 | 4 | 2283 | 3.9860 | 7.7092 | 9.1546 |
| 2025 | 9 | 5 | 2280 | 14.1228 | 13.9474 | 14.1667 |
| 2025 | 9 | 6 | 2282 | 1.9281 | 4.0316 | 5.6091 |
| 2025 | 9 | 7 | 2280 | 13.9912 | 14.2982 | 14.2982 |
| 2025 | 9 | 8 | 2282 | 3.4181 | 4.5136 | 8.4137 |
| 2025 | 9 | 9 | 2282 | 14.7677 | 15.2498 | 12.7082 |

## 6. 7車・9車の違い

全車番の実発走者ベース1着率は7車14.3412%/14.3495%、9車11.1322%/11.1701%（2024/2025）。
1番が高く6番が低い未補正傾向は両車立て・両年で観測される。
9車は4/6/8番も低い傾向だが、能力・番組編成を統制していない。
7車と9車の全体率差を車番効果へ転用しない。欠場と同着があるため単純な1/7・1/9への一致も強制しない。

## 7. 保存枠番の率・欠損

実DBの非NULL割当は、9車でbike1/2/3→frame1/2/3、4/5→4、6/7→5、8/9→6。
7車の少数非NULL例は1/2/3/4/5→同番、6/7→6。これは**保存分布の観察**で、公式制度確認ではない。
ParserはPJ0315のwakubanをnullable 1〜9として保存する。観測非NULLはすべてその範囲内で、
同車立て・同車番に複数の非NULL割当は0。公式対応表との矛盾件数は、公式資料未入手で未確認。
NULLは欠損/非適用を判別できず、FRAME_NOT_APPLICABLEへ既定分類しない。

7車のNULLは2024:150,066/150,402登録（21,438/21,486レース）、2025:149,520/149,933登録
（21,360/21,419レース）。非NULLは336/413登録、わずか48/59レース。
9車は両年全20,547登録で枠番あり。NULLを除いた枠別結果を全7車へ一般化しない。
`frame_rider.csv` のNULL bucketは母集団照合用の未確認枠群であり、一つの実在枠ではない。

### 選手単位の枠条件付き率

単位%。分母はその保存枠に属する実発走者。7車の少数表も `frame_rider.csv` に全件保存した。

| 年 | 9車の枠 | 発走者分母 | 1着 | 2着 | 3着 |
|---|---:|---:|---:|---:|---:|
| 2024 | 1 | 2279 | 22.642 | 18.780 | 12.067 |
| 2024 | 2 | 2282 | 13.628 | 12.796 | 12.489 |
| 2024 | 3 | 2281 | 11.662 | 12.100 | 10.872 |
| 2024 | 4 | 4563 | 9.226 | 9.665 | 10.651 |
| 2024 | 5 | 4565 | 8.390 | 8.478 | 10.559 |
| 2024 | 6 | 4565 | 8.521 | 10.142 | 11.106 |
| 2025 | 1 | 2280 | 20.351 | 16.360 | 12.763 |
| 2025 | 2 | 2279 | 15.796 | 11.760 | 12.198 |
| 2025 | 3 | 2280 | 12.193 | 12.149 | 10.702 |
| 2025 | 4 | 4563 | 9.051 | 10.826 | 11.659 |
| 2025 | 5 | 4562 | 7.957 | 9.163 | 9.952 |
| 2025 | 6 | 4564 | 9.093 | 9.882 | 10.561 |

### 枠単位の入着発生率

少なくとも1人が発走したその枠のレースを分母にし、少なくとも1人が該当rankなら1イベント。
同枠2人が同着でも1。枠1〜3は1人枠なので上表と一致するが、複数人枠は一致しない。
全枠・年・車立ての分子/分母/CIは `frame-events.csv`。

| 年 | 9車の枠 | レース分母 | 1着発生 | 2着発生 | 3着発生 |
|---|---:|---:|---:|---:|---:|
| 2024 | 4 | 2283 | 18.441 | 19.273 | 21.288 |
| 2024 | 5 | 2283 | 16.776 | 16.951 | 21.113 |
| 2024 | 6 | 2283 | 17.039 | 20.280 | 22.208 |
| 2025 | 4 | 2283 | 18.090 | 21.638 | 23.303 |
| 2025 | 5 | 2283 | 15.900 | 18.309 | 19.886 |
| 2025 | 6 | 2283 | 18.178 | 19.711 | 21.069 |

## 8. 開催種別・級・開催grade別

全47,471対象レースのday_kind原文は `1`。
ScheduleParserがリンクのdata-pprm-dkbnをそのまま取得する実装を確認したが、
通常/ナイター/ミッドナイトへの対応根拠は未確認。全件 `UNKNOWN` と解釈し、
発走時刻だけでミッドナイトへ分類しない。公式対応資料の提示をユーザーへ質問したが、確認できるまで採用しない。
`session_raw_bike.csv` は原値1による照合表であり、公式通常開催の成績ではない。

保存race_typeの接頭辞だけでA/Sを分離した `race_class_bike.csv` と、
開催grade原文別の `meeting_grade_bike.csv` を補助保存した。選手級班の代理ではない。
A級チャレンジ等の略記は公式分類表へ変換せずA内に含め、段階別/番組編成調整とも呼ばない。
7車の1番1着率はA級28.2337→26.2220%、S級21.5517→21.0769%。
6番はA級3.6124→4.1784%、S級3.6931→4.1975%（2024→2025）。
級で分けても未補正の差は残るが、級内の得点差・開催種別・割当方式は未統制。
9車のA級は各年4レースだけで、一般化せず件数を伴う参考値として保持する。

## 9. 競輪場別

`track_bike.csv` に年×車立て×保存racetrack_id×車番の分子・分母・全率/CIを保存。
7車は両年41場、9車は2024年38場/2025年37場。
全てのこれらの場で1番の未補正1着率が6番より高かった。
1番実発走分母の範囲は7車2024:115〜830/2025:57〜1,082、
9車2024:33〜135/2025:37〜116。少数場での高率を「有利車番」と認定しない。
場別でも選手能力・割当・開催種別の交絡は残り、場を分けたこと自体は独立効果の証明ではない。

## 10. STAT01・実力補正の可否

年間STAT01は2024 run1、2025 run27。終端PARTIALLY_SUCCEEDED、finished_at設定、
error0、processed=target、全保存result数=宣言target_entry_countを確認。
対象7/9の170,949/170,480行はrace/entry/player/bike/hash/cutoff整合が全件成功。
得点利用可能は170,441/170,004行だが、**source_fetched_atは全件発走時刻以後**。
保存されたinput_as_ofが発走前でも、原得点が当時観測された証明にはならない。
現行existing-db Stat01InputRepositoryも汎用fetched_atを観測時刻へ渡す経路である。

したがって発走前得点の適切な証拠を今回確保できず、得点順位別率・最高得点配置割合・
共通順位構成への標準化を「実力補正済み結果」として算出/公開していない。
`score_adjustment=NOT_ESTIMATED_NO_VERIFIED_PRESTART_STAT01`。
現在race_entries.race_scoreや仮の順位で穴埋めしない。ミッドナイトの得点順編成という
交絡仮説も、この保存day_kindだけでは該当レースの実証をできない。

## 11. 95%CI・年別安定性・限界

全CSVの実発走分母の各率と枠イベント率へWilson95%参考区間を付けた。
7車1番の1着CIは2024 [26.1285,27.3126]%、2025 [24.4439,25.6049]%。
7車6番は [3.3886,3.8894]%/[3.9227,4.4594]%。
9車1番は [20.9701,24.4050]%/[18.7488,22.0527]%、
9車6番は [1.0311,2.0230]%/[1.4394,2.5784]%。
粗い高低傾向は2年とも残るが、1番の率などの水準は変わっており固定効果とは扱わない。

Wilsonは独立Bernoulli近似の参考値で、開催・同一選手・レース内/同枠複数選手の依存を補正しない。
車番差CIを各CI端点の引算で作らない。race-cluster bootstrap・有意差検定・多重比較補正は今回未実施。
探索的な場/級/枠の比較と同着を含む記述統計で、因果的有利性・予測精度改善・正式採用根拠ではない。
2024/2025は既に使用済みdevelopmentであり未観測holdoutではない。

## 12. 現行STAT39との重複・不足

Calculatorは `STAT-39-existing-db-v1`。
FIELD_BIKE=同じ宣言車立て/車番、FIELD_BASELINE=同車立て全車番、TRACK_FIELD_BIKE=同じ場/車立て/車番、
FIELD_FRAME/TRACK_FIELD_FRAME=保存枠別。
履歴は男子A/S、CONFIRMED/CORRECTED、history_from以後かつscheduled_start_at < target.input_as_of。
TargetのNULL input_as_ofをpreflightで拒否し、current時刻/発走時刻へfallbackしない。
NormalizerのFINISHED/TIEDだけをPopulation bucketへ追加し、正常完走分母でwin/top2/top3を算出。
異常/DNSはこのSTAT39率の分母に入らない。UNKNOWN_ABNORMALをstarted=trueとする共通Enumと違い、
今回の研究は未知を未確定分母として除外するが、今回の未知件数は0。

finish percentile=(N-rank)/(N-1)。過去raceの全entryに正の得点がある時だけ、
competition rankに基づくscore percentileを作り、その差を得点期待残差とする。
過去得点にはsource_entriesの保存現在値を使い、全entry数/得点の欠損を監査する。
対象本人のSTAT01 snapshot使用と、過去全entryの得点時点証明は別である。
残差の存在を因果補正や発走前実力証明へ読み替えない。
250履歴rowの前進cursor、累積bucket/hash chain、target working batch5raceで処理する。

| 採用参照run | 期間 | status | race | entry/result | history_from | STAT01 |
|---:|---|---|---:|---:|---|---:|
| 21 | 2024通年 | SUCCEEDED | 25,624 | 182,004 | 2023-01-01 | 1 |
| 57 | 2025通年 | SUCCEEDED | 25,273 | 180,005 | 2024-01-01 | 27 |

現行Bt02SourceManifestの明示ID/UUIDと実DBが一致。両runは版・終端・全件数・error0を検査した。
部分期間run19（2024-01-01、94race/645entry）は年間参照に選ばない。latest自動選択はしない。
研究対象7/9への保存STAT39は170,949/170,480行すべてVALID、STAT01 lineage/hash・cutoffの不一致0。
ただし平均保存履歴win_rate 0.1414437557/0.1413509307は各target時点の過去累積値の平均であり、
今回の年間事後率と直接引算していない。分母だけでなく期間・cutoffも異なる。
32車番群の正常分母の差分式だけを既存Batch04CalculatorSupport::deltaへ渡し、算術一致を確認した。
これは保存累積値の再計算でも、既存runの再実行でもない。

実モデル利用も確認した。Bt02SignalRegistryは
`features.FIELD_BIKE_DELTA.mean_score_expectation_residual` をSTAT39入力に使う。
既存最終C1のrun01/final modelにはSTAT39 active係数10個があり、3順位で各10個非ゼロ。
保存artifactのbytes/SHAとmodelが一致し、文書記録のmodel SHA
`e3cc1f7f10af60bb22f97a2172ca52ef2c09a3393222ee46c25619de752d43a1` も一致。
モデルをロードして予測/学習を再実行していない。全既存成果物を再hash監査したわけではない。

既存にexact2着/3着率、実発走分母、枠イベント率、公式割当・開催分類、初手/ライン/能力の独立補正が不足する。
旧PR87の生のFIELD_BIKEカテゴリ追加比較は既存記録上NOT_ADOPTED/主Gate未達。
今回の粗い関連を根拠にその否定結果を変更しない。

## 13. 新規STATの必要性

車番・枠番は既存STAT39の責務内。記述率の不足だけでSTAT47等を追加する理由にはならない。
判断Bが未確定なので新規配点・モデル投入・正式採用を提案しない。
`NO_INDEPENDENT_EFFECT_CONFIRMED`を「効果なしを実証した」とも言わず、今回は証拠不足とする。

## 14. 提案のみ・実装なし

既存STAT39の別版候補として、正常/実発走の二分母、exact P2/P3、同着/未知/非適用coverage、
枠の選手率/レースイベント率を別fieldへ追加する仕様をレビューする余地がある。
能力/割当/開催/ライン補正には、対象時点の原資料・公式制度対応・本人ID整合・common supportを先に要求する。
独立効果と予測への増分は別問題で、後者の検証は別途承認されたdevelopment工程で行う必要がある。
今回Calculator/式/coefficients/Gate/モデルを変更せず、points/confidenceを作らない。

## 15. 実行証跡・未確認事項・次工程

新規の永続証跡root:
`/home/shinya/neo-keirin-artifacts/stat39-bike-frame-probability-research-01/research-20261014-051106-5eab37ef/`

schema/access/connection、EXPLAIN、preflight inventory/run、quality/linkage/ties、最終集計JSON、
CSV8本、summary、独立算術/モデル検査、実行SHA/SQL SHA/時刻/終了コード/peak、manifest/COMPLETEを保存。
manifestは各ファイルのbytes/SHA、対象期間・母数・SQL版、確認した直接依存コードのSHAを記録する。
大きい原本・Raw・モデル・年次資料はコピーせず、削除/退避も0。

実際の主要コマンド（rootを上記証跡pathとする）:

```bash
php -d memory_limit=128M "$root/research.php" catalog
php -d memory_limit=128M "$root/research.php" preflight "$root/preflight.sql"
php -d memory_limit=128M "$root/research.php" quality03 "$root/quality.sql"
php -d memory_limit=128M "$root/research.php" linkage "$root/linkage.sql"
php -d memory_limit=128M "$root/research.php" final docs/sql/research_stat39_bike_frame_2024_2025.sql
php -d memory_limit=128M "$root/prepare.php"
php -d memory_limit=128M "$root/verify.php"
git diff --check
```

最終DB実行は2026-10-14 05:18:17〜05:18:30 JST、12.433846秒、PHP8.5.4、exit0/peak16MiB。
最終5集計は同一transactionで各2回完全一致。初版から変更していない品質・枠イベント・保存STAT・
得点時点集計は別transaction間でも一致し、主集計もA級のラベル変更以外は同じ数値だった。
CSV各層を合計すると年×車立ての元の分子・分母・状態数へ戻り、top2=P1+P2/top3=P1+P2+P3が全行成功。
Wilson既知値・分母0は独立算術チェック成功。prepare/verify最終exit0。
途中qualityの集約式エラー2回(exit1/SQLSTATE42803)と、調査用モデルチェックの
artifact.model構造取り違え1回(exit255)は失敗記録・当時のscript/SQLを保持した。
いずれも調査コードだけを修正し、DB・アプリ・保存モデルは修正していない。
PHPUnit/全体テスト/Pintは研究のみのため未実行。既存テスト結果を今回の実行として転用しない。

未確認: 公式枠割当と制度時点、day_kind公式意味、発走前得点/車番観測履歴、結果本人ID、
初手/ライン役割・割当方式、依存性補正後の差CIと因果効果。効果量の正式閾値を勝手に決めない。
DB書込み・Migration/統計build/run生成・HTTP/Raw再取得・学習/予測/性能評価・2026実データ参照は0。
historical_as_of_available=false、formal_adoption=false、prediction_use=NOT_AUTHORIZEDを維持する。
既存成果物は読取りのみで、全量不変性再監査は行わない。変更は新規報告書とSQLだけ。
commit/push/PR作成/mergeなし。次は**この報告/SQL/証跡のレビューのみ**で、自動的に製造工程へ進まない。
