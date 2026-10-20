# C1-2022-REAL-DATA-PREFLIGHT-01

## 1. 結論・承認境界

2026-10-21。保存済み2022年入力のread-only事前監査を実施した。**原本seal・内部整合・B1案の件数確認はPASS。保存元の一次資料との直接対応はNOT_ESTABLISHEDであり、総合はBLOCKED。正式B1実データ受入はNOT_RUN。** データ破損を検出したという意味ではなく、今回確認した証拠だけで実データ由来の全連鎖を認定しないという停止判断である。

```yaml
current_phase: C1-2022-REAL-DATA-PREFLIGHT-01
current_engine_state: REAL_DATA_PREFLIGHT_REPORTED_AWAITING_REVIEW
next_allowed_action: REVIEW_C1_2022_REAL_DATA_PREFLIGHT_ONLY
pr115_status: MERGED
real_data_preflight_status: BLOCKED
formal_b1_data_acceptance_status: NOT_RUN
method_b1_status: PROPOSED_NOT_APPROVED
method_selected: NONE
contract_frozen: false
selected_lambda: NONE
parent_selection_independent_of_2023_outcomes: NOT_ESTABLISHED
final_selected_2023_oof: NOT_ESTABLISHED
h1_u_training_use: PENDING_PARENT_SELECTION_REVIEW
historical_as_of_available: false
formal_adoption: false
prediction_use: NOT_AUTHORIZED
2026_holdout: FROZEN_NO_ACCESS
```

学習、refit、NLL、One-SE、bootstrap、lambda選択、予測、性能評価は0回。B1方法・境界・One-SE・予算を承認/凍結したものではない。既存最終C1のlambdaと新しい親lambda未選択を混同しない。

## 2. Git・参照契約・工程ゲート

開始cwdは `/var/www/NeoBicycleRaceInformationWeb`、main、worktree clean。HEADは `5a6d2867678666a28b1c40fe8e4683cbf4833293`。originのfetch/push先は対象repositoryのHTTPS URLと一致した。

`git fetch origin` / `git ls-remote origin refs/heads/main refs/heads/audit/c1-2022-real-data-preflight-01` / `git switch main` / `git pull --ff-only` / `git rev-parse HEAD` / `git log -1 --format='%H %s'` は各exit0。pullはAlready up to date。main、origin/main、ls-remote mainが上記SHAで一致し、件名は `Merge pull request #115 from Shinya-Taketani/design/c1-2022-parent-lambda-b1-contract-01`。GitHub PR APIの再照会ではなく、実際にfetchしたmerge履歴とユーザー報告の照合である。

指定branchはlocal/remoteとも未使用で、`git switch -c audit/c1-2022-real-data-preflight-01` はexit0。05:54:55 JSTの再確認でもHEAD/main/origin/mainは同SHA。mainを編集せず、作業branchのHEADも不変。reset/restore/stash/clean、commit/push/PR操作/mergeはしていない。

AGENTS.md、MASTER PLANの現在地・8工程ゲート・Section 26、[B1契約案](c1-2022-parent-lambda-b1-contract-01.md)、[親選択成立性](c1-2023-parent-lambda-selection-feasibility-01.md)、[2023候補生成記録](c1-2023-oof-recovery-01.md)、[旧実験](tactical-history-01.md)、[最終fit記録](tactical-history-final-01.md)を確認した。過去の実績を今回再実行したとは扱わない。

| 工程制御 | 今回の扱い |
| --- | --- |
| current_engine_state / next_allowed_action | 今回の事前監査報告レビューだけ。製造/fit/選択へ進まない |
| completed_phases | PR115の文書mergeを確認。B1学習・正式受入完了ではない |
| superseded_phases | 旧BT-03D・粗整数点数化を復活させない |
| blocked_phases | B1製造/実行、親選択、H1-U、正式採用、BT-04/BT-05-LIVEは未承認 |
| frozen_contracts | C1/STAT39/Solver/Decoder/Evaluator/Gate、時系列・欠損・異常結果・128M・artifactを維持 |
| unfrozen_contracts | B1方法・境界・単一層One-SE・保持I/O・予算は未承認 |
| holdout_status | 今回は固定2022入力だけ。2023〜2025結果/損失/モデル選択ファイル、2026データを開かない |

