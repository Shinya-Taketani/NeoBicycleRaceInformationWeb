# TACTICAL-PREDICTION-RESULT-01

2026-09-18。`VERIFIED_AWAITING_REVIEW / DEVELOPMENT_REPLAY_ONLY`。
main `aebabc3618706a8e9c1e7b2f2c4c88538e620b02` から `feature/tactical-prediction-result-01` で実装。

## 範囲と制限

固定済みrequestの予測を変更せず、保存labelsの結果と照合する。推論・学習・DB接続・Gate・CI・bootstrapは行わない。
用途は **IN_SAMPLE_REPLAY_TECHNICAL_CHECK**。最終モデル学習期間内の接続機能確認であり、未知データ精度、改善の証明、モデル選択、LIVE採用、2026評価ではない。
PR #57のコード修正レビュー・マージは完了。**ChatGPT側の旧63レース報告ZIP本体の照合は未完了**。以下の実行確認はCodexが行ったものであり、ChatGPTによる確認済みとは記載しない。

## 入力と契約

`--requests` はJSON objectで、`result_year`（2022-2025の整数）、`targets`（配列）を必須とする。
各targetは `year`, `race_id`, `request_id`, `bundle_path`, `manifest: {bytes, sha256}` を持つ。`evidence` は参照資料の絶対パスからsealへの任意のmap。配列順を維持し、race_id昇順は要求しない。
この一覧は今回、旧 `targets.json` と `validation-status-fix-01/requests.json` / `comparisons.json` の突合から作成。requestsディレクトリの全件探索で対象選択しない。

- 全targetの既存ArtifactStoreによるLOCKED/manifest/固定11ファイル、モデル識別、入力/予測identityを先に検証する。旧ArtifactStoreのinventoryは変更しない。
- 最終モデルSHA-256は `e3cc1f7f10af60bb22f97a2172ca52ef2c09a3393222ee46c25619de752d43a1`。
- 予測集合・結果原本とsidecar manifestのhashを `sources.json` に固定してから結果を解析する。
- 年別labelsは行単位に読み、年と重複raceを検査。選択結果だけを抽出する。出走ID・車番は型/範囲/重複/集合を検査し、位置でjoinしない。
- 結果側から使うのはyear/race_id/id/bike/rank/statusだけ。raw・anchor・signals・historyは固定inputを維持する。Enum外のstatus、FINISHED/TIEDの不正rank、異常statusの非NULL rank、欠落を拒否する。
- 比較に使う保存decisionは変更せず、`Bt03e05MetricEvaluator::raceComparison/add/finish` へ接続する。labels生成やOutcomeContextSnapshot再生成、Evaluation::evaluate()は呼ばない。
- メモリには選択metadataと結果のID/byte offsetのみ保持し、結果payloadを蓄積しない。人工の128MB超年別labelsでも128MB設定で成功。

## 保存と再現

既存合意baseの新規専用rootだけを使用。モデル/予測/labels参照元との重複を準備前に拒否する。明示request一覧だけは専用root直下にも置けるが、seal検査し上書きしない。
`evaluations/<evaluation_id>/` が独立した公開束。`request/sources/code/fixed/results/joined/contributions/summary/source-end/manifest/LOCKED` とJSONL sidecarを保存する。
`fixed.jsonl` は既存固定入力と予測のコピー、`results.jsonl` は選択した識別情報・rank/statusのみ、`joined.jsonl` は固定入力へ結果を結合した照合資料。
全原本・コードの終了検査後、stageをrenameして公開。同じID/同じ原本・コードは検証再利用、相違はCONFLICT、破損は再生成しない。flockで同時実行を拒否し、途中失敗stage/failure.jsonを残す。公開前の入力検証エラーはコマンドが非0終了し、原本・公開領域を変更しない。
再現は保存fixed/resultsからjoin・寄与・集計を作り直し、元の3ファイルhashと完全一致を要求する。DB・元labels・モデル推論は不要。今回の再現stageと成功イベントを監査用に保持し、既存stage/失敗証拠/固定requestは削除しない。
実行コードhash/PHP版は `code.json`。旧学習コードと区別し、元成果物を変更しない。結果訂正は人工データの別labels版・別evaluation_idで試験し、実結果を修正しない。

## 実行済みコマンド

