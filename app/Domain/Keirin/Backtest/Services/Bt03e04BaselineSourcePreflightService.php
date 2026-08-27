<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Contracts\Bt02FingerprintRunner;
use App\Domain\Keirin\Backtest\Enums\Bt02FingerprintType;
use App\Domain\Keirin\Backtest\Repositories\BacktestFeatureRepository;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use RuntimeException;

final class Bt03e04BaselineSourcePreflightService
{
    public function __construct(
        private readonly Bt01SourceManifest $manifest,
        private readonly Bt02BaselineFingerprintManifest $fingerprintManifest,
        private readonly BacktestFeatureRepository $features,
        private readonly Bt02FingerprintRunner $fingerprints,
        private readonly CanonicalHasher $hasher,
        private readonly Bt03e04ReadOnlyQueryAudit $audit,
    ) {}

    /** @return array<string,mixed> */
    public function run(): array
    {
        if ($this->fingerprintManifest->computedHash() !== Bt02BaselineFingerprintManifest::HASH) {
            throw new RuntimeException('BT-03E-04 fixed baseline fingerprint manifest was invalid.');
        }
        $sources = array_map(fn (int $year) => $this->manifest->forYear($year), Bt03e04Contract::DEVELOPMENT_YEARS);
        foreach (Bt03e04Contract::DEVELOPMENT_YEARS as $year) {
            $this->audit->recordBaselineYear($year);
        }
        $this->features->validateSources($sources);
        $this->fingerprints->assertVersionContract();
        $records = [];
        foreach (Bt03e04Contract::DEVELOPMENT_YEARS as $year) {
            $expected = $this->fingerprintManifest->forYear($year);
            $this->audit->recordBaselineYear($year);
            $source = $this->fingerprints->fingerprint($expected->featureRunId, Bt02FingerprintType::Source);
            $this->audit->recordBaselineYear($year);
            $content = $this->fingerprints->fingerprint($expected->featureRunId, Bt02FingerprintType::Content);
            if (! hash_equals($expected->sourceFingerprintSha256, $source)
                || ! hash_equals($expected->contentFingerprintSha256, $content)) {
                throw new RuntimeException("BT-03E-04 STAT-01 source fingerprint drifted for {$year}.");
            }
            $records[] = [
                'year' => $year,
                'stat_code' => Bt01SourceManifest::STAT_CODE,
                'feature_run_id' => $expected->featureRunId,
                'source_fingerprint_sha256' => $source,
                'content_fingerprint_sha256' => $content,
            ];
        }

        return [
            'baseline_manifest_hash' => $this->manifest->hash(),
            'baseline_fingerprint_manifest_hash' => $this->fingerprintManifest->computedHash(),
            'verified_baseline_runs' => count($sources),
            'fingerprints' => $records,
            'fingerprint_digest' => $this->hasher->hash($records),
        ];
    }
}
