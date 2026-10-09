# C1-STAT35-COMPOSITION-REQUEST-01

## 状態と範囲

2026-10-10。開始main/origin `c9fdc3d72292b7c7af83f8d67fba7cdc84affd89`、PR93 `MERGED_REVIEW_COMPLETED`。
branch `feature/c1-stat35-composition-request-01`。
状態 `COMPOSITION_REQUEST_FLOW_VERIFIED_AWAITING_REVIEW`。次は今回結果のレビューだけ。
依頼契約 `C1-STAT35-COMPOSITION-REQUEST-01-v1`。

P1は固定最終C2、P2/P3は固定最終C1。既存Forward/確率計算/E06 decoderをそのまま呼ぶ。
既存モデル側Contract::code()の96ファイル、旧C1 pipeline/request、旧正式モデル・成果物は変更しない。
新namespaceとCommandだけのcode identityを別に保存し、provider/bootstrap/composer/vendorは変更しない。
実データ学習・lambda選択・repackage・履歴/STAT生成・性能評価・CI・Gateは各0回。

用途 `DEVELOPMENT_FEATURE_SNAPSHOT_REPLAY`、historical_as_of_available=false、
formal_adoption/formal_freeze/live_use_authorized=false、points=null、2026_access=FORBIDDEN。
性能 `NOT_PERFORMED_REQUEST_FLOW_TECHNICAL_VERIFICATION_ONLY`。
保存完了は発走前予測済み、正式freeze、LIVE、精度向上を意味しない。
未知のinput_as_of/observed_atはNULL。選手名・開催名・日付をDBやIDから補完しない。

## 固定資料

root `/home/shinya/neo-keirin-artifacts/c1-stat35-composition-final-01/`。

| 用途 | rootからの相対パス | bytes | SHA-256 |
|---|---|---:|---|
| 公開package | pr93-repackage-commit-fix-20261009-e91a7f3c/package/artifact.json | 119032 | 75f793599687da3f6a946db6500921463df496144e6844e97a8c019a458a3de9 |
| v3公開receipt | 同packageのRELEASE_COMMITTED.json | 847 | d0ddc8bbe9e04e812f467c967fec765e8943e660fa224b7abdbe10bbcb084755 |
| outcome-free入力 | run-20261009-LE6Wit1O/result/verified-inputs/features-2025.jsonl | 72144103 | 4bf8c8ccd61125e2562807154b4cffafcb6fcd2985521206e59cfc9df0fa6bfa |
| 生成後の照合専用 | pr93-repackage-commit-fix-20261009-e91a7f3c/predictions-2025/predictions.jsonl | 218137295 | cab188fad614e49c0d699fab38b71ea57ce3e902fc15d5aa2cfadd22cad23e23 |

C1 model SHA `e3cc1f7f10af60bb22f97a2172ca52ef2c09a3393222ee46c25619de752d43a1`。
C2 model SHA `d1dbb706071a7dc25d4ea8fa0525ac685a8b68d6d09f3a43a980a70dc1334ea2`。
artifact/receiptをpinと照合後、通常Package::load()で公開v3を検証する。hashだけで公開承認しない。
年別入力のmanifest/input sidecarは実ファイルからSTART sealを取り、既存Input::read()契約とENDで照合する。
CLIの任意source/latest/DB/別年へのfallbackなし。実入力は2025だけ。人工DIは2024/2025のみ。
2026・不正整数・不正request_idをファイルを開く前に拒否する。

## 操作契約

`plan`: 固定契約を表示。DB接続/source本文/ファイル作成なし。

`create`: request_id、year、race_id、固定artifact/receipt/input/input_version、確率/decoder版、
接続code identityを意味identityとして扱う。時刻・PID・stage名を含めない。
Input::read()を最後まで消費し、後方重複・型・未知/結果field・sidecar/本文の終了検査を完了。
対象1レースの全出走者と元順序・17項目・history_status・NULL/0・raw/rank/anchorを保持する。
Forwardの確率・Primary・Supportingをそのまま保存し、独立argmaxで作り直さない。

専用rootは合意root直下の `request-store-01-<unique>`。旧source/package/run/予測、repo、symlink、
traversal、非所有の非空rootを準備・書込み前に拒否する。既存ArtifactStoreを緩めない。
STORE.jsonで所有契約を検証し、既存Publication APIのnever-published guard destinationを使ってrequest_idごとに排他。
公開先は `requests/<request_id>/`。生成時の期待bytes/sealと実ファイルを比較し、
source/model/code END検証、全inventory・入力/予測対応検証後、既存no-replace atomic directory commitで公開。
失敗時は自身のinprogress stage/FAILEDを残し、既存依頼を修復・上書きしない。確定後補助例外はwarning。

固定inventoryはrequest.json、input.jsonlとmanifest/input sidecar、prediction.jsonlとmanifest sidecar、
model-reference.json、runtime.json、manifest.json、COMPLETE.json。計10ファイル。
両親モデルは参照sealのみを保存し複製しない。生成途中の実ファイル走査で改変を追認しない。

同じcreate: 排他後まず既存一式を検証。同じ宣言済みidentityなら `REUSED`、異なれば `CONFLICT`。
REUSEDは年別入力抽出・Package解決・Forward呼出しなし。公開bytes/時刻/manifestは不変。

