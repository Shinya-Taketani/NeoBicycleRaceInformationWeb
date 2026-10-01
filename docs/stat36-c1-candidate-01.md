# STAT-36-C1-CANDIDATE-01

## 範囲・状態

- 開始main/origin・HEAD: `c24050eb6138483e37c9691ff6d949d222e47624`。ユーザー確認のPR #81マージを反映する。
- 作業branch: `feature/stat36-c1-candidate-01`。変更は未コミット。
- 契約: `STAT36-C1-CANDIDATE-v1`。保存表示S回数の候補sidecarであり、正式STAT特徴量・モデル入力ではない。
- 許可は固定3束の保存メタデータ読取り、人工検証、実候補生成1回、独立offline再現1回だけ。
- C1・既存モデル/係数/予測、C2追加Gate NOT_PASSED、旧pilot BLOCKED_INPUT_SEMANTICS、StartCountSnapshot v1、StartObservation v3 / DisplaySignature v2を維持する。
- DB接続/書込み、HTTP、Raw本文読取り/再解析、Migration、S再生成、学習/予測/性能評価、2026レース、LIVEは行わない。

## 固定source

| 用途 | 固定manifest SHA-256 |
|---|---|
| S snapshots / 5297 bytes | `bcadbe02aecb3eaee510b2646fc38b305db08f11633d3bf397be1e193190386d` |
| outcome-free C1 | `7f4356b93f90203dc727dc95d6215a8d8ce3f44869c4441c3981087ff1099c26` |
| 本人/開催 mapping / 3564 bytes | `5facd83259a5b2b147115ff1632632a6f7a11f96e07f9f4d3078286743eb839b` |

固定ディレクトリは `Sources::FIXED` / 出力contract.jsonに全文記録する。Sは
`stat36-start-count-01/run-20261001-nK8sZXFb/build`、C1は
`stat35-c1-input-02/run-20260930-054458-8739da4b/result`、mappingは
`stat35-c1-context-01/run-20260928-215453-d152edab/mapping`。すべて
`/home/shinya/neo-keirin-artifacts/` 配下。

固定manifest/COMPLETEと、実際に読む年別snapshots/unresolved、fetch-audit、coverage、
C1年別4本、mapping-audit/provenanceのsize/SHAを検証する。未使用の旧原本、
STAT35 sidecar、Raw本文、全旧成果物は読まない。未知版・欠落・改変を拒否し、
直接依存コードと読取りファイルのSTART/END一致を公開前に検査する。

## 対応と取得版

C1のyear/race_id/entry_id/bike集合と出現順が正本。非単調IDでも並べ替えない。
mappingは全対象を含むmapping-auditを使い、candidate=falseだけで拒否しない。
UNKNOWN_RACE_CLASSだけなら本人/開催が検証済みの対応は利用し、元理由を監査へ残す。
本人・開催対応、内部player_id状態、外部6桁ID、固定target日付と開催期間、
保存台帳・PC0201・PJ0315観測のレース/車番/外部IDを照合する。

**C1年別入力にはplayer_id列がない。** 存在しない列を追加したり、DBで補ったりしない。
mapping provenanceの原C1 manifest seal、各年対象数とnon_result_sha256が、固定C1束の
原本sealと投影ファイルsealに一致することを検証してから、同じ原対象の
mapping target.player_idとplayer_id_status=MATCHを根拠にする。現在プロフィールは使用しない。

取得版は全部保存する。全正常数値が同じ整数なら一候補。整数3/文字列"003"は
原型差として保持し、数値競合とはしない。0も観測値として保持する。
異なる整数はVALUE_CONFLICT、成功版の行欠落/不正値/本人矛盾が混在すれば保留。
最新版・多数決・平均/合計で解決しない。DNS等の失敗版は数値観測ではなく、
正常別版の一致判定を妨げず、fetch版の監査に残す。

同一fetch/row_index重複や未結合の行、mapping欠落/余分/重複は公開前に拒否する。
C1と同じrace/bikeの異本人は対象外に捨てず矛盾として保留する。
C1にない観測はOUTSIDE_C1_COHORTとして別明細・件数に残し、母集団を増やさない。
個別未検証行は他の正常行を巻き込まず、検証済みの真の開催競合は全レースを保留する。
検証済み同一レース内の外部ID重複は該当本人を両方保留する。

