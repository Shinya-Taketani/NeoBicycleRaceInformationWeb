# GROWTH-ADJUSTMENT-CALIBRATION-01

## 結果閲覧前契約 / 2026-09-19

PR #60 merged、main `62abf52c612038cfd32e1ccbfd069a319981e629` のclean状態から
`experiment/growth-adjustment-calibration-01` を作成。旧v1/v2のPHP・文書・固定成果物は変更しない。

対象は修正版TACTICAL-HISTORY run-01のOuter C1、2024年25,212レース/179,089出走、2025年24,866レース/177,120出走。
登録manifestからモデル・入力・予測・正解・保存済み寄与を特定し、hashをSTART/ENDで確認する。
2024モデルは2022-2023、2025モデルは2022-2024の固定fit。lambda=0.1、再学習・bin再作成なし。
既存ModelLoader/ Layout / Dataset / Predictor / ProbabilityScorer / E06 decoder / E05 MetricEvaluatorを再利用する。

growth sourceは固定v2 bundle。SCOREのyear/race_id/entry_id/player_id/raw/point/status/same_meeting_previousだけを
outcome-free snapshotへprojectし、それ以外のtarget outcome、B/Cはpredictionへ渡さない。
開催grade/競走区分は別の既存cohort分類として診断だけに使用。global weightは層別変更しない。

候補k=-50..50、w=k/100、101候補。全position共通でanchor'=anchor+w*point。
点0、欠損、w=0は元anchorそのものを保持。欠損はGROWTH_MISSING_NO_ADJUSTMENTとして0点と区別。
anchorが不変でも他出走者の補正により確率/相対的なPrimary順位は変わり得る。
全出走者のanchorが不変のレースだけは、w=0で照合済みの保存予測を厳密に再利用できる。
順位変更診断はPrimaryの1/2/3/OUTSIDE_TOP3。完全な4着以降の予測順序とは呼ばない。

w=0は全50,078レースの確率/decisionをcanonical全一致させ、保存済みPrimary4指標の未丸め分子分母と照合する。
2025について先に許可するのはw=0の既存成績再現確認だけ。非ゼロ候補の2025結果はselection seal後にのみ評価する。
2024のみで4指標のdelta（P1/P2/P3 >= -0.003、Hit@3 >=0）を満たす候補からHit@3最大、abs(k)最小、k昇順で選択。
selection.jsonに2024指標・全source hashes・grid・選択規則を保存し、selection-seal.jsonを確定してから2025候補へ進む。
2025 outcomeは選択関数へ渡さず、full grid/pooledはDIAGNOSTIC_ONLY_NOT_FOR_WEIGHT_SELECTION。
境界選択はBOUNDARY_SELECTED、範囲拡張なし。odds倍率exp(w)/exp(3w)/exp(-3w)はutility差の参考で、確率の倍率ではない。

2025のselected wにHit@3 delta>0かつP1/P2/P3 delta>=-0.003を要求。
満たせばDIRECTIONALLY_REPLICATED_DEVELOPMENT_ONLY、その他NOT_REPLICATED、w=0はNO_INCREMENTAL_ADJUSTMENT_SELECTED。
正式採用Gate、LIVE判定、bootstrap、2026参照は行わない。

## 実行・保存

plan/execute/reproduceの専用command。DB_CONNECTION=disabled、PHP/PHPUnit128MB。
固定sourceだけから再現し、本番DB接続・書込み・新規取得・Migrationなし。
保存先 `/home/shinya/neo-keirin-artifacts/growth-adjustment-calibration-01-20260919-01/`。
生成時sealと公開前のsource/code/selection再検証、不一致時のfailure証跡を保持する。
grid全予測は保持せずPrimary semantic hashと集計を保存。selected wは再集計可能なentry/race明細を保存。
commit/push/PR/mergeを行わず、未コミットレビュー待ちで停止する。

実行済みコマンド（固定bundleへのexecute再実行は拒否。再確認はreproduceを使用）:

```bash
DB_CONNECTION=disabled php -d memory_limit=128M artisan keirin:backtest:growth-adjustment-calibration --plan

DB_CONNECTION=disabled php -d memory_limit=128M artisan keirin:backtest:growth-adjustment-calibration --execute \
  --outer-root=/home/shinya/neo-keirin-artifacts/tactical-history-01-review-fix-20260916-01 \
  --growth-bundle=/home/shinya/neo-keirin-artifacts/growth-point-analysis-01-v2-20260919-01/evaluations/outer-c1-growth-v2-2024-2025-01 \
  --output-root=/home/shinya/neo-keirin-artifacts/growth-adjustment-calibration-01-20260919-01 \
  --analysis-id=outer-c1-score-growth-calibration-2024-2025-01

DB_CONNECTION=disabled php -d memory_limit=128M artisan keirin:backtest:growth-adjustment-calibration --reproduce \
  --output-root=/home/shinya/neo-keirin-artifacts/growth-adjustment-calibration-01-20260919-01 \
  --analysis-id=outer-c1-score-growth-calibration-2024-2025-01
```

