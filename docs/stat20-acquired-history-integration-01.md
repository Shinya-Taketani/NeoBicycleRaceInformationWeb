# STAT-20-ACQUIRED-HISTORY-INTEGRATION-01

## 1. 到達点・開始状態

2026-10-30。PR127 MERGEDをローカルmerge履歴で確認。開始clean main/HEAD/ローカルorigin/mainは `80bfbcd2482fa1c3a65994df9d4158f8e3e1c9d8`。レビュー対象 `c6ea6ce6805476fa11052df2203779e647537691` とのファイル差分なしを確認し、未使用の `feature/stat20-acquired-history-integration-01` を作成した。fetch/外部GitHub照会・add/commit/push/PR/mergeは行わない。

新STAT20のPHP経路、lineage保存、16/27日時hash修正、45実Rawの天候原項目投影を製造・検証した。限定資料での保存/再読込/再現が成立し、未コミットでレビュー待ち。全46STATの全要件完成・本番適用・confidence推定・予測採用・精度改善ではない。

```yaml
current_phase: STAT-20-ACQUIRED-HISTORY-INTEGRATION-01
state: READY_FOR_REVIEW
historical_as_of_available: false
prediction_use: NOT_AUTHORIZED
formal_adoption: false
points: null
confidence: null
method_b1_status: PROPOSED_NOT_APPROVED
method_selected: NONE
selected_lambda: NONE
formal_b1_data_acceptance_status: NOT_RUN
production_db_access: 0
production_write: 0
http: 0
2026_race_access_this_phase: 0
```

## 2. 原本・互換性と版

AGENTS、MASTER PLAN、既存STAT20/新5STAT/Parser/公式結果Reader/Raw保存契約を確認した。要件と残タスク原本はファイル名v1.0(2)だが本文v0.1、取得可否4分類はドラフトv0.1。絶対パス/bytes/SHAは今回証跡の `source-references.json` に保存し、旧原本と同じSHAであることを確認する。SCR-STAT-20/21のOPENを全要件DONEへ変更しない。

| 対象 | 今回の契約 | 保持するもの |
| --- | --- | --- |
| 旧STAT20 | STAT-20-existing-db-v1 | 旧15項目/追加14run/135行上限、旧コード・保存行は不変 |
| 新STAT20 | STAT-20-acquired-history-v2 | 別Command/Options/Input/Repository/Calculator/Service。旧本人照合は15項目だけで合成再利用 |
| 09/25/44 | 既存計算v2 | 数値/status/quality/hash不変 |
| 16/27 | 計算v3 | 6条件距離式・等重み・時間減衰なしのv1式は不変 |
| 共通Support | ACQUIRED-HISTORY-JST-v2 | 既存のJST/暦/対象除外は不変 |
| hashコピー | SIMILAR-CONDITIONS-INSTANT-HASH-v3 | 4日時のみ正規化、元DTO/保存evidence不変 |
| 保存Service | ACQUIRED-HISTORY-RACE-AUDIT-v3 | 数値/status/quality/hashを保存時に改変しない |
| lineage | ACQUIRED-HISTORY-STAT01-LINEAGE-v1 | run parametersと新規結果evidenceの両方で検証 |
| 天候投影 | STAT-21-SAVED-PJ0326-RAW-FIELDS-v1 | 原文・存在/型・数値表記・未確認単位/時刻を分離 |

同じ09/25/44計算version/input_hashにも保存証跡契約の異なる結果が存在する。旧結果の証跡を後付け更新せず、新Serviceで新規runを作る。`stat01_run_id`/`stat01_input_hash`は実options/out.targetから保存し、同名キーの矛盾は例外とする。旧Serviceのrunは新STAT20で保存lineage契約未対応として拒否する。

## 3. 新STAT20の監査契約

