# PRESTART-CAPTURE-OFFLINE-BRIDGE-01

## 1. 範囲と状態

2026-10-24、ユーザーの限定製造承認によるオフライン接続基盤。PR120 MERGEDはユーザー指示と、fetch/main pull --ff-only後のclean main/origin `77347e4507449f52878872dfa2cafe874b04c95f`・merge件名で確認した。未使用の `feature/prestart-capture-offline-bridge-01` を作成。終了時のHEADも同じで、今回のPHP・テスト・文書差分は未コミット。

状態は `OFFLINE_BRIDGE_VERIFIED_AWAITING_REVIEW`。実HTTP取得・本番保存・予測入力受入の完了ではない。B1監査BLOCKED/方法未承認/親lambda未選択/H1-U保留、旧不採用判断、2026開催holdout凍結は維持する。

```yaml
version: PRESTART-CAPTURE-OFFLINE-BRIDGE-v1
historical_as_of_available: false
prediction_use: NOT_AUTHORIZED
formal_adoption: false
official_publication_at: null
points: null
confidence: null
```

## 2. PHP実装と再利用

新規ディレクトリは `app/Domain/Keirin/Scraping/PrestartCapture/`。旧 `PrestartEvidence` のSYNTHETIC形式へ実Rawを投入せず、新版・別rootへ保存する。

| ファイル | 責務 |
| --- | --- |
| Contract.php | 版・固定制限・対象/日時検査、直接依存code seal、新規排他書込み |
| CaptureRequest.php | ID・固定対象/manifest seal・PJ0315 POST route/binding・プログラム版。Transport呼出し前の検証 |
| CaptureResponse.php | 関連ID・開始/受信・HTTP/redirect/effective URI・原body・失敗/時刻出典 |
| OfflineTransport.php / FixtureTransport.php | 依存注入するオフライン境界と固定応答実装。HTTPクライアントなし |
| OfflineBridge.php | reserve → exchangeを1回 → finish。自動追従/retryなし |
| CaptureStore.php / StoredCapture.php | capture/attempt排他予約、不変Rawと証拠、公開前検査、COMPLETE/FAILEDと返却DTO |
| Projection.php | 取得元/整合性/本人/カテゴリー/時点の別軸投影 |
| CaptureVerifier.php | 外部manifest SHAを指定し、保存資料だけで再解析・再分類・marker照合 |

既存 `EvidenceFiles` のpath/owner/mode/link/mount検証・読取り・JSON/seal、`SourceValidator` の成分/POSTパラメータ照合、`CharacterEncodingConverter`、`RawObservationDecoder`、`RaceCategoryPolicy`/Enum、`RaceDetailParser`/DTO、`RaceRosterSnapshotDto::validateEntries()`を再利用する。現在DBの出走者や後日の予定時刻を取得する依存は持たない。STAT46 ReaderのDB結合を偽装せず、同じ既存Parserと出走者検査を使い、保存済みSTAT46受入資料へ照合する。

旧 `EvidenceFiles::writeNew()` の存在前 `@lstat` はPHPUnitの警告イベントになるため、旧API/code sealは変更せず、新名前空間内の小さい排他writerを使用。`fopen(..., 'xb')`、0600、write/flush/fsyncとディレクトリfsyncを確認し、衝突/失敗時は保持して停止する。既存 `Storage::put()` の上書き経路は使わない。

Artisan Command、ServiceProvider binding、実HTTP Transport、Scheduler/Queue登録は追加していない。呼出し経路は `new OfflineBridge($offlineTransport, new CaptureStore($dedicatedRoot))` の `capture($request)`。検証は `new CaptureVerifier` の `verify($captureDirectory, $externalManifestSha256)`。旧Raw保存・HTTPクライアント・Parser・STAT/C1/C2/BT/モデル/Migrationは変更しない。

## 3. 保存・識別契約

呼出し側が作成した既存の専用0700rootを使う。capture/attempt IDは各32桁hex。対象は2022〜2025のrace ID・日・場・番号・全車番/登録番号の固定manifest（外部SHA必須）だけ。2026対象、未許可route/parameter、対象不一致はTransportや予約より前に拒否する。

