# STAT39-OFFICIAL-RULE-SOURCE-LEDGER-01

- 作成日: 2026-10-15
- 状態: **未承認の出典台帳・制度照合設計案 v0.1**
- 工程: `STAT39-PRESTART-ASSIGNMENT-EVIDENCE-CONTRACT-01`
- 作業開始HEAD: `85c454aefa79129be87e81c1e2c2737afe14dc3d`（PR #107 merge）
- 対文書: [発走前属性・変更履歴契約案](stat39-prestart-assignment-evidence-contract-01.md)

## 1. 公式制度資料の確認対象

対象は7車/9車の車番・枠制度、枠適用/非適用、通常/モーニング/ナイター/ミッドナイト等の公式意味、番号割当方式と得点順位との関係、grade/競走区分例外、改正・経過措置・適用期間。時間帯ごとに割当方式が必ず違うとは仮定しない。

今回はコードとユーザー保存資料だけの確認で、HTTP検索/ダウンロード/Raw本文監査/DB接続は0。公式規則の原文・条項・適用版は確認できていない。以下の台帳は不足証拠を列挙するもので、正式マスタではない。

参照したのは [前工程要件](stat39-extension-requirements-01.md) Sections 10〜14/18、[既存研究](stat39-bike-frame-probability-research-01.md) Sections 7/8/10/16、[MASTER PLAN](statistical-engine-master-plan.md)の制限、[RaceScheduleParser](../app/Domain/Keirin/Scraping/Parsers/RaceScheduleParser.php)、[RaceDetailParser](../app/Domain/Keirin/Scraping/Parsers/RaceDetailParser.php)等のコード、および次の保存原本。

`/home/shinya/ダウンロード/スクレイピング残タスク_統計エンジン連携_STAT-01-46確定_v1.0(2).md`

SHA-256 `963ae1d64b6f04f1eccbefc039c7228710b950d7d22bcb413cdf17ea921471c3` を今回再確認。SCR-STAT-39-03等はOPEN。原本は「公式規則を取得する必要性」の要件であり、公式割当規則そのものではない。前工程が参照した統計要件原本も公式制度資料へ昇格させない。

一般参考の `/home/shinya/ダウンロード/競輪でのコースによる優位性レポート.md` は今回読取り確認した。サンプル代替/例示値の説明を含み、公式原文・条項の特定もないため、制度・数値・発行主体を確認する一次根拠に採用しない。

確認範囲はこれらの指定資料と関連repo文書/コード。全ローカル保存場所・すべての歴史公式資料を探索したとは言わない。未入手を制度不存在と判断しない。既存モデル・成果物は参照/変更しない。

## 2. 公式資料の採用基準

| source_class案 | 該当する資料 | 扱い |
|---|---|---|
| OFFICIAL_RULE | 発行主体・原文・版・条項と対象制度を公式に確認した資料 | Raw/hashと適用範囲の審査後だけ検証済みrule候補。URLが公式らしいだけでは不可 |
| OBSERVED_DISTRIBUTION | 既存研究に記録されたDB内の番号/枠/得点/属性分布 | 観測事実。公式方式/制度期間を証明しない |
| GENERAL_REFERENCE | 一般解説・調査レポート・要件/残タスク文書 | 調査の手掛かり。公式規則・正式対応表の代用品にしない |

採用には、正当な発行主体、文書名、完全な保存原文とSHA-256、版/条項/ページ、対象制度・適用範囲・改正との関係を確認する。文書内記載と抽出主張をpointerで結ぶ。公式URLと取得日も保存するが、取得日を公開日/適用開始日にしない。

署名・真正性の根拠、訂正/失効、全文の欠落、本文と附則の不一致が未解決ならOFFICIAL_SOURCE_NOT_VERIFIED。版が複数なら全て保持し、都合の良い説明を選ばない。公式資料であっても対象競走と期間が不明なら当該raceへの適用は未確認。

数値分布、得点が高い選手の低い車番への集中、時刻、開催名、一般常識から公式方式を確定しない。車番と物理的スタート位置も別概念とする。

## 3. 車番・枠番割当制度の出典台帳

### 3.1 データ辞書案

以下は論理field案。DB名・Enumを確定せず、Migrationを作らない。

