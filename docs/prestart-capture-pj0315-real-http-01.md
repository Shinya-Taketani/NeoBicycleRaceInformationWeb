# PRESTART-CAPTURE-PJ0315-REAL-HTTP-01

## 1. 範囲と結論

2026-10-28、最新ユーザー指示により、保存証拠で確認した2025年男子1レースだけを現在のKEIRIN.JPから実取得した。**実POST 1回、HTTP 200、保存Rawから既存Parserによるレース識別・9名の本人集合照合まで成功**。この1件では既存PJ0315の取得形式と解析構造が通用した。全レース・将来開催・定期取得の互換性保証ではない。

開始はclean main/HEAD/ローカルorigin/main `d96f55188e698c780933eed4bba5e4a7badd7c8a`。ローカルmerge履歴でPR124 MERGEDを確認し、未使用の `feature/prestart-capture-pj0315-real-http-01` を作成した。今回fetch/外部Git照会はしていない。PR124の文書だけという許可範囲は当時の履歴であり、今回だけ実HTTP技術検証を明示承認された。

```yaml
state: REAL_HTTP_PJ0315_VERIFIED_AWAITING_REVIEW
permission_status: UNVERIFIED
operational_decisions: PROPOSED_NOT_APPROVED
production_storage: NOT_RUN_NOT_AUTHORIZED
historical_as_of_available: false
prediction_use: NOT_AUTHORIZED
formal_adoption: false
official_publication_at: null
points: null
confidence: null
2026_holdout: FROZEN_NO_ACCESS
```

問い合わせ回答待ちを実装停止条件としなかったが、利用規約上の自動取得・継続保存・共有の許諾確認済みとは扱わない。運用契約D1〜D8、時計誤差/TTL、backup/保持責任、正式な発走前観測は引き続き未成立。B1未承認、method/selected_lambda NONE、正式B1受入NOT_RUN、H1-U保留を維持する。

## 2. 実対象と保存証拠

| 項目 | 確認した値 |
| --- | --- |
| 対象 | 2025-01-04、場コード28、1R、男子9車、race_id=12901 |
| 既存取得ログ識別子 | 40546（保存fixtureの記録。DBには接続していない） |
| 対象証拠 | `/home/shinya/neo-keirin-artifacts/stat46-roster-snapshot-01/pr120-nine-20261024-073534-oHV4inO8/fixtures/nine-fixture.json` |
| 証拠SHA-256 | `039c77ad09f052144ba07287b0bdaee039b7c75aaebe7887ce327f9f87e47b1f` |
| 保存baseline | 同ディレクトリ `nine-raw-40546.html`、94,103 bytes |
| baseline SHA-256 | `2041ed47a61c47de039cfc23f81f81240022e734d2cf5154fc76a2a532b710b7` |

単一caseのseal、保存済みrequest/logのPOST/PJ0315/encp一致、レース日・場・番号、原Rawのサイズ/SHA、男子カテゴリー、9名の車番/登録番号集合を送信前に検証した。encpは保存された実値だけを使用し、推測生成なし。生値はprivate-request.jsonだけに保持し、本書・共有manifest・stdoutへ出さない。2026開催の資料や一覧を対象にせず、既存年次資料・DBを探索/再監査していない。

## 3. 実装と取得契約

新規名前空間 `Scraping/PrestartCapture/Pj0315Probe/` の5クラスとCommandだけを追加した。

| クラス | 責務 |
| --- | --- |
| `Target` | 単一2025年の保存証拠/SHA・要求・本人・baselineを検証 |
| `SinglePostTransport` | Laravel HTTP Clientによる1回限りのPOST、転送情報/失敗分類 |
| `Transfer` | 転送metadataとResponseの保持。Rawのfsync完了までsinkの寿命を維持 |
| `Inspector` | 保存後のbytesを既存文字コードConverter/Parser/カテゴリーPolicyで照合 |
| `Probe` | 排他的attempt/private要求/原Raw/結果/manifest/終端マーカー保存、改変検出 |
| `ProbePj0315RealHttpCommand` | `keirin:prestart:probe-pj0315`、デフォルトは送信/出力作成なし |

