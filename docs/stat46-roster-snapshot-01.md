# STAT46-ROSTER-SNAPSHOT-01

最新状態は末尾「PR120 P1カテゴリー受入修正」（2026-10-24）。初版の版・SHA・件数・未コミット記録は当時の履歴として保持する。

## 範囲と開始条件

2026-10-23。PR119 MERGEDをユーザー承認、fetch/main pull --ff-only後のmain/origin一致、
`87a8a17ac620cd92723ee38f092153feba39e88f`のmerge件名で確認。
clean mainから未使用の`feature/stat46-roster-snapshot-01`を作成した。main編集・commit/push/PR操作なし。
AGENTS/MASTER PLANを全文確認し、8工程ゲートとSTAT46確定節・SCR-STAT-46-01〜04を照合した。
旧PR119レビュー待ちは当時の履歴。今回の承認は出走表履歴の限定製造・隔離検証だけ。

## 実装と保存契約

| PHP | 責務 |
| --- | --- |
| `Scraping/RosterSnapshots/Reader.php` | 既存RaceDetailParserとRawObservationDecoderから全出走者を投影。PC0201/PJ0315の本人集合・一意性を検査 |
| `Scraping/DTO/RaceRosterSnapshotDto.php` | レース識別・出走者数・車番/登録番号一意性・NULL枠の検査、保存版v1 |
| `Scraping/Services/RaceRosterSnapshotService.php` | 明示race/fetch-logのsource/Raw/対象照合、transaction・終端再検証、明示保存版の比較 |
| `app/Repositories/RaceRosterSnapshotRepository.php` | 同fetch-log/Parser版の冪等保存、既存内容の矛盾拒否 |
| `app/Models/RaceRosterSnapshot.php` | 型変換とUPDATE/DELETE拒否 |
| `Statistics/Calculators/Stat46RosterDifferenceCalculator.php` | 2版の純粋差分。DB/HTTP/現在のrace_entriesへの依存なし |
| `ImportRosterSnapshotsCommand.php` / `CompareRosterSnapshotsCommand.php` | plan既定、明示execute、取込みdry-run、比較read-only |

Scraping/Statisticsは`app/Domain/Keirin/`、Commandは`app/Console/Commands/Keirin/`配下。
保存版は`STAT46-PJ0315-ROSTER-SNAPSHOT-v1`、計算版は`STAT46-OBSERVED-ROSTER-DIFFERENCE-v1`。

Migration `2026_10_23_000016_create_race_roster_snapshots.php`は、1取得版につき1行の
`race_roster_snapshots`を追加する。JSON entriesは最大9人に限定し、Raw本文・結果値を複製しない。
race/fetch-logのRESTRICT FK、fetch-log＋Parser版UNIQUE、race＋取得日時INDEX、
件数/用途CHECKとUPDATE/DELETE拒否トリガーをPostgreSQL/SQLiteに定義した。入力済みdownは拒否する。
適用・保存の検証は隔離SQLiteだけ。本番PostgreSQLへの適用・正式保存は未実施・未承認。

レースID/日付/場/番号、fetch-log ID、取得日時、log作成日時、同Raw予定発走時刻・原field、
source URL、Raw path/bytes/SHA、変換後SHA、source metadata hash、取得Parser版/生成版/作成日時を保持する。
出走者は車番/枠番/6桁登録番号/氏名/級班/登録脚質/表示競走得点を保存する。
登録番号は文字列のまま先頭0を保持し、車番が連番であるという前提は置かない。

必要fieldの`present`、`raw_type`、`raw_json`、document内source pointerも保持する。
raw_jsonはnative JSON値の再表現であり、数値の原字句や元Rawバイト列の複製とは称さない。
欠落/NULL/空文字/空object/空listを区別し、正規化値がNULLでも原状態を消さない。正本はSHAで参照する元Raw。

元Rawのサイズ/SHA/安全なpath/文字コード検証は既存RawReaderを再利用する。
RaceDetailParser/RawObservationDecoder/共通canonicalは変更しない。RawReaderの年制限は現在の明示実行境界で使用し、
保存テーブル/DTO/純粋Reader投影/差分Calculatorには2022〜2025の固定年制約を追加しない。
将来の実取得への接続は別承認が必要。現Command/ServiceはSQL側で2022〜2025開催だけに限定する。

