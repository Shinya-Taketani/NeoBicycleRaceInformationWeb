# TACTICAL-HISTORY-01

開始日: 2026-09-15。PR #55修正版（2026-09-16）: EVALUATED_AND_REPRODUCED / PASS_DEVELOPMENT_INCREMENTAL_EFFECT_ONLY。
引継ぎSHA-256: `e77e9aa67b6e77779a108e45509cb72be8a5ea3a9625b53f06680a8a66af7acc`。
出力root: `/home/shinya/neo-keirin-artifacts/tactical-history-01-20260915-01/`。
修正版root: `/home/shinya/neo-keirin-artifacts/tactical-history-01-review-fix-20260916-01/`。旧入力・run・診断は上書きしない。

## 入力定義

入力の固定順序は以下の4回数。PJ0315集計値やB/H/Sは使わない。

1. `HIST_OBSERVED_ESCAPE_TOP2_COUNT_120D_PRE_MEETING`
2. `HIST_OBSERVED_MAKURI_TOP2_COUNT_120D_PRE_MEETING`
3. `HIST_OBSERVED_SASHI_TOP2_COUNT_120D_PRE_MEETING`
4. `HIST_OBSERVED_MARK_TOP2_COUNT_120D_PRE_MEETING`

Tは対象開催starts_onの00:00 JST。窓は `[T-120日,T)`、同一player_id・別開催・予定発走時刻で選定。
対象開催情報とinput_as_ofを照合する。対象race自身・同開催・T以降は除外する。
対象と履歴は2022-2025の男子A/S級だけ。2021/2026は読まない。

