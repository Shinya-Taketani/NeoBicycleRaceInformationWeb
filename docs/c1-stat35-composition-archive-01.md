# C1-STAT35-COMPOSITION-ARCHIVE-01

## 範囲と状態

- 契約: `C1-STAT35-COMPOSITION-ARCHIVE-01-v1`。
- 状態: `COMPOSITION_ARCHIVE_VERIFIED_AWAITING_REVIEW`。PR96は `MERGED_REVIEW_COMPLETED`。
- clean main / origin/main `f1d3829bb38f40f1025353d7cbe3cdf06b3abbab`から、`feature/c1-stat35-composition-archive-01`を作成（fetchなし）。
- 保存済み2025年予測24,866件を固定入力・labelsと対応付け、100件/頁の閲覧用アーカイブを新規1回だけ生成。249頁、最終66件。
- 固定10件の旧閲覧画面とトップを維持。旧モデル・依頼・結果照合のContract/code identity、provider/bootstrap/共通数値/保存処理を変更しない。

```yaml
purpose: IN_SAMPLE_SAVED_PREDICTION_ARCHIVE
historical_as_of_available: false
generalization_performance_evaluated: false
formal_adoption: false
formal_freeze: false
live_use_authorized: false
2026_access: FORBIDDEN
gate_ci_bootstrap: NOT_RUN
points: null
```

学習期間内の保存予測照合であり、将来精度や正式採用の証明ではない。PR92のOuter比較とは別。学習/λ選択/Forward/予測再生成/repackage/業務DB/Redis/Raw/外部HTTP/2026参照は0。

## 固定資料

固定path/rows/bytes/SHAは専用 `Contract::sources()` に明記。CLI/HTTPから差替えない。

| 資料 | rows | bytes | SHA-256 |
| --- | ---: | ---: | --- |
| PR93 repackage commit-fix の predictions-2025/predictions.jsonl | 24,866 | 218,137,295 | cab188fad614e49c0d699fab38b71ea57ce3e902fc15d5aa2cfadd22cad23e23 |
| run-20261009-LE6Wit1O/result/verified-inputs/features-2025.jsonl | 24,866 | 72,144,103 | 4bf8c8ccd61125e2562807154b4cffafcb6fcd2985521206e59cfc9df0fa6bfa |
| tactical-history-01-review-fix-20260916-01/run-01/labels-2025.jsonl | 24,866 | 72,086,838 | 509cf6e4c823c07f73f11cbae31a6844a376ced6b48b4e09d3f750ea4058beca |

予測COMPLETEのstatus/publication version/対象path/入力seal/package参照sealと各sidecarを検証。提示のないsidecar/COMPLETEの現物identityは生成manifestの `sources` へ開始・終了一致として記録した（計8ファイル）。モデル本体は開かない。
旧10件対照Dのmanifestは29,058 bytes/SHA `2fd97547840c3a14b3703d431da49fd670e677c4b72ac1eb5aeddc726aa4f086`。Dは回帰照合だけで、新アーカイブの作成元ではない。旧execute/reproduceは再実行しない。

## 生成と閲覧契約

