# STAT09-GRADE-BREAKDOWN-01

## 1. 到達点と開始状態

2026-10-31 JST。開始時のclean main/HEAD/ローカルorigin/mainは `2b2b4b2371067953cc5dd11f53d15362590db6c1`。PR128のmerge履歴とレビューhead `43560987972bc28f31efc6286609bd3c506af2ff` とのファイル差分0を確認し、未使用の `feature/stat09-grade-breakdown-01` を作成した。外部GitHub照会/fetchは行っていない。

保有期間内のSTAT09へ級班別・開催グレード別の独立2軸を追加し、新STAT20まで接続した。限定テスト・隔離実資料の保存/再読込/反復・別PHP再計算・独立数え上げが成立し、未コミットの `READY_FOR_REVIEW`。生涯通算、公式通算、最高級班、在籍期間、全46要件の完成、予測効果の確認ではない。

```yaml
current_phase: STAT09-GRADE-BREAKDOWN-01
state: READY_FOR_REVIEW
career_scope: PARTIAL_CAREER
usage: RETROSPECTIVE_ONLY
historical_as_of_available: false
prediction_use: NOT_AUTHORIZED
formal_adoption: false
raw_points: null
effective_points: null
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

## 2. 入力元と分類契約

要件原本のSTAT09/20、残タスク原本SCR-STAT-09-02/03、AGENTS、[全46台帳](stat-existing-data-completion-01.md#10-全46statの現在地stat-20統合工程2026-10-30)、[PR128記録](stat20-acquired-history-integration-01.md)と依存実装を確認した。原本の実在パス/サイズ/SHAは新証跡の `source-references.json` に保存する。ファイル名v1.0(2)と要件本文v0.1は区別する。SCR-STAT-09-02/03の全完了条件を満たしたとはせず、原本のOPENは変更しない。

採用履歴は既存 `AcquiredHistorySupport::prepare()` と同じ。対象レース、input_as_of以降、history_fromより前を除外し、重複/別人は拒否する。新Repositoryは採用entry IDを最大250件ずつ取得する。現在の選手プロフィールや対象レースの級班を過去へ流用しない。6条件入力全体は接続しない。

| 軸 | 出典・既知値 | UNKNOWN/不一致 |
| --- | --- | --- |
| ENTRY_GRADE_BREAKDOWN | 当該履歴race_entries.grade。既存GradeNormalizer後、SS/S1/S2/S3/A1/A2/A3/A4/B1/B2に完全一致 | null/空/未知/対象外を原値・理由付きUNKNOWN。欠けたentry行とgrade項目欠損は別理由 |
| MEETING_GRADE_BREAKDOWN | entry→race→race_day→race_meeting.grade。Classification::normalizeの既存MAPでGP/G1/G2/G3/F1/F2 | 関係/日付/開催期間/場の不整合、値不明、既知races.gradeとの矛盾は原値・理由付きUNKNOWN。名前等で補完しない |

entry/race/player IDが別行を指す場合はエラー。元行不在は `HISTORY_ENTRY_NOT_FOUND` / `HISTORY_RACE_UNAVAILABLE` 等で区別し、別行へfallbackしない。`Classification::meetings()` の別fallback契約は使わない。既知/UNKNOWN件数とUNKNOWN理由内訳を各軸に保存する。複数理由は同一参照内で重複を除くが、理由件数合計は独立履歴件数ではない。

## 3. 分母・hash・保存版

旧Calculatorへの委譲で全体/年/段階と各区分の同じcounts契約を使う。`observed_rows`、`started_count`、`normal_rank_count`、rank_1/2/3_count、`excluded_abnormal_or_missing_rank` を保存。勝率/3着内率は正常着順分母、分母0ならNULL。既存started/normalFinishが定める同着・失格・未完走・欠場・取消・着順欠落の扱いは不変。UNKNOWN込み各軸の加算可能件数がACQUIRED_PERIODへ戻る。率は合算/単純平均しない。未観測カテゴリはゼロ補完せず、履歴なしの新2軸は空表とする。

新hashには旧入力hash、使用履歴の対応ID・分類原値/正規化値/理由/出典、投影・正規化版、allowlist/MAPを含める。採用履歴ID/カテゴリを固定順にし、未使用の対象/未来/範囲前contextは含めない。正規化後同じでも原値差は追跡する。
4つの行取得日時はhash用コピーのみ同一瞬間の `U.u` へ変換し、microsecondを保持する。naive日時はJST、欠落/null/空は区別、不正な非空日時は拒否。evidence原文は変更しない。`source_max_fetched_at` は使用履歴entry/resultと使用分類のentry/race/day/meeting行の取得日時の最大値へ拡大し、scopeを明記する。属性専用観測時刻、履歴発生時刻、当時公開時刻の証明ではない。

| 経路 | 明示選択/版 | 互換性 |
| --- | --- | --- |
| 旧09 | --stat09-profile=legacy-v2 / STAT-09-acquired-career-v2 | 無指定既定。旧Calculator・features/evidence/status/quality/hash不変 |
| 新09 | --stat09-profile=grade-breakdown-v3 / STAT-09-acquired-career-grade-breakdown-v3 | 別Calculator、profile/投影契約を新runとevidenceへ保存 |
| 旧20 | --source-contract=STAT-20-acquired-history-v2 | 無指定既定。旧09v2＋PR128他19項目の完全一致map |
| 新20 | --source-contract=STAT-20-acquired-history-v3 | 09だけ新版、他19はPR128と同じ。新旧交差/偽装/親不一致を拒否 |
| 共通保存/lineage | ACQUIRED-HISTORY-RACE-AUDIT-v3 / ACQUIRED-HISTORY-STAT01-LINEAGE-v1 | transaction/終端/失敗item契約は不変。Service期待版を監査契約へliteral固定 |

STAT09以外への非既定profile、未知profile/contractは書込み前に拒否。新20も共通Command/Repository/Calculator/Serviceを使い、20上流/追加run最大19/物理行最大172/STAT41だけRACE行を維持する。保存version/evidence.source_contract/hash版は選択契約と一致。旧20v1・他4STAT・6条件式・汎用Hasher・SourceAuditは変更しない。

## 4. 隔離実資料受入

新証跡rootは `/home/shinya/neo-keirin-artifacts/stat09-grade-breakdown-01/run-20261031-srrlFF0z/`。PR128 manifest/COMPLETE/各sealを照合して次を確定した。

| 元資料 | SHA-256 |
| --- | --- |
| PR128 manifest | 1fc795bdc2a68a9e101e2ab48b2ead71ac2f38be291dc7649e07b4d3d350c009 |
| manifest指定acceptance-dZDILxLM/logs/acceptance.sqlite | 22131b9c4be9071435c2de8942c376e589347f92d2e2fdfc7934f8661df7fdd0 |
| 今回作業SQLiteの保存後 | 160c06eeaeaaa53e0d3d86c5476ac1f82ca963553ba4c323e44e1078755a7c07 |

PR128 rootは `/home/shinya/neo-keirin-artifacts/stat20-acquired-history-integration-01/run-20261030-c7xRlke2/`。元SQLiteは186,228,736bytes、実開始件数135 run/1,035結果/135 item。PR127元SQLiteのa6760f3f…や111 run/843結果を開始値に流用していない。今回の `acceptance-ENTGTmzP/logs/acceptance.sqlite` へ作業用複製は1回だけ。他の再検証も同一ファイルをread-onlyで使い、大容量添付コピー/ZIPなし。

元09 run112/124のhistory_from=2024-01-01、対象2025-01-04、input_as_of/対象範囲を実読して同条件を使用した。全2022～2025年を今回集計した値ではない。旧19上流は再生成しない。

| 対象 | 元01/旧09/旧20v2 | 新09（各2回） | 新20（各2回） | 20の両回で固定する09 source |
| --- | --- | --- | --- | --- |
| race12773、2025-01-04、7出走 | 1 / 112 / 122,123 | 136,137 | 138,139 | 136 |
| race12901、2025-01-04、9出走 | 42 / 124 / 134,135 | 140,141 | 142,143 | 140 |

実測は新09 4 run/32行＋新20 4 run/32行、計8 run/64行。全PARTIAL、処理エラー0。最終143 run/1,099結果/143 item。旧09の16行と旧20v2の32行を旧経路で再現し、features/evidence/status/quality/hashが一致。新09基礎集計16比較と新同士反復は一致し、新版hashだけ意図的に区別。各run/item/resultの件数/終端/エラー一致、旧行/業務表digest・原本・直接依存コードのSTART-END seal不変。points/confidence/利用制限は保持した。

### 独立検算と同実装再現

| 検証 | 実測と意味 | memory_limit / peak / 所要時間 / exit |
| --- | --- | --- |
| 保存/反復・旧経路照合 | acceptance-ENTGTmzP/logs/summary.json、上記64新行 | 128M / 119,541,760bytes / 2.642060秒 / 0 |
| A 別PHPプロセス | reproduce-8PsTaET3/logs/reproduction.json。新09 32行/新20 32行を同実装で再計算。独立算術ではない | 128M / 77,598,720bytes / 0.419157秒 / 0 |
| B 独立数え上げ | recount-t6lifHmY/logs/recount-summary.json。新Calculator/新投影helperなし、16本人/32保存行を照合 | 128M / 10,485,760bytes / 0.160900秒 / 0 |

A/BともSQLite read-only＋query_only、書込み0・SHA不変。Bは採用1,325履歴参照（固有entry IDも1,325）の保存grade原値/対応行/結果状態から既存分類/分母を使って別算術で数え、各区分・率・coverage/理由が一致。16本人のsource_maxも照合。集計表の正本は `recount-t6lifHmY/logs/grade-breakdown-reconciliation.csv`、本人ごとの照合は `subject-reconciliation.json`。

### 実資料で観測された区分

次は主16本人の採用履歴を合わせた記述件数。反復runを重複加算していない。

| 軸 | 区分 | 観測 | 発走 | 正常着順分母 | 1着 | 2着 | 3着 | 異常/着順欠落 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 級班 | A1 | 431 | 429 | 427 | 90 | 71 | 75 | 2 |
| 級班 | A2 | 118 | 118 | 117 | 27 | 26 | 16 | 1 |
| 級班 | A3 | 42 | 42 | 42 | 27 | 6 | 6 | 0 |
| 級班 | S1 | 137 | 137 | 134 | 29 | 21 | 20 | 3 |
| 級班 | S2 | 597 | 595 | 583 | 69 | 63 | 77 | 12 |
| 開催 | F1 | 699 | 697 | 689 | 114 | 101 | 108 | 8 |
| 開催 | F2 | 366 | 364 | 362 | 102 | 62 | 58 | 2 |
| 開催 | G1 | 20 | 20 | 20 | 3 | 1 | 5 | 0 |
| 開催 | G2 | 8 | 8 | 8 | 0 | 0 | 0 | 0 |
| 開催 | G3 | 221 | 221 | 214 | 22 | 23 | 21 | 7 |
| 開催 | UNKNOWN | 11 | 11 | 10 | 1 | 0 | 2 | 1 |
| 各軸合計 | ACQUIRED_PERIOD | 1,325 | 1,321 | 1,303 | 242 | 187 | 194 | 18 |

級班UNKNOWN=0、開催既知1,314/UNKNOWN11。理由は `MEETING_GRADE_UNKNOWN_OR_MISSING` 11件、対象の保存meeting.grade原値は11件ともNULL。GP/F2等へ推定しない。実資料の既知開催grade矛盾は0件。GP、他男子級班、未知/矛盾/関係欠落/同着/NULL等の境界は合成回帰で確認し、実資料で観測したとしない。

## 5. テスト・実行コマンド

最終限定11ファイルは `tests-3vV8fc2c/logs/`。PHP8.5.4、Artisan/Collision→PHPUnit12.5.31、179 passed/1,695 assertions、failure/error/warning/skipped0、exit0、6.41秒。今回追加45 cases/302 assertions、既存134/1,393。PHPUnit子も128Mを起動時に確認、peak78,118,912bytes。

手算術、実Repositoryの境界、保存/dry-run0、run/result/親/hash/profile交差拒否、UTC/JST/microsecond/年境界、旧09/20v2のliteral版＋固定旧結果、共有Serviceの途中失敗と16/27/6条件・旧20v1回帰を含む。初期人工Fixtureのduration_days欠落（25失敗/44成功）、必須場IDをNULLにした1失敗はログを保持し、Fixtureを実schemaへ合わせて解消した。期待値の緩和/skip追加はしていない。

実コマンド/引数は各 `logs/command.txt` と `runner.sh` が正本。限定テスト例:

```bash
php -d memory_limit=128M artisan test \
  tests/Unit/Domain/Keirin/Statistics/Stat09GradeBreakdownCalculatorTest.php \
  tests/Feature/Stat09GradeBreakdownTest.php \
  tests/Unit/Domain/Keirin/Statistics/Stat20AcquiredHistoryCalculatorTest.php \
  tests/Feature/AcquiredHistoryFeaturesTest.php \
  tests/Feature/Console/Keirin/BuildStat20AcquiredHistoryCommandTest.php \
  tests/Unit/Domain/Keirin/Statistics/AcquiredCareerCalculatorsTest.php \
  tests/Unit/Domain/Keirin/Statistics/AcquiredHistoryCalendarTest.php \
  tests/Unit/Domain/Keirin/Statistics/SixConditionSimilarityTest.php \
  tests/Unit/Domain/Keirin/Statistics/Stat20CalculatorTest.php \
  tests/Feature/Console/Keirin/BuildStat20FeaturesCommandTest.php \
  tests/Unit/Domain/Keirin/Statistics/SimilarConditionsHashInputTest.php \
  -d memory_limit=128M --bootstrap tests/Support/stat38-memory-bootstrap.php \
  --display-all-issues --do-not-cache-result