| field | 保持する内容・検証 |
|---|---|
| rule_id | 根拠を受入れた規則の内部識別子。下記調査IDとは別。未確認ならNULL |
| official_document | 正式文書名。推定名称で埋めない |
| issuing_body | 公式に確認した発行主体。一般レポートの説明を転記して確定しない |
| document_version | 文書版/改正番号、確認できなければUNKNOWN |
| section | 条項/ページ/表番号と保存原文pointer |
| official_url | 実際に確認した公式URL。今回はNULL、想像したURLを登録しない |
| source_hash | 保存した公式原文bytesのSHA-256。研究/要件文書hashとは別 |
| published_at | 公式公開日時とTZ/出典。未確認NULL。取得時刻とは別 |
| effective_from / effective_to | 制度上の適用開始/終了、日/日時粒度・TZ・境界規則も保存 |
| field_size | 公式制度が対象とする人数の意味・範囲。登録/定員/実発走の区別 |
| event_category | 公式開催種別・定義版。session/grade等の軸を混ぜない |
| race_class | 公式対象競走区分・段階・例外の参照 |
| assignment_method | 公式方式原文と正規化版。無作為/規則/不明を区別 |
| frame_mapping | 根拠付き車番↔枠対応、適用/非適用と例外。NULL欠損から作らない |
| verification_status | 未確認/確認待ち/検証済み/矛盾等。資料検証とrace適用判定は別 |
| evidence_reference | 公式原文・取得capture・抽出/審査revisionの参照。引用先だけで原文を代用しない |
| unresolved_reason | 不足する文書/範囲/期間/時点/例外の理由を複数保持 |

追加案はsource_class、rule_revision_id、supersedes_revision、recorded_at、verification_completed_at、rule_evidence_published_at、原文取得時刻、適用scopeの条件式/版、附則・例外・経過措置、審査記録。制度が有効だった時点と、システムがその版を知り利用可能になった時点を分離する。

### 3.2 未確認の調査レコード

R01〜R12は**調査要求IDであり実在規則IDではない**。各行のrule_id、official_document、issuing_body、document_version、section、official_url、source_hash、published_at、effective_from/to、assignment_method、frame_mapping、公式event_category/race_classはすべてNULL/UNKNOWN。下表の対象は調査したいscopeで、確認済みrule fieldの値ではない。

| 調査ID | 調査対象scope・主張候補 | verification_status | 不足証拠・evidence_referenceの現状 |
|---|---|---|---|
| R01 | 7車の車番割当 | OFFICIAL_SOURCE_NOT_VERIFIED | 方式/対象区分/期間。前工程・研究は背景参照のみ |
| R02 | 9車の車番割当 | OFFICIAL_SOURCE_NOT_VERIFIED | 同上。無作為と断定できない |
| R03 | 7車の枠制度・適用/非適用・対応 | OFFICIAL_SOURCE_NOT_VERIFIED | 公式定義。保存NULLを非適用とする証拠なし |
| R04 | 9車の枠制度・適用/非適用・対応 | OFFICIAL_SOURCE_NOT_VERIFIED | 公式対応表/対象範囲。保存分布は規則でない |
| R05 | 通常開催の公式定義 | OFFICIAL_SOURCE_NOT_VERIFIED | 名称/区分・元HTML属性値との対応 |
| R06 | モーニングの公式定義 | OFFICIAL_SOURCE_NOT_VERIFIED | 定義/適用範囲/対応版。時刻から分類しない |
| R07 | ナイターの公式定義 | OFFICIAL_SOURCE_NOT_VERIFIED | 同上 |
| R08 | ミッドナイトの公式定義 | OFFICIAL_SOURCE_NOT_VERIFIED | 同上。得点順割当の有無も独立に確認する |
| R09 | 車番割当と競走得点順位の関係 | OFFICIAL_SOURCE_NOT_VERIFIED | 使う得点の定義/基準日時、同点/欠損時規則、対象と例外 |
| R10 | grade・競走区分・段階の例外 | OFFICIAL_SOURCE_NOT_VERIFIED | 全体規則と特例の関係、対象開催/競走の特定 |
| R11 | 改正/制度の開始終了 | OFFICIAL_SOURCE_NOT_VERIFIED | 旧新本文・附則/告示、境界と経過措置 |
| R12 | data-pprm-dkbn/day_kindの公式対応 | OFFICIAL_SOURCE_NOT_VERIFIED | 属性の公式定義/適用版。保存1を通常へ変換しない |

有効期間不明を「無期限」、対象不明を「全競走」、rule不明を無作為へ既定変換しない。完全な下書きrecordの全項目は3.1/3.2の共通NULL宣言と各調査scopeから構成できるが、公式資料レコードとして公開しない。