root直下に `attempt-{id}.json` を排他予約し、capture IDの子へ以下を保存する。同じbodyの再取得も別capture/attemptで保持し、同じIDは再利用・上書きしない。

| ファイル | 内容 |
| --- | --- |
| request.json / allowed-targets.json | 固定対象、許可route/digest、manifest参照/seal、用途・版 |
| response.json / execution.json | 応答識別・HTTP状態・時刻出典・宣言Raw seal、今回処理時刻 |
| private-request.json / private-response.json | 完全URI・encp等の照合用private情報。共有/Gitへ出さない |
| raw.bin | bodyが実在する場合だけ、未変換原bytes。接続失敗を架空Rawにしない |
| validation.json | source/Raw/category/roster/timing/reasonsと固定禁止値 |
| manifest.json | 全上記ファイルのbytes/SHA・直接依存code seal・capture ID・制限 |
| COMPLETE.json / FAILED.json | manifestと整合する排他的終端marker |

全ファイルは0600。保存失敗/予期しないTransport例外もFAILEDと実在証拠を保持し、自動削除・修復・再試行しない。保存途中のFAILEDは完全bundleではなく、正常Verifier受入対象ではない。全材料が揃った拒否応答はFAILED bundleとして独立再検証できる。対象外カテゴリーはCOMPLETE＋`SKIPPED_UNSUPPORTED_CATEGORY`、男子出走表はNULLで、正常男子保存/予測適格を意味しない。両marker併存、余分/欠落ファイル、改変・虚偽の再seal済みvalidation、code driftを拒否する。

Raw概要のPC0201から既存Policyでカテゴリーを先に検査する。MENだけ詳細Parserへ渡し、GIRLS/L級/UNKNOWNは明示skip、Rawと既知の呼出し元固定DB分類が対立すればFAILED。不明RawをDB値で男子へ補完しない。人数では判定しない。

日・場・番号、PC0201概要とPJ0315詳細、固定targetの全車番/登録番号集合を照合する。非連番5車と登録番号先頭0を保持し、現在 `race_entries` が変わっていても照会/補完しない。元Raw自体は一切正規化しない。

## 4. 時刻と取得元の意味

| 項目 | 意味と今回の扱い |
| --- | --- |
| request_started_at | 模擬Transportの明示時刻。旧取得ログに証拠がない歴史再生ではNULL必須 |
| response_received_at | 模擬時刻、又は固定logのfetched_at。出典を別fieldで明示 |
| raw_saved_at | 今回の新しいコピーのwrite/flush/fsync後の実UTC時刻。CURRENT_OFFLINE_COPY_AFTER_FSYNC |
| historical_raw_saved_at | 旧Rawの保存完了は未記録、NULL。mtime/log.created_atで代用しない |
| observed_scheduled_start_at | 同RawのaftStartTime。既存Asia/Tokyo解釈と明記し、公式TZ根拠の承認とは別 |
| prediction_input_as_of | 要求側が固定したoffset付き時刻。今回コピーとの比較も別軸 |
| official_publication_at / actual_start_at | 証拠なし、NULL |

開始と受信が既知ならstrict orderを検査し、等時刻/逆転はFAILED。未知はUNKNOWNを保持する。今回処理の開始≤Raw保存≤終了は別に検査する。模擬Transportの時計と今回の実システム時計は別出典であり、模擬時刻を本物の取得/保存証明へ転用しない。時計信頼性UNKNOWN、誤差NULL、予測入力の利用可能性NOT_ESTABLISHEDを維持する。

同Raw予定時刻との比較はstrict `<`。`SIMULATED_BEFORE_SAME_RAW_START` / `SIMULATED_AT_OR_AFTER_SAME_RAW_START` / `RECORDED_BEFORE_SAME_RAW_START` / `RECORDED_AT_OR_AFTER_SAME_RAW_START` / `UNKNOWN`を使う。等値を発走前へ含めず、いずれも実発走前・公式公開時刻・予測利用の証明ではない。