過去Rawの集合は現在race_entries/entrant_countへ合わせない。現在DBと異なっても、
Raw内部とレース識別が一致すればそのまま保存する。RaceEntry/Result/Tactic/Distance観測は更新しない。
同Raw再取得も別fetch-logなら別版。同取得版の再処理は追加0、内容矛盾なら停止する。
保存・比較の公開前にRaw/source metadata/レース識別を再照合し、取込み途中のdriftはrollbackする。

## 差分と時点の判定

| 状態 | 判定 |
| --- | --- |
| OBSERVED_ROSTER_CHANGED | 登録番号の追加/消失、同車番の本人変更、同本人の車番/既知枠番変更を観測 |
| OBSERVED_ROSTER_UNCHANGED | 上記差分がなく、共通本人の枠番も両版で確認できた |
| INSUFFICIENT_SNAPSHOTS | 2版未満、または同じfetch-logを2回指定 |
| AMBIGUOUS_OBSERVATION_ORDER | 取得時刻不明、同時刻でRawまたは構成が異なる。DB IDで順序を推測しない |
| SOURCE_MISMATCH | race/日付/場/番号/情報源/URL不一致 |
| NOT_ESTABLISHED | 生成版未対応、または他の構成差はないが枠番比較の証拠不足 |

Raw配列順は保存し、比較用の車番/枠番/登録番号投影だけを車番順にする。
表示得点/氏名/級班/脚質だけの変化を出走者の交代とは判定しない。
NULL枠から公式枠を推測せず、比較未確認の登録番号を列挙する。
取得時刻が異なる場合だけ時系列順に並べ、引数の指定順やDB IDで前後を決めない。

比較は任意のoffset付き`input_as_of`を受け、取得/log保存が双方strict `< cutoff`か、
cutoffが同Raw予定時刻よりstrictに前かを別監査fieldで返す。取得よりlog保存が前なら利用可能とはしない。
取得/log保存/予定/公式初回公開/比較基準は別物。log作成日時を専用のRaw永続化確認時刻とは扱わない。
予定時刻のAsia/Tokyo解釈は既存Parser経路の規約で、実発走/公式初回公開の証明ではない。
公式公表はUNKNOWN/NULL、全結果RETROSPECTIVE_ONLY、prediction_use=NOT_AUTHORIZED、as-of=false。
公式変更区分・本人補正・レース再構成影響・不確実性・points/confidenceはすべてNULL。

## コマンド

```bash
php artisan keirin:rosters:import-snapshots --race-id=ID --fetch-log-id=LOG
php artisan keirin:rosters:import-snapshots --race-id=ID --fetch-log-id=LOG --execute --dry-run
php artisan keirin:statistics:compare-stat46-rosters --race-id=ID --first-snapshot-id=A --second-snapshot-id=B --input-as-of=ISO_WITH_OFFSET --execute
```

取込みは1〜100個の明示fetch-logのみ。既定planはDB/Rawアクセスなし。
非dry-run executeだけ新規スナップショットを保存し、dry-runはrun等を含め書込み0。
比較は明示した2保存版を再検証して読み取り、統計run/resultも保存しない。
上記は操作仕様であり、本番保存の実行許可ではない。

## 保存済み実資料の受入

PR118が使用した保存実PJ0315を、その既存fixture sealと照合して再利用する。新規HTTP/DB再取得/全Raw走査なし。
原本root: `/home/shinya/neo-keirin-artifacts/stat38-distance-laps-mvp-01-20261021-01/`。
actual fixture SHA `7ec6fae7e6ff5f8137754986c8f9e8d10bd766b9c65f61aa1252944494ba04dc`、
history fixture SHA `0344d20e9db4918a6a1a8cc0f691cdea724c22fc1b90c9ce2520ce12a02379ca`。

6レース/39出走/8取得版/延べ51出走者を隔離DBへ保存し、全員の値・先頭0・Raw SHA/bytes・再処理を確認した。
比較可能なのはrace12542のfetch39453/39988、race63439のfetch173067/224195の2組4版。
両組とも車番/登録番号の変更・人数増減は0件。全版の枠番はNULLで、枠の変更有無はNOT_ESTABLISHED。
枠を含む完全な「変更なし」や、公式補充がなかったことを確認したとはしない。

いずれも2026年の過去年事後取得で、当時の発走前証明ではない。
元fixtureにlog.created_atが含まれないため、正常系の隔離DBでもNULLを維持した。現在時刻で歴史的保存時刻を捏造しない。
Raw正常保存と本人集合の受入は成立するが、予測利用可能時点の受入はNOT_ESTABLISHED。
人工の変更算術・境界ケースを実競輪の変更イベントへ読み替えない。

