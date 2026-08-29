<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Calculators\Bt03e02ParameterLayout;
use App\Domain\Keirin\Backtest\Calculators\Bt03e02ParameterLayoutBuilder;
use App\Domain\Keirin\Backtest\Calculators\Bt03e07AcceptanceGate;
use App\Domain\Keirin\Backtest\Calculators\Bt03e07DirectPositionObjective;
use App\Domain\Keirin\Backtest\Calculators\Bt03e07DirectPositionScorer;
use App\Domain\Keirin\Backtest\Calculators\Bt03e07FistaOptimizer;
use App\Domain\Keirin\Backtest\Calculators\Bt03e07MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e07OneSeSelector;
use App\Domain\Keirin\Backtest\Calculators\Bt03e07P1FrozenDecisionDecoder;
use App\Domain\Keirin\Backtest\Calculators\Bt03e07PairedBootstrap;
use App\Domain\Keirin\Backtest\DTO\Bt03e07FitResultDto;
use App\Domain\Keirin\Backtest\Repositories\Bt03eRuleSourceRepository;
use App\Domain\Keirin\Backtest\Support\Bt03e02RaceSpool;
use App\Domain\Keirin\Backtest\Support\Bt03e05RaceSpool;
use App\Domain\Keirin\Backtest\Support\Bt03e06MetricContributionSpool;
use App\Domain\Keirin\Backtest\Support\Bt03e06RaceSpool;
use App\Domain\Keirin\Backtest\Support\Bt03e07PredictionManifestAccumulator;
use App\Domain\Keirin\Backtest\Support\Bt03e07ValidationLossSpool;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use RuntimeException;
use Throwable;

final class Bt03e07DevelopmentEvaluationService
{
    /** @var list<Bt03e05RaceSpool> */
    private array $sourceSpools = [];

    /** @var list<Bt03e02RaceSpool|Bt03e06RaceSpool> */
    private array $raceSpools = [];

    /** @var list<Bt03e07ValidationLossSpool> */
    private array $lossSpools = [];

    /** @var list<Bt03e06MetricContributionSpool> */
    private array $metricSpools = [];

    public function __construct(
        private readonly Bt03e07SourceBundleLoader $sourceBundles,
        private readonly Bt03e07SourcePreflightService $preflight,
        private readonly Bt03e07TrainingDatasetBuilder $trainingDatasets,
        private readonly Bt03e07PredictionDatasetBuilder $predictionDatasets,
        private readonly Bt03e02ParameterLayoutBuilder $layouts,
        private readonly Bt03e07LayoutIdentityGuard $layoutIdentity,
        private readonly Bt03e07FistaOptimizer $optimizer,
        private readonly Bt03e07DirectPositionObjective $objective,
        private readonly Bt03e07OneSeSelector $oneSe,
        private readonly Bt03e07DirectPositionScorer $scorer,
        private readonly Bt03e07P1FrozenDecisionDecoder $decoder,
        private readonly Bt03eRuleSourceRepository $sourceRepository,
        private readonly Bt03eOutcomeSnapshotProvider $snapshots,
        private readonly Bt03e07OutcomeEvaluationLoader $outcomes,
        private readonly Bt03e07MetricEvaluator $metrics,
        private readonly Bt03e07PairedBootstrap $bootstrap,
        private readonly Bt03e07AcceptanceGate $acceptance,
        private readonly Bt03e02SourceIntegrityGuard $integrity,
        private readonly Bt03e07OutcomeSnapshotEndVerifier $endSnapshotVerifier,
        private readonly Bt03e07ReproducibilityVerifier $reproducibility,
        private readonly Bt03e07ArtifactWriter $artifacts,
        private readonly CanonicalHasher $hasher,
        private readonly Bt03e07ReadOnlyQueryAudit $audit,
        private readonly Bt03eReadOnlyDatabaseGuard $databaseGuard,
    ) {}

