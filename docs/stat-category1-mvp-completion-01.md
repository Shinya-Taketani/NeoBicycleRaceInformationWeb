# STAT-CATEGORY1-MVP-COMPLETION-01

## 承認範囲と判定

2026-10-12の最新ユーザー指示に基づき、区分1の16項目だけを対象とする。
開始はclean main / ローカルorigin/main `3abaaf8acc2230cd660abe339e7757d10b8afae9`
（ローカル履歴でPR104 mergeを確認）、作業branchは
`feature/stat-category1-mvp-completion-01`。今回fetch・GitHub照会は行っていない。

**限定MVPのコード・人工検証完了、レビュー待ち。実DB正式保存は今回未実施・未確認。**
これはSTAT-01〜46全要件、品質の正式confidence、発走前利用、モデルの正式採用の完成を意味しない。
区分1以外、C1/C2/C3、Solver/Decoder/Gate、旧比較・モデル・成果物は変更しない。
`historical_as_of_available=false`、予測利用未承認、2026 holdout閉鎖を維持する。

確認した分類資料は `/home/shinya/ダウンロード/STAT-01-46_データ取得可否4分類_ドラフト_v0.1.md`。
要件定義資料はユーザー提示のダウンロード配下のURLエンコード名ファイルで、
ファイル名の `v1.0(2)` と本文の「文書バージョン v0.1」は一致していない。
今回の16項目は本文の該当節、分類ドラフト、既存MVP契約と最新指示を照合した。
本文の高度版候補・未確定の配点を今回の製造契約に追加していない。

## 16項目の受入台帳

表の `IMPLEMENTED` は既存DB限定MVPの実装状態。高度版は未完成のままである。
全行の保存状態 `未確認` は、今回本番DBへ接続せず正式runを再確認していないことを示す。
旧文書の2024正式保存件数・run記録は当時の記録であり、今回の実測ではない。
U/Fは下記クラス別のUnit/Featureケース数。同じBatchの共有テストを各STAT行に示すため、行ごとに加算しない。
全版は `STAT-xx-existing-db-v1`、Calculatorは `app/Domain/Keirin/Statistics/Calculators/` 配下。

