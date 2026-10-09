# C1-STAT35-COMPOSITION-FINAL-01

## Scope

以下の学習・旧実行実績はv1当時の記録。PR93の今回の許可は末尾の公開修正・固定成功runの再パッケージ・予測の技術再検証だけであり、実データ再学習は0回。

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
php -d memory_limit=128M artisan keirin:c1:stat35-composition-predict --artifact=... --input=... --output-dir=...
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

## PR93 Publication Review Fix

開始branch `feature/c1-stat35-composition-final-01`、HEAD/origin feature `69cf3ca0bccefa2df15fb4100904d2ac2dd9eec4`、clean。mainは変更しない。
P2-Aはroot完了前のpackage COMPLETEだけで公開loadできたこと、P2-Bは本体/manifest/COMPLETEの個別公開が失敗・競合時に部分出力を残したこと。
旧HEADのPackage/Predictionを別PHPで実行し、未完了/FAILED root/移設packageの受理と、manifest/COMPLETE失敗後の公開本体残存を人工ケースで再現した。

### Publication Contract

| 項目 | 契約 |
|---|---|
| 学習生成契約 / 入力 / 数値モデル | FINAL-01-v1 / FEATURE-INPUT-v1 / STAT35-C2-SEQUENTIAL-POSITION-v1を維持 |
| 今回の公開契約 | `C1-STAT35-COMPOSITION-PUBLICATION-v2` |
| PREPARED | 内容検証/内部roundtripのみ。stageの完了sealだけでは公開扱いにしない |
| COMMITTED | 所定destinationへ公開単位全体の上書き禁止renameが成功した状態 |
| FAILED | 失敗stageと診断を保持。通常load/predictで拒否 |

`Package::prepared()`は内部内容検査、`Package::load()`は公開root・所属・seal・完了状態を検証する別入口。
FITのpackageは`publication_owner=../..`で成功rootに結び付き、rootの未完了/FAILED/rename失敗やpackage単体移設は公開loadできない。
移設用REPACKAGEは固定旧成功rootだけを検証する専用経路で発行し、モデル・旧生成証跡・新公開証拠を内包する。任意旧artifact/APP_ENVによる旧検証skip/allow-unpublishedはない。
内容artifact → `publication.json`（artifact所属/ファイルseal/旧成功証拠）→ `COMPLETE.json`（publication seal）の順で、循環hashを作らない。
旧packageのgeneration_code/COMPLETE/modelを変更せず、新artifactでは旧generation_codeと今回のpublication_codeを分離する。

予測は`--output-dir=<新規directory>`で次の一式を公開する。旧`--output`および両option指定は移行案内付きで拒否する。

```text
predictions.jsonl
predictions.jsonl.manifest.json
COMPLETE.json
```

root/入力/packageとの重複を作成前に検査し、正規化destinationの永続lock fileを`flock`で排他する。
lock取得後も再確認し、同一filesystemの一意stageでEND検査/生成時seal/完了照合後、一度だけ上書き禁止renameする。
GNU mv 9.7の`--no-copy --update=none-fail --no-target-directory`を使用し、cross-filesystem copy fallbackはしない。
既存file/directory/symlink/dangling symlinkと競合相手の出力を保持する。lock fileを削除せず、プロセス中断でlockは解放される。
公開前失敗はstageにFAILEDを残し、正式出力を作らない。再試行は新stageで行う。公開後の補助例外は成功bundleをFAILED化/再作成せず、成功状態とpostcommit_warningを返す。

### Repackage And Technical Verification

今回状態は`REPACKAGED_WITHOUT_RETRAINING_AWAITING_REVIEW`。
証跡root: `/home/shinya/neo-keirin-artifacts/c1-stat35-composition-final-01/pr93-publication-fix-20261009-041410-cafd5508/`。
固定元は上記旧成功runのresultのみ。315523-byte root manifestの固定SHA/COMPLETE/status、旧37一致記録、両runのmodel/layout/selection、親seal、モデル診断、2025入力/旧予測seal、旧学習/公開コードと独立実行記録を検査した。
旧成果物で今回実参照・START/END照合したのは32ファイル。旧37意味ファイル全体の再学習/再現、source117の旧監査を再実行したという意味ではない。
旧直接code93の数値関連は現行と照合し、変更した公開7ファイルの旧版はreview HEADのGit blobで検証。今回runtime code95のENDは不変。
repackage CLIはExperiment/Trainer/Optimizer/Selector/教師を解決・実行せず、固定入力や証拠の欠損時にrefitへfallbackしない。

