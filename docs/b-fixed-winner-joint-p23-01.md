# B-FIXED-WINNER-JOINT-P23-01

## 実行前契約 / 2026-10-12

開始HEAD/main/origin `8a9ea0f4768f620b9c23194e7c40051681c25d94`、clean。
既存branch `feature/b-fixed-winner-joint-p23-01` を使用し、Git書込みは行わない。
GitHub PR103はMERGED、同merge SHA、head b40a1843a209bf5170b37a50584e59d6487cde19。
旧C3不採用・B改善候補・C1/全旧成果物を保持する。同方式の既存完了記録は見つからなかった。

- A: 元C1、補助参照。B: PR92 run-01 C2_P1_C1_P23_COMPOSITION。
- 固定B manifest: f1c1687aed2aa6cf9540c6f351e8017c240f096177e3206af775d936e3d92264。
- C: B_FIXED_WINNER_JOINT_P23、experiment/decoder/tie は専用v1。
- Bのwinner aを固定、全相異順序対(b,c)を保存順で列挙。
- score = E06.conditionals(entries,a).q2[b] * q3_paths[b][c]、binary64の左値×右値を1回。
- 比較は厳密な > / ===。epsilon、丸め、clip、再正規化、log scoreへの置換はしない。
- Bのpairが最大と同値ならBを維持。それ以外の最大同値は
  SHA256("B-FIXED-WINNER-JOINT-P23-TIE-v1|year|race_id|a|b|c")の辞書順最小。
  SHA衝突時だけb,c昇順。年・race・車番はdecimal表記。
- 有効なE06条件付き分布のexp/product underflow=0は0として保持し、そのまま最大化する。
  分布不正、非有限utility/確率、最大値不明は停止する。未知値を0へ補完しない。
- Bのutility・全確率・Supporting/MAPはコピーせず参照し、C decisionのみ保存する。
  Bの旧q2+q3 objectiveを新joint objectiveとして偽装しない。
- 対象は保存2024/2025、予定50078レース/356209出走を実照合する。
- 両年のC候補生成・seal・cohort不変性検証後だけ教師を開放。
- 1 execute内で原資料独立再読込み2run。再現を追加標本としない。
- Primary完全順序一致とSupporting MAPは別指標。Hit3はordered適格raceの位置一致数/(3*n)。
- 年層別paired race bootstrap2000/seed20260812/Type7/年等重みを維持。
  非削除版の既存数値互換spool/bootstrapを使用する。
  CI用EXACT_ORDERED_TOP3_RATE slotにPrimary寄与を投影し、CI名はPRIMARY_EXACT_ORDERED_TOP3_RATEとする。
  その他Supportingの点推定は元Evaluator出力を保持、Primary/Supportingを混同しない。
- incremental Gateは既存TacticalHistory.Evaluation.incrementalGateをそのまま使う。
- 使用済みdevelopmentの限定比較、historical_as_of_available=false、formal_adoption=false。
  学習0、DB/HTTP/Raw/2026/LIVE/正式置換は禁止。結果を見た条件変更・再試行なし。
- 新出力root: /home/shinya/neo-keirin-artifacts/b-fixed-winner-joint-p23-01/。
  元資料/code START-END/公開前検証、排他作成、失敗証拠/spool保持、永久削除なし。

## 実装・実測

実装・保存資料の限定比較・独立再現が完了。技術的成功と精度を分ける。
Primary三連単はdevelopmentで改善したが、位置別の既存incremental GateはNOT_PASSED。
今回方式を正式採用せず、元C1とB改善候補を維持する。条件変更・追加再試行を行わない。

### 主比較 C−B

率は%、差はpercentage points (pp)。丸めは表示のみ。Hit3分母は3×ordered適格race数。

| 年 | 指標 | B分子/分母 | C分子/分母 | B率% | C率% | 差pp |
|---|---|---:|---:|---:|---:|---:|
| 2024 | Primary三連単 | 1197/25040 | 1227/25040 | 4.780351 | 4.900160 | +0.119808 |
| 2025 | Primary三連単 | 1173/24663 | 1189/24663 | 4.756112 | 4.820987 | +0.064875 |
| 2024 | 1着 | 10464/25158 | 10464/25158 | 41.593131 | 41.593131 | 0 |
| 2025 | 1着 | 9901/24789 | 9901/24789 | 39.941103 | 39.941103 | 0 |
| 2024 | 2着 | 6063/25106 | 6006/25106 | 24.149606 | 23.922568 | -0.227037 |
| 2025 | 2着 | 5747/24727 | 5726/24727 | 23.241800 | 23.156873 | -0.084927 |
| 2024 | 3着 | 4709/25094 | 4734/25094 | 18.765442 | 18.865067 | +0.099625 |
| 2025 | 3着 | 4673/24739 | 4721/24739 | 18.889203 | 19.083229 | +0.194026 |
| 2024 | Hit3 | 21159/75120 | 21128/75120 | 28.166933 | 28.125666 | -0.041267 |
| 2025 | Hit3 | 20254/73989 | 20278/73989 | 27.374339 | 27.406777 | +0.032437 |

