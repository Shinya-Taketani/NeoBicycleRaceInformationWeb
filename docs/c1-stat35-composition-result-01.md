# C1-STAT35-COMPOSITION-RESULT-01

## 今回の状態と範囲

- 契約: `C1-STAT35-COMPOSITION-RESULT-01-v1`。
- 状態: `COMPOSITION_RESULT_REBUILT_VERIFIED_AWAITING_REVIEW`。
- branch: `feature/c1-stat35-composition-result-01`、開始HEAD/ローカルorigin/main: `2f36e0adfc807a5a44b0e5aa78887cf1dd96f6b1`（PR94受入・merge確認、fetchなし）。
- 事故で失われた前回実装の再実装。指定資料の復元確認と安全helperレビューを利用し、コード/vendorの再復元・再インストールは行わない。環境全体の完全復旧を宣言しない。
- 保存済み固定10依頼と保存済み2025結果の照合だけ。実データ学習・λ選択・予測再生成・repackage・DB/HTTP/Raw/Migration/2026結果参照は0。
- 既存AGENTS安全差分、`CompositionResultTemporaryDirectory`とその専用テストを保持。旧モデル/依頼コード・成果物は変更しない。

```yaml
purpose: IN_SAMPLE_REPLAY_TECHNICAL_CHECK
historical_as_of_available: false
generalization_performance_evaluated: false
formal_adoption: false
formal_freeze: false
live_use_authorized: false
2026_access: FORBIDDEN
points: null
gate_ci_bootstrap: NOT_RUN
```

2025は最終学習期間内であり、未知データ精度の証明ではない。照合前の今回の固定順序は、過去の発走前保存を証明しない。

## 入力と依存

固定store:

`/home/shinya/neo-keirin-artifacts/c1-stat35-composition-final-01/request-store-01-pr94-validation-fix-20261010-064120-1bb292ad`

対象は2025年の37750、12542～12550。request IDは `dev-composition-2025-r<RACE_ID>-validation-fix-01`。
代表37750 manifestは5590 bytes/SHA-256 `bac8508b995c24e73ede87bc82c38456ae205d0571f29885318e788b27d1c05d`。
他9件は復元確認記録に保持されたPR94 create/showログ由来の期待sealと照合。ログ自体の外部独立pinまで保証するものではない。`manifest-provenance-check.json`へ根拠を保存した。

固定labels:

`/home/shinya/neo-keirin-artifacts/tactical-history-01-review-fix-20260916-01/run-01/labels-2025.jsonl`

24866行、72086838 bytes、SHA-256 `509cf6e4c823c07f73f11cbae31a6844a376ced6b48b4e09d3f750ea4058beca`。
対応sidecarのrows/bytes/SHAと本文を照合。CLIから任意labelsへ切替える経路はない。

保存requestのモデル参照はartifact SHA `75f793599687da3f6a946db6500921463df496144e6844e97a8c019a458a3de9`、receipt SHA `d0ddc8bbe9e04e812f467c967fec765e8943e660fa224b7abdbe10bbcb084755`。今回モデル本体は開かない。

既存の依頼Store/PredictionVerifier、TacticalPredictionResultのMatcher、Bt03e05MetricEvaluator、baseline tie定義、共有Files/JsonlArtifact/Publicationを変更せず接続する。
新規専用Contractで直接依存と定義元を別記録する。旧モデル96コード/依頼7コードのidentityは開始・終了一致し、新規実装を旧globへ混入させない。

## 実行・保存契約

1. 対象year/race/requestとmanifest bytes/SHAをselectionへ固定。空集合・重複・対象違い・不正年・破損を拒否する。
2. 全依頼を既存Store/PredictionVerifierで検証。元requestをbyte不変でコピーし、fixed input/predictionとfreeze順序をsealした後だけ結果を解析する。
3. labelsを完走し、年/全race重複/終了sealを確認。year/race、entry ID/bike、rank/statusだけ抽出し、Raw/anchor/signals/historyを予測へ入れない。
4. 対象上限10件だけを保持し、年別全payloadは保持しない。重複race検査には自身のstage内の一時SQLite spoolを使う（アプリ/本番DBではない）。
5. 既存MatcherでID＋bike対応。予測選び直し・配列位置join・順位振り直し・結果欠損の黙殺はない。同着/異常結果は既存Enumのまま保持。
6. 分子/分母を既存Evaluatorで合計。分母0はrate/delta=NULLと理由を表示。11指標・除外・診断・未丸め値を保存する。
7. 同じIDのguard排他後に既存成果物を先に検査。同じ契約/code/依頼集合/結果版ならREUSED、差があればCONFLICT。未完了/破損は拒否し、自動修復しない。
8. 書込み時に保持した期待seal、固定inventory、意味検算、source/code ENDを公開前照合し、既存Publicationで一式をno-replace配置する。再採取したhashだけで改変を追認しない。
9. showは保存束だけ。NOT_FOUNDをexecuteへ切替えない。reproduceは保存fixed/resultsからjoined/contributions/summaryを独立再計算し、元束と厳密一致を検査する。