| STAT | MVP契約（対象／対象外） | Calculator | 実装状態 | 今回テスト | データ品質・時点 | 正式保存 | 未完成事項 |
|---|---|---|---|---|---|---|---|
| 01 | 得点生値・順位・平均との差・最高差・母分散／配点・級班標準化は対象外 | Stat01Calculator | IMPLEMENTED | U12/F13 PASS | NULLは欠損、0以下は不正、相対値は部分母集団を監査、汎用fetched_atは得点専用時刻でない | 未確認 | 原資料との対応・発走前観測証明、正式配点 |
| 07 | 同一場対全場の正常成績・得点期待残差／改修条件補正なし | Stat07Calculator | IMPLEMENTED | B3 U21/F4 PASS | 本人・同一場履歴、対象/未来除外、異常は正常率から分離 | 未確認 | 改修版・縮約・最低標本・減衰 |
| 08 | JST連続時刻・同時刻帯対全時間帯／公式ナイター分類なし | Stat08Calculator | IMPLEMENTED | B3 U21/F4 PASS | 発走時刻不明は欠損、公式区分は未確認、異常分離 | 未確認 | 公式開催区分・境界・交絡補正 |
| 10 | 正常直近成績・得点期待残差、開催前/開催内を分離 | Stat10Calculator | IMPLEMENTED | B2 U9/F10 PASS | 正常率は正常完走分母、NO_HISTORYと部分窓を区別 | 未確認 | 正式窓・最低標本・減衰・配点 |
| 11 | 実発走を分母に異常別率・直近性／事故責任推定なし | Stat11Calculator | IMPLEMENTED | B2 U9/F10 PASS | DNS/中止は実発走率から除外、分母0はNULL、観測0件は0 | 未確認 | 公式事故原因・責任・訂正時点 |
| 12 | 実発走間隔・本人分布／長期休養分類・理由推定なし | Stat12Calculator | IMPLEMENTED | B2 U9/F10 PASS | DNS除外、異常実発走は含む、予定時刻とcutoffを分離 | 未確認 | 休養閾値・復帰局面・公式理由 |
| 20 | 保存run/resultの充足・識別・時点・窓の監査／総合confidenceなし | Stat20Calculator | IMPLEMENTED | U41/F14 PASS、run受入24 PASS | PARTIAL/DEGRADEDを基本とし、重大不正/欠損を分離。元Raw・公開時刻等は未検証 | 未確認・新規正式保存0 | fetch-log/Rawの厳密対応、正式confidence・モデル実績 |
| 23 | 過去開催の日番号別正常成績／意図・通過圏推定なし | Stat23Calculator | IMPLEMENTED | B3 U21/F4 PASS | target開催の未来成績は除外、日番号不明は欠損、段階は別STAT | 未確認 | 公式進出条件・通過圏・戦略解釈 |
| 24 | 正常成績の母分散・IQR・MAD・残差変動／安定性配点なし | Stat24Calculator | IMPLEMENTED | B2 U9/F10 PASS | 異常結果はSTAT11へ分離、単一標本の母分散0、空母集団はNULL | 未確認 | 正式窓・時間減衰・採用分類 |
| 26 | 実出走数・開催/活動日・日程密度／医学的疲労推定なし | Stat26Calculator | IMPLEMENTED | B2 U9/F10 PASS | DNS/中止を実出走数へ加えず、移動距離・役割負荷は未取得 | 未確認 | 移動/役割・通常負荷補正・閾値 |
| 31 | 保存グレード・準決/決勝経験量と正常成績を分離 | Stat31Calculator | IMPLEMENTED | B3 U21/F4 PASS | 異常実発走は経験に含むが正常成績には混ぜない、未知grade/stage監査 | 未確認 | 制度時点マスタ・正確な進出機会・重み |
| 32 | 正規化stage別対通常成績・残差／公式制度補正なし | Stat32Calculator | IMPLEMENTED | B3 U21/F4 PASS | OTHERとUNKNOWNを区別、未知の今回段階は欠損、未来除外 | 未確認 | 制度版・公式stage・役割補正 |
| 33 | 同開催前走と過去の隣接正常出走の観測遷移 | Stat33Calculator | IMPLEMENTED | B3 U21/F4 PASS | 開催初戦はNOT_APPLICABLE、異常前走はPARTIAL、後日確定は履歴監査 | 未確認 | **公式勝ち上がり・通過圏・ポイント・tie-breakは未実装** |
| 39 | 車立て/車番/枠番/場別の成績・残差／因果効果・占術なし | Stat39Calculator | IMPLEMENTED | B4 U9/F7 PASS | 対象以前の母集団集計、枠欠損だけで車番値を消さない | 未確認 | 割当方式・初手/ライン補正・変更履歴 |
| 41 | 固定STAT01得点分布・上位点差・pairwise点差、1 race 1 row | Stat41Calculator | IMPLEMENTED | U8/F8 PASS | 競走得点欠損/不正・車立て矛盾を監査、同一cutoff必須 | 未確認 | **拮抗/波乱/予測不確実性scoreはNULL、確率・ライン等未接続** |
| 42 | 同乗選手との直接対戦pair・相対残差／推移律なし | Stat42Calculator | IMPLEMENTED | B4 U9/F7 PASS | 同着/異常/DNSの分母を分離、履歴なしを0相性にしない | 未確認 | 敵味方ライン・役割・主要相手選定・配点 |

### 入力経路・計算・保存の共通確認

- 01: `Stat01RaceInputDto/Stat01EntryInputDto` → `Stat01InputRepository` → `Stat01FeatureBuildService` → `BuildStat01FeaturesCommand`。
- B2（10/11/12/24/26）: `Batch02TargetEntryDto/HistoricalRaceDto`、`HistoricalRaceRepository`、`Batch02FeatureBuildService`、`BuildBatch02FeaturesCommand`。
- B3（07/08/23/31/32/33）: `Batch03TargetEntryDto/Batch03HistoricalRaceDto`、`Batch03HistoricalRaceRepository`、対応B3 Service/Command。
- B4（39/42）: `Batch04TargetEntryDto/Batch04PositionHistoryContextDto/Batch04HeadToHeadEventDto`、`Batch04TargetRepository/Batch04PositionBiasRepository/Batch04HeadToHeadRepository`、対応B4 Service/Command。
- 41: `Batch05RaceInputDto/Batch05TargetEntryDto`、`Batch05TargetRepository`、対応B5 Service/Command。出走者別resultとレース別resultを混同しない。
- 履歴Repositoryは本人IDをjoinし、CONFIRMED/CORRECTED、対象レース以外、`scheduled_start_at < input_as_of` を用いる。後日バックフィルした最終状態であり、当時の公式公開・確定時刻を復元した証明ではない。
- 正常着順強度は `(車立て - rank) / (車立て - 1)`。正常率は正常完走、異常率/間隔/日程は実発走を分母とする。得点残差は保存相手得点が有効な場合だけ計算し、不完全ならNULL。
- 比率は分母0ならNULL、対象ありの観測0は0。5〜9車、同着・失格・落車・欠場・未完走、部分窓・履歴なし、本人/車番、target/未来除外は関連人工試験で確認。
- 既存DTO/context hashと元履歴hashを決定的に計算し、出走者/履歴の並び順に依存させない。元入力・相手変更、chunk差、再実行の新run、race単位失敗継続を既存試験で確認。
- DB保存先は既存 `statistic_feature_runs/run_items/results`。1 run×subjectはUNIQUE、race単位transaction。dry-runは統計テーブルにも書かず、業務テーブルは保存実行でも変更しない。
- `docs/sql/validate_stat01_2024.sql`、`validate_batch02_2024.sql`〜`validate_batch05_2024.sql` と新 `validate_stat20.sql` は正式保存後の検証手段。**今回は本番でSQLを実行していない。**

