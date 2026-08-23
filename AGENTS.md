# AGENTS.md

## 1. このファイルの目的

このリポジトリで作業する Codex および開発者向けの共通指示です。

- 変更前に必ずこのファイルを読むこと。
- 指示が競合する場合は、ユーザーの最新指示、`AGENTS.md`、既存仕様書、既存コードの順で優先すること。
- 不明点を推測で埋めず、コード・テスト・取得元HTMLなどの根拠を確認すること。
- 作業結果、変更ファイル、実行コマンド、テスト結果、未解決事項は日本語で報告すること。

---

## 2. プロジェクト概要

競輪の選手情報、開催情報、出走表、レース結果、払戻情報を収集し、複数の独立した予測エンジンで分析するWebアプリケーションです。

将来の予測エンジンは次のとおりです。

- 四柱推命エンジン
- 九星気学・現代天文節気版
- 九星気学・天保暦準拠版
- 高島嘉右衛門式易占エンジン
- 戦略・レース構造評価エンジン
- 履歴統計エンジン

ただし、現在の最優先作業は予測機能ではなく、**スクレイピング基盤、選手情報取得、レース結果取得**です。

---

## 3. 開発環境

- Backend: Laravel 13
- PHP: 8.4
- Database: PostgreSQL 18
- Frontend: Vue 3
- JavaScript: TypeScriptを基本とする
- Test: PHPUnit / Laravel Feature Test / Unit Test
- Timezone: `Asia/Tokyo`
- Character encoding inside application: UTF-8

コードでは次を基本とします。

- `declare(strict_types=1);`
- PHPの型宣言、Enum、readonly DTOを使用する
- 日時は原則として `DateTimeImmutable` または Laravel の immutable date を使用する
- DB内部IDと外部サイト上の識別子を分離する
- PostgreSQL固有機能を使う場合は、目的をコメントまたは仕様に残す

---

## 4. 現在の作業スコープ

### 対象

1. 男子競輪の選手一覧取得
2. 男子競輪の選手詳細取得
3. 男子競輪のレース結果取得
4. 払戻情報取得
5. 生HTML保存
6. Parser、DTO、DB保存処理
7. バッチ履歴、失敗履歴、再実行機能
8. ParserのFixtureテスト

### 対象外

- ガールズ競輪の本実装
- 予測点数の計算
- 占術計算
- オッズによる能力評価
- PDF出力
- フロント画面の本実装
- 大規模並列取得

ガールズ競輪を検出した場合は、無理に男子用Parserへ通さず、`SKIPPED_UNSUPPORTED_CATEGORY` 等の明示的な状態で記録します。将来拡張できるDB構造にはしておきます。

---

## 5. 実装原則

### 5.1 責務分離

必ず次の流れに分離します。

```text
Fetcher
  -> Raw response storage
  -> Parser
  -> DTO / validation
  -> Application service
  -> Repository / database
```

- FetcherはHTTP取得のみを担当する
- Raw storageはレスポンス原文と取得メタデータを保存する
- Parserは保存済みHTMLまたは文字列だけを入力とし、ネットワークへ接続しない
- DTOは解析結果を型付きで保持する
- Serviceはトランザクション、upsert、履歴更新を制御する
- Artisan Commandは引数受付とService呼び出しに限定する

スクレイピングと予測計算を同じCommand、Service、Transactionに混在させてはいけません。

### 5.2 推奨ディレクトリ

既存構成がある場合は整合させます。新規構築時の基準は次です。

```text
app/
├── Console/Commands/Keirin/
├── Domain/Keirin/Scraping/
│   ├── Contracts/
│   ├── DTO/
│   ├── Exceptions/
│   ├── Fetchers/
│   ├── Http/
│   ├── Parsers/
│   ├── Services/
│   └── ValueObjects/
├── Models/
└── Repositories/

tests/
├── Feature/Console/Keirin/
├── Unit/Domain/Keirin/Scraping/
└── Fixtures/Keirin/
```

候補クラス名：