    /** @return array<string,mixed> */
    public function run(string $sourceBundle, string $outputDirectory = '/tmp', ?string $verifyReproducibility = null): array
    {
        $startedAt = hrtime(true);
        $runIdentity = 'bt03e07-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(16));
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

            $snapshotPath = $this->sourceRepository->outcomeSnapshotPath();
            $snapshot = $this->snapshots->open(storage_path('app/'.$snapshotPath), $snapshotPath);
            if (! hash_equals(Bt03SourceManifest::OUTCOME_SNAPSHOT_MANIFEST_HASH, $snapshot->manifestHash())) {
                throw new RuntimeException('BT-03E-07 outcome snapshot identity was invalid.');
            }
            $snapshotStart = $snapshot->auditParameters();
            $this->assertSourceSnapshot($sourceResult, $snapshotStart);

            $raw = [];
            foreach ([2022, 2023] as $year) {
                $raw[$year] = $this->trackRace($this->trainingDatasets->buildRaw($year, $snapshot, sys_get_temp_dir()));
            }

            $innerA = $this->fitGrid([$raw[2022]], [$raw[2023]], 'inner-a');
            $outer2024Selection = $this->selectLambda([2023 => $innerA]);
            $outer2024 = $this->fitOuter(
                [$raw[2022], $raw[2023]],
                $source['years'][2024],
                $outer2024Selection['lambda'],
                'outer-2024',
                $sourceResult['outer_2024']['model']['bins'],
                $source,
            );
            $this->audit->recordCandidateManifestSealed(2024);

            $raw[2024] = $this->trackRace($this->trainingDatasets->buildRaw(2024, $snapshot, sys_get_temp_dir()));
            $innerB = $this->fitGrid([$raw[2022], $raw[2023]], [$raw[2024]], 'inner-b', $sourceResult['outer_2024']['model']['bins']);
            $outer2025Selection = $this->selectLambda([2023 => $innerA, 2024 => $innerB]);
            $outer2025 = $this->fitOuter(
                [$raw[2022], $raw[2023], $raw[2024]],
                $source['years'][2025],
                $outer2025Selection['lambda'],
                'outer-2025',
                $sourceResult['outer_2025']['model']['bins'],
                $source,
            );
            $this->audit->recordCandidateManifestSealed(2025);

            $outer = [];
            $metricSpools = [];
            $predictionSpools = [2024 => $outer2024['predictions'], 2025 => $outer2025['predictions']];
            foreach (Bt03e07Contract::OUTER_YEARS as $year) {
                $context = $this->trackRace($this->outcomes->build($year, $snapshot, sys_get_temp_dir()));
                $metricSpool = $this->trackMetric(new Bt03e06MetricContributionSpool(
                    sys_get_temp_dir().'/bt03e07-metrics-'.$year.'-'.bin2hex(random_bytes(8)).'.bin',
                ));
                $summary = $this->metrics->emptySummary();
                $this->evaluateYear($predictionSpools[$year], $context, function (array $decision, array $contextRace) use ($metricSpool, &$summary): void {
                    $comparison = $this->metrics->raceComparison($contextRace, $decision);
                    $this->metrics->add($summary, $comparison);
                    $metricSpool->append($comparison);
                });
                $metricSpool->seal();
                $metricSpools[$year] = $metricSpool;
                $outer[$year] = $this->metrics->finish($summary);
            }

            $intervals = $this->bootstrap->evaluate($metricSpools);
            $snapshotEnd = $this->endSnapshotVerifier->verify(
                $this->snapshots->open(storage_path('app/'.$snapshotPath), $snapshotPath),
                $this->audit,
            );
            $this->integrity->assertUnchanged($snapshotStart, $snapshotEnd, 'BT-03E-07 outcome snapshot');
            $featureEnd = $this->preflight->run();
            $this->integrity->assertUnchanged($featureStart, $featureEnd, 'BT-03E-07 fixed feature source');
            $queryAudit = $this->audit->finish();
            $databaseAudit = $this->databaseGuard->rollback();

            $summary = [
                'run_identity' => $runIdentity,
                'calculation_version' => Bt03e07Contract::CALCULATION_VERSION,
                'contract' => Bt03e07Contract::plan(),
                'source_bundle_identity' => $source['identity'],
                'source_bundle_runtime' => ['absolute_path' => realpath($sourceBundle) ?: $sourceBundle],
                'feature_source_integrity' => ['start' => $featureStart, 'end' => $featureEnd, 'unchanged' => true],
                'outcome_snapshot_identity' => ['start' => $snapshotStart, 'end' => $snapshotEnd, 'unchanged' => true],
                'fold_definitions' => Bt03e07Contract::plan()['outer_folds'],
                'inner_layout_identities' => ['inner_a' => $innerA['layout_hash'], 'inner_b' => $innerB['layout_hash']],
                'outer_2024' => $this->outerArtifact($outer2024Selection, $outer2024, $outer[2024]),
                'outer_2025' => $this->outerArtifact($outer2025Selection, $outer2025, $outer[2025]),
                'prediction_manifests' => [2024 => $outer2024['prediction_manifest'], 2025 => $outer2025['prediction_manifest']],
                'paired_bootstrap_ci' => $intervals,
                'acceptance_gate_input' => ['outer_metrics' => $outer, 'paired_bootstrap_ci' => $intervals],
                'audit' => [...$queryAudit, ...$databaseAudit, 'source_drift' => false, 'partial_publication' => false, '2026_access_count' => 0],
            ];
            $hash = $this->reproducibility->hash($summary);
            $verification = $this->reproducibility->verify($verifyReproducibility, $hash);
            $gate = $this->acceptance->evaluate($outer, $intervals, $verification['verified']);
            if (! $verification['verified']) {
                $gate['status'] = 'REPRODUCIBILITY VERIFICATION REQUIRED';
            }
            $summary = [...$summary, 'reproducibility_hash' => $hash, 'reproducibility_verification' => $verification, 'acceptance_gate' => $gate, 'runtime' => [
                'seconds' => (hrtime(true) - $startedAt) / 1_000_000_000,
                'peak_bytes' => memory_get_peak_usage(true),
                'memory_contract_bytes' => 128 * 1024 * 1024,
            ]];
            $paths = $this->artifacts->write($outputDirectory, $summary, [2024 => $outer2024['predictions'], 2025 => $outer2025['predictions']]);

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
            foreach ($this->lossSpools as $spool) {
                $spool->cleanup();
            }
            $this->lossSpools = [];
            foreach ($this->metricSpools as $spool) {
                $spool->cleanup();
            }
            $this->metricSpools = [];
        }
    }

    /** @param list<Bt03e02RaceSpool> $training @param list<Bt03e02RaceSpool> $validation @param array<string,mixed>|null $expectedBins @return array<string,mixed> */
    private function fitGrid(array $training, array $validation, string $role, ?array $expectedBins = null): array
    {
        $layout = $this->layouts->build($this->source($training));
        $layoutHash = $this->layoutIdentity->verify($layout, $expectedBins, $role);
        $binnedTraining = $this->trackRace($this->trainingDatasets->buildBinned($training, $layout, sys_get_temp_dir(), $role.'-training'));
        $binnedValidation = $this->trackRace($this->trainingDatasets->buildBinned($validation, $layout, sys_get_temp_dir(), $role.'-validation'));
        $path = $this->optimizer->fitPath($this->source([$binnedTraining]), $layout);
        $losses = $this->trackLoss(new Bt03e07ValidationLossSpool(
            sys_get_temp_dir().'/bt03e07-validation-'.$role.'-'.bin2hex(random_bytes(8)).'.bin',
            array_keys($path['fits']),
        ));
        foreach ($binnedValidation->races() as $race) {
            $raceLosses = [];
            foreach ($path['fits'] as $key => $fit) {
                foreach (Bt03e07Contract::POSITIONS as $position) {
                    $raceLosses[$key][$position] = $this->objective->raceLoss($race, $layout, $fit->coefficients[$position], $position);
                }
            }
            $losses->append($raceLosses);
        }
        $losses->seal();

        return ['fits' => $path['fits'], 'candidate_statuses' => $path['candidate_statuses'], 'fit_order' => $path['fit_order'], 'losses' => $losses, 'layout_hash' => $layoutHash];
    }

    /** @param array<int,array<string,mixed>> $inner @return array<string,mixed> */
    private function selectLambda(array $inner): array
    {
        $selection = $this->oneSe->select(array_map(static fn (array $fold): Bt03e07ValidationLossSpool => $fold['losses'], $inner));

        return [...$selection, 'fit_order' => array_map(static fn (array $fold): array => $fold['fit_order'], $inner), 'candidate_statuses' => array_map(static fn (array $fold): array => $fold['candidate_statuses'], $inner)];
    }

    /** @param list<Bt03e02RaceSpool> $training @param array<string,mixed> $sourceBundle @return array<string,mixed> */
    private function fitOuter(array $training, Bt03e05RaceSpool $sourcePredictions, float $lambda, string $role, array $expectedBins, array $sourceBundle): array
    {
        $year = (int) substr($role, -4);
        $layout = $this->layouts->build($this->source($training));
        $this->layoutIdentity->verify($layout, $expectedBins, $role);
        $binnedTraining = $this->trackRace($this->trainingDatasets->buildBinned($training, $layout, sys_get_temp_dir(), $role.'-training'));
        $predictionRaw = $this->trackRace($this->predictionDatasets->buildRaw($year, $sourcePredictions, sys_get_temp_dir()));
        $predictionBinned = $this->trackRace($this->predictionDatasets->buildBinned([$predictionRaw], $layout, sys_get_temp_dir(), $role));
        $refit = $this->optimizer->fitSelectedViaPath($this->source([$binnedTraining]), $layout, $lambda);
        $model = $this->modelArtifact($layout, $refit['fit']);
        $model['p1_model_identity'] = [
            'source_reproducibility_hash' => $sourceBundle['identity']['source_reproducibility_hash'],
            'source_artifact_manifest_sha256' => $sourceBundle['identity']['source_artifact_manifest_sha256'],
            'source_prediction_manifest_sha256' => $sourceBundle['source_result']["outer_{$year}"]['prediction_manifest']['semantic_sha256'],
        ];
        $sourceIdentity = [
            'source_reproducibility_hash' => $sourceBundle['identity']['source_reproducibility_hash'],
            'source_artifact_manifest_sha256' => $sourceBundle['identity']['source_artifact_manifest_sha256'],
            'source_prediction_manifest_sha256' => $sourceBundle['source_result']["outer_{$year}"]['prediction_manifest']['semantic_sha256'],
            'source_p1_semantics' => Bt03e07Contract::SOURCE_PROBABILITY_VERSION,
            'direct_model_sha256' => $this->hasher->hash($model),
        ];
        $predictions = $this->trackRace(new Bt03e06RaceSpool('DECODER', sys_get_temp_dir().'/bt03e07-predictions-'.$year.'-'.bin2hex(random_bytes(8)).'.jsonl'));
        $manifest = new Bt03e07PredictionManifestAccumulator($year, $sourceIdentity, $this->hasher);
        $sourceIterator = $sourcePredictions->races();
        $binnedIterator = $predictionBinned->races();
        $sourceIterator->rewind();
        $binnedIterator->rewind();
        while ($sourceIterator->valid() && $binnedIterator->valid()) {
            $sourceRace = $sourceIterator->current();
            $binnedRace = $binnedIterator->current();
            $direct = $this->scorer->predict($binnedRace, $refit['fit']);
            $decision = $this->decoder->decode($sourceRace, $direct);
            $manifest->append($decision);
            $predictions->append($decision);
            $sourceIterator->next();
            $binnedIterator->next();
        }
        if ($sourceIterator->valid() || $binnedIterator->valid()) {
            throw new RuntimeException('BT-03E-07 source P1 and direct prediction race streams differed.');
        }
        $predictions->seal();

        return [
            'model' => $model,
            'refit_path' => ['selected_lambda' => $refit['selected_lambda'], 'fit_order' => $refit['fit_order'], 'candidate_statuses' => $refit['candidate_statuses']],
            'predictions' => $predictions,
            'prediction_manifest' => $manifest->seal(),
        ];
    }

    /** @param callable(array<string,mixed>,array<string,mixed>):void $consumer */
    private function evaluateYear(Bt03e06RaceSpool $decisions, Bt03e06RaceSpool $context, callable $consumer): void
    {
        $left = $decisions->races();
        $right = $context->races();
        $left->rewind();
        $right->rewind();
        while ($left->valid() && $right->valid()) {
            if ($left->current()['race_id'] !== $right->current()['race_id'] || $left->current()['year'] !== $right->current()['year']) {
                throw new RuntimeException('BT-03E-07 candidate and outcome race cohorts differed.');
            }
            $consumer($left->current(), $right->current());
            $left->next();
            $right->next();
        }
        if ($left->valid() || $right->valid()) {
            throw new RuntimeException('BT-03E-07 candidate and outcome race counts differed.');
        }
    }

    /** @param array<string,mixed> $sourceResult @param array<string,mixed> $feature */
    private function assertSourceFeatures(array $sourceResult, array $feature): void
    {
        $records = $sourceResult['source_integrity']['start']['fingerprints'] ?? null;
        if (! is_array($records)) {
            throw new RuntimeException('BT-03E-07 source feature fingerprint evidence was unavailable.');
        }
        $normalize = static fn (array $record): array => array_intersect_key($record, array_flip([
            'year', 'stat_code', 'feature_run_id', 'source_fingerprint_sha256', 'content_fingerprint_sha256',
        ]));
        $sourceFixed = array_values(array_map($normalize, array_filter($records, static fn (mixed $record): bool => is_array($record)
            && in_array($record['year'] ?? null, Bt03e07Contract::DEVELOPMENT_YEARS, true)
            && in_array($record['stat_code'] ?? null, ['STAT-01', ...Bt03e07Contract::STAT_CODES], true))));
        $currentFixed = array_map($normalize, $feature['fingerprints']);
        if ($sourceFixed !== $currentFixed) {
            throw new RuntimeException('BT-03E-07 source artifact and current fixed feature fingerprints differed.');
        }
    }

    /** @param array<string,mixed> $sourceResult @param array<string,mixed> $snapshot */
    private function assertSourceSnapshot(array $sourceResult, array $snapshot): void
    {
        $sourceIdentity = $sourceResult['outcome_snapshot']['start'] ?? null;
        if (! is_array($sourceIdentity) || $sourceIdentity !== $snapshot) {
            throw new RuntimeException('BT-03E-07 source and current outcome snapshot identities differed.');
        }
    }

    /** @param list<Bt03e02RaceSpool> $spools @return callable():\Generator<int,array<string,mixed>> */
    private function source(array $spools): callable
    {
        return static function () use ($spools): \Generator {
            foreach ($spools as $spool) {
                yield from $spool->races();
            }
        };
    }

    private function trackRace(Bt03e02RaceSpool|Bt03e06RaceSpool $spool): Bt03e02RaceSpool|Bt03e06RaceSpool
    {
        $this->raceSpools[] = $spool;

        return $spool;
    }

    private function trackLoss(Bt03e07ValidationLossSpool $spool): Bt03e07ValidationLossSpool
    {
        $this->lossSpools[] = $spool;

        return $spool;
    }

    private function trackMetric(Bt03e06MetricContributionSpool $spool): Bt03e06MetricContributionSpool
    {
        $this->metricSpools[] = $spool;

        return $spool;
    }

    /** @param array<string,mixed> $selection @param array<string,mixed> $outer @param array<string,mixed> $metrics @return array<string,mixed> */
    private function outerArtifact(array $selection, array $outer, array $metrics): array
    {
        return ['lambda_selection' => $selection, 'model' => $outer['model'], 'refit_path' => $outer['refit_path'], 'prediction_manifest' => $outer['prediction_manifest'], 'metrics' => $metrics];
    }

    /** @return array<string,mixed> */
    private function modelArtifact(Bt03e02ParameterLayout $layout, Bt03e07FitResultDto $fit): array
    {
        return [
            'optimizer_version' => Bt03e07Contract::OPTIMIZER_VERSION,
            'objective_version' => Bt03e07Contract::OBJECTIVE_VERSION,
            'probability_version' => Bt03e07Contract::PROBABILITY_VERSION,
            'lambda' => $fit->lambda,
            'stat01_anchor_coefficient' => 1.0,
            'bins' => $layout->canonicalBins(),
            'position_coefficients' => $fit->coefficients,
            'weighted_center_means' => array_map(fn (array $values): array => $layout->weightedMeans($values), $fit->coefficients),
            'objectives' => $fit->objectives,
            'iterations' => $fit->iterations,
            'eligible_races' => $fit->eligibleRaceCounts,
            'excluded_races' => $fit->excludedRaceCounts,
            'optimizer_diagnostics' => $fit->diagnostics,
        ];
    }
}