### コード読取りと実行の区別

[Dataset](../app/Domain/Keirin/Backtest/Experiments/TacticalHistory/Dataset.php)、[SourceIntegrity](../app/Domain/Keirin/Backtest/Experiments/TacticalHistory/SourceIntegrity.php)、[InputBuilder](../app/Domain/Keirin/Backtest/Experiments/TacticalHistory/InputBuilder.php)、[HistoryAggregator](../app/Domain/Keirin/Backtest/Experiments/TacticalHistory/HistoryAggregator.php)、[HistoryReader](../app/Domain/Keirin/Backtest/Experiments/TacticalHistory/HistoryReader.php)、[SourceVerifier](../app/Domain/Keirin/Backtest/Experiments/TacticalHistory/SourceVerifier.php)、[JsonlArtifact](../app/Domain/Keirin/Backtest/Experiments/TacticalHistory/JsonlArtifact.php)、[元dataset生成](../app/Domain/Keirin/Backtest/Services/Bt03e02DatasetBuilder.php)を読んだ。これら8ファイルの実hashは保存 `code-frozen/files.json` の対応sealと一致した。

InputBuilderはsnapshot/固定STATとDBの開催・entry情報を照合してtargetを作り、history source参照を保存する。元dataset生成は対象結果とSTAT-01の車番集合を照合する。SourceIntegrityは固定STATと履歴の両検査を要求し、SourceVerifierはDBへ接続する。これらの生成/DB経路は起動していない。Trainer/grid、削除型Spool、アプリKernel、Artisanも起動しない。

## 3. 対象保護・実行方式

原本root（以下B）:

```text
/home/shinya/neo-keirin-artifacts/tactical-history-01-review-fix-20260916-01
```

主要5件を最初に限定確認し、そこに記録された依存関係を根拠に第4節の13件を追加した。B全体の再帰走査はしていない。root/祖先/各fileのlstat・実パス・所有者・権限・device/inode・mountを確認し、symlink/特殊file/複数hardlinkを拒否。対象は同一ext4 `/home` mount、device 66306、shinya所有の通常ファイル、元modeは664。root775/inputs-v2 755を変更せず、全18原本をbwrap内で個別read-only bindした。

`--unshare-net --ro-bind / / --clearenv`、home/一時領域/run/mnt/mediaの隔離、本物の.envのマスク、storage/bootstrap/cacheの隔離を使用した。`/proc/net/dev`でloだけ、原本18個のmountinfoでro、home不可視を実検証。PHPは `-n -d memory_limit=128M`、PDO/SQLite拡張なし、autoload/Kernelなし。DB接続・HTTP・Raw参照なし。集計は1race/1history行ずつ、ID集合だけをメモリに保持し、一時disk索引・外部sort・実データ複製は作らない。

単発PHPを実行前に読取り確認・構文検査し、書込み/移動/削除/cleanup/destructor/外部process呼出しがないことを確認した。stdout/stderrはhost側で新規証跡へ排他保存した。新規証跡root0700、ファイル0600。既存artifactの退避・後片付け・永久削除は0。

通常sandbox内の最初のbwrap probeはnamespace作成不可でexit1。権限レビュー下で同じ隔離を確認した。その後、初回metadata起動はread-only root上の `/evidence` mount点を作れず、PHP起動前にexit1。保護を弱めず、隔離tmpfs内の `/tmp/evidence` へmount点を変更した。失敗ログも保持し、未実行を成功へ読み替えない。

## 4. 原本のSTART/ENDと保存時seal

以下は**今回実際に読んだ全18原本**。表のbytes/SHAはSTARTとENDの双方で実測一致した値。最初の5件はユーザー指定の過去値とも一致し、export自身を除く17件はexportのincluded/omitted sealとも一致した。omittedは旧小型ZIPからの省略を表し、ローカル原本の欠落ではない。