- `KeirinHttpClient`
- `RawResponseStorageService`
- `PlayerListFetcher`
- `PlayerListParser`
- `PlayerDetailFetcher`
- `PlayerDetailParser`
- `PlayerSummaryDto`
- `PlayerDetailDto`
- `GradeNormalizer`
- `PlayerSyncService`
- `PlayerResolverService`
- `RaceResultFetcher`
- `RaceResultParser`
- `PayoutParser`
- `RaceResultImportService`

---

## 6. HTTP取得規則

- Laravel HTTP Clientを基本とする
- 接続タイムアウトと全体タイムアウトを明示する
- リトライ回数と指数バックオフを設定する
- User-Agentを明示する
- 429、5xx、タイムアウト、文字コード変換失敗を区別する
- 初期実装では並列取得しない
- 取得間隔を設け、外部サイトへ過剰な負荷を掛けない
- 利用規約、robots.txt、公開範囲を確認し、アクセス禁止領域を取得しない
- URL、クエリパラメータ、HTMLセレクタは現在の実ページで確認する
- 古いコードにあるURLやセレクタを無検証で流用しない

レスポンス取得に失敗した場合、ダミーHTML、ダミー選手、ダミーレースを生成して成功扱いにしてはいけません。

---

## 7. 生HTML保存

解析前に可能な限り生レスポンスを保存します。

保存候補：

```text
storage/app/private/scraping/raw/
└── {source}/
    └── {yyyy}/{mm}/{dd}/
        └── {request-key}-{fetched-at}.html
```

最低限、次を記録します。

- 取得元URL
- HTTPメソッド
- HTTPステータス
- 取得日時
- Content-Type
- 検出文字コード
- UTF-8変換結果
- レスポンスサイズ
- SHA-256ハッシュ
- 保存ファイルパス
- Parserバージョン
- 再試行回数
- エラー種別

同一レスポンスの重複保存を避ける場合でも、取得履歴自体は失わないようにします。

---

## 8. 文字コード規則

外部HTMLがShift_JIS、Windows-31J、CP932等の場合があります。

- 文字コードを検出またはレスポンスヘッダー・metaから判定する
- 変換元文字コードを記録する
- UTF-8への変換は一箇所に集約する
- Parser内部で場当たり的に再変換しない
- 変換失敗を空文字や成功として扱わない
- 元レスポンスは変換前の状態でも追跡可能にする

---

## 9. Parser規則

- DOMDocument、DOMXPath、またはDOMベースのライブラリを使用する
- 正規表現だけでページ全体を解析しない
- 空白、全角・半角、改行、`&nbsp;`を正規化する共通処理を用意する
- 必須項目欠損と任意項目欠損を区別する
- 不明値を架空の初期値へ変換しない
- 不明な級班をA3などへ既定変換しない
- 生年月日不明を `1990-01-01` 等で補完しない
- 着順不能、失格、棄権、未完走、欠場、取消、同着を別状態として保持する
- HTML構造の変化を検知した場合は明示的なParser例外にする

Parserは同じ入力に対して同じDTOを返す決定的処理でなければなりません。

---

## 10. DB設計の基準

初期の正規化テーブル候補：

- `players`
- `player_status_histories`
- `player_stat_snapshots`
- `racetracks`
- `races`
- `race_entries`
- `race_results`
- `race_payouts`
- `batch_runs`
- `batch_run_items`
- `scraping_fetch_logs`

原則：

- 主キーはDB内部IDを使用する
- 外部IDには一意制約を付ける
- 同じデータの再取得は冪等なupsertにする
- 履歴として必要な値を現在値で上書きしない
- 一括処理全体を巨大な単一Transactionにしない
- 1レースまたは適切な小単位でTransactionを分ける
- PostgreSQLの `INSERT ... ON CONFLICT` 相当を適切に利用する
- バッチ多重実行が問題になる箇所はAdvisory Lock等を検討する
- Migration実行をCommand本体へ組み込まない

結果状態の候補：

```text
UNAVAILABLE
PROVISIONAL
UNDER_REVIEW
CONFIRMED
CORRECTED
CANCELLED
```

選手のレース結果状態は、必要に応じて次を別項目で表現します。