既存 `RaceLiveFetcher::fetchDetail()` は `KeirinHttpClient::postForm()` で `/pc/racelive` に `disp=PJ0315` とencpを送る。一方、旧クライアントには自動retry、旧ScrapingFetchService/RawResponseStorageServiceにはDB保存があるので今回は呼ばない。新経路は `asForm()` のHTTPS POST `https://keirin.jp/pc/racelive` に固定し、retry APIを使用しない（PendingRequestの単一試行）、redirect追従false、接続5秒/全体20秒、同時1。TLS検証を無効化していない。Scheduler/Queue/通常取得への登録なし。

UAは `NeoBicycleRaceInformationWeb/PJ0315-technical-probe-01`、Refererは既存経路と同じ `https://keirin.jp/pc/racelist`。Accept-Encodingはidentity、decode_content=false。4MiBを超える応答は中断して部分証拠を保持し、完全Rawとして公開しない。

既存の `EvidenceFiles` のパス/owner/0700・symlink/mount検査と、旧 `Contract::writeNew()` の排他的0600保存だけを再利用する。人工trust/人工時刻を実証根拠へ転用していない。attemptを先に排他作成し、同じ出力rootへの再送を拒否。原bytesの新規sinkを送信前に確保し、受信/flush/fsync/保存完了の後だけ解析する。既存path衝突・保存不良・Raw変更ではFAILEDを保持し、削除/上書き/再試行しない。別の新規rootからの再送を包括許可するものではない。

実効URIはtransferの観測値を用い、不明時に要求URIで補完しない。200でも空本文/文字コード/構造/本人不一致は解析失敗とし、HTTP受信成功と区別する。失敗例外の本文（要求値を含む可能性あり）は公開ログへ出さず、分類と例外クラスを保存する。

旧Bridge v1の24ファイルcode seal/Operational/Fetcher/Parser/保存器/STAT/C1/C2/BT/モデル・設定・Migrationは変更しない。新仕様は `PJ0315-REAL-HTTP-PROBE-v1`。新規5クラス・Command・composer.lockと旧依存sealを実行時に記録し、START/END一致を確認した。

## 4. 実通信の測定結果

実行root（private0700、各ファイル0600）:

`/home/shinya/neo-keirin-artifacts/prestart-capture-pj0315-real-http-01/execute-20261028-060903-9GbWsyyO/`

| 項目 | 実測 |
| --- | --- |
| 要求開始UTC | 2026-10-27T21:09:03.395109Z |
| 受信完了UTC | 2026-10-27T21:09:03.733211Z |
| 原Raw保存完了UTC | 2026-10-27T21:09:03.735250Z |
| JST | 2026-10-28 06:09:03、開始→受信約0.338102秒 |
| 実POST / retry / redirect追従 | 1 / 0 / 0 |
| HTTP / 実効URI | 200 / `https://keirin.jp/pc/racelive` |
| Content-Type / 検出文字コード | `text/html;charset=UTF-8` / UTF-8 |
| 構造 | HTML内PC0201・PJ0315埋込みJSON |
| 原Raw | `private/raw.bin`、93,721 bytes |
| 原Raw SHA-256 | `e46900fe80a06c67e989d666d7a6eefcfac8c08508542df9ea1a3a48c71f7e36` |
| manifest SHA-256 | `9819156e418680565563b7ad05ddec2d1f9f17662411d79bb10aa1eb689d158a` |
| Parser / 本人 | PARSED_IDENTITY_VERIFIED、日/場/番号一致、車番/登録番号9名一致 |
| 終端 / exit / stderr | COMPLETE / 0 / 空 |
| PHP memory_limit / peak | 128M / 33,554,432 bytes |

今回の現在取得Rawは旧baselineとbytes/SHAが異なる。旧原本は保持し、当時の値と同じ・訂正があった等の原因推定や全field差分監査はしていない。

### Parserで確認できた項目

- race9項目: raceDate、trackCode、raceNumber、raceType、distance、laps、raceName、startTime、salesCloseTime。
- entry21項目: bikeNumber、frameNumber、externalPlayerId、playerName、prefecture、previousGrade、grade、ridingStyle、graduationPeriod、age、raceScore、escapeCount、sprintCount、overtakeCount、markCount、backCount、homeCount、startCount、winRate、quinellaRate、trioRate。
- 上記DTOのNULL欠損はrace0項目・9名のentry0項目。NULLでないことは各値の公式意味/公表時点の独立証明ではない。実値と本人集合はprivate result.jsonだけに保存し、共有記録は項目名/件数/集合SHAとする。
- 公式初回公開時刻・当時の取得可能性・許諾・ホスト時計の厳密誤差上限は不明。ライン/初手役割は既存DTOの出力対象外で、今回未解析。S等の意味確認や新しいSTAT計算はしていない。