## 検証・証跡

状態: **STAT46_ROSTER_SNAPSHOT_READY_FOR_REVIEW**。最終コードの限定検証は以下。既存の失敗ログも削除しない。

| 128MiB / Artisan・Collision | cases | assertions | failure/error/warnings/skip | exit | PHP peak bytes |
| --- | ---: | ---: | --- | ---: | ---: |
| 新規Feature15＋純粋Unit32 | 47 | 768 | 全て0 | 0 | 59,244,544 |
| STAT17/19/30/38関連7クラス | 86 | 4,998 | 全て0 | 0 | 65,536,000 |

PHP `/usr/bin/php` 8.5.4 / PHPUnit12.5.31。親Artisan/子PHPUnitへ`-d memory_limit=128M`を指定し、
既存独立bootstrapで実設定・子PID・peakを記録した。親プロセスの共有累積peakで業務テストを判定しない。
新規メソッドのLaravel seed()名衝突と、実RawのNULL枠を考慮しない初回テスト期待値の失敗も保持した。
NULL枠を既知枠へ補完して成功させていない。既存テストの削除/緩和/新規skipなし。

新規最終ログ: `tests-20261023-065051-2l1htAZa/logs/`、関連: `tests-20261023-064726-dmIVDABd/logs/`。
実行argv/runner/stdout/stderr/JUnit/events/時刻/exit/measurement/原本START・END sealを保持する。
`roster-real-raw-acceptance.json`（158,767bytes）SHAは
`3c29cc8d4738207e0e64812cf384926ed47eebdb98a73889b6f3c21cb510fc1c`。原値・取得版・差分も含むprivate限定受入記録で、Gitへ追加しない。
新規PHP11件の構文確認・限定Pint・diff確認を実施する。通常全体は旧危険cleanupの安全確認範囲外のため未実施。

永続root: `/home/shinya/neo-keirin-artifacts/stat46-roster-snapshot-01/`。
既存bwrap隔離でネットワーク遮断、repo/原本read-only、本物.env/storageを人工領域で保護し、SQLite :memory:を使う。
専用一時rootは作成時保持した既存helperのpath()だけをretireし、人工Desktop/Trashへrename-only退避する。
安全helper自体は変更しない。原本fixture/RawのSTART/END SHAを照合する。

## 未完成・次工程

SCR-STAT-46-01〜04はOPENのまま。今回SCR46-02の全出走者・車番/枠番履歴部分だけを製造した。
公式補充/追加/取消/交代理由・決定/公開時刻、ライン/ギヤ、変更連鎖・依存STAT再計算・stale検出、
本人補正/構造影響/不確実性/交絡調整・性能効果は未実装・未確認。
PostgreSQLの実適用、独立CIへのprivate fixture供給、発走前の実取得/時計・公開証拠の接続は未成立。
B1未承認/正式受入NOT_RUN、lambda未選択、H1-U保留、旧不採用、2026凍結/LIVE禁止は不変。
次は未コミット差分と限定テスト証跡のレビューだけ。本番適用・取得・学習・評価へ自動移行しない。

## PR120 P1カテゴリー受入修正 / 2026-10-24

開始はcleanな既存branch、HEAD/origin branch/remote PR120 headは
`faa260df3f17a4f4ea345ba3faf9f09d6f2aa7c7`、origin/main/remote mainは上記87a8a17と一致した。
Git ls-remoteで照合し、新branch/main編集/commit/push/PR操作はない。

原因はReader/Serviceにカテゴリー検査がなかったこと。既存RaceCategoryPolicy/RaceCategoryを変更せず再利用する。
PJ0315の`C0201racedtl.syumoku`を既存Parserが`RaceDetailPageDto::raceType`へ投影する。
DBの`Race::race_type`はJSJ017同期とPJ0315詳細更新で保存される現在値で、過去Rawを男子へ補完する根拠にはしない。

| Rawの分類 | DBの分類 | 判定 |
| --- | --- | --- |
| MEN | MEN / UNKNOWN | Rawを根拠として保存可能。出走人数から分類しない |
| GIRLS | GIRLS / UNKNOWN | SKIPPED_UNSUPPORTED_CATEGORY / UNSUPPORTED_RACE_CATEGORY、保存0 |
| UNKNOWN | MEN / GIRLS / UNKNOWN | SKIPPED_UNSUPPORTED_CATEGORY / RAW_CATEGORY_UNKNOWN、保存0。DBから補完しない |
| MEN / GIRLS | 対立する既知分類 | ROSTER_CATEGORY_MISMATCHで停止、保存0 |