`show`: 公開されたrequest_idだけを検証し表示。未保存は `NOT_FOUND`、store/依頼を自動作成しない。
入力・予測のyear/race/entry/bike対応、原値、Primary、use制限、完了証拠を検証する。
モデルsource・年別入力・DB・推論に依存しない。%表示はP1/P2/P3の**無条件周辺確率**。
winner条件付きQ2/Q3は保存decisionとして区別し、Supportingも保存値のまま。`--json`は未丸め数値。

`reproduce`: 保存1レースinputと同じartifact/receiptを通常loadし再計算。明示移設artifactも同じpinが必須。
保存Forward出力と厳密一致、package/code END検証、元依頼一式不変を確認。元年別/学習source/照合予測不要。
別stageへの推論成果物保存はせずstdoutに再現結果を出す。

## 実行済みCLI

作業場所 `/var/www/NeoBicycleRaceInformationWeb`。以下のstoreは実在し、40操作すべてexit0。
環境DB/HTTPはCommand内でprocess-localに拒否。SQLite一時spoolだけを使う。

```bash
STORE=/home/shinya/neo-keirin-artifacts/c1-stat35-composition-final-01/request-store-01-20261010-053237-4be690a5
PACKAGE=/home/shinya/neo-keirin-artifacts/c1-stat35-composition-final-01/pr93-repackage-commit-fix-20261009-e91a7f3c/package
php -d memory_limit=128M artisan keirin:c1:composition-request plan
php -d memory_limit=128M artisan keirin:c1:composition-request create \
  --year=2025 --race-id=37750 --request-id=dev-composition-2025-r37750-01 --store-root="$STORE" --json
php -d memory_limit=128M artisan keirin:c1:composition-request show \
  --request-id=dev-composition-2025-r37750-01 --store-root="$STORE" --json
php -d memory_limit=128M artisan keirin:c1:composition-request reproduce \
  --request-id=dev-composition-2025-r37750-01 --store-root="$STORE" --artifact="$PACKAGE/artifact.json" --json
```

予測/正解を見る前に固定した対象は37750＋元保存順先頭9件12542～12550。
selection.jsonへ選定規則・入力seal・一覧を先行保存。10 create/10 show/10 REUSED/10 reproduce/10参照厳密一致。
照合Cは全createの固定保存後に開き、year/race/entry/bikeを確認して対応付けた。正解/教師は参照0。
show/REUSEDはrepo＋storeのみのopen_basedir、reproduceはこれに公開packageだけを追加した独立PHPで成功。
年別/旧source/照合予測を読めないため、再送の抽出・再計算をしていないことを検証できる。
START/ENDモデルコード96、package/receipt、両親/model/layout/selection、年別入力・照合予測hashは不変。

実行2026-10-10 05:32:37～05:32:52 JST、15.419245958秒。各コマンド128M、最大peak32MiB、driver28MiB。
証跡はstore/review/のstart.json・selection.json・verification.json・実argv/stdout/stderr・verify.php。
37750代表manifest: `requests/dev-composition-2025-r37750-01/manifest.json`、5336 bytes、
SHA `ed75263496df9634ea381415ebd94ff0201ecdcc179022a172f3150f8b46849d`。

### 保存された37750の予測

Primary: **1着車番2 / 2着車番1 / 3着車番6**。既存2025予測と厳密一致。
以下は表示丸めのみ。保存数値はreview/race-37750-view.jsonの未丸め値。

| 車番 | entry_id | P1周辺% | P2周辺% | P3周辺% |
|---:|---:|---:|---:|---:|
| 1 | 268978 | 17.225582 | 22.817234 | 21.636400 |
| 2 | 268979 | 41.506586 | 21.255688 | 13.329804 |
| 3 | 268980 | 14.045394 | 18.862158 | 16.599895 |
| 4 | 268981 | 14.107013 | 15.112514 | 14.486413 |
| 5 | 268982 | 3.993128 | 7.428918 | 11.102160 |
| 6 | 268983 | 8.554510 | 13.003883 | 19.908735 |
| 7 | 268984 | 0.567787 | 1.519605 | 2.936593 |

## テストと未対応

専用47件/166 assertionsが128Mで成功。100MiB超人工年別入力を独立128M PHPでactual createまで実行。
全員抽出・5～9車・欠番・非単調・NULL/0・後方破損/重複/結果field/2026・厳密Forward・
CREATED/REUSED/CONFLICT・saved-only拒否依存・改変/再seal対応不一致・stage非公開・
2process排他・END/write/commit失敗・確定後warning・reproduce不一致・path/symlink・学習依存未解決を確認。
既存PR93関連107件/546 assertionsが128Mで成功。旧テスト・モデルseal対象は変更0。
最終コードの通常全体 `php artisan test` は1回、3252 tests / 3243 passed / 30247 assertions / 既存9 skipped、
319.894秒、exit0。全体peakは未計測。7変更PHPのphp-l・限定Pint --test・git diff --checkは成功。
既存テスト削除/緩和/新規skipなし。共有ZIPはstore直下の `C1-STAT35-COMPOSITION-REQUEST-01-review.zip`。
review/final-checks.jsonにテスト結果、changes.patchに未コミットの新規ファイル込み差分、review-zip.jsonに実ZIPのbytes/SHAを保存する。

未対応: DBからの新入力生成、Web/API、scheduler、未来発走前利用可否、LIVE、正式採用/freeze。
今回は精度評価ではない。既知のscore-gap P3/E08否定/旧pilot保留等は維持。
既存学習/成功run/失敗証拠/公開成果物を変更しない。commit/push/PR操作なし、レビュー待ちで停止する。
