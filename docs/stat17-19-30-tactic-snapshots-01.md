# STAT-17/19/30 PJ0315 tactic snapshots MVP

## 状態と範囲

2026-10-22、`CODE_AND_LIMITED_REAL_RAW_VERIFIED_AWAITING_REVIEW`。
PR117 MERGED、fetch/pull --ff-only後のclean main/originは `0b07b29ed7c5666e56aa1b69b9ff1e22813c36ad`。
新branch `feature/stat17-19-30-tactic-snapshots-01`、main編集/commit/push/PR作成/mergeなし。
今回は保存基盤とSTAT-17表示回数分布だけ。STAT-19/30 Calculator、役割自在性・成功度・転換判定は未実装。

要件原本STAT-17/19/30とSCR-STAT-17/19/30各01〜03を確認した。残タスク9件の完了条件全体は満たさずOPENを維持する。
旧PJ0315 pilotのBLOCKED_INPUT_SEMANTICS、120日公式1/2着決まり手を使うC1、旧STAT-17多様性比較NOT_ADOPTEDは変更しない。
今回の表示回数をC1の4履歴値と同じ入力定義にせず、モデルへ接続しない。

## 保存・検証契約

Migration `2026_10_22_000015_create_race_tactic_observations.php` が専用の追記型 `race_tactic_observations` を追加する。
race/entry/player（NULL可）/fetch-logのRESTRICT FK、fetch-log＋entry＋観測版のUNIQUE、race/player×取得日時INDEXを持つ。
値域・2022〜2025・用途制限CHECKとUPDATE/DELETE拒否トリガーをPostgreSQL/隔離SQLiteに定義する。データがあるdownも拒否する。
今回の適用・保存は隔離SQLiteだけで、本番PostgreSQLには接続していない。

`PJ0315-TACTIC-BHS-OBSERVATION-v1` は以下を保存する。

| Raw | DB | 意味の限界 |
| --- | --- | --- |
| nigeCnt / makuriCnt / sasiCnt / markCnt | escape_count / sprint_count / overtake_count / mark_count | 表示4回数。試行回数・生涯累計・出走当たり率と断定しない |
| backCnt / homeTori / stTori | back_count / home_count / start_count | 表示B/H/S回数。対象レース単位の行動実績ではない |

既存RaceDetailParser/DTOの整数変換（0〜9999という技術的範囲）を再利用する。
Raw原値・型・存在・source pointerと、MISSING/NULL/EMPTY_STRING/PLACEHOLDER/OBSERVED_ZERO/OBSERVED_POSITIVEを保持する。
欠損を0へ補完せず、不正型/範囲外は拒否する。登録番号は6桁の文字列で先頭0を維持する。
日付/場/番号/source検証はSTAT-38の`RaceDistanceObservationService::prepare()`を再利用し、距離観測の保存処理は呼ばない。
Rawのサイズ/原SHA/変換SHA検証は既存RawReader、PC0201とPJ0315の車番/登録番号は既存Parserを使用する。
DB出走者集合・車番・登録番号・出走人数も照合する。内部player IDはNULLでも一意に照合できれば保存し、補完しない。

原Rawは参照し、DBへ本文を複製しない。race/entry/車番/登録番号、取得/Raw保存日時、Raw path/サイズ/SHA、
取得元URL、source metadata hash、元Parser/観測版、同Raw予定発走時刻、作成日時を追跡可能にする。
同じ取得版の再処理は追加0、別取得版は別行。同じキーの内容変更は拒否し、過去行を上書きしない。
取込み後のRaw/metadataを再検証し、失敗時はレース単位transactionをrollbackする。

## STAT-17限定計算

`STAT17-PJ0315-DISPLAYED-COUNT-PROFILE-v1`。明示したrace/fetch-logの保存観測だけを再検証して使用する。
自動的な最新版選択や別取得版からの欠損補完は行わない。
4回数が全て非NULLで合計>0の場合だけ、各回数/合計の構成比と観測戦法数を返す。
合計0はtotal=0/観測戦法数0/構成比NULL（ZERO_TOTAL_UNDEFINED）。一部NULLはtotal/構成比/戦法数をNULLにし、欠損fieldを列挙する。
既存EnumのPARTIALまたはMISSING_INPUT、quality=DEGRADEDで既存StatisticFeatureResultへ保存する。