## 5. 保存後の独立確認

通信なしの別PHPプロセスで、保存済み5ファイルのseal、manifest/COMPLETE、新コードseal、private要求の元パラメータ/本人対応、実効URI/HTTP200/単一試行、時刻順、Rawの再解析一致を検証した。

`/home/shinya/neo-keirin-artifacts/prestart-capture-pj0315-real-http-01/verify-20261028-060947-IbSDes8O/report.json`

結果 `SAVED_EVIDENCE_REVERIFIED_NO_NETWORK`、exit0、128M/peak10,485,760 bytes。追加HTTPは0。元証拠JSON/baselineのSTART/END SHA一致、新旧コードseal一致。既存モデル・予測成果物は参照/変更していない。

## 6. テストと実行方法

人工Fixtureはsyntheticと明示し、実HTTP受入とは分離した。HTTP fakeで200、301/302/307/308、403、429、500/503、timeout、接続失敗、空本文、HTML変更、本人不一致、不正UTF-8、保存失敗、Raw改変、元Raw SHA不一致、実効URI不明/不一致、retryなし、同root再実行/既存Raw上書き拒否、人工2026/JS送信前拒否を確認。Commandもデフォルト無送信・sealed人工targetによる明示execute・失敗時exitを検証した。

| 最終検証 | tests / assertions | failed / errors / warnings / skipped | exit | PHP peak bytes |
| --- | --- | --- | --- | --- |
| 新規Unit23＋Command3 | 26 / 225 | 0 / 0 / 0 / 0 | 0 | 52,953,088 |
| 関連Bridge/Approval/Command/STAT46/Parser/旧HTTP fake | 285 / 1949 | 0 / 0 / 0 / 0 | 0 | 59,244,544 |

実PHPは `/usr/bin/php` → `/usr/bin/php8.5`、8.5.4 CLI。AGENTSのPHP8.4表記との環境差は記録し、8.4で成功したとは主張しない。Artisan/Collision経路、PHPUnit本体にも128Mを適用しshutdown測定した。原本ro-bind、ネットワークnamespace分離、人工.env/storage/bootstrap cache・SQLite設定（DB未接続）、人工Desktop/Trashを既存bwrapで隔離した。退避は既存helperが作成時に保持した専用pathだけ、rename-only、実Desktop/既存成果物への操作なし。

最終新規ログ: `test-20261028-060855-Ifj1SR7n/logs/`、関連ログ: `test-20261028-060814-UHsQ0g5H/logs/`（いずれも上記専用root直下）。stdout/stderr/JUnit/events/exit/memory/旧seal比較を保持。最初の人工URIテスト失敗1/成功21のログ、隔離DNS mount設定不備による未送信plan失敗も残した。既存テスト削除/緩和/skip・警告抑制なし。通常全体・過去監査/全Raw再生は未実行、過去警告全解消も未確認。

Artisanのオプション形（**今回は実送信済み。再実行指示ではない**）:

```bash
php -d memory_limit=128M artisan keirin:prestart:probe-pj0315
# --execute --evidence=<単一保存証拠の絶対パス> --evidence-sha=<外部期待SHA> --output=<新規private専用root>
```

実通信は上記runの `logs/runner.sh`/`entry.php` を使用し、同じArtisan Kernel/Commandへ明示 `--execute` を渡した。entryは実.env/storageを使わず、DB/Redis/旧HTTP・DB保存サービスを例外ガードにする。通信時だけnetwork namespace分離を外し、原本read-only・home/run等の秘匿、単一source親read-onlyを維持した。要求コマンド/start/end/exit/メモリは同logsに保存。計画確認と独立検証ではネットワーク遮断を維持し、POST1回以外の外部取得はしていない。

対象テストの実行形（同rootの隔離runnerに正確なコマンドを保持）:

```bash
php -d memory_limit=128M artisan test tests/Unit/Domain/Keirin/Scraping/Pj0315RealHttpProbeTest.php tests/Feature/Pj0315RealHttpProbeCommandTest.php -d memory_limit=128M --bootstrap tests/Support/stat38-memory-bootstrap.php --display-all-issues --do-not-cache-result
```

## 7. 残件と終了

