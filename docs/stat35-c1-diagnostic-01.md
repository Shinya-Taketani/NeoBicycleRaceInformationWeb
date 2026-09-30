# STAT-35-C1-DIAGNOSTIC-01

## 範囲と位置付け

開始main/origin: `d2ac7b6e2a9a75d4cb503fb269900dc626e1276e`、PR #78はレビュー後マージ済み。
作業ブランチは `feature/stat35-c1-diagnostic-01`。
ユーザーが許可した保存済みOuter 2024/2025の事後診断
`POST_HOC_DESCRIPTIVE_DIAGNOSTIC` のみを実施する。
COMPARE-01のC2−C1 Gate `NOT_PASSED`、C1維持、C2正式採用なし、
`historical_as_of_available=false`、2026 holdout閉鎖を維持する。
再学習・予測再生成・bootstrap・CI・Gate再判定・DB/HTTP/Raw参照はしない。

## 固定Source

- COMPARE: `/home/shinya/neo-keirin-artifacts/stat35-c1-compare-01/run-20260929-215427-3b1776ba/result`
- COMPARE manifest: 138599 bytes、SHA-256 `8fc40000c93bbc604caac37cf21e60519f26f28c4e77cf9284fa31403f5487c8`
- INPUT: `/home/shinya/neo-keirin-artifacts/stat35-c1-input-02/run-20260930-054458-8739da4b/result`
- INPUT manifest SHA-256: `7f4356b93f90203dc727dc95d6215a8d8ce3f44869c4441c3981087ff1099c26`
- C1 root: `/home/shinya/neo-keirin-artifacts/tactical-history-01-review-fix-20260916-01`

COMPLETEと固定manifestを照合し、その子sealから2024/2025 INPUT・sidecar、
run-01 Outer C1/C2モデル・保存予測、教師、contributions、comparisonsだけを読む。
読取り前後に選択した子ファイルを検証する。run-02は標本に含めず、
2022/2023生学習データや旧354依存ファイル全体の再監査は行わない。
教師の特徴量はINPUTとの照合にのみ用い、utilityへ渡す正本はINPUTとsidecarとする。

## 診断契約

`STAT-35-C1-DIAGNOSTIC-01-v1`。Primary decisionだけを比較し、MAPを代用しない。
既存MetricEvaluatorの各公式順位の一意性・FINISHED/TIED規則を維持する。
適格レースをA（両的中）/B（C1のみ）/C（C2のみ）/D（両不的中）に分類する。
不適格は別集計し、予測変更数とDを混同しない。
Hit@3は公式1～3着がすべて一意のレースの一致位置数0～3の4×4表。
年別分子・分母・未丸め率を保存済み寄与・比較へ照合する。
年等重み差は年別率差の単純平均。分母0はnull/NOT_EVALUATED。

各entry/順位についてanchor、既存16項目、STAT35直接寄与を分離する。
各モデル固有のbin/active parameter対応を使い、既存feature順と補償加算を維持する。
同一算術経路で復元したutilityと保存utilityはbinary64でexact一致を要求する。
異なる加算順での分解残差だけに、実データ読取り前に固定した次の許容幅を用いる。

```text
64 * PHP_FLOAT_EPSILON * max(1, abs(anchor)
    + sum(abs(C1 active contributions)) + sum(abs(C2 active contributions)))
```

NULL、未学習/非active、観測された0、active係数0を区別する。
NULLのSTAT35直接寄与は0だが、再学習済み既存項目の差まで0とは要求しない。
寄与はutility尺度であり確率ppや因果効果ではない。
各年・順位・B/Cの最初の3例を固定入力順で保存し、好都合な例を選ばない。

## 実行経路

```bash
php -d memory_limit=128M artisan keirin:stat35:c1-diagnostic plan
php -d memory_limit=128M artisan keirin:stat35:c1-diagnostic build --output-dir=RUN/result
php -d memory_limit=128M artisan keirin:stat35:c1-diagnostic reproduce --output-dir=RUN/reproduction --original-dir=RUN/result
```

RUNは `/home/shinya/neo-keirin-artifacts/stat35-c1-diagnostic-01/` 配下の新規ディレクトリ。
アプリDB/HTTPはprocess-localで拒否。JSONLはstreaming、重複ID照合はローカルSQLite。
新規出力は排他的に作成し、source/code終了時seal・生成物検証後だけCOMPLETEを公開する。
再現はモデルを再学習せず今回の明細・集計を独立生成して全成果物sealを比較する。

## 検証・実行記録

### テスト

