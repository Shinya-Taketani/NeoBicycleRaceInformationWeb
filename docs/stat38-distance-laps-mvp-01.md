# STAT38-DISTANCE-LAPS-MVP-01

最新状態（2026-10-22）は末尾「PR117レビュー修正」。初版の件数・SHA・未接続・実行記録は当時の履歴として保持する。

## 状態・承認範囲

2026-10-21。`CODE_AND_LIMITED_REAL_RAW_VERIFIED_AWAITING_REVIEW`。
PR116 MERGEDをfetch後のmain/originとmerge件名で確認した。開始SHAは
`14f3927034fceb7883af5a0626479d1be1ad8e2d`、clean mainから
`feature/stat38-distance-laps-mvp-01` を作成した。mainは編集しない。

今回のユーザー承認は、保存実PJ0315を使う予定距離/周回数の保存基盤と限定MVPの製造・隔離テストだけ。
B1監査BLOCKED・正式B1受入NOT_RUN、方法B1 PROPOSED_NOT_APPROVED、method_selected/selected_lambda=NONEを維持する。
historical_as_of_available=false、prediction_use=NOT_AUTHORIZED、2026_holdout=FROZEN_NO_ACCESS。
正式採用や予測精度改善を確認した工程ではない。

## 根拠と既存接続

- 要件原本「統計エンジン要件定義_STAT-01-46確定_v1.0(2).md」のSTAT-38確定節、残タスク原本SCR-STAT-38-01〜04、取得可否4分類ドラフトを確認した。保存先はダウンロード配下。残タスク4件はOPENのまま。
- 既存`RaceDetailParser` / `RaceDetailPageDto`の `PC0201.C0201data.C0201racedtl.kyori` と `syukai` を再利用する。実際距離/周回数は取得しておらず、予定値から補完しない。
- 既存`RaceRepository::updateRaceDetail`は距離/周回数を永続化せず、現在値を更新する。今回はSTAT-35の履歴保存との比較から、専用観測テーブルを採用した。既存`races`・Fetcher・Parser・通常同期処理は変更しない。
- 既存`RawReader`の保存Rawサイズ/SHA・変換検証を再利用する。新規HTTPはない。取込みは選択した既存race/fetch-logに限定する明示コマンドで、通常同期へ自動接続しない。
- STAT-01/Batch02〜05/STAT-20のDTO・Repository・Calculator・Service・Command、既存状態Enumと汎用統計保存テーブルを確認し、既存実装は変更していない。

## PHPとMigration

| ファイル（共通prefix省略） | 責務 |
| --- | --- |
| `Scraping/DTO/RaceDistanceObservationDto.php` | 識別・値域・SHA・出走者重複検査、予定値/原値/時点状態 |
| `Scraping/Services/RaceDistanceObservationService.php` | 取得元/Raw/日付/場/番号の照合、取込みと保存後再検証 |
| `app/Repositories/RaceDistanceObservationRepository.php` | capture/版の冪等保存、既存内容との矛盾拒否 |
| `app/Models/RaceDistanceObservation.php` | 型変換、追記型モデル |
| `Statistics/DTO/Stat38InputDto.php` / `Stat38FeatureResultDto.php` | 入力/結果契約 |
| `Statistics/Repositories/Stat38InputRepository.php` | 年限定、本人・過去日の履歴を100行ずつ読込、Raw/fetch-log再照合 |
| `Statistics/Calculators/Stat38Calculator.php` | 予測用と事後参考の分離、厳格cutoff、NULLと観測0の区別 |
| `Statistics/Services/Stat38FeatureBuildService.php` | レース単位transaction、既存統計run/item/resultへのSTAT-38限定保存 |
| `app/Console/Commands/Keirin/ImportStat38DistanceCommand.php` | 明示Raw取込み、plan/execute/dry-run |
| `app/Console/Commands/Keirin/BuildStat38FeaturesCommand.php` | 明示input_as_ofによる限定build、plan/execute/dry-run |
| `database/migrations/2026_10_21_000014_create_race_distance_observations.php` | 観測テーブル・UNIQUE/INDEX/RESTRICT FK/CHECK/不変トリガー |

Scraping/Statisticsのprefixは `app/Domain/Keirin/`。