## 4. 7車・9車の制度確認項目

7車/9車は別々に、対象人数の意味、欠番、欠場/補充、車番変更時の再割当、枠非適用条件、対応表と人数/同枠複数選手の扱いを原条項で確認する。grade/競走区分・開催単位と単一raceの適用範囲も区別する。

既存研究の9車bike1/2/3→frame1/2/3、4/5→4、6/7→5、8/9→6という記録はOBSERVED_DISTRIBUTION。7車の少数非NULLと大量NULLも保存観察であり、公式な適用/非適用・対応表ではない。今回DBから再取得していない。

枠NULLの原値を保ち、公式規則と対象一致が確認できた場合だけ、別のapplicabilityにNOT_APPLICABLEを付ける。規則の欠損や原値欠損をその理由へ流用しない。選手単位の枠成績と「同枠の誰かが入着」のイベントも制度確認とは別の統計定義。

## 5. 開催種別の公式定義確認項目

通常/モーニング/ナイター/ミッドナイト/その他・不明について、発行主体が定める名称・対象・定義、適用期間、例外、表示属性との対応を別々に確認する。開催時間帯から方式を仮定しない。

RaceScheduleParser::parseはdata-pprm-dkbnをdayKindへ文字列のまま渡す。保存1の意味は未確認。sessionと開催grade、race class、選手級班、時刻は別軸。grade icon解析の存在もsessionの定義証明ではない。

未知はUNKNOWN、OTHERも公式分類根拠がある場合だけ。得点順の相関や開催名部分一致で穴埋めしない。将来の対応表には元原語/原値、公式根拠、対応版、適用対象・期間を要求する。

## 6. 制度適用期間と改正履歴

規則本文、改正文書、附則、経過措置を別の不変revisionとして保存し、どの主張がどの条項から来るかを追跡する。新発見/訂正/失効はsupersedes関係をappendし、過去rule/原文/判定を消さない。hashが同じ再取得も取得履歴は別。

適用開始/終了は公式原文から決め、初回取得日、DBに現れた日、最初の対象レースから推定しない。日付粒度か日時粒度か、境界を含むか、適用基準が開催初日かrace日かも確認する。実装上の半開区間に変換する場合は、原文の包含規則と変換版を残しUNKNOWNを勝手に+1日処理しない。

履歴再現では「制度上の適用期間」と「その規則証拠がcutoffまでに利用可能だった期間」を両方照合する。後日入手した公式資料で歴史制度を説明できても、当時の予測入力に使用済み/利用可能だった証明にはしない。

## 7. 例外・経過措置

開催日・車立て・競走区分・開催種別・grade・段階、改正境界、対象開催、取消/補充など、原文に明示された条件をそれぞれ版付きpredicateとして記録する。通常規則と特例の優先関係も公式条項を要求する。

必要条件の値または公式意味が不明ならUNKNOWNとして適用を保留する。例外が確認できないことを例外なしとせず、異なる期間/クラスへ近似規則を適用しない。公式に確認した排他的scopeを表現できる場合だけ候補を絞る。

## 8. 規則競合時の処理

matcher案の入力は、seal済み対象raceの日/場/外部identity、観測登録人数と人数定義、競走区分/sessionの公式対応版、開催開始等の規則が必要とする項目、cutoff/証拠版。現在DB値へのfallbackは禁止する。

| 段階 | 判定案 |
|---|---|
| 1 | OFFICIAL_RULEかつ原文/hash/審査が検証済みのrevisionだけを候補にする |
| 2 | race日/開催日等の公式適用軸、車立て、class/session、期間、例外/経過措置を全照合。不明predicateは確定一致にしない |
| 3 | 用途ごとに一致0件ならRULE_VERSION_UNKNOWN。不明条件が残れば同様に保留。近い制度を採用しない |
| 4 | 同一用途に複数規則一致ならRULE_CONFLICT。新しい日付/多数決/一般優先/小さいIDで自動選択しない |
| 5 | 1件でも原値/観測所属と矛盾ならRULE_CONFLICT。Rawを修正して規則へ合わせない |
| 6 | 一意・矛盾なしならrule_revisionと全証拠/policy hashを判断へ固定。cutoff利用可能性と予測利用承認はさらに別 |

無関係な用途（枠制度とsession定義等）のruleを互いに競合扱いしない。一方、同じ用途の重なりは、正式にscope/改正関係を整理した新revisionのレビューまで保留する。規則UNKNOWNでも観測車番そのもののRaw証拠は保持し、規則を使う補正用途だけBLOCKEDとする。

