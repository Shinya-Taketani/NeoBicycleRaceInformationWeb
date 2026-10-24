# PRESTART-CAPTURE-OPERATIONAL-CONTRACT-01

## 1. 状態と開始根拠

- 作成日・外部情報参照日: 2026-10-24（Asia/Tokyo）。文書版: DRAFT-v1。
- 状態: `OPERATIONAL_CONTRACT_DRAFT_AWAITING_REVIEW`。本書の運用案・数値・新状態名・クラス名はすべて `PROPOSED_NOT_APPROVED`。未確認の根拠は `UNVERIFIED`、未決の値は `UNRESOLVED`。
- fetch/main pull --ff-only後のclean main/origin/main: `cbff8c5b6ce685bfc6d024fef9afdde3d14ff6a2`。GitHubでもPR121 MERGED、merge SHA一致、merged_at `2026-10-24T03:49:25Z`を確認。PR headはbf4d9e54。以前のOPEN・未コミット終了は当時の履歴。
- mainから未使用の `design/prestart-capture-operational-contract-01` を作成。今回の変更は本書とMASTER PLANのみ。PHP・設定・テスト・Migration・既存成果物は変更しない。
- 本書のレビュー承認だけで、実装・運用有効化・HTTP試行・本番保存・予測接続を許可しない。D1〜D8の決定、限定実装の別承認、コード受入、具体的な実行承認を分離する。

```yaml
current_phase: PRESTART-CAPTURE-OPERATIONAL-CONTRACT-01
current_engine_state: OPERATIONAL_CONTRACT_DRAFT_AWAITING_REVIEW
real_http_capture: NOT_RUN_NOT_AUTHORIZED
production_storage: NOT_RUN_NOT_AUTHORIZED
historical_as_of_available: false
prediction_use: NOT_AUTHORIZED
formal_adoption: false
official_publication_at: null
points: null
confidence: null
2026_holdout: FROZEN_NO_ACCESS
```

本書でいうHTTP実取得禁止は出走表・結果・会員ページ等のデータ取得。D3のための静的サイトポリシーの独立閲覧とrobots.txtの確認試行は別記録とする。2026開催のmetadata/Rawを参照しない。B1方法未承認、親lambda未選択、正式B1受入NOT_RUN、H1-U保留、C1/C2/BT・全STATの未確定配点・旧不採用判断・LIVE禁止を維持する。

## 2. 根拠と確認範囲

### 2.1 確定要件とMVPの境界

確定要件原本は `/home/shinya/ダウンロード/%E7%B5%B1%E8%A8%88%E3%82%A8%E3%83%B3%E3%82%B8%E3%83%B3%E8%A6%81%E4%BB%B6%E5%AE%9A%E7%BE%A9_STAT-01-46%E7%A2%BA%E5%AE%9A_v1.0(2).md`。表題内の初期版表記ではなく、各STAT節と末尾の確定状況を区別して確認した。

| 根拠 | 本契約への制約 |
| --- | --- |
| STAT-01、11〜86行 | 当時取得可能だった公式得点と入力snapshot。事後更新を遡及せず、欠損を0にしない |
| STAT-17、2007〜2131行 | 登録脚質は補助情報。決まり手分布・役割・有効性は別で、登録脚質からラインや戦法成功を断定しない |
| STAT-18、2133〜2285行 | 過去継続性と直前変更、情報源/Parser訂正と実変更を分離。ライン不明を新規ラインにしない |
| STAT-29、3687〜3820行 | 同レースの複数時点・時点別ライン、SOURCE_CONFLICT、予測後変更を別管理。出走者変更はSTAT-46へ分離 |
| STAT-39、4969〜5088行 | 予測時点の車番/枠番履歴と制度根拠。Raw枠欠落から公式非適用を推定せず、生勝率で加点しない |
| STAT-46、5904〜6051行 | 決定/公表/取得/予測/発走を区別し、本人影響/構造影響/不確実性を分離。差分だけで補充理由を断定しない |

残タスク原本 `/home/shinya/ダウンロード/スクレイピング残タスク_統計エンジン連携_STAT-01-46確定_v1.0(2).md` の2864〜2940行を照合した。

| ID | 原本の完了条件と今回の境界 | 状態 |
| --- | --- | --- |
| SCR-STAT-46-01 | 公式変更イベントの時系列、決定/公表時刻、不明区分の明示。今回公式イベント取得源は確定しない | OPEN維持 |
| SCR-STAT-46-02 | 変更前後差分再計算・非上書き・ライン/車番/車立て連鎖。出走表MVPだけで全条件完了としない | OPEN維持 |
| SCR-STAT-46-03 | 変更連鎖・依存STAT/旧新予測・stale検出/解消。今回再計算を起動しない | OPEN維持 |
| SCR-STAT-46-04 | 補充適応履歴と本人/構造影響分離。今回成績計算・補正を行わない | OPEN維持 |

`/home/shinya/ダウンロード/STAT-01-46_データ取得可否4分類_ドラフト_v0.1.md` はMVP着手可否の旧ドラフト。STAT01/39=区分1、17/46=2、18/29=3という当時の分類を、完成・歴史的時点適格・正式利用の承認と解釈しない。17/46等の保存MVPは後続実装で進展したが、元資料のDB未保存等の記述を現在の事実へ流用しない。

### 2.2 既存実装から確認した事実

以下は開始mainのソース読取り結果で、本番DB・外部endpointの現在動作を測定した結果ではない。

