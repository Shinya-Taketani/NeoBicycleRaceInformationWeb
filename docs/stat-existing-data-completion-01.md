# STAT-EXISTING-DATA-COMPLETION-01

## 1. 範囲と状態

2026-10-30。開始時clean main / HEAD / ローカルorigin/mainは `1b9f54d24045bb33535fb1ab1b1d9823eaabcec6`。
ローカルmerge件名でPR126受入を確認し、`feature/stat-existing-data-completion-01` を新規作成した。今回fetch/GitHub照会はしていない。

今回の成果は**既存資料による限定MVPの製造・隔離保存・再読込・再現確認**である。STAT全要件完成・本番適用・精度改善ではない。
学校資料は原項目の保存までで、同期内評価の完成ではない。STAT-16/27の追加仕様は今回のユーザー回答で固定した基準方式であり、最適方式やモデル採用の承認ではない。

```yaml
current_phase: STAT-EXISTING-DATA-COMPLETION-01
state: LIMITED_MVPS_VERIFIED_AWAITING_REVIEW
production_write: 0
production_migration: NOT_RUN
historical_as_of_available: false
prediction_use: NOT_AUTHORIZED
formal_adoption: false
method_b1_status: PROPOSED_NOT_APPROVED
method_selected: NONE
selected_lambda: NONE
formal_b1_data_acceptance_status: NOT_RUN
2026_holdout: FROZEN_NO_ACCESS
```

## 2. 参照資料

AGENTS.md、MASTER PLAN、`stat-category1-mvp-completion-01.md`、既存Batch02～05/STAT20、STAT17/19/30/35/36/37/38/46のコード・保存契約を確認した。
要件原本はダウンロード配下のURLエンコード名 `...STAT-01-46確定_v1.0(2).md`、残タスク原本は同配下の `スクレイピング残タスク_統計エンジン連携_STAT-01-46確定_v1.0(2).md`、4分類原本は `STAT-01-46_データ取得可否4分類_ドラフト_v0.1.md`。実際のパス・SHAは証跡の資料一覧に保持する。ファイル名の版と本文の版を同一視しない。

## 3. 実装した経路

- `BuildAcquiredHistoryFeaturesCommand` → `HistoricalRaceRepository` / 新しい限定文脈Repository → Calculator → `AcquiredHistoryFeatureBuildService` → 既存統計3テーブル。
- `ExtractSchoolProfileCommand` → `SchoolProfileEvidenceService` → `SchoolProfileParser` / `SchoolProfileDto` → 排他的な新規private JSON。
- 共通 `AcquiredHistorySupport` は本人・重複・対象/未来除外・入力hashを扱う。STAT11の既存実発走Enumとcount windowを再利用する。
- Batch02の5STAT、旧Calculator/Enum、C1/B、Registry、モデル、Solver/Decoder/Evaluator、旧sealは変更しない。Migration追加なし。

### STAT-25

直近10実発走、暦上の過去1年 `[input_as_of - 1 year, input_as_of)`、取得範囲内の失格事実、段階別集計、最終失格からの日数/実発走数、反復の観測を保存する。窓の充足状態を別記する。
失格以外の落車/未完走を失格へ合算しない。履歴なしはNULL、確認した失格0は0、状態不明を含む確定件数/率はNULL。
最後の既知失格と、それ以後の状態不明により確定できない最終失格を分ける。DNS/WITHDRAWNは実発走数から除外する。

事実の基本根拠はCONFIRMED/CORRECTEDの保存DB結果で、全履歴Rawの再監査ではない。別途実5失格を既存公式Readerで検証し、保存 `raw_result_text` とPJ0326行の一致を確認した。
「斜行」「押上げ」「内圏線踏み切り」は原文であり、公式違反コード・責任・注意/警告・制裁期間へ変換しない。裁定/訂正の当時時刻は未証明。

### STAT-09 / STAT-44