保存inventoryは契約の14ファイル＋依頼ごと10ファイル＋manifest/COMPLETE（今回116）。意味出力のJSONL sidecarも検証する。
失敗stageと原因、再現stageは保持。公開確定後の補助例外はwarningで返し、成功成果物をFAILEDへ戻さない。
出力rootは既存合意root直下の専用名だけを許し、repository/元request/labels等との重複・相対/曖昧パスを作成前に拒否する。

## 固定10件の結果

車番は1着/2着/3着順。`なし`は公式順位集合が空で、評価不能を外れへ変換しない。

| race_id | 保存Primary | 実順位集合 | 1着 | 2着 | 3着 |
| --- | --- | --- | --- | --- | --- |
| 37750 | 2/1/6 | 1/5/6 | 不一致 | 不一致 | 一致 |
| 12542 | 1/3/4 | 1/5/なし | 一致 | 不一致 | 評価不能 |
| 12543 | 3/4/2 | 3/2/4 | 一致 | 不一致 | 不一致 |
| 12544 | 2/4/5 | 2/4/3 | 一致 | 一致 | 不一致 |
| 12545 | 1/3/2 | 1/3/5 | 一致 | 一致 | 不一致 |
| 12546 | 7/5/1 | 3/2/1 | 不一致 | 不一致 | 一致 |
| 12547 | 5/1/7 | 2/4/1 | 不一致 | 不一致 | 不一致 |
| 12548 | 7/2/3 | 6/2/1 | 不一致 | 一致 | 不一致 |
| 12549 | 3/7/4 | 7/4/3 | 不一致 | 不一致 | 不一致 |
| 12550 | 2/7/3 | 7/2/6 | 不一致 | 不一致 | 不一致 |

matched=10、missing=0、mismatched=0。

| 指標 | 構成モデル 分子/分母 | 率（表示丸め） | STAT-01 baseline | 差（percentage points） |
| --- | --- | --- | --- | --- |
| WINNER_HIT_AT_1 / POSITION_1_ACCURACY | 4/10 | 40.0000% | 3/10 | +10.0000 |
| POSITION_2_ACCURACY | 3/10 | 30.0000% | 2/10 | +10.0000 |
| POSITION_3_ACCURACY | 2/9 | 22.2222% | 3/9 | -11.1111 |
| POSITION_HIT_RATE_AT_3 | 8/27 | 29.6296% | 8/27 | 0.0000 |

Hit@3は公式1～3着が全て一意の9レースの位置一致8件 / (3×9)。12542を全体から除外するため、順位別の4+3+2を足した9/30ではない。
3着は12542の `NO_UNIQUE_OFFICIAL_POSITION` を除外。Hit@3/完全順序は `NO_UNIQUE_OFFICIAL_ORDERED_TOP3` を除外する。
Primary完全順序はdecoder_diagnosticsのPRIMARY_EXACT_ORDERED_TOP3_RATEとordered_eligibleから0/9=0%。SupportingのEXACT_ORDERED_TOP3_RATEも今回は0/9だが、別指標として保存し混同しない。
上記表示の丸めを保存JSONへ戻さない。Gate/CI/bootstrapは実施しない。10件の結果を学習完了や将来精度向上と表現しない。

## 実在する成果物・実行記録

```text
RESULT_ROOT=/home/shinya/neo-keirin-artifacts/c1-stat35-composition-final-01/result-store-01-rebuild-20261010-Ih1vik
REVIEW_DIR=/home/shinya/neo-keirin-artifacts/c1-stat35-composition-final-01/result-rebuild-review-20261010-Ih1vik
EVALUATION_ID=dev-composition-2025-fixed10-result-rebuild-01
```

照合束: `$RESULT_ROOT/evaluations/$EVALUATION_ID/`。manifestは29058 bytes/SHA-256 `2fd97547840c3a14b3703d431da49fd670e677c4b72ac1eb5aeddc726aa4f086`。
再現証跡: `$RESULT_ROOT/reproductions/dev-composition-2025-fixed10-result-rebuild-01.inprogress-608ad802ec72b92cbf10b112/REPRODUCED.json`。成功証跡は残し、後片付けしない。
`$REVIEW_DIR/reference-verification.json`、実行別stdout/stderr/argv/時刻/exit/peak、選択一覧・開始sealを保存した。

下記はそれぞれ1回だけ実行。REQUEST_STORE/LABELSは上記固定値、SELECTIONは `$REVIEW_DIR/selection.json`。実際の絶対pathとbwrap引数全体は各 `execution.json` に保存。

```bash
php -d memory_limit=128M artisan keirin:c1:composition-result plan
php -d memory_limit=128M artisan keirin:c1:composition-result execute \
  --evaluation-id="$EVALUATION_ID" --request-store-root="$REQUEST_STORE" \
  --requests="$SELECTION" --output-root="$RESULT_ROOT" --json
php -d memory_limit=128M artisan keirin:c1:composition-result show \
  --evaluation-id="$EVALUATION_ID" --output-root="$RESULT_ROOT" --json
# 同じexecuteを1回: REUSED
php -d memory_limit=128M artisan keirin:c1:composition-result reproduce \
  --evaluation-id="$EVALUATION_ID" --output-root="$RESULT_ROOT" --json
```

