# STAT-35-37-TRACK-CONTEXT-02

## Scope and state

2026-09-23、PR #70 merge・2件のレビュー修正完了後の限定工程。
開始main: `24a217e2fdfd21c7df490c55a08cb34760031e80`、開始時clean。
作業branch: `feature/stat35-37-track-context-02`。未コミットで今回結果のレビュー待ち。

- コード: v2専用の3資料形式を追加し、配布抜粋の場・項目・値・単位・期間をロード成功前に検証。
- 資料確認: 固定42場の入口・案内/歴史候補を一巡。ナビゲーションを読めた37場、取得/変換不能5場。全場の歴史資料確認完了とはしない。
- 配布: 42場89観測版。v1の44版を保持し、年間記録集42版と開催案内3期間を追加。
- 歴史coverage: 同一10,660場日で距離解決3→14、UNKNOWN 10,657→10,646、競合0、後退0。
- **SCR-STAT-35-02、STAT-35/37全体、歴史マスタ完全化は未完了**。点数計算や能力補正は実装していない。
- `historical_as_of_available=false`、`prediction_use=NOT_AUTHORIZED`、旧C1/成果物、2026 holdout凍結を維持。

## Immutable inputs and evidence

専用永続証跡ディレクトリ:

`/home/shinya/neo-keirin-artifacts/stat35-37-track-context-02/run-20260923-061824-fe45e340/`

生成時に一意パスを作り、以後明示引数で渡した。umask 077、排他的新規作成。旧証跡は上書きしない。

| 入力 | SHA-256 |
|---|---|
| 固定targets.json | `9826917544d22feb5ccbce9855151e293278a974e084f362ec1a88c23e001067` |
| PR70修正版v1 coverage | `828af10825e8f59c99c0f976cb1da9ce7b95fdd2f01e66a4d2c84395bb72f24a` |
| v1 manifest | `d987a6eed079a8370492e57cc92e51611145e42705ae6c68866478e32e35da8a` |
| 今回v2 manifest | `3ece1b4066b4b50d326889a1f341d1fee57bdf523029c0fd4d8139b0923a6c35` |

固定targetsは前工程 `run-20260923-104546-92962a55/targets.json`、比較元は
`pr70-review-fix-20260923-125144-2u5Yb8/coverage.json` を読み取り専用で使用した。
本番DBから対象を再取得していない。v1全9ファイルの開始時サイズ/hashと終端値は一致。

証跡の主要ファイル:
- `baseline-seals.json`: v1全ファイルの開始時seal。
- URLのSHA-256を名前にした `.raw/.json/.html`: 原文、取得metadata、変換後HTML。失敗も記録。
- `venue-XX.json` / `venue-review.json`: 全42場の確認URL、取得可否、歴史候補、残る不足。
- `source-decisions.json`: 新規取得URLの採否、非採用・限界。非採用は「構造情報が存在しない」の証明ではない。
- `collector.php` / `collector-restricted.php`: 初期収集と候補制限後の収集手順。
- `build-master.php` / `extraction-notes.json` / `tc02-*.png`: Raw検証・抽出・生成手順、PDF該当ページの目視確認根拠。
- `coverage.json` / `coverage-comparison.json/.csv` / `compare-coverage.php`: 1回の実coverage、保存済みreport同士の比較・項目充足・全場差分。
- `run-command.php`、`focused.*` / `full-suite.*` / `pint.*` / `lint*` / `coverage.*`: 明示コマンド、開始終了時刻、stdout/stderr、終了コード。

## Sources and applicability

新資料を旧v1の見出しへ偽装せず、次の形式だけを追加した。

