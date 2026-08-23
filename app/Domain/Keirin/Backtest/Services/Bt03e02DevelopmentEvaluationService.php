<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Calculators\Bt03e02AcceptanceGate;
use App\Domain\Keirin\Backtest\Calculators\Bt03e02AlphaSelector;
use App\Domain\Keirin\Backtest\Calculators\Bt03e02FistaOptimizer;
use App\Domain\Keirin\Backtest\Calculators\Bt03e02MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e02OneSeSelector;
use App\Domain\Keirin\Backtest\Calculators\Bt03e02PairedBootstrap;
use App\Domain\Keirin\Backtest\Calculators\Bt03e02PairwiseObjective;
use App\Domain\Keirin\Backtest\Calculators\Bt03e02ParameterLayout;
use App\Domain\Keirin\Backtest\Calculators\Bt03e02ParameterLayoutBuilder;
use App\Domain\Keirin\Backtest\Calculators\Bt03e02Scorer;
use App\Domain\Keirin\Backtest\DTO\Bt03e02FitResultDto;
use App\Domain\Keirin\Backtest\Repositories\Bt03eRuleSourceRepository;
use App\Domain\Keirin\Backtest\Support\Bt03e02RaceSpool;
use App\Domain\Keirin\Backtest\Support\Bt03e02ValidationLossSpool;
use RuntimeException;
use Throwable;

class Bt03e02DevelopmentEvaluationService
{
    /** @var list<Bt03e02RaceSpool> */
    private array $temporarySpools = [];

    /** @var list<Bt03e02ValidationLossSpool> */
    private array $temporaryLossSpools = [];

    public function __construct(
        private readonly Bt03eRuleSourceRepository $sourceRepository,
        private readonly Bt03eOutcomeSnapshotProvider $snapshots,
        private readonly Bt03e02SourcePreflightService $preflight,
        private readonly Bt03e02SourceIntegrityGuard $sourceIntegrity,
        private readonly Bt03e02DatasetBuilder $datasets,
        private readonly Bt03e02ParameterLayoutBuilder $layouts,
        private readonly Bt03e02FistaOptimizer $optimizer,
        private readonly Bt03e02PairwiseObjective $objective,
        private readonly Bt03e02Scorer $scorer,
        private readonly Bt03e02AlphaSelector $alphaSelector,
        private readonly Bt03e02MetricEvaluator $metrics,
        private readonly Bt03e02OneSeSelector $oneSe,
        private readonly Bt03e02PairedBootstrap $bootstrap,
        private readonly Bt03e02AcceptanceGate $acceptance,
        private readonly Bt03e02ReproducibilityVerifier $reproducibility,
        private readonly Bt03e02ArtifactWriter $artifacts,
        private readonly Bt03e02ReadOnlyQueryAudit $queryAudit,
        private readonly Bt03eReadOnlyDatabaseGuard $databaseGuard,
    ) {}