現サイトと旧POST/Parserの形式差は今回1件では検出しなかった。ただし定期運用の単一試行台帳・実時計誤差/TTL・許諾/保持backup・将来の具体対象と予算承認・出走者変更時の受入は未実装/未成立。今回の専用技術チェックを運用パイロットの全受入や正式な発走前証拠に置き換えない。

次に実際の発走前観測をするには、D1〜D8の実対象/slot/許諾/時計/保存責任等の個別根拠と、対象工程のテスト・コード受入・具体的実行承認が必要。今回新しい観測を予約/自動継続しない。JS別承認・2026凍結・予測禁止も維持する。

DB/Redis/実Raw原本の変更、Migration、学習、予測、性能評価、repackage、2026開催参照は0。`git diff --check`と変更範囲/新規PHP構文・限定Pintを確認し、feature branchに未コミットでレビュー待ち。commit/push/PR/mergeなし。

## 8. PR125 P2/P3レビュー修正 / 2026-10-28

開始はcleanな既存 `feature/prestart-capture-pj0315-real-http-01`、HEADとローカルorigin branchは `aca1d150df6718e0daf7caa690e2b6f2aa25da1f`、origin/mainはd96f551。新branch/外部Git照会/PR操作なし。上記1〜7節の実HTTP・件数・hash・テスト・未コミット終了は初版時点の履歴であり、その後PR125で保存された。**今回の追加実HTTPは0回**。

### P2: 固定技術scopeの単一試行

旧v1のインスタンス内usedと出力root内attemptだけでは、別インスタンス/別rootで再送できた。v2は専用 `ScopeAttempt` を追加し、Commandのscope保存先を次に固定する。CLIからscope/root/承認識別子を変更するオプションはない。テストでのみDIにより人工scopeを渡す。

`/home/shinya/neo-keirin-artifacts/prestart-capture-pj0315-real-http-01/technical-scope-attempt-01/registry`

scopeは `PRESTART-CAPTURE-PJ0315-REAL-HTTP-01`、検証IDは `SINGLE_2025_PJ0315_ENDPOINT_CHECK_01`、上限1。対象と保存証拠・要求bindingのSHAを予約へ記録する。scopeには一つのslotしかなく、証拠/対象を変えても同じ検証の予算を増やさない。既存scopeと出力rootの重複/親子関係も拒否する。新しい運用全体のAttemptLedgerや承認trust storeではない。

既存0700専用scopeディレクトリのinodeに対するnon-blocking flockで排他し、reservation.jsonを排他作成/flush/fsyncしてから出力作成・送信へ進む。全予約を保守的に予算消費済みとし、dispatch.json、outcome.jsonは別の追記専用記録。例外/timeout/中断で予算を解放せず、ファイル削除・上書き・再試行・別rootへの切替なし。プロセス再起動後も同じ記録を読む。scope未初期化/不正/不明をAVAILABLEと推定しない。

| scope state | 意味 | 追加送信 |
| --- | --- | --- |
| AVAILABLE | 正しい初期化資料だけがあり予約がない人工テストscope | 上限1の予約に成功した呼出しだけ |
| RESERVED | 予約済み、送信開始を確認できない | 拒否。未送信と決めて予算を戻さない |
| INTERRUPTED_OR_UNKNOWN | dispatch記録あり、終端不明。進行中/中断を未送信と推定しない | 拒否 |
| HTTP_SUCCEEDED | 通信HTTP200。Parser/保存/予測利用の承認とは別 | 拒否 |
| HTTP_FAILED | 429等の非200応答 | 拒否 |
| TIMEOUT / TRANSPORT_FAILED | timeout / 接続等の通信失敗 | 拒否 |
| UNKNOWN | 未初期化・破損・孤立記録・矛盾・読取り不能等 | 拒否、予算消費済みとして扱う |

実scopeは初期化済みのAVAILABLEに戻していない。旧v1実証拠の9ファイル（attempt/private要求応答/result/Raw/manifest/COMPLETE、実行時code START/END）を読取り専用で照合し、旧probeコードは記録済みsealと開始コミットaca1d15の内容、旧24依存は従来sealと一致。保存Rawも再解析一致。旧1回を `IMPORTED_VERIFIED_V1_NO_HTTP` と明示した新しい参照記録として上記scopeへ登録し、HTTP_SUCCEEDED/budget_consumed=true・追加予約拒否を確認した。これは再通信・旧束の再sealではない。

