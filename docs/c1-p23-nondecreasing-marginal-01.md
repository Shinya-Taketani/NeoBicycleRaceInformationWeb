# C1-P23-NONDECREASING-MARGINAL-01

## 実行前契約

開始main/origin `05eb5c63316128d19eab2da378ceca2c55990383`、PR89/90 MERGED。
branch `feature/c1-p23-nondecreasing-marginal-01`。旧比較・旧manifestは変更せず参考記録とする。
両旧Contract/decoder/文書は異なる規則で、今回rootは開始時存在せず、同一成分別制約比較の完了根拠はない。

- experiment: `C1-P23-NONDECREASING-MARGINAL-01-v1`
- candidate: `C1_WINNER_FIXED_P23_NONDECREASING_MARGINAL`
- decoder: `C1-P23-NONDECREASING-MARGINAL-v1`
- tie: `C1-P23-NONDECREASING-MARGINAL-TIE-v1`
- artifact_role: `FIXED_MODEL_DECISION_POLICY_COMPARISON`
- 元モデル版はTACTICAL-HISTORY-SEQUENTIAL-POSITION-v2。学習・lambda探索・bin生成0。
- LIMITED_DEVELOPMENT_EXPERIMENT_ONLY / historical_as_of_available=false / formal_adoption=false / live_use_authorized=false / points=null。
- DB/HTTP/Raw/2026/LIVE/正式C1置換は禁止。過去score gap P3未解決も維持。

保存元E06でdecisionを照合しP1=a0固定、元P2=b0・P3=c0の保存無条件周辺確率をt2/t3とする。
全相異非winner順序pairのうちP2(b)>=t2、P3(c)>=t3のみを許可し、binary64 P2+P3を厳密最大化。
元pairが最大と同値なら維持。それ以外の同値最大は
`TIE_VERSION|year|race_id|a0|b0|c0|b|c` のSHA辞書順最小、hash衝突はb/c昇順。
丸め/epsilon/clip/再正規化/貪欲選択/空集合fallbackは使わない。変更時は和の厳密増加を要求。
旧q2/q3や条件付きobjectiveを候補へコピーしない。元winner tieと制約内最大pair tieを区別する。
Evaluatorのsecond_third_tie_countは制約内最大pair数、technicalは元winner hashまたは新pair hash使用。
元pair同値維持は新hash使用ではない。Supportingとその診断は元のまま。
結果を使わず各raceで元C1 S <= PR90方式 S <= 今回 S <= E05無制約 Sを検証する。
旧コマンドや旧性能評価は再実行せず、既存decoderを算術照合にのみ呼ぶ。

固定sourceは入力manifest `7f4356b93f90203dc727dc95d6215a8d8ce3f44869c4441c3981087ff1099c26`
（stat35-c1-input-02/run-20260930-054458-8739da4b/result）と、
tactical-history-01-review-fix-20260916-01のexport
`4268b801b74b77cfb3a94b64832ff483e9eb5e3221ee8e75ceb467e5a5cf92e6` / contract
`5db3b57b08fb8b91dbe6c057b459e518252c454b0a4ff60531c2d671b7ce506a` の二束だけ。
Sourcesのpin/所属/COMPLETE/子sealを検証。本文は2024/2025入力・run-01保存確率・開放後labelsのみ。
旧候補・最終fit・2022/2023本文は使わない。

Reader::decisionsUsing / Decoder::baseline / Evaluation::evaluate(check)を再利用。
両年生成・seal・P1固定/成分別非低下確認後にlabels開放。未知field/結果混入/改変/集合不一致を拒否。
P1寄与・年別分子分母・計算された差/CI=0を検査し、0へ上書きしない。
Hit@3は全上位一意raceの位置一致数/(3×適格race)。全P2/P3的中差とは母集団が異なる。
Primary完全順序一致とSupporting MAP一致は別出力。
paired年層別race-cluster bootstrap 2000/seed20260812/Type7/年等重み、元Gateは変更しない。
主Gate: 全4CI下限 > -0.0015、Hit@3CI下限 >0、各年Hit@3 >=0、各年全4差 >=-0.003、integrity/再現成立。
補助STAT01 PASSを主Gateの代用にしない。確率非低下を実測精度非低下と解釈しない。
使用済みdevelopment結果を踏まえた仮説であり、未観測holdout/探索全体を補正した確認試験ではない。

新root `/home/shinya/neo-keirin-artifacts/c1-p23-nondecreasing-marginal-01/` の一意run/result。
1 execute内で独立原資料再読込み2run、動的意味ファイル比較、source/code終了時・公開前検証後COMPLETE。
技術失敗はNOT_EVALUATED/nullとして証跡を保持。上書きしない。

## 実測結果と判断

