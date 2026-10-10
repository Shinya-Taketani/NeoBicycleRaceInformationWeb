# C1-STAT35-COMPOSITION-VIEW-01

## 状態と許可範囲

- 状態: `COMPOSITION_LOCAL_VIEW_VERIFIED_AWAITING_REVIEW`。PR95は受入・マージ完了。
- 開始main/ローカルorigin/main: `40c76900c955dba2ab29d7f28eb96c4a14c43d14`、branch: `feature/c1-stat35-composition-view-01`。fetchなし、開始時clean。
- Laravel/Bladeと通常CSSによるローカル閲覧GETだけを追加。トップ `/` と既存middleware、モデル/依頼/照合契約、共有計算、provider/bootstrap、依存、`.env`/storage/vendorは変更しない。
- 実データ学習、推論、予測生成、結果照合execute/reproduce、再パッケージ、DB/Redis/Raw/年別資料、Migration、外部通信、2026レース参照は行わない。
- `historical_as_of_available=false`、正式採用/freeze/LIVE=false、points=nullを維持。学習期間内の10件の表示確認であり、将来精度の評価ではない。環境全体の復旧確認でもない。

## 読取り契約と表示

専用設定 `config/composition_result_view.php` に次の一束だけを固定する。HTTP query/bodyからpath、年、モデル、hashを選択できない。探索・fallback・再生成なし。

```text
RESULT_ROOT=/home/shinya/neo-keirin-artifacts/c1-stat35-composition-final-01/result-store-01-rebuild-20261010-Ih1vik
EVALUATION_ID=dev-composition-2025-fixed10-result-rebuild-01
manifest bytes=29058
manifest sha256=2fd97547840c3a14b3703d431da49fd670e677c4b72ac1eb5aeddc726aa4f086
```

`Reader::read()`は既存Result Storeのroot確認、外側manifest固定seal、`Store::verify()`、終了sealを通す。一覧はそのsummary/racesから必要項目だけをPresenterで投影する。同一HTTP内の照合束検証は一回で、テンプレート行ごとの再検証はない。

詳細は検証済み対象集合にあるrace IDのみ。束内コピーrequestを既存Request Storeで検証し、固定コピーmanifestとjoinedのsealも確認する。周辺確率はコピー済みprediction、rank/statusはjoined contextを使用し、出走IDと車番を両方照合する。元request/package/年別labelsは開かない。保存utilityによる既存検算は実行するが、Package/Predictor/Forwardの再推論はない。

保存Primaryを保持し、P1/P2/P3は無条件周辺確率を%表示へ丸めるだけ。winner条件付きQ2/Q3とは異なる。出走IDを選手IDと呼ばない。公式順位は車番集合を保持し、同着/順位なしを不一致にしない。開催名・レース日・選手名は未収録。照合束の保存日時は原記録のタイムゾーン付き文字列で表示し、レース日時へ転用しない。

全画面に「構成モデル 予測・結果確認」「開発用・2025年の保存データ」「学習期間内の照合です。将来レースの精度を示すものではありません」「発走前取得時点の保証なし／LIVE利用未承認」を表示する。Blade escape、no-store、内部path/trace非表示。書込みボタン・子プロセス・Artisan呼出し・永続キャッシュなし。

## 起動方法と確認URL

確認用launcherは今回専用ディレクトリの `server.php`。process-localで空の環境読取り先と専用bootstrap/storage/compiledへ向け、DB/Redis/外部HTTP/推論の解決を拒否する。共有設定・APP_KEY・cacheを変更しない。

```bash
cd /var/www/NeoBicycleRaceInformationWeb
VIEW_REVIEW=/home/shinya/neo-keirin-artifacts/c1-stat35-composition-final-01/composition-view-review-20261010-2Cqy1Fv4
APP_ENV=local APP_DEBUG=false C1_COMPOSITION_VIEW_ENABLED=true PAO_DISABLE=1 \
php -d memory_limit=128M -S 127.0.0.1:8765 \
  -t /var/www/NeoBicycleRaceInformationWeb/public "$VIEW_REVIEW/server.php"
```

これは実際に検証したサーバー引数。検証時はさらにbwrapでネットワーク/資料を隔離した（最終全argvは `browser-04/execution.json`）。検証後、起動したserver/Chromeだけを停止済み。手動閲覧では上記を再起動し、使用後Ctrl-Cで停止する。8765が使用中なら既存serverを停止せず、未使用portへ変更する。

- 一覧: `http://127.0.0.1:8765/development/keirin/composition-results`
- 詳細: `http://127.0.0.1:8765/development/keirin/composition-results/37750`
- 公式3着なし: `http://127.0.0.1:8765/development/keirin/composition-results/12542`