代表出典は最小fetch_log_id、次にrow_indexの固定順で参照だけを選ぶ。
全取得版、成功行/失敗版、原型/値/型/存在状態、原本/変換hash、
source pointerと保存snapshotファイルhash/行番号をsource-observation-linksへ残す。
この代表を発走前版・正式最新版と主張しない。

## 時点と利用拒否

候補値と時点適格性は独立。fetched_atはSYSTEM_FETCH_TIMEであってS cutoffではない。
明示timezoneのある妥当な日時だけを、Asia/Tokyoの対象日と比較する。

| 判定 | 意味 |
|---|---|
| BEFORE_TARGET_DATE | 取得日が対象日より前。S基準時点の保証ではない |
| SAME_TARGET_DATE_NO_START_TIME | 同日。発走時刻がないため発走前と判定しない |
| AFTER_TARGET_DATE | 後日取得。S自身が未来結果を含むと断定しない |
| UNKNOWN_TIME_OR_TIMEZONE | 時刻またはtimezone未確認 |
| INVALID_FETCH_TIME | 日時/offset不正。自動繰上げ・補正しない |

aggregation_period/statistical_as_of/correction_as_ofはnullのまま。
4ヶ月を120日に変換せず、lastUpdateTimeも転用しない。
全候補は以下を明記する。

```text
artifact_role=REVIEW_CANDIDATE_ONLY
prediction_use=NOT_AUTHORIZED
training_evaluation_authorized=false
historical_as_of_available=false
points=null
```

専用Bundleをtraining/prediction/evaluation用途で開くと、データ読取り前に
BLOCKED_INPUT_SEMANTICSで拒否する。解除CLI/hash自己申告機能はない。
既存モデルが読むsignals/historyへ値を追加せず、
candidate_displayed_start_countとして独立sidecarに出す。
完成状態はCANDIDATES_PREPARED_NOT_AUTHORIZEDであり、学習準備成功とはしない。

## 実行経路・成果物

```bash
php -d memory_limit=128M artisan keirin:stat36:c1-candidate plan
php -d memory_limit=128M artisan keirin:stat36:c1-candidate build --output-dir=<new-run>/build
php -d memory_limit=128M artisan keirin:stat36:c1-candidate reproduce --original-dir=<new-run>/build --output-dir=<new-run>/reproduce
```

planは契約表示のみ。固定保存rootはユーザー指定
`/home/shinya/neo-keirin-artifacts/stat36-c1-candidate-01/`。
既存パス/入力への上書き・重複を拒否する。CommandはDB/HTTPを拒否し、
ディスク上SQLiteインデックスとJSONLストリームを使う（本番DB接続ではない）。

15成果物: contract、候補年別4本、対応監査年別4本、source-observation-links、
coverage JSON/CSV、timing-status、invariance、verification。manifest/COMPLETEで公開する。
独立再現は固定3束から新しく生成して全15ファイルとmanifestを比較し、
元束もSTART/END再検査する。PID・経過時間・保存先・exit/peakは別ログに保存する。

## 検証・実行記録

人工回帰: 全版一致/真の数値競合、原型差、0/NULL/欠損、不正値、本人/開催矛盾、
UNKNOWN class、C1集合・順序、未対応/対象外、重複/未知field/版/seal改変、
時刻・timezone、学習拒否、正常行維持、真の重複、100MiB超の独立128M生成。
既存テスト削除・緩和・新規skipなし。

関連200 tests/1514 assertions（新規43ケースを含む）、最終コード通常全体
**2593 passed / 9既存skip / 24407 assertions**、exit0、189.089秒。
CSVキー最終修正前に開始した全体確認も成功したが、初期記録 `full-final/` として分離。
最終全体は `full-code-final/`。追加PHP11件構文、限定Pint、100MiB超の独立128M試験成功。

### 実候補生成・独立再現

run: `/home/shinya/neo-keirin-artifacts/stat36-c1-candidate-01/run-20261001-WbqYscoJ/`