| Bからの相対パス | START = END bytes | START = END SHA-256 |
| --- | ---: | --- |
| `report-export-manifest.json` | 221711 | `4268b801b74b77cfb3a94b64832ff483e9eb5e3221ee8e75ceb467e5a5cf92e6` |
| `frozen-experiment-contract.json` | 53460 | `5db3b57b08fb8b91dbe6c057b459e518252c454b0a4ff60531c2d671b7ce506a` |
| `inputs-v2/manifest.json` | 3823 | `d5158a7671f8c453afc77dccc1ab0b089c940198f58f388c038ad0d4fad684cf` |
| `inputs-v2/inputs-2022.jsonl` | 75218174 | `cbfac43e1e398aea4a031ff87a63397ed03fd5cc7cd3fc5dc8fb641107b31b95` |
| `inputs-v2/history-2022.jsonl` | 510307079 | `148b5269e827a98e88ad4bfcd755e21bcde9cc0b7dc5f64f62e8b8032c79e680` |
| `input-run-result.json` | 147125 | `edf5eb4c85ac03d9729a38ae724c3ea04ecd6e5694c6d0507d533d3dcb1f173c` |
| `source-before-fit.json` | 24800 | `3b5c15700ded74df6406d93d507cdc42b72ec375e8bc69e4376239a6c2a22123` |
| `source-after-run.json` | 24800 | `a1888632df49495bf92e690010007b7b72ee58b70420eecc0b711a444fbe25d7` |
| `run-01-completion.json` | 1142 | `ff0077689f218c6a2ff6aad590baa7ff9c4ed035ec6ffb7daefaf78b9134c7f9` |
| `inputs-v2/inputs-2022.jsonl.manifest.json` | 127 | `161409010bf3e07ee032e6d32b7ac5aaae6eaaec2b87587c5d554ac3d619db96` |
| `inputs-v2/history-2022.jsonl.manifest.json` | 129 | `b1b416edeb33006cff38365a823486ce5503b2bbbadf7c2da376d3a34dba24e7` |
| `code-frozen/files.json` | 66500 | `cb46e80b7ec31275f2f360ccfecf7218b20d4470977ad3d0cc35fe98219e21af` |
| `tools/reaggregate-inputs.php` | 10062 | `1d8e90449a8bec61f774472813bf8466489a72be92df713b0d0d5d60e7ae05d6` |
| `tools/export-report.php` | 3116 | `a0eea5060e4c9ab7d4483bc0423ce288a6a41626871370539f52d84db1d0c018` |
| `tools/capture.php` | 1279 | `9f143712f1bda00ba2bec0755adc1a70c10a50842882b3fa2cdfe68be3bc1682` |
| `tools/verify-source-end.php` | 3959 | `e4c1744d87232ec535f8217d44d8937e840d15727968c10e282b7db81560a4c9` |
| `logs/source-before-fit/execution.json` | 434 | `6bcfd69928159a08167fbc48890380f7434e60e3ea8fba8d322fea3e3dc98c53` |
| `logs/source-after-run/execution.json` | 434 | `80e9040357efe92c02bdd6ed300530cae7eb4256a8b65373dc1fdf3048cdf9ab` |

主要5件の初回START/ENDは05:50:53〜05:50:54 JST。全18件の監査START/ENDは05:53:21〜05:53:24、証拠整理後の全18件再確認は05:54:10。追加のmetadata照合2件も05:54:55にSTART/END一致。個別時刻はstdout内に保存する。`provenance-partition`は時刻を工程単位で記録する。

年別子manifestと親manifestの2022記録を厳密比較し、実ストリーム行数/bytes/SHAとも一致した。これは**保存時seal・今回不変性の確認**であり、現在DBの一致や当時の発走前利用可能性を証明しない。

## 5. provenance: 確認できた対応と停止理由

