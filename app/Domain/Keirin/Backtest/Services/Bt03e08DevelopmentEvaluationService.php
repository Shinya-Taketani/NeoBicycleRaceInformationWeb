<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Calculators\Bt03e02ParameterLayout;
use App\Domain\Keirin\Backtest\Calculators\Bt03e02ParameterLayoutBuilder;
use App\Domain\Keirin\Backtest\Calculators\Bt03e03ProbabilityScorer;
use App\Domain\Keirin\Backtest\Calculators\Bt03e06WinnerConditionedDecoder;
use App\Domain\Keirin\Backtest\Calculators\Bt03e07AcceptanceGate;
use App\Domain\Keirin\Backtest\Calculators\Bt03e07MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e07PairedBootstrap;
use App\Domain\Keirin\Backtest\Calculators\Bt03e08FistaOptimizer;
use App\Domain\Keirin\Backtest\Calculators\Bt03e08OneSeSelector;
use App\Domain\Keirin\Backtest\Calculators\Bt03e08P1Q2FrozenDecoder;
use App\Domain\Keirin\Backtest\Calculators\Bt03e08WinnerConditionedP3Objective;
use App\Domain\Keirin\Backtest\Calculators\Bt03e08WinnerConditionedP3Scorer;
use App\Domain\Keirin\Backtest\DTO\Bt03e06ReconstructedModelDto;
use App\Domain\Keirin\Backtest\DTO\Bt03e08FitResultDto;
use App\Domain\Keirin\Backtest\Repositories\Bt03eRuleSourceRepository;
use App\Domain\Keirin\Backtest\Support\Bt03e02RaceSpool;
use App\Domain\Keirin\Backtest\Support\Bt03e05RaceSpool;
use App\Domain\Keirin\Backtest\Support\Bt03e06MetricContributionSpool;
use App\Domain\Keirin\Backtest\Support\Bt03e06RaceSpool;
use App\Domain\Keirin\Backtest\Support\Bt03e08PredictionManifestAccumulator;
use App\Domain\Keirin\Backtest\Support\Bt03e08ValidationLossSpool;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use RuntimeException;
use Throwable;

final class Bt03e08DevelopmentEvaluationService
{
    /** @var list<Bt03e05RaceSpool> */
    private array $sourceSpools = [];

    /** @var list<Bt03e02RaceSpool|Bt03e06RaceSpool> */
    private array $raceSpools = [];

    /** @var list<Bt03e08ValidationLossSpool> */
    private array $lossSpools = [];

    /** @var list<Bt03e06MetricContributionSpool> */
    private array $metricSpools = [];

    public function __construct(
        private readonly Bt03e08SourceBundleLoader $sourceBundles,
        private readonly Bt03e08SourcePreflightService $preflight,
        private readonly Bt03e08TrainingDatasetBuilder $trainingDatasets,
        private readonly Bt03e08PredictionDatasetBuilder $predictionDatasets,
        private readonly Bt03e02ParameterLayoutBuilder $layouts,
        private readonly Bt03e08LayoutIdentityGuard $layoutIdentity,
        private readonly Bt03e08FistaOptimizer $optimizer,
        private readonly Bt03e08WinnerConditionedP3Objective $objective,
        private readonly Bt03e08OneSeSelector $oneSe,
        private readonly Bt03e08WinnerConditionedP3Scorer $p3Scorer,
        private readonly Bt03e08P1Q2FrozenDecoder $decoder,
        private readonly Bt03e06WinnerConditionedDecoder $frozenDecoder,
        private readonly Bt03e06ModelReconstructor $models,
        private readonly Bt03e03ProbabilityScorer $sourceScorer,
        private readonly Bt03e06ForwardReconstructionVerifier $forwardVerifier,
        private readonly Bt03eRuleSourceRepository $sourceRepository,
        private readonly Bt03eOutcomeSnapshotProvider $snapshots,
        private readonly Bt03e08OutcomeEvaluationLoader $outcomes,
        private readonly Bt03e07MetricEvaluator $metrics,
        private readonly Bt03e07PairedBootstrap $bootstrap,
        private readonly Bt03e07AcceptanceGate $acceptance,
        private readonly Bt03e02SourceIntegrityGuard $integrity,
        private readonly Bt03e08OutcomeSnapshotEndVerifier $endSnapshotVerifier,
        private readonly Bt03e08ReproducibilityVerifier $reproducibility,
        private readonly Bt03e08ArtifactWriter $artifacts,
        private readonly CanonicalHasher $hasher,
        private readonly Bt03e08ReadOnlyQueryAudit $audit,
        private readonly Bt03eReadOnlyDatabaseGuard $databaseGuard,
    ) {}