## STAT-20の契約

`Stat20BuildOptionsDto/Stat20SourceInputDto/Stat20RaceInputDto/Stat20FeatureResultDto/Stat20BuildSummaryDto`、
`Stat20InputRepository`、`Stat20Calculator`、`Stat20FeatureBuildService`、`BuildStat20FeaturesCommand`を分離した。
versionは `STAT-20-existing-db-v1`、modeは既存 `BACKFILL`。

対象は明示したSTAT01 runの出走snapshot。任意の14 runもIDを明示し、最新runを自動選択しない。
runのSTAT/version/mode/終端状態/finished_at、下流runの `parameters.stat01_run_id` を検証する。
STAT01は処理完了・error0が必要。他runで失敗した対象行は推測で補完せず `SOURCE_RESULT_MISSING`。
省略されたrunは `SOURCE_RUN_NOT_SELECTED`。どちらも `DATA_SOURCE_PENDING`。

各resultはrace/entry/player/bike、subject key、version、status/qualityの既存Enum、hash形式、
共通input_as_of、下流evidenceのSTAT01 run/hashを照合する。STAT41はrace粒度で、participant_snapshotの全員と照合。
ENTRANT_COUNTは全行で整数5〜9かつ一致が必要。数値文字列・floatを整数へ暗黙変換せず不正として監査する。
現在のrace_entriesとの本人対応は確認するが、ソフトデリート行を含む現在の対応確認を歴史的本人証明とは呼ばない。
保存input_hashの形式とlineageの検証は、元資料からのhash再構築・seal検証とは別である。

保存するfeaturesは `REQUIRED_INPUT_AUDIT`、`SOURCE_RESULT_COVERAGE`、`RACE_ENTRY_COVERAGE`、`STAT_QUALITY`、`CONFIDENCE=null`。
各STATのstatus/quality/run/resultID/version/hash、取得時刻のBEFORE/AT/AFTER/UNKNOWN、窓充足・標本数・理由を残す。
source statusの充足率は**15項目の保存結果状態の件数比**であり、入力列の総充足率・性能・信頼度・重要度の単純平均ではない。
NOT_APPLICABLEは適用項目分母から除外し、NO_HISTORY/PARTIAL_HISTORY/不正/未接続を区別する。

取得時刻は保存resultの `source_fetched_at`。STAT01は汎用出走行由来で得点専用時刻ではない。
発走後取得のAFTER、同時刻AT、不明UNKNOWNをそのまま残し、BEFOREも公式公開済みの証明にしない。
取得ログ・Raw・元Parser版は厳密な対応資料未接続。`DATA_SOURCE_PENDING` / `NOT_VERIFIED` を保持し、推測join・DB/Raw fallbackを追加しない。
confidenceの重み・閾値・モデル実績は未定義であり、raw_points/confidence/effective_pointsは全NULL。

原則 `PARTIAL/DEGRADED`。未解決本人・cutoff不明は `MISSING_INPUT`、上流INVALID_INPUT・車立て不整合・
history_max_atがcutoff以降なら `INVALID_INPUT` とblocked_reasons。重大な識別/lineage/hash型不正はraceをFAILEDにし、resultを保存しない。
DBに未知の新statusは追加しない。後日取得のBACKFILL自体は監査時点リスクとして残し、発走前予測を承認しない。

STAT単位の監査はSTAT_QUALITY、選手単位はRACE_ENTRY result、レース充足は共通RACE_ENTRY_COVERAGEとcontext hashに保存。
独立レースconfidence行やモデル信頼度を新設しない。全選手の元resultをcontext hashへ含め、相手入力変更も全対象hashへ反映する。
STAT41の参加者順序だけは新STAT20のhash用投影でentry ID順にする。原evidence・共有hasher・既存hash仕様は不変。
対象raceはID keyset、chunk1〜1000（既定100）、各raceのsource上限135行。各raceで保存transactionを閉じ、error後も後続を処理しrunを終端化する。

運用例（**今回、本番では未実行**）:

```bash
php artisan keirin:statistics:build-stat20 \
  --stat01-run-id=<reviewed-id> \
  --source-run-id=<reviewed-downstream-id> \
  --from=2024-01-01 --to=2024-01-01 --chunk=100 --dry-run
```