確認ログ: `/home/shinya/neo-keirin-artifacts/prestart-capture-pj0315-real-http-01/pr125-legacy-verification-20261028-064409-RBxG2iBR/logs/report.json`。exit0、128M/peak10,485,760bytes。旧Raw SHA e46900fe80a06c67e989d666d7a6eefcfac8c08508542df9ea1a3a48c71f7e36、manifest SHA9819156e418680565563b7ad05ddec2d1f9f17662411d79bb10aa1eb689d158aは保持。旧v1の有効性を現在v2のcode sealとの不一致だけで失効としない。保存版と当時のコードを基準に確認する。検証時点のv2 sealと最終修正コードのsealはそれぞれの新規記録へ分離する。

### P3: 例外時の保持表示

旧Commandの無条件 `evidence_retained=true` を廃止。`ProbeFailure` に保持状態の確認事実を載せる。Commandは入力検証前と処理開始後も区別し、未知の処理例外では未生成と断定しない。

| evidence_status | evidence_retained | output_write_started | failed_evidence_confirmed |
| --- | --- | --- | --- |
| NOT_CREATED | false | false | false |
| WRITE_STARTED_RETENTION_UNCONFIRMED | null | true | false |
| FAILED_EVIDENCE_CONFIRMED | true | true | true |
| RETENTION_UNKNOWN | null | null | false |

`evidence_scope=CURRENT_INVOCATION_OUTPUT_ONLY`。NOT_CREATEDは**今回の呼出しが新しい出力証拠を生成していない**という意味であり、以前のroot/Raw/成功束が失われたという意味ではない。FAILED資料は今回のattempt_idを含むマーカーを安全なReaderで読取り確認できた時だけtrue。保存失敗・読取り不能を隠すためのtrue/false補完はしない。生の例外本文/encpは出力しない。

### 今回の限定回帰

| 対象 | tests / assertions | failures / errors / warnings / skipped | exit | PHP peak bytes |
| --- | --- | --- | --- | --- |
| Probe Unit23＋scope Unit10＋Command8 | 41 / 326 | 0 / 0 / 0 / 0 | 0 | 55,050,240 |
| 既存Bridge/Approval/Command/STAT46/Parser/旧HTTP fake | 285 / 1949 | 0 / 0 / 0 / 0 | 0 | 59,244,544 |

人工scopeで最初の1回だけ成立、同root/別root/別Transport/新Command/新PHPプロセスの再送拒否、同時2プロセスの送信合計1、429/timeout後の拒否、予約残留/送信中断/破損/未初期化の拒否を確認。SHA不一致・output未指定は未生成、書込み開始後の失敗はFAILED読取り確認の有無でtrue/nullを区別した。人工2026/JS入力は外部期待SHAを再計算した場合も予約前に拒否する。

PHPUnit本体と子プロセスは128M、PHP8.5.4、原本read-only・ネットワーク遮断・人工.env/storage/Desktop/Trash・既存安全helperを維持。子プロセスのHTTPはすべてfakeで、人工制御記録は各テスト自身の記録済みrootだけに保存/退避。既存テスト削除/緩和/skip追加なし。初回にテスト出力を二度消費して失敗した1/成功25の開発ログも保持し、期待値を緩和せず修正した。

最終ログは専用root直下 `test-20261028-064739-fxXYS0qp/logs/` と `test-20261028-064743-P82JCld4/logs/`。実行形は第6節の隔離runnerにscopeテスト `tests/Unit/Domain/Keirin/Scraping/Pj0315ScopeAttemptTest.php` を追加したもの。stdout/stderr/JUnit/events/exit/128M peak・旧24依存START/END一致を保存。変更9PHPの構文・限定Pint・diff checkを確認した。通常全体/旧監査/実HTTP/DB/2026開催/学習/予測/評価は再実行しない。

新コードは `PJ0315-REAL-HTTP-PROBE-v2`、旧実通信はv1のまま。旧attempt/Raw/manifest/COMPLETE/code sealは上書き・再封印・削除なし。今回の最終状態は `PR125_SCOPE_AND_RETENTION_FIX_AWAITING_REVIEW`。D1〜D8未承認、利用許諾UNVERIFIED、as-of=false、予測/正式採用禁止、2026凍結、B1/親lambda/H1-U保留を維持する。実HTTPを再実行せず、既存branchの未コミット差分で停止し、commit/push/PR/mergeなし。
