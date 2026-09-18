# GROWTH-POINT-ANALYSIS-01

## 結果閲覧前契約 v1 / 2026-09-18

開始main: `056e339a8d7ef56aacb3537fe413ca6d72a8813b`、clean確認後に
`experiment/growth-point-analysis-01` を作成。C1や正式STATは変更しない探索診断。
対象はrun-01 Outer C1の2024年25,212・2025年24,866レースの全出走行。
開催grade・競走区分は既存開催分析の固定資料から再利用し、再推定しない。

## 正式式の調査

STAT-10/24、HistoricalRaceRepository、Batch02仕様に以下の正式式が存在する。
finish_strength_percentile = (entrant_count-rank)/(entrant_count-1)。
score_rank = 1 + 当該過去レースで本人より競走得点が高い人数（競争順位、同点は同順位）。
score_percentile = (entrant_count-score_rank)/(entrant_count-1)。
residual = finish_strength_percentile - score_percentile。高いほど期待以上。
全出走の当該race_entries.race_score > 0が揃い、宣言車立てと一致する場合のみ残差を算出する。
FINISHED/TIEDを正常完走とする既存定義を再利用。異常結果を順位へ変換しない。
STAT-44の仕様・クラスは今回のリポジトリ検索では特定できない。実装済みとは扱わない。
新しい残差式や正式STAT-44の代理実装を作らない。

## 時間・入力

履歴取得範囲は既存developmentと同じ2022-01-01～2025-12-31の男子レース。
各targetより厳密に前の実発走をrace_date/予定発走時刻で選ぶ。race ID大小を時間順に使用しない。
CONFIRMED/CORRECTEDかつFINISHED/TIED/DISQUALIFIED/CRASHED/DID_NOT_FINISHは実発走候補。
CANCELLED/DID_NOT_START/WITHDRAWNは除外。未確定・結果欠落・未知状態・時刻欠損は黙って飛ばさず、直前候補範囲にある場合PARTIAL_HISTORY。
同一選手・同一時刻の複数イベントは順序を捏造しない。targetの時刻がない場合もPARTIAL_HISTORY。
同一開催のprev1を保持し、same_meeting_previousを別属性にする。開催不明はUNKNOWN。
観測範囲の最初の行はNO_PREVIOUS_RACE、観測境界を示し、生涯初出走とは主張しない。
prev2不足でもAは計算できる。BはNO_SECOND_PREVIOUS_RACE。欠場/中止のNULL時刻は履歴を汚染しない。

A=target_score-prev1_score。race_entriesの歴史的値のみを使用し、現在プロフィールは取得しない。
得点の2桁小数は整数百分の一へ正規化して差を取り、rawを保存する。欠損は0にしない。
B=prev1_residual-prev2_residual。prev1/prev2が異常完走ならBは分析不能。より古い正常結果へのすり替えは禁止。
target結果をgrowth計算へ渡さず、growth計算後に保存済みtarget結果を結合する。
DB上のtarget結果を保存結果へ置換するために再取得するのではなく、歴史イベント集合としての結果を保存し、cohortの結果との一致も検証する。
historical scores/resultsはBACKFILLED_FINAL_RESULT / DEVELOPMENT_ONLY / publication_time_verified=UNKNOWN。
汎用fetched_atを得点の発走前公開根拠としない。予定時刻と確認済み結果による歴史イベント再構成であり、実発走秒・訂正公開時刻の保証ではない。

## 分位点・ポイント

2024用は2022-2023、2025用は2022-2024の利用可能rawのみ。対象選手集合に限定し、当該年の男子CONFIRMED/CORRECTEDレースの各出走を1標本とする。
target自身の正常/異常・順位でthreshold標本を選別しない。観測可能なscore/performance rawを別々に使用する。
Type7、P10/P25/P40/P60/P75/P90。重複点を丸めたりjitterで分離したりしない。
以下を上から順に評価し、最初に一致したpointを採用する（同値境界の重なりでも決定的）。
raw<=P10:-3、raw<=P25:-2、raw<=P40:-1、raw<P60:0、raw<P75:+1、raw<P90:+2、その他:+3。
したがってraw=0が必ずpoint=0になるとは限らない。空point区分と分位点重複を報告する。
A/B両方が利用可能な場合だけC=A_point+B_point、範囲-6～+6。重み最適化なし。

## 集計と事前固定の診断分類

