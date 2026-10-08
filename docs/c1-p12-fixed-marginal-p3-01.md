# C1-P12-FIXED-MARGINAL-P3-01

## 限定契約

- 用途: `LIMITED_DEVELOPMENT_EXPERIMENT_ONLY`、学習0、`historical_as_of_available=false`、`formal_adoption=false`、`live_use_authorized=false`、`points=null`。DB/HTTP/Raw/2026は禁止。
- 開始main/origin: `85c411960241749d3285c72543f06a96d01bf05c`。依存PR89はユーザー指定のレビュー済みhead `6637b04994ef952bf7dfff65f6caa8ef06afbd14`、開始時GitHubでOPEN/未マージ。新branch `feature/c1-p12-fixed-marginal-p3-01` は同headから作成した。後続PRのbaseは依存PRの状態に合わせる必要がある。
- 既存記録の検索では同じ「元P1/P2固定・無条件P3最大」比較は確認されなかった。旧E08の再学習、PR89のP2/P3同時変更とは別であり、旧結果を転記しない。
- 入力は固定C1 input manifest `7f4356b93f90203dc727dc95d6215a8d8ce3f44869c4441c3981087ff1099c26` と、TacticalHistory v2 run-01 export `4268b801b74b77cfb3a94b64832ff483e9eb5e3221ee8e75ceb467e5a5cf92e6` / contract `5db3b57b08fb8b91dbe6c057b459e518252c454b0a4ff60531c2d671b7ce506a`。既存Sourcesで所属・版・子sealを検証し、2024/2025だけ読む。PR89候補/最終fitモデルへ置換しない。

## 選択規則

実験版 `C1-P12-FIXED-MARGINAL-P3-01-v1`、候補 `C1_P12_FIXED_MARGINAL_P3`、decoder `C1-P12-FIXED-MARGINAL-P3-v1`。
保存probabilitiesを既存E06で照合し、原decision全体をbaselineとして保持する。
元P1=a0、元P2=b0、元P3=c0とし、a0/b0を除く出走者の保存 `position_3_probability` の厳密最大値mを求める。
c0の値がmならc0を維持する。違えば厳密最大集合からSHA-256の辞書順最小を選択する。
入力は `C1-P12-FIXED-MARGINAL-P3-TIE-v1|year|race_id|a0|b0|candidate_bike`、hash衝突時は車番昇順。
epsilon、丸め、条件付きq3、P3再正規化、結果に応じた例外は使わない。
P1/P2固定・3人相異・Supporting不変・新P3確率>=旧P3確率、変更時は厳密>を要求する。

candidateは専用policyであり、古い `selected_q3_given_winner` / 目的関数をコピーしない。
元/新P3の周辺確率、変更有無、理由、最大集合件数、同値維持、hash選択と固定P1/P2の出典を保存する。
Evaluator互換の `second_third_tie_count` は「今回のP3最大集合件数」で、旧pair探索件数ではない。
`primary_decision_tied` は元P1 tieまたはP3 tie、`primary_technical_tiebreak_used` は元P1 hashまたは新P3 hash。
同率最大の元P3維持は新P3 hash使用には数えない。Supportingの選択/診断は元のまま。
Primary完全順序一致はSupporting MAP完全順序一致と別比較として保存する。

## 実行・監査

PR89の検証をpublic baseline境界へ抽出し、Readerには専用decode callable、評価には寄与検査callbackだけ追加。
旧API既定動作、共通Files::canonical、E05/E06数学、旧契約、Evaluator、bootstrap、Gateは変更しない。
1 execute内で2runが独立に固定原資料を読み、両年候補をseal・P1/P2検証してからlabelsを開放する。
レース単位のP1/P2寄与を完全一致させ、Hit@3適格内だけで「位置差=P3的中差」をレース/年合計で検査する。
全P3分子差をHit@3差へ転記しない。分母0はNOT_EVALUATED、技術失敗の性能/Gateはnull。
旧年層別paired race-cluster bootstrap: 2000、seed20260812、Type7、年等重み。再現runは標本に追加しない。
主Gateは旧TacticalHistory incrementalGateをそのまま使用。補助STAT01 Gateは代用しない。
実読取りsource/直接依存codeのSTART/END・生成seal・公開前検証、独立意味ファイル一致を要求する。
保存rootは `/home/shinya/neo-keirin-artifacts/c1-p12-fixed-marginal-p3-01/` の新規run。

## 検証・結果

人工fixtureによるP1/P2固定、同率維持/hash/微小差、欠番5/7/9人、順序違い、不正原資料/labels、
同着/分母0、結果独立、end drift、Gate一致、独立128Mで100MiB超JSONLを検証する。
1 execute内の独立再読込み2runが完了。実入力から2024年25,212race/179,089出走、
2025年24,866race/177,120出走、合計50,078race/356,209出走を確認した。
各年P1/P2変更0、P3変更1,163/1,203、未変更24,049/23,663。同率最大維持/hash選択はいずれも0。