新設`race_distance_observations`はrace ID、レース日/場/番号、fetch-log ID、予定距離/周回数と取得状態、
同Rawの予定発走時刻、実取得日時、source URL/Raw path、原Raw SHA/サイズ、変換後SHA、
fetch metadata hash、元Parser版/観測Parser版、原fieldの存在/値、車番/登録番号、作成日時を保存する。
`actual_distance_m` / `actual_laps`は常にNULL。

観測版は`STAT38-PJ0315-PLANNED-OBSERVATION-v1`、Calculator版は`STAT38-PLANNED-DISTANCE-MVP-v1`。
距離/周回数はAVAILABLE/MISSING。範囲外/不正型はParser/DTOで拒否し、失敗をNULL/0へ変換して保存しない。
欠落と明示NULLは`raw_fields.present`で区別する。新たな共通状態Enumは追加しない。
timing_statusはUNKNOWN / BEFORE_SAME_RAW_START / AT_OR_AFTER_SAME_RAW_STARTで、比較はstrict `<`。
この時刻比較だけで公式初回公開や予測利用を認定しない。

同一fetch-log＋観測Parser版は一意。同じ原Rawを再処理しても追加0、新たな実取得版は別行で保持する。
既存観測と内容が違えば拒否する。UPDATE/DELETEはPostgreSQL/SQLite両方でトリガー拒否、
入力済みテーブルのdownも拒否する。今回PostgreSQLへは適用していない。

## 時点・経験数契約

`input_as_of`は利用者がoffset付きISO日時で明示し、現在日時や`Race.last_fetched_at`から推定しない。
対象の予測用予定値は、同Rawで対象本人/車番が一致し、取得日時とfetch-log作成日時がともにcutoffより前で、
cutoffが同Rawの予定発走より前の場合だけ候補になる。予定発走不明・同時刻・事後取得は利用不能。

予測用経験数にはさらに以下を要求する。

- 保存対象は2022〜2025年、対象の前日までの履歴に限定する。同一レース/未来レース/他選手/重複は拒否する。
- 本人の内部ID（NULLの場合はその状態）と外部登録番号を混同しない。観測Rawの車番/登録番号も検査する。
- 距離観測のRaw/fetch-logを再検証する。履歴の発走時刻がcutoffより前であることも必要。
- 出走確認は既存結果状態Enumを使用し、未知状態や単なる出走表掲載を実出走として補完しない。
- PJ0326の保存import/fetch-log/Raw/対象/車番/登録番号/結果状態の一致を検査する。取得と保存がcutoffより前でなければ予測用件数には入れない。欠場/取消は出走数から除外する。

`PREDICTION_EXPERIENCE_COUNT` / `PREDICTION_SAME_DISTANCE_COUNT`は、保存履歴範囲の全行の必要証拠が揃う場合だけ返す。
証明不足や履歴未確認はNULL＋NOT_ESTABLISHED/理由で、架空の0件にしない。
確認済みの対象外出走だけである場合の0と未確認NULLは区別する。
career全体の出走総数ではなく、`SAVED_2022_2025_PRIOR_CALENDAR_DATES_ONLY_NOT_CAREER_TOTAL`の範囲である。
同日先行レースは保守的に対象外であり、完全な生涯経験集計を実装済みとはしない。

`RETROSPECTIVE`は`use=RETROSPECTIVE_ONLY`の別項目。
事後取得した予定値、本人の`observed_past_entry_count`と完全一致距離の`same_distance_observed_entry_count`を保存する。
これは予定出走表の件数であり、実際の出走件数ではない。
`confirmed_started_count` / `same_distance_started_count`は出走証拠が不足すればNULLにする。
距離一致は公式予定メートル整数の完全一致だけ。1625と1700を「類似」と推定統合しない。
類似距離の境界・交絡補正・距離適性・配点は未定義/未実装。
すべてpoints/confidence=NULL、prediction_use=NOT_AUTHORIZED。既存C1へ自動接続しない。

## 実保存Rawの限定確認

業務DBには接続せず、既存seal付きmetadata bundleから対象/取得ログを読み取った。
元manifest SHAは`691717e4a95c7912c5fcfff23c3da8a1e1df13194ccd87bd5cc4dde1d3f41e7a`。
元targets/sources/race-requestsのサイズ/SHAを照合し、実Rawの存在・サイズ/SHA・Parser識別・出走者を確認した。