1. A/Bを既存Input/PredictionVerifier/Matcherで全件検証してから、Cの結果行を解析する。入力・予測・結果の不足/余分/重複/2026/識別不一致は拒否する。
2. year/race_id/entry ID/bikeで結合する。SQLite spoolで順不同資料を対応付け、保存予測のレース順・出走者順を保持。結果からは識別・rank/statusのみ抽出し、raw/signals等で入力を上書きしない。
3. 最大100レースの詳細だけを保持し、既存Matcher/Evaluatorをレース単位で使用。元の最大10件Calculationの上限は変更しない。
4. `summary.json`、小さい `race-index.jsonl`、`pages/000001.jsonl`～`000249.jsonl`とsidecar、監査用work.sqliteを保存。各頁に固定入力・保存予測・抽出結果・joined・寄与を保持する。
5. 生成時に保持した期待seal、索引・頁境界・全件対応、保存予測との一致、寄与再集約、source/code ENDを公開前照合。Publicationの排他/no-replace stage commitを再利用。失敗stage/作業索引/ログを保持し、削除・自動再生成はしない。
6. 新しい生成code identityは既存3系統の依存と新規生成コードを記録し、生成後の閲覧config/root/pinを含めない。旧Contract globへ新コードを混入させない。
7. HTTPは固定manifest/COMPLETE、summary、小さい索引、選択1頁だけを検証。元年次資料・モデル・他248頁を開かない。任意root/hash/file/yearをqueryで指定できない。
8. 一覧100件、厳密な正整数page、完全一致race_id検索→詳細。未知ID/不正pageは404、破損/設定未完は503。見つからないものをDBや新予測へfallbackしない。
9. flag既定false、local/人工testing、実REMOTE_ADDR loopback、GET/HEADだけ、no-store。新ルートだけCSP `form-action 'self'`、旧画面の `form-action 'none'` は不変。セッション/DB/Redisを解決しない。
10. Blade escape、内部path/例外詳細非表示。保存資料にない開催名・日付・選手名は未収録。start時点不明、同着、NULL/0、公式順位なしを維持する。

## 年次の実集計

matched=24,866、missing=0、mismatched=0。分子合計/分母合計、表示丸めのみ。

| 指標 | 分子 / 分母 | 率 | 評価除外レース |
| --- | ---: | ---: | ---: |
| 1着 | 9,928 / 24,789 | 40.0500% | 77 |
| 2着 | 5,740 / 24,727 | 23.2135% | 139 |
| 3着 | 4,680 / 24,739 | 18.9175% | 127 |
| 位置Hit@3 | 20,275 / 73,989 | 27.4027% | 203 |
| Primary完全順序一致 | 1,183 / 24,663 | 4.7967% | 203 |

位置Hit@3は公式1～3着が全て一意の24,663レースに限った位置一致 / (3×24,663)。順位別的中数の単純合計や率平均ではない。Primary完全順序とSupportingの `EXACT_ORDERED_TOP3_RATE`（1,211/24,663）を区別する。11指標と診断・除外理由・未丸め値はsummaryへ保存。

全24,866予測の完全payload（確率/decision含む）は原予測と厳密一致。全寄与を別PHPで再集約しsummaryと一致。Dの10件のinput/context/Primary/全確率/結果/寄与/小計も一致。
初回の独立参照では旧10件小計を年次順で加算し、NDCG浮動小数の厳密比較が失敗。参照v2だけを旧10件の旧順序へ修正して成功した。年次全体は元の年次順序のまま。初回コード・失敗ログも保持し、実生成を繰り返していない。

## 保存先・起動方法

```text
REVIEW=/home/shinya/neo-keirin-artifacts/c1-stat35-composition-final-01/composition-archive-review-20261010-172936
ARCHIVE=$REVIEW/archive-2025
manifest bytes=113784
manifest SHA-256=22c1f8b8b9f3ea7e72a041b4c5395ea84b2c211e532ce5405e1fe74eb0d344b8
```

config `composition_archive_view.php` へ上記出力とpinを固定。既存成果物/保存ルートは変更しない。
実行はbwrapで原本/repositoryをread-only、.env/storage/shared cacheとDBソケットを遮断、network namespace分離。自身の新review/runtime/人工Desktopと人工Trashだけを書込み可能にした。全argvは各execution.jsonに保持。

```bash
cd /var/www/NeoBicycleRaceInformationWeb
php -d memory_limit=128M artisan keirin:c1:composition-archive plan
php -d memory_limit=128M artisan keirin:c1:composition-archive build \
  --output=/home/shinya/neo-keirin-artifacts/c1-stat35-composition-final-01/composition-archive-review-20261010-172936/archive-2025
```