STAT09は保有期間の実発走/正常着順件数、年別・段階別の実績。率は正常完走・有効着順の分母と明記し、異常/着順欠損を別記する。生涯通算・公式優勝回数へ読み替えない。
STAT44は対象以前の四半期別保存得点/期待残差、観測済み過去最高との隔たり、経過日数、対象時点の暦年齢を保存する。確認済み学校資料を明示指定した本人だけデビュー日・経過日数/年数を接続する。
現在級班/現在得点/現在通算値/将来ピーク/引退日を過去入力へ流用しない。四半期水準を交絡補正済みの成長効果とせず、局面コード・同年代補正は未確定のままNULL。

### STAT-02 / STAT-03

学校順位、200mタイム、卒業期、デビュー日だけをDOMから投影する。男子以外・不明カテゴリーは明示スキップする。引退者形式は既存 `RetiredPlayerDetailParser` の本人照合と既存カテゴリーPolicyを使い、引退日を出力しない。
Rawのbytes/SHA、取得ログID・取得日時・Parser版を保持する。同期人数を現役登録人数で代用せず、同期内割合/200m percentileはNULL。未解析の非欠損表記はINVALID_INPUTと区別する。

## 4. STAT-16/27の固定基準方式

版: `SIX-CONDITIONS-EQUAL-WEIGHT-NO-DECAY-v1`。新規の2STATで `SixConditionSimilarity` を共有する。

| 条件 | 保存元/正規化 | 距離 d |
| --- | --- | --- |
| 車立て | races.entrant_count。RaceRepositoryの出走表entries件数としての保存経路を確認し、対象・過去とも保存出走表人数と照合 | abs(x-y)/max(x,y) |
| レース段階 | races.race_type / 既存RaceStageNormalizer | 既知一致0、不一致1 |
| 開催グレード | race_days→race_meetings.grade / 既存Classification。races.gradeとの既知矛盾は不明 | 既知一致0、不一致1 |
| 本人級班 | 当該race_entries.grade / 既存GradeNormalizer＋既知値集合 | 既知一致0、不一致1 |
| 開催日番号 | race_days.day_number。正整数・既存durationとの整合を確認 | abs(x-y)/max(x,y) |
| JST予定発走時刻 | races.scheduled_start_atをAsia/Tokyoの分数mへ変換。秒・小数秒も分へ換算 | min(abs(m1-m2),1440-abs(m1-m2))/720 |

実発走/完走人数を予定車立てへ混ぜない。未知のカテゴリーや汎用OTHER同士は一致にしない。無効な整数や時刻は比較不能とし、原値と理由を残す。
日番号の距離は1対2で0.5、5対6で1/6。同じ1日差を常に同距離とする方式ではない。全期間min/maxで正規化しない。

```text
s_i = 1 - d_i
m = 比較可能な条件数
C = m / 6
m >= 2: S = sum(s_i) / m; W = S * C
m <= 1: S = NULL; W = NULL（Cと除外理由は保持）
E = sum(W)
N_eff = sum(W)^2 / sum(W^2)（分母0はNULL）
```

欠損条件のd/sを0にしない。2条件のみ一致ならS=1、C=1/3、W=1/3。6条件完全一致と同じ寄与にしない。
本人の対象以外・別開催の確認済み実発走だけを参照する。既存の開催開始cutoffを緩めず、`min(STAT01 input_as_of, 対象開催開始00:00 JST)` より厳密に前とする。開催不明・同一開催・cutoff以後・非出走・本人不一致を混入させない。
適格履歴全体をWで加重し、閾値/top-K/時間減衰は加えない。経験の新しさまで評価したとはしない。

STAT16は正常完走・有効着順・正のWだけの加重成績。順位別分子、重み分母、対象件数、除外理由を保存する。同着の扱いは既存の正常結果契約を維持し、失格/未完走へ順位を割り当てない。
STAT27は実発走の失格/未完走も経験に含め、非出走と出走未確認は除外。E/N_eff/正の重み件数/最大S/平均S/C/対象期間を保存する。
履歴なし、履歴はあるが条件不足、比較可能で重み0、数値生成可能を別状態にする。条件不足を経験0とせず、N_effだけで高信頼・習熟・高新規性を認定しない。
対象/各過去の6値・原値・保存元、条件別d/s/理由、S/C/W、参照/除外ID、状態/順位、cutoff・範囲・式/正規化版をevidenceへ保存する。Cは6条件内の充足率であり、要件全体の充足率ではない。