| 対応 | 今回の根拠・判定 |
| --- | --- |
| export → 固定契約/入力/履歴/保存コード台帳/検証記録 | 実物18件のseal照合。参照範囲のリンクはPASS |
| 固定契約 → input-run-result/source-before-fit/code-frozen/files | 契約内SHAと実hashが一致。reaggregate/verify-source-endのtool SHAも一致 |
| input-run-result → inputs-v2 manifest | JSON内容が厳密一致。2022子seal/coverageも実ストリームと一致 |
| 固定STATの識別 | 2022年の13runのsource/content fingerprintを保存記録で確認。STAT-01=25、07=36、08=37、10=31、11=32、12=33、23=38、24=34、26=35、31=39、32=40、39=29、42=30 |
| 生成・保存履歴 | `CANCELLED_NULL_SCHEDULE_REAGGREGATION` / `INPUTS_GENERATED_NOT_TRAINED`、v1継承、2022 affected/changed各0という保存記録を確認。今回新規再集計した影響件数ではない |
| 履歴元 | targetのfeature_input_hash、cache_key/source_sha256、history_referencesがある保存形式を確認。元SQLiteのsource_rowsとの照合はNOT_RUN |
| 元の検証履歴 | source-before-fit/after-runの実ファイルとcommand/時刻/exit0記録を照合。両者のfeature/history内容は一致。これは当時の実行記録で、今回DBを再検証した意味ではない |
| 完了記録 | 実名は `run-01-completion.json`。状態はMODELS_AND_PREDICTIONS_COMPLETED_NOT_YET_EVALUATED、Model/Solver v2。外側予測/選択内容は開かず、今回の監査完了証拠として流用しない |
| 実開催/出走・公式結果の一次資料との直接対応 | 保存ID・生成コード・seal連鎖は確認したが、元の2022 snapshot/STAT行/履歴source行の実物を開いて照合していないためNOT_ESTABLISHED |

当時の固定STAT fingerprint digestは `c0f7ec403006c2e7a669b55ce46b676b85711e51d3c5329c899cd1be5fa61859`。inputのsource_start/source_end_same_snapshotとbefore/afterの保存値が一致する。各2022runの個別SHAは `provenance.stdout.json` に保持し、DB再計算ではないと区別する。

当時のsource-before-fitは2026-09-16 06:56:34〜07:04:08 JST、after-runは同日15:40:57〜15:48:23 JST、ログ上各exit0。両reportのsession/transaction read-only=on、履歴221,559窓/対象706,051/identity717,709は**4年分の過去記録**であり、今回の2022実測件数へ流用しない。

### 今回開いていない依存先

- 継承元は `/home/shinya/neo-keirin-artifacts/tactical-history-01-20260915-01/input-attempt-03/inputs`。親manifestの保存SHAは `352ff20b067ee6025438a8d1c3d618ae71060df7790270353a6b472c73fe122a`、親input reportは `61dac260e954beefdc8d315aab5e9c057b76830e42c14b00d31286a8ea8bff22`。指定Bの外なので開かない。
- 元公式結果snapshotの保存参照は `private/backtest/bt02/outcome-context/a4b1800095b22fe0ae40216ce90243c7e80a0cf652a96e328c45223160c3dad9`。2022 partitionの保存metadataは `2022.jsonl` / 24,868race / 173,847result行 / 16,710,283bytes / SHA `654ddb68ef510d41528cb56a6addb8daedeba1882354133a64989a4f74c80c28`。これは今回の実ファイル検査値ではない。manifest/2022原本の実在やC1 subsetとの行対応を未確認。2023〜2025 partition本体は読まない。
- `inputs-v2/history-cache.sqlite` の保存SHAは `af4ccd5601a838d9c177b5bee7d718ce91beb847409323bdae6666ba69304199`。複数年度のsource_rowsを含むため、2022限定の追加照合経路を今回作らず未読。固定STATの元行も未読。

後続の直接照合には対象別の明示的な範囲確認が必要であり、無断でrootを拡大しない。元snapshotの24,868raceとC1の24,394raceは同じ母集団と仮定しない。生成コードに適格性選別はあるが、除外474raceの実物内訳を今回確定していない。hashだけで取得元の真正性を認定しない。

metadata抽出の初回はpartitionを年keyの辞書と仮定してnullを出した。後続で実際はyear field付きlistだと確認し、2022項目を抽出した（`provenance-partition.stdout.json`）。初回nullを原本の欠落と扱わず、古い出力も上書きしない。

## 6. 内部整合性の今回実測

| 項目 | 実測 |
| --- | ---: |
| inputs JSONL行 / race | 24,394 / 24,394 |
| history JSONL行 / entry | 170,835 / 170,835 |
| distinct meeting ID | 837 |
| race重複・非連続再出現 / entry重複 / race内bike重複 | 0 / 0 / 0 |
| inputs-history race/entry/bike・順序不一致 | 0 |
| 同raceの開催/日付/期間矛盾 / 同meeting期間矛盾 | 0 / 0 |
| 必須field欠落・型/日付・期間不正 | 0 |
| 2022年以外の対象race | 0 |
| input_as_of +09:00 | 170,835 |
| anchor AVAILABLE | 170,835 |

