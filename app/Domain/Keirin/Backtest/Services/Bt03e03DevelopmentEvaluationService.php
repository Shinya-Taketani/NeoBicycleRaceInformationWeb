<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Calculators\Bt03e02ParameterLayout;
use App\Domain\Keirin\Backtest\Calculators\Bt03e02ParameterLayoutBuilder;
use App\Domain\Keirin\Backtest\Calculators\Bt03e03AcceptanceGate;
use App\Domain\Keirin\Backtest\Calculators\Bt03e03ConditionalSoftmaxObjective;
use App\Domain\Keirin\Backtest\Calculators\Bt03e03FistaOptimizer;
use App\Domain\Keirin\Backtest\Calculators\Bt03e03MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e03OneSeSelector;
use App\Domain\Keirin\Backtest\Calculators\Bt03e03PairedBootstrap;
use App\Domain\Keirin\Backtest\Calculators\Bt03e03ProbabilityScorer;
use App\Domain\Keirin\Backtest\DTO\Bt03e03FitResultDto;
use App\Domain\Keirin\Backtest\Repositories\Bt03eRuleSourceRepository;
use App\Domain\Keirin\Backtest\Support\Bt03e02RaceSpool;
use App\Domain\Keirin\Backtest\Support\Bt03e03PredictionManifestAccumulator;
use App\Domain\Keirin\Backtest\Support\Bt03e03ValidationLossSpool;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use RuntimeException;
use Throwable;

class Bt03e03DevelopmentEvaluationService
{
    /** @var list<Bt03e02RaceSpool> */
    private array $temporarySpools = [];

    /** @var list<Bt03e03ValidationLossSpool> */
    private array $temporaryLossSpools = [];

    public function __construct(
        private readonly Bt03eRuleSourceRepository $sourceRepository,
        private readonly Bt03eOutcomeSnapshotProvider $snapshots,
        private readonly Bt03e02SourcePreflightService $preflight,
        private readonly Bt03e02SourceIntegrityGuard $sourceIntegrity,
        private readonly Bt03e02DatasetBuilder $datasets,
        private readonly Bt03e02ParameterLayoutBuilder $layouts,
        private readonly Bt03e03FistaOptimizer $optimizer,
        private readonly Bt03e03ConditionalSoftmaxObjective $objective,
        private readonly Bt03e03ProbabilityScorer $scorer,
        private readonly Bt03e03MetricEvaluator $metrics,
        private readonly Bt03e03OneSeSelector $oneSe,
        private readonly Bt03e03PairedBootstrap $bootstrap,
        private readonly Bt03e03AcceptanceGate $acceptance,
        private readonly Bt03e03ReproducibilityVerifier $reproducibility,
        private readonly Bt03e03ArtifactWriter $artifacts,
        private readonly CanonicalHasher $canonicalHasher,
        private readonly Bt03e02ReadOnlyQueryAudit $queryAudit,
        private readonly Bt03eReadOnlyDatabaseGuard $databaseGuard,
    ) {}