`--source-run-id` は複数指定可。日付範囲かrace-idを必須とし、2026以降・両者混在・未限定・重複runを拒否する。
stored invocationごとに新しいrunを保存し、同一入力hashでも過去runを上書きしない。

## 再現した既存不具合と修正

既存B2/B3/B4/B5の `validatedStat01Run()` は件数/error/versionだけでRUNNINGやFAILED、finished_at=NULLを受け入れた。
人工再現は4 Repository×4状態、修正前16 failures / 16 assertions / exit1。
終端 `SUCCEEDED/PARTIALLY_SUCCEEDED` と終了時刻の2条件だけを各Repositoryへ追加。
修正後の拒否16ケース＋成功/部分成功の許可8ケースは24 tests / 40 assertions / exit0。
計算式・version・過去資料を変更していない。旧実験コードsealや過去結果を今回コードの実行記録へ読み替えない。

新STAT20の開発中は、参加者順序のhash差を追加試験で検出して上記専用投影を修正。
初回保存比較テストのstdClassオブジェクト同一性比較も、全列を配列にしたstrict値比較へ修正した。
最終レビューで整数7と文字列"7"/float7.0の混在を同一車立てとして扱う問題を5ケース中2 failuresで再現し、全行の整数型検査を追加。5ケース/75 assertionsが成功し、正常入力のhashは不変。
失敗ログは保持し、既存テストの削除・緩和・新規skip・警告抑制は行っていない。

## 今回の検証

PHP8.5.4 / PHPUnit12.5.31（AGENTSのPHP8.4記述との差を明示）、独立PHPへ `-d memory_limit=128M` を適用。
Linux bwrapの単一root viewでネットワーク/socket・実.env/storage/cacheを隔離、人工SQLite :memory:とvendor-only bootstrapを使用。
実repoはread-only。秘密のない人工.env、保持する人工領域だけ書込み可。実Desktop・旧成果物の移動/削除は0。
他環境のbwrap/user namespace互換性や本番PostgreSQL上の照合成功を今回の結果から保証しない。

| 最終検証群 | tests | assertions | 結果 |
|---|---:|---:|---|
| 既存15項目のCalculator/Command/SQL/Normalizer | 129 | 1338 | PASS |
| STAT20 Calculator（独立メモリ試験・SQL契約を含む） | 41 | 479 | PASS |
| STAT20 Command/Repository/Service | 14 | 131 | PASS |
| 既存4 Repositoryのrun受入 | 24 | 40 | PASS |
| 合計 | 208 | 1988 | failure/error/warning/skip=0、exit0 |

最終検証2026-10-12 18:38:04〜18:38:09 JST、5.804583秒、PHPUnit peak66.50MiB。
型混在の修正前の中間検証203/1913は保存ログの当時の結果として残し、最終結果へ読み替えない。
人工6,000レース/42,000出走・累計583,714,986 bytesの逐次入力は独立128Mでpeak10MiB/exit0。
既存履歴のpaged query/5race working batch、STAT20のraceごとの処理を人工試験・コードで確認した。
これは本番年間全量処理の実測メモリではない。

通常全体テストは未実施。対象外の `C1MarginalP23DecoderTest.php`、`C1Stat35P1CompositionTest.php` と
`Bt03e05MetricContributionSpool.php` に永久削除処理が残るため、安全確認済みの関連範囲だけを実行した。
過去59 warningsの本文は今回調査せず、原因/全件解消はNOT_CONFIRMEDのまま。
変更19PHPの構文・限定Pintは全件成功/exit0、git diff --checkもexit0。
新規未追跡17ファイルもno-indexの差分検査で空白エラーなし（差分ありを表すexit1と検査エラーを区別）。DB Migration追加は0。

証跡root:
`/home/shinya/neo-keirin-artifacts/stat-category1-mvp-completion-01/verification-20261012-175659-cec31fd1/`

各実行のstdout/stderr/execution.json（実コマンド・exit・時刻）、sandbox/evidenceのJUnit/events、
修正前の拒否失敗と開発中の失敗を区別して保持する。大きいモデル/年次資料のコピーは行わない。

## 正式保存前の残件

本番DBのrun件数・schema・入力対応、READ ONLY dry-run/検証SQL、正式書込みは別途承認が必要。
照合対象は明示runの `statistic_feature_runs/results/run_items`、対象2022〜2025の `races/race_entries` に限定し、
runID・期間・chunk・statement timeout・想定件数/負荷を先に確定する。今回、本番SQL接続は0。
元Raw/log/Parser版・公式公開時刻の証拠が不足している品質要素は、不明のまま保存する。
取得証拠がなければ歴史的as-of利用を承認せず、confidenceや配点を勝手に作らない。
次はコード・人工検証・台帳のレビューのみ。commit/push/PR作成/mergeは行わない。
