# PRESTART-CAPTURE-DECISION-EVIDENCE-01

## 1. 目的と対象範囲

2026-10-27、文書版 `DRAFT-v1`。状態は `OPERATIONAL_DECISIONS_AWAITING_USER_REVIEW`。将来の限定観測パイロットについて、ユーザーがD1〜D8を承認・変更・保留するための資料であり、運用承認記録・設計freeze・実行指示ではない。

06:28 JSTのfetch/main switch/pull --ff-only後、clean main・origin/main・GitHub PR123のmerge SHAが `adfd52af53988d0e3337c78278b1e3a6bab84cf9` で一致した。PR123はMERGED、merged_at=`2026-10-26T21:20:17Z`（2026-10-27 06:20:17 JST）、merge時head=`06d6bf5dadfc34a73a83b8d68e4aefdda15aa096`。未使用の `design/prestart-capture-decision-evidence-01` をmainから作成した。

変更は本書と[MASTER PLAN](statistical-engine-master-plan.md)だけ。実レース選定、承認済みmanifest生成、実データHTTP・DB接続・予約・本番保存・Raw再生・モデル操作・2026開催資料参照は行わない。公式静的2ページの閲覧、ローカル時計と既存親領域のread-only確認は以下に別記する。Gitのマージ受入と運用承認を分離する。