- 新規32ケース・240 assertions。既知A/B/C/D、変更した両不的中、Primary/MAP差、同着各位置、Hit@3行列、分母0、年等重み/一括比の違いを確認。
- year/race/entry/bikeの欠落・重複・並び違い、非単調ID、年をまたぐ重複、正解列混入を確認。
- モデル別bin/index、NULL・有効0・係数0・非active、utility exact/丸め残差、教師変更で寄与不変を確認。
- 未知版・改変・seal不一致・上書き・生成時改変の拒否、DB/HTTP/学習禁止、独立再現を確認。
- 独立PHP `memory_limit=128M` で24,000レース・120,000出走、100MiB超のsourceを実際にstreaming生成。終了0、ピーク128MiB未満を要求し成功。
- 関連: `php -d memory_limit=128M vendor/bin/phpunit --filter 'Stat35C1DiagnosticTest|Stat35C1ComparisonTest|Stat35C1ComparisonNumericsTest|TacticalHistoryFinalModelLoaderTest' --colors=never` は105 tests / 567 assertions、16.854秒、終了0。
- 最終コードの通常全体 `php artisan test --colors=never` は **2320 passed / 9既存skipped / 19689 assertions**、182.483秒、終了0。新規skipなし。
- 変更PHP12ファイルの `php -l`、同12ファイルを対象とする限定Pint `--test`、`git diff --check` 成功。

### 実行証跡

実在run:
`/home/shinya/neo-keirin-artifacts/stat35-c1-diagnostic-01/run-20260930-111106-0ec076bb`

`runner.php` と `focused/full-suite/pint/syntax/plan/build/reproduce` 各ディレクトリに
正確なargv、stdout/stderr、開始終了日時、所要時間、終了コードを保存。
runnerは既存テスト用process環境分離を利用し、共有.env/config cacheを変更しない。

実行コマンド（上記RUNを代入。再学習・予測生成を伴わない）:

```bash
php -d memory_limit=128M artisan keirin:stat35:c1-diagnostic plan
php -d memory_limit=128M artisan keirin:stat35:c1-diagnostic build --output-dir=/home/shinya/neo-keirin-artifacts/stat35-c1-diagnostic-01/run-20260930-111106-0ec076bb/result
php -d memory_limit=128M artisan keirin:stat35:c1-diagnostic reproduce --output-dir=/home/shinya/neo-keirin-artifacts/stat35-c1-diagnostic-01/run-20260930-111106-0ec076bb/reproduction --original-dir=/home/shinya/neo-keirin-artifacts/stat35-c1-diagnostic-01/run-20260930-111106-0ec076bb/result
```

| 実行 | 開始〜終了（JST、ログはUTC） | 秒 | peak bytes | exit |
|---|---|---:|---:|---:|
| build 1回 | 2026-09-30 20:15:16〜20:15:53 | 37.685506 | 37748736 | 0 |
| reproduce 1回 | 2026-09-30 20:16:07〜20:16:45 | 38.128022 | 37748736 | 0 |

14成果物（合計751,447,721 bytes）とmanifestが独立再現でサイズ・SHA-256一致。
両manifest: **15936 bytes**、SHA-256
`304d2329df00ff17de383352ec45b01522430cb9181c2e13c399f531b8d451f9`。
選択source21ファイル・直接依存code35ファイルはSTART/END不変。
2024年25,212レース/179,089出走、2025年24,866レース/177,120出走、
合計50,078レース/356,209出走を保持。両モデル全3順位の保存utilityとexact一致。
再現は追加標本・新規評価ではない。

## 実測診断

### Primaryの的中変化

A=両的中、B=C1のみ的中、C=C2のみ的中、D=両不的中。
変更数は全レース/適格レース。率差はC2−C1、単位pp。表示だけ丸める。

| 年 | 順位 | A | B | C | D | 予測変更 全/適格 | 差分子 C−B | 分母 | 差pp |
|---|---|---:|---:|---:|---:|---:|---:|---:|---:|
| 2024 | 1 | 10323 | 101 | 141 | 14593 | 406/405 | +40 | 25158 | +0.158995 |
| 2024 | 2 | 5757 | 284 | 315 | 18750 | 1455/1449 | +31 | 25106 | +0.123476 |
| 2024 | 3 | 4447 | 254 | 289 | 20104 | 1517/1509 | +35 | 25094 | +0.139476 |
| 2025 | 1 | 9790 | 96 | 111 | 14792 | 368/365 | +15 | 24789 | +0.060511 |
| 2025 | 2 | 5493 | 250 | 255 | 18729 | 1275/1266 | +5 | 24727 | +0.020221 |
| 2025 | 3 | 4426 | 251 | 238 | 19824 | 1346/1338 | -13 | 24739 | -0.052549 |

年等重み差（各年率差の単純平均）は1着+0.109753、2着+0.071849、3着+0.043463、Hit@3+0.071916 pp。
全年度の分子/分母による加重差ではない。元のcontributions・comparisonsと未丸めで一致。

### Hit@3

行=C1の一致位置数0/1/2/3、列=C2の一致位置数0/1/2/3。

```text
2024:
10047 312   53    3
  282 8854 179   39
   39 159 3841   37
    0  33   31 1131

2025:
10288 238   47    0
  254 8678 151   35
   42 132 3591   28
    0  33   42 1104
```

