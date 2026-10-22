# STAT19-30-OFFICIAL-METHOD-HISTORY-MVP-01

> 2026-10-23 PR119レビュー修正の現在地は第7節。以下のv1・22/1321・172/3877・未コミット記載は初版作業終了時の履歴。ユーザーがcommit/push/PR作成後、cleanな同一ブランチで修正を再開した。

## 1. 範囲と現在地

2026-10-22。PR118はGitHubでMERGED（merge `73cb5717d149fc9e9d16b119cbdb1aa4d68f1b09`、merged_at `2026-10-22T03:39:36Z`）。fetch/main pull --ff-only後のclean main/originが同SHAであることを確認し、`feature/stat19-30-official-method-history-mvp-01` を作成した。

状態は `CODE_AND_LIMITED_REAL_RAW_VERIFIED_AWAITING_REVIEW`。公式1・2着決まり手の事後記述統計だけを製造した。積極性・実際の仕掛け・B/H・役割・戦法転換・予測効果の完成ではない。

## 2. 実装と保存

- `OfficialMethodHistory/Contract`: 逃げ/捲り/差し/マークをESCAPE/MAKURI/SASHI/MARKへ対応。FINISHED/TIEDの公式1・2着だけ。マークは2着。3着以下の無記載は欠損としない。着順/状態/決まり手の矛盾は拒否する。
- `OfficialMethodHistory/Reader`: 既存RawReader・RaceLiveResultParser・EmbeddedJsonExtractorを再利用。race/date/場/番号、車番/登録番号、着順/状態/決まり手、import/log/path/サイズ/SHA/変換後SHA/取得元・Parser版を検査する。非NULLの結果entry/player IDは一致必須、NULLは補完せず、Raw本人照合先と元のNULLを別々に記録する。
- `OfficialMethodHistoryRepository` と入力/プロフィールDTO: 男子2022〜2025年だけをSQL限定し、登録番号・非NULL内部ID、対象開催を検査する。履歴はlazy(100)、日付/予定時刻/race ID/entry IDの降順。重複・順序矛盾は拒否する。
- `WindowBuilder`: 共通窓集計。全履歴hashと先頭20候補の参照だけを保持する。未確認行を飛ばして古い結果でN走を埋めない。
- `Stat19OfficialMethodHistoryCalculator`: `STAT19-OFFICIAL-TOP2-METHOD-MVP-v1`。`OFFICIAL_TOP2_METHOD_OUTCOME_PROFILE`、走数窓・日数窓を `RETROSPECTIVE` に保存。`recent_aggressiveness=NULL` と理由を返す。
- `Stat30OfficialMethodMixCalculator`: `STAT30-OFFICIAL-TOP2-MIX-MVP-v1`。直近5走/その前15走の非重複集計。両構成比が成立したときだけL1距離。`TACTIC_TRANSITION_STATUS=NOT_ESTABLISHED`。
- `OfficialMethodHistoryBuildService` / `BuildOfficialMethodHistoryCommand`: 公開前にRepository/Rawを再読込みし入力hash一致を検査。STAT-19/30を同一トランザクションで既存run/item/resultへ保存する。1対象レース最大9出走、失敗時は両STATともrollback。既定plan、明示executeだけ保存、execute+dry-runはrun履歴も含め書込み0。

既存EnumのPARTIAL/PARTIAL_HISTORY/NO_HISTORYとDEGRADEDを使用。runのpartial_countには既存Batch02/03と同じくNO_HISTORY/PARTIAL_HISTORYも含める。新規Migration・DB状態は追加していない。既存TACTICAL-HISTORYの120日固定ReaderとSTAT38のprivateな照合処理は直接改変・転用せず、既存Parser/RawReaderを利用する独立Readerとした。

## 3. 期間・欠損・時点の契約