    /** @return array<string,mixed> */
    public function run(string $outputDirectory = '/tmp', ?string $verifyReproducibility = null): array
    {
        $startedAt = hrtime(true);
        $runIdentity = 'bt03e03-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(16));
        $this->queryAudit->start();
        try {
            $this->databaseGuard->begin();
            $sourceStart = $this->preflight->run();
            $snapshotPath = $this->sourceRepository->outcomeSnapshotPath();
            $snapshot = $this->snapshots->open(storage_path('app/'.$snapshotPath), $snapshotPath);
            if (! hash_equals(Bt03SourceManifest::OUTCOME_SNAPSHOT_MANIFEST_HASH, $snapshot->manifestHash())) {
                throw new RuntimeException('BT-03E-03 outcome snapshot identity was invalid.');
            }
            $snapshotStart = $snapshot->auditParameters();
            $raw = [];
            foreach ([2022, 2023] as $year) {
                $this->queryAudit->recordSnapshotYear($year);
                $raw[$year] = $this->track($this->datasets->buildRaw($year, $snapshot, sys_get_temp_dir()));
            }

            $innerA = $this->fitGrid([$raw[2022]], [$raw[2023]], 'inner-a');
            $outer2024Selection = $this->selectLambda([2023 => $innerA]);
            $this->queryAudit->recordCandidateFrozen(2024);
            $this->queryAudit->recordSnapshotYear(2024);
            $raw[2024] = $this->track($this->datasets->buildRaw(2024, $snapshot, sys_get_temp_dir()));
            $outer2024 = $this->fitOuter(
                [$raw[2022], $raw[2023]],
                $raw[2024],
                $outer2024Selection['lambda'],
                'outer-2024',
            );

            $innerB = $this->fitGrid([$raw[2022], $raw[2023]], [$raw[2024]], 'inner-b');
            $outer2025Selection = $this->selectLambda([2023 => $innerA, 2024 => $innerB]);
            $this->queryAudit->recordCandidateFrozen(2025);
            $this->queryAudit->recordSnapshotYear(2025);
            $raw[2025] = $this->track($this->datasets->buildRaw(2025, $snapshot, sys_get_temp_dir()));
            $outer2025 = $this->fitOuter(
                [$raw[2022], $raw[2023], $raw[2024]],
                $raw[2025],
                $outer2025Selection['lambda'],
                'outer-2025',
            );

            $intervals = $this->bootstrap->evaluate([
                2024 => ['source' => $this->source([$outer2024['predictions']]), 'race_count' => $outer2024['predictions']->metadata()['race_count']],
                2025 => ['source' => $this->source([$outer2025['predictions']]), 'race_count' => $outer2025['predictions']->metadata()['race_count']],
            ]);
            $outerResults = [2024 => $outer2024['metrics'], 2025 => $outer2025['metrics']];
            foreach (Bt03e03Contract::DEVELOPMENT_YEARS as $year) {
                $this->queryAudit->recordSnapshotYear($year);
                $snapshot->verifyPartition($year);
            }
            $snapshotEnd = $this->snapshots->open(storage_path('app/'.$snapshotPath), $snapshotPath)->auditParameters();
            $this->sourceIntegrity->assertUnchanged($snapshotStart, $snapshotEnd, 'BT-03E-03 outcome snapshot');
            $sourceEnd = $this->preflight->run();
            $this->sourceIntegrity->assertUnchanged($sourceStart, $sourceEnd, 'BT-03E-03 feature source fingerprints');
            $queryAudit = $this->queryAudit->finish();
            $databaseAudit = $this->databaseGuard->rollback();
            $summary = [
                'run_identity' => $runIdentity,
                'calculation_version' => Bt03e03Contract::CALCULATION_VERSION,
                'contract' => Bt03e03Contract::plan(),
                'source_integrity' => ['start' => $sourceStart, 'end' => $sourceEnd, 'unchanged' => true],
                'outcome_snapshot' => ['start' => $snapshotStart, 'end' => $snapshotEnd, 'unchanged' => true],
                'outer_2024' => $this->outerArtifact($outer2024Selection, $outer2024),
                'outer_2025' => $this->outerArtifact($outer2025Selection, $outer2025),
                'paired_bootstrap_ci' => $intervals,
                'acceptance_gate_input' => [
                    'outer_metrics' => $outerResults,
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
            $gate = $this->acceptance->evaluate($outerResults, $intervals, $verification['verified']);
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
            $paths = $this->artifacts->write($outputDirectory, $summary, [
                2024 => $outer2024['predictions'],
                2025 => $outer2025['predictions'],
            ]);

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
            foreach ($this->temporarySpools as $spool) {
                $spool->cleanup();
            }
            $this->temporarySpools = [];
            foreach ($this->temporaryLossSpools as $spool) {
                $spool->cleanup();
            }
            $this->temporaryLossSpools = [];
        }
    }

    /** @param list<Bt03e02RaceSpool> $training @param list<Bt03e02RaceSpool> $validation @return array<string,mixed> */
    private function fitGrid(array $training, array $validation, string $role): array
    {
        $layout = $this->layouts->build($this->source($training));
        $binnedTraining = $this->track($this->datasets->buildBinned($training, $layout, sys_get_temp_dir(), $role.'-training'));
        $binnedValidation = $this->track($this->datasets->buildBinned($validation, $layout, sys_get_temp_dir(), $role.'-validation'));
        $path = $this->optimizer->fitPath($this->source([$binnedTraining]), $layout);
        $losses = $this->trackLoss(new Bt03e03ValidationLossSpool(
            sys_get_temp_dir().'/bt03e03-validation-loss-'.$role.'-'.bin2hex(random_bytes(8)).'.bin',
            array_keys($path['fits']),
        ));
        foreach ($binnedValidation->races() as $race) {
            $raceLosses = [];
            foreach ($path['fits'] as $key => $fit) {
                foreach (Bt03e03Contract::POSITIONS as $position) {
                    $raceLosses[$key][$position] = $this->objective->raceLoss($race, $layout, $fit->coefficients[$position], $position);
                }
            }
            $losses->append($raceLosses);
        }
        $losses->seal();

        return [
            'fits' => $path['fits'],
            'candidate_statuses' => $path['candidate_statuses'],
            'fit_order' => $path['fit_order'],
            'losses' => $losses,
        ];
    }

    /** @param array<int,array<string,mixed>> $inner @return array<string,mixed> */
    private function selectLambda(array $inner): array
    {
        $selection = $this->oneSe->select(array_map(
            static fn (array $fold): Bt03e03ValidationLossSpool => $fold['losses'],
            $inner,
        ));

        return [
            ...$selection,
            'fit_order' => array_map(static fn (array $fold): array => $fold['fit_order'], $inner),
            'candidate_statuses' => array_map(static fn (array $fold): array => $fold['candidate_statuses'], $inner),
        ];
    }

    /** @param list<Bt03e02RaceSpool> $training @return array<string,mixed> */
    private function fitOuter(array $training, Bt03e02RaceSpool $validation, float $lambda, string $role): array
    {
        $layout = $this->layouts->build($this->source($training));
        $binnedTraining = $this->track($this->datasets->buildBinned($training, $layout, sys_get_temp_dir(), $role.'-training'));
        $binnedValidation = $this->track($this->datasets->buildBinned([$validation], $layout, sys_get_temp_dir(), $role.'-validation'));
        $refitPath = $this->optimizer->fitSelectedViaPath($this->source([$binnedTraining]), $layout, $lambda);
        $predictions = $this->predictionSpool($binnedValidation, $refitPath['fit'], $role);

        return [
            'layout' => $layout,
            'fit' => $refitPath['fit'],
            'refit_path' => [
                'selected_lambda' => $refitPath['selected_lambda'],
                'fit_order' => $refitPath['fit_order'],
                'candidate_statuses' => $refitPath['candidate_statuses'],
            ],
            'predictions' => $predictions['spool'],
            'prediction_manifest' => $predictions['manifest'],
            'metrics' => $this->metrics->evaluate($this->source([$predictions['spool']])),
        ];
    }

    /** @return array{spool:Bt03e02RaceSpool,manifest:array{version:string,race_count:int,entry_count:int,semantic_sha256:string}} */
    private function predictionSpool(Bt03e02RaceSpool $source, Bt03e03FitResultDto $fit, string $role): array
    {
        $spool = $this->track(new Bt03e02RaceSpool(
            'PREDICTION',
            sys_get_temp_dir().'/bt03e03-prediction-'.$role.'-'.bin2hex(random_bytes(8)).'.jsonl',
        ));
        $manifest = new Bt03e03PredictionManifestAccumulator($this->canonicalHasher);
        foreach ($source->races() as $race) {
            $prediction = $this->scorer->predict($race, $fit);
            $manifest->append($prediction);
            $spool->append($prediction);
        }
        $spool->seal();

        return ['spool' => $spool, 'manifest' => $manifest->seal()];
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

    private function track(Bt03e02RaceSpool $spool): Bt03e02RaceSpool
    {
        $this->temporarySpools[] = $spool;

        return $spool;
    }

    private function trackLoss(Bt03e03ValidationLossSpool $spool): Bt03e03ValidationLossSpool
    {
        $this->temporaryLossSpools[] = $spool;

        return $spool;
    }

    /** @return array<string,mixed> */
    private function outerArtifact(array $selection, array $outer): array
    {
        return [
            'lambda_selection' => $selection,
            'model' => $this->modelArtifact($outer['layout'], $outer['fit']),
            'refit_path' => $outer['refit_path'],
            'metrics' => $outer['metrics'],
            'probability_metrics' => $outer['metrics']['probability_metrics'],
            'calibration' => $outer['metrics']['calibration'],
            'map_diagnostics' => $outer['metrics']['map_diagnostics'],
            'prediction_manifest' => $outer['prediction_manifest'],
        ];
    }

    /** @return array<string,mixed> */
    private function modelArtifact(Bt03e02ParameterLayout $layout, Bt03e03FitResultDto $fit): array
    {
        return [
            'optimizer_version' => Bt03e03Contract::OPTIMIZER_VERSION,
            'probability_version' => Bt03e03Contract::PROBABILITY_VERSION,
            'tie_rule_version' => Bt03e03Contract::TIE_RULE_VERSION,
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