| 操作 | 秒（wrapper実測） | peak bytes | exit |
| --- | --- | --- | --- |
| plan | 0.100251674 | 31457280 | 0 |
| execute CREATED | 0.834933470 | 35651584 | 0 |
| show SAVED | 0.121809182 | 33554432 | 0 |
| execute REUSED | 0.212912332 | 33554432 | 0 |
| 別PHP reproduce | 0.169691171 | 33554432 | 0 |

2026-10-10 15:06:10～15:06:11 JST、合計1.439597829秒、各128M、stderr空。別PHP再現では元request store/年別labels/packageを空read-only bindでアクセス不可にした。
外部の独立参照処理は元requestとlabelsから対応を作り、既存Evaluatorを直接呼んだ。新Service/保存joinedを再利用して一致を作らず、10寄与・全分子分母・11指標・show/REUSED/reproduceを厳密比較し一致。
参照wrapper0.456606793秒/peak12582912 bytes/exit0。直接使用した原本103ファイルとモデル96/依頼7コードSTART/END不変。

## 安全対策・今回の検証

`CompositionResultFixture`は作成時のhelperオブジェクトを保持し、正常tearDown・初期化例外で `retire($temporary->path(), reason)` だけを呼ぶ。request IDからdirnameを推測しない。
子PHPの領域も作成した親オブジェクトが管理。実データの結果束・失敗証跡・元資料は移動せず、永久削除へのfallbackはない。helper自身の再設計・網羅試験は行わない。

bwrapで原本filesystemはread-only、network namespace分離、DB unix sockets遮断、process-localのDB/HTTP解決拒否。テストは.env読込み/通常Laravel起動を避ける人工Application。
helper既定 `/home/shinya/Desktop` はテストnamespace内で `$REVIEW_DIR/synthetic-desktop` にbindし、人工rootと人工Trashだけを書込み可能にした。実Desktop/Trashの既存内容を移動する試験はない。
人工Trashと失敗ログを保持し、自動整理しない。今回の実行は新result rootと新review dirだけを書込み可能にし、旧成果物への書込みを遮断。

最終コードの結果:

- 専用: `php -d memory_limit=128M vendor/bin/phpunit --do-not-cache-result --colors=never tests/Feature/CompositionPredictionResultTest.php`、44 tests / 229 assertions、5.162秒（wrapper5.241058163秒）、peak32.50MiB、exit0。
- 直接関連: Bt03e05MetricsTest/Bt03e06DecoderTest、11 tests / 140 assertions、128M、peak22MiB、exit0。
- 限定Pint: 新規9PHP＋保持helper/安全testの11指定ファイル、exit0。11PHPの `php -l` 成功。
- 通常全体テストは最新指示により未実行。復旧後3342 passed / 31187 assertions / 9 skippedは既存コードの過去実績で、新機能の成功に転用しない。
- 初回の人工Fixture入力過剰field、2回目の人工labels/output配置不備による失敗ログを保持。productionのパス/数値保護を緩めずFixtureを修正し、最終対象が成功した。

回帰範囲: 正常10件、5～9車/結果逆順、同着1/2/3・既存異常Enum、指標別分母/分母0、結果不足/余分/重複/本人車番不一致/不正rank/status/2026末尾拒否、予測seal前の結果未解析、CREATED/REUSED/CONFLICT、実2プロセス排他、再sealした集計矛盾/生成時改変/source END drift、未完了/破損/NOT_FOUND、確定後warning、安全helper正常/例外。
100MiB超の人工年別labelsは独立PHPにmemory_limit128Mを実適用し実execute、exit0/matched1を要求した。full suiteの共有peakで合否を決めない。新規skip・既存テスト削除・緩和0。

追加確認の上限は累積1800秒で管理。保存された検証/実行コマンド（失敗試行・限定formatter含む）の実測合計は15.210970512秒。準備読取りは900秒の保守的配分、最終文書照合・ZIP確認は残枠内に限定し、実装時間とは区別する。読取り・推論の全区間を秒単位で計測した値ではない。

## レビュー資料と未確認事項

小型レビューZIPは `$REVIEW_DIR/C1-STAT35-COMPOSITION-RESULT-01-review.zip`。新規を含むソース/差分、選択一覧、固定10件束、テスト/実行/参照ログを収録する。全年別labels、人工100MiB資料、.env/鍵/vendor/Rawは含めない。サイズ・SHA・存在確認は同じreview dirの `review-archive.json` へ保存する。
過去実装の消失ログ・復元監査は既存証跡のまま保持。APP_KEY・全storage/Raw・DBの完全復旧を追加監査したとは扱わない。
未確認: 将来/未知データ精度、歴史的発走前の取得時点、全環境復旧、今回の通常全体回帰。次は今回の結果/コードレビューのみ。未コミットで停止し、LIVE/2026/別工程へ進まない。