基準Tは対象開催開始日00:00 Asia/Tokyo。対象レース/同開催/日付T以後を除外し、予定時刻もstrict `< T`。Nは3/5/10/20、日数Dは30/60/90/180で `[T-D,T)`。生涯全出走の完全性は主張せず、`OBSERVED_DB_HISTORY_2022_2025_NOT_CAREER_TOTAL`、2022年以前UNOBSERVEDとする。

確認済み中止は実出走から除外し、予定時刻NULLでも他の正常履歴を汚染しない。Raw照合済みDID_NOT_START/WITHDRAWNも除外する。失格/落車/途中棄権は確認済み実出走だが決まり手を推定しない。同着は既存TIED・順位を保持する。

結果/本人/開催/予定時刻/上位決まり手の証拠不足は理由を保持し、N候補枠を消費する。`verified_started_count` と `observed_method_counts` は確認できた下限値。完全な窓の `method_counts` は不足・欠損・左切断時NULL。日数窓の開始が2022年より前ならLEFT_TRUNCATED。観測履歴自体が0ならNO_HISTORYであり、経験0件にしない。完全なN走に決まり手が0件の場合、4件数は0、構成比はNULL/ZERO_METHOD_OBSERVATIONS。

STAT30は20走が不足/未確認ならL1=NULL。両窓の構成比を分母とした `sum(abs(p_recent[i]-p_baseline[i]))`（範囲0〜2）だけであり、戦法転換の確定ではない。

取得、log保存、import、結果処理/更新を別時刻として記録し、取得と取込みの一致は要求しない。保存が取得より前などの矛盾は拒否する。事後取得・CORRECTEDと訂正有効時刻UNKNOWNを監査へ残す。今回の全値は `data_role=DEVELOPMENT_RETROSPECTIVE`、`historical_as_of_available=false`、`prediction_use=NOT_AUTHORIZED`、`official_publication_at=null`、points/confidence=NULL。発走前入力として公開しない。

## 4. 実資料と確認できた数値

公式結果は既存PR117の `result-fixture.json`（112,954bytes、SHA `706e5747f6383711055d1ded3c16575016cfc22148823cc75379117410ee3f11`）と対応PJ0326 Raw6件をread-onlyで使用。rootは `/home/shinya/neo-keirin-artifacts/stat38-distance-laps-mvp-01-20261021-01/pr117-review-20261022-075425-15b42fa7/`。各Rawの実SHA/サイズも検証した。コピー先だけが人工SQLite用の保存パスで、元Rawは不変。

開催情報は既存context extractionのmanifest（SHA `1e1975b5d2c0b598870bb3b860a1cfdbe9bbfbcbaba035e3d878be5928f9a021`）とtargets/db-recordsの保存seal・START/ENDを照合して6レース/39出走だけ投影した。新しい `prepare-20261022-133230-672ed72a/meeting-fixture.json` は13,978bytes、SHA `3dfa0b2f3d8d510a13ec2e74c2b73f27a9c7a1eca48b7d6f885434e87e1a3fc6`。target原本SHAは `de71417eaeecd940dbad4db3abe91d7fb76816af706f61e376c7e3e46b076dd0`、db-recordsは `3229c005ea346553e975210930dca6b5064a54d346e92bba9c7a211ea2014338`。

SQLiteの必須外部キー用meeting/day外部識別子は `isolated-snapshot:<保存内部ID>` というadapterラベルであり、当時の外部識別子の証拠として使用していない。実開催内部ID・期間・開催日・entry/bike/登録番号は上記sealと照合した。DB接続は不要だった。

| 開催年 | race ID | 実出走行 |
| --- | --- | ---: |
| 2022 | 89012 | 7 |
| 2023 | 63439 | 7 |
| 2024 | 37815 / 37819 / 37828 | 20 |
| 2025 | 12542 | 5 |

6レース39出走のRaw照合で公式上位決まり手12件（ESCAPE2/MAKURI4/SASHI3/MARK3）を確認。他27件は3着以下または異常結果で決まり手無記載（落車2、失格1を含む）。全39件で保存結果のentry/player IDはNULLだったが、Rawから既存entryへ一意照合できた。これは複数選手・複数年を合わせたReaderの照合件数で、1人の直近N走統計ではない。