| 根拠（app/Domain/Keirin/Scraping/配下を基本とする） | 確認した事実・不足 |
| --- | --- |
| Http/KeirinHttpClient.php、DTO/FetchedResponseDto.php、config/keirin.php | connect timeout既定5秒/全体20秒、retry設定既定2、sleep1000ms。応答後のnowがfetchedAtで、要求開始・redirect証拠・Raw永続化完了はDTOにない。effective URI欠落時に元URLを返す。これを検証済み実効URIとしない |
| Fetchers/RaceLiveFetcher.php | race encpをPOST `/pc/racelive`、disp=PJ0315。PJ0326結果取得も持つため結果同期を収集adapterに使わない |
| Fetchers/RaceEntryListFetcher.php | day encpをGET `/pc/json`、type=JSJ017。race用encpと混用不可 |
| Services/ScrapingFetchService.php | bodyを保存後にHTTP異常を送出。3xx専用の非追従保証はない。中間retryの全試行証拠は保証しない |
| Services/RawResponseStorageService.php | 原SHA/bytes、Storage::put、変換、DB fetch-logの順。上書き防止・fsync時刻・ファイル/DB一体性を保証しない |
| Services/RaceListSyncService.php | PJ0301/JSJ001/JSJ017から現在テーブルを更新。予備取得もHTTPであり、パイロット予算外で呼ばない |
| Services/RaceResultSyncService.php | 通常result_available条件、PJ0315→現在詳細更新→PJ0326→結果取込み。発走前独立収集へ流用しない |
| Parsers/RaceDetailParser.php | PC0201の場/日/番号/概要車番・登録番号とPJ0315詳細を照合。aftStartTimeとkyakusituを投影 |
| Parsers/RaceEntryListParser.php | keirinCd/kaisaihi/reqprmとrInfo、車番/登録番号、stTime/kyaku、カテゴリーを解析。lastUpdateTimeは表示文字列であり公表時計の証明ではない |
| Services/RaceRosterSnapshotService.php、RosterSnapshots/Reader.php | Raw先行カテゴリー検査、現在出走者へ過去Rawを合わせない。現ServiceはSQLで2022〜2025限定、用途RETROSPECTIVE_ONLY。将来実収集の書込み経路ではない |
| PrestartCapture/全10PHP | v1はPJ0315・1capture1race・2022〜2025・SIMULATED又は履歴再生のみ。許可targetは1〜100件の技術上限で、日次HTTP上限ではない。固定全車番/登録番号の不一致はFAILED |
| PrestartCapture/Projection.php（PR121 P2） | 履歴要求を主条件にlog時刻証拠を必須化。SIMULATED/UNKNOWNへのフラグ変更で回避不可。人工時計UNKNOWNは維持 |
| PrestartCapture/CaptureStore.php、CaptureVerifier.php | 排他保存/fsync後の今回コピー時刻・code seal・marker・独立再投影。execution.finished_atはvalidation前の記録であり検証/公開完了時刻ではない |
| PrestartEvidence/関連クラス・Attributes/ | 旧capture/派生束はSYNTHETIC専用。別receiptと誤差区間の実装原則は参考になるが、人工契約を実時計へ付け替えない |
| app/Models/ScrapingFetchLog.php、2026_07_18_000001 Migration | request/log/Raw識別・SHA・日時等はあるが、開始/保存完了/redirectや時計証拠とは別。timestampsを代用しない |
| 2026_10_23_000016_create_race_roster_snapshots.php | append-only/RESTRICT/用途CHECK。現在のRETROSPECTIVE_ONLY保存を実取得用へ緩和しない |

読取り対象文書は[旧証拠契約](c1-racecard-prestart-evidence-contract-01.md)、[旧preflight](c1-racecard-asof-preflight-01.md)、[属性Phase1](stat39-prestart-attribute-evidence-phase1-01.md)、[STAT46](stat46-roster-snapshot-01.md)、[BridgeとPR121修正](prestart-capture-offline-bridge-01.md)。旧99,669レース監査の候補0、実Raw6レース8版＋別9車1版、76/883等のテスト値は過去実績で、今回は再生成・再実行しない。

## 3. 5W3H

| 観点 | 契約案 |
| --- | --- |
| Why | 発走前の原資料・本人・実時刻を結び、後日独立検証できる条件を定義する。精度改善を評価しない |
| What | 固定男子レースの出走表・原bytes・全試行証拠・出走者差分。ライン/公式変更理由は根拠がある場合だけ別観測 |
| Who | ユーザーがD1〜D8と具体実行を承認、実装者がMock受入、運用担当が送信前確認/停止、独立Verifierが保存資料を再検証 |
| When | 候補は予定60分前/15分前。公式公開タイミングUNVERIFIED、時計/TZ/期限が不成立なら送信しない |
| Where | 承認された新規private rootだけ。既存Raw/旧bundle/モデルに書かない。候補パスはD7で承認待ち |
| How | 許可manifest→条件確認→予算予約→1試行→不変保存→各軸検証→差分/要約→別の予測適格性Gate |
| How many | 推奨パイロットは1場1日最大2レース、PJ0315各2試行、全体最大4試行・同時1。すべて未承認 |
| How much | 固定9版の保存seal平均約92.4KBは参考値。容量/負荷/許諾/保持期間の実運用根拠は未確認 |

## 4. D1・D2: 対象と試行計画

### 4.1 固定allowlistと出走者変更

推奨は独立した承認manifestで `authorization_id / revision / previous_manifest_sha256 / approved_at / expires_at / operator / source_policy_version / budget_ledger_reference` と、列挙した年・日・場・race番号/外部identity・内部ID対応根拠・取得元/route/method・private request binding・期待出走者snapshot参照/seal・予定時計根拠・取得slotを固定すること。年範囲指定だけの許可、現在encpからの無検証な再構成、承認の自己申告は受け付けない。共有物には完全URI/encpを出さない。

manifestのbytes/SHAと承認記録を独立に保持し、対象/ソース/予算/期限/期待本人の変更は新revisionと再承認。旧manifestや過去captureは修正しない。同race内の追加・消失を観測する許可と、その新構成を予測へ使う承認も別とする。

現v1は全出走者集合固定のため、変更版をVERIFIEDにするために期待値をRawから自動再設定してはならない。推奨する別版では、固定race allowlistと「比較用の基準出走者」を分離する。同raceの全構成差分観測もD1の明示承認対象とし、追加/消失は `OBSERVED_ROSTER_DIFFERENCE_PENDING_REVIEW`（案）で保持する。日/場/番号不一致は実変更でなくidentity失敗、PC0201/PJ0315間の本人矛盾は停止。新しい本人や期待構成の未承認部分は予測候補に昇格させない。

カテゴリーは既存RaceCategoryPolicyを使い、Raw概要を詳細Parserより先に確認。GIRLS/L級とUNKNOWNは明示skip、既知分類の矛盾は停止する。5/7/9車や欠番で男女を推定しない。登録番号の先頭0、欠番、原配列順、欠落/NULL/空/型を保持し、現race_entries/現在プロフィールで過去値を補わない。

2026開催は列挙もRaw/metadata参照も禁止。現v1の2022〜2025ガードは変更しない。将来開催（例として2027以降）を選ぶ場合も、その年/日/場/raceの追加承認と、独立した実取得契約版・受入が必要。現時点で承認可能な実対象日は未指定であり、2027という年だけで有効化しない。

### 4.2 数値プロファイル案

以下は全行 `PROPOSED_NOT_APPROVED`。利用条件を確認した値でも、公式公開時刻を実測した値でもない。

| 項目 | 推奨案P | 代替案・理由 |
| --- | --- | --- |
| 対象 | 1場・1開催日・最大2男子race、実identityはUNRESOLVED | 1raceのみでさらに負荷を減らす |
| 取得元 | PJ0315のみ | JSJ017併用は別承認。現在Bridgeとの接続範囲を限定 |
| 目的/slot | 初回構成S0=予定60分前、変更観測S1=15分前 | 60/15/5分前の3点なら再確認余地は増すが最大6試行へ増えるため再承認必須 |
| 自動retry | 0、HTTP1試行=1capture=1attempt | 一時障害回復を狙うretryは試行証拠/予算を増やすので初回案に含めない |
| 上限 | 2試行/race、全体4試行/承認対象日・全run合計 | 同body、失敗、予約後の中断も消費に数える |
| 同時実行 | 承認scope全体で1 | プロセス数やrunディレクトリを変えて並列化しない |
| 最短間隔 | 全送信開始間60秒以上 | 既存sleep1000msを運用上限の根拠にしない |
| timeout | connect5秒、全体20秒、受信body有界 | 現configと同じ数値でも未承認。サーバー許諾とMock受入後に固定 |
| slot期限 | 計画時刻から60秒以内に開始、かつ予定発走前。遅れたslotは未実施として閉じる | 遅延時の取り返し取得は負荷/時点を変えるので禁止 |