1着予測変更0、1着的中差0。2年の的中純増減は2着+21、3着+37、Hit@3一致位置+59。
**主GateはNOT_PASSED。今回方式を採用せず、元C1を維持する。**
各周辺確率の非低下は全件成立したが、2024年2着の実測的中は5件減少した。
入力上の非低下と実測精度を混同しない。技術的に比較・再現が成立したことは採用承認ではない。

率は%、差はpercentage points (pp)。以下の差は候補−元C1。

| 年 | 指標 | 元C1分子/分母 | 候補分子/分母 | 元C1率 | 候補率 | 差pp |
|---|---|---:|---:|---:|---:|---:|
| 2024 | 1着 | 10424/25158 | 10424/25158 | 41.434136 | 41.434136 | 0 |
| 2024 | 2着 | 6041/25106 | 6036/25106 | 24.061977 | 24.042062 | -0.019916 |
| 2024 | 3着 | 4701/25094 | 4719/25094 | 18.733562 | 18.805292 | +0.071730 |
| 2024 | Hit@3 | 21091/75120 | 21104/75120 | 28.076411 | 28.093717 | +0.017306 |
| 2025 | 1着 | 9886/24789 | 9886/24789 | 39.880592 | 39.880592 | 0 |
| 2025 | 2着 | 5743/24727 | 5769/24727 | 23.225624 | 23.330772 | +0.105148 |
| 2025 | 3着 | 4677/24739 | 4696/24739 | 18.905372 | 18.982174 | +0.076802 |
| 2025 | Hit@3 | 20241/73989 | 20287/73989 | 27.356769 | 27.418941 | +0.062171 |

年等重み差とpaired 95%CI（pp、同一抽選2000回）。表示丸めをGate判定には使わない。

| 比較 | 指標 | 年等重み差pp | 95%CI pp |
|---|---|---:|---:|
| 候補−C1 | 1着 | 0 | [0, 0] |
| 候補−C1 | 2着 | +0.042616 | [-0.022066, +0.106672] |
| 候補−C1 | 3着 | +0.074266 | [-0.032398, +0.186632] |
| 候補−C1 | Hit@3 | +0.039739 | [-0.00021549951877694178, +0.081059] |
| 候補−STAT01 | 1着 | +2.522432 | [+2.181796, +2.877100] |
| 候補−STAT01 | 2着 | +0.429863 | [+0.044280, +0.816975] |
| 候補−STAT01 | 3着 | +1.224780 | [+0.846592, +1.600047] |
| 候補−STAT01 | Hit@3 | +1.395992 | [+1.155132, +1.645925] |

主Gateのnon_inferiority / temporal / integrityはtrue、superiorityはfalse。
Hit@3 CI下限の未丸め率は `-2.1549951877694178e-6`、厳密な `>0` を満たさない。
補助STAT01 GateはPASS / GO_TO_FREEZEだが、主Gateの代替や正式freezeの許可にはしない。
今回の候補を事後に変更して再試行しない。

## 選択・評価監査

| 年 | race/出走 | P1/P2/P3予測変更 | P2/P3確率低下 | 元pair維持 | PR90と同じpair | E05と同じpair |
|---|---:|---:|---:|---:|---:|---:|
| 2024 | 25212/179089 | 0/350/1160 | 0/0 | 23767 | 24862 | 24333 |
| 2025 | 24866/177120 | 0/386/1199 | 0/0 | 23352 | 24480 | 24026 |
| 合計 | 50078/356209 | 0/736/2359 | 0/0 | 47119 | 49342 | 48359 |

変更pairは2,959レース。PR90方式と異なるpair736、無制約E05と異なるpair1,719。
旧候補をbaselineに使わず、同じ元確率からの算術参照のみである。
元pairを含む候補集合と参照S順序は全件成立。

| 年 | 全順序pair | 制約適合 | P2のみ低下除外 | P3のみ低下除外 | 両方低下除外 |
|---|---:|---:|---:|---:|---:|
| 2024 | 796784 | 26856 | 160744 | 122242 | 486942 |
| 2025 | 790814 | 26581 | 158998 | 121164 | 484071 |
| 合計 | 1587598 | 53437 | 319742 | 243406 | 971013 |

元pairと別pairが同率最大の維持件数、新pairのhash tie使用件数は実データでともに0。
各規則は人工ケースで別途検証済み。S差の年別合計は13.098680350726745 /13.20255819883593、
最大は0.05418081644984862 /0.06405634730937748。このモデル期待値差を的中差へ換算しない。