## 5. 使用実資料と隔離

証跡親root: `/home/shinya/neo-keirin-artifacts/stat-existing-data-completion-01/`。

| 資料 | 実測対象 |
| --- | --- |
| snapshot-20261029-225509-adc95366 | 2024-01-01～2025-01-04、25,976レース、184,353出走行、184,196結果行、2,223選手、41場 |
| sources-20261029-225746-ad3a0588 | 2024年同一選手40履歴＋失格5レースの45 import/log/実Raw、学校1Raw |
| availability-20261029-230729-a70ccedf | 2022～2025のSQL限定属性充足調査。42場すべてregion=NULL。各年出走行の府県は保存あり |
| profiles-20261029-231457-8cd88660 | 学校資料2,475取得版、2,436固有選手。順位数値1,693、タイム数値654、デビュー日2,475。失敗0 |
| profile-replay-20261030-081537-uZACw1m8 | DB不要・ネットワーク遮断の独立再投影。出力SHAが上記と一致 |
| saved-verification-20261029-231813-c30b3bcc | mean6各年20出走、S原項目の少数実Raw、既存構造マスタの限定解決 |

フォルダ名のUTC/JST差は取得年・開催年の違いではない。2026年取得の過去年資料は事後資料として扱い、2026年開催は参照していない。
本番接続は直接PDOの既存認証でREAD ONLY/REPEATABLE READ、接続単位default_transaction_read_only=on、statement_timeout=1min、lock_timeout=5sを実測した。SQL/時刻/exit/peakは各JSONに保存。認証情報は保存しない。
その後は封印済みJSONLを250行ずつ新規ファイルSQLiteへ投入し、既存サービスを使用。bwrapの原本ro-bind、ネットワーク遮断、人工.env/storage/cache/Desktop/Trash、SQLiteを維持した。原本DB/設定/Raw/旧成果物は変更しない。

## 6. 計算・保存・再現結果

最終正本: `acceptance-20261030-081847-kEzV8WpA/logs/`。中間実行は同親rootの別runとして保持し、最終結果へ読み替えない。
主対象は2025-01-04のrace12773（7車）/12901（9車）の16出走。追加の学校/キャリア接続はrace61724、公式決まり手はrace12542で検証した。保有全期間の本番再計算ではない。

隔離SQLiteには89 run・669結果行を保存した。再現用2実行分を含む保存行数であり、669固有出走ではない。128M、peak61,341,696bytes、143.827945秒、exit0。

| 結果状態 | 保存行数 | 解釈 |
| --- | --- | --- |
| VALID | 445 | 当該限定契約で有効。予測利用承認ではない |
| PARTIAL | 178 | 条件・全キャリア等の不足を明示した部分結果 |
| PARTIAL_HISTORY | 16 | 実19/30の必要履歴不足。不足を架空の履歴で補完しない |
| NO_HISTORY | 12 | 対象集計の履歴なし。経験0へ置換しない |
| NOT_APPLICABLE | 18 | STAT33の対象外 |
| 計算失敗 | 0 | 人工rollback試験とは別の最終実資料計算結果 |

