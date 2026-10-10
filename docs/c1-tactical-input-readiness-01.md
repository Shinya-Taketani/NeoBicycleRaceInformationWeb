# C1-TACTICAL-INPUT-READINESS-01

## 状態と範囲

2026-10-11、契約 `C1-TACTICAL-INPUT-READINESS-v1`。結論は **PARTIAL_READINESS**、コード・候補証跡レビュー待ち。
PR98は `MERGED_REVIEW_COMPLETED`。clean main / ローカルorigin `90520bbd47d8192efe6fa01f102edf05062b46f0` から `feature/c1-tactical-input-readiness-01` を作成した。fetch・commit・push・PR操作なし。

今回許可されたのは固定C1の2022〜2025年の出走表属性のREAD ONLY確認と候補保存・offline再現だけ。既存C1/C2・構成モデル・予測・年次アーカイブ・数値契約は変更していない。
`historical_as_of_available=false`、`prediction_use=NOT_AUTHORIZED`、精度改善 `NOT_MEASURED`。学習・評価・bootstrap・推論・repackage・全体テスト・DB書込み・Migration・新規取得・2026参照は0回。

## 根拠と意味

ユーザー指定原本はURLエンコードされた実ファイル名で読取り可能だった。`/mnt/data/統計エンジン要件定義_STAT-01-46確定_v1.0(2).md` はこの環境では未入手。
確認した原本は `/home/shinya/ダウンロード/%E7%B5%B1%E8%A8%88%E3%82%A8%E3%83%B3%E3%82%B8%E3%83%B3%E8%A6%81%E4%BB%B6%E5%AE%9A%E7%BE%A9_STAT-01-46%E7%A2%BA%E5%AE%9A_v1.0(2).md`、SHA-256 `73b4846b7cdeeb090dbb1c4410e12cb8ba717020f07c77de15e1fee28b7bc4ab`。STAT-17/18/28/29の各節を確認した。

- STAT-17は戦法多様性で、登録脚質は補助情報。登録脚質だけで完成STATとしない。
- STAT-18は過去のライン構成・継続履歴が必要。履歴不足を継続0回へ置換しない。
- STAT-28の先頭・番手・後位・単騎役割と登録脚質は別物。順序・本人・明示/推定の出典未確認なら役割NULL。
- STAT-29は同じレースの発走前ライン履歴が必要。単一の現在値から変更回数を作らない。

`RaceEntryListParser` はJSJ017 `sInfo.kyaku`、`RaceDetailParser` はPJ0315 `sensyuTypeInfo[].kyakusitu` を取り込む。`RaceRepository::syncRaceDay()` と `updateRaceDetail()` は `riding_style` と汎用 `fetched_at` を更新する。
`line_text` は列とモデル属性が存在するが、現行Parser/Repositoryの保存元は見つからなかった。列の存在をライン情報の保存証明としない。
列専用の取得時刻・取得版への直接参照がないので、`fetched_at` が発走前でも後でもフィールドの観測時刻へ転用しない。全件 `UNKNOWN_SOURCE_TIMING`、専用 `observed_at=null`。発走後のみのフィールド取得が確認できた件数も未確認であり、汎用時刻から認定しない。

## 固定資料と接続

新規rootは `/home/shinya/neo-keirin-artifacts/c1-tactical-input-readiness-01/`。今回の一意な実行親は:

`/home/shinya/neo-keirin-artifacts/c1-tactical-input-readiness-01/run-20261010-224221-fb937a1d/`

固定資料のmanifest・COMPLETE・直接使用ファイルを照合してからDBへ接続した。

| 用途 | 保存資料 | manifest SHA-256 |
| --- | --- | --- |
| 結果非依存C1入力 | `stat35-c1-input-02/run-20260930-054458-8739da4b/result` | `7f4356b93f90203dc727dc95d6215a8d8ce3f44869c4441c3981087ff1099c26` |
| C1 targetだけの既存抽出 | `stat35-c1-context-01/run-20260928-215453-d152edab/extraction` | `1e1975b5d2c0b598870bb3b860a1cfdbe9bbfbcbaba035e3d878be5928f9a021` |
| seal済み本人対応 | 同実行の `mapping` | `5facd83259a5b2b147115ff1632632a6f7a11f96e07f9f4d3078286743eb839b` |