実履歴の例：2025 race12542、登録番号014236の過去2走はrace37828/37819（2024年同開催、対象開催とは別）。本人は5着/3着で上位決まり手は各0。確認済み実出走2/観測4件数全0だが、必要3/5/10/20走不足のため完全窓4件数NULL、STAT30 L1=NULL。対象2024 race37828では同開催の37819を除外し履歴0。日数窓は履歴なし。この限定資料から20走分の実分布差を算出できたとは扱わない。

すべて2026年に取得した過去年資料であり、2026年開催の資料ではない。正常な新レース・距離・決まり手・着順を創作していない。20走/L1の算術・中止等の境界だけを明示した人工Fixtureで確認した。

## 5. 検証と実行経路

新規Feature10 + Unit12 = **22 passed / 1321 assertions**。関連STAT17/38・既存Calculator/結果状態正規化等は **172 passed / 3877 assertions**。それぞれfailure/error/warning/skip=0、exit0。PHP8.5.4・PHPUnit12.5.31・Artisan/Collision。既存の危険cleanupのある旧STAT36群・通常全体は実行しない。過去155 warningsの原因/全解消は今回確認していない。

両実行とも親とPHPUnit子に `memory_limit=128M` を適用し、既存memory bootstrapで実上限・子PID・PHPpeakを保存した。新規22件のpeak59,244,544bytes（56.50MiB）、関連172件65,536,000bytes（62.50MiB）。5,000人工履歴のgeneratorも逐次処理し、保存参照20件だけであることを検証した。

既存bwrap方式で `/` read-only、network namespace分離、/home・/run・/mnt・/mediaを遮断。必要な実Fixtureだけread-only bind、実.envは非秘密コメントFixtureへ差替え、storage/bootstrapは人工tmpfs。DBは人工SQLiteメモリのみ。自分が作成した一時rootだけ既存helperで人工 `/home/shinya/Desktop/Trash/` へrename退避し保持する。本物のDesktop/Trash/.env/storageを変更していない。

実行したArtisanコマンド（bwrap/環境/ログ引数の完全形は各runner.sh/command.txt）：

```bash
php -d memory_limit=128M artisan test tests/Feature/OfficialMethodHistoryMvpTest.php tests/Unit/Domain/Keirin/Statistics/OfficialMethodHistoryTest.php -d memory_limit=128M --bootstrap tests/Support/stat38-memory-bootstrap.php --display-all-issues --do-not-cache-result
```

Commandの使用例（実本番では未実行、今回許可は隔離テストのみ）：

```bash
php artisan keirin:statistics:build-official-method-history --race-id=12542 --input-as-of=2025-01-01T00:00:00+09:00
php artisan keirin:statistics:build-official-method-history --race-id=12542 --input-as-of=2025-01-01T00:00:00+09:00 --execute --dry-run
```

証跡rootは `/home/shinya/neo-keirin-artifacts/stat19-30-official-method-history-mvp-01/`。新規最終ログは `tests-20261022-134301-aV0fhBO3/logs/`、関連は `tests-20261022-134027-dKmQiuOe/logs/`。stdout/stderr/JUnit/events/終了コード/時刻/メモリ/人工Trashを保持。新規logには `real-result-acceptance.json` と `real-history-acceptance.json` を保存した。

初回のtest helper名とLaravel親seed()の衝突、および異常FixtureがRepository検査より先にFK制約で拒否された失敗ログも保持した。helper名をseedSavedへ変更し、異常系の参照先だけ既存の実IDへ変更して検査を到達させた。既存テストの削除・緩和・skip追加はない。

歴史的な開催日/期間/競輪場の矛盾もMEETING_CONTEXT_UNVERIFIEDとして検出し、完全窓にしない。元実験の履歴とは分けて追加1件で確認した。