## 9. 公式根拠未確認の一覧

- R01〜R12の文書名・発行主体・公式URL・版・条項・原文hash・公開日・適用期間・例外は未確認。今回は公式資料受入0件。
- 7車枠NULLが非適用か未取得か、9車の保存対応がどの規則/期間に基づくかは未確認。
- day_kind=1の意味、session別に割当方式が違うか、得点順位が割当に使われる範囲/基準時点は未確認。
- 既存得点/番号の原値が予測時点で利用可能だった証明も別の不足。公式制度が分かっても後日現在値を発走前へ昇格しない。
- 本書は全保存場所の不存在調査ではない。新しい公式資料が提示された場合はその実在/出典/hash/版を確認してから新revisionを提案する。

原本SCR-STAT-39-03の「無作為・規則割当・不明を区別」「改正前後の効果を分離」は、今は不明を保持する設計まで。SCR-STAT-39-01の枠非適用判定、02の変更、04の初手/ライン統合も未完了・OPENを維持する。

## 10. 次の公式資料調査の取得計画

**実行は設計レビュー後の別許可。** 次に何を取得・照合するかを示し、今回はURLを作成・アクセスしない。

| 調査まとまり | 必要な原資料 | 取得後の照合・保存 |
|---|---|---|
| R01〜04 | 正当な制定主体の車番/枠制度・番組編成規則、その附表、7車/9車・適用外の定義 | 文書名/発行主体を公式経路で確認し、全文/附表/ページ/hash、対象人数の意味、欠番/変更時の例外を照合 |
| R05〜08/R12 | 公式開催区分の定義と、公開HTML属性data-pprm-dkbnに対応する仕様または公式説明 | 名称と元値の対応・版・期間を確認。対応説明が得られなければ原値とUNKNOWNを継続 |
| R09 | 得点を用いる割当が存在するかを定める条項・編成基準、基準得点/時点・同点/欠損・例外 | 観測された得点順から逆算せず原条項を抽出。規則が使う得点の定義と現在保存scoreの意味を別照合 |
| R10 | grade/競走区分/段階別の特則、開催全体と個別競走の適用指定 | 一般規則との関係と排他scope、例外優先の根拠を確認 |
| R11 | 対象期間に対応する旧版・改正文書・附則/施行告示・経過措置 | 現行文書だけで2022〜2025を遡及説明しない。改正境界・適用軸・旧版との対応を確認 |

取得前に利用条件、公式経路/許可URL、対象文書/期間、アクセス量、保存root/private権限、source検証、秘密情報/失敗資料の取扱いを承認する。公式規則調査で2026 holdoutのレース/結果/Rawを読まない。文書の日付と対象レース年を区別し、将来の文書読取り範囲も明示して承認する。

保存時は原文無加工bytes、実URL・取得/受信/保存時刻・HTTP/source情報、hash/サイズ、抽出主張/pointer、審査revisionとUNKNOWN理由を保持する。既存合意root内の専用namespaceを承認してから使用し、本書で新しい保存rootを決めない。原文再取得も旧版を上書きしない。

## 11. 制度マスタ製造の開始条件

1. 本書・対文書の設計レビューと、R01〜R12のうち製造対象scopeの指定。
2. 対象scopeの公式原文・出典・版・適用期間/例外・真正性の確認。未確認scopeはUNKNOWNとして残し、正式マスタへ補完しない。
3. 台帳辞書・revision/審査・適用matcher・時計/利用可能性と、競合STOP規則の承認。
4. 人工fixtureで7/9車、非適用/NULL、期間境界・不明、制度競合、元観測との矛盾を検証する製造許可。実2026データなし、旧Raw/モデル/計算/Gate不変。
5. DB/Migration/公式HTTP取得・実収集を必要とする場合は、その操作だけ別途許可。本書提出を包括許可としない。

文書検証は相対リンク、原本の名称/節/SHA、UNKNOWNの統一、調査IDとrule_idの分離、末尾空白、指定2ファイルだけの変更を対象とする。PHPUnit/Pint/DB試験/HTTP試験は未実施で、成功済みとは記録しない。

次は設計レビューのみ。`historical_as_of_available=false`、`prediction_use=NOT_AUTHORIZED`、C1_PLUS_FIELD_BIKE不採用、旧C1/STAT01/STAT39・Gate・旧成果物・2026閉鎖を保持する。製造・収集・学習・予測利用へ自動移行しない。