S0→S1の差分は変更の観測であり、変更後再確認は常に可能とは限らない。S0で変更が見えた場合はS1が再確認を兼ね得る。S1で初めて変更が見えた場合は `RECONFIRMATION_NOT_OBTAINED`（案）を残し、3回目を自動追加しない。

予定時刻の改定を検出したら旧slot/旧予定を保持し、そのraceの未送信slotを保留。自動で予約を移動せず、期限/上限内の新revision承認を求める。検出前に到来したslotを後日新予定で発走前に読み替えない。

予算は送信直前に永続予約し、`authorization_id＋対象日＋race＋slot`の多重予約を拒否する。台帳はrunから独立し、クラッシュ/再起動/新root/manifest改訂で消費数をリセットしない。予約後の成否不明は消費済み、同slotの再送不可。送信前に不許可と判明したslotはHTTP0で閉じ、未使用枠を別raceへ転用しない。

429は承認scope全体を即停止し、Retry-Afterをprivateに記録しても自動再開しない。5xx/timeout/接続失敗も当該scopeの残slotを停止する保守案とし、原因確認・残予算照合・明示承認後だけ未送信/未期限切れslotを再開可能とする。再開が4試行上限の追加枠を生まないことを必須とする。

## 5. D3: 取得元・利用条件

### 5.1 独立確認の結果

