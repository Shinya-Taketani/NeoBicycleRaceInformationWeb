<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Calculators\Bt03e06AcceptanceGate;
use App\Domain\Keirin\Backtest\Calculators\Bt03e06MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e06PairedBootstrap;
use App\Domain\Keirin\Backtest\Calculators\Bt03e06WinnerConditionedDecoder;
use App\Domain\Keirin\Backtest\Repositories\Bt03eRuleSourceRepository;
use App\Domain\Keirin\Backtest\Support\Bt03e05RaceSpool;
use App\Domain\Keirin\Backtest\Support\Bt03e06DecoderManifestAccumulator;
use App\Domain\Keirin\Backtest\Support\Bt03e06MetricContributionSpool;
use App\Domain\Keirin\Backtest\Support\Bt03e06RaceSpool;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use RuntimeException;
use Throwable;

final class Bt03e06DevelopmentEvaluationService
{
    /** @var list<Bt03e05RaceSpool> */
    private array $sourceSpools = [];

    /** @var list<Bt03e06RaceSpool> */
    private array $raceSpools = [];

    /** @var list<Bt03e06MetricContributionSpool> */
    private array $metricSpools = [];

    public function __construct(
        private readonly Bt03e06SourceBundleLoader $sourceBundles,
        private readonly Bt03e06SourcePreflightService $preflight,
        private readonly Bt03e06ModelReconstructor $models,
        private readonly Bt03e06PredictionInputReconstructor $inputs,
        private readonly Bt03e06WinnerConditionedDecoder $decoder,
        private readonly Bt03eRuleSourceRepository $sourceRepository,
        private readonly Bt03eOutcomeSnapshotProvider $snapshots,
        private readonly Bt03e06OutcomeEvaluationLoader $outcomes,
        private readonly Bt03e06MetricEvaluator $metrics,
        private readonly Bt03e06PairedBootstrap $bootstrap,
        private readonly Bt03e06AcceptanceGate $acceptance,
        private readonly Bt03e02SourceIntegrityGuard $integrity,
        private readonly Bt03e06OutcomeSnapshotEndVerifier $endSnapshotVerifier,
        private readonly Bt03e06ReproducibilityVerifier $reproducibility,
        private readonly Bt03e06ArtifactWriter $artifacts,
        private readonly CanonicalHasher $hasher,
        private readonly Bt03e06ReadOnlyQueryAudit $audit,
        private readonly Bt03eReadOnlyDatabaseGuard $databaseGuard,
    ) {}