| 年 | 指標 | 両的中 | C1のみ | 候補のみ | 両不的中 | 除外 |
|---|---|---:|---:|---:|---:|---:|
| 2024 | 2着 | 5972 | 69 | 64 | 19001 | 106 |
| 2024 | 3着 | 4512 | 189 | 207 | 20186 | 118 |
| 2025 | 2着 | 5682 | 61 | 87 | 18897 | 139 |
| 2025 | 3着 | 4484 | 193 | 212 | 19850 | 127 |

Hit@3適格raceは2024年25,040件、2025年24,663件。
適格内のP2/P3純増減は-5/+18、+26/+20で、Hit@3位置差+13/+46とrace単位・年単位で一致。
全P3的中純増+37とHit@3適格内P3純増+38は、除外集合が異なるので同じ値にしない。
Hit@3改善/悪化/同値/除外race数は2024年262/247/24531/172、2025年291/246/24126/203。
P1寄与・各年分子分母・計算差/CIは完全一致し、0へ補正していない。

Primary完全順序一致は2024年1195→1186 /25040、2025年1179→1177 /24663（計-11）。
Supporting MAP完全順序一致は1236/25040、1198/24663で不変。
Supportingの全指標差/CI=0、元確率・Supporting診断は不変。

## 実行・再現・原本保護

実行root:
`/home/shinya/neo-keirin-artifacts/c1-p23-nondecreasing-marginal-01/run-20261008-uUZqYlrV/`

```bash
php -d memory_limit=128M artisan keirin:c1:p23-nondecreasing-marginal plan
php -d memory_limit=128M artisan keirin:c1:p23-nondecreasing-marginal execute \
  --output-dir=/home/shinya/neo-keirin-artifacts/c1-p23-nondecreasing-marginal-01/run-20261008-uUZqYlrV/result
```

process-localのtesting/SQLite/共有config cache不使用とDB/HTTP明示拒否下でexecuteは1回。
2026-10-08 12:51:37～12:56:17 JST、279.6818759441376秒、exit0、stderr0 bytes。
`memory_limit=128M`、peak33,554,432 bytes (32MiB)。学習・係数更新・lambda探索・bin生成は0回。
run-02は原資料を独立に再読込みし、コピーや追加標本合算をしていない。

実列挙した12意味ファイルが一致。source23件/code44件のSTART/END・公開前検証が成立。
保存候補・寄与からの独立再集計とbyte単位の再現照合は共有資料作成時にも検査する。
両年のseal・P1固定・成分別非低下検査を終えてからlabelsを開いたaccess-orderを保存。
旧比較コマンド・旧学習・旧監査の再実行は0。

result manifest: 24,031 bytes / SHA-256
`c970ec8f92ffbd05a5f3630d1633adee8f17ba3dc3429ad3b1f905a07ac9a918`。
COMPLETEは比較・不変性・独立再現成立を示し、主Gate通過を意味しない。

原Outerモデルの不変SHA-256:

- 2024: `38a78da4efc01249d3ad579f620c9473e1c231d19dc5eeb2e0b9e5a6bf26c249` (80,370 bytes)
- 2025: `f37452a8fe5cef4108f5c0357c1b880222742f7610ea5700fb54caa636bdce43` (81,018 bytes)

## 検証結果・レビュー資料

- 専用最終テスト: 62 tests /851 assertions、128M、exit0。
- 関連最終テスト: 348 tests /2806 assertions、128M、exit0。
- 最終通常全体: `php artisan test` 1回、3045 passed /29208 assertions /既存PG限定9 skipped、失敗0、exit0。
- 変更PHP7ファイルの構文検査と限定Pint成功。既存テスト削除・緩和・新規skipなし。
- 独立PHP128Mで100MiB超の人工JSONL 18,000raceをストリーミング処理。
- 人工全pair oracle、成分別除外、元pair同値維持/hash tie、微小差、5/7/9車・欠番、
  非単調ID・順序差、元Supporting/確率、正解変更不変、両年seal、P1寄与/Gate境界、
  不正source/入力/分母0、終了時drift、学習/DB/HTTP拒否を検証。

共有ZIP保存先（生成後のサイズ・SHAは同runのreview-zip.jsonと終了報告に記録）:
`/home/shinya/neo-keirin-artifacts/c1-p23-nondecreasing-marginal-01/run-20261008-uUZqYlrV/C1-P23-NONDECREASING-MARGINAL-01-review.zip`

契約、比較、最小race別寄与・選択明細、変更pair policy、監査/再現、manifest/COMPLETE、
実行/テストログ、文書と未コミット差分を収録する。原モデル・原確率・学習本文は重複添付しない。
START/END検証済み原本と旧成果物を変更せず、PR89/90の過去件数・テスト/hashを保持する。
実データの追加実行、結果を見た条件緩和、別候補探索へ進まない。
未コミットのコードと不採用結果のレビュー待ちで終了する。