参照日は2026-10-24。ブラウズツールで[公式サイトポリシー](https://keirin.jp/pc/dfw/portal/guest/policy/index.html)を確認した。法定例外以外の複製/転用制限と、私的利用を超える無断の対価取得に関する記述がある。これを非公開研究なら自由という判断や、第三者提供/自動収集の許諾へ拡張しない。同ページから自動取得の対象・頻度・保存/共有条件の明示許可は確認できなかった。権利解釈や許可取得を本書が代行するものではない。

| 項目 | 確認範囲と状態 |
| --- | --- |
| 非公開研究 | プロジェクトの研究利用が適法/許諾済みという証拠はUNVERIFIED。利用目的と保存内容を示して確認が必要 |
| 第三者提供 | Raw/原文再配布は未承認。共有は必要最小限の識別子・hash・判定要約案だが、この提供条件も確認対象 |
| 自動アクセス/負荷 | 当該ポリシーページに具体許可を確認できずUNVERIFIED。禁止不存在・少量なら許可とは判断しない |
| robots | `https://keirin.jp/robots.txt` は閲覧ツールで取得不能。HTTP404/403やサーバー拒否とは断定せず、内容UNVERIFIED。robotsの許可だけでも利用許諾と同一視しない |
| 公式データAPI | 当該ポリシーだけでは公開API・利用条件・提供対象を確認できない。API不存在とは断定せずUNVERIFIED |
| 未実施 | 出走表/結果/会員ページの新規取得、負荷試験、ログイン、サイト全体の探索・収集 |

送信前には、その時点の利用条件・公開アクセス方針と必要な許諾根拠を承認資料へ保存する。対象endpoint・目的・private保存/第三者提供・頻度/上限・User-Agent/連絡先・有効期限を確認する。未確認なら `POLICY_UNVERIFIED`（案）でHTTP0。確認不能を設定フラグで回避せず、必要なら公式問い合わせを別作業としてユーザーが判断する。今回は問い合わせも行っていない。

### 5.2 技術的な比較候補（送信許可ではない）

| ソース | scheme/host/port/path | methodと正規パラメータ |
| --- | --- | --- |
| PJ0315 | https / keirin.jp / 443 / /pc/racelive | POST form、disp=PJ0315、race encp。URI queryなし |
| JSJ017 | https / keirin.jp / 443 / /pc/json | GET、type=JSJ017、day encp。実送信queryをbindingへ含める |

これは現行コード・旧保存資料における対応で、現サイトを新規取得して確認したものではない。内部JSON endpointがあることは公式公開APIの許諾を意味しない。configの既定User-Agentや環境変数によるhost変更も承認根拠にしない。

初回送信前と実効URIの両方でscheme/host/port/path/method/パラメータ集合/対象bindingを照合する。URLの前方一致・部分一致、未知のURI、extra query、userinfo、fragmentを拒否。正規query順は意味で照合し、privateには実送信値を保持する。HTTPクライアントの自動redirectは無効、全3xxを拒否しLocation先へ接続しない。HTTPS→HTTP、同host内別path、同routeへのredirectも例外にしない。effective URIが取得できなければUNKNOWNであり、元URLのコピーで救済しない。

1試行ごとの開始/受信/redirect/status/bodyの実証が必要なため、現KeirinHttpClientの自動retry経路をそのまま利用しない。将来の専用Transportは1応答/1例外で必ず戻り、自動追従/再送/結果同期/補助ページ取得を発生させない。

## 6. D4: JSJ017とPJ0315の分離

| 軸 | JSJ017 | PJ0315 |
| --- | --- | --- |
| 取得単位 | 1day Rawに複数race。原Raw1件を対象race観測から共有参照 | 1Raw/1race |
| binding | GET queryとday encp | POST formとrace encp |
| race本人 | keirinCd/kaisaihi/reqprm、rInfo.raceNo、sInfo.syaban/senNo | PC0201.selKaisai/selKjyoCd/selRaceNo、概要carNum/numPlayer、詳細syaban/sensyuRegistNo |
| 予定時刻 | rInfo.stTime | C0201racedtl.aftStartTime |
| 登録脚質 | sInfo.kyaku | sensyuTypeInfo[].kyakusitu |
| 構成 | sInfoの全出走者。選手登録番号は文字列 | 概要/詳細の両集合と一意性を確認 |
| 公表/反映速度 | lastUpdateTimeだけで公式初回公表を証明しない。速度はUNVERIFIED | 取得完了と初回公表を区別。速度はUNVERIFIED |
| 現Bridge対応 | 非対応。旧SYNTHETIC JSJ017処理は実資料の受入ではない | v1オフラインのみ対応 |

初回パイロットはPJ0315のみを推奨するが、優位性が確認済みという意味ではない。JSJ017併用案ではday単位2slot分を追加し、最大4 PJ＋2 JS＝6 HTTP試行/日の案になる。既定の4枠で勝手にJSを追加せず、D1/D2/D4を再承認する。day Rawが他raceを含んでも対象集合を増やさず、対象外部分は入力へ投影しない。

両源がある場合は同race/slotの各capture ID・取得実時刻・manifestを別に保持する。値を多数決・最新到着順・現在DBで統合しない。時刻・本人・脚質が矛盾すれば `SOURCE_CONFLICT`（案）とし、各原値と比較不能理由を保持する。非同時取得による更新差の可能性があっても、更新時刻証拠なしに「後者が訂正」と断定しない。片源だけなら `SECOND_SOURCE_NOT_CAPTURED`（案）、源がfieldを提供しなければSOURCE_NOT_PROVIDEDであって値欠落/0とは別。

JS対応の別版では1capture→複数raceの関係、race別予定時刻/本人/カテゴリー/理由、対象allowlistとの交差、共有Rawへのsource pointer、day/race encpの帰属を追加する。既存PJの1capture1race形式を無断拡張せず、旧manifest・旧hash・旧SYNTHETIC code sealを不変にする。登録脚質はライン順/役割ではなく、両源からラインを推定しない。

## 7. D5: 変更・中止・延期

| 観測/公式区分 | 保持する事実 | 扱い案 |
| --- | --- | --- |
| 出走者追加/消失 | 同raceの版間登録番号集合差分 | 追加/消失を観測しただけ。補充/取消を確定しない |
| 車番/既知枠変更 | 同本人の前後値、両capture ID | 差分を保持。NULL枠から制度適用や変更なしを推測しない |
| 欠場 | 明示された公式状態と出典 | 配列消失だけでは判定しない。残りの出走表を最新DBから補完しない |
| 出走取消 | 明示された公式区分と出典 | 欠場・失格・未掲載と混同しない |
| 補充 | 公式区分/対象選手/決定・公表時刻/理由 | 直接証拠がなければUNKNOWN_ENTRY_TYPE |
| 追加あっせん | 同上 | 単なる新登録番号から認定しない |
| 交代 | 公式な前後本人/対応/理由 | 同じ車番の本人変更は観測であり、公式交代区分と別 |
| race中止 | 明示された対象識別と中止根拠 | 当該raceの残slotを停止。空配列だけでは中止認定しない |
| 開催順延 | 明示された元対象日/新日/状態の根拠 | 日付変更はD1再承認。自動で翌日へ移動しない |
| ページ未公開/状態不明 | flagの有無/原型/メッセージとRaw | UNKNOWN/利用不能として保持。正常出走表を捏造しない |

全変更は旧snapshotを保持して新取得版を追加する。取得順と公式イベント順を分離し、2つの観測間に複数変更があったかは不明とする。A→B→Aなら3観測を残し、同bodyだから古い期間を流用しない。実変更・source訂正・Parser訂正の理由が不明ならUNKNOWN_CHANGE。

再確認はD2の残slot/全体上限内だけ。公式公表・あっせん決定は取得時刻から推定せずNULL/UNKNOWN。将来、別承認で公式公表だけを証明できた場合のPUBLICATION_TIME_FALLBACKは派生計算の出典flagとし、決定時刻の原値を上書きしない。本工程のofficial_publication_atはNULL固定。STAT46配点、変更連鎖・stale解消・予測再計算は実装も実行もしない。

## 8. D6: 実時計・予定時刻・境界

| 時計 | 記録する境界と証明範囲 |
| --- | --- |
| request_started_at | 単一HTTP試行の直前。slot予定時刻やDB日時で代用しない |
| response_received_at | body受信完了又は失敗確定時刻。request開始をコピーしない |
| raw_saved_at | 原bytesのwrite/flush/fsyncと格納先の永続化確認後。mtime/log.created_atを使わない |
| observed_scheduled_start_at | 各同Rawの原field・TZ/精度の根拠版を併記。別Raw/後日DBの予定を混ぜない |
| actual_start_at | 直接の公式根拠がなければNULL。予定時刻で代用しない |
| official_publication_at | 原資料の明示的根拠がなければNULL。lastUpdateTime/初回取得時刻と別 |
| prediction_input_as_of | 将来の別承認で固定する利用基準。今回予測は作らない |
| validation_completed_at / publication_completed_at | 将来の別版で実測する検証/証拠公開完了。現execution.finished_atを使わない |

比較はUTC、表示はoffset付きAsia/Tokyo。元表記・TZ根拠・精度・観測時計の出典を残す。ホスト既定TZだけでは公式RawのTZを実証しない。予定のHH:MMは分精度で、秒00を実発走の正確な値とみなさない。丸め方/TZの根拠が未確認なら予定時刻区間もUNVERIFIED。

推奨は、送信前後に既存の時刻同期機構の成功状態・参照元・最終同期・offset/error bound・取得時刻をread-onlyで記録し、同一プロセスの単調時計で開始→受信→保存の順序を補助検証すること。同期機構の具体名/状態の有効期間/許容誤差上限はUNRESOLVED。未測定の精度を0秒や仮の±1秒で埋めない。今回はホストの時計サービス調査・設定変更をしていない。

案として各時刻を `[L,U]` の証拠付き区間とし、次を別々に判定する。

```text
予定前受信候補: U(response_received_at) < L(observed_scheduled_start_at)
利用基準までの処理完了: U(raw_saved_at/validation_completed_at/publication_completed_at) <= L(prediction_input_as_of)
基準時点も予定前: U(prediction_input_as_of) < L(observed_scheduled_start_at)
```

誤差区間の接触/重複、時計逆転、同期証拠/TZ/予定欠落ではTIMING_UNKNOWN。等時刻を予定前に含めない。受信前・保存後、検証後・公開未完了を別に残す。予定前候補でも実発走前の証明ではない。同期証拠不足が送信前に分かればHTTP0で停止し、取得中に失われた場合は実Raw/失敗資料を保存して残slotを止める。

SIMULATED_SCENARIO、HISTORICAL_RAW_REPLAY、将来の実取得modeは別版/別領域。PR121の要求mode主条件を維持し、履歴Sourceの日時を人工時刻へ変更して通さない。実取得応答にも履歴Source/人工時計を混入させない。新しい検証/公開完了証拠はmanifest/外部期待SHAに結び付く別receipt案とし、旧manifestに時刻を追記・再sealしない。旧Attributes receiptの実装原則を参考にするが、人工クラスのSYNTHETICガードは外さない。

## 9. D7: 保存・容量・復旧

候補は合意済み親 `/home/shinya/neo-keirin-artifacts/` 配下の `prestart-capture-live-pilot-01/<unique-run>/private/` と `summary/`。パスは未承認・未作成。privateに完全request/response・原Raw・詳細log、summaryに必要な対象ID・判定・版・hashだけを置く。旧bridge/Raw/storageを出力先に使わず、root自体がmount点にならない専用子を既存安全APIで検査する。

所有者は実行ユーザー、directory0700/file0600・umask077、既存path/ID衝突・symlink・mount越境・解決不能/保護pathは拒否する案。capture ID/attempt IDは各一意、再取得は別IDで同bodyも保持。pathをIDから推測して後片付けしない。共有digestがあるだけでprivate requestが独立検証できるとはせず、private資料への正当な読取り権限も必要とする。

### 9.1 固定小標本からの容量参考

今回read-onlyで確認したのはPR121保存済み再生レポート `pr121-review-20261024-095754-qVyCKZzZ/logs/replay-evidence/real-replay-verification.json`（親root: `/home/shinya/neo-keirin-artifacts/prestart-capture-offline-bridge-01/`）。SHA `ebe79f9baca5207032f95b0c62046c008638d729281065d5f2d7a79d2fcd0fd5` を照合し、固定2022〜2025開催だけの `raw_seal.bytes` を再集計した。Raw本文読取り・実ファイルの再計測・再生・過去監査は行っていない。

| 標本 | 件数 | 合計bytes | 最小〜最大bytes | 平均bytes |
| --- | ---: | ---: | --- | ---: |
| 既存6raceの8版 | 8 | 737,414 | 87,784〜96,169 | 92,176.75 |
| 別枠9車1版 | 1 | 94,103 | 94,103 | 94,103 |
| 計 | 9 | 831,517 | 87,784〜96,169 | 92,390.78（表示丸め） |

これは事後取得PJ0315の小標本で、独立9raceでも全サイトの平均でもない。JSJ017・異常body・圧縮/展開・filesystem・meta・backupの容量は含まない。仮に同平均のPJを4試行/日繰り返す仮定なら、Rawだけで30日=120×831517/9 ≈11.09MB、365日=1460×831517/9 ≈134.89MB（MB=10^6bytes）。これは算術シナリオであり、日次運用の承認・年間容量保証ではない。

提案上限は原body4MiB/試行、metadata1MiB/試行、構造化実行log4MiB/run、run全体64MiB、開始時空き128MiB以上。4試行の原body16MiB＋meta4MiB＋log4MiBの24MiBに失敗保存/余裕を持たせる保守案で、サンプルから安全性を保証した値ではない。次工程Mockで最大時・途中失敗・ディスク不足を受入し、D7承認前に有効化しない。新しい実取得Transportは展開後も有界にし、超過した部分資料はtruncatedと明記して完全Raw hash/COMPLETEを主張しない。

送信前/各保存前に次の最大body/meta/失敗記録の予約量と残量を検査する。確保不能なら取得を止め、/tmp等へ逃がさない。別runで64MiB上限をリセットして同一承認で収集を継続しない。保持期限の確定日・backup頻度/媒体/責任者はUNRESOLVED。最低限、受入レビューと復旧可能性の確認まで原本保持・自動削除0とし、容量不足は停止で扱う。

### 9.2 障害・復旧の案

保存途中・同期失敗・公開前drift・marker/receipt不一致ではpartial/FAILEDと実在bytesを残す。COMPLETE後に検証失敗を見つけた場合も正常候補から隔離し、削除や修復上書きはしない。破損資料の隔離はまず論理的な参照除外＋新監査記録を推奨し、元pathの移動は既定で行わない。

再処理は旧ID/旧束を書換えず、元SHA/コード版を参照する新監査run。backupは別承認でprivate権限を維持し、Raw・manifest・外部期待SHA・承認/試行台帳・実行時コード/依存版を一組として保全する。復旧も別承認で別rootへ戻し、元期待SHA/件数/版を独立Verifierで検証してから参照を切り替える。部分一致で再sealしたり、backup一覧確認を復元成功と呼んだりしない。今回はbackup取得/媒体確認/復元試験0。

実Raw/証拠/失敗資料は後片付け対象ではない。人工テスト専用領域だけ、作成時保持オブジェクトのpathを既存helperに渡し、人工Desktop/Trashでrename-onlyを試験する。本物の `/home/shinya/Desktop/Trash/` を使う許可が必要な場合も、永久削除・上書き・コピー後削除fallback・Trash整理は禁止する。

## 10. D8: 観測と予測適格性を分離

| 軸 | 成立に必要な証拠 | 単独では意味しないこと |
| --- | --- | --- |
| HTTP試行完了 | attempt別の実開始/受信/失敗記録 | HTTP200・Rawあり・本人一致ではない |
| 原Raw保存完了 | fsync後時刻・bytes/SHA・排他保存 | 取得元や発走前の保証ではない |
| 取得元 | 許可/binding・実効URI・非redirect | Raw内本人の保証ではない |
| race/本人 | 場/日/番号/全車番・登録番号・原資料間の一貫性 | 現在DBとの一致や公式変更理由ではない |
| 時点候補 | D6の時計/TZ/予定区間・strict境界 | 実発走前・公式初回公開の証明ではない |
| 必要属性 | 原値/型/欠落・用途別形式・全対象充足 | 得点ありだけでC1入力全体適格ではない |
| 二源整合 | source別の本人/予定/脚質と比較可能な時点 | 片源未取得を一致扱いしない |
| 正式予測利用 | 別Gate・入力契約・版選択・ユーザー承認 | COMPLETE/VERIFIED・本書承認ではない |

取得版の選択案は、承認scopeとsourceを固定し、cutoffまでに受信/保存/検証/公開を完了した適格版から、同source内の最新観測を決定的に選ぶこと。同時刻異内容はAMBIGUOUSとして保留し、DB ID/取込み到着順で優劣を決めない。既知の取消/後続の不明・矛盾・取得失敗を、古い正常版へ戻って隠さない。以前の版を研究参照に使う場合も理由と選択規則を明示し、最終入力へ自動採用しない。

PJのみパイロットは二源整合が未成立である。欠損・部分対象・訂正時点不明・新本人未確認・SOURCE_CONFLICTは用途ごとの未確定状態を残す。capture ID→原Raw SHA→解析/属性版→入力snapshot SHA→将来の予測IDの追跡を設計対象とし、最後の入力/予測リンクは今回は作らない。C1の係数・Decoder・Evaluator・Gate・過去入力/予測へ結合しない。

最終Gateの製造は別工程。固定7値（1節）を引き続き適用し、official_publication_atの将来拡張も別契約とする。時点証拠が増えても、過去99,669raceや全既存出走を一括でhistorical_as_of=trueへ変更しない。

## 11. シーケンスと停止状態案

状態名は新運用版だけの案。既存DB Enum/旧bundle状態を書き換えるものではない。

```text
対象manifestの承認 → 条件/上限確認 → slot計画 → 送信前検証 → 試行予約
→ 単一HTTP試行/時計 → 不変Raw保存 → source検証 → カテゴリー/本人/属性
→ 時点候補 → snapshot差分 → 証拠公開/独立Verifier
→ 予測利用適格性Gate（別工程・今回未実装）
```

| 工程 | 正常時 | 異常/UNKNOWN | 停止範囲・再開条件 |
| --- | --- | --- | --- |
| 承認manifest | APPROVAL_VERIFIED | 未承認/失効/改変 | 全scope HTTP0。新承認以外で救済しない |
| 条件/対象 | TARGET_BOUND | 不許可/2026/許諾不明 | 全scope停止。別日/別sourceへ自動変更しない |
| slot/予算 | SLOT_RESERVED | 期限切れ/超過/重複/台帳不明 | 当該slotを閉じ、予算不明は全scope停止。新runで再送しない |
| 送信 | RESPONSE_RECORDED | 接続/timeout/429/5xx/3xx | 実在body/失敗記録保持、全scope残slot停止。原因確認/残枠/明示再開承認 |
| Raw保存 | RAW_PERSISTED | partial/容量/権限/同期失敗 | 全scope停止。fallback/自動修復なし |
| source | SOURCE_VERIFIED | URI/redirect不明・不一致 | 全scope停止。内容が正しくても通さない |
| カテゴリー | MEN | GIRLS/UNKNOWN | 明示skip、当該race残slot停止。既知矛盾は全scope停止 |
| 本人/属性 | IDENTITY_VERIFIED | 別race/内部表矛盾/属性欠落 | 別race/内部矛盾は全scope停止。承認範囲内の実構成差は保留記録、D2残slotのみ観測可 |
| 時点 | PRESTART_TIMING_CANDIDATE | 境界/時計/TZ不足 | TIMING_UNKNOWN又は非候補。時計異常は全scope停止、資料は保持 |
| 変更比較 | OBSERVED_DIFFERENCE | 1版/同時刻/line不明/二源矛盾 | UNKNOWN/不足を保持、追加HTTPを誘発しない |
| 証拠公開 | COMPLETE＋判定 | code/source/manifest drift、receipt失敗 | FAILED/部分束保持、正常公開なし。COMPLETE後FAILEDもVerifier拒否 |
| 予測接続 | 今回常にNOT_AUTHORIZED | 自動接続要求 | 入力/モデル/予測更新0のまま停止 |

JS/PJの到着順が逆でも、それぞれの実取得/保存/公開時刻を保持し、後着資料を前のslotへ差し込まない。取り消された承認は新試行を止めるが、過去の観測時刻や束を書き換えない。全体上限到達後の再開は同じ承認では不可、明示した追加予算の別承認が必要。

## 12. 次工程の受入テスト仕様（今回未作成・未実行）

全てFixture/Mockでネットワーク不要。実HTTPを禁止するMockで送信回数・転送先0件・DB/モデル書込み0をassertする。人工時計/カテゴリー変更等は実競輪正常受入と呼ばない。

| ID | 入力/境界 | 必須期待結果 |
| --- | --- | --- |
| T01 | allowlist外/期限切れ/対象改訂未承認 | HTTP0、旧manifest不変 |
| T02 | 人工2026対象 | Raw/DB/HTTP I/Oより前に拒否。実2026資料は使わない |
| T03 | 未承認source・extra parameter・別host/path | HTTP0。部分URL一致で通さない |
| T04 | 3race/2場/別日など上限超過 | 全対象を送信前拒否。都合のよい2raceだけ勝手に切出さない |
| T05 | 3回目/race・5回目/日・二重起動・別run | 上限超過/重複拒否、同時実行1、失敗予約も消費維持 |
| T06 | slot期限外/間隔60秒未満/予定変更 | HTTP0又は未送信slot保留、取り返し取得0 |
| T07 | 429＋Retry-After | 元失敗証拠保持、全scope停止、次送信0、自動sleep再開0 |
| T08 | 5xx/timeout/接続失敗 | 1試行のみ、body有無別記録、retry0、残slot停止 |
| T09 | 全3xx・同host/別host Location | 非追従、Location先HTTP0、FAILED |
| T10 | effective URI不一致/欠落 | Rawが正しくても拒否。元URLで補完しない |
| T11 | Raw1byte/size/SHA/書込み/fsync失敗 | 原本保持、FAILED/partial、COMPLETEなし、/tmp fallback0 |
| T12 | MEN/GIRLS/L/UNKNOWN・既知矛盾 | 概要先行、対象外は男子詳細Parser未呼出し、矛盾停止 |
| T13 | PJ概要/詳細の不一致・重複・先頭0・欠番 | 不一致拒否、正常先頭0/非連番を保持 |
| T14 | JS day/race本人・複数race・共有Raw | 1Raw参照、許可raceのみ投影、race別時点分類、day/race encp混同拒否 |
| T15 | 二源の予定/脚質/本人矛盾、到着順反転 | SOURCE_CONFLICT/未確認保持、多数決/後着遡及0 |
| T16 | 追加/消失/車番変更、A→B→A | 全版と差分再現、補充理由NULL、無承認期待集合変更0 |
| T17 | 欠場/中止/順延/未公開/空配列 | 公式根拠と対象照合、空=中止としない、race/day再承認、追加取得0 |
| T18 | 開始=受信/逆転・単調時計逆転 | 順序不成立を保持、正常実時計へ補正しない |
| T19 | 同期/TZ/精度不明・誤差区間接触 | TIMING_UNKNOWN、前候補/0誤差にしない |
| T20 | 前/等値/後、受信前・保存/公開後cutoff | strict境界・完了時計を別assert、候補と利用適格を混同しない |
| T21 | 再取得/同ID衝突/途中中断/旧bundle | 新ID履歴保持、上書き/再seal0、原本START/END SHA一致 |
| T22 | 未承認予測接続・属性欠損/NULL/空/不正型 | NOT_AUTHORIZED、C1/STAT/モデル更新0、原値/存在/型保持 |
| T23 | 上限停止後再起動/台帳破損/取消承認 | HTTP0、予算復元不能は停止、同slot再送0 |
| T24 | 容量/permission/link/mount/Trash移動失敗 | 保護path不操作、人工領域だけ検証、原本保持、永久削除/fallback0 |
| T25 | 要求modeと応答出典/履歴Source混入 | PR121 P2の全回避経路拒否、正当な人工UNKNOWN・履歴再生9版は維持 |
| T26 | 保存資料だけの独立Verifier/receipt/code drift | DB/ネットワーク不要、外部期待hash/再投影/公開時計一致、旧版不変 |

受入はテスト件数だけでなく全期待結果の成立、別PHP128MiBでの有限保存/独立検証、stderr/警告/skip/exit/peakの記録、原本read-only・人工.env/storage・SQLite隔離・既存安全helperでの限定回帰までを条件とする。旧cleanupを安全確認せず通常全体を起動しない。必要な既存回帰はPrestartCaptureのUnit/Feature、PrestartEvidence/属性、AutomatedRaceParser、STAT46、関連HTTP/storage/serviceのMockテスト。警告を抑制して受入しない。

## 13. 後続実装候補と実HTTP有効化条件

以下は候補名であり存在/製造/承認済みではない。専用 `Scraping/PrestartCapture/Operational/` 等の別版を推奨し、既存v1直下globのcode sealや旧DTOの年/mode制約を変えない。

| 候補ファイル | 最小責務 |
| --- | --- |
| Operational/Contract.php、CaptureRequest.php、CaptureResponse.php | 実取得版/許可manifest/基準本人・差分/応答出典・実時計の契約。既存2modeと分離 |
| Operational/AttemptLedger.php | 同承認/日/race/slotの永続予約・全run予算・単一起動。汎用job基盤にはしない |
| Operational/SingleAttemptTransport.php | retry/redirectなしの単一HTTP、実時刻/URI/失敗を返す。既存KeirinHttpClientは無断変更しない |
| Operational/CaptureService.php、CaptureStore.php | 送信前の承認/時計/予算/容量、実Raw不変保存、終端検査。既存安全APIを再利用 |
| Operational/TimingEvidence.php、CaptureVerifier.php | 時計根拠/誤差・同Raw本人/属性・receiptの独立再検証、DB/HTTP依存なし |
| config/prestart_capture.php | 独立設定候補。既定無効、承認manifest必須、既存keirin設定から許可を継承しない |
| 専用Artisan Command候補 | plan既定（DB/HTTP/Rawアクセス0）と明示executeを分離。Scheduler/Queue登録なし |
| 専用Unit/Feature Test | T01〜26と既存回帰。HTTP fake/ネットワーク遮断、人工時計を出典付きで扱う |

実HTTP有効化には、D1〜D8の決定台帳の署名/記録、具体日/場/raceの許可manifest、D3の根拠・当日有効な許諾/負荷条件、時計/TZ根拠、保存/容量/保持/backup条件、別版実装・Mock受入/コードレビュー、実行コマンド/停止/担当者の明示承認がすべて必要。特に2026禁止と現v1の過去年制限のまま実HTTPを開始する経路はない。

文書の承認、コード製造の承認、限定パイロット送信の承認、観測資料の受入、最終予測利用承認を同じチェックボックスにしない。今回の次の許可作業は本書とMASTER PLANのレビューだけ。

## 14. 確認結果

本工程は文書作成と読取り確認のみ。PHPUnit/Pint/Artisan/DB/データ取得HTTP/Raw再解析/再生/学習/予測/評価/2026開催参照は各0。新しい統計計算は行わず、9節の保存bytesの算術集計だけを実施した。静的ポリシー独立閲覧とrobots確認不能は5節の範囲で記録した。

文書検証はexit0。D1〜D8の全台帳欄・未承認表示、受入仕様26項目、相対参照5文書の実在、コード区切りと末尾空白を確認した。MASTER PLANの旧15.86/15.86.1本文は開始HEADと完全一致し、git diff --checkはexit0。新規文書とMASTER PLAN以外の変更はない。過去の76/883・150/6047・旧証拠警告、ユーザー全体3972/41738/155warnings/9skippedは過去記録で、今回成功したテストとは報告しない。commit/push/PR作成/mergeなし、設計branchの未コミットレビュー待ちで停止する。

## 15. D1〜D8決定台帳（文書末尾・全項目未承認）

ユーザーは各IDを承認/修正/保留し、承認する場合は具体値・根拠・版・承認日時を別記録へ明記する。技術案への同意だけでUNRESOLVEDが埋まったとは扱わない。

### D1: 対象と変更観測範囲

| 欄 | 内容 |
| --- | --- |
| ID・決定内容 | D1、具体年/日/場/race/source/基準本人/変更観測範囲・対象上限 |
| 既存仕様の根拠 | STAT01/39/46の時点・本人履歴、Bridge v1の年/全本人固定、AGENTSカテゴリーPolicy |
| 推奨案 | 4節P：1場1日最大2男子race、PJのみ、固定raceと基準本人を分離する別版 |
| 代替案 | 1raceのみ、又は基準本人一致版だけを観測し変更はFAILED資料として保留 |
| 推奨理由 | 対象と負荷を限定しつつ、変更を「本人不一致の自動修復」で隠さない |
| 想定される影響 | 旧CaptureRequestの制限を維持して新DTO/版・承認manifestを用意 |
| 未確認の根拠 | 将来対象の公式識別/encp/基準本人・予定公開証拠はUNVERIFIED |
| 承認が必要な値（ユーザー判断） | 実年/日/場/番号と期限、基準snapshot/seal、同raceの新出走者差分観測可否。全てUNRESOLVED |
| 現在の状態 | PROPOSED_NOT_APPROVED / UNRESOLVED。2026禁止は確定 |
| 承認後の実装（技術選択） | Operational/Contract・CaptureRequest・CaptureService、対象manifest検証テスト |
| 受入基準 | T01〜04/T12〜17/T25、対象外/2026送信0・基準改竄/自動再固定0 |

### D2: slot・予算・障害停止

| 欄 | 内容 |
| --- | --- |
| ID・決定内容 | D2、取得目的/時刻/頻度/試行上限/timeout/retry/再開 |
| 既存仕様の根拠 | 旧契約D2と1試行1証拠、KeirinHttpClientの中間retry証拠不足 |
| 推奨案 | 60/15分前、2回/race・全4回/日、同時1/間隔60秒、connect5/全体20秒、retry0、障害時scope停止 |
| 代替案 | 1時点で負荷最小、又は3時点/全6回を別承認。2時点で最後の変更を再確認できないリスク |
| 推奨理由 | 既存Bridgeの単一試行性を維持し、際限ない変更追跡/再送を防ぐ |
| 想定される影響 | 永続予算/slot台帳、単一起動、予定改定時保留が必要。公開時刻が合わず未実施になり得る |
| 未確認の根拠 | 公式公開/更新周期・負荷許容値・この時点で資料があるかはUNVERIFIED |
| 承認が必要な値（ユーザー判断） | 上記全数値、slot期限60秒、停止scope/再開責任者、第三目的の再確認不足許容 |
| 現在の状態 | PROPOSED_NOT_APPROVED。公開周期と実対象slotはUNRESOLVED |
| 承認後の実装（技術選択） | Operational/AttemptLedger・SingleAttemptTransport・CaptureService、専用config/Command |
| 受入基準 | T05〜08/T23、別run/失敗/再起動でも予算非リセット、HTTP試行数が上限以内 |

### D3: 利用条件と送信先

| 欄 | 内容 |
| --- | --- |
| ID・決定内容 | D3、権利/自動アクセス/第三者提供/route/負荷の根拠と有効期限 |
| 既存仕様の根拠 | 公式ポリシー確認範囲は5節、旧契約redirect検証、現SourceValidator |
| 推奨案 | 根拠確認まで実取得無効。許可対象route/method/bindingを限定し全3xx非追従 |
| 代替案 | 条件付き公式データ提供/APIが確認できれば別契約を比較。現時点で存在/利用可否は未確認 |
| 推奨理由 | 少量・公開・非公開研究という事情だけで自動収集許可を推定しない |
| 想定される影響 | 許諾根拠と設定版を送信前検査、既存retry/URI fallbackを使わない専用Transport |
| 未確認の根拠 | robots本文・自動取得/保存/共有条件・API・負荷/時間帯の許諾はUNVERIFIED |
| 承認が必要な値（ユーザー判断） | 許諾/利用根拠、用途/提供範囲、具体route/期限/連絡先/User-Agent/負荷条件 |
| 現在の状態 | PROPOSED_NOT_APPROVED / UNRESOLVED。実送信はNOT_AUTHORIZED |
| 承認後の実装（技術選択） | Operational/Contract・SingleAttemptTransport・CaptureVerifier、source Mockテスト |
| 受入基準 | T03/T07〜10、許可不明時HTTP0・redirect先0・effective不明の救済0 |

### D4: ソース優先・統合しない規則

| 欄 | 内容 |
| --- | --- |
| ID・決定内容 | D4、初回source、二源の比較単位と矛盾/欠落時の扱い |
| 既存仕様の根拠 | JSJ017 day GETとPJ0315 race POST、各Parserのfield対応、現BridgeはPJのみ |
| 推奨案 | PJのみ技術パイロット、二源未検証を明示。両源追加後も矛盾はSOURCE_CONFLICTで保留 |
| 代替案 | day共有JSを先行する案、又はPJ4＋JS2の6試行案。予算/対象の再承認が必要 |
| 推奨理由 | 現実装の受入範囲を限定できるが、情報源の鮮度優位は主張しない |
| 想定される影響 | JS採用なら1capture複数race・race別時点/Pointer・共有Rawの新形式が必要 |
| 未確認の根拠 | 二源の更新/公表順、公式の優先関係、ライン提供情報源はUNVERIFIED |
| 承認が必要な値（ユーザー判断） | 初回source、JS併用範囲/予算、単一源未確認を許す研究範囲、矛盾時保留方針 |
| 現在の状態 | PROPOSED_NOT_APPROVED。予測用source選択はUNRESOLVED |
| 承認後の実装（技術選択） | Operationalのsource別Request/Projection/Verifier、JS用共有Raw観測契約 |
| 受入基準 | T14/T15、取得順逆転でも非遡及、片源欠落を一致としない、ライン推定0 |

### D5: 変更・中止・延期

| 欄 | 内容 |
| --- | --- |
| ID・決定内容 | D5、観測差分と公式イベントの区別、残slotと再確認方針 |
| 既存仕様の根拠 | STAT18/29/46とSCR46-01〜03、STAT46差分Calculator/中止Parserの既存契約 |
| 推奨案 | 7節の区分で原版を追加保持、理由/決定/公表不明はNULL、残slot以外の再取得なし |
| 代替案 | 変更後に全取得停止し人手確認。再確認優先ならD2の3時点案を別承認 |
| 推奨理由 | 観測された追加/消失だけでは補充等の意味を確定できない |
| 想定される影響 | 比較結果にcapture参照と保留理由、順延は対象manifest再承認。予測再計算は別工程 |
| 未確認の根拠 | 公式区分/理由/決定/公表の取得源と欠場/取消の直接証拠はUNVERIFIED |
| 承認が必要な値（ユーザー判断） | 変更時の残slot継続可否、順延/中止の停止・再承認、未再確認を残す方針 |
| 現在の状態 | PROPOSED_NOT_APPROVED。公式イベント連携はUNRESOLVED |
| 承認後の実装（技術選択） | Operational/CaptureService・Projection候補、STAT46の純粋比較を読取り再利用 |
| 受入基準 | T16/T17、A→B→A全版保持、理由推測/空=中止/予測更新0 |

### D6: 時計と許容誤差

| 欄 | 内容 |
| --- | --- |
| ID・決定内容 | D6、同期根拠/TZ/精度/誤差区間と時点候補の境界 |
| 既存仕様の根拠 | 旧契約D6、Attributes/ScenarioTimingの誤差区間、Bridgeの別時計と原Raw予定 |
| 推奨案 | 実開始/受信/fsync後/検証/公開を別計測、UTC区間比較、根拠不明はUNKNOWN/送信停止 |
| 代替案 | 時計根拠が成立するまで全試行を保留。推定0誤差や予定=実発走の案は採らない |
| 推奨理由 | 前後数値だけではclock jumpや予定丸め・事後保存を識別できない |
| 想定される影響 | 専用Clock evidenceと新receipt、単調時計の補助監査。旧人工basisは変更しない |
| 未確認の根拠 | 同期方式/精度/TZの公式根拠/予定の丸め/実発走はUNVERIFIED |
| 承認が必要な値（ユーザー判断） | 同期方式・証拠有効期間・測定後の最大許容誤差・境界/障害停止方針 |
| 現在の状態 | PROPOSED_NOT_APPROVED。誤差上限/有効期間の数値はUNRESOLVED |
| 承認後の実装（技術選択） | Operational/TimingEvidence・Transport・Store・Verifier、境界Mock |
| 受入基準 | T18〜20/T25/T26、UNKNOWNを0にしない、等時刻/区間接触は前候補なし |

### D7: 保存・容量・保全

| 欄 | 内容 |
| --- | --- |
| ID・決定内容 | D7、専用root/private分離・予算・保持・backup/復旧の責任 |
| 既存仕様の根拠 | Bridge排他/fsync/manifest、既存安全helper、固定9版seal再集計は9節 |
| 推奨案 | 新private0700/file0600、body4MiB/meta1MiB/log4MiB/run64MiB、開始空き128MiBの案。自動削除0 |
| 代替案 | 1race/さらに小さい容量枠、又は審査保全専用媒体。保存先を勝手に変更しない |
| 推奨理由 | 小標本平均の外挿で容量保証せず、失敗資料も保持できる有界実装を受入する |
| 想定される影響 | 有界受信・容量前検査・failure予約・private/summary分離・別root復旧手順 |
| 未確認の根拠 | 実運用の空き/権限/最大body/meta/backup環境はUNVERIFIED |
| 承認が必要な値（ユーザー判断） | 正確なroot・全容量値・保持期限日・backup媒体/頻度・復旧責任者。期限/媒体等はUNRESOLVED |
| 現在の状態 | PROPOSED_NOT_APPROVED。root未作成、保全/復旧試験未実施 |
| 承認後の実装（技術選択） | Operational/CaptureStore・CaptureVerifier・Command、既存path/退避helper再利用 |
| 受入基準 | T11/T21/T24/T26、原本非上書き、復旧は元SHA一致、失敗時fallback/永久削除0 |

### D8: 最終予測入力の承認境界

| 欄 | 内容 |
| --- | --- |
| ID・決定内容 | D8、8軸の分離・取得版選択/欠損/競合・将来の最終Gate |
| 既存仕様の根拠 | STAT01/17/18/29/39/46の時点・用途分離、PR121固定禁止値、旧属性用途別判定 |
| 推奨案 | 観測パイロットと予測接続を分離。最新適格版＋完全性/後続未知・二源矛盾の監査、10節の案 |
| 代替案 | 当面は全観測を研究資料だけに限定し最終Gateの設計自体を保留 |
| 推奨理由 | COMPLETE/VERIFIEDや単一field充足を予測可能と誤認しない |
| 想定される影響 | 将来capture→入力SHA→予測IDの参照。既存C1入力/モデルへの接続は別承認・別受入 |
| 未確認の根拠 | 必要属性/二源/訂正規則・本物の事前利用可能性・予測効果はUNVERIFIED |
| 承認が必要な値（ユーザー判断） | 研究限定範囲、将来Gate工程/入力用途/版選択・競合/不明の扱い。正式利用は本書の対象外 |
| 現在の状態 | PROPOSED_NOT_APPROVED / 最終Gate UNRESOLVED。prediction_use=NOT_AUTHORIZED維持 |
| 承認後の実装（技術選択） | 今回は記録設計のみ。最終Gate/予測接続のクラスは別工程で決定し、Operational/Verifierを流用して自動許可しない |
| 受入基準 | T15/T20/T22/T26、追跡と保留理由が再現可能、未承認入力/モデル書込み0 |