| レース日 | race ID | fetch-log ID | 予定m | 予定周回 |
| --- | --- | --- | --- | --- |
| 2022-01-01 | 89012 | 287586 | 1625 | 4 |
| 2023-01-01 | 63439 | 173067 / 224195 | 1625 | 4 |
| 2024-01-01 | 37815 | 103289 | 1625 | 4 |
| 2024-01-01 | 37819 | 103357 | 1625 | 4 |
| 2024-01-02 | 37828 | 103579 | 1625 | 4 |
| 2025-01-01 | 12542 | 39453 / 39988 | 1700 | 5 |

実測した確認範囲は6レース・39出走・8取得版。取得は2026年の事後取得で、2026開催のデータではない。
2025対象本人に一致する2024年の2レースは参考出走表件数2、1700mと1625mは不一致0、
2024-01-02対象から2024-01-01の同本人を見る場合は参考件数1・1625m一致1を確認した。
複数の取得版で同じレースを二重計上しない。他の正常出走者へ件数を混入させない。

この限定資料には当時取得済みの証明がなく、予測用経験数の実データ受入はNOT_ESTABLISHED。
PJ0326による出走確認済み件数の正常系は今回は実物接続していない。
不足資料を架空の取得日時/結果で埋めず、実出走件数はNULLのまま検証した。
本番保存件数は0であり、隔離SQLiteでの保存テストを本番実績としない。

## 実行・テスト

PHP `/usr/bin/php` 8.5.4、Artisan/Collision→PHPUnit 12.5。
ネットワークnamespaceを分離し、既存rootはro-bind、home/run/mnt/mediaを隔離、
本物の.envはコメントだけの人工ファイルで隠し、storage/bootstrap/cacheは人工領域にした。
実Raw fixtureのコピーは今回専用rootから読取り専用で公開し、DBはSQLite `:memory:`のみ。
テスト作成rootは既存helperの保持オブジェクトで管理し、人工Desktop/Trashへrename-only退避する。
本物のDesktop/Trash・復元済み.env/storageを変更せず、永久削除もしない。

隔離内の最終実コマンド（実fixtureの環境変数とbwrap全argvはログ参照）:

```bash
/usr/bin/php -d memory_limit=128M artisan test \
  tests/Feature/Stat38DistanceMvpTest.php \
  tests/Unit/Domain/Keirin/Statistics/Stat38CalculatorTest.php \
  -d memory_limit=128M --display-all-issues --do-not-cache-result \
  --log-junit /tmp/evidence/junit.xml --log-events-text /tmp/evidence/events.log
```

子PHPUnitにも`-d memory_limit=128M`を渡し、テスト内で実際の設定値をassertする。
親PHPだけの指定では子が`-1`だった初回失敗も保存している。閾値緩和やphpunit.xml変更はない。

| 検証 | ケース | assertions | 失敗/エラー | warnings | skip | exit |
| --- | --- | --- | --- | --- | --- | --- |
| 新規Feature | 14 | 224 | 0 | 0 | 0 | 0 |
| 新規Unit | 4 | 26 | 0 | 0 | 0 | 0 |
| 既存STAT-01/Batch02/03/04/STAT-20/文字コード変換 | 96 | 812 | 0 | 0 | 0 | 0 |

新規18件の最終所要0.56秒、最大RSS98,272KiB（OS計測、PHP peakではない）。
関連96件は4.90秒、最大RSS86,324KiB。いずれも128MiBの限定実行。
初期関連実行の51 warningsは隔離用/dev/nullを.envへ重ねた際のPermission denied（phpdotenv Reader.php:73）。
秘密なし通常ファイルへの隔離修正後、同一関連クラスで0件を確認した。既存アプリ/依存/設定は変更せず、過去の警告全件解消とは扱わない。
最初のメソッド名衝突・Fixture必須列不足・子memory_limit失敗もログに保持し、消していない。