    /** @return array<string,mixed> */
    public function run(string $sourceBundle, string $outputDirectory = '/tmp', ?string $verifyReproducibility = null): array
    {
        $startedAt = hrtime(true);
        $runIdentity = 'bt03e08-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(16));
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
                throw new RuntimeException('BT-03E-08 outcome snapshot identity was invalid.');
            }
            $snapshotStart = $snapshot->auditParameters();
            $this->assertSourceSnapshot($sourceResult, $snapshotStart);

            $raw = [];
            foreach ([2022, 2023] as $year) {
                $raw[$year] = $this->trackRace($this->trainingDatasets->buildRaw($year, $snapshot, sys_get_temp_dir()));
            }
            $innerA = $this->fitGrid([$raw[2022]], [$raw[2023]], 'inner-a');
            $selection2024 = $this->selectLambda([2023 => $innerA]);
            $outer2024 = $this->fitOuter([$raw[2022], $raw[2023]], $source['years'][2024], $selection2024['lambda'], 'outer-2024', $sourceResult['outer_2024']['model']['bins'], $source);
            $this->audit->recordCandidateManifestSealed(2024);

            $raw[2024] = $this->trackRace($this->trainingDatasets->buildRaw(2024, $snapshot, sys_get_temp_dir()));
            $innerB = $this->fitGrid([$raw[2022], $raw[2023]], [$raw[2024]], 'inner-b', $sourceResult['outer_2024']['model']['bins']);
            $selection2025 = $this->selectLambda([2023 => $innerA, 2024 => $innerB]);
            $outer2025 = $this->fitOuter([$raw[2022], $raw[2023], $raw[2024]], $source['years'][2025], $selection2025['lambda'], 'outer-2025', $sourceResult['outer_2025']['model']['bins'], $source);
            $this->audit->recordCandidateManifestSealed(2025);

            $outer = [];
            $metricSpools = [];
            $predictionSpools = [2024 => $outer2024['predictions'], 2025 => $outer2025['predictions']];
            foreach (Bt03e08Contract::OUTER_YEARS as $year) {
                $context = $this->trackRace($this->outcomes->build($year, $snapshot, sys_get_temp_dir()));
                $metricSpool = $this->trackMetric(new Bt03e06MetricContributionSpool(sys_get_temp_dir().'/bt03e08-metrics-'.$year.'-'.bin2hex(random_bytes(8)).'.bin'));
                $metricSummary = $this->metrics->emptySummary();
                $this->evaluateYear($predictionSpools[$year], $context, function (array $decision, array $contextRace) use ($metricSpool, &$metricSummary): void {
                    $comparison = $this->metrics->raceComparison($contextRace, $decision);
                    $this->metrics->add($metricSummary, $comparison);
                    $metricSpool->append($comparison);
                });
                $metricSpool->seal();
                $metricSpools[$year] = $metricSpool;
                $outer[$year] = $this->metrics->finish($metricSummary);
            }
            $intervals = $this->bootstrap->evaluate($metricSpools);
            $snapshotEnd = $this->endSnapshotVerifier->verify($this->snapshots->open(storage_path('app/'.$snapshotPath), $snapshotPath), $this->audit);
            $this->integrity->assertUnchanged($snapshotStart, $snapshotEnd, 'BT-03E-08 outcome snapshot');
            $featureEnd = $this->preflight->run();
            $this->integrity->assertUnchanged($featureStart, $featureEnd, 'BT-03E-08 fixed feature source');
            $queryAudit = $this->audit->finish();
            $databaseAudit = $this->databaseGuard->rollback();
            $summary = [
                'run_identity' => $runIdentity, 'calculation_version' => Bt03e08Contract::CALCULATION_VERSION, 'contract' => Bt03e08Contract::plan(),
                'source_bundle_identity' => $source['identity'], 'source_bundle_runtime' => ['absolute_path' => realpath($sourceBundle) ?: $sourceBundle],
                'feature_source_integrity' => ['start' => $featureStart, 'end' => $featureEnd, 'unchanged' => true],
                'outcome_snapshot_identity' => ['start' => $snapshotStart, 'end' => $snapshotEnd, 'unchanged' => true],
                'fold_definitions' => Bt03e08Contract::plan()['outer_folds'], 'inner_layout_identities' => ['inner_a' => $innerA['layout_hash'], 'inner_b' => $innerB['layout_hash']],
                'outer_2024' => $this->outerArtifact($selection2024, $outer2024, $outer[2024]), 'outer_2025' => $this->outerArtifact($selection2025, $outer2025, $outer[2025]),
                'prediction_manifests' => [2024 => $outer2024['prediction_manifest'], 2025 => $outer2025['prediction_manifest']],
                'paired_bootstrap_ci' => $intervals, 'acceptance_gate_input' => ['outer_metrics' => $outer, 'paired_bootstrap_ci' => $intervals],
                'audit' => [...$queryAudit, ...$databaseAudit, 'source_drift' => false, 'partial_publication' => false, '2026_access_count' => 0],
            ];
            $hash = $this->reproducibility->hash($summary);
            $verification = $this->reproducibility->verify($verifyReproducibility, $hash);
            $gate = $this->acceptance->evaluate($outer, $intervals, $verification['verified']);
            if (! $verification['verified']) {
                $gate['status'] = 'REPRODUCIBILITY VERIFICATION REQUIRED';
            }
            $summary = [...$summary, 'reproducibility_hash' => $hash, 'reproducibility_verification' => $verification, 'acceptance_gate' => $gate, 'runtime' => ['seconds' => (hrtime(true) - $startedAt) / 1_000_000_000, 'peak_bytes' => memory_get_peak_usage(true), 'memory_contract_bytes' => 128 * 1024 * 1024]];
            $paths = $this->artifacts->write($outputDirectory, $summary, [2024 => $outer2024['predictions'], 2025 => $outer2025['predictions']]);