正常FINISHED/TIEDだけを率・percentileの分母とする。1/2/3着、2/3着内は公式rankで数え、同着を単独勝者へ変換しない。
全出走行数、正常数、異常/非出走状態数、欠損signal数を別記。missing区分も母集団に残す。
平均/中央値finish percentile、win/top3参考Wilson区間（レース・選手・開催相関未補正）を保存。
Spearmanはraw/pointとfinish percentileの平均同順位rankのPearson相関、正常かつsignal利用可能標本のみ。定数分布はNULL。
年別、年×grade、年×競走区分、年×grade×競走区分、年×same_meeting_previousを出力。
診断分類はモデル採用Gateではない。p値、有意差検定、bootstrapは実施しない。

事前規則（A/B/C共通、Cのcontinuous値は存在せずpointの相関だけ）:
- 正常利用可能数<1000、選手<30、race<100、または正常数30以上のpointが3個未満: INSUFFICIENT_SAMPLE。
- |rho(raw)|と|rho(point)|がともに0.03未満、point別winの最大最小差<0.02かつtop3差<0.03: NO_CLEAR_RELATION。
- rhoがともに>=0.03、正常数30以上の観測pointを昇順に並べたwin/top3双方の厳密な単調増加違反0: POSITIVE_MONOTONIC_TENDENCY。
- rhoがともに<=-0.03、双方の厳密な単調減少違反0: NEGATIVE_MONOTONIC_TENDENCY。
- それ以外: NON_MONOTONIC。定数分布はNO_CLEAR_RELATION（標本不足判定を優先）。
空区分の隣接差はNULLとし、観測point間の差と区別する。結果を見て規則を変更しない。
C1補助診断は保存P1選択者/公式単独1着/取り逃した単独1着のpoint分布と誤りraceのactual-predicted point差。
高growthの未選択勝者が存在するだけで、C1へ追加すれば改善するとは主張しない。

## 保護・再現

専用namespace/commandのplan/execute/reproduce。planはDB・結果を読まない。
本番はREAD ONLYを接続前に確認。SQLを2022-2025と対象選手・必要な過去raceへ限定し、2026をquery前に拒否する。
本番へのDDL/writeなし。専用root内のSQLiteはディスク作業領域として使用し、PHP/SQLite cacheを128MB未満に制限する。
旧固定資料とDB snapshotのSTART/END照合、生成時seal、公開前検証を行う。再現は保存snapshotのみ、DB無効。
保存先は `/home/shinya/neo-keirin-artifacts/growth-point-analysis-01-20260918-01/`。旧成果物を変更しない。
全て人工テスト成功後に実分析する。commit/push/PR/mergeなし、未コミットのレビュー待ちで停止。

## 実行結果 / 事前契約は上記のまま維持

2024: 25,212レース・179,089出走、正常176,597、異常/非出走2,492。
2025: 24,866レース・177,120出走、正常174,836、異常/非出走2,284。
全356,209出走を保存し、選択された予測上位だけへ縮小していない。

| 年 | signal | 正常利用可能n | raw Spearman | point Spearman | 事前分類 |
|---|---|---:|---:|---:|---|
| 2024 | A SCORE | 176394 | 0.016586 | 0.012971 | NON_MONOTONIC |
| 2025 | A SCORE | 174641 | 0.016975 | 0.013309 | NON_MONOTONIC |
| 2024 | B PERFORMANCE | 168502 | -0.072251 | -0.069132 | NON_MONOTONIC |
| 2025 | B PERFORMANCE | 167262 | -0.070199 | -0.068077 | NON_MONOTONIC |
| 2024 | C COMPOSITE | 168502 | 該当なし | -0.050279 | NON_MONOTONIC |
| 2025 | C COMPOSITE | 167262 | 該当なし | -0.048289 | NON_MONOTONIC |

Aは非常に弱い正方向。占有point間の1着/3着内率は単調に増加するがrho基準0.03未満、
かつwin幅がNO_CLEAR_RELATIONの条件外なので、固定ルール上はNON_MONOTONIC。
この分類名だけでAの4占有区分に率の逆転があるとは主張しない。
Bは主に逆方向だが、3着内率の隣接逆転があり厳密な負単調ではない。Cも非単調。
2024/2025とも「高いgrowthなら必ず次走が良い」は支持できない。

### 分位点と同一開催

AのP10/P25/P40/P60/P75/P90は2024用[-0.29,0,0,0,0,0.28]、2025用[-0.28,0,0,0,0,0.27]。
標本数338,335/519,387。Bの標本数320,523/493,094、閾値はthresholds.jsonに未丸め保存。
raw=0は先勝ち境界で-2点、Aの-1/0/+1点は空。0点への再割当・境界の微調整をしない。
同一開催Aの118,820/117,729出走は全件raw=0、正常標本の相関は定数のためNULL。
Bの同一開催raw相関は-0.105379/-0.104639、他開催は-0.004291/+0.000543。
開催内の変動と長期成長は区別する。