表の保存資料はすべて `/home/shinya/neo-keirin-artifacts/` 配下。共通元manifest、年別件数、結果非依存入力hash、mappingのextraction参照も一致確認した。旧history全量を再結合せず、確認済みtarget抽出と本人対応を再利用する。本人IDは任意のorigin自己申告ではなく固定mappingのsealと対象一致から接続する。
race/entry ID・車番・レース日・内部/外部選手IDで結合し、車番重複・対象外/不足行・別年・別選手・2026・余分な結果列を拒否する。UNKNOWN値と本人対応不能は別。本人対応不完全なら対象を落としてCOMPLETEを出さない。

## READ ONLY抽出と実測

既存 `AgariC1Context\ReadOnlySession` を再利用。実接続先は `127.0.0.1:5432 / neo_keirin_prediction_db / public`、session/transactionともread-only、隔離 `repeatable read`、snapshot `484420:484420:`。
500出走ずつのSQL VALUESによる固定entry/race/date完全一致と、SQL側の2022〜2025年制限を併用した。取得列は識別子・脚質・ライン・汎用取得日時・予定発走時刻のみ。結果/払戻/現在の選手プロフィールは照会していない。SQLと対象件数は各SELECT前に記録した。
同一REPEATABLE READ snapshot内のSTART/END属性hashは共に `aa8cd4986f3fdfd4f0b6d1d5e753065dddec894110b3de24cb599897ab2c7c26`。これは固定snapshot内の一致であり、別トランザクションから見た同時更新がなかったという主張ではない。終了時はrollback/disconnectし、DB書込みなし。

| 年 | レース | 出走・正常対応 | 脚質値/正規化可能 | ライン値 | 時点検証済み | 時点未確認 | 識別不一致 |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 2022 | 24,394 | 170,835 | 170,835 | 0 | 0 | 170,835 | 0 |
| 2023 | 25,197 | 179,007 | 179,007 | 0 | 0 | 179,007 | 0 |
| 2024 | 25,212 | 179,089 | 179,089 | 0 | 0 | 179,089 | 0 |
| 2025 | 24,866 | 177,120 | 177,120 | 0 | 0 | 177,120 | 0 |
| 合計 | 99,669 | 706,051 | 706,051 | 0 | 0 | 706,051 | 0 |

脚質coverageは100%。脚質のNULL/空文字/未対応原文は各0件。ラインは全706,051件NULLで、空文字・値ありは各0件。
正規化表は `逃→逃 / 追→追 / 両→両` の完全一致のみ。推測分類や空白補正は行わず原文を保存する。

| 年 | 逃 | 追 | 両 |
| --- | ---: | ---: | ---: |
| 2022 | 49,214 | 87,319 | 34,302 |
| 2023 | 52,652 | 91,631 | 34,724 |
| 2024 | 51,758 | 91,162 | 36,169 |
| 2025 | 49,830 | 90,206 | 37,084 |
| 合計 | 203,454 | 360,318 | 142,279 |

## 候補保存と再現

新規namespaceは `Statistics\TacticalInputReadiness`。plan/audit/build/reproduceを専用Commandへ追加し、旧モデルのcode identity glob外へ置いた。SQLiteの一時索引とJSONLストリームを使い、全Eloquent Collectionや全資料のメモリ読込みはしない。索引・失敗証拠も削除していない。
candidate各年は原値・正規化値・状態・出典・汎用時刻・時点未確認を保存する。ライン役割・専用観測時刻はNULL、予測利用は未承認。UNKNOWNと数値0を混同しない。
生成時seal、依存コード/原本START/END照合、公開直前のsnapshot検証を維持する。出力先は排他的新規作成のみ。途中改変時はFAILEDを保持しCOMPLETEを出さない。