```text
FINISHED
TIED
DISQUALIFIED
DID_NOT_START
DID_NOT_FINISH
WITHDRAWN
CRASHED
```

---

## 11. Artisan Command

初期コマンド名：

```bash
php artisan keirin:players:sync
php artisan keirin:races:import-results --from=YYYY-MM-DD --to=YYYY-MM-DD
```

必要に応じて次のオプションを追加できます。

```text
--limit-pages=
--resume=
--force=
--dry-run
```

規則：

- CommandにHTML解析やDB詳細処理を書かない
- 終了コードを正しく返す
- 成功件数、更新件数、スキップ件数、失敗件数を表示する
- 途中失敗後に再開できる
- 同じ期間を再実行しても重複登録しない
- `--dry-run`では永続化せず解析結果を確認できることが望ましい

---

## 12. テスト規則

### 必須テスト

- 保存HTML Fixtureを使用したParser Unit Test
- HTTP Clientの成功、タイムアウト、429、5xxテスト
- 文字コード変換テスト
- upsertの冪等性テスト
- 同じバッチを2回実行したときの重複防止テスト
- 途中失敗後の再開テスト
- Commandの終了コードと件数表示テスト

### レースFixtureで確認する条件

- 7車立て
- 9車立て
- 同着
- 失格
- 欠場・取消
- 未完走・落車
- 開催中止
- 複数種類の払戻
- 結果訂正
- HTML構造差
- 文字コード差

### テスト実行

```bash
php artisan test
```

対象を限定する場合：

```bash
php artisan test --filter=PlayerListParserTest
php artisan test --filter=RaceResultParserTest
```

テストを実行できない場合は、実行していないことと理由を報告します。未実行のテストを成功したと報告してはいけません。

---

## 13. 禁止事項

- 架空データを本物として保存する
- 取得失敗を成功扱いにする
- 不明値を適当な既定値で埋める
- スクレイピングCommand内でMigrationやSchema変更を実行する
- 巨大な単一CommandへHTTP、解析、DB、予測を集約する
- Parser内からHTTPアクセスする
- HTML全体を正規表現だけで解析する
- 予測ロジックをスクレイピング層へ追加する
- 既存ソースを検証せずコピーする
- 認証情報、Cookie、APIキー、個人情報をGitへコミットする
- `.env`をコミットする
- テストを削除または無効化して通過させる
- ユーザーの明示指示なしに大規模な設計変更を行う

---

## 14. 作業手順

1. リポジトリ構造、既存コード、Migration、テストを確認する
2. 対象サイトの現在のURL、レスポンス、文字コード、HTML構造を確認する
3. 生HTML Fixtureを保存する
4. DTOとParser Testを先に作る
5. Parserを実装する
6. FetcherとRaw storageを実装する
7. Service、Repository、Migrationを実装する
8. Artisan Commandを実装する
9. 冪等性、再開、異常系をテストする
10. 全テストと静的解析を実行する
11. 変更内容と残課題を報告する

最初のCodex作業では、範囲を広げすぎず、次を優先します。

```text
選手一覧取得
  -> 生HTML保存
  -> PlayerListParser
  -> PlayerSummaryDto
  -> Fixture Test
```

DB全体、レース結果、予測エンジンまで一度に実装しないでください。

---

## 15. 完了条件

タスクは、少なくとも次を満たした場合に完了とします。

- 要求された範囲が実装されている
- 責務分離が守られている
- ダミー・フォールバックで失敗を隠していない
- 再実行しても重複登録しない
- 生HTMLと取得メタデータを追跡できる
- Parserを保存Fixtureで検証できる
- 正常系と主要異常系テストがある
- 実行したテストが成功している
- 変更ファイル、コマンド、結果、未解決事項が報告されている

---

## 16. 作業報告フォーマット

Codexは最終報告を次の順で記載してください。

```text
1. 実装概要
2. 変更ファイル
3. DB変更
4. 実行コマンド
5. テスト結果
6. 未解決事項・既知の制約
7. 次に行うべき作業
```

エラーや未完了部分を隠さず、確認できた事実と推測を分けて記載してください。