全数値はRETROSPECTIVE配下の限定プロフィール。aggregation_period_status/aggregation_basis_status/official_publication_statusはUNKNOWN、
official_publication_at=NULL、historical_as_of_available=false、prediction_use=NOT_AUTHORIZED、points/confidence=NULL。
取得/保存がinput_as_of前かどうかは監査flagで、trueでも集計基準・当該レース結果除外の証明にはならず予測利用を承認しない。
4ヶ月の正確な端点/基準日・分母・訂正時点は未確認。差分・多様性指数・加点減点・成功率は計算しない。

## コマンド

```bash
php artisan keirin:tactics:import-observations --race-id=ID --fetch-log-id=LOG
php artisan keirin:statistics:build-stat17-profile --race-id=ID --fetch-log-id=LOG --input-as-of=ISO_WITH_OFFSET
```

既定はplan（DB/Raw本文アクセス・書込みなし）。明示executeだけ保存、execute＋dry-runは観測・統計run/resultを含め書込み0。
buildは保存済み取得版を要求し、未保存なら停止する。統計公開前にも同一入力を再検証する。
Migration/HTTP/通常同期をCommandから起動しない。上記はコードの操作仕様であり本番実行許可ではない。

## 実Rawによる限定受入

STAT-38の封印済みactual/history fixtureとRawコピーを再利用し、DB再取得/新規HTTP/全Raw走査は行わない。
manifestに相当するfixture SHAは `7ec6fae7e6ff5f8137754986c8f9e8d10bd766b9c65f61aa1252944494ba04dc` と
`0344d20e9db4918a6a1a8cc0f691cdea724c22fc1b90c9ce2520ce12a02379ca`。
対象は2022年1/2023年1/2024年3/2025年1レース、計6レース/39出走/8取得版/延べ51観測。
全7値・原値・本人・版・source sealを照合し、隔離DB保存と再読込み・計算を確認した。本番保存件数ではない。

実例: 2025-01-01 race12542/1番、7値は4/5/1/0/13/11/4。
4戦法合計10、構成比0.4/0.5/0.1/0、戦法数3。2024-01-01 race37815/1番は実Rawで全7値0、構成比NULLを確認した。
全取得版は2026年に事後取得された過去年の資料。2026開催のholdoutではなく、当時の予測利用を証明した資料でもない。

## テスト・証跡

PHP8.5.4 / PHPUnit12.5.31、既存bwrap隔離を再利用し、ネットワーク遮断、本物.env/storage/旧成果物read-only保護、
SQLite :memory:だけを使用。作成時に保持した既存helperのpath()だけをretireし、人工Desktop/Trashへrename-only退避した。

| 最終128MiB実行 | tests | assertions | failure/error/warnings/skip | exit |
| --- | --- | --- | --- | --- |
| 新規Feature14＋Unit3 | 17 | 2286 | 全て0 | 0 |
| STAT-38全33＋既存STAT/文字コード96 | 129 | 1494 | 全て0 | 0 |

新規のPHP peakは59,244,544bytes（56.5MiB）。親Artisanと子PHPUnitへ128Mを指定し、既存独立bootstrapで実設定/子PID/peakを保存した。
NULL/空/0、Raw/識別不一致、再処理/再取得、公開前改変rollback、dry-run書込み0、2026人工拒否を検証した。
全体および旧StartObservation/StartCountテストは永久削除cleanup（deleteDirectory/unlink）が残るため未実行。
既存テストの削除・緩和・新規skipはなく、今回その旧処理を一括修正していない。

永続root: `/home/shinya/neo-keirin-artifacts/stat17-19-30-tactic-snapshots-01/`。
新規最終ログ `tests-20261022-084104-Obcg5KPG/logs/`、関連最終ログ `tests-20261022-083908-qFz13iAx/logs/`。
実コマンド/runner/stdout/stderr/JUnit/イベント/開始終了/exit/measurement/real-raw-acceptance.jsonを保持する。
初期のテストメソッド名衝突と日付投影不一致の失敗ログも保持した。PHP14件の構文/限定Pint/diff検査を確認する。

## 未実装・次の作業

STAT-17の戦法有効性/役割自在性、STAT-19の直近変化、STAT-30の戦法転換・訂正原因判定、正確な集計窓の実証は未完成。
PostgreSQLでのMigration/制約実適用、本番取込み/正式統計保存は未実施・未承認。private fixture供給がない独立CI受入も未確認。
旧STAT-36/38/C1/C2/BT/solver/モデル/Raw/成果物は変更していない。学習・lambda選択・性能評価・2026実データ参照0。
次は未コミット差分のコードレビューのみ。正式採用・本番適用・次工程へ自動進行しない。