| 実行 | 処理本体秒 | peak bytes | 終了コード |
| --- | ---: | ---: | ---: |
| audit | 34.559685 | 33,554,432 | 0 |
| build | 7.837097 | 31,457,280 | 0 |
| reproduce | 8.394428 | 31,457,280 | 0 |

PHPUnit本体・各CLI・独立メモリ子プロセスは `memory_limit=128M`、PHP `/usr/bin/php8.5` / 8.5.4。
reproduceは元C1/旧成果物を不可視にし、ネットワーク/DB socketを遮断した別PHPで保存snapshotとcandidateだけから実施。7意味ファイルのbytes/SHAとmanifestが一致。

- `snapshot/manifest.json`: `2332bebaa235385843e4d6d4a308c1a31090b7f151c3f18b7871d19e08964afd`
- `candidate/manifest.json` と `candidate-reproduce/manifest.json`: `f3b00f73843427da4b57d1827c877992423da77d2104cbe00c187fd552a82ab3`

保存コマンドは同親の `plan/`、`audit/`、`execution-build/`、`execution-reproduce/` のexecution.jsonとstdout/stderrにある。意味上のコマンドは:

```bash
php -d memory_limit=128M artisan keirin:c1:tactical-input-readiness plan
php -d memory_limit=128M artisan keirin:c1:tactical-input-readiness audit --authorize-read-only --output-dir="$RUN/snapshot"
php -d memory_limit=128M artisan keirin:c1:tactical-input-readiness build --snapshot="$RUN/snapshot" --output-dir="$RUN/candidate"
php -d memory_limit=128M artisan keirin:c1:tactical-input-readiness reproduce --snapshot="$RUN/snapshot" --original="$RUN/candidate" --output-dir="$RUN/candidate-reproduce"
```

`RUN`は上記実行親。実際には保存runnerによる既存bwrap隔離内で実施。auditだけ既存認証を読取り専用で使用し、他は実.env/DBを遮断。原本storage/cache/正式成果物は読取り専用または不可視、人工Desktop/Trashと今回の出力だけを書込み可能にした。

## 回帰検証と残件

最終専用 **27 tests / 167 assertions**、2.922秒、PHPUnit peak26.50MiB、exit0。正常3脚質/未知/NULL/空文字、不明ライン、明示・推定を示す異なる人工原文の保持（意味は未認定）、汎用取得時刻の発走前後に依存しないUNKNOWN、自己申告の専用時刻混入拒否、順不同、重複、別選手/年/2026/結果列拒否、固定seal参照、offline再現、既存出力拒否を確認。
100MiB超の資料を独立128M PHPで生成・実buildし30,000出走/exit0を確認。共有プロセスのpeak値で合否を決めない。生成途中に人工sourceを変更する別子プロセスでは、公開前のhash不一致・FAILED保持・COMPLETEなしを確認した。
再利用するREAD ONLY接続経路は人工SQLiteで書込み拒否とrollbackを確認。直接関連の安全helperは **27 tests / 119 assertions**、exit0。変更PHP7件構文・限定Pint成功、既存テスト削除/skip/緩和なし。

初回helperテストは隔離内/tmpとTrashが同じdeviceで1件失敗。実際の別deviceに作成・記録した人工/tmpをbindして同じ既存テストを再実行し成功した。退避拒否された人工ファイルは元の場所に保持、永久削除なし。初回SQLite人工接続のdriver設定不足と、buildログ先/生成先の名前重複による保護拒否も修正し、旧ログは保持した。実DB auditの再実行はしていない。
従来C1関連テストには永久削除tearDownがあるため今回は実行せず、利用する固定接続/Validator/Stream/Artifactsの回帰経路を専用人工テスト内で確認した。通常全体・旧Raw全量監査は未実施。

次のP2/P3比較に検討できるのは、意味・本人対応を確認した登録脚質3カテゴリの**未承認候補**だけ。歴史的発走前入力としての保証はない。ライン役割・STAT-18/29履歴は利用不可。次工程の時点取扱い・入力定義・比較開始は追加承認が必要で、自動的に学習や精度評価へ進まない。