```

隔離runnerはbwrap原本read-only/network遮断、人工.env/storage/cache、隔離SQLite、人工Desktop/Trashと既存安全helperを維持。新しい通常処理の呼出し例は次のとおりで、本番実行の許可ではない。実受入は同Service/Optionsを実行PHPから呼び、監査ログへ実引数を保存した。

```bash
php -d memory_limit=128M artisan keirin:statistics:build-acquired-history \
  --stat=STAT-09 --stat09-profile=grade-breakdown-v3 \
  --stat01-run-id=1 --race-id=12773 --history-from=2024-01-01 --chunk=10 --dry-run
php -d memory_limit=128M artisan keirin:statistics:build-stat20-acquired-history \
  --source-contract=STAT-20-acquired-history-v3 --stat01-run-id=1 --race-id=12773 \
  --source-run-id=7 --source-run-id=8 --source-run-id=2 --source-run-id=3 \
  --source-run-id=4 --source-run-id=9 --source-run-id=5 --source-run-id=6 \
  --source-run-id=10 --source-run-id=11 --source-run-id=12 --source-run-id=13 \
  --source-run-id=15 --source-run-id=14 --source-run-id=136 --source-run-id=114 \
  --source-run-id=116 --source-run-id=118 --source-run-id=120 --chunk=10 --dry-run
