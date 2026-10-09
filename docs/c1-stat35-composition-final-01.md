# C1-STAT35-COMPOSITION-FINAL-01

## Scope

PR92はレビュー後MERGED。開始main/origin `a5ea469cc331b009cfe048338584e211a02ff5c2`、作業branch `feature/c1-stat35-composition-final-01`。
今回だけ新最終C2と固定最終C1の構成development候補を作る。C1最終再学習・旧OOF1/2再学習・旧Outer性能比較は0回。
既存目的関数/solver/grid/200更新上限/収束閾値/One-SE/PR92確率/E06/固定pipelineは変更しない。
PR92の+55/+26/+4/Hit3+81は旧比較の測定であり、今回の新性能改善ではない。

## Fixed Sources

指示されたA/B/C/D/Eのmanifest/sealと必要子ファイルを検証する。未知のhashを創作しない。
入力A: `stat35-c1-input-02/run-20260930-054458-8739da4b/result`、manifest `7f4356b93f90203dc727dc95d6215a8d8ce3f44869c4441c3981087ff1099c26`。
元C1 B: `tactical-history-01-review-fix-20260916-01`、export `4268b801b74b77cfb3a94b64832ff483e9eb5e3221ee8e75ceb467e5a5cf92e6`、contract `5db3b57b08fb8b91dbe6c057b459e518252c454b0a4ff60531c2d671b7ce506a`。
旧C2 C: `stat35-c1-compare-01/run-20260929-215427-3b1776ba/result`、manifest `8fc40000c93bbc604caac37cf21e60519f26f28c4e77cf9284fa31403f5487c8`。
PR92 D: `c1-stat35-p1-composition-01/run-20261008-fALiXmYk/result`、manifest `f1c1687aed2aa6cf9540c6f351e8017c240f096177e3206af775d936e3d92264`。
最終C1 E: `tactical-history-final-01-20260917-01/fit/run-01/final/artifact.json`、model `e3cc1f7f10af60bb22f97a2172ca52ef2c09a3393222ee46c25619de752d43a1`、lambda0.1。
上記は `/home/shinya/neo-keirin-artifacts/` 配下。旧モデル・資料・code hashは上書きせず当時の記録として残す。

## Training Boundary

- OOF1 Train2022/Validate2023、OOF2 Train22/23/Validate2024を保存lossから再構築し、元値・順序・適格性・training/layout/support/modelと照合する。
- 共通収束集合Kを保存記録から導く。OOF3は固定strong-to-weak順の先頭からmin(K)まで連続prefix、途中の強い非K候補もwarm startとして保持する。
- それより弱い候補は `INELIGIBLE_FROM_REUSED_FOLDS_NOT_FIT_OOF3`。固定8候補gridは維持し、未実行を新たな非収束と記録しない。
- OOF3は2022～2024だけをfit/bin/supportに使用し、候補seal後だけ2025教師をvalidationに開放する。
- 3fold共通収束集合、年/順位等重み、2000回seed20260812の既存One-SEで最終C2 lambdaを選択する。手動lambdaや失敗fallbackはない。
- 選択後に2022～2025全体でC2全3順位をfitする。構成にはC2 P1だけを使用する。
- 1 execute内の独立run-01/02は新モデル/cacheを共有しない。新path最大4本、実候補試行数を記録する。

## Package And Prediction

新形式 `C1-STAT35-COMPOSITION-FINAL-01-v1`、入力 `C1-STAT35-COMPOSITION-FEATURE-INPUT-v1`。
数値モデルは旧 `STAT35-C2-SEQUENTIAL-POSITION-v1` 形式を再利用し、今回の生成工程は別metadataに保存する。
公開packageは固定役割C2 P1/C1 P2,P3、別16/17項目layoutと係数次元、両親hash、選択、用途、コードsealを保持する。
C1 model/artifactはbyte不変で内包。全pathは検証済み相対path、symlink/traversal/未知版/役割違い/hash不一致を拒否する。
原sourceを参照しなくてもpackageと特徴量入力だけで予測できる。推論時bin学習、教師・旧予測・DB・HTTPアクセスは不要。
入力はAgariC1Inputの既存fieldにnullable numeric `stat35_mean6` だけを加える。余分結果field、重複ID、不正年/型/履歴状態/anchor、破損を公開前拒否する。
`stat01_rank` は入力順位であり正解rankではない。NULL/0・元16値・順序を維持し、予測年は2024/2025だけ。
公開前に旧Outer全50,078の数学出力、保存/読込、独立128Mプロセス、独立fit、source/code ENDと生成時sealを確認する。
新出力はinprogressディレクトリに保存し、全部成功後のrenameで公開する。失敗時の部分成果物・診断は残す。

```bash
php -d memory_limit=128M artisan keirin:c1:stat35-composition-final plan
php -d memory_limit=128M artisan keirin:c1:stat35-composition-final execute --output-dir="$RUN/result"
php -d memory_limit=128M artisan keirin:c1:stat35-composition-predict --artifact=... --input=... --output=...
```

## Execution Status

