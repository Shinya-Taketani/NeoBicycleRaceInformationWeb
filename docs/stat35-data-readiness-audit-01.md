# STAT-35-DATA-READINESS-AUDIT-01

## Scope And Access Contract

Starting main: `121517ebf6edb311e20be2b730b378cb7b201c27` (PR #63 merged).
Branch: `audit/stat35-data-readiness-01`. Contract: `STAT35-DATA-READINESS-v2-QUALITY-ONLY`.

2022-2025 result outcome fields are accessible only for STAT-35 data-quality classification.
Target rank/winner are not consumed for predictive evaluation, feature selection or parameter selection.
This incorporates the user's clarification of the original section 34. Reading a Raw body containing
rank is distinct from consuming rank as an analysis variable. Only saved `result_status` is projected
from result rows. No rank/winner fields are written to audit row artifacts.

This is not STAT-35 implementation, prediction evaluation, backfill, migration or model fitting.
Production DB and saved Raw are READ ONLY. The 2026 **race** cohort, including its metadata, is forbidden.
Acquisition timestamps/directories in 2026 for explicitly identified 2022-2025 races are not 2026 race data.
No new network acquisition is performed.

## Existing Storage

- `RaceResultParser` parses the legacy `pitbodyBs` HTML table; `raw_result_text` is concatenated text.
- `RaceLiveResultParser` parses embedded PJ0326 named objects. It already reads `agari` into DTO
  `finishTime`, unlike the initial prompt's assumption that the DTO has no such field.
- `RaceResultImportService` does not persist `finishTime` as a dedicated column. It preserves the
  original result object serialized as JSON in `raw_result_text`. The schema has no structured agari column.
- `race_result_imports.source_hash` and fetch-log `sha256` describe original response bytes;
  `converted_hash` describes converted UTF-8 bytes. Sizes describe original bytes.
- Every stored-response import creates a new import row. Results are current upserts keyed by race/bike;
  import history and Raw therefore must be retained for revision auditing.
- `race_result_imports` has no `fetched_at`. Use the linked fetch log, not `imported_at` or current
  `race_results.fetched_at`, which is the persistence time. Missing fetch logs mean unknown acquisition timing.

## Preimplementation Raw Evidence

The separate `preinspection.json` (52 pages) and `header-preinspection.json` (71 distinct imports)
are saved under the agreed audit root. Supplemental fixed queries select first imports containing
TIED/abnormal statuses and S-class nine-entrant races in each year. No random selection or rank analysis.
All 71 pages have this header sequence:

```text
着 / 車番 / 選手名 / 年齢 / 府県 / 期別 / 級班 / 着差 / 上り / 決まり手 / H/B / 個人状況
```

`#rrDispTyakuJyun thead tr` uses **td**, not th. `#rrTableTyakuJyunBody` is empty in stored HTML;
the result values reside in embedded `PJ0326.tyakujyunItemSubData`, including named `agari` and `syaban`.
The extractor verifies the observed header set and 16-key object schema. It does not equate 16 object
keys with 12 logical columns. `row_cell_count=null` records this distinction. Rendered body rows or
unknown schemas fail visibly rather than guessing a positional interpretation.

Only observed header `上り` is accepted, no guessed aliases. Reordered verified headers are accepted
with a different SHA-256 signature. Duplicate/missing/unknown headers are rejected.
Sample values include empty text and positive decimal strings, e.g. `11.4`, `13.0`, `18.3`.
Stored raw text and decimal precision are retained. Blank/dash remain missing, never zero.
Numeric exponent/sign syntax is not accepted. Zero/nonfinite values are invalid, not empirical outliers.
Min/max are review candidates only; no observed-distribution exclusion threshold is introduced.
Track timing distance and official first publication time remain unverified, not inferred from the values.

## Identity, Coverage And Timing

The DB query uses the existing Statistics/GrowthTrend fullwidth A/S-prefix scope and fixed SQL date range.
All imports are captured. Each result object must match unique race/bike in both current entries and
results; its registration number must match the entry. Nullable player links remain explicitly unresolved.
Headers and named object schemas, race date/track/race number, file bytes/hash and conversion hash are checked.
Normal/abnormal quality counts are separate. FINISHED/TIED counts do not make abnormal agari valid normal history.
Grade/class uses existing Classification; unknown meeting grade remains UNKNOWN, with no GP/name inference.

Coverage denominators are explicitly **import-version rows**, not unique latest race results.
`current_result_rows` and target race counts are separate unique DB counts. Revision comparisons preserve
all versions ordered by known fetched time then import ID; unknown fetched times are ordered last but
never become as-of history. Revision types distinguish additions/removals/value/format/header changes.

Fixed Outer inputs are verified using four already-reviewed literal file/sidecar seals; only
race ID/entry ID/bike/year are projected. Prediction/model/outcome files are not opened.
Historical H must differ from T, precede T as an event, and have import acquisition strictly before T.
The latest valid-format pre-target version of each historical race is selected; later corrections are excluded.
PRE_MEETING and IN_MEETING are separate. Valid-format availability counts are not a frozen STAT-35
result-status admission policy. Current DB status is quality evidence only; for a superseded import it
does not establish historical status. A separate normal-status count requires the status import to match.
Counts of zero do not assert no career history: `LEFT_TRUNCATED_POSSIBLE` before 2022.
System acquisition does not prove first official publication: `PUBLICATION_TIME_UNKNOWN`.

## Integrity And Reproduction

Dedicated new root: `/home/shinya/neo-keirin-artifacts/stat35-data-readiness-audit-01-20260921-01/`.
Audit ID: `stat35-agari-readiness-2022-2025-01`.
No old artifacts are overwritten. A staging bundle is published only after DB START/END, every Raw hash,
fixed Outer inputs, direct code identities and write-time output seals are verified.
DB reads use an already READ ONLY PostgreSQL session, 120000ms statement timeout, bounded pages and
fresh START/END snapshots. A local SQLite workspace is not a production DB and is removed after successful computation.
Reproduction uses sealed source/target inventories and original Raw, with Production DB disabled.
Raw drift by one byte fails; missing Raw is recorded and cannot silently appear or fall back on reproduction.
All processing is under `memory_limit=128M`.

## 実測結果

READINESS: **BLOCKED_IDENTITY_MAPPING**。別の独立した制約として、厳密な取得時点による
historical as-of coverageも0であり、`BLOCKED_INSUFFICIENT_RAW_HISTORY` に該当する。
今回のprimary判定は、事前の拒否規則に従って識別照合不能を優先する。
監査コマンドの正常終了を、STAT-35実装可能・予測性能向上・採用と解釈しない。

### 母集団・抽出率

全101,326レース、717,709出走行、716,837現在結果行、127,121 importを棚卸し。
Raw存在・original SHA-256・converted SHA-256は全importで一致、物理欠損0。
完全照合できた127,008 importから899,996延べ行を抽出した。
うちVALID 879,664、MISSING_AGARI 20,332、非数値・不正format・0以下の値は0。
数値3行はDISQUALIFIEDであり、正常完走の有効値879,661行へ混ぜていない。

| 年 | レース | import / Raw照合 | 完全解析import | 抽出延べ行 | VALID | MISSING | 正常有効 / 正常行 | 正常有効率 |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 2022 | 24,868 | 24,865 | 24,824 | 173,847 | 169,679 | 4,168 | 169,678 / 171,285 | 99.061798% |
| 2023 | 25,561 | 51,108 | 51,076 | 362,784 | 354,086 | 8,698 | 354,086 / 357,606 | 99.015676% |
| 2024 | 25,624 | 25,615 | 25,601 | 181,847 | 177,632 | 4,215 | 177,631 / 179,322 | 99.057004% |
| 2025 | 25,273 | 25,533 | 25,507 | 181,518 | 178,267 | 3,251 | 178,266 / 179,187 | 99.486012% |

正常は品質集計上のFINISHED/TIED。正式STAT採用policyではない。
結果状態別の延べ行数は以下。WITHDRAWNは各年0件で、JSONの観測済み区分には現れない。

| 状態 | rows | VALID / present | MISSING | 不正format |
| --- | ---: | ---: | ---: | ---: |
| FINISHED | 884,245 | 876,510 | 7,735 | 0 |
| TIED | 3,155 | 3,151 | 4 | 0 |
| DISQUALIFIED | 4,266 | 3 | 4,263 | 0 |
| CRASHED | 6,654 | 0 | 6,654 | 0 |
| DID_NOT_FINISH | 320 | 0 | 320 | 0 |
| DID_NOT_START | 1,356 | 0 | 1,356 | 0 |
| WITHDRAWN | 0 | 0 | 0 | 0 |

2023等は再取得分を含むため、延べ行数をuniqueレース・結果行数と混同しない。
1 importのレース75,473、2 importのレース25,824、import未登録レース29。
既存参照Rawの再取得必要率は物理欠損に関して0%だが、未登録29レースのRaw有無や、
中止48 importの解決に新規取得が必要かは未確認。全レースの再取得必要率0%とは主張しない。

### Header・識別

完全解析できた127,008ページ、4年・42競輪場でheader signatureは1種類:

`48be9980e816f89440fae32ba7ccc09822869f422eca0f405fa688d90cda88ec`

`coverage`の`header_recognized`/`result_table_present`は、車番照合まで完了したページ数。
途中で拒否した113ページも別の未解析ページ診断で再読込し、同じsignatureを確認した。
従ってheaderの確認済み総数は127,121ページ。補足診断にrank/winner値を保存・利用していない。

48 import / 40 uniqueレース (importは2022:15 / 2023:16 / 2024:7 / 2025:10) は `ENTRY_RESULT_BIKE_MISMATCH`。
全て保存importがCANCELLED。通常結果の不正選手へ強制結合することはしない。
例: race 15998 / import 16182、2025-02-17・track 24・10R。
出走車番1～7に対し、Rawは車番7・空のagariの1行、DB結果0行であり、完全照合不能。
空のPJ0326・CANCELLED import・DB結果0行が一致する別65 importは
`EXPLICIT_CANCELLED_NO_RESULT_ROWS`として区別する。存在しない結果を捏造しない。
解析成立分の登録番号・entry/player参照不一致は0。未照合48 importのplayer対応を成立扱いにしない。

### 競輪場・級班・開催グレード

年×競輪場162区分の全明細を `coverage-by-track.json` に保存。
正常有効率の最小は2022/track 37の4,099 / 4,315 = 94.994206%。
100%は2023/track 62の1,458 / 1,458、2025/track 75の898 / 898。
競輪場差の原因や能力・予測との関係は分析しない。

| 年 | S_CLASS 正常有効率 | A1_A2 | A_CHALLENGE | UNKNOWN競走数 |
| --- | ---: | ---: | ---: | ---: |
| 2022 | 99.178746% | 99.170053% | 98.634958% | 61 |
| 2023 | 99.098471% | 99.080344% | 98.750000% | 60 |
| 2024 | 99.109377% | 99.171483% | 98.727908% | 60 |
| 2025 | 99.634322% | 99.582722% | 99.035618% | 141 |

開催グレードはF1/F2/G1/G2/G3/UNKNOWN。保存gradeがUNKNOWNのレースは各年31、計124。
GPへの補完は行わず、既存Classificationの意味を維持した。
全分子・分母を `coverage-by-grade-class.json` と `coverage-by-meeting-grade.json` に保存。

### 分布・訂正

値はすべて小数1桁の原文。全VALIDの年別min / P50 / maxは、2022: 8.9 / 11.9 / 34.1、
2023: 8.9 / 11.9 / 42.0、2024: 8.8 / 11.9 / 25.5、2025: 8.8 / 11.9 / 26.6。
P01/P05/P10/P25/P50/P75/P90/P95/P99は `agari-value-distribution.json`。
このファイルは有効format全体の分布。正常/異常を分けた分布は報告用集約で別々に出力した。
正常群の分位点・min/maxは上記全VALIDと一致するが、nは169,678 / 354,086 / 177,631 / 178,266。
極端値は `SUSPICIOUS_VALUE` の確認候補であり、不正と断定したり除外thresholdに使用したりしない。
計測区間・競輪場補正・公式公開時刻は未確定。

比較可能な183,159隣接version行は全てUNCHANGED。VALUE_ADDED/REMOVED/CHANGED、FORMAT/HEADER_CHANGEDは0。
first/latestは101,200レース・700,885出走行で有効値比較でき、変化0。
これは確認できた保存version間の結果であり、保存されなかった訂正が存在しない証明ではない。

### 取得時点・固定Outer

全127,121 importはレース後取得。取得範囲は2026-07-21 19:54:55+09～2026-08-04 13:08:51+09。
従って2024/2025の対象発走前に取得済みだったという条件を満たさない。
レースの実施時刻を取得時刻の代用にしない。後からの再取得でも過去as-of要件は回復しない。

| Outer年 | 対象レース | 出走行 | PRE=0 | PRE>=1/3/5/10 | IN>=1 |
| --- | ---: | ---: | ---: | --- | ---: |
| 2024 | 25,212 | 179,089 | 179,089 | 全て0 | 0 |
| 2025 | 24,866 | 177,120 | 177,120 | 全て0 | 0 |

全356,209 target行でplayer identityとscheduled_start_atが確認できた。
従って今回の0件は、target側の識別・時刻欠損によるものではない。
履歴件数の全分位点は0。recencyはn=0で中央値・分位点NULL、30/60/90/180/365日以内率はいずれも0。
`LEFT_TRUNCATED_POSSIBLE` / `PUBLICATION_TIME_UNKNOWN`を保持し、選手の競走経験なしとは解釈しない。
後日訂正版のtarget履歴への混入0。本人targetレースの混入0。

### 保護・テスト・実行状態

DBのSTART/ENDは101,326レース・127,121 import・716,837結果状態行で一致。
snapshot SHA-256: `6044f0e11d69a90fbe57b81bb8f81f30ae570904d92fb6b985bd2f6d3d387011`。
8,112業務SELECT、session/transaction READ ONLY=on、DB write=0。
全RawのEND再ハッシュ、固定Outer4ファイルと直接依存コードの不変性も確認済み。
`data_quality_access_audit.json`は解析1回当たり127,121 Raw参照・716,837 unique結果状態行。
DB START/ENDの2回の物理読取り、追加Rawハッシュ走査、事前サンプル・補足診断はこの論理件数と区別する。
rank/winnerのsemantic利用・予測metric計算・2026レース参照はいずれも0。

focused 58 tests / 109 assertions。既存Parser関連51 tests / 286 assertions、
自動結果同期31 tests / 570 assertions。全体1,778件中1,769成功・9スキップ / 13,657 assertions。
PostgreSQL固有9件は通常のSQLiteテスト環境でスキップ。productionへMigrationは実行していない。
変更PHP10ファイルのphp-l/Pint、git diff --check成功。
execute-03: exit 0、811.462秒、PHP peak 36MiB / memory_limit=128M。
DB無効の全件reproduce: exit 0、1,209.636秒、PHP peak 32MiB / memory_limit=128M。
26生成物はBYTE_EXACT。別の集約スクリプトでもサイズ・SHA-256・JSONL行数を全件照合して一致。
37個の生成時sealとmanifest/LOCKEDは不変。報告用補足処理のPHP peakは12MiB。
参照Rawは127,121 distinct path / distinct SHA、総11,180,177,942 bytes。

初回は現行schemaに存在しない`race_entries.deleted_at`参照で失敗し、修正した。
2回目は中止の空結果分類等を検証するため中断。両方のログ・未公開stage・旧コードを別名で保持。
成功した契約はv2。既存成果物・Raw・DBを上書きしていない。
報告補助の初回はJSONL sealのrows付帯情報を考慮していない比較で停止した。
実際のbytes/SHAは一致しており、補助を行数も検証する形へ修正して再確認した。失敗ログも保持。
No predictive metric, adoption Gate or model has been computed or changed.

## 実行・共有資料

`run-command.php`と`*.execution.json`に実行コマンド・終了コード・所要時間を保存。
planはDB無効、executeは`PGOPTIONS='-c default_transaction_read_only=on -c statement_timeout=120000'`、
reproduceは`DB_CONNECTION=stat35_disabled DB_URL=`で、いずれも`php -d memory_limit=128M artisan`を使用。
コマンド名は`keirin:audit:stat35-data-readiness`。固定日付・既存audit-idの上書き禁止。
監査本体は合意済みroot内の`stat35-agari-readiness-2022-2025-01/`、再現は別`.reproduce-*`。
`report/REPORT.md`は指示【69】の40項目順。CSV/JSON、未解析診断、最小Raw参照、全差分を同梱する。
ZIPは同rootの`STAT-35-DATA-READINESS-AUDIT-01-report.zip`。
実在・サイズ・SHA-256・unzip/CRC・全member SHAの確定値は`zip-verification.json`を参照。

## Next Phase

Review only. A future storage proposal may consider `agari_time_seconds`, `agari_raw_text`, `agari_status`,
`agari_source_import_id`; timing distance would be separate. No schema or backfill is authorized here.
Growth k=34/w=+0.34 remains NOT_TRANSFERRED and not adopted. 2026 remains frozen.

## PR #64 Review Fix

開始HEAD `267a17c74b4254de4d04337ab7dc38238598de0d`、同じaudit branch、開始時clean。
上記旧run・bundle・ZIP・診断を履歴として保持する。以下はv3の独立した再監査である。

- A: CANCELLEDかつDB結果0の部分行を通常結果のidentity失敗と混同していた。
  同じheader・レース識別・PJ0326 object schema・車番範囲/重複を検証した中止専用経路に分ける。
  空配列はEXPLICIT_CANCELLED_NO_RESULT_ROWS、全agari空欄はEXPLICIT_CANCELLED_PARTIAL_ROWS_NO_AGARI。
  いずれも抽出結果・履歴は0行。非空agariとDB結果ありは独立blockerとして可視化する。
  空欄中止行のentry/登録番号異常はper-import診断に分離し、通常のidentity成立とは呼ばない。
- B: substringによる最終判定を廃止し、identity-mapping-audit.jsonに明示的な種別別件数を保存。
  unresolved extracted playerとunresolved targetを別々に加算し、一件でもあればidentity_safe=false。
  targetのNULL playerでは履歴SQLを実行せずUNRESOLVED_PLAYERを保持する。
  primary/secondary blockerを併記し、抽出できることと過去as-of利用可能性を分ける。
- C: reproduceはランダムsuffix付きの一意attemptを使用。Growth監査と同様、成功・失敗の証拠を保持する。
  suffixは決定的生成物に含めず、応答のattempt_pathだけに記録する。元bundleへ再公開しない。

契約: `STAT35-DATA-READINESS-v3-PR64-REVIEW-FIX`。
新ID: `stat35-agari-readiness-2022-2025-pr64-review-fix-01`。保存rootは旧runと同一。
Production全import READ ONLY executeと、DB無効の連続2回reproduceを128MBで実施した。
正常ページのagari・player照合・revision・時刻境界は変更しない。
readinessはidentity、semantic、as-of履歴不足、Raw gap policyの順に判定する。
import未登録レースは物理欠損Rawと同一視せず、別のraces_without_importとして記録しRaw gap policy対象とする。
正式STAT-35・schema・backfill・予測評価・2026レース参照は未許可のまま。

### PR64 Production Old / New

全127,121 importを本番READ ONLYと原Rawから再計算した。旧成果物のコピーによる結果作成ではない。
旧新database inventory・targets・agari全行・target履歴明細・revision・分布等19ファイルはbytes/SHAが一致。
全127,121 Raw参照の取得metadataも旧新完全一致。coverageは中止分類の変更だけで、通常値は全層不変。

| 項目 | 旧run | PR64修正版 |
| --- | ---: | ---: |
| 対象レース / import | 101,326 / 127,121 | 101,326 / 127,121 |
| Raw存在 / hash一致 | 127,121 / 127,121 | 127,121 / 127,121 |
| 通常解析import / 抽出行 | 127,008 / 899,996 | 127,008 / 899,996 |
| ENTRY_RESULT_BIKE_MISMATCH | 48 | 0 |
| 空配列中止import | 65 | 65 |
| 空欄部分行中止import / rows | 独立分類なし | 48 / 53 |
| 中止非空agari / DB結果矛盾 | 独立分類なし | 0 / 0 |
| unresolved extracted player / target | 独立blockerなし | 0 / 0 |
| identity blocker total | 48（旧heuristic） | 0（明示的監査） |
| identity_safe | false | true |
| 正常有効agari / 正常行 | 879,661 / 887,400 | 879,661 / 887,400 |
| revision比較 / changed | 183,159 / 0 | 183,159 / 0 |
| first/latestレース / 出走 / changed | 101,200 / 700,885 / 0 | 101,200 / 700,885 / 0 |

再分類した48 importは40レースで、全てCANCELLED・DB結果0・agari空欄。
53行の非空agari、entry対応異常、登録番号異常は実集計で全て0。
空欄中止を通常のparsed_importsへ加算せず、履歴にも入れていない。
Headerは全127,121参照で同一signature `48be9980e816f89440fae32ba7ccc09822869f422eca0f405fa688d90cda88ec`。

readinessは旧BLOCKED_IDENTITY_MAPPINGから **BLOCKED_INSUFFICIENT_RAW_HISTORY** へ変更。
primary=INSUFFICIENT_RAW_HISTORY、secondary=RAW_GAP_POLICY（import未登録29レース）。
2024の179,089 target / 2025の177,120 targetについてPRE/IN履歴は両年とも0のまま。
storage_backfill_feasible=trueは既存確認済みRawの抽出可能性だけを示す。
historical_as_of_backtest_feasible=falseであり、正式STAT実装やbackfillの許可ではない。
PUBLICATION_TIME_UNKNOWNを維持し、当時サイト上に値がなかったとは結論しない。

本番START/ENDは旧runと同じSHA、8,112業務SELECT、session/transaction READ ONLY=on、write=0。
executeはexit 0、813.448秒、PHP peak 36MiB、memory_limit=128M。
focused 76 tests /225 assertions、Parser関連83 tests /737 assertions、自動レース同期31 tests /570 assertions。
全体1,787成功 /9 skip /13,773 assertions、変更PHP6ファイルのphp-l/Pint成功。
9 skipは通常SQLite環境でのPostgreSQL専用テスト。production Parser・Migration変更は0。

### PR64 Reproduction / Evidence

同一IDの実データreproduceをDB_CONNECTION=stat35_disabled、memory_limit=128Mで連続実行した。
1回目はexit 0、1,124.190秒、2回目はexit 0、887.862秒。PHP peakは両方32MiB。
各27生成物がBYTE_EXACT。別の照合処理でも全サイズ・SHA-256・JSONL行数が一致した。
attempt suffixはそれぞれc9866ad57dc194da / 7619de7c55287600で、衝突0。両方の試行証拠を保持する。
人工テストでは同サイズRaw改変で失敗後、復旧した新attemptが成功し、失敗証拠hashも不変であることを確認。
元の正式bundleを再公開・上書きせず、旧bundleと旧ZIP計40ファイルのSTART/END不変を確認した。
旧ZIP SHA-256はcc73cb79342fd65d5470ceb263cdf6f1c063c0caf8b28f78f303bf8e5d4cf1cdのまま。

同じ保存rootのpr64-review-fix/へ、指示【57】の46項目順REPORT、旧新比較、全実行ログ、
中止診断、生成物参照、独立照合、変更コード・テスト・文書・Git差分をまとめる。
実行コマンドはpr64-review-fix-run.phpと各*.execution.json、追加照合はpr64-review-fix-evidence.phpに記録。
新共有ZIPはSTAT-35-DATA-READINESS-AUDIT-01-PR64-review-fix-report.zipで、旧ZIPとは別ファイル。
存在・bytes・SHA-256・unzip/CRC・全member SHAの確定値はpr64-review-fix-zip-verification.jsonを参照。
Raw本文の大量複製は行わず、参照・hashと中止診断を同梱する。
PR #64の同じbranchで未コミットのレビュー待ちとし、次実装はNOT_AUTHORIZED。