CONFIRMED/CORRECTEDレースのFINISHED/TIEDかつ公式1/2着について、厳密な語彙「逃げ」「捲り」「差し」「マーク」を数える。
各語と1/2着との関係は[KEIRIN.JPの出走表説明](https://keirin.jp/pc/static/beginner/basics/racecard.html)を確認した。
120日という窓、過去公開時刻、PJ0315保存仕様をこの説明から推定するものではない。
マークは2着のみ。1着マークはINVALID_HISTORY、1/2着の未知/空値はMISSING_METHOD_HISTORY。
既知出走に結果がなければPARTIAL_HISTORY、中止は除外。v2では対象年・選手・開催・race/entry識別検査後、中止を予定時刻欠損の検査より先に除外する。3着以下の空決まり手は欠損扱いにしない。
2022-01-01より前へ出る窓はLEFT_TRUNCATED、観測可能な履歴0件はNO_HISTORY。
これらの追加4値はNULLとし、確認できた0回とは区別する。C1だけレースを除外しない。
履歴のID・構造矛盾はfail closed。取得日時を過去公開時刻や訂正有効時刻へ読み替えない。

## モデル・検証契約

C0はSTAT-01 RACE_SCORE_Z係数1と既存12 STAT。C1は4回数だけ追加し、全3順位へ接続する。
E03 v2のconditional categorical NLL、training-local bin/support中心化、正規化L2/group/smoothnessを維持。
M/G/edge数は各モデルのactive係数・group・numeric edgeに従う。損失・正則化は既存実装との一致を検証するが、誤ったsolver更新への一致は要求しない。
空の追加groupと追加項目のsupport=0 binは非activeとする。numeric edgeはactive bin間で構成し、実際のM/G/edge数・support重みをモデルへ記録する。
lambda gridは `[0,1e-6,1e-5,1e-4,1e-3,1e-2,1e-1,1]`、strong-to-weak、200 accepted updatesと既存停止条件を変更しない。
PR #55修正版solverは `TACTICAL-HISTORY-CONSTRAINED-EUCLIDEAN-FISTA-v2`、modelは `TACTICAL-HISTORY-SEQUENTIAL-POSITION-v2`。
support比率wの制約w・beta=0上へ `z=v-w*(w・v)/(w・w)` で直交射影し、`tau=step*lambda/(G*sqrt(d))` のgroup縮小をzへ適用する。
初期値にも同じ射影を使用する。restart後は丸め誤差を超える目的関数増加を受理せずbacktrackingする。
既存の係数変化1e-7・相対目的関数変化1e-10に加え、固定reference step=1の近接勾配残差と中心化残差が1e-7以下であることを要求する。小さいstepによる見かけの停止を収束としない。
追加状態と観測件数は監査専用で、5番目の特徴量にしない。

Outer 2024は2022→2023で選択、2022-2023 refit。Outer 2025は2022→2023と2022-2023→2024で選択、2022-2024 refit。
対象自身の正解を予測器へ渡さず、予測manifest固定後に評価する。対象T以降/同開催を変更してもその対象の履歴と予測を変えない。
C1-C0とC1-STAT-01はそれぞれpaired race bootstrap、2000回、seed20260812、year-stratified、Type7。
追加効果はHit@3年等重みCI下限>0、全4主指標下限>-0.0015、各年Hit@3差>=0、各年全4差>=-0.0030と整合性/再現性を要求する。
既存対STAT-01 Gateは別に報告する。

## 保証範囲

`HISTORICAL_EVENT_RECONSTRUCTION / BACKFILLED_FINAL_RESULT / DEVELOPMENT_ONLY`。
coverageはOBSERVED_DB_HISTORYであり、全過去出走の完全捕捉や公式4ヶ月値を意味しない。
event_cutoff_verifiedとpublication_time_verifiedを別記録する。公開時点不明はUNKNOWNで維持。
2026、LIVE採用、E08/DATA-AUDIT再実行は許可しない。
旧TACTICAL-PILOT-01はBLOCKED_INPUT_SEMANTICSのままであり、消失したログを復元したと称さない。

## 実装・実行経路

独立namespaceは `App\Domain\Keirin\Backtest\Experiments\TacticalHistory`。
既存E03/E06/E08の正式クラス、SourceManifest、版番号は変更しない。
出走IDだけの2022-2025索引を出力先SQLiteへ作る。本番DBに索引やテーブルを追加せず、結果照会は各player/開催前120日窓に限定する。
入力作成はREAD ONLY / REPEATABLE READと共有exported snapshotのfingerprint検証を使う。
生成後は別の新しいREAD ONLY snapshotで、固定STAT・出走ID索引・履歴窓・対象開催メタデータの不変性を再確認する。

予測用データは対象のrank/status/labelsを持たず、C0/C1両予測の内容sealと対象ID一致を確認してからouter labelsを開く。
C0/C1は両方とも修正版solverで新規学習する。旧E06モデルを修正版C0の代わりに使わない。旧E06の成績は過去の参考値に限る。
非収束candidateの診断は逐次保存し、One-SEから除外する。選択済みrefitの非収束は停止し、定数やlambda gridを変更しない。
Primaryの順位・集合指標と、凍結Gate用のMAP/marginal supporting指標は別名で出力する。

実行スクリプト、stdout/stderr、終了コード、コード写しは上記の永続rootに保存する。
中断した入力試行は未確定として保持し、学習には使用しない。初期2試行はID順序の誤った前提の修正と読取負荷の改善で停止したもので、データの性能FAILではない。
正式E03/E06/E08の再実行コマンドは起動しない。外部READ ONLYスクリプトに既存の固定STAT検査と履歴検査があったことを維持し、両方必須の接続をSourceIntegrityで回帰検証する。

## v1の実行結果（変更前の記録）

- 引継ぎZIPは27,280,880 bytesと指定SHA-256が一致。既存文書は開始HEAD `db653d64a34914802e49d93818720a65ea2eb8e2` に保存済みだったため、巻き戻さず `experiment/tactical-history-01` を作成した。
- 入力レース数は2022年24,394、2023年25,197、2024年25,212、2025年24,866。NULL追加値の行も同じ対象集合へ保持した。
- 生成中の共有snapshotと、生成後の別READ ONLY snapshotで固定STAT・履歴窓221,559件・対象706,051出走・717,709出走IDの不変性を確認。入力生成のPHPピーク70MiB。
- Outer 2024のC0は旧E03モデルのbin/supportと旧E06 CSV全項目が一致したため再利用。再構成予測25,212件、C0新規fit=0、検証済み再利用=1 outer。
- C1のinner A学習を1回実行し、固定8lambda候補すべてが `NUMERICALLY_NON_CONVERGED`。lambda=0.1はPOSITION_2、残る7候補はPOSITION_1で200 accepted updatesに到達。C1学習のピーク26MiB、終了コード2。
- 成立したC1候補=0。選択lambda・outer refit・C1予測・精度比較・paired CI・Gate・実データ再現性検証は未実施。C1-C0/C1-STAT-01の1着・2着・3着・Hit@3はすべて `NOT_EVALUATED`。
- 非収束を性能FAILや差0へ変換せず、solver定数・gridを緩和しない。次の変更には新しいユーザー指示が必要。
- 新規24テスト/303 assertions成功。全体1,026テスト中1,017成功・9スキップ、7,469 assertions（128MiB）。新規差分PintとPHP構文検査は成功。全体Pintは既知・未変更の `Bt03e08BoundedMemoryTest.php` の `statement_indentation` だけ失敗。
- 入力定義のfit前固定版は成果物rootの `field-definition.md` と `frozen-experiment-contract.json`。この節は実行後の状態追記であり、凍結した定義を変更しない。
- `performance-summary.md`、`candidate-diagnostics.csv`、`run-01/run-state.json`、`logs/` とコード写しを同じ永続rootへ保存する。共有ZIPの実在・CRC・SHAは `export-verification.json` で確認する。

## PR #55修正版の実行状態

- 2026-09-16の明示許可に基づく修正。開始HEAD `fc1fb673cd0b067edfde88121c3607e4e11f753c`、既存PRブランチを継続する。
- 保存済み221,559窓の中止・予定時刻NULL該当は0件。今回の再集計対象0件で、既存4年分の入力内容・対象集合をそのまま再利用する。入力manifestは親SHAとv1継承を明記した別版として保存する。
- 人工問題で修正前の誤った最適解と中止欠損を検出し、修正後の制約・独立な最適解・目的関数・残差・restart単調性を検証する。
- 実学習前の固定52 STAT・履歴221,559窓・対象706,051出走・717,709出走IDのREAD ONLY照合に成功。
- Outer 2024はC0/C1ともlambda=0.1で再学習成功し、各25,212レースの予測を固定。その後に2024年labelsを開放した。
- 初回はOuter 2025もC0/C1ともlambda=0.1で再学習成功し、各24,866レースの予測固定後に2025年labelsを開放した。最大メモリ26MiB。
- 同一条件での独立した実学習をもう1回完了。各runで8学習path・40lambda候補を検証し、旧E06再利用0。両年のC0/C1はすべてlambda=0.1。モデル・bin/support・選択・候補診断・予測等100ファイルがバイト単位で一致した。
- 評価と2000回の年層別paired bootstrapも完全一致。比較JSON SHA-256は両runとも `6fd5afac169056ae49a4bb68db28c2500028d909e2d242987b7f12a25ad731a3`。
- 終了時もREAD ONLYで固定52 STAT・履歴221,559窓・対象706,051出走・717,709出走IDを検証し、不変。本番write=0、2026参照=0。学習・比較のPHPピーク26MiB。
- 修正後は関連31テスト/434 assertions、全体1,033テスト中1,024成功・9スキップ、7,600 assertions（128MiB）。差分Pint・PHP構文・`git diff --check`成功。全体Pintは既知・未変更の `Bt03e08BoundedMemoryTest.php` の `statement_indentation` のみ失敗。

### 修正版の精度比較

単位はpercentage point。2024/2025を年等重みとし、95%CIは2000回・seed20260812・Type7のpaired race-cluster bootstrap。年別CIではない。

| 指標 | C1-C0差 [95%CI] | C1-STAT-01差 [95%CI] |
|---|---:|---:|
| 1着 | +2.066185 [+1.741520, +2.395045] | +2.522432 [+2.181796, +2.877100] |
| 2着 | +0.238033 [-0.098844, +0.620193] | +0.387247 [+0.000109, +0.784484] |
| 3着 | +0.979463 [+0.697511, +1.274654] | +1.150514 [+0.796412, +1.528833] |
| Hit@3 | +1.097709 [+0.893535, +1.325206] | +1.356253 [+1.113567, +1.608789] |

| 年 | 候補 | 1着率% | 2着率% | 3着率% | Hit@3率% |
|---|---|---:|---:|---:|---:|
| 2024 | C0 | 39.367199 | 23.723413 | 17.781143 | 26.959531 |
| 2024 | C1 | 41.434136 | 24.061977 | 18.733562 | 28.076411 |
| 2024 | STAT-01 | 38.607997 | 23.404764 | 17.677532 | 26.569489 |
| 2025 | C0 | 37.815160 | 23.088122 | 17.898864 | 26.278231 |
| 2025 | C1 | 39.880592 | 23.225624 | 18.905372 | 27.356769 |
| 2025 | STAT-01 | 37.661866 | 23.108343 | 17.660374 | 26.151185 |

1着/2着/3着の分母は2024年25,158/25,106/25,094、2025年24,789/24,727/24,739。Hit@3は順位一意の25,040/24,663レースで、分母はその3倍の75,120/73,989。異なる指標の対象を混同しない。

追加効果Gateは `PASS_DEVELOPMENT_INCREMENTAL_EFFECT_ONLY`（NI・temporal・superiority・integrityすべてtrue）。既存対STAT-01 Gateも `PASS / GO_TO_FREEZE`（8条件すべてtrue）。
C1-C0の2着CIは0を含むので、全順位で優越性が確認できたとは述べない。旧E06は旧result.jsonをhash照合した参考値のみで、修正版C0の代用も再評価もしていない。

この結果はdevelopment期間の観測済み履歴による追加効果であり、当時の公開時刻はUNKNOWNのまま。正式採用・LIVE・2026利用は未許可で、次工程には自動移行しない。
詳細数値・診断・再現性・実行ログは修正版rootに保存。共有用小型ZIPは `TACTICAL-HISTORY-01-review-fix-report.zip` とし、`export-verification.json` で実在・全member hashを確認する。