- 既存16項目と新STAT09/16/25/27/44を保存・再読込・同じSTAT01 source runから2回再計算。STAT41はレース別、STAT20は品質監査のまま。
- 意味上の結果（subject/status/quality/features/input hash）は一致。STAT20は明示upstream run/result IDだけを比較用に除き、監査値を照合した。ID差を隠して全ファイルbyte一致とは記載しない。
- 新STAT09/16/25/27/44は主対象の各16出走を各2回保存。別プロセスの独立算術監査では、アプリCalculatorを読み込まず、STAT16/27の64結果行・5,300履歴参照・31,752既知条件距離からS/C/W・E/N_eff・正常完走成績を再計算し一致した。主要数値は64/64で生成。監査peak4,194,304bytes、128M、exit0、SQLiteのSTART/END SHA一致。
- 実例race12901/entry91799では適格114履歴、平均S=0.5590525503573748、平均C=0.9956140350877192、E=63.38912037037036、N_eff=106.38679772109498。STAT16は正常完走112履歴、重み分母62.20879629629628、1/2/3着率0.24531706990347626/0.13753339634296094/0.13188857714834307。異常2件は成績から除外し、実発走経験には含めた。高信頼・習熟・改善成功の認定ではない。
- STAT25には実際の非ゼロ失格・観測0の両方がある。実5件のPJ0326原文とDB行が一致。コード/制裁/警告・当時の裁定時刻は未確認。
- STAT19/30は本人014236の20実発走すべてRaw検証済み。決まり手は差し1/マーク1、20走構成比は各0.5。直近5対直前15のL1=2。ただし各側の決まり手観測は1件で、戦法転換の実証ではない。他4人の20走不足はNULLを維持。
- 学校の独立再投影SHA: `3782e24460ef42ddb738d56c1b1c655b160eb123e8e0032d46d23b27c44929a7`。正式同期人数/同期分布・測定基準日が未証明のためpercentile生成0。
- mean6は80出走（数値52/NULL28）で保存側sidecar・監査分数・312参照開催の平均を照合。C1/モデル用固定入力は再生成しない。
- Sは6取得版37観測行を独立2解析で照合。表示S回数であって実際の初手位置ではない。STAT17/38/46は既存の実6レース39固有出走/8取得版の保存・照合も限定テストで確認した。
- STAT38は予定距離/周回の観測・再読込と、実結果対応の事後2実発走を確認。発走前取得証明不足の予測経験数はNULLのまま。STAT46の実2組比較は本人/車番の変化0、枠番は全NULLで変化未確認。

## 7. テストと実行方法

最終限定回帰: `tests-20261030-081809-6taGRA5u/logs/`。
新規31 tests / 170 assertions、既存308 / 7,487、合計339 / 7,657。failure/error/warning/skip0、exit0、9.64秒。PHP8.5.4、Artisan/Collision、PHPUnit12.5.31、本体128M、peak82,837,504bytes。
削除/緩和/新規skipなし。dry-run書込み0、SQLiteの人工INSERT失敗時のレース全体rollback、本人/重複拒否、NULL/観測0、未来除外、重み/周期/欠損/状態の境界を検証した。
途中の0のfloat型アサーション不一致と、引退者人工Fixtureのidentity marker欠落は修正前記録として保持した。実数の改善・予測精度の改善ではない。

保存済みrunner.shとcommand.txtが正確な起動条件の正本。代表コマンド:

```bash
php -d memory_limit=128M artisan keirin:statistics:build-acquired-history \
  --stat=STAT-25 --stat01-run-id=<isolated-source-run> --history-from=2024-01-01 --race-id=12901
php -d memory_limit=128M artisan keirin:statistics:build-acquired-history \
  --stat=STAT-16 --stat01-run-id=<isolated-source-run> --history-from=2024-01-01 --race-id=12901 --dry-run
php -d memory_limit=128M artisan keirin:statistics:extract-school-profile \
  --raw=<saved-profile> --source=<sealed-fetch-metadata> --source-sha256=<sha256> --output=<new-private-json>
```

上記は隔離SQLite/新規private出力用。本番での実行承認ではない。STAT44の明示接続には `--school-profile` と `--school-profile-sha256` を使う。
追加PHP20ファイルの `php -d memory_limit=128M -l`、同20ファイル限定の `vendor/bin/pint --test`、`git diff --check` はすべてexit0。Pintも原本read-only/ネットワーク遮断で確認した。
通常全体テストは旧TrackContext等にunlink/rmdir cleanupが残るため起動しない。旧155警告/9skipの全解消は主張しない。安全helper/旧テストを一括変更していない。

最終レビュー証跡: `/home/shinya/neo-keirin-artifacts/stat-existing-data-completion-01/review-20261029-232559-4c19a7f2/`。
資料原本の絶対パス/bytes/SHA、生成・検証スクリプト、実行済み資料のseal一覧、構文/Pintログ、独立算術監査、全22ファイルの差分・コードseal・文書検証・manifest/COMPLETEを保存する。大容量Raw/SQLiteの複製・ZIP作成は行わない。実際のSQLiteと実行ログは上記acceptance正本に保持する。

## 8. STAT別の受入範囲と残件

