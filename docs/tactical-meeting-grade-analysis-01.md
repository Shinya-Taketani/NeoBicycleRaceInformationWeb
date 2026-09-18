# TACTICAL-MEETING-GRADE-ANALYSIS-01

## 集計前契約 v1 (2026-09-18)

ユーザー指定により主軸を予測選手の級班から開催グレードへ変更する。
旧TACTICAL-GRADE-ANALYSIS-01は参考資料として保護し、意味・実装・成果物を変更しない。
対象は修正版run-01のOuter C1、2024年25,212・2025年24,866レースのみ。
旧LOCKED束のmanifest SHA-256は `f2c796b179d195e2b1f7e66dcc834619942889abce11a8f982ca903aa5a4e77c`。
そこに記録されたanalysis-inputと原本contributionsをストリーム照合し、旧予測decisionを再利用する。
原本の巨大な履歴・予測の再結合、出走級班の再取得、推論・学習は行わない。

## 出典と分類

- `races.race_day_id -> race_days.race_meeting_id -> race_meetings.id` が同一開催の識別根拠。
- `race_meetings.grade`: RaceScheduleParserが月間日程の最初のgradeIconSizeを採用し、RaceRepository::upsertScheduleItemが保存。現実装にGP分岐はない。
- `races.grade`: RaceDayMetadataParserの `C0201data.imgGradeAlt` をRaceRepository::syncRaceDayが保存。同一JSJ001内の全レースへ同じ開催ヘッダーgradeを渡す。個別競走の格付けや選手級班ではない。
- 主分類は同一開催内で共通。開催gradeと全対象raceのヘッダーgradeが一致すれば採用。
- 開催gradeがNULL/空白の場合のみ、同一開催IDの全対象raceで認識可能なヘッダーgradeが一致すれば補完する。多数決はしない。
- 認識不能、矛盾、複数grade混在、開催識別欠落はUNKNOWNとして全母集団に残す。開催gradeが明示的な既知値でヘッダーが全NULLの場合は開催側のみと明示する。
- 正規化: 空白除去・全角英数字の半角化後、GP/G1/G2/G3/F1/F2、GⅠ/GⅡ/GⅢ/FⅠ/FⅡ、GI/GII/GIII/FI/FIIだけを明示対応。原文は保持する。
- GP分類は「GP開催内の対象レース」。併催のS級一般等も含む。「単発GP競走だけ」とは表示しない。
- 予備属性調査: 50,016レースで双方一致、62レースが開催NULL/ヘッダーGP、対象1,796開催内のgrade混在なし。結果指標を見る前に本規則を固定した。

開催日・会場・日付範囲・車立てを固定対象と照合する。READ ONLY接続を確認してから、SQLのID集合と2024/2025日付で限定する。
DBから結果・順位・出走級班・選手プロフィールは取得しない。属性START/ENDを比較し、以後は保存snapshotだけで再現する。
コード上の保存経路を出典として確認した分析であり、過去公開時刻や全Rawアイコンの再監査を意味しない。Parser/DBは修正しない。

## 指標・補助分類

主表は年×開催grade、補助表は年×開催grade×車立て/競走区分/段階。各表は1race1件。
開催数はDB開催IDのdistinct。UNKNOWNも含む。2年合算は分子合計/分母合計の「件数加重合算」。
競走区分は保存race_typeの明示表記からS級戦、A級チのチャレンジ戦、通常のA級予選・準決勝・一般等をA1/A2戦とする。
特殊なアマチュア等の略号は推測せずUNKNOWN。段階は準決勝/準決、決勝、予選/予選1/2、一般、特選、選抜の末尾だけを認識。
判別不能な略号・賞競走はUNKNOWN。詳細な対応表は版管理対象Classification.phpに固定する。

保存Primary decisionを既存Bt03e05MetricEvaluatorへ渡し、原本の各raceの分子・分母と厳密一致を要求する。
P1/P2/P3はそれぞれの公式順位が一意なraceのみ。Hit@3は公式1/2/3がすべて一意なraceだけの位置一致数/(3×適格race数)。
各位置の率の平均、異なる適格集合の分子合計、全3人一致率ではない。除外は同着/順位欠落の位置別理由を保存。
年別分母: 2024=25158/25106/25094/75120、2025=24789/24727/24739/73989。
分子は未丸めの保存寄与で照合し、率から逆算しない。
P1/P2/P3の参考95%CIは旧Wilson関数を再利用。開催・選手の相関は未補正。Hit@3のCIは未計算。
少数開催区分を一般化せず、年・車立て・競走区分による構成差を併記する。新bootstrap/有意差検定/採用Gateは行わない。

## 実行・保存

専用namespace/contract/commandのplan・execute・reproduce。PHPとPHPUnitは128M、JSONLはstreaming。
既存read-only session、固定束検証、生成時seal、公開前検証、Wilson/CSV writerを再利用する。
原本hashと直接依存コードをSTART/END照合し、書き込み途中失敗はstageへ残し公開しない。
保存先: `/home/shinya/neo-keirin-artifacts/tactical-meeting-grade-analysis-01-20260918-01/`。
旧成果物は上書きしない。2026、LIVE、重み変更、Migration、本番書込み、新規取得は禁止。
再現はDB無効で実施し、分類・明細・集計JSON/CSVのhash一致を確認する。
完了後は未コミットのレビュー待ち。新しい実装工程へ進まない。