            return [...$summary, 'artifacts' => $paths];
        } catch (Throwable $throwable) {
            try {
                if ($this->audit->active()) {
                    $this->audit->finish();
                }
            } catch (Throwable) {
            }
            try {
                if ($this->databaseGuard->active()) {
                    $this->databaseGuard->rollback();
                }
            } catch (Throwable) {
            }
            throw $throwable;
        } finally {
            $this->cleanup();
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
        $losses = $this->trackLoss(new Bt03e08ValidationLossSpool(sys_get_temp_dir().'/bt03e08-validation-'.$role.'-'.bin2hex(random_bytes(8)).'.bin', array_keys($path['fits'])));
        foreach ($binnedValidation->races() as $race) {
            $raceLosses = [];
            foreach ($path['fits'] as $key => $fit) {
                $raceLosses[$key] = $this->objective->raceLoss($race, $layout, $fit->coefficients);
            } $losses->append($raceLosses);
        }
        $losses->seal();

        return ['fits' => $path['fits'], 'candidate_statuses' => $path['candidate_statuses'], 'fit_order' => $path['fit_order'], 'losses' => $losses, 'layout_hash' => $layoutHash];
    }

    /** @param array<int,array<string,mixed>> $inner @return array<string,mixed> */
    private function selectLambda(array $inner): array
    {
        $selection = $this->oneSe->select(array_map(static fn (array $fold): Bt03e08ValidationLossSpool => $fold['losses'], $inner));

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
        $sourceModel = $this->models->reconstruct($year, $sourceBundle['source_result']["outer_{$year}"]['model']);
        if ($sourceModel->layout->canonicalBins() !== $layout->canonicalBins()) {
            throw new RuntimeException('BT-03E-08 source Q2 and P3 layouts differed.');
        }
        $model = $this->modelArtifact($layout, $refit['fit'], $sourceBundle, $year, $sourceModel);
        $model['selection_evidence'] = ['selected_lambda' => $lambda, 'rule' => Bt03e08Contract::LAMBDA_SELECTION_VERSION];
        $model['refit_evidence'] = ['selected_lambda' => $refit['selected_lambda'], 'fit_order' => $refit['fit_order'], 'candidate_statuses' => $refit['candidate_statuses']];
        $sourceIdentity = ['source_reproducibility_hash' => $sourceBundle['identity']['source_reproducibility_hash'], 'source_artifact_manifest_sha256' => $sourceBundle['identity']['source_artifact_manifest_sha256'], 'source_prediction_manifest_sha256' => $sourceBundle['source_result']["outer_{$year}"]['prediction_manifest']['semantic_sha256'], 'source_model_canonical_sha256' => $sourceModel->canonicalHash, 'p3_model_sha256' => $this->hasher->hash($model)];
        $predictions = $this->trackRace(new Bt03e06RaceSpool('DECODER', sys_get_temp_dir().'/bt03e08-predictions-'.$year.'-'.bin2hex(random_bytes(8)).'.jsonl'));
        $manifest = new Bt03e08PredictionManifestAccumulator($year, $sourceIdentity, $this->hasher);
        $sourceIterator = $sourcePredictions->races();
        $binnedIterator = $predictionBinned->races();
        $sourceIterator->rewind();
        $binnedIterator->rewind();
        while ($sourceIterator->valid() && $binnedIterator->valid()) {
            $sourceRace = $sourceIterator->current();
            $binnedRace = $binnedIterator->current();
            $reconstructed = $this->sourceScorer->predict($binnedRace, $sourceModel->fit);
            $this->forwardVerifier->verifyRace($sourceRace, $reconstructed);
            $frozen = $this->frozenDecoder->decode($reconstructed);
            $p3 = $this->p3Scorer->predict($binnedRace, $refit['fit'], $frozen['primary_position_1_bike']);
            $decision = $this->decoder->decode($reconstructed, $p3);
            $manifest->append($decision);
            $predictions->append($decision);
            $sourceIterator->next();
            $binnedIterator->next();
        }
        if ($sourceIterator->valid() || $binnedIterator->valid()) {
            throw new RuntimeException('BT-03E-08 source and prediction race streams differed.');
        }
        $predictions->seal();

        return ['model' => $model, 'refit_path' => ['selected_lambda' => $refit['selected_lambda'], 'fit_order' => $refit['fit_order'], 'candidate_statuses' => $refit['candidate_statuses']], 'predictions' => $predictions, 'prediction_manifest' => $manifest->seal()];
    }

    /** @param array<string,mixed> $sourceBundle */
    private function modelArtifact(Bt03e02ParameterLayout $layout, Bt03e08FitResultDto $fit, array $sourceBundle, int $year, Bt03e06ReconstructedModelDto $sourceModel): array
    {
        return ['optimizer_version' => Bt03e08Contract::OPTIMIZER_VERSION, 'objective_version' => Bt03e08Contract::OBJECTIVE_VERSION, 'probability_version' => Bt03e08Contract::PROBABILITY_VERSION, 'lambda' => $fit->lambda, 'stat01_anchor_coefficient' => 1.0, 'bins' => $layout->canonicalBins(), 'p3_coefficients' => $fit->coefficients, 'weighted_center_means' => $layout->weightedMeans($fit->coefficients), 'objective' => $fit->objective, 'iterations' => $fit->iterations, 'eligible_races' => $fit->eligibleRaceCount, 'excluded_races' => $fit->excludedRaceCount, 'optimizer_diagnostics' => $fit->diagnostics, 'source_p1_q2_identity' => ['source_reproducibility_hash' => $sourceBundle['identity']['source_reproducibility_hash'], 'source_prediction_manifest_sha256' => $sourceBundle['source_result']["outer_{$year}"]['prediction_manifest']['semantic_sha256'], 'source_model_canonical_sha256' => $sourceModel->canonicalHash]];
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
                throw new RuntimeException('BT-03E-08 candidate and outcome race cohorts differed.');
            } $consumer($left->current(), $right->current());
            $left->next();
            $right->next();
        }
        if ($left->valid() || $right->valid()) {
            throw new RuntimeException('BT-03E-08 candidate and outcome race counts differed.');
        }
    }

    /** @param array<string,mixed> $sourceResult @param array<string,mixed> $feature */
    private function assertSourceFeatures(array $sourceResult, array $feature): void
    {
        $records = $sourceResult['source_integrity']['start']['fingerprints'] ?? null;
        if (! is_array($records)) {
            throw new RuntimeException('BT-03E-08 source feature fingerprint evidence was unavailable.');
        }
        $normalize = static fn (array $record): array => array_intersect_key($record, array_flip(['year', 'stat_code', 'feature_run_id', 'source_fingerprint_sha256', 'content_fingerprint_sha256']));
        $sourceFixed = array_values(array_map($normalize, array_filter($records, static fn (mixed $record): bool => is_array($record) && in_array($record['year'] ?? null, Bt03e08Contract::DEVELOPMENT_YEARS, true) && in_array($record['stat_code'] ?? null, ['STAT-01', ...Bt03e08Contract::STAT_CODES], true))));
        if ($sourceFixed !== array_map($normalize, $feature['fingerprints'])) {
            throw new RuntimeException('BT-03E-08 source artifact and current fixed feature fingerprints differed.');
        }
    }

    /** @param array<string,mixed> $sourceResult @param array<string,mixed> $snapshot */
    private function assertSourceSnapshot(array $sourceResult, array $snapshot): void
    {
        if (! is_array($sourceResult['outcome_snapshot']['start'] ?? null) || $sourceResult['outcome_snapshot']['start'] !== $snapshot) {
            throw new RuntimeException('BT-03E-08 source and current outcome snapshot identities differed.');
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

    private function trackLoss(Bt03e08ValidationLossSpool $spool): Bt03e08ValidationLossSpool
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

    private function cleanup(): void
    {
        foreach ($this->sourceSpools as $spool) {
            $spool->cleanup();
        } foreach ($this->raceSpools as $spool) {
            $spool->cleanup();
        } foreach ($this->lossSpools as $spool) {
            $spool->cleanup();
        } foreach ($this->metricSpools as $spool) {
            $spool->cleanup();
        } $this->sourceSpools = $this->raceSpools = $this->lossSpools = $this->metricSpools = [];
    }
}