race IDの単調増加は要求せず、全体の再出現を集合で検査した。meeting IDの反復は正常所属として扱った。target全10fieldを検査し、正しい暦日、期間内race_date、明示offset付きinput_as_of、開催開始以降であることを確認。入力/履歴の順序付き識別streamは双方SHA `8b461c9f2f0d76b63ea132ae99f5fec6491b2ded8e22dd8fae253a0b9d4600e6`。ID集合はメモリ内だけで保持した。

| history状態 | 出走数 | 4値の扱い |
| --- | ---: | --- |
| AVAILABLE | 115,096 | 非負整数4個、NULLなし |
| LEFT_TRUNCATED | 54,577 | 全4値NULL |
| INVALID_HISTORY | 457 | 全4値NULL |
| PARTIAL_HISTORY | 705 | 全4値NULL |

計170,835。historyのNULLセル222,956と、観測された0セル197,385は別に数え、置換していない。12信号のNULLセル数は固定順07/08/10/11/12/23/24/26/31/32/39/42に対し、20,751 / 3,939 / 1,687 / 113,397 / 0 / 588 / 2,084 / 113,226 / 656 / 1,529 / 0 / 21,656。値の再計算・欠損補完はしていない。

manifestはv2だが、history.aggregate.calculation_versionは170,835件ともv1。未影響窓のv1保持という保存契約と整合し、v2へ改変しない。実入力とhistoryのstatus/4値も全件一致した。rank/status/labelsは既知field・型の検査だけで、結果評価・適格性能分母・NLL・fold判定に使用しない。

## 7. B1案のread-only件数再集計

内部整合を全件確認してから、Asia/Tokyoの暦日begin=2022-01-01/cut=2022-07-01/until=2023-01-01で開催を分類した。trainはstart>=beginかつend<cut、validationはstart>=cutかつend<until、それ以外を有効な境界保留とする。input_as_of・教師・履歴値は分類に使わない。2022対象開催に付随する2021/2023境界日付は、別年度raceを開くことではない。

| 区分 | 今回の開催 | 今回のrace | 今回のentry | PR114過去値との比較 |
| --- | ---: | ---: | ---: | --- |
| TRAIN | 408 | 11,926 | 83,516 | 全て一致 |
| VALIDATION | 421 | 12,282 | 85,958 | 全て一致 |
| HELD | 8 | 186 | 1,361 | 全て一致 |
| 合計 | 837 | 24,394 | 170,835 | 全て一致 |
| 保留: 年初 | 2 | 29 | 191 | 全て一致 |
| 保留: 7月 | 3 | 120 | 924 | 全て一致 |
| 保留: 年末 | 3 | 37 | 246 | 全て一致 |

TRAIN/VALIDATION、TRAIN/HELD、VALIDATION/HELDの各meeting/race/entry交差は全て0。3集合の被覆は全固定対象と一致。同race内entry/同meeting内raceは一つの区分のみで、元入力を分配・再直列化していない。**正式foldファイル/部分入力は生成・sealしておらず、B1採用でもない。**

## 8. 実行・証跡

新規の永続証跡root（以下R）:

```text
/home/shinya/neo-keirin-artifacts/c1-2022-real-data-preflight-01-20261021-0550-71d4b9
```

PHP `/usr/bin/php` 8.5.4。各実処理を以下の共通条件で起動した。完全なargv/各18 bindは `audit.command.json`、他工程のcommand記録に保存する。同じRへの上書き再実行用コマンドではない。

```bash
/usr/bin/bwrap --die-with-parent --unshare-net --ro-bind / / \
  --dev /dev --proc /proc --tmpfs /home --tmpfs /tmp --tmpfs /run \
  --tmpfs /mnt --tmpfs /media \
  --tmpfs /var/www/NeoBicycleRaceInformationWeb/storage \
  --tmpfs /var/www/NeoBicycleRaceInformationWeb/bootstrap/cache \
  --ro-bind /dev/null /var/www/NeoBicycleRaceInformationWeb/.env \
  ...個別の許可原本と監査スクリプトをread-only bind... \
  --clearenv --setenv PATH /usr/bin:/bin --setenv TZ Asia/Tokyo \
  /usr/bin/php -n -d date.timezone=Asia/Tokyo -d memory_limit=128M /tmp/audit.php
```