## 実行結果

全50,078レース、開催ID distinct 1,796。年境界の開催は合算時に重複排除する。
開催側とヘッダー一致50,016、補完62、UNKNOWN/矛盾/混在0。補完内訳は2024静岡（開催ID2211、12/28-30）、
2025平塚（ID1313、12/28-30）の各31レース。GP競走自体は各1レースであり、以下のGP行は併催を含む。

| 年 | 開催grade | 開催数 | レース数 | 1着率 | 2着率 | 3着率 | Hit@3 |
|---|---|---:|---:|---:|---:|---:|---:|
| 2024 | GP | 1 | 31 | 38.71% | 25.81% | 9.68% | 24.73% |
| 2024 | G1 | 6 | 333 | 31.63% | 15.41% | 12.46% | 20.02% |
| 2024 | G2 | 3 | 121 | 19.83% | 11.67% | 11.67% | 14.44% |
| 2024 | G3 | 43 | 1967 | 37.81% | 21.58% | 15.84% | 25.08% |
| 2024 | F1 | 276 | 9163 | 38.45% | 22.44% | 18.66% | 26.52% |
| 2024 | F2 | 567 | 13597 | 44.41% | 25.83% | 19.44% | 29.89% |
| 2025 | GP | 1 | 31 | 19.35% | 19.35% | 3.23% | 13.98% |
| 2025 | G1 | 6 | 343 | 31.78% | 16.33% | 12.87% | 20.27% |
| 2025 | G2 | 3 | 137 | 34.07% | 20.00% | 15.33% | 22.96% |
| 2025 | G3 | 46 | 2018 | 35.99% | 20.46% | 14.88% | 23.77% |
| 2025 | F1 | 274 | 9109 | 37.63% | 22.10% | 19.39% | 26.41% |
| 2025 | F2 | 578 | 13228 | 42.34% | 24.64% | 19.41% | 28.81% |

UNKNOWNは両年0/0、評価不能であって0%ではない。全分子/分母、除外理由、Wilson区間、件数加重合算・補助表はsummary.json/csvと報告ZIPに収録する。
原本寄与との照合合計（P1/P2/P3/H3）:
2024 `10424/25158, 6041/25106, 4701/25094, 21091/75120`、
2025 `9886/24789, 5743/24727, 4677/24739, 20241/73989`。
P1/P2/P3の別々の適格母集団を足した値をHit@3へ転用していない。

7車でもF2の1着/2着/Hit@3はF1より高め（Hit@3: 2024 29.90%対26.37%、2025 28.80%対26.35%）。
ただしA1/A2戦に限定したHit@3は2024 F2 28.06%対F1 28.09%、2025 27.34%対27.00%。
同じA1/A2戦の1着率は2024 F1 41.82%対F2 40.01%、2025 39.88%対38.88%へ逆転する。
F2全体の高さにはAチャレンジ戦の構成が影響する。3着率も全区分一律の優位ではない。
9車のG3/G1のHit@3は2024 24.57%/20.04%、2025 23.73%/20.32%だが、構成調整や有意差検定は行っていない。
GP各年1開催、G2各年3開催は少数で、年度差も大きく一般化しない。
競走区分UNKNOWNは2024 14・2025 94レースで、主開催分類から除外していない。

実行/再現は128M指定、双方ピーク38.5MiB。READ ONLY属性START/ENDのSHA-256は
`949e86a4fdd5c509ce890289de96633b48d5c4c589acc184f305e55cdd161b3c`。
DBなし再現で分類・details/summary JSON/CSVが一致。31原本と旧固定束・直接依存コードも不変。
明細hash `549b81b008e5b4d9e75f3eb6d1596186db599d1a406b87a4a4e9932f554ae01a`、
summary hash `c2511641caf70db38fa4fc11b12fabf998e793a46ec3e2e4a7f37b740e55a929`。

人工追加56テスト216 assertions、関連246テスト2744 assertions。
全体1362件中1353 passed、9 PostgreSQL限定skip、10572 assertions。直接PHPUnitとartisan testを実施。
変更PHPのPintは成功。全体Pintは従来の `Bt03e08BoundedMemoryTest.php` のstatement_indentationのみ失敗し、範囲外なので変更していない。

```bash
php -d memory_limit=128M artisan keirin:backtest:tactical-meeting-grade-analysis --plan
PGOPTIONS='-c default_transaction_read_only=on -c statement_timeout=120000' \
php -d memory_limit=128M artisan keirin:backtest:tactical-meeting-grade-analysis --execute \
  --source-bundle=/home/shinya/neo-keirin-artifacts/tactical-grade-analysis-01-20260918-01/evaluations/outer-c1-2024-2025-01 \
  --output-root=/home/shinya/neo-keirin-artifacts/tactical-meeting-grade-analysis-01-20260918-01 \
  --analysis-id=outer-c1-meeting-2024-2025-01
DB_CONNECTION=disabled-meeting-offline DB_URL='' \
php -d memory_limit=128M artisan keirin:backtest:tactical-meeting-grade-analysis --reproduce \
  --output-root=/home/shinya/neo-keirin-artifacts/tactical-meeting-grade-analysis-01-20260918-01 \
  --analysis-id=outer-c1-meeting-2024-2025-01
```

公開済みIDへexecuteを重ねず、確認にはreproduceを使用する。