正常Parser値、source保持、再処理/再取得、NULL/不正値、Raw改変/サイズ/SHA不一致、対象識別、
本人/未来/重複拒否、事後参考分離、時点不明/同時刻/発走後、dry-run書込み0、保存後再検証、
DB不変トリガー/RESTRICT FK/空でないdown拒否、2026人工拒否、他STAT/既存業務列不変を検証した。
変更PHP14件の構文確認・限定Pintを通過した。全体テストは未実施で、未確認のcleanup経路へ拡大していない。

## 証跡と利用制約

永続root: `/home/shinya/neo-keirin-artifacts/stat38-distance-laps-mvp-01-20261021-01`。
原本は変更せず、Rawコピー/取得識別を含むprivate fixtureをGitへ追加していない。

- `actual-fixture.json` SHA: `7ec6fae7e6ff5f8137754986c8f9e8d10bd766b9c65f61aa1252944494ba04dc`
- `history-fixture.json` SHA: `0344d20e9db4918a6a1a8cc0f691cdea724c22fc1b90c9ce2520ce12a02379ca`
- 最終新規テスト: `tests-20261021-070502-RowUArzw/logs/`
- 最終関連回帰: `tests-20261021-070317-GEtzlcWS/logs/`

stdout/stderr、イベント、JUnit、終了コード、開始終了時刻、PHP版、資源使用量を保持する。
実Raw正常系テストには上記sealのfixture bundleが必要で、`STAT38_TEST_FIXTURE`に読取り専用の
`actual-fixture.json`を指定する。同階層にRawコピーとhistory fixtureが必要。
資料がない環境ではfail-closedで、ダミー/skipへ置換しない。独立CIへのfixture供給/互換性は未確認。

コマンド名は `keirin:stat38:import-distance --race-id= --fetch-log-id=*` と
`keirin:statistics:build-stat38 --race-id= --input-as-of=`。既定はDB書込みなしのplan。
明示`--execute --dry-run`でもrun/batch/観測/統計保存は0。
MigrationをCommandから実行しない。非dry-runはレース/取込み単位のtransactionで失敗時rollbackし、自動再試行しない。
正常保存の実行例を本番への実行許可とはしない。

## 未完了・次の判断

PostgreSQLでのMigration/制約/型の実適用、正式取込み、本番保存は未実施・未承認。
予測用経験数の正常実データ受入、同日過去レース、距離変更/再発走・公式制度master、
級班/段階/開催方式/バンクの交絡調整・距離適性評価・点数/信頼度は未完成。
既存Scraping/Parser/C1/STAT-35/既存モデル・成果物は不変。
本番DB接続/HTTP/2026開催/B1学習/lambda選択/H1-U/性能評価/commit/push/PR作成/mergeは0。
次はコードと限定受入差分のレビューだけ。本番適用や学習へ自動進行しない。

## PR117レビュー修正（2026-10-22）

`PR117_REVIEW_FIX_VERIFIED_AWAITING_REVIEW`。開始はcleanな既存branch、HEAD/PR headは
`f4fafffb73ef48166f7a45bf253b1bd440c9d475`、origin/mainは上記14f3927。新branch/main編集/Git書込みなし。
今回の計算版は `STAT38-PLANNED-DISTANCE-MVP-v2-PR117`。観測保存契約v1と既存Migrationは変更しない。

- P1: 既存`RaceResultImportService::syncAvailableResults()`の`race_results.fetched_at`は取込み処理時刻であり、HTTP取得時刻ではない。完全一致条件を廃止し、取得/log保存/import開始・保存/結果処理・更新を区別する。必要な保存時刻の最大値と取得日時がともにstrict `< input_as_of`でなければ予測利用できない。欠落時刻はNULL、不正な前後関係は拒否する。
- 既存importerは正常取込みでも結果のentry/player IDを設定しない。ユーザー承認に従いrace＋車番で結果を読み、import/fetch-log/Rawのpath/サイズ/SHA/source/Parser、公式Rawの日付/場/番号・車番/登録番号・着順/結果状態を照合する。DB側IDが非NULLなら必ず一致、NULLは補完せず`result_identity_evidence`に元のNULLと照合先を別記する。DB/Rawの本人重複・不一致は拒否する。既存importer/業務DBは修正していない。
- P2: 観測版は取得日時とRaw保存証拠（fetch-log作成日時）がともにcutoff前のものだけから決定的に選択する。不適格な最新版を除いて古い適格版を利用できる。100行ずつ走査し、同一取得時刻の競合は最新2件だけでなく全該当版で検査する。103版の人工境界試験も通過した。
- P2: 過去観測があり、出走状態がすべて検証済みの欠場/取消なら参考実出走数0。未確認・未観測はNULL。予測用経験数との分離を維持する。
- 業務テストから親`memory_limit`への依存を除き、別の`Stat38MemoryTest`が専用子PHPに`-d memory_limit=128M`を実際に指定する。子は実Raw取込み・結果照合・参考集計の3ケースを実行し、PID、設定、終了コード、JUnit、PHP peakを検査・保存する。閾値変更、skip、警告抑制はない。