```bash
FIX=/home/shinya/neo-keirin-artifacts/c1-stat35-composition-final-01/pr93-publication-fix-20261009-041410-cafd5508
SOURCE=/home/shinya/neo-keirin-artifacts/c1-stat35-composition-final-01/run-20261009-LE6Wit1O/result
php -d memory_limit=128M artisan keirin:c1:stat35-composition-final repackage \
  --source-result="$SOURCE" --output-dir="$FIX/package"
php -d memory_limit=128M artisan keirin:c1:stat35-composition-predict \
  --artifact="$FIX/package/artifact.json" --input="$SOURCE/verified-inputs/features-2025.jsonl" \
  --output-dir="$FIX/predictions-2025"
```

実argv/stdout/stderr/開始終了/exit/runner SHAは`logs/*/`、移設コピーと独立照合は`review/moved-copy.json`、`review/verification.json`。
新package `package/artifact.json`: 118380 bytes、SHA `c50d4f5596d4d212f5b1f86111ebbe7a619e1c6b41ad0618207e604ea41a255f`。
公開証拠 `package/publication.json`: 54275 bytes、SHA `ba0043a4388aee4afa7df3592603485aa69b0feadf27b4dbd860424c1242a517`。
C1/C2 modelは上記固定SHAのまま、layout/selectionもbyte不変。新たなλ選択・OOF・最終fit・係数更新・decoder更新は0回。

| 今回実行（128M、各1回） | 秒 | peak bytes | exit |
|---|---:|---:|---:|
| 固定成功run再パッケージ | 0.606095076 | 35651584 | 0 |
| 通常公開CLIの2025全件予測 | 8.775000095 | 33554432 | 0 |
| 移設後の独立PHP通常公開CLI予測 | 8.873772144 | 33554432 | 0 |

移設bundleは`moved-check/package/`、入力もbyte不変コピーし、子PHPをrepository/moved-check/tmpだけのopen_basedirに制限した。元教師・旧予測・旧sourceは推論から参照できない。
通常公開load/CLIを省略していない。両新予測と旧成功予測は各24866行/218137295 bytes、SHA `cab188fad614e49c0d699fab38b71ea57ce3e902fc15d5aa2cfadd22cad23e23` で厳密一致。
比較は推論とは別のstreaming照合で行い、丸め/並べ替え/旧予測本文コピーはしていない。

新公開回帰33件/156 assertions、128M関連184件/815 assertions、100MiB超の独立128M入力予測を含め成功。
最終PHPの通常全体1回は3164 passed/29847 assertions/既存9 skipped、314.086462021秒、exit0。11変更PHPの構文/限定Pint/diff check成功。
入力拒否の既存18ケースは、旧file不在assertを正式directory不在assertへ強化して18/36成功。補正前全体1回（同件数、314.382980108秒）はlogs/fullに保持し、補正後の最終全体はlogs/full-finalとして区別する。実データ処理は再実行していない。
実書込み失敗、END drift、実rename競合、同一destinationの2子プロセス、公開直前/直後SIGKILL、失敗証拠保持と新stage再試行、旧証拠/モデル/選択/入力改変、学習器到達拒否を検証した。
旧3131テスト/37意味ファイル/4path・8試行/旧hashは当時の記録を維持し、今回値へ読み替えない。
性能は`NOT_PERFORMED_PUBLICATION_FIX_AND_TECHNICAL_REVALIDATION_ONLY`。公開修正成功は精度改善ではない。
用途/歴史的時点不明/未採用/未freeze/未LIVE/points=null/2026禁止は維持。業務DB・HTTP・Raw・Migration・正式pipeline置換・他工程は0回。
最終小型review bundleは証跡rootの`PR93-publication-review-final.zip`。先のZIP/checksも保持し、最終差分と最終全体結果はreview-final/checks.jsonで識別する。