    /** @return array<string,mixed> */
    public function run(
        string $sourceBundle,
        string $outputDirectory = '/tmp',
        ?string $verifyReproducibility = null,
    ): array {
        $startedAt = hrtime(true);
        $runIdentity = 'bt03e06-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(16));
        $source = $this->sourceBundles->load($sourceBundle, sys_get_temp_dir());
        foreach ($source['years'] as $spool) {
            $this->sourceSpools[] = $spool;
        }
        $sourceResult = $source['source_result'];

        $this->audit->start();
        try {
            $this->databaseGuard->begin();
            $this->audit->recordContractFrozen();
            $this->audit->recordSourceBundleValidated();
            $featureStart = $this->preflight->run();
            $this->assertSourceFeatures($sourceResult, $featureStart);

            $models = $reconstructed = $decoderSpools = $decoderManifests = $reconstructionManifests = [];
            foreach (Bt03e06Contract::DEVELOPMENT_YEARS as $year) {
                $models[$year] = $this->models->reconstruct($year, $sourceResult["outer_{$year}"]['model']);
                $reconstructed[$year] = $this->inputs->reconstruct(
                    $year,
                    $source['years'][$year],
                    $models[$year],
                    $sourceResult["outer_{$year}"]['prediction_manifest'],
                    $featureStart['year_fingerprint_digests'][$year],
                    sys_get_temp_dir(),
                );
                $this->raceSpools[] = $reconstructed[$year]['spool'];
                $reconstructionManifests[$year] = $reconstructed[$year]['reconstruction_manifest'];
                $sourceIdentity = [
                    'source_reproducibility_hash' => $source['identity']['source_reproducibility_hash'],
                    'source_artifact_manifest_sha256' => $source['identity']['source_artifact_manifest_sha256'],
                    'source_prediction_manifest_sha256' => $sourceResult["outer_{$year}"]['prediction_manifest']['semantic_sha256'],
                    'source_model_canonical_sha256' => $models[$year]->canonicalHash,
                    'reconstruction_manifest_sha256' => $reconstructionManifests[$year]['semantic_sha256'],
                ];
                $decoderSpool = $this->trackRace(new Bt03e06RaceSpool(
                    'DECODER',
                    sys_get_temp_dir().'/bt03e06-decoder-'.$year.'-'.bin2hex(random_bytes(8)).'.jsonl',
                ));
                $manifest = new Bt03e06DecoderManifestAccumulator($year, $sourceIdentity, $this->hasher);
                foreach ($reconstructed[$year]['spool']->races() as $prediction) {
                    $decision = $this->decoder->decode($prediction);
                    $manifest->append($decision);
                    $decoderSpool->append($decision);
                }
                $decoderSpool->seal();
                $decoderSpools[$year] = $decoderSpool;
                $decoderManifests[$year] = $manifest->seal();
                $this->audit->recordCandidateManifestSealed($year);
            }

            $snapshotPath = $this->sourceRepository->outcomeSnapshotPath();
            $snapshot = $this->snapshots->open(storage_path('app/'.$snapshotPath), $snapshotPath);
            if (! hash_equals(Bt03SourceManifest::OUTCOME_SNAPSHOT_MANIFEST_HASH, $snapshot->manifestHash())) {
                throw new RuntimeException('BT-03E-06 outcome snapshot identity was invalid.');
            }
            $snapshotStart = $snapshot->auditParameters();
            $this->assertSourceSnapshot($sourceResult, $snapshotStart);

            $outer = $metricSpools = [];
            foreach (Bt03e06Contract::DEVELOPMENT_YEARS as $year) {
                $context = $this->trackRace($this->outcomes->build($year, $snapshot, sys_get_temp_dir()));
                $metricSpool = $this->trackMetric(new Bt03e06MetricContributionSpool(
                    sys_get_temp_dir().'/bt03e06-metrics-'.$year.'-'.bin2hex(random_bytes(8)).'.bin',
                ));
                $summary = $this->metrics->emptySummary();
                $this->evaluateYear($decoderSpools[$year], $context, function (array $decision, array $contextRace) use ($metricSpool, &$summary): void {
                    $comparison = $this->metrics->raceComparison($contextRace, $decision);
                    $this->metrics->add($summary, $comparison);
                    $metricSpool->append($comparison);
                });
                $metricSpool->seal();
                $metricSpools[$year] = $metricSpool;
                $outer[$year] = $this->metrics->finish($summary);
            }

            $intervals = $this->bootstrap->evaluate($metricSpools);
            $endSnapshot = $this->snapshots->open(storage_path('app/'.$snapshotPath), $snapshotPath);
            $snapshotEnd = $this->endSnapshotVerifier->verify($endSnapshot, $this->audit);
            $this->integrity->assertUnchanged($snapshotStart, $snapshotEnd, 'BT-03E-06 outcome snapshot');
            $featureEnd = $this->preflight->run();
            $this->integrity->assertUnchanged($featureStart, $featureEnd, 'BT-03E-06 fixed feature source');
            $queryAudit = $this->audit->finish();
            $databaseAudit = $this->databaseGuard->rollback();

            $summary = [
                'run_identity' => $runIdentity,
                'calculation_version' => Bt03e06Contract::CALCULATION_VERSION,
                'contract' => Bt03e06Contract::plan(),
                'source_bundle_identity' => $source['identity'],
                'source_bundle_runtime' => ['absolute_path' => realpath($sourceBundle) ?: $sourceBundle],
                'outer_model_canonical_hashes' => array_map(static fn ($model): string => $model->canonicalHash, $models),
                'feature_source_integrity' => ['start' => $featureStart, 'end' => $featureEnd, 'unchanged' => true],
                'outcome_snapshot_identity' => ['start' => $snapshotStart, 'end' => $snapshotEnd, 'unchanged' => true],
                'reconstruction_manifests' => $reconstructionManifests,
                'decoder_manifests' => $decoderManifests,
                'outer_2024' => ['metrics' => $outer[2024]],
                'outer_2025' => ['metrics' => $outer[2025]],
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
                if ($this->audit->active()) {
                    $this->audit->finish();
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
            foreach ($this->sourceSpools as $spool) {
                $spool->cleanup();
            }
            $this->sourceSpools = [];
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
    private function evaluateYear(Bt03e06RaceSpool $decisions, Bt03e06RaceSpool $context, callable $consumer): void
    {
        $decisionIterator = $decisions->races();
        $contextIterator = $context->races();
        $decisionIterator->rewind();
        $contextIterator->rewind();
        while ($decisionIterator->valid() && $contextIterator->valid()) {
            $decision = $decisionIterator->current();
            $contextRace = $contextIterator->current();
            if ($decision['year'] !== $contextRace['year'] || $decision['race_id'] !== $contextRace['race_id']) {
                throw new RuntimeException('BT-03E-06 candidate and outcome race cohorts differed.');
            }
            $consumer($decision, $contextRace);
            $decisionIterator->next();
            $contextIterator->next();
        }
        if ($decisionIterator->valid() || $contextIterator->valid()) {
            throw new RuntimeException('BT-03E-06 candidate and outcome race counts differed.');
        }
    }

    /** @param array<string,mixed> $sourceResult @param array<string,mixed> $feature */
    private function assertSourceFeatures(array $sourceResult, array $feature): void
    {
        $sourceRecords = $sourceResult['source_integrity']['start']['fingerprints'] ?? null;
        if (! is_array($sourceRecords)) {
            throw new RuntimeException('BT-03E-06 source feature fingerprint evidence was unavailable.');
        }
        $normalizer = static fn (array $record): array => array_intersect_key($record, array_flip([
            'year', 'stat_code', 'feature_run_id', 'source_fingerprint_sha256', 'content_fingerprint_sha256',
        ]));
        $sourceFixed = array_values(array_map($normalizer, array_filter(
            $sourceRecords,
            static fn (mixed $record): bool => is_array($record)
                && in_array($record['year'] ?? null, Bt03e06Contract::DEVELOPMENT_YEARS, true)
                && in_array($record['stat_code'] ?? null, ['STAT-01', ...Bt03e06Contract::STAT_CODES], true),
        )));
        $currentFixed = array_map($normalizer, $feature['fingerprints']);
        if ($sourceFixed !== $currentFixed) {
            throw new RuntimeException('BT-03E-06 source artifact and fixed feature fingerprints differed.');
        }
    }

    /** @param array<string,mixed> $sourceResult @param array<string,mixed> $snapshot */
    private function assertSourceSnapshot(array $sourceResult, array $snapshot): void
    {
        $sourceHash = $sourceResult['outcome_snapshot']['start']['outcome_snapshot_manifest_hash'] ?? null;
        if (! is_string($sourceHash)
            || ! hash_equals((string) $snapshot['outcome_snapshot_manifest_hash'], $sourceHash)) {
            throw new RuntimeException('BT-03E-06 source and evaluation outcome snapshots differed.');
        }
    }

    private function trackRace(Bt03e06RaceSpool $spool): Bt03e06RaceSpool
    {
        $this->raceSpools[] = $spool;

        return $spool;
    }

    private function trackMetric(Bt03e06MetricContributionSpool $spool): Bt03e06MetricContributionSpool
    {
        $this->metricSpools[] = $spool;

        return $spool;
    }
}