### 実資料と人工境界の区別

既存2022/2023/2024/2025各1月のread-only保存snapshotについて、使用5表のmanifestとSTART/END sealを照合した。
対象は初版と同じ6レース・39出走の公式PJ0326結果Raw6版だけ。実Rawのサイズ/SHA・変換SHA・対象・本人・結果を照合し、
39件すべてで元のresult entry/player IDはNULLだった事実を保持した。race 37815の実記録は取得20:23:06 UTC、結果処理20:23:07 UTCで1秒差がある。
既存サービスへ保存済み2022 Rawを隔離SQLiteで再投入しても処理時刻は取得時刻と異なり、本人照合が成功した。
実資料の過去2レースは参考実出走数2、予測用経験数はNULL。事後取得のため予測用実データ受入は引き続きNOT_ESTABLISHED。
取得/save境界・確認済み欠場0・ID/登録/状態/重複/改変/欠損は明示した人工制御・異常系であり、当時取得済みだった証明ではない。

### 今回の検証

| 実行 | ケース | assertions | 失敗/エラー/warnings/skip | exit |
| --- | --- | --- | --- | --- |
| STAT-38、Artisan/Collision、128M | 33 | 682 | すべて0 | 0 |
| 同じ33件、通常の親memory_limit（子PHPUnitは-1） | 33 | 682 | すべて0 | 0 |
| 既存STAT関連96件、128M | 96 | 812 | すべて0 | 0 |

33件の内訳はFeature26/591、Calculator6/72、独立メモリ1/19。
独立子のPHP peakは57,147,392 bytes（54.5MiB）、memory_limit=128M、親子PID別、exit0。OS RSSと混同しない。
修正前コードで、通常起動の128M固定assert、適格旧版選択、全版不適格NULL、確認済み0件が失敗した記録を保持する。
開始コミットのRepositoryだけを隔離子へread-only重ね合わせしたP1確認でも、通常import後の本人対応が失敗し、修正後は通過した。

ユーザー実行の過去全体結果は1 failed /155 warnings /9 skipped /3791 passed /35356 assertions。
今回は危険cleanupを含む対象外全体テストへ広げず、上表の安全な限定実行だけ。155 warningsの本文・原因・全解消は未確認で、今回の警告0と区別する。
変更PHP6件の構文、限定Pint、diff検査を実施する。PHP8.5.4 / PHPUnit12.5.31。bwrap隔離方法は初版と同じ。

今回の証跡: 上記永続rootの `pr117-review-20261022-075425-15b42fa7/`。
`result-fixture.json` SHA: `706e5747f6383711055d1ded3c16575016cfc22148823cc75379117410ee3f11`。
最終128M: `tests-bounded-20261022-080252-dNkSQDc3/logs/`、通常: `tests-default-limit-20261022-080304-Tx2KBCcp/logs/`、
既存回帰: `tests-bounded-20261022-080226-uSN8G2Sn/logs/`。独立子の測定/実行/JUnitは各実行の人工Desktop/Trash内に保持する。
stdout/stderr/イベント/実コマンド/時刻/exit、初期Fixtureのseal比較実装・JSON型・NULL IDに関する停止、テスト準備の失敗も消していない。
旧18件/250 assertions、関連96/812、初版hashは初版時点の記録であり、修正後の実行へ読み替えない。

本番DB接続・書込み/Migration正式適用/HTTP/2026開催/B1学習/lambda選択/H1-U/性能評価は0。
本番適用と予測用経験数の正常実データ受入、独立CIへのprivate fixture供給は未成立。今回差分は未コミットで再レビュー待ち。