### 開催grade・競走区分

| 区分 | A raw rho 2024/2025 | B raw rho 2024/2025 |
|---|---|---|
| F1全体 | 0.01556 / 0.01252 | -0.06929 / -0.07342 |
| F2全体 | 0.02188 / 0.02399 | -0.07141 / -0.06615 |
| F1 A1_A2 | 0.02074 / 0.01207 | -0.06813 / -0.07489 |
| F2 A1_A2 | 0.00875 / 0.02026 | -0.07886 / -0.07331 |
| F2 A_CHALLENGE | 0.04102 / 0.02907 | -0.05963 / -0.05652 |

F2全体のAの弱い正方向を、そのままF1に対する優位とはしない。
G1/G3のAはほぼ0、G2は年差があり、GPは各年1開催かつ正常約270出走で一般化しない。
全grade・UNKNOWN・競走区分・隣接差・違反数は保存表に収録。

### C1補助診断・欠損

Aで取り逃した単独勝者の平均pointは-1.32957/-1.35254、C1 P1候補は-1.27168/-1.28250。
高いAを常に取り逃しているという根拠にはならない。
Bで取り逃した勝者の平均pointは-0.12135/-0.11079、P1候補は-0.45175/-0.47478。
ただし全出走Bは逆方向相関であり、この条件付き分布からweight追加による改善を主張しない。
単独勝者・P1誤りraceの分布とactual-predicted差、欠損はc1-diagnostics.jsonに保存。
A欠損は206/202出走、B/C欠損は8,249/7,702。異常結果や未知時点を0補完していない。

### 整合性・再現・テスト

READ ONLY履歴101,317レース・717,661出走、digest
`f1534999a07d1365ffef043e47db8d168506fa8619f692a4aaeff7f386f2858c` がSTART/ENDで一致。
旧原本62ファイルと直接依存コードも不変。
DB接続を無効化したreproduceで、閾値・growth-input・growth-details・集計・相関・分類・C1診断が一致。
明細SHA-256 `101b6c9263fa5a1de1b11bc89ccd6b471305d80744bf52db6bb5f13c975784cc`。
summary SHA-256 `675b0857aad019efbd7b8f3c9c03877f50769d0b43a2199243be9ff56a62da52`。
PHP peakは実行48MiB、再現42MiB。全てmemory_limit=128M。

追加41 tests / 159 assertions、旧2分析を含む関連163 tests / 1,549 assertions成功。
安全な全体PHPUnitは1,403件中1,394成功・9skip / 10,731 assertions。
変更PHP10ファイルのPint・構文検査成功。全体Pintは既存未変更の
`tests/Unit/Domain/Keirin/Backtest/Bt03e08BoundedMemoryTest.php` のstatement_indentationだけ失敗。

### コマンド

```bash
php -d memory_limit=128M artisan keirin:backtest:growth-point-analysis --plan
PGOPTIONS='-c default_transaction_read_only=on -c statement_timeout=120000' \
php -d memory_limit=128M artisan keirin:backtest:growth-point-analysis --execute \
  --output-root=/home/shinya/neo-keirin-artifacts/growth-point-analysis-01-20260918-01 \
  --analysis-id=outer-c1-growth-2024-2025-01 \
  --source-bundle=/home/shinya/neo-keirin-artifacts/tactical-meeting-grade-analysis-01-20260918-01/evaluations/outer-c1-meeting-2024-2025-01
DB_CONNECTION=growth_disabled DB_URL= \
php -d memory_limit=128M artisan keirin:backtest:growth-point-analysis --reproduce \
  --output-root=/home/shinya/neo-keirin-artifacts/growth-point-analysis-01-20260918-01 \
  --analysis-id=outer-c1-growth-2024-2025-01
php -d memory_limit=128M vendor/bin/phpunit
php -d memory_limit=128M vendor/bin/pint --test
git diff --check
```

execute済みIDへの再実行は拒否。既存bundleの確認はreproduceを使用する。
成果物bundleは専用root/evaluations/outer-c1-growth-2024-2025-01。
報告ZIPは専用root/GROWTH-POINT-ANALYSIS-01-report.zip。
raw履歴と大容量原本をZIPへ重複収録せず、path/bytes/hash一覧と再集計用の小型派生CSVを含める。
推論・学習・正式STAT採用・Gate/bootstrap・2026参照・本番DB変更は行っていない。
