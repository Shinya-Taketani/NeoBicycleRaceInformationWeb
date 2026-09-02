<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Contracts\Bt02FingerprintRunner;
use App\Domain\Keirin\Backtest\Enums\Bt02FingerprintType;
use App\Domain\Keirin\Backtest\Repositories\BacktestFeatureRepository;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class Bt03e08SourcePreflightService
{
    public function __construct(
        private readonly Bt01SourceManifest $baselineManifest,
        private readonly Bt02BaselineFingerprintManifest $baselineFingerprints,
        private readonly Bt02SourceManifest $signalManifest,
        private readonly BacktestFeatureRepository $baselineFeatures,
        private readonly Bt02FingerprintRunner $fingerprints,
        private readonly CanonicalHasher $hasher,
        private readonly Bt03e08ReadOnlyQueryAudit $audit,
    ) {}

    /** @return array<string,mixed> */
    public function run(): array
    {
        if ($this->signalManifest->computedHash() !== Bt02SourceManifest::HASH || $this->baselineFingerprints->computedHash() !== Bt02BaselineFingerprintManifest::HASH) {
            throw new RuntimeException('BT-03E-08 fixed source manifests were invalid.');
        }
        $baselineSources = array_map(fn (int $year): object => $this->baselineManifest->forYear($year), Bt03e08Contract::DEVELOPMENT_YEARS);
        foreach (Bt03e08Contract::DEVELOPMENT_YEARS as $year) {
            $this->audit->recordFeatureSourceYear($year);
        }
        $this->baselineFeatures->validateSources($baselineSources);
        $signalSources = [];
        foreach (Bt03e08Contract::DEVELOPMENT_YEARS as $year) {
            foreach (Bt03e08Contract::STAT_CODES as $statCode) {
                $signalSources[] = $this->signalManifest->for($year, $statCode);
                $this->audit->recordFeatureSourceYear($year);
            }
        }
        $metadata = $this->signalMetadata($signalSources);
        $this->fingerprints->assertVersionContract();
        $records = [];
        $yearRecords = array_fill_keys(Bt03e08Contract::DEVELOPMENT_YEARS, []);
        foreach (Bt03e08Contract::DEVELOPMENT_YEARS as $year) {
            $manifest = $this->baselineManifest->forYear($year);
            $fingerprint = $this->baselineFingerprints->forYear($year);
            $record = $this->record($year, 'STAT-01', $fingerprint->featureRunId, $fingerprint->sourceFingerprintSha256, $fingerprint->contentFingerprintSha256, [
                'feature_run_uuid' => $manifest->featureRunUuid, 'calculation_version' => $fingerprint->calculationVersion,
                'target_from' => $manifest->targetFrom, 'target_to' => $manifest->targetTo, 'race_count' => $manifest->expectedRaceCount,
                'row_count' => $manifest->expectedResultCount, 'status' => 'PARTIALLY_SUCCEEDED', 'error_count' => 0,
            ]);
            $records[] = $yearRecords[$year][] = $record;
        }
        foreach ($signalSources as $source) {
            $record = $this->record($source->year, $source->statCode, $source->featureRunId, $source->sourceFingerprintSha256, $source->contentFingerprintSha256, [
                'feature_run_uuid' => $source->featureRunUuid, 'calculation_version' => $source->calculationVersion,
                'target_from' => $source->targetFrom, 'target_to' => $source->targetTo, 'race_count' => $source->processedRaceCount,
                'row_count' => $source->rowCount, 'status' => $metadata[$source->featureRunId]['status'], 'error_count' => $metadata[$source->featureRunId]['error_count'],
            ]);
            $records[] = $yearRecords[$source->year][] = $record;
        }
        $digests = [];
        foreach ($yearRecords as $year => $yearRecord) {
            $digests[$year] = $this->hasher->hash($yearRecord);
        }

        return [
            'verified_baseline_runs' => count($baselineSources), 'verified_signal_runs' => count($signalSources),
            'fingerprints' => $records, 'fingerprint_digest' => $this->hasher->hash($records), 'year_fingerprint_digests' => $digests,
            'verification_digest' => $this->hasher->hash(['baseline_manifest' => $this->baselineManifest->hash(), 'signal_manifest' => $this->signalManifest->hash(), 'records' => $records]),
        ];
    }

    /** @param array<string,int|string> $metadata @return array<string,int|string> */
    private function record(int $year, string $statCode, int $runId, string $expectedSource, string $expectedContent, array $metadata): array
    {
        $this->audit->recordFeatureSourceYear($year);
        $source = $this->fingerprints->fingerprint($runId, Bt02FingerprintType::Source);
        $this->audit->recordFeatureSourceYear($year);
        $content = $this->fingerprints->fingerprint($runId, Bt02FingerprintType::Content);
        if (! hash_equals($expectedSource, $source) || ! hash_equals($expectedContent, $content)) {
            throw new RuntimeException("BT-03E-08 source fingerprint drifted for {$year} {$statCode}.");
        }

        return ['year' => $year, 'stat_code' => $statCode, 'feature_run_id' => $runId, ...$metadata, 'source_fingerprint_sha256' => $source, 'content_fingerprint_sha256' => $content];
    }

    /** @param list<object> $sources @return array<int,array{status:string,error_count:int}> */
    private function signalMetadata(array $sources): array
    {
        $ids = array_map(static fn (object $source): int => $source->featureRunId, $sources);
        $runs = DB::table('statistic_feature_runs')->whereIn('id', $ids)->get()->keyBy('id');
        $counts = DB::table('statistic_feature_results')->selectRaw('feature_run_id, COUNT(*) AS row_count, COUNT(DISTINCT race_id) AS race_count')->whereIn('feature_run_id', $ids)->groupBy('feature_run_id')->get()->keyBy('feature_run_id');
        $metadata = [];
        foreach ($sources as $source) {
            $run = $runs->get($source->featureRunId);
            $count = $counts->get($source->featureRunId);
            if ($run === null || $count === null || (string) $run->run_uuid !== $source->featureRunUuid || (string) $run->stat_code !== $source->statCode
                || (string) $run->calculation_version !== $source->calculationVersion || (string) $run->target_from !== $source->targetFrom || (string) $run->target_to !== $source->targetTo
                || ! in_array((string) $run->status, ['SUCCEEDED', 'PARTIALLY_SUCCEEDED'], true) || (int) $run->error_count !== 0
                || (int) $run->processed_race_count !== $source->processedRaceCount || (int) $count->row_count !== $source->rowCount || (int) $count->race_count !== $source->processedRaceCount) {
                throw new RuntimeException("BT-03E-08 signal source {$source->featureRunId} drifted.");
            }
            $metadata[$source->featureRunId] = ['status' => (string) $run->status, 'error_count' => (int) $run->error_count];
        }

        return $metadata;
    }
}
