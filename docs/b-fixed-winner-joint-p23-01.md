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

## PR104: 通常一時領域と人工入力ガードの整合 / 2026-10-12

対象branch `feature/b-fixed-winner-joint-p23-01`、開始HEAD `c7cc0eea2d8ebe2ce56a552642c1172aaf2a1a48`、開始clean。PR104作成済みという最新ユーザー指示に従い、上記の「未コミットの差分レビュー待ち」は初回実行当時の記録として保持する。今回はテスト環境だけの未コミット修正で、次は既存PR104の再レビューのみ。

ユーザーの全体実行記録は7 failed / 59 warnings / 9 skipped / 3595 passed / 33204 assertions / 360.69秒。今回その全体実行は再実行していない。59警告の本文付きログは未入手で、すべて同一原因または解消済みとは扱わない。

### 原因と限定修正

- 既存fixtureは省略時Desktop配下へ作成する一方、既存C1 SourcesはtestingかつPHP一時領域の配下だけを許可する。通常PHPの `sys_get_temp_dir()=/tmp` / TMPDIR未設定では、Desktop fixtureが `Synthetic pins are testing-only.` で拒否される。APP_ENV=testingの隔離実行でも旧7ケースを再現した。
- 実測deviceは `/tmp=44`、Desktop/Trash=66306。直接 `/tmp` に移すだけではrename-only退避を満たさない。初回の `sys_temp_dir=Desktop` 設定はこの通常条件との差を隠していた。同じdeviceでも別bind mount間はrename不能なので、mount IDも照合する。
- `BFixedWinnerJointP23TestProcess::temporary/delegate/run` をテスト専用に追加。作成・保持したオブジェクト、device/inode/作成記録hashを検証し、一つの専用filesystem view内に人工 `/tmp` と人工 `/home/shinya/Desktop/Trash/` を用意する。通常条件で安全な同一mountなら直接実行、異なる場合は指定8ケースだけを128M隔離PHPへ委譲する。
- 既存7ケースの本体・assertionsを子プロセスで実行し、case/file/JUnit/終了コードを検証してassertionsを親へ引き継ぐ。許可外path・非testing環境・未登録オブジェクトを拒否する1ケースを追加。無効化・skip・ガード緩和はない。
- 原本repoはread-only、実 `.env` はコメントだけの人工fixtureへ置換し、storage/cacheは人工領域、ネットワークとDB socketは不可。保持pathだけを既存helperへ渡し、同一filesystemの人工Trashへrename-only退避する。正常時もspool/失敗証拠/ログを残す。Production Sources・共通安全helper・Decoder/Reader/Evaluation/Gateは変更しない。

### 検証結果と実行条件

PHP `/usr/bin/php8.5` 8.5.4、PHPUnit 12.5.31。対象最終コマンドは下記を専用read-only隔離内で実行した。後ろの `-d` はPHPUnitへの指定であり、Artisan親だけのPHPオプションは子へ継承されなかった。最終34ケースの環境記録で本体128M・TMPDIR未設定・一時領域 `/tmp` を確認した。

```bash
php -d memory_limit=128M artisan test -d memory_limit=128M \
  --filter=BFixedWinnerJointP23Test --do-not-cache-result --colors=never \
  --display-all-issues --log-junit /evidence/junit-128.xml \
  --log-events-text /evidence/events-128.log

php -d memory_limit=128M vendor/bin/phpunit tests/Unit \
  --filter='CompositionResultTemporaryDirectoryTest|Bt03e06DecoderTest|Bt03e03ProbabilityTest|Bt03e05MetricsTest|Stat35Trend6P1NumericsTest' \
  --do-not-cache-result --colors=never --display-all-issues
```

| 確認 | 実行ケース | assertions | failure/error | warning/skip | exit | peak |
|---|---:|---:|---|---|---:|---|
| 修正前のArtisan再現 | 33 | 107 | 0/7 | 26/0 | 2 | 未記録 |
| 最終Artisan対象128M | 34 | 140 | 0/0 | 0/0 | 0 | 91750400 bytes / 87.5MiB |
| 通常device差からの委譲8ケース | 8 | 33 | 0/0 | 0/0 | 0 | 子42467328 bytes / 40.5MiB |
| 安全helper＋数値関連 | 48 | 530 | 0/0 | 0/0 | 0 | 38.50MiB |

最終Artisanは13:18:46〜13:18:57 JST / 11.040301秒。通常 `/tmp` / device差のvendor/helperのみの親から最終コードの8子ケースを実行し、退避8件MOVEDを確認した。最終delegate呼出しでも元11 assertionsの引継ぎと自作領域だけの退避を確認した。親ではアプリ・実 `.env` を起動/読取りしていない。
独立128M大容量fixtureは193198986 bytes / 18000レース / 126000出走、peak29360128 bytes（28MiB）、全件保持・P1変更0・joint/Supporting不変。初回の26MiBは過去の実測であり、今回の値へ読み替えない。
関連48ケースは共通helper27/119と数値21/411。改変・親資料drift・重複・両年seal前教師拒否、symlink/保護path/mount境界・移動失敗時の原本保持を確認。変更PHP2の構文・限定Pint成功、diff検査実施。

修正前の26警告は人工 `.env` にbindした `/dev/null` が読取り拒否された隔離起因の警告。別にread-only結果cache書込み警告1件も記録した。最終は秘密値なしの通常ファイルをbindし、原本cacheを保存対象にしない `--do-not-cache-result` を使用した。警告抑制ではない。この条件差をユーザー全体の59警告の原因証明へ流用しない。Artisan親だけ128Mだった途中の対象成功ログも保持し、最終128M実績と区別する。

証跡root（UTC名、ログJST）: `/home/shinya/neo-keirin-artifacts/b-fixed-winner-joint-p23-01/pr104-review-20261012-035526-80b76f84/`。
`before/`、`target-128/`、`target-sandbox/evidence/`、`native-final/`、`native-wrapper-final/`、`related/`、`static/` にstdout/stderr/argv/終了コード/JUnit/events/環境・退避記録を保持。native委譲の自作envelopeだけは同helperで実Trashの一意な専用場所へ退避し、元path/先/時刻/理由をreport.jsonへ記録した。過去Desktop資料は操作していない。

### 未確認範囲とレビュー判定

通常全体は未実施。対象外の `C1Stat35P1CompositionTest` / `C1MarginalP23DecoderTest` の `File::deleteDirectory/unlink` と既存metric spoolの `unlink` が残るため、永久削除禁止を守って停止した。一括改変や無効化は行わない。
専用helperはLinuxのbwrap/user namespaceとmountinfoを必要とする。利用不能なら失敗証拠を保持してfail-closed。当地では隔離の入れ子namespaceが拒否されたため、Artisan対象には一つの専用root view、通常device差の委譲にはnativeのvendor-only親を使用して両経路を別々に実測した。標準全体ランナーでの連続成功・59警告本文は未確認。
今回の限定修正は `READY_FOR_REVIEW`。全体テスト確認済みを意味しない。既存Gate NOT_PASSED、formal_adoption=false、historical_as_of_available=false、2026_access=FORBIDDENは不変。実DB/競輪HTTP/実Raw/学習/保存実験再実行/旧成果物変更/永久削除/Git書込みは0、予測精度への影響は `NOT_MEASURED_THIS_CHANGE`。
