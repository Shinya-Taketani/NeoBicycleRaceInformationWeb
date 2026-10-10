# C1-COMPOSITION-TEST-ENV-01

## 範囲と修正

2026-10-11、PR97受入・マージ完了後のclean main/ローカルorigin/main
`1eed67740986aae7e52096a935b60015b32d7de1`から
`fix/composition-http-test-env-01`を作成。fetchなし、main直接編集なし。

`CompositionResultViewTest`と`CompositionArchiveTest`の既存`createApplication()`の
`try`内で、記録済み人工rootの`.env`をKernel bootstrap前に排他的新規作成する。
内容は `# Isolated test fixture. No application secrets.` と改行だけ。
書込みバイト数とflush成功を確認し、既存例外経路を維持する。
`useEnvironmentPath($root)`、設定値、アクセス制御、予測・集計期待値は変更しない。
各既存代表テストに、参照先・存在・内容の3 assertionsだけを追加した。
共通helper・アプリ本体・退避処理・本物の`.env`は変更していない。

## 今回の検証

| クラス | ケース | assertions | 欠落.env警告 | その他警告 | 失敗/エラー | 終了コード |
| --- | ---: | ---: | ---: | ---: | --- | ---: |
| CompositionResultViewTest | 26 | 151 | 0 | 0 | 0/0 | 0 |
| CompositionArchiveTest | 45 | 517 | 0 | 0 | 0/0 | 0 |

両クラス各1回、Artisan / Collision / PHPUnit経路で実行。
`/usr/bin/php8.5`、PHP 8.5.4、PHPUnit 12.5.31。
Viewは7.551571秒、Archiveは2.409136秒（隔離起動込み）。
JUnitの件数・assertions・失敗・エラー・skipと、全イベントおよびstderrを確認。
skip・notice・deprecation・riskyも0件。変更PHP2ファイルの構文検査、限定Pintはexit0。
差分検査は`git diff --check`で行い、変更対象は許可された4ファイルだけとする。

実際のArtisan引数（LOGは下記の実在する新規証跡ディレクトリ）:

```bash
/usr/bin/php8.5 artisan test tests/Feature/CompositionResultViewTest.php --display-all-issues --log-events-text "$LOG/view-events.txt" --do-not-cache-result --no-ansi --log-junit "$LOG/view-junit.xml"
/usr/bin/php8.5 artisan test tests/Feature/CompositionArchiveTest.php --display-all-issues --log-events-text "$LOG/archive-events.txt" --do-not-cache-result --no-ansi --log-junit "$LOG/archive-junit.xml"
```

既存の代表警告再現と同じbwrap隔離を使用。原本`.env`・storage・共用cache・正式成果物を
不可視/読取り専用にし、network namespaceとDB socketを遮断。
新規ログ・runtime・人工Desktop/Trashのみ書込み可能。
`APP_ENV=testing`、`APP_DEBUG=false`、無効DB接続名、空DB_URL、cache/viewの専用パス、
`LOG_CHANNEL=stderr`、既存`PAO_DISABLE=1`は前回警告再現時のまま。
CLI memory_limit=-1、error_reporting=22527、timezone=UTCも維持。
今回、新しい警告抑制・error_reporting変更・依存無効化は追加していない。
標準入力は`/dev/null`、stdout/stderrは別保存。元の全体実行の完全な環境・TTY条件は未確認。
これらの隔離差は`conditions.json`、完全な起動argvは各`execution.json`に保存した。
後片付けは保持した作成オブジェクトのpathを既存helperへ渡し、人工Trashへ退避。
永久削除・既存Trash整理は行わない。

証跡:
`/home/shinya/neo-keirin-artifacts/c1-stat35-composition-final-01/composition-test-env-review-20261010-220455-e01b5a01/`

`view-tests/`・`archive-tests/`にstdout/stderr・日時・終了コード、直下にイベント/JUnitを保持。
構文/Pint・Git差分も同じ証跡配下へ保存。開始は2026-10-11 07:03:39 JST、30分上限内。

## 過去記録との区別

過去のユーザー全体実行: `php artisan test`、71 warnings / 9 skipped /
3386 passed / 32078 assertions。PR97のマージを妨げない残件として扱った記録を保持する。
前回の代表2件では、人工rootの`.env`欠落が
`vendor/vlucas/phpdotenv/src/Store/File/Reader.php:73`の警告として確認済み。
全71件の過去本文・当時の全環境は未確認であり、すべて同じ原因と断定しない。
今回の対象71ケース警告0を、通常全体テストの警告解消へ読み替えない。
旧年次生成・原本一致・モデルhash・過去の実行件数は当時の記録のまま。
関係しない旧tearDown残件は今回変更していない。

通常全体テスト・年次生成・実照合execute/reproduce・学習・予測再生成・repackageは各0回。
DB・Redis・外部HTTP・2026結果参照0。未コミットの文書/テスト差分だけをレビュー待ちとし、
次工程へ進まない。