変更PHP13件のphp -l、限定Pint、git diff --check（新規ファイルも検査）、変更範囲15件と原本8件の終端sealを確認済み。専用検証記録・全差分・ファイルSHA・manifest/COMPLETEは同rootの一意なfinal-checksディレクトリに保存した。

## 6. 未完成・保護

STAT19の行動率/積極性、STAT30の持続的転換/開始日時/成績改善/役割/JS/変化点/配点は未実装。公式初回公開/訂正有効時刻、全生涯履歴・未保存レースの完全性は未確認。実20走のL1正常値受入とPostgreSQLでの保存は未実施。私有の実Fixtureがない環境でのテスト互換性は保証せず、代替ダミーやskipで埋めない。

SCR-STAT19/30各01〜03をDONEにしない。PR118保存基盤、STAT17/38、C1/C2/120日入力・旧成果物/モデル/Solver/Decoder/Gateは不変。業務DB/HTTP/新規取得/本番Migration/正式保存/学習/親lambda選択/H1-U/性能評価/2026開催参照は0。旧pilot保留・B1未承認・正式採用禁止も維持する。

未コミットのコード・テスト・文書差分をレビュー待ちとし、commit/push/PR作成/merge、本番実行や次工程へ進まない。

## 7. PR119 P2レビュー修正 / 2026-10-23

開始branchは `feature/stat19-30-official-method-history-mvp-01`、HEAD/remote branch/GitHub PR headは `882b7d79c9c4f3aa81ae78333900a56e84e86a10` で一致、worktree clean。fetch後のorigin/mainとPR baseは `73cb5717d149fc9e9d16b119cbdb1aa4d68f1b09`。PR119はOPEN/merged=false。新branch・main編集・commit/push/merge/PR更新なし。

### 原因と修正

1. **確定状態**: 旧ReaderはresultParsingCompleteだけを見ていたが、既存RaceLiveResultParserはPROVISIONALも解析する。v2ではRaw detectedStatusがCONFIRMED/CORRECTED、Raw/importのpage statusがRESULTS_AVAILABLE、requested_result_statusがCONFIRMED/CORRECTEDかつ現在race.result_statusと一致する場合だけ本人・結果照合後に数える。暫定/審議/不明やmetadata矛盾はUNKNOWN＋RESULT_FINALITY_UNVERIFIEDとし、N枠は残す。取込み契約ではRESULTS_AVAILABLEを明示的CORRECTEDとして保存できるため、Raw CONFIRMED＋requested/race CORRECTEDも受け付ける。Raw状態と要求/保存状態は別fieldに記録する。
2. **中止**: DBのCANCELLEDだけでは除外しない。既存syncCancelled()は結果を消し、0結果/0払戻のSUCCEEDED importを残す。v2は同raceの最新SUCCEEDED importをimported_at（NULLS FIRST）/IDの降順で選び、CANCELLED要求/parsed status/0件、fetch-log、Raw対象/サイズ/SHA/状態、取得・保存・取込みの時系列を検査する。後続成功importと矛盾したとき古い中止へ戻らない。欠損/状態・対応・時刻未確認はUNKNOWN＋CANCELLED_*理由で枠を残す。SHA改変・不正path・構文破損等の致命的エラーは従来どおり例外停止。Raw確認済み中止だけevidence_verified=true/CANCELLEDとして除外し、予定時刻NULLでも正常履歴を汚染しない。
3. **固定時点**: Repository/WindowBuilderは `Contract::inputAsOf()` でDateTimeの瞬間を値比較し、開催開始00:00 JSTとの完全一致を要求する（マイクロ秒差も拒否）。同じ瞬間のJST/UTCは受理してJSTに正規化する。Serviceはその固定値をrun parameters/result input_as_of/evidenceへ保存し、run policyは共通定数MEETING_START_JST_RETROSPECTIVE_ONLY。Eloquentの標準日時変換はoffsetを落とすため、このServiceが保存する結果インスタンスだけoffset付き書式にし、DBセッションのタイムゾーンに依存させない。共通Model/DB設定は不変。Commandは不正日付・不正offsetを拒否し、planでも対象metadataの時点を照合する（DB読取りあり、Raw読取り/書込みなし）。不正時点はexecute/dry-runとも両STAT書込み0。