| 年 | C1レース | C1出走 | 本人/開催接続 | 数値候補 | 候補NULL | 数値0 |
|---|---:|---:|---:|---:|---:|---:|
| 2022 | 24,394 | 170,835 | 170,835 | 170,835 | 0 | 47,139 |
| 2023 | 25,197 | 179,007 | 179,007 | 179,007 | 0 | 43,924 |
| 2024 | 25,212 | 179,089 | 179,089 | 179,089 | 0 | 42,750 |
| 2025 | 24,866 | 177,120 | 177,120 | 177,120 | 0 | 43,716 |
| 合計 | 99,669 | 706,051 | 706,051 | 706,051 | 0 | 177,529 |

全年度とも値競合/候補NULL/排他的保留/非排他的保留理由は0。
UNKNOWN_RACE_CLASSのみの元理由は96/158/97/652出走、合計1,003を監査に残し、
本人/開催の検証成功を利用した。STAT35側のclass保留を解除したわけではない。

| 年 | 元snapshot行 | C1対応行 | 対象外行 | 対象外unique出走 | C1複数同値版出走 |
|---|---:|---:|---:|---:|---:|
| 2022 | 174,152 | 170,835 | 3,317 | 3,317 | 0 |
| 2023 | 363,103 | 358,021 | 5,082 | 2,541 | 179,007 |
| 2024 | 182,004 | 179,089 | 2,915 | 2,915 | 0 |
| 2025 | 181,779 | 178,838 | 2,941 | 2,885 | 1,718 |
| 合計 | 901,038 | 886,783 | 14,255 | 11,658 | 180,725 |

差引き推定ではなく、全観測をrace/bike/本人照合して計測した。
取得版127,160、失敗1版（2023 race84651/fetch215551）は数値行0として明細を保持。
同レース7出走は他の正常版で候補成立。原型差/数値競合は今回の実測で各0。
source-observation-linksは127,160取得版と901,038 snapshot行の双方を含む。

正常例: 2025 race12542、entry89422/bike1、外部ID014985は4。
entry89423/bike2、外部ID012878は0。各2正常版・一意数値。
最小fetch39453のsnapshots-2025.jsonl行1/2、
row_index0/1を参照し、全版は別明細で保持する。
実データの保留例は0件。人工例だけでは、正常0に異なる4が混在すれば
VALUE_CONFLICT、正常値にnull/欠落/不正値が混在すれば当該出走だけ保留する。
人工保留例を実データ実績として表示しない。

C1年別原本seal/非結果semantic digestと候補順digestはinvariance.jsonに保存。
原C1は非結果値・対象集合・出現順・型とも不変。
全対応観測886,783行の取得日分類はAFTER_TARGET_DATE。
S期間/基準日/訂正時点の未確認は706,051候補すべて。
後日取得を未来結果混入の確定へ変換していない。

- build: 2026-10-01 18:47:29-18:48:42 JST、73.738秒、exit0。
- reproduce: 同日18:49:38-18:50:53 JST、75.381秒、exit0。
- 両方128M指定、peak31,457,280 bytes（30MiB）。
- 15成果物とmanifest4603 bytesが完全一致。
- manifest SHA-256: `f175deff20fe8905b40e92ffa2d9ff16de71f432584c9e13e129d47045306437`。
- source/code START/END、各取得版行数、全901,038観測の一度ずつ計上を検証。
- 完成束をtraining/prediction/evaluation用途で実際に開き、すべて
  BLOCKED_INPUT_SEMANTICSで拒否。レビュー用の読込みだけ成功。
- 実引数/stdout/stderr/開始終了/exitはbuild-execution、reproduce-executionへ保存。
  report.json、reproduction.json、verification.jsonも保存済み。DB/HTTP/Raw本文、
  S再生成、学習/予測/評価、2026レース参照は0。

数値候補接続は全C1対象で完了。S時点適格性の証明・学習利用承認は未完了のまま。
**CANDIDATES_PREPARED_NOT_AUTHORIZEDとして未コミットのレビュー待ちで停止する。**
旧v1の901,038行、14成果物一致、manifest等は旧工程記録のまま保持する。
今回の結果に読み替えず、C1/旧S/旧モデルを再生成・変更しない。

## 未確認事項・レビュー境界

数値接続が成立しても学習利用は未承認。必要な一次資料確認事項は次の3点である。

1. S回数の集計起算・端点・基準日。
2. 過去race指定時に当該race自身と以後の結果を含まないこと。
3. 掲載更新時刻とS回数の更新・訂正時点の関係。

今回の範囲外であり、少数Raw再確認・全量再抽出・学習等へ自動移行しない。