実行時コード、コマンド、ログ、終了コードは同root内に保存。

## 初回実行結果

2024だけで選択した係数はk=3、w=+0.03（INTERIOR_SELECTED）。exp(w)=1.030454533953517、
exp(3w)=1.0941742837052104、exp(-3w)=0.9139311852712282。
selection seal: `5cc8457ae69875f84351e4eb2e31c2624a6b377b65dfa14b8842fac8ff6fb410`。
2025非ゼロ候補の評価前にsealし、2025/pooledで再選択していない。

| 年 | 指標 | C1 分子/分母 | 補正後 分子/分母 | C1 % | 補正後 % | 差 pp |
|---|---|---:|---:|---:|---:|---:|
| 2024 | 1着 | 10424/25158 | 10438/25158 | 41.434136 | 41.489785 | +0.055648 |
| 2024 | 2着 | 6041/25106 | 6085/25106 | 24.061977 | 24.237234 | +0.175257 |
| 2024 | 3着 | 4701/25094 | 4733/25094 | 18.733562 | 18.861082 | +0.127521 |
| 2024 | Hit@3 | 21091/75120 | 21181/75120 | 28.076411 | 28.196219 | +0.119808 |
| 2025 | 1着 | 9886/24789 | 9888/24789 | 39.880592 | 39.888660 | +0.008068 |
| 2025 | 2着 | 5743/24727 | 5735/24727 | 23.225624 | 23.193271 | -0.032353 |
| 2025 | 3着 | 4677/24739 | 4672/24739 | 18.905372 | 18.885161 | -0.020211 |
| 2025 | Hit@3 | 20241/73989 | 20230/73989 | 27.356769 | 27.341902 | -0.014867 |

固定2025判定は **NOT_REPLICATED**。2024の改善は2025へ継続しなかった。
位置指標は-0.003以内だが、Hit@3 delta > 0を満たさない。正式採用・LIVE・元C1への反映は行わない。
Primary変更は2024年1,088レース、2025年1,108レース。
変更レースのHit@3 gained/lost/netは388/298/+90、343/354/-11。
w=+0.01でも370/407レース、w=-0.01でも380/350レースのPrimaryが変化する。
全101候補の年別曲線と分子・分母の件数加重pooled曲線を保存した。後二者は診断専用。

欠損206/202件、同一開催0点118,820/117,729件は期待値と一致し、全件anchor不変。
0点総数は119,668/118,519件。Primary内外の相対移動は0点にも105/81件、欠損にも1/5件あり、
これは相手のutility変更によるもので自身への補正ではない。4着以降の順位を推定したとは扱わない。
開催grade/競走区分別は単一wの診断のみ。F2のHit@3差は+0.111070/-0.058319pp、
A1_A2は+0.157471/-0.045977pp。GPは各年31レースにすぎず一般化しない。
GP等は開催区分であり、当該開催内の対象レースの成績。単発のGP競走だけの成績とは表示しない。

w=0は両年の確率・decision全体と未丸め寄与が完全一致。初回実行のPHPピークは30MiB。
DB原本への接続はなく、ローカルSQLiteは識別情報のディスクspoolだけに使用。
DB無効の完全再現は成功し、全26生成物のhashが一致。再現時もPHPピーク30MiB。
2025への改善継続と、同じ入力からの数値再現性は別の検証である。
実入力全50,078レース・101係数で0点・欠損・同一開催0点のanchor不変を別途全件確認した。
補助検査スクリプトv1の型エラーは失敗版と診断を残してv2で修正。本体の計算コードは不変。

追加人工テスト125件/922 assertions成功。全体artisan testは1550成功・9 skip/11780 assertions。
128MB単一PHPUnitは既存TacticalGradeAnalysisTest開始時にメモリ不足となり、ログを保持。
制限を上げずファイル単位で全1559件を再確認し、1550成功・9 skip/11776 assertions。
変更PHP13ファイルの構文とPint、git diff --checkは成功。
全体Pintは未変更の`Bt03e08BoundedMemoryTest.php`に既存の整形違反1件があり失敗。
本番DB接続・2026実データ参照・再学習・正式Gate/bootstrap・commit/push/PR/mergeなし。
正式加点値の妥当性は未確認として、未コミットのレビュー待ちで停止する。