| 資料 / 配布形式 | 構造値と原文位置 | 採用期間 |
|---|---|---|
| [JKA 月刊競輪WEB 2023年版年間記録集](https://keirin-web.com/admin/wp-content/uploads/2024/10/%E7%AB%B6%E8%BC%AA%E5%B9%B4%E9%96%93%E8%A8%98%E9%8C%B2%E9%9B%86.pdf) / `ANNUAL_2023_STRUCTURAL_COLUMNS_TSV` | PDF physical page 19 / printed 15「■バンクレコード」の周長・最大カント・直線部カント・見なし直線距離の4列、対象42場 | 全42版UNKNOWN。構造の基準日記載なし |
| [前橋73周年公式program](https://www.maebashi-keirin.jp/wp-content/uploads/2023/05/dome2306.pdf) / `MAEBASHI_2023_PROGRAM_TRANSCRIPT` | page 1開催日、page 2右下「バンクの特徴」: 335m、46.7m、36度0分0秒 | `[2023-06-29,2023-07-03)` の4日だけ |
| [平塚2024年11月公式program](https://www.shonanbank.com/wp-content/uploads/2024/10/web-hiratsuka.pdf) / `HIRATSUKA_2024_PROGRAM_TRANSCRIPT` | page 1の2開催、page 4「バンクデータ」: 400m、54.2m、31°28′37″ | `[2024-11-04,2024-11-07)` と `[2024-11-08,2024-11-12)` の7日だけ |

Raw SHA-256（保存済み原文から生成時照合）:
- 年間記録集: `8894dfc332fe579f41e9276a91e4352b660e7c11361d67c99e8b921ae1257939`
- 前橋: `ed87904fa46863008b271ba3092243e1939a0dedcb18ed5b538a6233178baaae`
- 平塚: `1265e646b55ccebbc1a51ddcf7c78a7f9e0215d0748305661913e604b8f09dfd`

年間記録集は実表の列見出しを確認して構造列だけを抽出した。PDF固有の周長数字glyph（㻜～㻥）を0～9へ対応し、描画ページと照合。
表にある記録タイム・選手名・府県・記録日は配布抜粋へ含めない。
[公式公開一覧](https://keirin-web.com/data/)の2024-10-22は公表日であり、2023全体や2023末の構造適用日ではない。
2023版という名前、2012資料との同値、改修記事が見つからないことから適用期間を作らない。

前橋の日付bannerと平塚の画像PDFは、実ページを描画して目視転記した**actual transcript**。
全文自動OCRや元PDFのbyte同一抜粋とは扱わない。空白/改行を正規化し、原文の値・ラベル・日付tokenを保存。
前橋は文字層の構造箱とも照合。平塚の選手/戦法グラフ側の「2024/10/8現在」を構造の基準日に流用しない。
11月7日を2開催の間から補間せずUNKNOWNとする。開催後にも延長しない。

半周定義はv1のKEIRIN.JP用語集/Q&Aをそのまま再利用。DIRECTの周長と定義の両参照からDERIVED距離をexact decimalで生成。
前橋167.5m、平塚200m。構造値の掲載確認と過去発走前の公表時刻確認は別であり、as-of利用を認めない。
各版の屋内外・幅員等、該当資料で未確認の任意項目はMISSING。原測定精度はnullのまま。

[月刊競輪WEB利用規約](https://keirin-web.com/terms/)の商業利用制限を確認。
今回配布するのは構造の事実と期間の最小転記であり、PDF全文・写真・個人/記録/戦法表は配布しない。
本検証を商用利用許諾や予測利用の承認とは扱わない。

## Venue review limits

全42場を未着手のまま残さず公式入口と候補を一巡したが、全場の公式案内/歴史を確認できたとはしない。
37場はナビゲーション確認まで成功。立川はcharset `none`、小田原・伊東・別府はcharset `non` により既存変換器で失敗。
松山は空のrobots応答の文字コードを確定できず、保守的に本体取得へ進まなかった。成功資料へ偽装せず、本番変換器も変更しない。
各場の試行URL/HTTP/エラーはv2の `venue-review.json` と外部 `venue-XX.json` に保存。

逐次1500ms間隔、接続5秒/全体20秒、有限の1試行設定、robots/policy確認・保存済み資料再利用。
初期の広い候補抽出は無関係な案内リンクも選択したため収集を停止し、同一host・path/label条件を絞って再開した。
既取得URLはcache再利用で再取得していない。これらの非構造ページや共通headerはマスタへ採用しない。
robots/terms未確認・エラーは台帳に残す。一巡と採用可能な歴史区間の網羅を区別する。

熊本は既存施設案内に加え[歴史](https://www.kumamotokeirin.jp/guidance/history/)と[2024年7月告知一覧](https://www.kumamotokeirin.jp/news/2024/07/)および該当告知を確認。
2024-07-18告知の2024-07-20本場再開は、現施設の全項目の適用開始日ではない。
告知自身に400mが示されていないため、現在400mとの結合で歴史期間を生成しない。市の改修資料候補は404だった。
旧500m、現400m、今回年刊表500mは別観測のまま。異なる時点の値を同日の競合と決め付けず、対象111場日はUNKNOWNを維持。
西武園も新たな通年期間根拠は採用できず、旧3日以外はUNKNOWN。

## Offline validation and coverage

`resources/data/keirin/track-context/v2/` を明示指定する。v1ファイル・既存ロード/解決/距離換算結果は不変。
ロードは同梱sealed抜粋だけで完結し、DB/HTTP/外部Rawへアクセスしない。
新Parserは見出し、場名/ID、列/項目名、重複、decimal/DMS/単位、実在する連続開催日・曜日を解析。
既存Verifierがその結果をDIRECT raw/value/unit、period、参照へ照合。JSONとmanifestだけを再封印した改変を拒否する。
manifestはデータ・出典・確認台帳・全抜粋のbytes/SHAを含み、自分自身を含めない。

固定対象で次を**1回**実行（exit 0、stderr空）。比較は保存済みreportを読むだけで、coverage再実行ではない。

```bash
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= \
php -d memory_limit=512M artisan keirin:track-context:coverage \
  --master-version=v2 \
  --targets=/home/shinya/neo-keirin-artifacts/stat35-37-track-context-01/run-20260923-104546-92962a55/targets.json \
  --output=/home/shinya/neo-keirin-artifacts/stat35-37-track-context-02/run-20260923-061824-fe45e340/coverage.json
```

対象場日集合・重複なし・分母はv1と完全一致。旧44観測版と西武園3日の解決値/出典は完全一致。
全場SOURCE_CONFLICT 0、解決済みからの後退0。

| 場 | 対象場日 | v1距離解決 | v2距離解決 | 増分 | v2期間不明 |
|---|---:|---:|---:|---:|---:|
| 11 函館 | 253 | 0 | 0 | 0 | 253 |
| 12 青森 | 292 | 0 | 0 | 0 | 292 |
| 13 いわき平 | 229 | 0 | 0 | 0 | 229 |
| 21 弥彦 | 232 | 0 | 0 | 0 | 232 |
| 22 前橋 | 313 | 0 | 4 | 4 | 309 |
| 23 取手 | 233 | 0 | 0 | 0 | 233 |
| 24 宇都宮 | 251 | 0 | 0 | 0 | 251 |
| 25 大宮 | 220 | 0 | 0 | 0 | 220 |
| 26 西武園 | 272 | 3 | 3 | 0 | 269 |
| 27 京王閣 | 239 | 0 | 0 | 0 | 239 |
| 28 立川 | 190 | 0 | 0 | 0 | 190 |
| 31 松戸 | 266 | 0 | 0 | 0 | 266 |
| 34 川崎 | 281 | 0 | 0 | 0 | 281 |
| 35 平塚 | 241 | 0 | 7 | 7 | 234 |
| 36 小田原 | 243 | 0 | 0 | 0 | 243 |
| 37 伊東 | 279 | 0 | 0 | 0 | 279 |
| 38 静岡 | 198 | 0 | 0 | 0 | 198 |
| 42 名古屋 | 274 | 0 | 0 | 0 | 274 |
| 43 岐阜 | 180 | 0 | 0 | 0 | 180 |
| 44 大垣 | 343 | 0 | 0 | 0 | 343 |
| 45 豊橋 | 260 | 0 | 0 | 0 | 260 |
| 46 富山 | 197 | 0 | 0 | 0 | 197 |
| 47 松阪 | 303 | 0 | 0 | 0 | 303 |
| 48 四日市 | 247 | 0 | 0 | 0 | 247 |
| 51 福井 | 212 | 0 | 0 | 0 | 212 |
| 53 奈良 | 316 | 0 | 0 | 0 | 316 |
| 54 向日町 | 184 | 0 | 0 | 0 | 184 |
| 55 和歌山 | 176 | 0 | 0 | 0 | 176 |
| 56 岸和田 | 317 | 0 | 0 | 0 | 317 |
| 61 玉野 | 369 | 0 | 0 | 0 | 369 |
| 62 広島 | 88 | 0 | 0 | 0 | 88 |
| 63 防府 | 167 | 0 | 0 | 0 | 167 |
| 71 高松 | 281 | 0 | 0 | 0 | 281 |
| 73 小松島 | 255 | 0 | 0 | 0 | 255 |
| 74 高知 | 274 | 0 | 0 | 0 | 274 |
| 75 松山 | 224 | 0 | 0 | 0 | 224 |
| 81 小倉 | 437 | 0 | 0 | 0 | 437 |
| 83 久留米 | 264 | 0 | 0 | 0 | 264 |
| 84 武雄 | 264 | 0 | 0 | 0 | 264 |
| 85 佐世保 | 274 | 0 | 0 | 0 | 274 |
| 86 別府 | 411 | 0 | 0 | 0 | 411 |
| 87 熊本 | 111 | 0 | 0 | 0 | 111 |
| 合計 | 10,660 | 3 | 14 | 11 | 10,646 |

対象日へ解決できる項目: 周長14、半周距離14、カント14、みなし直線11、直線部傾斜3、幅員/屋内外0場日。
掲載値が確認できる観測版数（期間UNKNOWNを含み上記とは別）: 周長/距離/カント各89、みなし直線88、直線部傾斜86、各幅員1、屋内外0。
年間表42版の追加を42場の歴史解決と数えない。精度不明・公表時点不明も解消していない。

## Tests and closeout

隔離testing / SQLiteメモリDB / PHP 128Mで各1回:
- `php -d memory_limit=128M artisan test --filter=TrackContext`: **108 passed / 1,757 assertions**。
- `php -d memory_limit=128M artisan test`: **1,985件中1,976 passed / 既存9 skip / 16,090 assertions**、exit 0。新規skipなし。
- 変更PHP4本限定 `vendor/bin/pint --test`: 成功。
- 変更PHP4本 `php -l`: 成功。一括ログに出力がなかった3本は個別結果も記録した。

追加24ケースは配布実ファイル統合、各形式、v1不変、JSON/manifest再封印19異常系、人工改修境界/間隙/期間競合、
任意欠損、DB/HTTPなしのv2 command、2026対象/重複/出力上書き拒否を検証する。
改修・競合・改変値はsyntheticであり、実マスタへ混入させていない。

本番DB接続/書込み、Migration、agari再処理、旧backfill/dry-run/backup/監査再実行、STAT得点、学習・予測評価なし。
2026の静的構造案内だけを許可範囲で読み、2026レースの結果/出走表/オッズ/記録タイムを目的に取得・分析していない。
AgariSpeedCalculatorの式/丸め/状態、既存保存/スクレイピング/予測コード、設定、v1、旧Raw/成果物は不変。
次は今回の根拠・期間・coverage不足のレビューだけ。追加実装/本番処理/予測利用へ自動移行しない。