2024: 適格25,040、除外172、分母75,120、C1分子21,091→C2分子21,196（+105）。
2025: 適格24,663、除外203、分母73,989、C1分子20,241→C2分子20,244（+3）。
4×4表から復元した分子・分母・率差は原評価に一致。
2/3着だけを入れ替えたPrimaryは公式1～3着一意の範囲で2024年561、2025年477レース。

### 同着・異常結果

| 年 | TIEDを含むレース | 異常結果を含むレース | 1着除外 | 2着除外 | 3着除外 |
|---|---:|---:|---|---|---|
| 2024 | 282 | 1664 | 非一意54 | 欠順位54、非一意52 | 欠順位58、非一意60 |
| 2025 | 315 | 1522 | 非一意77 | 欠順位77、非一意62 | 欠順位66、非一意61 |

TIED/異常を含むだけでレース全体を除外しない。各順位の適格性を既存Evaluatorで判定。
異常出走件数（2024/2025）はCRASHED 1310/1280、DID_NOT_FINISH 63/69、
DID_NOT_START 259/261、DISQUALIFIED 860/674。両レース集計は重複し得る。
詳細なHit@3除外位置はtransition-summary.jsonに保持。

### 係数と寄与

全bin台帳は1,710行（C1/C2各年のanchor含む）。STAT35は各年10学習bin×3順位。
下表は保存係数範囲と全対象出走での寄与差の平均絶対値。すべてutility尺度。

| 年 | 順位 | STAT35係数 min〜max | mean abs 直接寄与 | mean abs 既存16項目差 |
|---|---|---|---:|---:|
| 2024 | 1 | -0.034515〜0.095016 | 0.026584 | 0.004530 |
| 2024 | 2 | -0.066863〜0.110452 | 0.038134 | 0.021601 |
| 2024 | 3 | -0.034038〜0.044912 | 0.019173 | 0.010668 |
| 2025 | 1 | -0.036167〜0.117255 | 0.028739 | 0.013511 |
| 2025 | 2 | -0.051635〜0.113028 | 0.036664 | 0.021545 |
| 2025 | 3 | -0.027078〜0.032610 | 0.016511 | 0.010159 |

2024のACTIVE174,950/NULL4,139、2025のACTIVE173,413/NULL3,707。
有効数値0は53/98件。両年ともNULL全件で既存項目差があり、NULLでもモデル全体は同じではない。
最大分解残差は2024 `5.412337245047638e-16`、2025 `5.377642775528102e-16`。
全件が事前固定許容幅内で、同一演算経路による保存utility残差は0。

### 固定順の具体例と限界

各年・順位・B/Cについて最初の3例、計36例をexamples.jsonへ保存。
元race_id・entry_id・bike・source行・全出走の3順位寄与から追跡できる。
例として2024年1着の最初のBはrace38202（source行325）、Cはrace37846（行99）。
Bでは予測3→1、公式3。車番3のutilityは1.2437324863→1.2283345656、
既存項目差+0.0025174330、STAT35直接寄与-0.0179153537。
Cでは予測2→5、公式5。車番5のutilityは0.9849014209→0.9952973969、
既存項目差+0.0036126805、直接寄与+0.0067832956。

2025年3着の最初のCはrace12577（行14）。車番7は直接寄与-0.0113996667でも
Primaryが5→7に変わって的中している。符号だけで的中変化を説明できない具体例であり、
softmax/decoderの他出走者・他順位への依存を無視した因果説明はしない。
既存16項目の再学習差とSTAT35の直接寄与を数学的に区別できたが、
どちらが的中増減を因果的に生んだか、将来も改善するかは本診断では分からない。
新しい仮説探索・CI・Gateは未実施。C1維持、C2追加Gate NOT_PASSED、正式採用変更なし。

## 変更範囲と終了状態

- 新規Command: `app/Console/Commands/Keirin/DiagnoseStat35C1Command.php`
- 独立診断8クラス: `app/Domain/Keirin/Backtest/Experiments/Stat35C1Diagnostic/` の Contract/Sources/Reader/Utility/Transitions/UtilitySummary/LineWriter/Builder
- 新規テスト: `tests/Feature/Stat35C1DiagnosticTest.php` と `tests/Support/Stat35C1DiagnosticFixture.php`、`stat35-c1-diagnostic-memory.php`
- 文書: 本書とMASTER PLAN v1.42のみ。

既存アプリコード、モデル、入力、予測、comparisons、Gate、旧manifestは変更なし。
診断のアプリDB接続・本番DB変更/HTTP/Raw/2026実データ/再学習/新予測/新CI/Gateは0。
重複管理と人工テストのローカルSQLite利用は、この禁止対象とは区別する。
開始・終了HEADは `d2ac7b6e2a9a75d4cb503fb269900dc626e1276e`、未コミットでコード・結果レビュー待ち。
commit/push/PR作成/mergeなし。次工程を自動実行しない。
