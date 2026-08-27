<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Calculators\Bt03e04AcceptanceGate;
use App\Domain\Keirin\Backtest\Calculators\Bt03e04DecisionDecoder;
use App\Domain\Keirin\Backtest\Calculators\Bt03e04MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e04PairedBootstrap;
use App\Domain\Keirin\Backtest\Repositories\Bt03eRuleSourceRepository;
use App\Domain\Keirin\Backtest\Support\Bt03e04DecoderManifestAccumulator;
use App\Domain\Keirin\Backtest\Support\Bt03e04MetricContributionSpool;
use App\Domain\Keirin\Backtest\Support\Bt03e04RaceSpool;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use RuntimeException;
use Throwable;

class Bt03e04DevelopmentEvaluationService
{
    /** @var list<Bt03e04RaceSpool> */
    private array $raceSpools = [];

    /** @var list<Bt03e04MetricContributionSpool> */
    private array $metricSpools = [];

    public function __construct(
        private readonly Bt03e04SourceBundleLoader $sourceBundles,
        private readonly Bt03eRuleSourceRepository $sourceRepository,
        private readonly Bt03eOutcomeSnapshotProvider $snapshots,
        private readonly Bt03e04BaselineSourcePreflightService $preflight,
        private readonly Bt03e02SourceIntegrityGuard $integrity,
        private readonly Bt03e04EvaluationContextLoader $contexts,
        private readonly Bt03e04DecisionDecoder $decoder,
        private readonly Bt03e04MetricEvaluator $metrics,
        private readonly Bt03e04PairedBootstrap $bootstrap,
        private readonly Bt03e04AcceptanceGate $acceptance,
        private readonly Bt03e04ReproducibilityVerifier $reproducibility,
        private readonly Bt03e04ArtifactWriter $artifacts,
        private readonly CanonicalHasher $hasher,
        private readonly Bt03e04ReadOnlyQueryAudit $queryAudit,
        private readonly Bt03eReadOnlyDatabaseGuard $databaseGuard,
    ) {}

