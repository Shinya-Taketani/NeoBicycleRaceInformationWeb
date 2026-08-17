<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\DTO\Bt03SourceArtifactFingerprintsDto;
use App\Domain\Keirin\Backtest\Support\Bt02ModelArtifactHasher;

class Bt03SourceManifest
{
    public const VERSION = 'BT03-BT02-RUN5-SOURCE-MANIFEST-v1';

    public const FIXED_BIN_CONTRACT = 'BT03-FIXED-BT02-TRAINING-BINS-v1';

    public const SOURCE_BT02_RUN_ID = 5;

    public const SOURCE_BT02_RUN_UUID = '8e81ae0d-8018-4d99-b31d-203d8076e6cb';

    public const SOURCE_BT02_MANIFEST_HASH = '92aa8439775101c4f9d190d829b8a0f3e3702fd8646101b66a42b68babb79e6d';

    public const OUTCOME_SNAPSHOT_MANIFEST_HASH = 'a4b1800095b22fe0ae40216ce90243c7e80a0cf652a96e328c45223160c3dad9';

    public const OBJECTIVE_VERSION = 'RIDGE-LOGISTIC-MEAN-LOSS-NEUMAIER-v2';

    public const OPTIMIZER_VERSION = 'DAMPED-NEWTON-CHOLESKY-v3';

    public const PROBABILITY_SEMANTICS = 'ENTRY_BINARY_NOT_RACE_NORMALIZED';

    public const RUN_AND_FOLD_FINGERPRINT = 'aa26d72c206b9d70401e4649c401d390818cbd5d292d08881d047908270f02f7';

    public const SIGNAL_SPEC_FINGERPRINT = 'd9a0c4363ba3f370ff7925be525d6fd8b6cc6cc41ed6010c4c6f279f6fe7f359';

    public const MODEL_FINGERPRINT = '26d831a05a668d95613a90e56e9c465b3126fda7be4d2b96157253f8882d4cd1';

    public const METRIC_FINGERPRINT = 'e483ab582cdad2b2996f65b86bcb50e68c9a22ade2cf683feeadcb1cf9acfb02';

    public const EFFECT_BIN_FINGERPRINT = '8d9030775176c59d5a13cc5c67b7f080fb3c3bd7cddca071c131927d9f2fef7c';

    public const ARTIFACT_MANIFEST_HASH = '5178fd7207cb9d043fdc1c7b6808d3f3a59a565f18298dc2b89c1353d11cb1fa';

    public const HASH = 'f114e079768748cf0bf84746471bb7e84ea304e5fcb61db83d59b79940e45d98';

    /** @var list<string> */
    public const ENTRY_STAT_CODES = [
        'STAT-07', 'STAT-08', 'STAT-10', 'STAT-11', 'STAT-12', 'STAT-23',
        'STAT-24', 'STAT-26', 'STAT-31', 'STAT-32', 'STAT-39', 'STAT-42',
    ];

    public function __construct(private readonly Bt02ModelArtifactHasher $hasher) {}

    public function expectedFingerprints(): Bt03SourceArtifactFingerprintsDto
    {
        return new Bt03SourceArtifactFingerprintsDto(
            self::RUN_AND_FOLD_FINGERPRINT,
            self::SIGNAL_SPEC_FINGERPRINT,
            self::MODEL_FINGERPRINT,
            self::METRIC_FINGERPRINT,
            self::EFFECT_BIN_FINGERPRINT,
            self::ARTIFACT_MANIFEST_HASH,
        );
    }

    public function computedHash(): string
    {
        return $this->hasher->hash([
            'version' => self::VERSION,
            'fixed_bin_contract' => self::FIXED_BIN_CONTRACT,
            'source_run_id' => self::SOURCE_BT02_RUN_ID,
            'source_run_uuid' => self::SOURCE_BT02_RUN_UUID,
            'source_bt02_manifest_hash' => self::SOURCE_BT02_MANIFEST_HASH,
            'outcome_snapshot_manifest_hash' => self::OUTCOME_SNAPSHOT_MANIFEST_HASH,
            'objective_version' => self::OBJECTIVE_VERSION,
            'optimizer_version' => self::OPTIMIZER_VERSION,
            'probability_semantics' => self::PROBABILITY_SEMANTICS,
            'folds' => ['WF_2023', 'WF_2024', 'WF_2025'],
            'entry_stat_codes' => self::ENTRY_STAT_CODES,
            'counts' => ['folds' => 3, 'signal_specs' => 14, 'models' => 432, 'metrics' => 648, 'effect_bins' => 668],
            'artifact_fingerprint_version' => Bt03SourceArtifactFingerprinter::VERSION,
            'artifact_fingerprints' => $this->expectedFingerprints()->canonical(),
            'artifact_manifest_hash' => self::ARTIFACT_MANIFEST_HASH,
        ]);
    }
}