buildは2026-10-10 17:34:37～17:35:04 JSTに1回、26.808304秒/peak39,845,888 bytes/exit0。planはsource本文不可視で成立。
閲覧時は原本/labels/モデルを不可視にし、新アーカイブだけで動作確認。起動した自分のserver/browserは終了済み。
再閲覧用のrepo内routerは `scripts/composition-archive-server.php`。以下は既存の専用runtimeを使うprocess-local起動例で、.envや共有cacheを変更しない（port使用中なら別portを指定）。

```bash
C1_ARCHIVE_VIEW_RUNTIME=/home/shinya/neo-keirin-artifacts/c1-stat35-composition-final-01/composition-archive-review-20261010-172936/runtime \
C1_COMPOSITION_ARCHIVE_ENABLED=true PAO_DISABLE=1 APP_ENV=local \
php -d memory_limit=128M -S 127.0.0.1:8878 -t public scripts/composition-archive-server.php
```

確認したURL（検証port38843は終了済み）:

- 一覧: `http://127.0.0.1:8878/development/keirin/composition-archive/2025`
- 次/最後: `?page=2` / `?page=249`
- 完全一致検索: `?race_id=37750` → `/2025/37750`
- 公式3着なし: `/2025/12542`
- 旧10件以外の例: `/2025/12551`

| 操作 | HTTP秒 | PHP peak bytes |
| --- | ---: | ---: |
| 一覧・先頭 | 0.066033 | 12,582,912 |
| 次頁 | 0.064612 | 12,582,912 |
| 最終頁 | 0.049252 | 10,485,760 |
| 完全一致検索・302 | 0.055975 | 8,388,608 |
| 詳細37750 | 0.057087 | 8,388,608 |
| 詳細12542 | 0.071922 | 12,582,912 |
| 詳細12551 | 0.071694 | 12,582,912 |

Chrome/Node CDPで1440×1000と390×844の計12画面、各頁100/100/66件・保存確率/Primary・集計・12542評価対象外・GET検索遷移・横溢れなしを確認。ツール導入なし。

## 試験・制約・レビュー資料

- 新規 `CompositionArchiveTest`: 43 tests / 507 assertions、PHPUnit本体memory_limit128M、peak68.50MiB、exit0。人工201件・逆順結果・頁境界・ID検索・不足/余分/重複・不一致・同着/NULL・END drift・設定/pin/頁破損・escape・flag/local/loopback・GET CSP・原本/モデル/DBなしを確認。
- 直接関連の旧閲覧/結果照合/E05指標: 75 tests / 391 assertions、128M、peak50.50MiB、exit0。
- 変更限定Pint・変更PHP構文・diff --checkを最終確認。通常全体テスト・旧全Raw監査は今回未実行。
- 新Fixtureはhelper作成オブジェクトだけを保持し、親tearDown例外時もfinallyで退避を試みる。人工namespace内のDesktop/Trashのみ。既存helper・旧Fixtureは変更しない。
- 初回人工試験はテストのエラー文言期待値とPOST確認順に2件の不備。修正して成功、失敗ログは保持。新規skip/警告抑制/旧テスト緩和なし。
- 確認・検証は累積1800秒以内で、実装時間を除外。初期資料確認は保守的600秒枠、コマンド実測と画面/結果/最終文書確認区間を `$REVIEW/intervals.jsonl` に記録。正確な全初期手動区間を秒計測したという意味ではない。
- PR96残件の全体26 warnings詳細未確認、旧Fixtureの親tearDown例外時退避改善は未解決として別管理。今回解消したとは扱わない。
- 小型レビューZIP: `$REVIEW/C1-STAT35-COMPOSITION-ARCHIVE-01-review.zip`。差分/新規コード/契約/summary/代表記録/12画面/ログのみ。年次全データ、work.sqlite、モデル/.env/秘密情報は含めない。実在サイズ/SHAはreview-archive.jsonへ記録。

次は今回のコード・結果・画面レビューのみ。未コミットで停止し、別工程・LIVE・2026へ進まない。
