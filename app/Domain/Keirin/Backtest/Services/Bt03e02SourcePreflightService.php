<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Contracts\Bt02FingerprintRunner;
use App\Domain\Keirin\Backtest\Enums\Bt02FingerprintType;
use App\Domain\Keirin\Backtest\Repositories\BacktestFeatureRepository;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class Bt03e02SourcePreflightService
{
    public function __construct(
        private readonly Bt01SourceManifest $baselineManifest,
        private readonly Bt02BaselineFingerprintManifest $baselineFingerprints,
        private readonly Bt02SourceManifest $signalManifest,
        private readonly BacktestFeatureRepository $baselineFeatures,
        private readonly Bt02FingerprintRunner $fingerprints,
        private readonly CanonicalHasher $hasher,
        private readonly Bt03e02ReadOnlyQueryAudit $queryAudit,
    ) {}

    /** @return array<string,mixed> */
    public function run(): array
    {
        if ($this->signalManifest->computedHash() !== Bt02SourceManifest::HASH
            || $this->baselineFingerprints->computedHash() !== Bt02BaselineFingerprintManifest::HASH) {
            throw new RuntimeException('BT-03E-02 fixed source manifests were invalid.');
        }
        $baselineSources = array_map(fn (int $year) => $this->baselineManifest->forYear($year), Bt03e02Contract::DEVELOPMENT_YEARS);
        foreach (Bt03e02Contract::DEVELOPMENT_YEARS as $year) {
            $this->queryAudit->recordFeatureSourceYear($year);
        }
        $this->baselineFeatures->validateSources($baselineSources);
        $signalSources = [];
        foreach (Bt03e02Contract::DEVELOPMENT_YEARS as $year) {
            foreach (Bt03e02Contract::STAT_CODES as $statCode) {
                $signalSources[] = $this->signalManifest->for($year, $statCode);
                $this->queryAudit->recordFeatureSourceYear($year);
            }
        }
        $this->assertSignalMetadata($signalSources);
        $this->fingerprints->assertVersionContract();
        $records = [];
        foreach (Bt03e02Contract::DEVELOPMENT_YEARS as $year) {
            $source = $this->baselineFingerprints->forYear($year);
            $records[] = $this->fingerprintRecord($year, 'STAT-01', $source->featureRunId, $source->sourceFingerprintSha256, $source->contentFingerprintSha256);
        }
        foreach ($signalSources as $source) {
            $records[] = $this->fingerprintRecord($source->year, $source->statCode, $source->featureRunId, $source->sourceFingerprintSha256, $source->contentFingerprintSha256);
        }

        return [
            'verified_baseline_runs' => count($baselineSources),
            'verified_signal_runs' => count($signalSources),
            'source_fingerprint_count' => count($records),
            'content_fingerprint_count' => count($records),
            'fingerprints' => $records,
            'fingerprint_digest' => $this->hasher->hash($records),
            'verification_digest' => $this->hasher->hash([
                'baseline_manifest' => $this->baselineManifest->hash(),
                'signal_manifest' => $this->signalManifest->hash(),
                'records' => $records,
            ]),
        ];
    }

    /** @return array<string,int|string> */
    private function fingerprintRecord(int $year, string $statCode, int $runId, string $sourceExpected, string $contentExpected): array
    {
        $this->queryAudit->recordFeatureSourceYear($year);
        $source = $this->fingerprints->fingerprint($runId, Bt02FingerprintType::Source);
        $this->queryAudit->recordFeatureSourceYear($year);
        $content = $this->fingerprints->fingerprint($runId, Bt02FingerprintType::Content);
        if (! hash_equals($sourceExpected, $source) || ! hash_equals($contentExpected, $content)) {
            throw new RuntimeException("BT-03E-02 source fingerprint drifted for {$year} {$statCode}.");
        }

        return [
            'year' => $year,
            'stat_code' => $statCode,
            'feature_run_id' => $runId,
            'source_fingerprint_sha256' => $source,
            'content_fingerprint_sha256' => $content,
        ];
    }

    /** @param list<object> $sources */
    private function assertSignalMetadata(array $sources): void
    {
        $ids = array_map(static fn (object $source): int => $source->featureRunId, $sources);
        $runs = DB::table('statistic_feature_runs')->whereIn('id', $ids)->get()->keyBy('id');
        $counts = DB::table('statistic_feature_results')
            ->selectRaw('feature_run_id, COUNT(*) AS row_count, COUNT(DISTINCT race_id) AS race_count')
            ->whereIn('feature_run_id', $ids)
            ->groupBy('feature_run_id')->get()->keyBy('feature_run_id');
        foreach ($sources as $source) {
            $run = $runs->get($source->featureRunId);
            $count = $counts->get($source->featureRunId);
            if ($run === null || $count === null
                || (string) $run->run_uuid !== $source->featureRunUuid
                || (string) $run->stat_code !== $source->statCode
                || (string) $run->calculation_version !== $source->calculationVersion
                || (string) $run->target_from !== $source->targetFrom
                || (string) $run->target_to !== $source->targetTo
                || ! in_array((string) $run->status, ['SUCCEEDED', 'PARTIALLY_SUCCEEDED'], true)
                || (int) $run->error_count !== 0
                || (int) $run->processed_race_count !== $source->processedRaceCount
                || (int) $count->row_count !== $source->rowCount
                || (int) $count->race_count !== $source->processedRaceCount) {
                throw new RuntimeException("BT-03E-02 signal source {$source->featureRunId} drifted.");
            }
        }
    }
}