取得元のVERIFIEDは `OFFLINE_REQUEST_RESPONSE_BINDING_ONLY`。許可routeは今回の模擬比較用で、実送信の許可ではない。3xx・redirectあり/不明・URI不一致/不明は拒否する。旧ログに元HTTPのeffective URI/redirect証拠はないため、歴史再生のoffline応答URIを元通信の証明にせず、`original_http_effective_uri_evidence=UNKNOWN` / `original_http_redirect_evidence=UNKNOWN`を別に残す。

## 5. 保存実Rawの検証

実RawはPR120で受入済みの固定fixtureだけをread-onlyで再利用。元資料のSHA・サイズと各対象日/場/番号/全本人集合を再確認した。

| 対象 | 今回の実測 |
| --- | --- |
| 既存2022〜2025 PJ0315 | 6レース、8取得版、39固有race/選手、延べ51観測 |
| PR120の別枠9車 | 2025-01-04、race12901、fetch40546、1版・9出走 |
| 独立Verifier | 合計9保存束、各1回の別PHPプロセス、保存資料のみ、全exit0 |
| 固定STAT46出走表 | 原8版の7項目が一致、Raw SHA・取得時刻も一致 |
| 固定STAT46差分 | race12542/63439の2組、status/difference完全一致。本人/車番差分0、枠不足NOT_ESTABLISHED維持 |
| 時点 | 9版とも記録受信は同Raw予定時刻以後。発走前実取得の成功ではない |

固定元rootは `/home/shinya/neo-keirin-artifacts/stat38-distance-laps-mvp-01-20261021-01/`。`actual-fixture.json` SHAは `7ec6fae7e6ff5f8137754986c8f9e8d10bd766b9c65f61aa1252944494ba04dc`、`history-fixture.json` SHAは `0344d20e9db4918a6a1a8cc0f691cdea724c22fc1b90c9ce2520ce12a02379ca`。

別枠9車は `/home/shinya/neo-keirin-artifacts/stat46-roster-snapshot-01/pr120-nine-20261024-073534-oHV4inO8/fixtures/nine-fixture.json`、SHA `039c77ad09f052144ba07287b0bdaee039b7c75aaebe7887ce327f9f87e47b1f`。固定STAT46照合先は同rootの `pr120-tests-20261024-073619-8Nc4kMdB/logs/roster-real-raw-acceptance.json`、SHA `f3091e255c89924aaf47bae7d523d6a09973c6b0b564adb3f1d88ae8e7cfb6d4`。

今回保存先は `/home/shinya/neo-keirin-artifacts/prestart-capture-offline-bridge-01/`。最終実Raw再生・独立検証は `tests-20261024-091452-og1LlBad/logs/replay-evidence/`。`real-replay-verification.json` SHAは `9ee61ce9d56bc76050e91fb050e81113edc4fd68e3e7990460a70219e1526831`。各captureのmanifest SHAは同報告に収録する。privateファイル/Rawは共有対象に含めない。

旧Raw・fixture・9車資料・STAT46受入資料のSTART/END SHA比較は各実行exit0。旧PrestartEvidence/Attributesのcode sealも新規保存前後で一致し、Git既存コードも不変。旧bundleの全媒体走査はしていない。

## 6. テスト・コマンド・証跡

PHP `/usr/bin/php` 8.5.4、PHPUnit12.5.31。Artisan/Collision経路で各PHPプロセスに128Mを指定し、既存 `stat38-memory-bootstrap.php` の起動時検査・終了時peakを使用。独立Verifier9プロセスもそれぞれ `php -d memory_limit=128M -r ...` を指定する。

新規テストは `tests/Unit/Domain/Keirin/Scraping/PrestartCaptureOfflineTest.php` と `tests/Feature/PrestartCaptureReplayTest.php`。Unitの全レース・時刻・失敗はSYNTHETICと明記し、実Raw正常系・歴史時刻捏造拒否と分離する。

| 最終検証 | ケース | assertions | failures/errors | warning対象ケース | skipped | exit | PHP peak bytes |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 新規Unit46＋実再生Feature2 | 48 | 553 | 0 | 0 | 0 | 0 | 29,884,416 |
| PR120・STAT17/19/30/38 | 150 | 6,047 | 0 | 0 | 0 | 0 | 67,633,152 |
| 旧PrestartEvidence/STAT39属性 | 252 | 1,560 | 0 | 155 | 0 | 0 | 38,273,024 |
| 保存束だけの別Verifier | 9プロセス | 上記Feature内 | 0 | 0 | 0 | 全0 | 最大10,485,760 |