| 指標 | C−B年等重み差pp | paired95%CI pp | C−A差pp | C−A95%CI pp |
|---|---:|---|---:|---|
| Primary三連単 | +0.092341 | [+0.019844,+0.168134] | +0.084171 | [+0.009495,+0.162957] |
| 1着 | 0 | [0,0] | +0.109753 | [+0.033411,+0.195174] |
| 2着 | -0.155982 | [-0.293805,-0.014625] | -0.104080 | [-0.254313,+0.055007] |
| 3着 | +0.146826 | [-0.015080,+0.303673] | +0.154681 | [-0.012212,+0.311410] |
| Hit3 | -0.004415 | [-0.074716,+0.070297] | +0.049631 | [-0.033802,+0.131198] |

C−B Gateはnon_inferiority=false、temporal=false、superiority=false、integrity=true。
2着CI下限が-0.0015以下、2024 Hit3差が負、Hit3 CI下限が0以下である。
C−AもNOT_PASSED。B−Aは旧PR92のPASS_DEVELOPMENT_INCREMENTAL_EFFECT_ONLYを保持。
三連単向上を位置別Gate通過へ読み替えない。多数探索済みdevelopmentであり、探索補正済み確認・未来/未使用holdoutでの改善ではない。

| 年 | 全race/出走 | pair変更 | Bのみ三連単的中 | Cのみ | 両的中 | 両不的中 | ordered除外 |
|---|---|---:|---:|---:|---:|---:|---:|
| 2024 | 25212/179089 | 2480 | 67 | 97 | 1130 | 23746 | 172 |
| 2025 | 24866/177120 | 2522 | 88 | 104 | 1085 | 23386 | 203 |

全50078レース/356209出走でwinner保持・joint非低下が成立。P2変更1446/1405、P3変更2329/2351。
Bが厳密最大の45076レースを保持。同値SHA規則とunderflowは人工検証し、実測zero-score pairは0。
Supporting MAP三連単はB/Cとも1241/25040、1198/24663で不変。Primaryの1227/1189と混同しない。
Top3集合一致はB/Cとも3852/25212、3644/24866、他のSupportingもrace寄与が完全不変。
全11指標・未丸め分子/分母/差、補助B−A、Primary別集計とCIはcomparison.jsonへ保存した。
その他SupportingのCIは追加計算していない。C−BのSupporting差は全raceで0と検証済み。

### 証跡・再現・検証

新規root:
`/home/shinya/neo-keirin-artifacts/b-fixed-winner-joint-p23-01/run-20261011-233148-f1c173e7/`
名前はUTC、実行ログはJST。結果はresult/、stdout/stderr/argv/開始終了/exitはexecute/。

```bash
php -d memory_limit=128M artisan keirin:b:fixed-winner-joint-p23 execute \
  --output-dir=/home/shinya/neo-keirin-artifacts/b-fixed-winner-joint-p23-01/run-20261011-233148-f1c173e7/result
```

execute1回、2026-10-12 08:36:53〜08:43:59 JST、425.841524秒、exit0、stderr空、peak35651584bytes (34MiB)。
独立2runが原資料を読み直し、decision・寄与・評価・CI・Gate・bin spool・SQLite索引を含む18ファイルがbyte/size/SHA一致。
source/code START-END・公開前検査、生成sealとCOMPLETE照合成功。旧モデル・旧資料・旧実行code記録は変更していない。
manifest: 130745bytes、SHA-256 `fc250be6615b147cd1f25c531494903220cebbb799150c982e8886e150064786`。
保存後のverification/report.jsonでも全39生成ファイル・47 source・2教師資料・55依存codeを照合した。
B−Aの全未丸め指標/分子分母・位置別paired CI・Gateが旧PR92と厳密一致。再学習/比較executeの再実行ではない。

- 新規最終33 tests/128 assertions、関連21 tests/411 assertions、各128M/exit0、failure/error/skip=0。
- 関連はE06条件付き分布、scorer、同着分母、非削除bootstrap数値互換の安全な人工テストだけ。
- 独立PHP128Mで193198986bytesの人工B JSONL、18000race/126000出走を実Readerで処理、peak27262976bytes (26MiB)。
- 追加10PHP構文、限定Pint成功。既存テスト削除/緩和/新規skipなし。
- 初回の人工tmpfs/Trash device差と、別bind mount間rename拒否は安全に停止し、失敗ログを保持。
  人工Desktop内で作成・退避し、sys_temp_dirもそこへ向ける隔離設定で解消。helperの拒否を緩めず、削除fallbackなし。
  SQLite重複索引はjournal_mode=OFF/一つのtransactionへまとめ、索引/spoolを保持した。業務DBを使わない。
- 原本ro-bind、home/run/.env/storage/cache隔離、ネットワーク遮断。退避は保持オブジェクトのpathのみで人工Desktop/Trashへrename-only。
- 通常全体は旧危険cleanupを避けて未実行。学習/旧予測再生成/実Raw/業務DB/競輪HTTP/2026/Migration/LIVEは0。

implementation_status=IMPLEMENTED_TESTED、evaluation_status=MEASURED_REPRODUCED、joint_probability_invariant=true、winner_preservation=true。
development_accuracy_evidence=PRIMARY_EXACT_ORDER_IMPROVEMENT_DEVELOPMENT_ONLY、existing_incremental_gate=NOT_PASSED。
formal_adoption=false、historical_as_of_available=false、2026_access=FORBIDDEN。Bを維持し、未コミットの差分レビュー待ち。
Git add/commit/push/fetch/switch/PR操作なし。既存ファイルの削除・移動・旧成果物の上書きなし。