Reader自身が対象外の専用例外を送出し、Serviceがその例外だけを明示的skip結果へ変換する。
skipにはrace/fetch ID、Raw/DB分類、理由、予定保存0、snapshot_id=NULL、dry_runを記録する。致命的エラーをskipへ一括変換しない。
比較時も非男子Rawを拒否する。公開前にDB race_typeの不変性も照合し、途中変更はrollbackする。
カテゴリー受入版は`STAT46-ROSTER-CATEGORY-ACCEPTANCE-v1-PR120`。
保存形式v1、差分計算v1、Migration、既存カテゴリーPolicy/Enum・他STAT/C1/モデル・旧成果物は変更しない。

追加の最初の14回帰ケースは修正前14 failed /16 assertions /exit1、修正後14 passed /262 assertions /exit0。
ガールズ/L級、未知/null/空、Raw/DB矛盾、Reader直接呼出し、反復処理、Command execute/dry-runを確認した。
その後、field欠落・公開前カテゴリーdrift拒否・実男子5/7/9車の3ケースを追加した。カテゴリー変更Fixtureは隔離異常系のみ。

元の実6レース/39固有出走者/8取得版/延べ51出走者の保存・冪等性・現在DBと異なる過去Raw保持を維持した。
男子9車の正常系は人工補完せず、同じ既存封印済み資料から選んだ実1レース/1版を別枠で確認した。
対象は2025-01-04 race12901 / fetch40546 / Rawの種目「Ｓ級一予選」。当時の発走前利用を証明した資料ではない。
元資料3ファイルのSTART/END sealと日付/場/番号/9車番/9登録番号、対応request/log/Rawサイズ・SHAを照合した。
private fixtureは `pr120-nine-20261024-073534-oHV4inO8/fixtures/nine-fixture.json`、
5,605bytes / SHA `039c77ad09f052144ba07287b0bdaee039b7c75aaebe7887ce327f9f87e47b1f`。Gitには追加しない。

| 今回の最終128MiB / Artisan・Collision | passed | assertions | failure/error/warnings/skip | exit | PHP peak bytes |
| --- | ---: | ---: | --- | ---: | ---: |
| STAT46 Feature32＋既存純粋Unit32 | 64 | 1,049 | 全て0 | 0 | 61,341,696 |
| 既存STAT17/19/30/38の7クラス | 86 | 4,998 | 全て0 | 0 | 65,536,000 |

PHP8.5.4 / PHPUnit12.5.31、親子128M。既存bwrap隔離・SQLite・人工.env/storage/Desktop/Trashを再利用した。
原本はread-only、network遮断、既存helperの記録済みpathだけをrename-only退避。変更PHP4件の構文・限定Pint・diffを確認する。
ユーザーの全体結果3907 passed /155 warnings /9 skippedは別記録。今回通常全体は実行せず、155警告の本文/原因/全解消は未確認。

証跡は初版と同じ永続root配下の新規ディレクトリだけ。
修正前 `pr120-tests-20261024-073414-NGETP3SW/logs/`、14件修正後 `pr120-tests-20261024-073448-iE3FAnuL/logs/`、
最終64件 `pr120-tests-20261024-073619-8Nc4kMdB/logs/`、関連86件 `pr120-tests-20261024-073653-YCjC6Cxb/logs/`。
runner/実コマンド/stdout/stderr/JUnit/events/時刻/exit/メモリ/原本START・END SHAを保持する。
今回の `roster-real-raw-acceptance.json` は158,767bytes / SHA
`f3091e255c89924aaf47bae7d523d6a09973c6b0b564adb3f1d88ae8e7cfb6d4`。初版SHAとは別の今回の実行記録で、旧記録を再sealしない。

実ガールズ資料の新規調査・受入/本番DB接続・Migration・正式取込み・HTTP/2026開催参照/学習/予測/評価は0。
historical_as_of_available=false、prediction_use=NOT_AUTHORIZED、正式採用禁止、旧B1/holdout制約を維持する。
状態は **PR120_CATEGORY_FIX_VERIFIED_AWAITING_REVIEW**。未コミットで今回差分の再レビューだけを待つ。