契約版は `OFFICIAL-TOP2-METHOD-HISTORY-v2-PR119`、計算版は `STAT19-OFFICIAL-TOP2-METHOD-MVP-v2-PR119` / `STAT30-OFFICIAL-TOP2-MIX-MVP-v2-PR119`。v1の成果物・manifest・実行時hash・数値を改名/再seal/上書きしていない。公式決まり手分布/窓/L1の数式は不変。

### 回帰結果・証跡

追加14ケース（Feature12/Unit2）。最初の修正前実行では対象2クラスの例外assertがPHPUnit自身の失敗もRuntimeExceptionとして捕捉していたため、assertをcatchの外へ出して強化した。その後の修正前11ケースは **10 failed / 1 passed / 441 assertions / exit2** で3系統の不具合を再現。UNKNOWN枠保持の純粋集計1件は元から成功していた。失敗証跡は `pr119-tests-20261023-055652-0aA1l779/logs/` に保持する。

修正後は **対象36 passed / 2030 assertions**、関連STAT17/38・既存統計・状態遷移・Parserは **206 passed / 4371 assertions**。各failure/error/warning/skip=0、exit0、Artisan/Collision・PHP8.5.4・PHPUnit12.5.31。対象peak61,341,696bytes（58.50MiB）、関連67,633,152bytes（64.50MiB）、親/子とも128Mを適用し既存bootstrapで確認した。

保存実Rawの6レース/39出走/上位12件（ESCAPE2/MAKURI4/SASHI3/MARK3）を再照合した。本人の実2走は依然N不足/L1=NULL。中止/暫定/訂正・20走/L1の人工ケースを実Raw正常受入へ読み替えていない。中止の人工正常系は実際の既存importStoredResponse()/syncCancelled()を通し、結果行0件の保存契約と照合した。

対象ログは `/home/shinya/neo-keirin-artifacts/stat19-30-official-method-history-mvp-01/pr119-tests-20261023-060340-ZmpryM0i/logs/`、関連は同rootの `pr119-tests-20261023-055953-gjfvnjjm/logs/`。完全なコマンド/runner.sh、stdout/stderr/JUnit/events/終了コード/日時/メモリ、実Raw受入JSONを保持した。途中の人工Fixture復帰時JSONキャスト二重化による失敗も保持し、Fixture側を修正した。

実行は第5節と同じbwrap read-only/network遮断・人工SQLite/.env/storage/Desktop/Trash。永久削除は行わず、既存helperが記録した人工rootだけをretireした。変更PHP10件の構文・限定Pint・diff checkと変更範囲/原本sealを確認し、同rootの一意なpr119-final-checksへ差分/manifest/検証記録を保存する。

ユーザー実行の全体結果 **3846 passed / 155 warnings / 9 skipped** は別の検証記録。今回のCodex限定検証を全体実行と呼ばず、155 warningsの本文/原因/全解消は未確認。通常全体は未監査の旧cleanupを無理に実行せず未実施。

### 未実施・終了状態

本番DB接続/正式保存/Migration/バックフィル/競輪HTTP/取得/学習/性能評価/2026開催参照0。STAT17/38/TacticalHistory/C1/C2/BT・モデル・旧資料不変。用途はRETROSPECTIVE_ONLY相当のDEVELOPMENT_RETROSPECTIVE、as-of=false、prediction_use=NOT_AUTHORIZED、points/confidence=NULLを維持する。実中止Rawの新規探索・全履歴受入・本番PostgreSQL保存・全体警告原因は今回未確認。状態は `PR119_REVIEW_FIX_VERIFIED_AWAITING_REVIEW`。未コミットでユーザーによるPR更新・再レビューだけを待つ。
