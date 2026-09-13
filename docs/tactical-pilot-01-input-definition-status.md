# TACTICAL-PILOT-01 入力定義・着手状態

- 確認日: 2026-09-13
- 開始SHA / origin/main: `d7975fb3b09f127cb9afb9c89aa1bb33d07c857c`
- 作業branch: `experiment/tactical-pilot-01`
- 状態: **BLOCKED_INPUT_SEMANTICS**
- C1-C0 / C1-STAT-01精度差・CI・Gate: **NOT_EVALUATED**。0差や性能FAILとは扱わない。
- 新規fit: 0、学習用snapshot生成: 未実施、C0の再利用適格性検証: 未実施。

## 1. 許可と先行結果を分離する

今回の限定実験はユーザーが渡した `Codex_TACTICAL-PILOT-01_Implement_Train_Compare.md` の新規指示による。
E08はengineering・development評価・再現性確認済みの否定結果として閉じる。E08の再学習・再評価、DATA-AUDIT-01の再実行をしない。
E06も本番採用済みモデルではない。既存結果を戦法入力追加の成果へ付け替えない。

C0はSTAT-01 anchor + 既存12 STAT、E03 v2の逐次条件付きcategorical NLL学習とE06型decoder。
C1は同じ経路へ適格な戦法回数だけを追加し、全3順位で学習する。E08のP3-only方式は流用しない。
既存の正式Contract・SourceManifest・solver・decoder・Gateを変更せず、入力適格性の確定後に独立した実験経路を実装する。

## 2. 限定して確認した証拠

既存DATA-AUDIT-01の `sample-manifest.jsonl`、`sample-entry-metadata.jsonl`、`sample-log-metadata.jsonl` を再利用。
既存の各年20標本から開催日・race IDで先頭/末尾、同レースでは取得時刻・log IDで最初のPJ0315を選んだ。
Rawを読む前に8レースを固定した。結果や特徴量の大小は選定に使っていない。標本80件の一般監査は再実行していない。

| 年 | race ID | 対象日 | log ID |
|---|---:|---|---:|
| 2022 | 89166 | 2022-01-03 | 288036 |
| 2022 | 113054 | 2022-12-19 | 335574 |
| 2023 | 63541 | 2023-01-01 | 173107 |
| 2023 | 88792 | 2023-12-28 | 223806 |
| 2024 | 38688 | 2024-01-14 | 105117 |
| 2024 | 60635 | 2024-11-21 | 149129 |
| 2025 | 12649 | 2025-01-01 | 39559 |
| 2025 | 37250 | 2025-12-24 | 89566 |

全件について元Raw SHA、監査logとの対応、PC0201の日付・場・raceNo、車番集合、登録番号、必要人数、現行RaceDetailParserのDTO値を照合。
Rawは2026年に取得されたBACKFILLだが、対象は2022-2025のレース。保存ディレクトリ年を対象年の判定には使用していない。
Parserの成功やauditの `VERIFIED` を入力の時間的適格性とは扱わない。

## 3. 項目別の判断

| 元キー | 実験feature ID | 現行DTO | 表示列 | 学習採否 |
|---|---|---|---|---|
| nigeCnt | TACTICAL_ESCAPE_COUNT | escapeCount | 逃 | 保留 |
| makuriCnt | TACTICAL_MAKURI_COUNT | sprintCount | 捲 | 保留 |
| sasiCnt | TACTICAL_SASHI_COUNT | overtakeCount | 差 | 保留 |
| markCnt | TACTICAL_MARK_COUNT | markCount | マ | 保留 |
| backCnt | TACTICAL_BACK_COUNT | backCount | B | 保留 |
| homeTori | TACTICAL_HOME_COUNT | homeCount | H | 保留 |
| stTori | TACTICAL_START_COUNT | startCount | S | 保留 |

コード上の対応は `RaceDetailParser::parse()` と `RaceDetailEntryDto` で確認した。
現行Parserは整数/数字文字列を0-9999へ解析し、NULL・空文字・「－」をNULLへ変換する。新しい学習入力の正当性をこの上限から推定しない。
今回の外部確認ではキー欠落、NULL、空、placeholder、観測0、観測非0を別状態で保持した。欠落を0にせず、登録番号不一致・重複車番・人数不一致・SHA不一致では停止する。

### 確認済み