旧252ケースは97 clean passed＋155 warning対象、全assertionは成功。今回のeventsで2,125件のPHP警告を確認し、全て未変更の `PrestartEvidence/EvidenceFiles.php:149` の存在前 `@lstat`。警告抑制/期待値緩和/新skip/旧API変更はしない。過去ユーザー全体3907passed/155warnings/9skippedの全本文が同じ原因だったとは断定しない。

初回人工43ケースはJSON改変時の例外型2失敗/40warning対象だった。新VerifierのJSONエラーを明示的なRuntimeExceptionとし、新専用writerの存在確認を修正後に43/249/警告0を確認。実Raw再生の初回はログ先マウント点を既存安全APIが拒否したため、安全ガードを変えず専用子ディレクトリを作って対応した。追加改変/code drift試験と限定Pint後の最終結果が上表。失敗ログも保持する。

新規テストの最終コマンド（隔離内）は次の通り。

```bash
/usr/bin/php -d memory_limit=128M artisan test \
  tests/Unit/Domain/Keirin/Scraping/PrestartCaptureOfflineTest.php \
  tests/Feature/PrestartCaptureReplayTest.php \
  -d memory_limit=128M --bootstrap tests/Support/stat38-memory-bootstrap.php \
  --display-all-issues --do-not-cache-result \
  --log-junit /tmp/evidence/junit.xml --log-events-text /tmp/evidence/events.log
```

各実行rootの `logs/runner.sh` / `command.txt` / `php-version.txt` / stdout・stderr / events / JUnit / exit・開始終了日時 / measurement / source-start/end.sha256を保持。新規は `tests-20261024-091452-og1LlBad`、STAT回帰は `tests-20261024-091356-Kf2uZyjz`、旧証拠回帰は `tests-20261024-091404-LuanaiqP`。全て上記新root直下。新規logsの `verification-summary.json` に全12PHPの構文結果・code seal・各集計を保存する。

`bwrap --unshare-net`、原本read-only、homeを隠して固定development資料だけ再mount、人工コメント.env、storage/cache隔離、SQLite in-memory、人工Desktop/Trashを使用した。人工領域のみ既存helperへ作成時保持 `path()` を渡し、同filesystem rename-only退避。永久削除/実Desktop操作なし。DBサーバー・HTTP・2026開催資料を必要としない実行環境で、対象年はfixture検査・要求DTOの双方で拒否/限定する。実データを使う正常系を模擬Rawで代用していない。

全12PHPの `php -l`、新namespaceと新テスト2ファイルだけのPint、`git diff --check`を確認する。通常全体テスト・旧全件build/audit/reproduce・DB・HTTP・モデル学習/予測/性能評価は未実施。

## 7. 未実装・次工程の未決条件

- 実HTTP Transport、既存Fetcher/Raw/logへの運用adapter、Command、Scheduler、DB保存は未実装・未承認。今回はオフライン証拠フローの完成だけ。
- JSJ017はday一括の1 Rawに複数レースを対応させ、GET request binding/レース別時刻・本人集合を保持する拡張が必要。現RequestはPJ0315の1capture1race固定で不足するため、偽装して受け付けず後続課題とする。
- 既存契約D1（対象/上限）、D2（先行時間/間隔/retry上限）、D3（利用条件/許可URI/負荷/429）、D4（二源優先/矛盾）、D5（延期/中止/欠場/再取得）、D6（時計/TZ/誤差）、D7（運用保存/容量/保持/回復）、D8（最終適格性/版選択）は未決のまま。本工程の模擬routeや専用root受入を、実運用承認へ拡張しない。
- 公表/実発走/旧保存完了/時計根拠はUNKNOWN/NULL。今回の事後再生からhistorical_as_ofや予測入力を承認しない。予測精度への影響は `NOT_MEASURED_THIS_CHANGE`。
- 次の許可作業は今回未コミット差分のレビューのみ。自動で実取得・正式保存・モデル接続へ進まない。