旧15の01/07/08/10/11/12/23/24/26/31/32/33/39/41/42と新5の09/16/25/27/44を監査する。STAT20自身を含めず、計算versionを20件の完全一致allowlistで制限する。
STAT41は1物理RACE行、他19はRACE_ENTRY。最大9車×19＋1＝172行、173行目で拒否。追加run最大19、重複STAT/run・任意version・最新runの自動選択は許可しない。

元STAT01は終端/finished/error0/processed=target/保存entry件数一致が必要。他runの終端PARTIALLY_SUCCEEDEDはerror/itemも監査する。BACKFILL mode、元STAT01 run、本人/race/entry/bike/subject/input_as_of/hash形式/status/quality、新5の親hash・run/result両lineage・利用制限を照合する。

| 条件 | 保存/失敗の扱い |
| --- | --- |
| run未指定 | SOURCE_RUN_NOT_SELECTED、DATA_SOURCE_PENDING。分母から消さない |
| run選択済み・結果なし | SOURCE_RESULT_MISSING。通常欠損として記録 |
| 上流FAILED item、または失敗帰属不明のrun＋結果欠落 | UPSTREAM_FAILURE_OR_UNATTRIBUTED_MISSING、監査結果INVALID_INPUT。正常欠損に隠さない |
| NO_HISTORY/PARTIAL_HISTORY/PARTIAL/NOT_APPLICABLE | 元statusを個別保持。N/Aだけ20項目分母から除外 |
| 上流INVALID_INPUT、保存された当該/未来履歴 | ブロック理由付きINVALID_INPUT |
| version/lineage/本人/重複/不正JSON | 明示的なレース処理エラー、保存0・FAILED item・Command exit1 |

レース単位で結果と成功itemを同一transactionに保存し、commit後だけ累計を加算する。例外はレース全体rollback、FAILED item/原因を記録して後続レースへ継続。iterator等の外側エラーは停止し、既に記録した同じ原因を二重加算しない。runはfinallyで実保存件数に一致する累計/終端へ更新する。監査自体の成功は上流の不正を見逃した意味ではなく、監査INVALID_INPUTを正しく保存できた場合Commandは0となり、技術的な処理失敗は1となる。
dry-runは同じ計算件数を返すがrun/item/resultを書かない。SQL監視で書込み0を確認した。

### 保存証跡から確認できること・できないこと

旧配置のhistory_min/max、DAY/COUNT windows、sample_count/window_complete等と、新配置の保有期間/年/段階/失格/四半期・16/27のhistory_references/条件充足/制限を専用adapterで読む。保存された参照の対象/本人と時刻を照合し、既知の未来/対象混入をブロックする。事後fetched_atは履歴イベント時刻と別分類にし、AFTERだけで履歴が未来とはしない。
原履歴時刻が保存されていない箇所はNOT_RECORDED、09/25/44の一部日時だけでは全履歴を検証したとしない。保存履歴全件・Raw・公開時点の独立証明はNOT_VERIFIED。Calculatorが未来を除外する契約と、保存資料からその全参照を再検証できることを分離する。
充足率は選んだ20上流の保存結果状態だけの比率。6条件C、正解率、confidence、信頼重み、46要件の完成率とは異なる。raw/effective pointsとCONFIDENCEはNULLを維持する。

## 4. STAT16/27のP3

原因はSimilarityConditionsRepositoryが返す非空raw/sourcesの日時原文をそのままhashしていたこと。hash用コピーの対象/参照条件にある `raw.scheduled_start_at` と `sources.fetched_at` だけをUTC整数秒＋小数秒の `U.u` へ正規化する。offset尊重、naive保存日時は明示的にJST。date-onlyのmeeting_startには触れない。
欠落キー/null/空文字/条件自体欠損を区別し、不正な非空日時は例外。日時以外の原値・参照・版・理由・比較内訳は維持する。汎用HasherとRepositoryは変更しない。
実Repositoryから対象/履歴を取得する人工境界テストで、4位置のUTC/JST同一瞬間はfeatures/status/quality/hash一致、evidenceは各原文保持。1秒/1microsecond差、非日時原値・source版の差はhash変更。暦/年・四半期・6条件算術/途中失敗の既存回帰も維持した。

