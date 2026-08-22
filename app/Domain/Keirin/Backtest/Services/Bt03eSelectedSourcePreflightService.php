<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Contracts\Bt02FingerprintRunner;
use App\Domain\Keirin\Backtest\Enums\Bt02FingerprintType;
use App\Domain\Keirin\Backtest\Repositories\BacktestFeatureRepository;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class Bt03eSelectedSourcePreflightService
{
    public function __construct(
        private readonly Bt01SourceManifest $baselineManifest,
        private readonly Bt02BaselineFingerprintManifest $baselineFingerprints,
        private readonly Bt02SourceManifest $signalManifest,
        private readonly BacktestFeatureRepository $baselineFeatures,
        private readonly Bt02FingerprintRunner $fingerprints,
    ) {}

    /** @return array{verified_baseline_runs: int, verified_signal_runs: int, source_fingerprints: int, content_fingerprints: int} */
    public function run(): array
    {
        if ($this->signalManifest->computedHash() !== Bt02SourceManifest::HASH
            || $this->baselineFingerprints->computedHash() !== Bt02BaselineFingerprintManifest::HASH) {
            throw new RuntimeException('BT-03E fixed source manifests were invalid.');
        }

        $years = [Bt03eContract::TRAINING_YEAR, Bt03eContract::EVALUATION_YEAR];
        $baselineSources = array_map(fn (int $year) => $this->baselineManifest->forYear($year), $years);
        $this->baselineFeatures->validateSources($baselineSources);
        $signalSources = [];
        foreach ($years as $year) {
            foreach (Bt03eContract::STAT_CODES as $statCode) {
                $signalSources[] = $this->signalManifest->for($year, $statCode);
            }
        }
        $this->assertSignalMetadata($signalSources);

        $this->fingerprints->assertVersionContract();
        $sourceMatches = $contentMatches = 0;
        foreach ($years as $year) {
            $baseline = $this->baselineFingerprints->forYear($year);
            $this->assertFingerprint($baseline->featureRunId, Bt02FingerprintType::Source, $baseline->sourceFingerprintSha256);
            $sourceMatches++;
            $this->assertFingerprint($baseline->featureRunId, Bt02FingerprintType::Content, $baseline->contentFingerprintSha256);
            $contentMatches++;
        }
        foreach ($signalSources as $source) {
            $this->assertFingerprint($source->featureRunId, Bt02FingerprintType::Source, $source->sourceFingerprintSha256);
            $sourceMatches++;
            $this->assertFingerprint($source->featureRunId, Bt02FingerprintType::Content, $source->contentFingerprintSha256);
            $contentMatches++;
        }

        return [
            'verified_baseline_runs' => count($baselineSources),
            'verified_signal_runs' => count($signalSources),
            'source_fingerprints' => $sourceMatches,
            'content_fingerprints' => $contentMatches,
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
            ->groupBy('feature_run_id')
            ->get()->keyBy('feature_run_id');
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
                throw new RuntimeException("BT-03E fixed signal source {$source->featureRunId} drifted.");
            }
        }
    }

    private function assertFingerprint(int $runId, Bt02FingerprintType $type, string $expected): void
    {
        $actual = $this->fingerprints->fingerprint($runId, $type);
        if (! hash_equals($expected, $actual)) {
            throw new RuntimeException("BT-03E {$type->value} fingerprint mismatched for run {$runId}.");
        }
    }
}