    /** @return array<string,mixed> */
    public function run(
        string $sourceBundle,
        string $outputDirectory = '/tmp',
        ?string $verifyReproducibility = null,
    ): array {
        $startedAt = hrtime(true);
        $runIdentity = 'bt03e04-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(16));
        $source = $this->sourceBundles->load($sourceBundle, sys_get_temp_dir());
        foreach ($source['years'] as $spool) {
            $this->raceSpools[] = $spool;
        }
        $sourceResult = $source['source_result'];

        $this->queryAudit->start();
        try {
            $this->databaseGuard->begin();
            $this->queryAudit->recordDecoderContractFrozen();
            $this->queryAudit->recordSourceBundleValidated();
            $baselineStart = $this->preflight->run();
            $snapshotPath = $this->sourceRepository->outcomeSnapshotPath();
            $snapshot = $this->snapshots->open(storage_path('app/'.$snapshotPath), $snapshotPath);
            if (! hash_equals(Bt03SourceManifest::OUTCOME_SNAPSHOT_MANIFEST_HASH, $snapshot->manifestHash())) {
                throw new RuntimeException('BT-03E-04 outcome snapshot identity was invalid.');
            }
            $snapshotStart = $snapshot->auditParameters();
            $this->assertSourceCompatibility($sourceResult, $baselineStart, $snapshotStart);

            $outer = [];
            $decoderSpools = [];
            $metricSpools = [];
            $decoderManifests = [];
            foreach (Bt03e04Contract::DEVELOPMENT_YEARS as $year) {
                $context = $this->trackRace($this->contexts->build($year, $snapshot, sys_get_temp_dir()));
                $decoderSpool = $this->trackRace(new Bt03e04RaceSpool(
                    'DECODER',
                    sys_get_temp_dir().'/bt03e04-decoder-'.$year.'-'.bin2hex(random_bytes(8)).'.jsonl',
                ));
                $metricSpool = $this->trackMetric(new Bt03e04MetricContributionSpool(
                    sys_get_temp_dir().'/bt03e04-metrics-'.$year.'-'.bin2hex(random_bytes(8)).'.bin',
                ));
                $manifest = new Bt03e04DecoderManifestAccumulator($this->hasher);
                $summary = $this->metrics->emptySummary();
                $this->evaluateYear($source['years'][$year], $context, function (array $sourceRace, array $contextRace) use (
                    $decoderSpool,
                    $metricSpool,
                    $manifest,
                    &$summary,
                ): void {
                    $decision = $this->decoder->decode($sourceRace);
                    $comparison = $this->metrics->raceComparison($contextRace, $decision);
                    $this->metrics->add($summary, $comparison);
                    $metricSpool->append($comparison);
                    $manifest->append($decision);
                    $decoderSpool->append($decision);
                });
                $decoderSpool->seal();
                $metricSpool->seal();
                $outer[$year] = $this->metrics->finish($summary);
                $decoderSpools[$year] = $decoderSpool;
                $metricSpools[$year] = $metricSpool;
                $decoderManifests[$year] = $manifest->seal();
            }

            $intervals = $this->bootstrap->evaluate($metricSpools);
            $snapshotEnd = $this->snapshots->open(storage_path('app/'.$snapshotPath), $snapshotPath)->auditParameters();
            $this->integrity->assertUnchanged($snapshotStart, $snapshotEnd, 'BT-03E-04 outcome snapshot');
            $baselineEnd = $this->preflight->run();
            $this->integrity->assertUnchanged($baselineStart, $baselineEnd, 'BT-03E-04 STAT-01 baseline source');
            $queryAudit = $this->queryAudit->finish();
            $databaseAudit = $this->databaseGuard->rollback();
            $diagnostics = [
                2024 => $outer[2024]['decoder_diagnostics'],
                2025 => $outer[2025]['decoder_diagnostics'],
            ];
            $summary = [
                'run_identity' => $runIdentity,
                'calculation_version' => Bt03e04Contract::CALCULATION_VERSION,
                'contract' => Bt03e04Contract::plan(),
                'source_bundle_identity' => $source['identity'],
                'source_bundle_runtime' => ['absolute_path' => realpath($sourceBundle) ?: $sourceBundle],
                'baseline_source_integrity' => ['start' => $baselineStart, 'end' => $baselineEnd, 'unchanged' => true],
                'outcome_snapshot_identity' => ['start' => $snapshotStart, 'end' => $snapshotEnd, 'unchanged' => true],
                'outer_2024' => ['metrics' => $outer[2024]],
                'outer_2025' => ['metrics' => $outer[2025]],
                'diagnostics' => $diagnostics,
                'decoder_manifests' => $decoderManifests,
                'paired_bootstrap_ci' => $intervals,
                'acceptance_gate_input' => [
                    'outer_metrics' => [2024 => $outer[2024], 2025 => $outer[2025]],
                    'paired_bootstrap_ci' => $intervals,
                ],
                'audit' => [
                    ...$queryAudit,
                    ...$databaseAudit,
                    'source_drift' => false,
                    'partial_publication' => false,
                    '2022_outcome_access_count' => $queryAudit['snapshot_partition_access'][2022],
                    '2023_outcome_access_count' => $queryAudit['snapshot_partition_access'][2023],
                    '2026_access_count' => 0,
                ],
            ];
            $hash = $this->reproducibility->hash($summary);
            $verification = $this->reproducibility->verify($verifyReproducibility, $hash);
            $gate = $this->acceptance->evaluate($outer, $intervals, $verification['verified']);
            if (! $verification['verified']) {
                $gate['status'] = 'REPRODUCIBILITY VERIFICATION REQUIRED';
            }
            $summary = [
                ...$summary,
                'reproducibility_hash' => $hash,
                'reproducibility_verification' => $verification,
                'acceptance_gate' => $gate,
                'runtime' => [
                    'seconds' => (hrtime(true) - $startedAt) / 1_000_000_000,
                    'peak_bytes' => memory_get_peak_usage(true),
                    'memory_contract_bytes' => 128 * 1024 * 1024,
                ],
            ];
            $paths = $this->artifacts->write($outputDirectory, $summary, $decoderSpools);

            return [...$summary, 'artifacts' => $paths];
        } catch (Throwable $throwable) {
            try {
                if ($this->queryAudit->active()) {
                    $this->queryAudit->finish();
                }
            } catch (Throwable) {
                // Preserve the primary failure.
            }
            try {
                if ($this->databaseGuard->active()) {
                    $this->databaseGuard->rollback();
                }
            } catch (Throwable) {
                // Preserve the primary failure.
            }
            throw $throwable;
        } finally {
            foreach ($this->raceSpools as $spool) {
                $spool->cleanup();
            }
            $this->raceSpools = [];
            foreach ($this->metricSpools as $spool) {
                $spool->cleanup();
            }
            $this->metricSpools = [];
        }
    }