`FINAL_COMPOSITION_FIT_REPRODUCED_AWAITING_REVIEW`。人工検証後の実データexecute1回内で独立2runを完了し、全検査後に公開。
両runのOOF3/One-SE選択、最終C2全3順位、保存/読込、構成package、別PHP予測、実列挙37意味ファイルが厳密一致。
実行証跡: `/home/shinya/neo-keirin-artifacts/c1-stat35-composition-final-01/run-20261009-LE6Wit1O/`。
永続出力root: `/home/shinya/neo-keirin-artifacts/c1-stat35-composition-final-01/`。
公開package: `result/run-01/package/artifact.json`、41797 bytes、SHA-256 `b2741d0d3ca60070c311b658061e174eb8ba52f6ea14b33b755971b48e9513c6`。
全体manifest: `result/manifest.json`、315523 bytes、SHA-256 `ec525fd12b3b784af394032252c160bfcca4e7e833f8b565455c52ec2a2acad3`。COMPLETEと一致。
execute: 2026-10-09 06:26:58〜08:48:20 JST、8482.426790383秒、exit0、memory_limit128M、peak35651584 bytes（34MiB）。実行PHP `/usr/bin/php8.5`。
新規4path/8候補試行（各3順位）、旧OOF1/2の2fold再利用・最終C1 fit0。独立run間で新学習モデル/cacheを共有していない。
今回は `NOT_PERFORMED_FINAL_FIT_AND_TECHNICAL_REPRODUCTION_ONLY`。2025最終予測は学習内の技術検証であり、未知holdoutの評価ではない。
用途 `DEVELOPMENT_FINAL_MODEL_CANDIDATE_ONLY`、historical_as_of_available=false、formal_adoption/formal_freeze/live_use_authorized=false、points=null。
業務DB/レースHTTP/Raw/2026実データ参照/旧成果物変更/正式C1置換なし。未コミットでレビューを待ち、次工程へ進まない。

## Verification Results

- 通常全体テストは最終PHPコードで1回: 3,131 passed / 9 existing skips / 29,691 assertions / exit0、309.64091303秒。関連187テスト/904 assertionsも成功。15変更PHPの最終構文/限定Pintは全てexit0。
- 人工回帰は保存fold版/年/順序/loss、prefix一致、2025教師開放、選択非収束、役割別layout、未知入力/破損、移設/独立process、100MiB超入力を対象。既存テスト削除/緩和/新規skipなし。
- source117ファイル・直接依存code93ファイルのSTART/END不変、生成時seal/公開直前検査成功。全件99669レース/706051出走を保持。
- 旧Outerは2024の25212、2025の24866、合計50078レースで特徴量からの確率/Primary/Supportingが厳密一致。出力監査fieldと数学出力は区別し、性能再測定はなし。
- 旧C2 OOF1/2のtraining/layout/support/model/loss件数・順序・値・適格性を照合して再利用。元Aは親manifestのsealを使用し、存在しない個別sidecarを要求しない。
- 保存2fold共通Kはlambda0.1/1。OOF3順は1→0.1、弱い6候補は未実行理由を記録。両runの両候補全3順位が収束し、候補seal確認後だけ2025教師を開放。
- 両runの3fold One-SE選択は0.1で全選択記録が厳密一致。平均loss1.5990314812506738、SE0.0018430178086091442、上限1.600874499059283。lambda1の平均1.6186141762872868は上限外。
- 全4年の新C2 layoutは17項目/17 active groups/147 active coefficients/147 bins。旧C1の137係数をC2の期待値として使わない。両runの最終全3順位は更新112/113/89で収束。
- 新C2 modelは86399 bytes、SHA-256 `d1dbb706071a7dc25d4ea8fa0525ac685a8b68d6d09f3a43a980a70dc1334ea2`。旧C1原モデルと内包modelは固定SHA `e3cc1f7f10af60bb22f97a2172ca52ef2c09a3393222ee46c25619de752d43a1` 不変。
- 両別PHPの2025全24866レースは厳密一致、各exit0、memory_limit128M、peak27262976 bytes。別PID31596/47646、原sourceをOSのopen_basedirで制限したpackage/input/repository-only経路。
- 業務DB/HTTPを実コマンド内で拒否。許可されたローカルSQLite/spoolは使用する。人工テストのSQLite/fake HTTPと業務接続0を混同しない。

| 最終C2 | 更新数 | 適格/除外race | prox gradient mapping max | centering residual max |
|---|---:|---:|---:|---:|
| P1 | 112 | 99420 / 249 | 1.0937740921312944e-8 | 1.734723475976807e-17 |
| P2 | 113 | 99190 / 479 | 9.1651985861807e-9 | 4.30970363562988e-18 |
| P3 | 89 | 98925 / 744 | 7.326684708930387e-8 | 5.421010862427522e-18 |

## Successful Public Prediction

以下は実行済みコマンドの絶対pathをRUN表記で示す。既存出力へ再実行しない。

```bash
RUN=/home/shinya/neo-keirin-artifacts/c1-stat35-composition-final-01/run-20261009-LE6Wit1O
/usr/bin/php8.5 -d memory_limit=128M artisan keirin:c1:stat35-composition-predict \
  --artifact="$RUN/result/run-01/package/artifact.json" \
  --input="$RUN/result/verified-inputs/features-2025.jsonl" \
  --output="$RUN/public-predictions-2025.jsonl"
```

08:49:09〜08:49:17 JST、8.612024083秒、exit0、memory_limit128M、peak33554432 bytes（32MiB）。
24866レース/218137295 bytes、SHA-256 `cab188fad614e49c0d699fab38b71ea57ce3e902fc15d5aa2cfadd22cad23e23`。
専用入力72144103 bytes、SHA-256 `4bf8c8ccd61125e2562807154b4cffafcb6fcd2985521206e59cfc9df0fa6bfa`。
公開predictのsemantic sealは保存前/読込後/両独立PHPと完全一致。旧utilityや予測本文コピーは使っていない。
plan/execute/predict全stdout/stderr、開始終了/exit/runner hashは証跡の各 `log-*/execution.json` に保存。
小型review bundleは契約・選択・fit診断・package目録・再現・ログ・差分を含め、学習本文/全予測JSONLを重複添付しない。
未コミット結果レビュー待ち。正式置換/freeze/LIVE/2026/新性能評価/他工程は未実施・未承認。