flag既定false、実閲覧はlocal+実REMOTE_ADDRが127.0.0.1/::1の場合だけ。人工テストのtestingは許可。production、非loopback、無効flagはReader解決前404。Host/X-Forwarded-Forは許可根拠にしない。不正/対象外race IDは404、欠損/破損/pin不一致は503「保存結果を確認できません」。セッション/Cookie/CSRF等の除外はこの2GETのみ。Responseを直接生成し、Laravelのresponse factory経由のDBセッション解決も避ける。

## 実表示の確認結果

| 指標 | 分子/分母 | 表示率 |
| --- | --- | --- |
| 1着 | 4/10 | 40.0000% |
| 2着 | 3/10 | 30.0000% |
| 3着 | 2/9 | 22.2222% |
| 位置Hit@3 | 8/27 | 29.6296% |
| Primary完全順序一致 | 0/9 | 0.0000% |

保存summaryとDOM上の5集計が一致。Supporting完全順序とは別指標。37750はPrimary2/1/6、実1/5/6、不一致/不一致/一致。12542は3着「公式順位なし」/「評価対象外」と保存理由を表示、1/2着は個別評価のまま。10件の保存順37750,12542～12550を維持。

一覧/37750/12542は実HTTP200、未知99999は404、全応答no-store/Set-Cookieなし。記録したPHP peakは一覧6,291,456 bytes、詳細4,194,304 bytes、上限128M。Chromeは既存インストールを使用し依存追加なし。1440×1000、390×844で各3画面、計6スクリーンショットを保存。ページ全体の横溢れなし、表は横スクロール、一覧リンク→37750詳細遷移成功。出走ID/車番/保存P1/P2/P3全列をDOMと原predictionで比較し一致。

実確認は、原本filesystem read-only、元モデル/request/年別資料をartifact rootごと遮断、復元.env/storage/共有cacheとDB unix socketを隠し、network namespace分離の環境。許可したresult rootだけro-bind、今回の証跡/runtimeだけ書込み可。DB/推論が利用できるから成功した試験ではない。

## 人工回帰と保持

- 新規HTTP: 26 tests / 148 assertions、6.854秒（wrapper6.932586680秒）、peak50.50MiB、128M、exit0。
- 直接関連の既存人工照合: 44 tests / 229 assertions、5.186秒（wrapper5.264844271秒）、peak32.50MiB、128M、exit0。
- 限定Pint7PHP、7PHP構文成功。通常全体テストは指示どおり未実行。
- 無効flag/production/非loopback/偽proxy/IPv6、不正race、欠損/破損/pin、HTML escape、同着/公式順位なし、NULL/0、1HTTP1検証、トップroute不変、DB/Redisセッション解決なしを確認。
- 初回テスト起動/テスト検査順の失敗と、実HTTPのresponse factory依存・ブラウザ検証スクリプトの選択式失敗は別ログで保持。最終成功へ読み替えない。
- 人工一時領域は既存helperの保持オブジェクトのpathだけをretireへ渡す。実Desktop/Trashは人工bindで遮断し、今回作成した領域だけ退避。削除/旧証跡整理/fallbackなし。
- 原本117ファイル（evaluation116+STORE）とモデル/依頼/結果照合の既存Contract::code()はSTART/END一致。旧manifest、code hash、モデル、予測、成功記録を更新して通さない。
- PR95後の3386 passed / 31416 assertions / 9 skipped / 322.99秒は後続ユーザー実行記録であり、今回Codexが実行した全体テストではない。

追加確認は累積30分上限。検証コマンドの実測は各execution.json、失敗試行を含む集計はvalidation-accounting.jsonへ記録。実装時間とコマンド実行時間は別扱い。読取り/画像確認/差分等を含む全区間の正確な秒計測はなく、非コマンド確認には保守的な時間枠を別記録する。

## 提出と未確認

証跡: `/home/shinya/neo-keirin-artifacts/c1-stat35-composition-final-01/composition-view-review-20261010-2Cqy1Fv4/`。
最終画面: `browser-attempt-04/screens/`、DOM/HTTP/自身のプロセス停止記録を同じ試行ディレクトリに保持。小型ZIPは `C1-STAT35-COMPOSITION-VIEW-01-review.zip`、実在/サイズ/SHAは `review-archive.json`。新規を含む差分、画面、テスト/実行方法/source識別を収録し、モデル・年別資料・秘密情報は収録しない。

未確認: 全環境復旧、将来精度、歴史的発走前取得時点、通常全体回帰。次はこの画面/コードレビューのみ。未コミットで停止し、LIVE/2026/次工程へ移行しない。