## 5. 実資料受入と再現

証跡root: `/home/shinya/neo-keirin-artifacts/stat20-acquired-history-integration-01/run-20261030-c7xRlke2/`。
`acceptance-dZDILxLM/logs/` にPR127最終SQLiteを1回だけ作業用複製した。レビュー添付用の追加コピーはしない。

| 旧資料 | 固定SHA-256 |
| --- | --- |
| PR127 manifest | 834beb3c0a89c3379fd52d32d1ec53fbfdc129e2ee6d263a0d5699c2b574b94a |
| PR127 SQLite | a6760f3f2ea51b525f1b65c0cf7e1d56d6d0dc1c76ec27f39e32eb9d47456b15 |
| 45Raw source-manifest | d34576ecd4769e822393e07fc9f983512bc355facb0233268cbb970c922ae082 |

旧manifest/COMPLETE/各seal・Raw/import/logを検証。既存843結果/111run等のコピー済み旧行と業務表のSTART/END digest、元ファイルのSHA、実行コードsealは不変。snapshotをDBから再取得せず、2026開催を含まない既存隔離資料を再利用した。

| 対象 | 元01 | 旧14source run（07,08,10,11,12,23,24,26,31,32,33,39,41,42順） | 新5source run（09,16,25,27,44順） | 新20 run |
| --- | --- | --- | --- | --- |
| race12773、2025-01-04、7出走 | 1 | 7,8,2,3,4,9,5,6,10,11,12,13,15,14 | 112,114,116,118,120 | 122,123 |
| race12901、2025-01-04、9出走 | 42 | 48,49,43,44,45,50,46,47,51,52,53,54,56,55 | 124,126,128,130,132 | 134,135 |

元run/対象/本人件数を実照合し、source群を混ぜず2回目も同群を使用した。source物理行は134/172、新STAT20は4 run/32行（PARTIAL32）、failure0。新5は各2回の20 run/160行（VALID32/PARTIAL128）。比較80件はfeatures/status/quality差0、09/25/44 hash同一、16/27はv3の意図したhash変更、新契約同士はhashも一致した。run/item/result全24組の件数一致。全2022～2025レースを計算した結果ではない。

受入実行: 128M、peak75,497,472bytes、3.727183秒、exit0。別PHPの `verify-1pzxWiP0/logs/` では受入SQLiteをread-only＋query_onlyで開き、保存済み32行を同sourceから再計算照合し、45天候を再投影、書込み0、SHA不変。128M、peak75,497,472bytes、0.595238秒、exit0。実行識別子を計算結果の同一性へ混ぜず、元証跡の版を今回版へ再封印しない。

## 6. STAT21の45実Raw

既存2024年40履歴＋失格5の45レース/45import/45logを対象とした。既存OfficialMethodHistory Readerで公式結果の対象日/場/R・本人・確定状態・import/log/Raw SHA/sizeと保存日時を照合し、既存RawReader/Parser/EmbeddedJsonExtractorを再利用する。新Serviceは検証済み結果から天候原項目だけを投影し、URL/encp/Raw全文は出力しない。

天候原文は晴28/曇12/雨5。tenki/husokuとも欠落/null/空文字/不正0。husokuは正の数値表記42・明示0表記3、数値token範囲0～5。単位を裏付ける項目/対応ラベルは今回の投影契約で証明できず `unit=null, unit_status=NOT_VERIFIED`。m/sや単位付き風速と呼ばない。
取得日時・Parser版・Raw SHA/bytes/path・import/log ID・ページlastUpdateTimeは保持するが、後二者を気象実観測時刻に置換しない。実観測時刻はNULL。2026年取得の2024結果ページでありRETROSPECTIVE_ONLY、予測利用不可。原文の型/存在状態・明示0と欠損も保存する。
`stat21-weather-projection.json` の保存/再読込/同一プロセス再投影と、別プロセス再投影45/45が一致した。風向/路面/観測地点/単位/当時公開時点の証明、風速区分/類似距離/減衰/適性Calculatorは未実装。STAT21予測版の完成とは数えない。

