# STAT-35-37-TRACK-CONTEXT-01

## Scope and state

2026-09-23、PR #69 merge・2022-2025保存結果レビュー完了後の限定工程。
開始main/origin: `3d03273ab8aee5a23e8fd317e98a2dc7bad8e9e6`。
作業branch: `feature/stat35-37-track-context-01`。

- 実装: 版付きファイルマスタ検証、日付解決、未補正距離換算、offline coverage command。
- 収集: 42場・44観測版。ただし42版は**2012-12-31時点の資料**であって現在/2022-2025全期間の構造ではない。
- 対象: READ ONLY取得の実一覧42場・10,660場日。場数の固定値をコードに持たない。
- 歴史的適用: 西武園の公式2022-06-28～30イベント案内で3場日のみ解決。10,657場日は `UNKNOWN_LAYOUT_VERSION`、期間競合0。
- 個別の静的構造案内は西武園・熊本を確認。他40場の現在案内/改修履歴の確認は未完了。旧資料の存在を現在構造確認とは数えない。
- **SCR-STAT-35-02全体、STAT-35の得点、STAT-37全歴史網羅はCOMPLETEDではない**。PR #70レビュー修正検証済み・再レビュー待ち。
- `historical_as_of_available=false`、`prediction_use=NOT_AUTHORIZED`。既存C1・旧実験・2026 holdout凍結は不変。

## Evidence

専用証跡ディレクトリ（0700、ファイル0600）:

`/home/shinya/neo-keirin-artifacts/stat35-37-track-context-01/run-20260923-104546-92962a55/`

DBは接続開始から `default_transaction_read_only=on`、lock timeout 5s、statement timeout 5min。
実効設定と接続先を `connection.json` へ保存。対象SQLは `targets.sql`、結果は `targets.json`。
source=keirin_jpかつ2022-01-01～2025-12-31の競輪場ID・外部コード・名称・場日だけを取得。
結果・順位・払戻・上がり全行は再読取せず、本番DML/DDL・BatchRun/FetchLog書込みなし。

採用資料:

| source | 確認内容 | 適用上の限界 |
|---|---|---|
| [KEIRIN.JP用語集](https://keirin.jp/pc/static/beginner/keirin-glossary/a-o.html) | 最終バック線～決勝線の半周 | 公表日時・秒の計測精度は未確認 |
| [KEIRIN.JP Q&A](https://keirin.jp/pc/dfw/portal/guest/guide/question/race.html) | 半周、500mなら250mの説明 | 掲載月ラベルから正確な日時を推定しない |
| [公式2012年記録集](https://keirin.jp/pc/dfw/portal/guest/column/download/kiroku2012_2.pdf) | physical page 11 / printed page 9の構造4列を対象42場について確認 | 「平成24年12月31日現在」の一時点。2022年以降へ延長しない |
| [西武園公式静的イベント案内](https://www.keirin-saitama.jp/seibuen/lp/) | 周長400m・センター/ホーム傾斜角、令和4年度6月28～30日の開催文脈 | 採用期間はこの3日だけ。2012資料の直線値や屋内外を混ぜない |
| [熊本公式施設案内](https://www.kumamotokeirin.jp/guidance/facility/) | 現在の400m、直線60.3m、カント/幅員、2024年6月リニューアルの記述 | 月単位の改修記述から日単位の開始/終了を作らない。期間UNKNOWN |

原文は外部証跡の `.raw`、HTTP metadataは同名 `.json`、文字コード変換後は `.html`。
取得は既存 `KeirinHttpClient` / `CharacterEncodingConverter` を再利用し逐次・間隔あり。
query付きURLの扱いは外部収集スクリプトで明示し、既存クライアントは変更していない。
実行コードは `capture.php` / `capture-v2.php` / `build-master.php`、採否は `source-decisions.json` に記録。

非採用も保存する: robots/サイトpolicy/場一覧は確認用、2021 NAVIは構造適用根拠なし、函館2013PDF URLは404。
動的KEIRIN.JP案内はqueryのない共通テンプレートとなり構造値を確認できず、採用しない。
共通headerは対象構造データに使用せず、当日レースのリンクやbank record/分析APIを追跡していない。
現在の個別案内を全場取得したとは報告しない。2026の結果・出走表・オッズ・bank record取得や評価は行わない。

## Master contract

`resources/data/keirin/track-context/v1/` の明示版のみ。自動latest fallbackなし。

- `tracks.json`: source + external_track_idのキー、名称、layout_version、項目別状態・原文・単位・参照、改修注記、期間。
- `definitions.json`: 計測定義ID・HALF_LAP/EXPLICIT_DISTANCE/UNKNOWN・確認状態・出典・秒精度。
- `sources.json`: URL、fetched_at、published_at（不明はnull）、外部rawパス/サイズ/SHA-256、該当位置、抽出ファイル、正規化版、機械照合用evidence（format / track_id / row / columns）。
- `*.txt/*.tsv/*.html`: 必要最小限の定義/構造抜粋。選手、記録タイム、順位等は実マスタに取り込まない。
- `manifest.json`: 全データ・出典・抜粋のbytes/SHA-256。**自分自身を含めない**。

PR #70修正前manifest SHA-256: `d8eb9cbfff17e64ab4a97ed7d1f8b33ea8ec86f6f5af2824f2754cd604b42b71`。
修正後manifest SHA-256: `d987a6eed079a8370492e57cc92e51611145e42705ae6c68866478e32e35da8a`。
対象一覧SHA-256: `9826917544d22feb5ccbce9855151e293278a974e084f362ec1a88c23e001067`。

Readerは全memberのhash/size、参照先、版、必須項目、単位、decimal、DMS、期間、半周派生と出典連鎖を検査する。
PR #70修正後は、さらに同梱抜粋を実際に解析し、DIRECTのraw/value/unitと場・掲載行・項目、計測定義・期間をロード成功前に照合する。
外部rawは収集/生成時に検証し、実行時は同梱されたsealed最小抜粋と出典台帳を読む。外部rawの存在にfallbackしない。
manifest自体のSHAはcoverageへ残す。再配布時はmanifestのSHAも別経路で照合する。

`CONFIRMED` は資料がその値を掲載していること。全期間適用や発走前公表を意味しない。
欠損/競合はvalue=nullと理由を持ち、欠損を0・屋外へ変換しない。
数値は文字列で公表表記の小数桁を維持し、333/333.3/335を区別。
DMSは原文とdegrees/minutes/seconds/整数arcseconds。29度26分54秒を29.2654度にしない。
`precision=null` は測定精度/不確かさ未確認を表す。文字列表記の小数桁数と物理的計測精度は区別する。
半周距離は確認済み周長の正確な1/2で `DERIVED`、周長と定義の両出典を参照する。
任意項目の欠損だけでは、確認済み距離の解決/計算を妨げない。

## Date resolution

- 期間は日付 `[from, until)`。両端の実在日付、from < until、根拠と出典が必須。
- 確認範囲を有限区間として記録する。一日だけのsnapshotは翌日を排他的終端とし、翌日以降へ延ばさない。
- 期間UNKNOWNは両端null。現在値やpublished_at/fetched_atを期間へ転用しない。
- 対象日に0版なら `UNKNOWN_LAYOUT_VERSION`。2版以上なら、値が同じでも `SOURCE_CONFLICT`。
- 対象版に項目の出典競合があれば `SOURCE_CONFLICT`。先頭/最新/多数決で解決しない。
- 1版のみで項目競合がなければ `RESOLVED`。距離/定義の確認状態はさらに別途チェック。
- 2012熊本500mと現在400mは別時点の観測差として保持。改修境界未確認であって、同一日の競合と決め付けない。

構造時点の解決はpublication timingの証明ではなく、過去予測への利用許可も与えない。

## Pure speed conversion

`AgariSpeedCalculator::calculate()` はDB/HTTPを使用しない。
既存 `AgariStatus` と `RaceEntryResultStatus` の意味は変更しない。
通常換算はVALIDかつFINISHED/TIED、日付解決済み、定義ID一致、距離確認済みのみ。
MISSING、INVALID_FORMAT、OBSERVED_ABNORMAL_RESULT、異常結果、距離/定義不明、定義不一致、構造不明/競合は別reason、数値null。
明示された不正時間/距離（0、負、float、NaN、指数表記等）は例外で拒否する。経験的速度上限はない。

`BigDecimal` により入力は正確な10進文字列。m/s=距離/秒、km/h=(距離×3.6)/秒。
**丸め済みm/sに3.6を掛けない**。各出力だけ12小数桁、half-even。
単位は `speed_mps` / `speed_kmh`、入力距離・秒・版・定義・出典・計算version・用途も出力。
人工例は400m/12秒と500m/15秒で60km/h、333m/10秒で59.94km/h、335m/10秒で60.3km/h。
これは実走行軌跡の正確な速度・総合能力scoreではない。追加した表示桁は原測定精度の向上を意味しない。
気象・戦法・ライン・相手水準補正なし。STAT-35の正式得点や予測評価は未実施。

## Offline execution and coverage

初回実装時の実行コマンド（当時1回、memory_limit=128M、DB接続なし。PR #70の新しい原文照合は未実装）:

```bash
DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php -d memory_limit=128M artisan keirin:track-context:coverage \
  --master-version=v1 \
  --targets=/home/shinya/neo-keirin-artifacts/stat35-37-track-context-01/run-20260923-104546-92962a55/targets.json \
  --output=/home/shinya/neo-keirin-artifacts/stat35-37-track-context-01/run-20260923-104546-92962a55/coverage.json
```

出力は排他的新規作成。既存出力・原本の上書き禁止。対象一覧に重複場日や2026があれば拒否。
読み込む対象一覧は16MiB以下、各マスタファイルは8MiB以下。対象はレース明細ではなく場日一覧。
`coverage.json` は場別・版別の確認/欠損・期間・原文参照と日別解決を保持。
`coverage.csv` は同じ保存reportから作った要約で、DBの再問合せやcoverage再実行ではない。

全場で2012資料の周長・直線・カント・直線傾斜を確認。2012版の屋内外・幅員は未確認。
西武園イベント版は周長/カント/直線傾斜のみ確認、熊本現在版はさらに直線と幅員3項目を確認。
屋内外は写真から推測しないため全観測版で未確認。

| コード・場 | 対象場日 | 距離解決 | 期間不明 |
|---|---:|---:|---:|
| 11 函館 | 253 | 0 | 253 |
| 12 青森 | 292 | 0 | 292 |
| 13 いわき平 | 229 | 0 | 229 |
| 21 弥彦 | 232 | 0 | 232 |
| 22 前橋 | 313 | 0 | 313 |
| 23 取手 | 233 | 0 | 233 |
| 24 宇都宮 | 251 | 0 | 251 |
| 25 大宮 | 220 | 0 | 220 |
| 26 西武園 | 272 | 3 | 269 |
| 27 京王閣 | 239 | 0 | 239 |
| 28 立川 | 190 | 0 | 190 |
| 31 松戸 | 266 | 0 | 266 |
| 34 川崎 | 281 | 0 | 281 |
| 35 平塚 | 241 | 0 | 241 |
| 36 小田原 | 243 | 0 | 243 |
| 37 伊東 | 279 | 0 | 279 |
| 38 静岡 | 198 | 0 | 198 |
| 42 名古屋 | 274 | 0 | 274 |
| 43 岐阜 | 180 | 0 | 180 |
| 44 大垣 | 343 | 0 | 343 |
| 45 豊橋 | 260 | 0 | 260 |
| 46 富山 | 197 | 0 | 197 |
| 47 松阪 | 303 | 0 | 303 |
| 48 四日市 | 247 | 0 | 247 |
| 51 福井 | 212 | 0 | 212 |
| 53 奈良 | 316 | 0 | 316 |
| 54 向日町 | 184 | 0 | 184 |
| 55 和歌山 | 176 | 0 | 176 |
| 56 岸和田 | 317 | 0 | 317 |
| 61 玉野 | 369 | 0 | 369 |
| 62 広島 | 88 | 0 | 88 |
| 63 防府 | 167 | 0 | 167 |
| 71 高松 | 281 | 0 | 281 |
| 73 小松島 | 255 | 0 | 255 |
| 74 高知 | 274 | 0 | 274 |
| 75 松山 | 224 | 0 | 224 |
| 81 小倉 | 437 | 0 | 437 |
| 83 久留米 | 264 | 0 | 264 |
| 84 武雄 | 264 | 0 | 264 |
| 85 佐世保 | 274 | 0 | 274 |
| 86 別府 | 411 | 0 | 411 |
| 87 熊本 | 111 | 0 | 111 |
| 合計 | 10,660 | 3 | 10,657 |

## Verification and remaining work

初回実装時の記録: 新規/関連78 tests / 222 assertions成功。その後の原文値整合性強化の影響範囲55 tests / 156 assertions成功。
これらは当時のJSON内部整合性・hash/size等の検証であり、配布抜粋とDIRECT値・期間の新しい意味照合を検証していたものではない。
Unitで人工期間・改修境界・重複版・未知/欠損/異常・小数/DMS/丸め・hash破損/参照切れを検証。
Featureでは実ファイルマスタによる決定性、上書き拒否、DB接続禁止mock、HTTP送信0を検証。
全回帰はtesting/SQLiteメモリDBで1回実行し、1,932件中1,923成功・既存9skip、14,489 assertions、exit 0（33.036秒）。新規skipなし。
変更PHPのPint --test成功、全10 PHPのphp -l成功。結果は今回証跡の `pint` / `lint` run logに保存。

残課題は現在案内/改修資料の対象全場確認と2022-2025の根拠付き期間充足、屋内外/幅員/公表日時/原計測精度。
不足を0や現在値で埋めず、SCR全体完了としない。無制限の歴史探索やSTAT計算へは移行せずレビューを待つ。
今回の本番書込み、Migration、旧backfill/dry-run/監査/backup再実行、agari再処理、モデル学習/予測評価は全て0。

## PR #70 Review Fix / 2026-09-23

開始branch `feature/stat35-37-track-context-01`、HEAD `5d69de51c93fc5f6d24e1025a13d31f655355f8b`、開始時clean。
本レビュー修正では本番DB接続・新規HTTP取得は0。旧Raw・対象一覧・外部スクリプト・旧coverageは変更しない。

P1の原因は旧 `build-master.php` が `table.hyo3` の先頭を配布用に抜粋したこと。
元Raw内の先頭は記録表、構造表は別表だった。FixtureとRaw全体の解析成功では、誤った配布抜粋を検出できなかった。
指定Rawの17,380 bytes / SHA-256 `1a2b17049aac0c967712dc937ed35812f0f93f48d1ccdc0de14d02be5a2d4022` を照合。
修正版抽出は `周長 / ホーム傾斜角 / センター傾斜角` の3ラベルを全て持つ唯一の表を選択し、0件/複数件は拒否する。
原文の400m、2°51′45″、29°26′54″を保持。記録タイム・選手名・記録日は配布抜粋に含めない。
同じRawの実 `meta[name=description]` にある「2022年6月28日～30日に西武園競輪場で開催される」を保持し、手書きコメントを期間証拠にしない。
DOM再シリアライズによるtbody等の差はあるが、構造値は既存actual Fixtureと一致する。

P2の原因は、ロード時に抜粋をhashで照合するだけで、掲載内容とJSONの対応を解析していなかったこと。
`ExcerptEvidenceVerifier` をロード成功前に呼び、v1の4形式だけを検証する。

- 2012 TSV: 見出し・列名、場名の一意行、周長/直線/カント/直線傾斜、原文「平成24年12月31日現在」を解析。一日snapshotの翌日を排他的終端とする。
- 名称対応: `伊東 -> 伊東温泉`、`向日町 -> 京都向日町` を明示。他は完全一致。sourceのtrack_idとtrack側IDも一致を要求する。
- 西武園: ラベル選択構造表と実metaの場名・開催年月日を解析し、2022-06-28以上2022-07-01未満を照合する。
- 熊本: 専用施設抜粋の場識別・項目キー7個・単位を照合。月単位リニューアル記述を日付境界に転用せず期間UNKNOWNを維持する。
- 用語集/Q&A: 同梱した半周定義の原文を照合。DERIVED距離は引き続き周長/2のexact decimalと周長・定義両出典を要求する。DMSをfloat度へ変換しない。

DIRECTの整合的raw/value改変と派生距離/manifest再封印、別場参照、行/項目/期間根拠の欠落・重複、単位変更、定義変更、歴史期間の異なるDIRECT参照を拒否する。
人工Fixtureも掲載行・列・日付・定義本文を持つ。synthetic専用の検証迂回はない。
半周換算式・丸め・結果状態・42場のtracks.jsonとdefinitions.jsonは変更していない。

新規証跡（旧証跡とは別ディレクトリ）:
`/home/shinya/neo-keirin-artifacts/stat35-37-track-context-01/pr70-review-fix-20260923-125144-2u5Yb8/`

`extract-review.php`、`extraction.json`、新旧manifest、テスト/coverageのstdout・stderr・終了コード、`compare-review.php` / `comparison.json` を保存。
固定targets.jsonのSHAは上記と不変。coverageを次のコマンドで1回だけ新規生成した。

```bash
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= \
php -d memory_limit=512M artisan keirin:track-context:coverage \
  --master-version=v1 \
  --targets=/home/shinya/neo-keirin-artifacts/stat35-37-track-context-01/run-20260923-104546-92962a55/targets.json \
  --output=/home/shinya/neo-keirin-artifacts/stat35-37-track-context-01/pr70-review-fix-20260923-125144-2u5Yb8/coverage.json
```

exit 0・stderr空。42場/44観測版、10,660場日、RESOLVED 3 / UNKNOWN_LAYOUT_VERSION 10,657 / SOURCE_CONFLICT 0。
入力SHA・場日集合・構造値・期間・解決状態を含む**manifest_sha256以外のJSON全内容が旧coverageと一致**。変更場0。
新coverage SHA-256: `828af10825e8f59c99c0f976cb1da9ce7b95fdd2f01e66a4d2c84395bb72f24a`。
原文照合を通過した事実は今回の結果であり、旧coverageが同じ検証を行っていたとは扱わない。

検証は隔離testing/SQLiteメモリDB・PHP 128Mで各1回:
- TrackContext: 84 tests / 736 assertions成功（新規29ケース）。DB接続・HTTP送信禁止と出力上書き拒否も維持。
- 全体: 1,961件中1,952成功・既存9skip、15,069 assertions、exit 0、32.624秒。新規skipなし。
- 変更PHP6ファイル限定Pint --test成功、同6ファイルphp -l成功。

状態は `PR70_REVIEW_FIX_VERIFIED_AWAITING_REVIEW`。PR #70は未マージ・再レビュー待ち。
40場の個別現在案内未確認、歴史期間不足、SCR全体未完了、historical_as_of_available=false、予測利用未承認、2026凍結を維持する。