    /** @return array<string,mixed> */
    public function run(string $outputDirectory = '/tmp', ?string $verifyReproducibility = null): array
    {
        $startedAt = hrtime(true);
        $runIdentity = 'bt03e02-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(16));
        $this->queryAudit->start();
        try {
            $this->databaseGuard->begin();
            $sourceStart = $this->preflight->run();
            $snapshotPath = $this->sourceRepository->outcomeSnapshotPath();
            $snapshot = $this->snapshots->open(storage_path('app/'.$snapshotPath), $snapshotPath);
            if (! hash_equals(Bt03SourceManifest::OUTCOME_SNAPSHOT_MANIFEST_HASH, $snapshot->manifestHash())) {
                throw new RuntimeException('BT-03E-02 outcome snapshot identity was invalid.');
            }
            $snapshotStart = $snapshot->auditParameters();
            $raw = [];
            foreach ([2022, 2023] as $year) {
                $this->queryAudit->recordSnapshotYear($year);
                $raw[$year] = $this->track($this->datasets->buildRaw($year, $snapshot, sys_get_temp_dir()));
            }

            $innerA = $this->fitGrid([$raw[2022]], [$raw[2023]], 'inner-a');
            $outer2024Selection = $this->oneSe->select([2023 => $innerA['losses']]);
            $outer2024Alpha = $this->selectAlpha([2023 => $innerA], $outer2024Selection['lambda'], 'outer-2024-inner');
            $this->queryAudit->recordCandidateFrozen(2024);
            $this->queryAudit->recordSnapshotYear(2024);
            $raw[2024] = $this->track($this->datasets->buildRaw(2024, $snapshot, sys_get_temp_dir()));
            $outer2024 = $this->fitOuter([$raw[2022], $raw[2023]], $raw[2024], $outer2024Selection['lambda'], $outer2024Alpha['alpha'], 'outer-2024');

            $innerB = $this->fitGrid([$raw[2022], $raw[2023]], [$raw[2024]], 'inner-b');
            $outer2025Selection = $this->oneSe->select([
                2023 => $innerA['losses'],
                2024 => $innerB['losses'],
            ]);
            $outer2025Alpha = $this->selectAlpha([2023 => $innerA, 2024 => $innerB], $outer2025Selection['lambda'], 'outer-2025-inner');
            $this->queryAudit->recordCandidateFrozen(2025);
            $this->queryAudit->recordSnapshotYear(2025);
            $raw[2025] = $this->track($this->datasets->buildRaw(2025, $snapshot, sys_get_temp_dir()));
            $outer2025 = $this->fitOuter([$raw[2022], $raw[2023], $raw[2024]], $raw[2025], $outer2025Selection['lambda'], $outer2025Alpha['alpha'], 'outer-2025');

            $intervals = $this->bootstrap->evaluate([
                2024 => [
                    'source' => $this->source([$outer2024['predictions']]),
                    'race_count' => $outer2024['predictions']->metadata()['race_count'],
                    'alpha' => $outer2024Alpha['alpha'],
                ],
                2025 => [
                    'source' => $this->source([$outer2025['predictions']]),
                    'race_count' => $outer2025['predictions']->metadata()['race_count'],
                    'alpha' => $outer2025Alpha['alpha'],
                ],
            ]);
            $outerResults = [2024 => $outer2024['metrics'], 2025 => $outer2025['metrics']];
            foreach (Bt03e02Contract::DEVELOPMENT_YEARS as $year) {
                $this->queryAudit->recordSnapshotYear($year);
                $snapshot->verifyPartition($year);
            }
            $snapshotEnd = $this->snapshots->open(storage_path('app/'.$snapshotPath), $snapshotPath)->auditParameters();
            $this->sourceIntegrity->assertUnchanged($snapshotStart, $snapshotEnd, 'outcome snapshot');
            $sourceEnd = $this->preflight->run();
            $this->sourceIntegrity->assertUnchanged($sourceStart, $sourceEnd, 'feature source fingerprints');
            $queryAudit = $this->queryAudit->finish();
            $databaseAudit = $this->databaseGuard->rollback();
            $summary = [
                'run_identity' => $runIdentity,
                'calculation_version' => Bt03e02Contract::CALCULATION_VERSION,
                'contract' => Bt03e02Contract::plan(),
                'source_integrity' => ['start' => $sourceStart, 'end' => $sourceEnd, 'unchanged' => true],
                'outcome_snapshot' => ['start' => $snapshotStart, 'end' => $snapshotEnd, 'unchanged' => true],
                'outer_2024' => [
                    'lambda_selection' => $outer2024Selection,
                    'alpha_selection' => $outer2024Alpha,
                    'model' => $this->modelArtifact($outer2024['layout'], $outer2024['fit'], $outer2024['scales']),
                    'metrics' => $outer2024['metrics'],
                ],
                'outer_2025' => [
                    'lambda_selection' => $outer2025Selection,
                    'alpha_selection' => $outer2025Alpha,
                    'model' => $this->modelArtifact($outer2025['layout'], $outer2025['fit'], $outer2025['scales']),
                    'metrics' => $outer2025['metrics'],
                ],
                'paired_bootstrap_ci' => $intervals,
                'audit' => [
                    ...$queryAudit,
                    ...$databaseAudit,
                    'source_drift' => false,
                    'partial_publication' => false,
                    '2026_access_count' => 0,
                ],
            ];
            $reproducibilityHash = $this->reproducibility->hash($summary);
            $verification = $this->reproducibility->verify($verifyReproducibility, $reproducibilityHash);
            $gate = $this->acceptance->evaluate($outerResults, $intervals, $verification['verified']);
            if (! $verification['verified']) {
                $gate['status'] = 'REPRODUCIBILITY VERIFICATION REQUIRED';
            }
            $summary = [
                ...$summary,
                'reproducibility_hash' => $reproducibilityHash,
                'reproducibility_verification' => $verification,
                'acceptance_gate' => $gate,
                'runtime' => [
                    'seconds' => (hrtime(true) - $startedAt) / 1_000_000_000,
                    'peak_bytes' => memory_get_peak_usage(true),
                    'memory_contract_bytes' => 128 * 1024 * 1024,
                ],
            ];
            $paths = $this->artifacts->write($outputDirectory, $summary);

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

    /**
     * @param  list<Bt03e02RaceSpool>  $training
     * @param  list<Bt03e02RaceSpool>  $validation
     * @return array{layout:Bt03e02ParameterLayout,training:Bt03e02RaceSpool,validation:Bt03e02RaceSpool,fits:array<string,Bt03e02FitResultDto>,losses:Bt03e02ValidationLossSpool}
     */
    private function fitGrid(array $training, array $validation, string $role): array
    {
        $layout = $this->layouts->build($this->source($training));
        $binnedTraining = $this->track($this->datasets->buildBinned($training, $layout, sys_get_temp_dir(), $role.'-training'));
        $binnedValidation = $this->track($this->datasets->buildBinned($validation, $layout, sys_get_temp_dir(), $role.'-validation'));
        $fits = [];
        foreach (Bt03e02Contract::LAMBDA_GRID as $lambda) {
            $key = $this->lambdaKey($lambda);
            $fits[$key] = $this->optimizer->fit($this->source([$binnedTraining]), $layout, $lambda);
        }
        $losses = $this->trackLoss(new Bt03e02ValidationLossSpool(
            sys_get_temp_dir().'/bt03e02-validation-loss-'.$role.'-'.bin2hex(random_bytes(8)).'.bin',
        ));
        foreach ($binnedValidation->races() as $race) {
            $raceLosses = [];
            foreach ($fits as $key => $fit) {
                foreach (Bt03e02Contract::CHANNELS as $channel) {
                    $raceLosses[$key][$channel] = $this->objective->raceLoss(
                        $race,
                        $layout,
                        $fit->coefficients[$channel],
                        $channel,
                    );
                }
            }
            $losses->append($raceLosses);
        }
        $losses->seal();

        return ['layout' => $layout, 'training' => $binnedTraining, 'validation' => $binnedValidation, 'fits' => $fits, 'losses' => $losses];
    }

    /** @param array<int,array<string,mixed>> $inner */
    private function selectAlpha(array $inner, float $lambda, string $role): array
    {
        $predictions = [];
        $degenerate = [];
        foreach ($inner as $year => $fold) {
            $fit = $fold['fits'][$this->lambdaKey($lambda)];
            $scales = $this->scorer->trainingScales($this->source([$fold['training']]), $fit);
            foreach ($scales as $channel => $scale) {
                if ($scale['status'] === 'DEGENERATE_CHANNEL') {
                    $degenerate[$channel] = true;
                }
            }
            $predictions[$year] = $this->predictionSpool($fold['validation'], $fit, $scales, $role.'-'.$year);
        }

        return $this->alphaSelector->select(
            array_map(fn (Bt03e02RaceSpool $spool): callable => $this->source([$spool]), $predictions),
            array_keys($degenerate),
        );
    }

    /** @param list<Bt03e02RaceSpool> $training @return array<string,mixed> */
    private function fitOuter(array $training, Bt03e02RaceSpool $validation, float $lambda, array $alpha, string $role): array
    {
        $layout = $this->layouts->build($this->source($training));
        $binnedTraining = $this->track($this->datasets->buildBinned($training, $layout, sys_get_temp_dir(), $role.'-training'));
        $binnedValidation = $this->track($this->datasets->buildBinned([$validation], $layout, sys_get_temp_dir(), $role.'-validation'));
        $fit = $this->optimizer->fit($this->source([$binnedTraining]), $layout, $lambda);
        $scales = $this->scorer->trainingScales($this->source([$binnedTraining]), $fit);
        $predictions = $this->predictionSpool($binnedValidation, $fit, $scales, $role);

        return [
            'layout' => $layout,
            'fit' => $fit,
            'scales' => $scales,
            'predictions' => $predictions,
            'metrics' => $this->metrics->evaluatePaired($this->source([$predictions]), $alpha),
        ];
    }

    private function predictionSpool(Bt03e02RaceSpool $source, Bt03e02FitResultDto $fit, array $scales, string $role): Bt03e02RaceSpool
    {
        $spool = $this->track(new Bt03e02RaceSpool('PREDICTION', sys_get_temp_dir().'/bt03e02-prediction-'.$role.'-'.bin2hex(random_bytes(8)).'.jsonl'));
        foreach ($source->races() as $race) {
            $spool->append([
                'year' => $race['year'],
                'race_id' => $race['race_id'],
                'entries' => $this->scorer->predictions($race, $fit, $scales),
            ]);
        }
        $spool->seal();

        return $spool;
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

    private function trackLoss(Bt03e02ValidationLossSpool $spool): Bt03e02ValidationLossSpool
    {
        $this->temporaryLossSpools[] = $spool;

        return $spool;
    }

    /** @return array<string,mixed> */
    private function modelArtifact(Bt03e02ParameterLayout $layout, Bt03e02FitResultDto $fit, array $scales): array
    {
        return [
            'optimizer_version' => Bt03e02Contract::OPTIMIZER_VERSION,
            'centering_version' => Bt03e02Contract::CENTERING_VERSION,
            'normalization_version' => Bt03e02Contract::NORMALIZATION_VERSION,
            'summation_version' => Bt03e02Contract::SUMMATION_VERSION,
            'tie_rule_version' => Bt03e02Contract::TIE_RULE_VERSION,
            'lambda' => $fit->lambda,
            'bins' => $layout->canonicalBins(),
            'coefficients' => $fit->coefficients,
            'weighted_center_means' => array_map(fn (array $values): array => $layout->weightedMeans($values), $fit->coefficients),
            'channel_scales' => $scales,
            'objectives' => $fit->objectives,
            'iterations' => $fit->iterations,
            'pairwise_eligible_races' => $fit->eligibleRaceCounts,
            'pairwise_excluded_races' => $fit->excludedRaceCounts,
        ];
    }

    private function lambdaKey(float $lambda): string
    {
        return sprintf('%.17g', $lambda);
    }
}