| 年 | 指標 | 元C1分子 | 候補分子 | 共通分母 | 元C1率% | 候補率% | 差pp |
|---|---|---:|---:|---:|---:|---:|---:|
| 2024 | 1着 | 10424 | 10424 | 25158 | 41.434136 | 41.434136 | 0 |
| 2024 | 2着 | 6041 | 6041 | 25106 | 24.061977 | 24.061977 | 0 |
| 2024 | 3着 | 4701 | 4720 | 25094 | 18.733562 | 18.809277 | +0.075715 |
| 2024 | Hit@3 | 21091 | 21110 | 75120 | 28.076411 | 28.101704 | +0.025293 |
| 2025 | 1着 | 9886 | 9886 | 24789 | 39.880592 | 39.880592 | 0 |
| 2025 | 2着 | 5743 | 5743 | 24727 | 23.225624 | 23.225624 | 0 |
| 2025 | 3着 | 4677 | 4697 | 24739 | 18.905372 | 18.986216 | +0.080844 |
| 2025 | Hit@3 | 20241 | 20262 | 73989 | 27.356769 | 27.385152 | +0.028383 |

| 比較 | 指標 | 年等重み差pp | paired 95%CI pp |
|---|---|---:|---|
| 候補-C1 | 1着 | 0 | [0, 0] |
| 候補-C1 | 2着 | 0 | [0, 0] |
| 候補-C1 | 3着 | +0.078280 | [-0.026545, +0.190674] |
| 候補-C1 | Hit@3 | +0.026838 | [-0.008136, +0.064433] |
| 候補-STAT01 | 1着 | +2.522432 | [+2.181796, +2.877100] |
| 候補-STAT01 | 2着 | +0.387247 | [+0.000109, +0.784484] |
| 候補-STAT01 | 3着 | +1.228793 | [+0.850665, +1.600388] |
| 候補-STAT01 | Hit@3 | +1.383091 | [+1.139474, +1.634078] |

未丸め値で主incremental Gate `NOT_PASSED`。非劣性/年別条件/integrity=true、Hit@3優越=false。
今回方式は不採用・元C1維持。補助STAT01 Gate `PASS / GO_TO_FREEZE` は主Gateの代用・正式採用ではない。
年別STAT01分子は1着9713/9336、2着5876/5714、3着4436/4369、Hit@3 19959/19349で、分母は上表と同じ。
各レースP1/P2寄与、年別分子/分母/率/差・CI[0,0]は計算後の強制補正なしで完全一致した。

P3の両的中/C1のみ/候補のみ/両不的中/除外は、2024年4512/189/208/20185/118、
2025年4484/193/213/19849/127。全P3純増は19+20=39。
Hit@3適格25,040/24,663race内のP3変更は1,157/1,195、P3的中4691→4710/4663→4684。
同母集団のP3純増19/21と一致位置純増19/21は各レース・合計で一致し、Hit@3純増は40。
全P3純増39とは母集団が異なり、2025年に差1がある。Hit@3除外172/203を含める転記はしていない。
Primary完全順序一致は1195→1198/25040、1179→1177/24663。
Supporting MAP完全順序一致は1236/25040、1198/24663のまま、選択・全Supporting指標差0。
モデル上の期待P3確率gain合計10.091990301352125/10.053222061342213は実測的中増加と区別する。

独立12意味ファイルのbyte/size/SHA一致、source23/code42のSTART/END・公開前検証、manifest/COMPLETE照合成功。
manifest SHA-256: `bafa4696af468e5cb345f62f42c8d21c3e6cd6d0c75ae92a0b3833c397fd62ca`。
成果物: `/home/shinya/neo-keirin-artifacts/c1-p12-fixed-marginal-p3-01/run-20261008-001334-8728deef/result/`。
128M実行: 2026-10-08 09:20:32～09:25:04 JST、272.767078秒、peak33,554,432bytes（32MiB）、exit0、stderr空。
最終専用54 tests/636 assertions、関連232/1319（いずれも128M）。
通常全体1回: 2983 passed/28357 assertions/既存9 skipped、失敗0、264.895288秒。
変更9PHP構文/限定Pint/128M plan成功。独立128M人工試験は18000race・100MiB超JSONLを処理しexit0。
全体内の既存人工学習は実データ学習0回とは別。初回の人工「P3が変わる例」が実際には不変だったため、
人工utilityを独立探索で確認した変化例へ修正してから最終テストを行った。実資料に合わせた条件調整はない。

## 実行コマンド・レビュー

合意済み新規run配下の `runner.php` は認証環境を引き継がず、process-local testing/SQLiteと未作成config-cacheを指定。
実コマンド: `php -d memory_limit=128M artisan keirin:c1:p12-fixed-marginal-p3 execute --output-dir=/home/shinya/neo-keirin-artifacts/c1-p12-fixed-marginal-p3-01/run-20261008-001334-8728deef/result`。
Command内でアプリDB/HTTPを明示拒否。ローカルSQLiteは重複検知用workspaceで業務DBではない。
契約/比較/不変性/再現/実行ログ/直接コード差分は同runのreview bundleへ保存する。
共有ZIP: 同run直下 `C1-P12-FIXED-MARGINAL-P3-01-review.zip`。全確率・全学習本文は添付へ重複コピーしない。
PR89は終了時にもOPEN/未マージ・同head/baseを確認。依存headとの差分だけが今回の変更。
新branchは未コミットでレビュー待ち。正式decoder置換、再実行、追加実験、LIVEへ自動移行しない。
PR89の2着-29/3着+112/Hit@3+83は旧記録として維持する。
2024/2025は既使用development corpusであり、新しい未観測holdout/探索補正済み検証ではない。