## 7. テスト・実行条件

限定12ファイルの最終実行は `tests-ld0GLgXJ/logs/`、**171 passed / 3,451 assertions、failure/error/warning/skip0、exit0、7.00秒**。新規39/336と既存132/3115の内訳。PHP8.5.4、Artisan/Collision→PHPUnit12.5.31、本体128M/peak78,118,912bytes。新規回帰と既存STAT20・暦/6条件・公式Readerを含む。既存テスト削除/緩和/skip/警告抑制なし。直前の171/3451・7.02秒のログも履歴として保持する。
初回43/609成功、追加回帰時の22 error/149 passed/1529 assertions/exit2は実Fixture未bindによる隔離起動条件の失敗で、記録を保持した。封印済み6レース/開催資料だけをro-bindして上記171件成功。原本を変更して通したものではない。旧全体4,313件や87件、ユーザー側の警告件数を今回値へ読み替えない。

実コマンドの正本は各 `runner.sh` / `command.txt` / stdout/stderr / JUnit/events / measurement。代表コマンド:

```bash
php -d memory_limit=128M artisan keirin:statistics:build-stat20-acquired-history \
  --stat01-run-id=1 --race-id=12773 \
  --source-run-id=7 --source-run-id=8 --source-run-id=2 --source-run-id=3 \
  --source-run-id=4 --source-run-id=9 --source-run-id=5 --source-run-id=6 \
  --source-run-id=10 --source-run-id=11 --source-run-id=12 --source-run-id=13 \
  --source-run-id=15 --source-run-id=14 --source-run-id=112 --source-run-id=114 \
  --source-run-id=116 --source-run-id=118 --source-run-id=120 --chunk=10 --dry-run
php -d memory_limit=128M artisan test <command.txt記載の限定12ファイル> \
  -d memory_limit=128M --bootstrap tests/Support/stat38-memory-bootstrap.php \
  --display-all-issues --do-not-cache-result
```

上は隔離SQLite用の例で、本番コマンドの実行承認ではない。実資料の保存は記録済みPHP runnerが同じService/Optionsを呼んだもので、このdry-run例の本番実行はしていない。
bwrapの原本read-only・network namespace遮断・人工.env/storage/cache・隔離SQLite・人工Desktop/Trashと既存安全helperを使用した。後片付けは作成オブジェクトのpathだけをretire、永久削除なし。旧危険cleanupを含む通常全体テストは起動しない。変更PHP19ファイルの構文・限定Pint、git diff --check、正式名46件/相対リンク/表/コードブロック/凍結依存の文書検証はexit0。中間Pintと掲載順比較の検証スクリプト失敗も上書きせず保持し、最終品質ログ・全22ファイルの差分は同rootのquality実行へ保存する。

## 8. 未完了・次工程

[全46項目表](stat-existing-data-completion-01.md#10-全46statの現在地stat-20統合工程2026-10-30)は入力不足と未確定方式、未実装拡張と実資料検証不足を分離した。具体的な製造残件にはSTAT09保有期間内の級班/grade別記述件数がある。ライン10項目や事前オッズを捏造せず、学校同期・風向/単位/実観測時刻・構造有効期間/地理/ギヤ等を不足として残す。
次の許可作業は今回コード・証跡・文書のレビューだけ。B1/親lambda/H1-U・confidence/配点・モデル採用・本番保存は未承認のまま。ユーザーの後続方針である2026終了済みレースの時系列検証は別工程として保持し、今回アクセス0。永久禁止の新ルールへ拡張しない。