| 実処理 | 開始〜終了 JST | PHP処理秒 | peak bytes | exit / stderr bytes |
| --- | --- | ---: | ---: | --- |
| metadata-inspection-02 | 05:50:53〜05:50:54 | 0.485684 | 4,194,304 | 0 / 0 |
| dependencies | 05:51:24〜05:51:24 | 未個別計測 | 2,097,152 | 0 / 0 |
| audit（全行照合・件数） | 05:53:21〜05:53:24 | 3.267322 | 31,981,568 | 0 / 0 |
| provenance（全18 END） | 05:54:10〜05:54:10 | 0.385438 | 4,194,304 | 0 / 0 |
| provenance-partition（metadata抽出補足） | 05:54:55〜05:54:55 | 未個別計測 | 2,097,152 | 0 / 0 |

監査本体のtime -vはwall3.28秒、最大RSS39,416KiB。起動/待機を含むhost時刻は各execution記録、PHP内時刻/処理秒はstdoutへ分けて保存した。全行検査は1回だけ。実データへの変更・再生成はない。新規コードは単発の読取り監査だけで、アプリ/テスト/設定のPHP変更はない。

`audit.stdout.json` SHAは `00e2efce7bf628043a36c89c96506e22cd0b4869106ba5df7721319d4e3162c2`。本体のexit0は検査処理の終了を表し、総合PASSの意味ではない。結果JSON内はBLOCKED。`original-path-inventory.json` に実パス/mode/device/inode/mount、各PHP、command、stdout/stderr、execution、resource記録、初回起動失敗も保持した。実入力/モデル/Rawの複製やZIPは作成しない。

## 9. 静的確認・未確認・次の許可

変更は本書とMASTER PLANのみ。MASTER PLANをv1.74として冒頭metadata/5.0/工程表/15.81/PR115履歴/変更履歴/27へ同期し、旧5.0をv1.73履歴としてそのまま保持した。過去PR115 OPEN・未コミット終了・旧SHA/実績を削除しない。

文書のUTF-8/コードフェンス/相対リンク67件/YAML51ブロック/件数と証跡/凍結11状態/履歴不変/許可2ファイル限定を `python3 -B` / 既存PyYAML 6.0.3で静的検査しexit0。過去YAML・15.80/15.80.1・15.79以降の既存工程・v1.73以前の変更履歴・旧引継ぎ本文と記載済みSHAが保持されている。初回検査は旧引継ぎ比較に見出しも含めた検査範囲の誤りでexit1、旧本文に範囲を直して成功し、文書を緩和して通したものではない。

`git diff --check` はexit0。新規Markdownも `git diff --no-index --check -- /dev/null docs/c1-2022-real-data-preflight-01.md` でwhitespace警告なし（新規差分があるためexit1）。stagedは0、変更は本書とMASTER PLANだけ。PHPUnit/全体テスト/Pintはユーザー指定により未実施。監査用単発PHPの構文確認だけを行った。最終の検証ログ・差分と証跡manifestをRへ新規保存し、元資料を再sealしない。

| 判定対象 | 状態 |
| --- | --- |
| 主要5件の過去seal・全18件のSTART/END・参照範囲のmanifestリンク | PASS |
| 2022内部整合 / B1案の件数・非重複・被覆 | PASS / PASS |
| 元2022公式結果/固定STAT元行/履歴source行との直接照合 | NOT_ESTABLISHED |
| 当時の発走前利用可能性 | NOT_ESTABLISHED |
| 総合事前監査 | BLOCKED |
| 正式B1実データ受入 / 安全性専用テスト / fit・選択 | NOT_RUN / NOT_RUN / NOT_RUN |

input_as_ofの存在/offset一致は当時の発走・販売締切・初回公開を証明しない。研究者の既知2023〜2025結果に基づく手法選択バイアスも解消しない。現在DBとの一致は未確認であり、DB/HTTP/Raw/2026へ進まない。

次に許可されるのは**本報告と工程同期差分のレビューだけ**。一次資料の追加照合の必要範囲と十分性を判断する材料は第5節に記録したが、今回は開かない。B1製造・学習・lambda選択・2023最終OOF認定・H1-Uへ自動移行しない。未コミットで保持し、ChatGPTレビュー待ちで停止する。