    /** @param callable(array<string,mixed>,array<string,mixed>):void $consumer */
    private function evaluateYear(Bt03e04RaceSpool $source, Bt03e04RaceSpool $context, callable $consumer): void
    {
        $sourceIterator = $source->races();
        $contextIterator = $context->races();
        $sourceIterator->rewind();
        $contextIterator->rewind();
        while ($sourceIterator->valid() && $contextIterator->valid()) {
            $sourceRace = $sourceIterator->current();
            $contextRace = $contextIterator->current();
            $sourceBikes = array_map('intval', array_column($sourceRace['entries'], 'bike'));
            $contextBikes = array_map('intval', array_column($contextRace['entries'], 'bike'));
            sort($sourceBikes, SORT_NUMERIC);
            sort($contextBikes, SORT_NUMERIC);
            if ($sourceRace['year'] !== $contextRace['year']
                || $sourceRace['race_id'] !== $contextRace['race_id']
                || $sourceBikes !== $contextBikes) {
                throw new RuntimeException('BT-03E-04 source probability and evaluation context cohorts differed.');
            }
            $consumer($sourceRace, $contextRace);
            $sourceIterator->next();
            $contextIterator->next();
        }
        if ($sourceIterator->valid() || $contextIterator->valid()) {
            throw new RuntimeException('BT-03E-04 source probability and evaluation context race counts differed.');
        }
    }

    /** @param array<string,mixed> $sourceResult @param array<string,mixed> $baseline @param array<string,mixed> $snapshot */
    private function assertSourceCompatibility(array $sourceResult, array $baseline, array $snapshot): void
    {
        $sourceSnapshotHash = $sourceResult['outcome_snapshot']['start']['outcome_snapshot_manifest_hash'] ?? null;
        if (! is_string($sourceSnapshotHash)
            || ! hash_equals((string) $snapshot['outcome_snapshot_manifest_hash'], $sourceSnapshotHash)) {
            throw new RuntimeException('BT-03E-04 source and evaluation outcome snapshots differed.');
        }
        $sourceRecords = $sourceResult['source_integrity']['start']['fingerprints'] ?? null;
        if (! is_array($sourceRecords)) {
            throw new RuntimeException('BT-03E-04 source baseline fingerprint evidence was unavailable.');
        }
        $sourceBaseline = array_values(array_filter(
            $sourceRecords,
            static fn (mixed $record): bool => is_array($record)
                && ($record['stat_code'] ?? null) === 'STAT-01'
                && in_array($record['year'] ?? null, Bt03e04Contract::DEVELOPMENT_YEARS, true),
        ));
        if ($sourceBaseline !== $baseline['fingerprints']) {
            throw new RuntimeException('BT-03E-04 source and evaluation STAT-01 fingerprints differed.');
        }
    }

    private function trackRace(Bt03e04RaceSpool $spool): Bt03e04RaceSpool
    {
        $this->raceSpools[] = $spool;

        return $spool;
    }

    private function trackMetric(Bt03e04MetricContributionSpool $spool): Bt03e04MetricContributionSpool
    {
        $this->metricSpools[] = $spool;

        return $spool;
    }
}