- 8ファイルで `直近4ヶ月成績` のcolspan=11の見出し下に競走得点、決まり手4列、B/H/S3列、率3列がある。同じ静的見出し構造であり、単にページ内に複数の期間ラベルがあるという段階より対応を絞れた。
- 該当テーブルの `tbody#rbTableRightBody` は全8件で空。RawのJSON数値とブラウザ表示値の紐付けは外部 `/pc/static/js/FPJ0315.js` に依存する。
- `lastUpdateTime` は各対象日の07:00-07:05を表示している。
- `tyo4InfoSubData` は `kaisaiFirst` と1開催分の `resultInfoSubData` 等を持つ。検査した `kaisaiFirst` は当該レース日以前だった。
- `tyo4InfoSubData` は7回数の集計窓の開始日・終了日や母集団を表す仕様書ではない。子要素に結果が存在するだけで4ヶ月分を再集計できるとは扱わない。`konResultInfoSubData` を特徴量へ流用していない。

### 未確認で、使用を止めている事項

- 「直近4ヶ月」の起算・端点と、各7回数の集計基準日。対象日基準か開催初日基準か、当日分を含むか等を決められない。
- 過去レースをBACKFILL取得した時の7回数が、そのレース発走前の値として固定されること。対象レース自身とそれ以後の結果を含まないこと。
- ページ全体の `lastUpdateTime` と個々の集計回数の更新時点との関係。朝の表示時刻を各項目のcutoffや公式公開時刻へ読み替えない。
- 7列の厳密な集計対象・定義と年/テンプレートをまたぐ仕様の同一性。8標本で同じ見出しだったことを全件の証明にしない。

今回の回数生値実験に率の分母は要求しない。停止原因は率の分母が不明なことではなく、入力そのものの基準時点・集計内容の未確認である。
BACKFILLED_RACECARDと名付けるだけで現在プロフィール値を過去入力として許可しない。

## 4. 具体的な確認依頼

PJ0315の7回数について、集計期間の端点・基準日と、過去race指定時には当該race自身以後の結果を含まないことを確認できる公式説明、仕様資料、または当時の描画/データ生成仕様が必要。
特に `lastUpdateTime` がその7回数のas-ofを保証するか、`FPJ0315.js` の表示列とJSONキーの対応がどの仕様で保証されるかを確認したい。
定義を確認できる項目が一つでもあればその項目だけを適格に固定して継続できる。不明な残りを結果相関で選別しない。
必要資料がない限り、現時点で7候補すべてを保留とし、適格リストや学習用snapshotを確定しない。

## 5. 実行上の制約と記録

- 専用出力: `/tmp/neo-keirin-tactical-pilot-20260913-01/`。
- 最初の補助probeは既存監査exportに `player_id` がないことを考慮せずwarningを出した。原出力/当時のスクリプトを保持し、適格入力として利用しない。
- 修正版は `player_id=NOT_EXPORTED_IN_PRIOR_AUDIT_METADATA` と明示。DB上のNULLや未解決選手を意味しない。登録番号・race entry IDの照合と区別する。意味確認後の全件生成では必要なDB IDをREAD ONLYで取得する必要がある。
- 修正版の証拠は `probe-corrected/`。構造照合が成功しても意味・時点が未確認なので、train/compareを許可せず終了コード2。
- 8つの同じRawを補助probe訂正で再読したが、標本の差替え・80レース監査再実行・全Raw走査・新規HTTP取得はしていない。
- 本番DB接続0、新規fit0、予測生成0、bootstrap0、2026実データアクセス0。既存正式モデル・成果物・Raw・audit原本を変更していない。
- C1/C0の学習・接続テスト、未来入力変更テスト、正式比較、再現性実行は入力ゲート前で未実施。これらが成功したとは報告しない。
- 安全に実行できる既存E03/E06/E08の人工データUnit回帰と外部PHP構文検査は別に記録する。実データでのE08再評価ではない。

## 6. 精度比較の再開条件

適格項目と根拠、Raw選択規則、source、コード差分、bin/solver/decoder/評価規則をfit前にmanifestで固定してから、指示済みのC0/C1経路を実装・検証する。
追加情報の年別・項目別・cohort別有効率を全抽出行から計測し、欠損によってC1だけレースを除外しない。
再現性確認後も正式採用・2026利用・BT-04/BT-05開始は自動許可しない。