```yaml
operational_decisions: PROPOSED_NOT_APPROVED
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

5W3H: Why=時点付き観測を安全に検討するため、What=固定男子PJ0315の限定観測案、Who=ユーザーが条件と担当者を決定、When=未指定対象の予定60/15分前案、Where=第8節の未承認候補、How=決定記録→別製造/受入→別実行承認、How many=第4節の最大4試行案、How much=第8節の有界容量案。いずれも実送信・精度改善を承認/保証しない。

## 2. PR123マージ後の実装状態

既存の[運用契約DRAFT-v2](prestart-capture-operational-contract-01.md)、[承認前PreflightとPR123 P2記録](prestart-capture-approval-preflight-01.md)、[オフラインBridge](prestart-capture-offline-bridge-01.md)、[旧証拠契約](c1-racecard-prestart-evidence-contract-01.md)、[旧時点監査](c1-racecard-asof-preflight-01.md)を正本参照し、全文複製や再実行はしない。

| 根拠ID | 今回読んだ実装・資料 | 確認事実 / 未完成の境界 |
| --- | --- | --- |
| E-CODE | [Operational全11PHP](../app/Domain/Keirin/Scraping/PrestartCapture/Operational/Preflight.php)、[既存Command](../app/Console/Commands/Keirin/PrestartApprovalPreflightCommand.php) | 全8判断・独立した人工証拠・固定対象・予算・policy・UTC時計検査。人工カタログはSIMULATED専用、実運用trust storeではない |
| E-P2 | [BudgetPreflight](../app/Domain/Keirin/Scraping/PrestartCapture/Operational/BudgetPreflight.php)、[ApprovalPreflight](../app/Domain/Keirin/Scraping/PrestartCapture/Operational/ApprovalPreflight.php)、[SourcePolicyPreflight](../app/Domain/Keirin/Scraping/PrestartCapture/Operational/SourcePolicyPreflight.php)、[TargetManifestPreflight](../app/Domain/Keirin/Scraping/PrestartCapture/Operational/TargetManifestPreflight.php) | 全履歴＋予定の隣接間隔、全承認の評価/予定両時点、policy/manifestの予定期限を検査。P2修正はmainに含まれる |
| E-BRIDGE | [Contract](../app/Domain/Keirin/Scraping/PrestartCapture/Contract.php)、[CaptureRequest](../app/Domain/Keirin/Scraping/PrestartCapture/CaptureRequest.php)、[CaptureStore](../app/Domain/Keirin/Scraping/PrestartCapture/CaptureStore.php)、[CaptureVerifier](../app/Domain/Keirin/Scraping/PrestartCapture/CaptureVerifier.php)、Projection/応答/Transport等の直下全10PHP | PJ0315、2022〜2025、人工/履歴再生のみ。排他保存・fsync・独立再投影はオフライン版。実HTTP mode・永続scope予算台帳・新運用保存/receiptは未製造 |
| E-HISTORY | MASTER PLAN 15.88/.1、実装記録第8節 | 過去の対象145/829・Bridge/STAT46人工104/660、128M/exit0/警告等0、旧seal一致を保持。今回はテスト/再生/再hashを実施していない |
| E-REQ | 下記原本の指定STAT節とSCR46節 | 取得/公表/決定/予測時刻の分離、全版保持、未確認を補完しない要件。登録脚質は役割ではない |

原本は実在する次のファイルを読んだ。URLエンコード風の名前も実ファイル名であり、推測した復号後パスへ置換しない。

- `/home/shinya/ダウンロード/%E7%B5%B1%E8%A8%88%E3%82%A8%E3%83%B3%E3%82%B8%E3%83%B3%E8%A6%81%E4%BB%B6%E5%AE%9A%E7%BE%A9_STAT-01-46%E7%A2%BA%E5%AE%9A_v1.0(2).md`。STAT01=11〜86、17=2007〜2131、18=2133〜2285、29=3687〜3820、39=4969〜5088、46=5904〜6051行。今回SHA-256=`73b4846b7cdeeb090dbb1c4410e12cb8ba717020f07c77de15e1fee28b7bc4ab`、既存記録と一致。
- `/home/shinya/ダウンロード/スクレイピング残タスク_統計エンジン連携_STAT-01-46確定_v1.0(2).md`。2864〜2937行のSCR-STAT-46-01〜04は全てOPEN。今回SHA-256=`963ae1d64b6f04f1eccbefc039c7228710b950d7d22bcb413cdf17ea921471c3`、既存記録と一致。`/home/shinya/neo-keirin-reference/` は今回存在を確認できず、確認できたダウンロード側を使用した。

SCR46-01の公式イベント/決定・公表時刻、02の全構成とライン変更連鎖、03の依存STAT/旧新予測/stale制御、04の本人適応と構造影響の分離は、既存出走表MVPだけでは全完了でない。原本のOPENを維持する。STAT01の当時の得点、17の多様性と有効性、18の過去継続と直前変更、29の同レース複数時点、39の制度・交絡補正、46の本人/構造/不確実性を混同せず、今回計算やモデル接続はしない。

## 3. D1〜D8判断一覧

`status` は下表の意思決定状態。全項目とも運用承認は存在せず、全体は `PROPOSED_NOT_APPROVED`。値未選択は `UNRESOLVED`、根拠未確認は各 `unverified_evidence` に `UNVERIFIED` と記す。`evidence_verified_at` は限定した資料/コード/ホスト情報の確認時点であって、DecisionRecordの `evidence_verified=true` を発行した意味ではない。下記は文書台帳であり、Command入力/実承認JSONやtrust anchorを生成していない。

### D1

| field | 判断資料 |
| --- | --- |
| decision_id | D1 |
| decision_description | 実対象・全本人基準・許可manifest版の決定 |
| confirmed_facts | 固定本人検証は実装済み。対象日/場/raceは未指定。2026禁止、旧Bridgeは過去年限定 |
| recommended_value | 1場1開催日・最大2男子race・PJのみ。race allowlistと比較基準snapshotを分離する案 |
| alternative_value | 1男子raceだけに縮小、又は具体対象指定まで保留 |
| evidence_reference | E-CODE/E-BRIDGE/E-REQ、第4節 |
| evidence_verified_at | 2026-10-27 JST、コード/原本のみ確認。実対象証拠は未確認 |
| unverified_evidence | 実日/場/番号、内部ID対外部identity、基準本人/予定時刻の出典はUNVERIFIED |
| approval_required_from_user | 対象列挙、承認者/担当者、許可期間、基準snapshotと参照SHA、変更観測範囲 |
| acceptance_criteria | 全対象/全本人・情報源/版/期限を独立資料へ結び、対象外/2026を送信前拒否 |
| blocking_conditions | 未指定対象、帰属曖昧、失効、未承認revision、本人の自動再固定 |
| status | UNRESOLVED |
| implementation_dependencies | 実対象の独立照合・実承認読込み・将来用の別契約版。現v1の年制限は変更しない |

### D2

| field | 判断資料 |
| --- | --- |
| decision_id | D2 |
| decision_description | slot・全scope予算・障害時停止と再開責任 |
| confirmed_facts | snapshotの全状態/重複/間隔/timeoutを純粋検査できる。永続予約/再起動予算回復は未実装 |
| recommended_value | 第4節の60/15分前、2slot/race・全4試行、同時1、間隔60秒、retry0、connect5/total20秒、slot期限60秒の案 |
| alternative_value | 1race又は1slotへ縮小。3slot/6試行案は追加予算の別承認が必要 |
| evidence_reference | E-P2、運用契約4.2節、第4節 |
| evidence_verified_at | 2026-10-27 JST、コードと過去記録を確認。新規テストなし |
| unverified_evidence | 公式公開周期・負荷許容値・実slot成立性はUNVERIFIED |
| approval_required_from_user | 全数値、scope、遅延/障害停止、再開担当者、最終slot後の再確認不能を許容するか |
| acceptance_criteria | 失敗/予約/中断も全runで消費維持。全履歴間隔と評価/予定両時点期限、排他/再起動を別実装で受入 |
| blocking_conditions | 台帳/時刻不明、重複、予算/同時数超過、間隔違反、失効、429/接続障害等 |
| status | PROPOSED_NOT_APPROVED |
| implementation_dependencies | scopeを共有する永続台帳・slot管理・単一試行Transport。今のPreflightに予約効果はない |

### D3

| field | 判断資料 |
| --- | --- |
| decision_id | D3 |
| decision_description | 自動取得・保存・研究利用/共有の根拠と有効期間 |
| confirmed_facts | 公式ポリシー本文を閲覧。複製等の制限と変更可能性の記載がある。対象用途の明示許諾は未確認 |
| recommended_value | 許諾/利用根拠が揃うまで実取得を無効のまま維持。第5節の質問案をユーザーが確認 |
| alternative_value | 公式データ提供/API等の条件を確認して別契約を比較、又は収集保留 |
| evidence_reference | E-POLICY: 第5節の公式2URL、E-CODEのSourcePolicyPreflight |
| evidence_verified_at | 2026-10-27 06:28〜06:29 JST、閲覧ツールで確認した範囲のみ |
| unverified_evidence | 自動取得対象/頻度、非公開研究の許諾、保存/第三者提供、API、改訂/施行日・有効期間、問い合わせ本文はUNVERIFIED |
| approval_required_from_user | 問い合わせ実施可否/担当者、得られた回答と用途/route/頻度/共有/期限を含む条件の承認 |
| acceptance_criteria | 独立した実許諾資料・版/確認日/有効期限を対象bindingと結び、評価時刻と送信予定時刻で有効 |
| blocking_conditions | POLICY_UNVERIFIED、範囲不一致、失効、改訂未確認。公開/HTTP200/少量を許可の代用にしない |
| status | UNRESOLVED |
| implementation_dependencies | 実資料用の承認根拠読込みとpolicy照合。人工カタログを実運用へ転用しない |

### D4

| field | 判断資料 |
| --- | --- |
| decision_id | D4 |
| decision_description | PJ単独の初回範囲と二源未取得の扱い |
| confirmed_facts | 現BridgeはPJのみ。SECOND_SOURCE_NOT_CAPTURED/二源NOT_ESTABLISHEDを保持する |
| recommended_value | 初回PJ0315のみ、JS補完取得0、単一源の研究観測と明示 |
| alternative_value | JSを含む案はD1/D2/D4とJS別契約・別工程承認後に比較 |
| evidence_reference | E-CODE/E-BRIDGE、運用契約6/12/15節、第6節 |
| evidence_verified_at | 2026-10-27 JST、コード/工程別仕様のみ確認 |
| unverified_evidence | 二源の優先/公表/更新順、鮮度の優劣、ライン提供源はUNVERIFIED |
| approval_required_from_user | PJ単独・二源未照合を許容する研究範囲。JS追加は今回承認対象から除外するか |
| acceptance_criteria | T03/T15aと既存回帰を維持。JS送信拒否・二源一致誤認なし |
| blocking_conditions | 未承認JS、day/race binding混同、原値の多数決/現在値補完 |
| status | PROPOSED_NOT_APPROVED |
| implementation_dependencies | 初回PJ部分の別製造。T14/T15bは将来JS工程の必須残件として保持 |

### D5

| field | 判断資料 |
| --- | --- |
| decision_id | D5 |
| decision_description | 出走者/予定変更時の残slot継続か停止か、公式理由不明の扱い |
| confirmed_facts | 旧Bridgeは固定全本人不一致をFAILEDにする。観測差分だけでは公式変更区分を確定できない |
| recommended_value | 原版/失敗保持・UNKNOWN保持・追加取得なしを共通条件に、第6節の全停止案Aを初回の保守候補とする |
| alternative_value | 明示承認済みの残slotだけ観測継続する案B。実装/受入前には選択しても有効化しない |
| evidence_reference | E-BRIDGE/E-REQ、運用契約7節、第6節 |
| evidence_verified_at | 2026-10-27 JST、保存/本人検査と原本要件のみ確認 |
| unverified_evidence | 公式変更区分/理由/決定/公表の取得源はUNVERIFIED。案A/Bの選択はUNRESOLVED |
| approval_required_from_user | 継続/停止の選択、停止scope、再開責任、順延時の再承認、再確認不能を残す方針 |
| acceptance_criteria | A→B→A全版保全、理由不明を不明のまま保持、順延の自動日付移動/本人再固定/3回目取得0 |
| blocking_conditions | 日/場/番号/情報源の矛盾、予算/期限超過、空配列だけによる中止認定、無承認の取得継続 |
| status | UNRESOLVED |
| implementation_dependencies | 変更状態と停止制御の別製造。現D5承認値検査は実イベント検出/自動制御ではない |

### D6

| field | 判断資料 |
| --- | --- |
| decision_id | D6 |
| decision_description | 実時計根拠・誤差上限・証拠有効期間・公式予定の解釈 |
| confirmed_facts | 今回のホストはAsia/Tokyo、同期yes、chrony active/running。offset等の点観測を得た |
| recommended_value | 同期根拠を送信前後で取得し各実時計/単調時計を区別、UTC区間比較。不明/境界接触は停止 |
| alternative_value | 証拠の誤差評価・有効期間が決まるまで全試行保留 |
| evidence_reference | E-CLOCK: 第7節、E-CODEのTimingPreflight、運用契約8節 |
| evidence_verified_at | 2026-10-27 06:29:19〜06:29:25 JST。サービス確認は06:29:45 JST頃、CLI TZは06:37 JST |
| unverified_evidence | 将来送信時の同期、end-to-end誤差上限/証拠TTL、公式RawのTZ/丸め、実発走時刻はUNVERIFIED |
| approval_required_from_user | 同期方式を運用根拠として採るか、測定方法/許容誤差上限/証拠TTL/停止条件/監視担当者 |
| acceptance_criteria | 同じ実行ホスト・有効期間内の根拠と各イベントを対応付け、予定とのstrict区間比較を別実装で受入 |
| blocking_conditions | 同期不明/失効、誤差不明、clock jump/逆転、予定TZ不明、区間接触/重複 |
| status | UNRESOLVED |
| implementation_dependencies | 実時計証拠adapter/receiptの別製造。現TimingPreflightの人工basisは変更しない |

### D7

| field | 判断資料 |
| --- | --- |
| decision_id | D7 |
| decision_description | private保存先・容量枠・保持・backup/復旧の責任 |
| confirmed_facts | 親はshinya:shinya/0775、ext4 /home。空き107,705,253,888bytes。候補rootは未存在/未作成 |
| recommended_value | 第8節の専用private0700/file0600・umask077、有限容量案、全版/失敗保全・自動削除0 |
| alternative_value | 対象を1raceへ縮小、又はユーザー指定の別保全先を別承認。自動で保存先変更しない |
| evidence_reference | E-STORAGE: 第8節、E-BRIDGE、運用契約9節 |
| evidence_verified_at | 2026-10-27 06:29:45 JST頃のdf、06:28〜06:29 JSTのstat。ホストmountは06:30 JST頃 |
| unverified_evidence | 専用root権限/実書込み・容量予約・最大時保存、保持期限、backup媒体/頻度、復旧担当/試験はUNVERIFIED |
| approval_required_from_user | 正確なroot/所有者/共有範囲、全容量値、保持期限日、backup先・媒体/頻度/担当、復旧責任者 |
| acceptance_criteria | 送信/各保存前の残容量と失敗分確保、衝突/link/mount拒否、別root復旧後の元期待SHA/版/件数一致を別承認で確認 |
| blocking_conditions | root未承認、権限/容量不足、予約不成立、backup/保持責任未決、保存/flush/fsync/公開検証失敗 |
| status | UNRESOLVED |
| implementation_dependencies | 承認後の専用root検査・運用保存器/容量制御/復旧手順。今回rootを作成・試験しない |

### D8

| field | 判断資料 |
| --- | --- |
| decision_id | D8 |
| decision_description | 限定研究観測と予測入力・正式利用を分離 |
| confirmed_facts | 現出力はprediction_use=NOT_AUTHORIZEDを固定。COMPLETE/VERIFIEDも予測利用の許可ではない |
| recommended_value | 研究用観測だけを今後の承認候補とし、予測適格性Gate/モデル接続を別工程へ保留 |
| alternative_value | 研究パイロット自体も保留。観測承認から予測を自動有効化する案は採らない |
| evidence_reference | E-CODE/E-BRIDGE/E-REQ、運用契約10節、第9節 |
| evidence_verified_at | 2026-10-27 JST、固定禁止値/原本を確認。精度評価は未実施 |
| unverified_evidence | 本物の発走前利用可能性、入力完全性/版選択Gate、予測効果はUNVERIFIED |
| approval_required_from_user | 研究目的/出力/担当者/共有範囲と停止条件。予測利用は今回の承認候補に含めない |
| acceptance_criteria | 研究資料とモデル入力を分離し、予測/STAT配点/モデル更新/2026参照0を維持 |
| blocking_conditions | C1/STATへの無承認接続、事後データ混入、B1/lambda選択、LIVE/holdout開放 |
| status | PROPOSED_NOT_APPROVED |
| implementation_dependencies | 今回追加実装なし。将来の予測適格性Gate/比較は別承認・別受入 |

## 4. D1/D2パイロットの条件案

| 条件 | 推奨案（全てPROPOSED_NOT_APPROVED） | 変更の影響/不足 |
| --- | --- | --- |
| 対象 | 1場1開催日、最大2男子race、PJ0315単独 | 実対象はUNRESOLVED。1raceへ縮小可能。ユーザー以外が選定しない |
| slot | 予定60分前/15分前、2slot/race、slot開始期限60秒以内 | 公式公開周期未確認。遅延の取り返し/未使用枠の別race転用なし |
| scope予算 | 最大4試行/承認対象日・全run、同時1、全開始間60秒以上 | FAILED/RESERVED/INTERRUPTEDも消費扱い。再起動/改訂でリセットしない |
| 送信 | retry0、connect timeout5秒/total20秒 | 1試行1capture。429・5xx・timeout・接続障害はscope停止、再開は別承認 |
| 期限 | 全承認が評価/予定両時点で有効、policyも両時点、manifestも予定時点で有効 | `planned >= expires_at` は失効。時刻不明や期限延長の自動救済なし |

具体的な日/場/番号・内部race ID/外部identity・カテゴリー・本人snapshotの出典/取得時刻/SHA、manifestのauthorization/revision/previous SHA/承認者/承認日時/期限をユーザーが指定・確認する。**対象許可と基準snapshotは別物**。新しい本人集合を観測しても自動的に許可対象へ組み込まず、旧基準は固定する。共有物にはencp生値を載せない。今回は実manifestを生成しない。

現BudgetPreflightは台帳snapshotの検査であり、永続台帳の正しさ/排他性を証明しない。60秒等値受入、59.999999秒/履歴同士1秒差/同時刻/別race/入力逆順の違反検出と、各予定期限のP2修正は保持する。slot予定自体の遅延期限・429停止・再開/クラッシュ回復は別製造/Mock受入が必要。slot同士が60秒条件と衝突しても勝手に後ろへずらさず、未送信/再承認で扱う。

2026開催はmetadataも参照しない。2027以降も年だけの許可は不可。旧Bridge v1の2022〜2025ガードを外さず、将来実対象は別契約版/受入/個別実行承認が揃うまで扱わない。

## 5. D3利用条件の確認結果と不足証拠

参照日時は2026-10-27 06:28〜06:29 JST。閲覧対象は次の公式静的2URLだけで、出走表・結果・会員ページ、内部データendpoint、robotsの新規確認、フォーム送信は行っていない。

| 公式資料 | 今回確認できた範囲 | 未確認 |
| --- | --- | --- |
| [サイトポリシー](https://keirin.jp/pc/dfw/portal/guest/policy/index.html) | 法定例外を除く複製/転用の制限、私的利用を超える無断の対価取得の禁止、予告なく変更し得る旨を確認 | この目的の自動取得/保存/非公開研究/第三者提供の具体許諾、対象/頻度/負荷、公開API、改訂/施行日・有効期間 |
| [公式お問い合わせページ](https://keirin.jp/pc/dfw/portal/guest/site/otoiawase.html) | ポリシーからの参照先。閲覧ツールではobject対応ブラウザ向けの案内1行のみ取得 | 問い合わせ本文/フォーム/担当窓口・連絡先/手続/回答条件。推測した宛先へは送信しない |

本文の確認と、この用途が許されることの確認は別。非公開・少量・公開情報であるだけでは許可と判断しない。私的使用等の例外への該当性を本書は法的に認定しない。上記2ページから確認できないAPIを「存在しない」と断定せず、内部JSON実装を公式データ提供契約ともみなさない。許諾根拠はUNVERIFIED、D3の意思決定はUNRESOLVEDのまま。過去のrobots取得不能という記録は過去記録であり、今回は再検証していない。

### 問い合わせ質問文案（未送信）

> 非公開研究として、男子の固定1場・1開催日・最大2レースの出走表を、予定60分前と15分前に各1回、合計最大4試行で観測する案を検討しています。具体対象は未指定で、2026年開催は対象外です。PJ0315を利用する方式が許されるか、公式データ提供手段を利用すべきかを確認したく存じます。
>
> 自動アクセスの可否、対象URL/方式、許容頻度・同時数・時間帯・User-Agent/連絡先、障害/429時の条件をご教示ください。想定は同時1・開始間60秒以上・自動retry0ですが、これは未承認案です。
>
> 原文をアクセス制限付きで保存し、公開せず研究/監査に用いる場合の許諾要否と手続、保管期間・backup条件、識別子/hash/判定要約だけをレビュー担当へ共有する場合の条件をご教示ください。Raw再配布は予定していません。
>
> 公式データ提供/APIの有無と申請・費用・利用条件、ポリシーの版/施行日、回答の対象範囲・有効期限・改訂時の再確認先もご教示ください。今回は問い合わせ案の作成だけで、取得や予測利用は開始していません。

質問を誰がいつ送るか、回答をどの承認資料へ固定するかはユーザー決定待ち。権利者/管理者の確認が必要な点を、ユーザーの技術設定承認だけで充足したことにしない。

## 6. D4/D5情報源・変更対応

初回はPJ単独案。`SECOND_SOURCE_NOT_CAPTURED`、二源整合 `NOT_ESTABLISHED` を維持し、JSJ017で補完しない。JS追加時だけ別契約・別工程とD1/D2/D4再承認を必要とし、T14/T15b（共有Raw/複数race/二源矛盾・取得順反転）の将来必須残件を残す。初回のT03/T15aとT25/T26の既存回帰は必須のまま。

| 変更後の選択肢 | 得られること / 失うこと | 必須条件と影響 |
| --- | --- | --- |
| 案A: 残取得を停止して人手レビュー（初回の保守候補） | 負荷と本人誤認を抑えるが、変更後の継続観測・再確認が欠ける | 全版/FAILED保持、停止scope/再開責任を決定。未実施slotを別枠へ移さない |
| 案B: 許可済み残slotだけ研究観測を継続 | 再観測できる場合があるが、最終slot後の変化は再確認不能 | 変更観測範囲を事前承認。固定raceの帰属/カテゴリー/source/時計/容量/予算/期限が成立する場合のみ。本人差分は保留し基準を自動更新しない |

いずれの案も、日/場/番号/取得元の不一致・本人識別不能・中止/順延・停止条件・期限/予算超過を継続許可で救済しない。中止は当該raceの残slot停止、順延/予定改定は旧計画を保持して保留・再承認とし、自動日付移動や追加取得なし。全scope停止か当該race停止かの運用粒度と担当者をD5で明示する。

現v1は固定全本人差分をFAILEDにするため、案Bを選んでも現コードで正常受入できると主張しない。将来の変更観測用の別契約/テストが必要。追加/消失だけから補充・欠場・取消を認定せず、理由/決定/公表時刻はUNKNOWN/NULL。A→B→Aは全3観測、公式イベントとParser/情報源訂正を分離。STAT配点・stale解消・予測再計算は起動しない。

## 7. D6時計・同期状態調査

ホストのread-only点観測。sandbox内の `timedatectl status/show` はsystem bus接続不可で各exit1。許可されたホスト読取りで再実行したところ両方exit0。`command -v chronyc` は `/usr/bin/chronyc`、exit0で、既存chronyに対する照会だけを実施した。設定/時計/サービス操作・インストール・同期要求は0。

| コマンドと確認時点（JST） | 確認した出力 | exit |
| --- | --- | ---: |
| `timedatectl status` 06:29:19 | Local=2026-10-27 06:29:19 JST、UTC=2026-10-26 21:29:19、Asia/Tokyo +09、synchronized=yes、NTP active、RTC local TZ=no | 0 |
| `timedatectl show` 06:29:20 | Timezone=Asia/Tokyo、LocalRTC=no、CanNTP=yes、NTP=yes、NTPSynchronized=yes | 0 |
| `chronyc tracking` 06:29:20〜25 | Stratum3、reference time UTC=2026-10-26 21:21:26、Leap Normal、system time=0.000751905秒fast、last offset=-0.000148008秒、RMS offset=0.002163700秒 | 0 |
| 同tracking | root delay=0.241015851秒、root dispersion=0.008723503秒、update interval=387.8秒、skew=6.494ppm | 0 |
| `chronyc sources -v` 同区間 | 5 source、選択`^*`1・合成`^+`2・非合成`^-`2、reach全377。選択sourceのLastRx478秒、adjusted +3231us / measured +3083us、推定error ±121ms | 0 |
| `systemctl show chrony.service -p Id -p LoadState -p ActiveState -p SubState -p UnitFileState -p ActiveEnterTimestamp` 06:29:45頃 | loaded/active/running/enabled、ActiveEnterTimestamp=2026-10-27 05:45:40 JST | 0 |
| `/usr/bin/php -r 'echo date_default_timezone_get();'` 06:37頃（TZ値だけの確認） | CLI PHPの既定はUTC。アプリbootstrapは実行していない | 0 |

同期方式は今回応答したchronyによるNTPと確認できるが、認証方式や全設定の監査はしていない。systemd-timesyncd利用の追加調査は不要として実施しなかった。serviceの起動時刻とNTP reference timeは別物。上記は別コマンドの点観測なのでsourceのLastRxとtrackingの時刻を同じ測定として合成しない。

**同期yesは時刻精度保証ではない**。system time/last/RMS offset、sourceごとの推定error、root delay/dispersionは別量であり、±121msをホスト全時計/将来通信の厳密な誤差上限へ転用しない。未確認誤差を0へ補完せず、保証できるend-to-end上限と証拠TTLはUNRESOLVED。今回のreference timeを将来の送信時刻証拠へ再利用しない。

ユーザーは同期方式/記録対象/最大許容誤差/証拠有効期間/停止・再開担当を決める。次工程では同一ホストの送信前後の同期根拠とUTC/単調時計を、要求開始・受信・fsync後保存・検証/公開完了へ対応付ける必要がある。既存TimingPreflightは人工時計だけを受け入れるため、今回の実ホスト情報を投入して承認済みにする経路はない。

公式RawのTZ/分精度丸め・実発走/公式公表時刻は今回未調査。ホストAsia/Tokyoを公式資料のTZ証明にせず、CLI PHP既定UTCとも区別する。将来の実行プロセスはoffset付きUTC計測とJST表示を明示する必要がある。既存の区間strict比較/接触拒否/不明時UNKNOWNを維持し、許容誤差の新数値は決めない。

## 8. D7保存・容量・復旧調査

未承認候補は `/home/shinya/neo-keirin-artifacts/prestart-capture-live-pilot-01/`。今回は作成していない。既存親領域のmetadataだけを確認し、ファイル列挙・Raw/bundle/Trashへの操作・書込み試験・backup媒体探索はしていない。

| read-only確認 | 実測/確認結果 | 範囲と制限 |
| --- | --- | --- |
| `stat -c '%F %a %U %G %n'` 親と候補、06:28〜06:29 JST | 親directory、shinya:shinya、0775。候補はNo such file（合算exit1） | 親statは成功、候補は未存在。専用rootの権限/所有/実書込みは未検証 |
| `df -B1 /home/shinya/neo-keirin-artifacts` 06:29:45 JST頃、exit0 | /dev/nvme0n1p2、/home、総量474,451,066,880、使用342,569,844,736、available107,705,253,888bytes、77% | availableは非特権向けの瞬間値。予約済み容量・将来保証ではない |
| `findmnt -T /home/shinya/neo-keirin-artifacts -o SOURCE,FSTYPE,TARGET,OPTIONS` exit0 | ホストはext4 /home、rw,relatime | sandbox側のro,nosuid,nodev表示とは分離。ホストのread-only障害とは誤認しない |

| ユーザー判断対象 | 既存提案値（PROPOSED_NOT_APPROVED） | 未確認/停止条件 |
| --- | --- | --- |
| private/summary | 上記候補内のunique run、private分離、実行者所有0700/file0600、umask077 | 親0775は専用private権限の証明でない。正確なroot/閲覧者/共有範囲を承認後、既存path/保護path/link/mountを検査 |
| 最大body/metadata/log | 4MiB/試行、1MiB/試行、4MiB/run | 実最大サイズ/圧縮展開後/途中失敗を受入前。超過を完全Raw/COMPLETEとしない |
| run/開始空き | run64MiB、開始空き128MiB以上 | 今回availableが案の閾値を上回ることは保存保証でない。各送信/保存前に失敗分も確保、不能ならSTOP。/tmp fallbackやrun変更で上限回避なし |
| 保持期間 | 審査/復旧確認まで全版・失敗保全、自動削除0 | 確定保持期限日、法的条件と容量運用はUNRESOLVED。期限到来だけで自動削除しない |
| backup | Raw/manifest/外部期待SHA/承認・試行台帳/実行時コードと依存版を一組、private権限維持 | 保存先・媒体・頻度・担当者はUNRESOLVED。今回backup取得/完全性/復元可能性を検証していない |
| 復旧 | 別承認・別rootで元期待SHA/版/件数を独立Verifierと照合後に参照切替 | 復旧担当/手順/試験未決。原本修復上書き/再seal禁止、一覧検査と復元成功を混同しない |

原本・旧束・失敗資料は後片付け対象外。破損発見時は論理的参照除外＋追加監査記録を先行し、物理移動は既定でしない。人工一時領域だけ既存helperの作成時保持pathを使い、許可済みTrash内への同filesystem rename-only。永久削除・親推測・既存Trash整理・copy+delete fallbackは不可。今回は一時領域生成/退避/削除も0。

## 9. D8研究用途と予測利用の分離

今後承認候補にするのは有限の研究用観測だけ。`COMPLETE` は保存検証の終端、`VERIFIED` はその版の限定した照合結果であり、実発走前/当時の利用可能性/最終予測適格性を一括承認しない。

既存C1/C2/BT・STAT正式入力/配点、正式な新予測生成、B1/親lambda/H1-U、2026/holdout/LIVEは対象外。事後取得した過去資料を当時の予測入力へ混ぜない。最終入力Gate・使用版/欠損/訂正/二源矛盾の扱い・必要なら性能比較は別の明示承認/受入であり、研究利用への同意だけでは開始しない。精度改善は `NOT_MEASURED_THIS_CHANGE`。

## 10. 承認条件チェックリスト

`[x]` は今回の資料確認だけ、運用APPROVEDを表さない。未チェックは失敗ではなく未成立/未決。

- [x] PR123 MERGED・開始main一致、P2の全履歴間隔/予定時点期限がソースに存在することを確認。
- [x] 公式静的2ページの確認範囲と許諾未確認を区別し、未送信の質問文案を作成。
- [x] ホスト同期の点観測と将来の精度/証拠有効期間を区別。
- [x] 既存親の容量/権限と未作成の専用rootを区別。
- [ ] D1: 実日/場/race/全本人基準、独立identity根拠、manifest版/承認者/許可期間を決定。
- [ ] D2: 全slot/予算/timeout/失敗停止/再開責任を決定。
- [ ] D3: 対象用途の実許諾・条件/期限と送信先binding、問い合わせ担当を確認。
- [ ] D4: PJ単独・JSなし・二源未照合の研究範囲を承認。
- [ ] D5: 案A/Bと停止粒度、順延再承認、再確認不能/理由UNKNOWNの保持を選択。
- [ ] D6: 誤差評価/上限/証拠TTL、公式予定TZ/精度根拠、同期異常停止を決定。
- [ ] D7: 保存root/全容量枠/保持期限/backup媒体・頻度・担当/復旧責任を決定。
- [ ] D8: 研究用途/共有範囲/担当/禁止事項を承認。予測利用は別工程のまま。
- [ ] 実承認資料/実時計/Transport/永続台帳/運用保存の限定製造を別承認し、工程別Mock/独立128M/既存回帰/コードレビューを受入。
- [ ] 具体的なコマンド・対象・実行日時・停止/再開担当を別途承認してから限定実行。

## 11. 次工程の着手条件・STOP条件

**現在許可される次作業はD1〜D8判断資料のレビューだけ**。ユーザーは未決項目を個別に承認/修正/保留できるが、文書レビューや値の選択だけで実収集へ移らない。

1. 判断の根拠/値/未決をレビューする。問い合わせ送信も別の明示指示が必要。
2. 根拠とD1〜D8が具体化された後、限定実装の範囲を別途承認する。実運用用の根拠読込・単一試行Transport・永続台帳・実時計/容量/保存/独立検証はまだ不足する。
3. [運用契約第12節](prestart-capture-operational-contract-01.md)の当該工程必須条件を受入する。初回PJのPILOTとEXISTINGが必要。T14/T15bは別JS工程未承認の条件付き残件で、今回不要/成功済みにはしない。
4. 製造/Mock/コードレビュー後も、具体対象/実行時点の承認・D3有効性・D6同期根拠・D7権限容量を再確認し、別実行承認がなければ送信しない。

STOP: 実対象未指定、2026/対象外、identity/source矛盾、根拠/期限不明・失効、同期/誤差/公式予定不明、容量/権限/失敗保全不成立、予算/slot/重複/間隔違反、未承認のJS/自動再送/日付移動、未受入の運用コード、予測接続の要求が出た場合。未知値や人工カタログで補完せず、旧資料は保持する。全条件が揃っても2026凍結やB1保留を解除しない。

## 12. 未解決事項・ユーザー決定事項

| ID | ユーザーが次に決める具体事項 | 確認できていない根拠 |
| --- | --- | --- |
| D1 | 具体的な対象と本人基準、許可期間/担当者を指定するか保留 | 実対象identity/snapshot/予定の出典 |
| D2 | 第4節の数値一式を採るか縮小/保留、障害時の再開責任 | 公式更新周期/許容負荷、実台帳/slotの受入 |
| D3 | 第5節の質問内容と問い合わせ担当・送信可否を決める | 明示許諾/API/保存共有条件/改訂日・期限/窓口本文 |
| D4 | 初回PJのみ・二源未確認の研究を認めるか | 二源の優先/鮮度やライン提供源 |
| D5 | 案A全停止か案B残slotのみ継続か、停止粒度/再承認 | 公式変更理由/区分/決定・公表時刻 |
| D6 | 同期根拠の採用方法、誤差上限/TTL/障害担当 | 将来の時計保証、公式予定TZ/丸め、実発走 |
| D7 | root・容量枠・保持期限・backup媒体/頻度/担当・復旧責任 | 専用rootの実受入、backup/復元可能性 |
| D8 | 研究目的/共有範囲を承認するか保留。予測は引き続き不可 | 最終予測適格性/性能効果 |

今回の完了対象は**判断資料の作成**であり、運用判断そのものは未完了。PHP/設定/Migration/テスト・原本.env/storage/Raw/bundle/旧証拠/code seal/モデルは変更せず、既存証跡を再sealしていない。今回のPHPUnit/Pint/過去監査/Raw再生/学習/予測は未実施で、過去145/829・104/660やユーザー全体4101/42432/155 warnings/9 skippedを今回の結果としない。過去全警告の原因/解消は未確認のまま。

文書は相対リンク・表・全8台帳の13field・状態/固定禁止値・旧履歴保持と変更範囲を静的検証する。`git diff --check`、`git status --short --branch`、`git diff --stat`を確認して、専用design branchの未コミット差分を保持しレビュー待ちで停止する。commit/push/PR作成/mergeは行わない。

### 今回の文書検証

read-onlyのPHP静的検証で12節、各D1〜D8の13field/未承認状態、相対参照先、表列数/コード区切り、現在地/禁止値/B1保留、指定2文書だけの変更を確認。MASTER PLANの15.88/.1・既存変更履歴・Frozen/Unfrozen・Goal表は開始HEADと一致した。PHPUnit/Pintを実行したという意味ではない。

`git diff --check` はexit0。新規未追跡文書への `git diff --no-index --check /dev/null docs/prestart-capture-decision-evidence-01.md` は空出力/exit1（新規差分ありの終了値で、空白エラーなし）。通常の `git diff --stat` は追跡済みMASTER PLANのみを表示するため、新規文書を含む変更はstatusで2件と照合した。HEAD/main/origin/mainは開始SHAのまま、未コミットでユーザー判断資料のレビュー待ち。