区分: 1=今回製造した限定範囲、2=既存限定実装を確認、3=実装済みだが実資料検証未完、4=必須資料/意味未成立、5=入力と仕様が揃うが実装未完。

| STAT | 区分と今回の確認 | 本番/モデルと残件 |
| --- | --- | --- |
| 01 | 2: 実得点・相対値保存 | 既存版維持、新規採用なし |
| 07 | 2: 同場/全場成績 | 長窓の左打切りを保持 |
| 08 | 2: 人数条件実績 | 条件付きMVPのまま |
| 10 | 2: 直近状態 | 既存窓/契約維持 |
| 11 | 2: 異常結果集計 | STAT25と分離 |
| 12 | 2: 間隔・復帰 | 無出走期間を未来から補完しない |
| 20 | 2: 実upstream runの品質監査 | confidence推定ではない、新STATを無断統合しない |
| 23 | 2: 開催日別 | 日番号欠損を保持 |
| 24 | 2: 成績変動性 | 既存定義維持 |
| 26 | 2: 競走負荷 | 同上 |
| 31 | 2: 上位競走経験 | 制度補正は未完成 |
| 32 | 2: 段階別成績 | 標本/履歴不足は別記 |
| 33 | 2: 開催中診断 | NOT_APPLICABLE/NO_HISTORYを保持 |
| 39 | 2: 保存車番/枠番成績 | FIELD_BIKE不採用を維持、公式割当制度は未確認 |
| 41 | 2: レース別分布 | 出走別へ重複計上しない |
| 42 | 2: 対戦実績 | 対戦なしを欠損扱いと区別 |
| 25 | 1: 失格専用履歴・段階・recency | 正式DB保存なし。理由コード/制裁/時点は4 |
| 16 | 1: 承認6条件による正常完走の加重成績 | 全条件適性/配点/因果効果は未完成 |
| 27 | 1: 同じ比較のE/N_eff/充足率 | 新しさ/習熟/信頼度は未判定 |
| 09 | 1: 保有期間・年/段階別実績 | 生涯完全性/通算公式値は4 |
| 44 | 1: 過去四半期/観測ピーク/年齢/確認本人のデビュー | 局面・同年代補正は未確定、全キャリア不足 |
| 02 | 1: 学校順位/卒業期のRaw投影保存 | 同期内評価に必要な正式人数は4 |
| 03 | 1: 200m生値のRaw投影保存 | 同期分布/測定基準日は4 |
| 17 | 2: 既存実8版の表示分布保存 | 戦法成功率/仕掛けではない |
| 19 | 2: 実20走の窓・構成比 | 本人1例。他の履歴不足を全員完成としない |
| 30 | 2: 実5対15のL1保存 | 戦法転換・精度効果は未実証 |
| 35 | 2: 旧mean6と元参照/固定入力の少数照合 | 全量再生成/学習なし、歴史公開時点は4 |
| 36 | 2: Sの原文・本人対応・保持 | 実初手・S固有基準日は4 |
| 37 | 2: 既存マスタの証拠付き期間だけ構造解決 | 大部分の歴史的構造適用期間は4、適性Calculator全体完成ではない |
| 38 | 2: 実距離/周回/事後経験の保存 | 予測時点利用の証明不足は4 |
| 46 | 2: 既存8版と2組の実差分 | 枠番全NULL、公式補充区分は4 |
| 43 | 4: レース別府県はあるが場側42件region=NULL。検証したPJ0315のホーム欄に実値なし | 地元/遠征距離を地名の推測で作らない |
| 45 | 4: 検証した6版のPJ0315/PC0201にギヤ実値項目を確認できない。HTMLの見出しだけでは値の証拠でない | 全保存Rawに存在しないと断定しない。取得版付き比率がなければ変化量を作らない |

今回明示した限定MVP内の区分3/5は残さず、成立範囲の製造・保存・再現まで実施した。ただし上表の区分4や、全要件の未確定方式/未実装拡張は残るため、**既存資料STATの全要件が一通り完成したとは報告しない**。
本番Migrationは不要（追加なし）。次はコード/実資料証跡レビューのみ。受入後に本番計算を行う場合も対象・source run・出力先・書込みの別承認が必要で、自動実行しない。学習/予測/採用・B1・2026へ進まない。