```

変更PHP16件の構文・限定Pint、diff/変更範囲・Markdown/正式名46件を最終品質ログで検証する。通常全体テストは未実施。旧危険cleanupを無条件に起動せず、過去171件/4,352件や旧警告件数を今回結果へ読み替えない。

## 6. 残件と提出

当該2軸の限定製造は完了したが、今回主2レース16本人/2024保有履歴以外の網羅、生涯通算/公式優勝/最高級班/在籍期間、原Raw全件と当時公開時点の独立証明、級班/制度/相手補正は未完了。学校取得版の明示接続拡大、天候原項目投影の対象拡大も残す。SourceAuditの新機能追加、他STAT/モデル/配点/信頼度の変更なし。補正・予測精度の効果は未測定。
ユーザーの「既存データSTAT整備後、2026終了済みレースを時系列に取得→予測保存→結果取得→正解率確認」の方向は保持するが、本工程では実行せず、永久禁止へ拡張しない。B1/親lambda/H1-U・正式採用/LIVEの承認ではない。

root直下の `summary.json` / `manifest.json` / `COMPLETE.json` と限定テスト終了集計、区分別照合CSV/JSON、実行script/START-END seal/小型差分がレビュー資料。大きなSQLite/Rawの再コピー・ZIPは作成しない。次は今回のコード/文書/証跡のレビューのみ。Git add/commit/push/PR/mergeなし。