```bash
php -d memory_limit=128M artisan keirin:backtest:tactical-prediction-result --plan

DB_CONNECTION=disabled-offline-verification DB_URL='' \
php -d memory_limit=128M artisan keirin:backtest:tactical-prediction-result \
  --execute --mode=DEVELOPMENT_REPLAY_ONLY \
  --evaluation-id=development-2025-12-31-fixed63-01 \
  --requests=/home/shinya/neo-keirin-artifacts/tactical-prediction-result-01-20260918-01/selected-requests.json \
  --labels=/home/shinya/neo-keirin-artifacts/tactical-history-01-review-fix-20260916-01/run-01/labels-2025.jsonl \
  --labels-manifest=/home/shinya/neo-keirin-artifacts/tactical-history-01-review-fix-20260916-01/run-01/labels-2025.jsonl.manifest.json \
  --output-root=/home/shinya/neo-keirin-artifacts/tactical-prediction-result-01-20260918-01

DB_CONNECTION=disabled-offline-verification DB_URL='' \
php -d memory_limit=128M artisan keirin:backtest:tactical-prediction-result \
  --reproduce --mode=DEVELOPMENT_REPLAY_ONLY \
  --evaluation-id=development-2025-12-31-fixed63-01 \
  --output-root=/home/shinya/neo-keirin-artifacts/tactical-prediction-result-01-20260918-01
```

plan / RESULT_LOCKED / REPRODUCED / 同executeのREUSEDを確認した。ログは専用rootの `checks-01/` と `verification-01/`。
labelsはv2の `run-01-completion.json` から特定。24,866行、72,086,838 bytes、SHA-256 `509cf6e4c823c07f73f11cbae31a6844a376ced6b48b4e09d3f750ea4058beca`。
旧接続報告ZIPは1,889,403 bytes、SHA-256 `b9de265d8a8b0901271f6cbfa6ef9c8bfedeaff04d8088cd13da2f8909d32f3b` と一致。

## 技術照合結果

固定2025-12-31の63レース・432出走。照合成功63、不足0、不一致0。39件再利用/24件生成という旧区分を維持。
765参照ファイルと63予測・最終モデルhashは開始/終了不変。DBを無効にした再現と再利用も成功。
同じ原本を既存Evaluatorへ直接渡した独立参照スクリプトで、全レース寄与、分子/分母の累積、全11指標を厳密一致確認。

| 指標 | 保存C1 分子/分母 | 率 | 固定rawのSTAT-01 分子/分母 | 率 |
|---|---:|---:|---:|---:|
| WINNER_HIT_AT_1 / POSITION_1_ACCURACY | 26/63 | 41.269841% | 21/63 | 33.333333% |
| POSITION_2_ACCURACY | 18/63 | 28.571429% | 16/63 | 25.396825% |
| POSITION_3_ACCURACY | 10/63 | 15.873016% | 6/63 | 9.523810% |
| POSITION_HIT_RATE_AT_3 | 54/189 | 28.571429% | 43/189 | 22.751323% |

Hit@3は各着順の位置一致数/(3×一意な公式上位3着の対象数)。3人全員一致率・3連単的中率ではない。
EXACT_ORDERED_TOP3_RATEは既存定義のSupporting MAP、Primary完全順序一致はdecoder_diagnosticsに別保存。混同しない。
分母0の内部Evaluator値は変更せず、表示用rate=null、UNEVALUABLE_ZERO_DENOMINATORと除外数/理由を保存する。集計は分子/分母の合計でありレース別率の平均ではない。

## テスト・停止位置

新規50テスト、235 assertions。5-9車、同着1/2/3着、失格/未完走/欠場/取消/落車、欠損・ID/車番・rank/status・年・重複拒否、順不同join、入力非置換、訂正版、再利用/CONFLICT、flock排他、同サイズ改変、終了drift、DB不要再現、128MB超labelsを検証。
既存接続48件/926 assertionsも成功。`php artisan test` および直接PHPUnit 128MBはともに1,214件中1,205成功・PostgreSQL専用9skip、8,989 assertions。
変更PHPのPint・7ファイルのphp -l・git diff --check成功。全体Pintは未変更 `Bt03e08BoundedMemoryTest.php` の既存statement_indentation指摘1件で失敗。範囲外なので変更しない。
Migration・DB変更なし。2026・LIVE・新規取得・OOF・fit・予測再生成・Gate・CI・bootstrap未実施。レビュー待ちで停止する。
