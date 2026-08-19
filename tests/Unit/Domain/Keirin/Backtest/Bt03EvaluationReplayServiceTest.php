<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03BinEffectCalculator;
use App\Domain\Keirin\Backtest\Calculators\Bt03CenteredBaselineResidualCalculator;
use App\Domain\Keirin\Backtest\Calculators\Bt03FixedBinAssigner;
use App\Domain\Keirin\Backtest\Calculators\Bt03StoredModelReplayer;
use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\Calculators\RaceClusterBootstrap;
use App\Domain\Keirin\Backtest\Calculators\RidgeLogisticRegression;
use App\Domain\Keirin\Backtest\Calculators\Type7Quantile;
use App\Domain\Keirin\Backtest\Contracts\Bt02EvaluationDataset;
use App\Domain\Keirin\Backtest\Contracts\Bt03EvaluationSourceProvider;
use App\Domain\Keirin\Backtest\Contracts\EffectBinBoundaryProvider;
use App\Domain\Keirin\Backtest\DTO\Bt02BinaryLabelsDto;
use App\Domain\Keirin\Backtest\DTO\Bt02EvaluationRowDto;
use App\Domain\Keirin\Backtest\DTO\Bt03ComputedBinEffectDto;
use App\Domain\Keirin\Backtest\DTO\Bt03EvaluationReplaySelectionDto;
use App\Domain\Keirin\Backtest\DTO\Bt03EvaluationSourceDto;
use App\Domain\Keirin\Backtest\DTO\Bt03ExpectedPredictionManifestsDto;
use App\Domain\Keirin\Backtest\DTO\Bt03ModelPairDto;
use App\Domain\Keirin\Backtest\DTO\Bt03SourceBinDto;
use App\Domain\Keirin\Backtest\DTO\Bt03StoredModelDto;
use App\Domain\Keirin\Backtest\Enums\Bt02SignalCohort;
use App\Domain\Keirin\Backtest\Services\Bt02BaselineFingerprintManifest;
use App\Domain\Keirin\Backtest\Services\Bt02SourceManifest;
use App\Domain\Keirin\Backtest\Services\Bt03EvaluationReplayService;
use App\Domain\Keirin\Backtest\Services\Bt03SourceManifest;
use App\Domain\Keirin\Backtest\Support\Bt02ModelArtifactHasher;
use App\Domain\Keirin\Backtest\Support\Bt02PredictionManifestAccumulator;
use App\Domain\Keirin\Backtest\Support\Bt03EffectHasher;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class Bt03EvaluationReplayServiceTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/bt03-evaluation-replay-test-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_it_replays_probabilities_separates_labels_and_preserves_race_clusters(): void
    {
        $source = $this->numericSource();
        $rows = [
            $this->row(10, 101, -1.0, true, true, true),
            $this->row(10, 102, -0.5, false, true, true),
            $this->row(11, 103, 1.0, false, false, true),
        ];

        $summary = $this->service($source, $rows)->replay('WF_2023', 'STAT-07', 'STRICT', 25, 20260812);

        $this->assertSame(3, $summary->evaluationRowCount);
        $this->assertSame(2, $summary->evaluationRaceCount);
        $this->assertSame(2, $summary->trainingBinCount);
        $this->assertSame(9, $summary->spoolFileCount);
        $this->assertCount(6, $summary->effects);
        $winFirstBin = $this->effect($summary->effects, 1, 'IS_WIN');
        $top2FirstBin = $this->effect($summary->effects, 1, 'IS_TOP2');
        $top3FirstBin = $this->effect($summary->effects, 1, 'IS_TOP3');
        $this->assertSame(2, $winFirstBin->result->evaluationSampleCount);
        $this->assertSame(1, $winFirstBin->result->evaluationRaceCount);
        $this->assertSame(1, $winFirstBin->result->positiveCount);
        $this->assertSame(2, $top2FirstBin->result->positiveCount);
        $this->assertSame(2, $top3FirstBin->result->positiveCount);
        $this->assertNotEquals(
            $winFirstBin->result->baselineMeanProbability,
            $winFirstBin->result->incrementalMeanProbability,
        );
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $winFirstBin->effectHash);
        foreach (Bt03EvaluationReplayService::LABELS as $label) {
            $overall = array_map(
                fn (Bt03ComputedBinEffectDto $effect): string => sprintf('%.17g', $effect->centered->overallBaselineResidualMean),
                array_values(array_filter($summary->effects, fn (Bt03ComputedBinEffectDto $effect): bool => $effect->labelCode === $label)),
            );
            $this->assertCount(1, array_unique($overall));
        }
        $this->assertSame([], glob($this->directory.'/*') ?: []);
    }

    public function test_empty_training_bin_is_preserved_as_no_evaluation_rows(): void
    {
        $source = $this->numericSource([
            $this->bin(9001, 1, null, -1.0),
            $this->bin(9002, 2, -1.0, 1.0),
            $this->bin(9003, 3, 1.0, null),
        ]);
        $summary = $this->service($source, [
            $this->row(10, 101, -2.0, true, true, true),
            $this->row(11, 102, 2.0, false, false, false),
        ])->replay('WF_2023', 'STAT-07', 'STRICT', 5, 20260812);

        foreach (Bt03EvaluationReplayService::LABELS as $label) {
            $empty = $this->effect($summary->effects, 2, $label);
            $this->assertSame('NO_EVALUATION_ROWS', $empty->result->evaluationStatus);
            $this->assertSame(0, $empty->result->evaluationSampleCount);
            $this->assertSame('NO_EVALUATION_ROWS', $empty->centered->centeredCiStatus);
            $this->assertNotNull($empty->centered->overallBaselineResidualMean);
            $this->assertNull($empty->centered->centeredBaselineResidualMean);
        }
        $this->assertCount(9, $summary->effects);
    }

    public function test_unseen_category_is_created_only_when_observed(): void
    {
        $source = $this->categorySource();
        $knownOnly = $this->service($source, [
            $this->row(10, 101, 1.0, true, true, true),
        ])->replay('WF_2023', 'STAT-07', 'STRICT', 5, 20260812);

        $this->assertSame(0, $knownOnly->unseenRowCount);
        $this->assertCount(6, $knownOnly->effects);
        $this->assertSame([], array_filter($knownOnly->effects, fn ($effect): bool => $effect->bin->binIndex === 0));

        $withUnseen = $this->service($source, [
            $this->row(10, 101, 1.0, true, true, true),
            $this->row(11, 102, 3.0, false, false, false),
        ])->replay('WF_2023', 'STAT-07', 'STRICT', 5, 20260812);

        $this->assertSame(1, $withUnseen->unseenRowCount);
        $this->assertCount(9, $withUnseen->effects);
        foreach (Bt03EvaluationReplayService::LABELS as $label) {
            $unseen = $this->effect($withUnseen->effects, 0, $label);
            $this->assertSame('UNSEEN_CATEGORY', $unseen->bin->binOrigin);
            $this->assertNull($unseen->bin->sourceEffectBinId);
            $this->assertSame(1, $unseen->result->evaluationSampleCount);
        }
    }

    public function test_input_chunking_does_not_change_effect_results(): void
    {
        $rows = [
            $this->row(10, 101, -1.0, true, true, true),
            $this->row(10, 102, -0.5, false, true, true),
            $this->row(11, 103, 1.0, false, false, true),
            $this->row(12, 104, 2.0, true, true, true),
        ];
        $oneAtATime = $this->service($this->numericSource(), $rows, 1)
            ->replay('WF_2023', 'STAT-07', 'STRICT', 20, 20260812);
        $threeAtATime = $this->service($this->numericSource(), $rows, 3)
            ->replay('WF_2023', 'STAT-07', 'STRICT', 20, 20260812);

        $this->assertSame(
            array_column($oneAtATime->effects, 'effectHash'),
            array_column($threeAtATime->effects, 'effectHash'),
        );
    }

    public function test_single_training_bin_and_label_selection_uses_the_same_core_path(): void
    {
        $summary = $this->service($this->numericSource(), [
            $this->row(10, 101, -1.0, true, true, true),
            $this->row(11, 102, 1.0, false, false, false),
        ])->replay(
            'WF_2023',
            'STAT-07',
            'STRICT',
            20,
            20260812,
            new Bt03EvaluationReplaySelectionDto(2, 'IS_WIN'),
        );

        $this->assertSame(2, $summary->trainingBinCount);
        $this->assertSame(2, $summary->spoolFileCount);
        $this->assertCount(1, $summary->effects);
        $this->assertSame(2, $summary->effects[0]->bin->binIndex);
        $this->assertSame('IS_WIN', $summary->effects[0]->labelCode);
        $this->assertSame(1, $summary->effects[0]->result->evaluationSampleCount);
    }

    public function test_each_replayed_manifest_role_must_exactly_match_the_bt02_oracle_and_cleanup_spools(): void
    {
        $rows = [
            $this->row(10, 101, -1.0, true, true, true),
            $this->row(11, 102, 1.0, false, false, false),
        ];

        foreach (['baseline', 'incremental', 'outcome'] as $role) {
            $source = $this->withExpectedManifests($this->numericSource(), $rows);
            $source = $this->withManifestMismatch($source, 'IS_WIN', $role);
            try {
                $this->service($source, $rows, hydrateManifests: false)
                    ->replay('WF_2023', 'STAT-07', 'STRICT', 5, 20260812);
                $this->fail("A mismatched {$role} manifest must fail before effect calculation.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('BT03_REPLAY_MANIFEST_MISMATCH', $exception->getMessage());
                $this->assertStringContainsString('fold=WF_2023 stat=STAT-07 cohort=STRICT label=IS_WIN', $exception->getMessage());
                $this->assertStringContainsString("role={$role}", $exception->getMessage());
            }
            $this->assertSame([], glob($this->directory.'/*') ?: []);
        }
    }

    public function test_training_bin_selection_still_verifies_the_full_evaluation_universe(): void
    {
        $rows = [
            $this->row(10, 101, -1.0, true, true, true),
            $this->row(11, 102, 1.0, false, false, false),
        ];
        $source = $this->withExpectedManifests($this->numericSource(), $rows);

        $summary = $this->service($source, $rows, hydrateManifests: false)->replay(
            'WF_2023',
            'STAT-07',
            'STRICT',
            5,
            20260812,
            new Bt03EvaluationReplaySelectionDto(2, 'IS_WIN'),
        );

        $this->assertCount(1, $summary->effects);
        $this->assertSame(1, $summary->effects[0]->result->evaluationSampleCount);

        $selectedBinOnly = $this->withExpectedManifests($this->numericSource(), [$rows[1]]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('BT03_REPLAY_MANIFEST_MISMATCH');
        $this->service($selectedBinOnly, $rows, hydrateManifests: false)->replay(
            'WF_2023',
            'STAT-07',
            'STRICT',
            5,
            20260812,
            new Bt03EvaluationReplaySelectionDto(2, 'IS_WIN'),
        );
    }

    public function test_failure_cleans_every_spool_and_rejects_race_reappearance(): void
    {
        $rows = [
            $this->row(10, 101, -1.0, true, true, true),
            $this->row(11, 102, 1.0, false, false, false),
            $this->row(10, 103, -0.5, false, true, true),
        ];
        $source = $this->withExpectedManifests($this->numericSource(), array_slice($rows, 0, 2));

        try {
            $this->service($source, $rows, hydrateManifests: false)->replay('WF_2023', 'STAT-07', 'STRICT', 5, 20260812);
            $this->fail('A race reappearing after another race must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('race grouping', $exception->getMessage());
        }
        $this->assertSame([], glob($this->directory.'/*') ?: []);
    }

    public function test_wrong_source_ownership_is_rejected_before_dataset_replay(): void
    {
        $source = $this->numericSource();
        $pair = $source->modelPairs['IS_WIN'];
        $wrong = new Bt03ModelPairDto(
            $this->model('IS_WIN', 'BASELINE_MATCHED', sourceRunId: 4),
            $pair->incremental,
        );
        $source = new Bt03EvaluationSourceDto(
            $source->sourceRunId,
            $source->sourceFoldId,
            $source->foldCode,
            $source->evaluationFrom,
            $source->evaluationTo,
            $source->sourceSignalSpecId,
            $source->statCode,
            $source->primaryFeatureCode,
            $source->cohortCode,
            array_replace($source->modelPairs, ['IS_WIN' => $wrong]),
            $source->expectedPredictionManifests,
            $source->bins,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('model ownership');
        $this->service($source, [$this->row(10, 101, -1.0, true, true, true)])
            ->replay('WF_2023', 'STAT-07', 'STRICT');
    }

    public function test_execution_core_has_no_model_fitting_dependency(): void
    {
        $source = file_get_contents(dirname(__DIR__, 5).'/app/Domain/Keirin/Backtest/Services/Bt03EvaluationReplayService.php');

        $this->assertIsString($source);
        $this->assertStringNotContainsString('->fit(', $source);
        $this->assertStringNotContainsString('TrainingStandardizer', $source);
        $this->assertStringNotContainsString('RidgeLogisticRegression', $source);
    }

    /** @param list<Bt02EvaluationRowDto|mixed> $rows */
    private function service(
        Bt03EvaluationSourceDto $source,
        array $rows,
        int $chunkSize = 100,
        bool $hydrateManifests = true,
    ): Bt03EvaluationReplayService {
        if ($hydrateManifests) {
            $source = $this->withExpectedManifests($source, $rows);
        }
        $provider = new class($source) implements Bt03EvaluationSourceProvider
        {
            public function __construct(private readonly Bt03EvaluationSourceDto $source) {}

            public function load(string $foldCode, string $statCode, string $cohortCode): Bt03EvaluationSourceDto
            {
                return $this->source;
            }
        };
        $dataset = new class($rows, $chunkSize) implements Bt02EvaluationDataset
        {
            /** @param list<mixed> $rows */
            public function __construct(private readonly array $sourceRows, private readonly int $chunkSize) {}

            public function rows(DateTimeImmutable $from, DateTimeImmutable $to, string $statCode, Bt02SignalCohort $cohort): iterable
            {
                foreach (array_chunk($this->sourceRows, $this->chunkSize) as $chunk) {
                    foreach ($chunk as $row) {
                        yield $row;
                    }
                }
            }
        };
        $boundaryProvider = new class implements EffectBinBoundaryProvider
        {
            public function build(iterable $trainingValues): array
            {
                throw new \LogicException('BT-03 evaluation must not rebuild bin boundaries.');
            }
        };
        $hasher = new Bt02ModelArtifactHasher;

        return new Bt03EvaluationReplayService(
            $provider,
            $dataset,
            new Bt03FixedBinAssigner(new EffectBinBuilder($boundaryProvider)),
            new Bt03StoredModelReplayer(new RidgeLogisticRegression, $hasher),
            new Bt03BinEffectCalculator(new RaceClusterBootstrap, new Type7Quantile),
            new Bt03CenteredBaselineResidualCalculator(new RaceClusterBootstrap, new Type7Quantile),
            new Bt03EffectHasher($hasher),
            $this->directory,
        );
    }

    /** @param list<Bt03SourceBinDto>|null $bins */
    private function numericSource(?array $bins = null): Bt03EvaluationSourceDto
    {
        return $this->source($bins ?? [
            $this->bin(9001, 1, null, 0.0),
            $this->bin(9002, 2, 0.0, null),
        ]);
    }

    private function categorySource(): Bt03EvaluationSourceDto
    {
        return $this->source([
            new Bt03SourceBinDto(9001, 1, 'CATEGORY', null, null, '1', 100, str_repeat('b', 64)),
            new Bt03SourceBinDto(9002, 2, 'CATEGORY', null, null, '2', 100, str_repeat('b', 64)),
        ]);
    }

    /** @param list<Bt03SourceBinDto> $bins */
    private function source(array $bins): Bt03EvaluationSourceDto
    {
        $pairs = [];
        foreach (Bt03EvaluationReplayService::LABELS as $label) {
            $pairs[$label] = new Bt03ModelPairDto(
                $this->model($label, 'BASELINE_MATCHED'),
                $this->model($label, 'INCREMENTAL'),
            );
        }

        return new Bt03EvaluationSourceDto(
            5,
            81,
            'WF_2023',
            new DateTimeImmutable('2023-01-01'),
            new DateTimeImmutable('2023-12-31'),
            701,
            'STAT-07',
            'STAT07_WIN_RATE',
            'STRICT',
            $pairs,
            [],
            $bins,
        );
    }

    private function model(string $label, string $role, int $sourceRunId = 5): Bt03StoredModelDto
    {
        $features = $role === 'BASELINE_MATCHED'
            ? ['STAT01_RACE_SCORE']
            : ['STAT01_RACE_SCORE', 'STAT07_WIN_RATE'];
        $artifact = [
            'feature_names' => $features,
            'scaler_mean' => array_fill_keys($features, 0.0),
            'scaler_sd' => array_fill_keys($features, 1.0),
            'selected_lambda' => 0.1,
            'intercept' => -1.0,
            'coefficients' => $role === 'BASELINE_MATCHED' ? [0.01] : [0.01, 0.2],
            'objective_version' => Bt03SourceManifest::OBJECTIVE_VERSION,
            'optimizer_version' => Bt03SourceManifest::OPTIMIZER_VERSION,
            'probability_semantics' => Bt03SourceManifest::PROBABILITY_SEMANTICS,
        ];

        return new Bt03StoredModelDto(
            $role === 'BASELINE_MATCHED' ? 1001 : 1002,
            $sourceRunId,
            81,
            701,
            'WF_2023',
            'STAT-07',
            'STAT07_WIN_RATE',
            'STRICT',
            $label,
            $role,
            $features,
            $artifact['scaler_mean'],
            $artifact['scaler_sd'],
            [0.1],
            0.1,
            -1.0,
            $artifact['coefficients'],
            Bt03SourceManifest::OBJECTIVE_VERSION,
            Bt03SourceManifest::OPTIMIZER_VERSION,
            Bt03SourceManifest::PROBABILITY_SEMANTICS,
            'CONVERGED_GRADIENT',
            (new Bt02ModelArtifactHasher)->hash($artifact),
            str_repeat('0', 64),
        );
    }

    /** @param list<Bt02EvaluationRowDto> $rows */
    private function withExpectedManifests(Bt03EvaluationSourceDto $source, array $rows): Bt03EvaluationSourceDto
    {
        $replayer = new Bt03StoredModelReplayer(new RidgeLogisticRegression, new Bt02ModelArtifactHasher);
        $pairs = [];
        $expected = [];
        foreach (Bt03EvaluationReplayService::LABELS as $label) {
            $pair = $source->modelPairs[$label];
            $accumulator = new Bt02PredictionManifestAccumulator($this->manifestIdentity($source, $label, $pair));
            foreach ($rows as $row) {
                $accumulator->append(
                    $row->raceId,
                    $row->raceEntryId,
                    $row->label($label),
                    $replayer->probabilityFromValidatedModel($pair->baseline, ['STAT01_RACE_SCORE' => $row->baselineValue]),
                    $replayer->probabilityFromValidatedModel($pair->incremental, [
                        'STAT01_RACE_SCORE' => $row->baselineValue,
                        $source->primaryFeatureCode => $row->signalValue,
                    ]),
                );
            }
            $manifests = $accumulator->seal();
            $pairs[$label] = new Bt03ModelPairDto(
                $this->withPredictionManifest($pair->baseline, $manifests->baselinePredictionManifestSha256),
                $this->withPredictionManifest($pair->incremental, $manifests->incrementalPredictionManifestSha256),
            );
            $expected[$label] = new Bt03ExpectedPredictionManifestsDto(
                $manifests->baselinePredictionManifestSha256,
                $manifests->incrementalPredictionManifestSha256,
                $manifests->outcomeManifestSha256,
            );
        }

        return new Bt03EvaluationSourceDto(
            $source->sourceRunId,
            $source->sourceFoldId,
            $source->foldCode,
            $source->evaluationFrom,
            $source->evaluationTo,
            $source->sourceSignalSpecId,
            $source->statCode,
            $source->primaryFeatureCode,
            $source->cohortCode,
            $pairs,
            $expected,
            $source->bins,
        );
    }

    /** @return array<string, string> */
    private function manifestIdentity(Bt03EvaluationSourceDto $source, string $label, Bt03ModelPairDto $pair): array
    {
        return [
            'source_manifest_hash' => Bt02SourceManifest::HASH,
            'baseline_fingerprint_manifest_hash' => Bt02BaselineFingerprintManifest::HASH,
            'fold' => $source->foldCode,
            'stat_code' => $source->statCode,
            'cohort' => $source->cohortCode,
            'label_code' => $label,
            'baseline_model_hash' => $pair->baseline->modelHash,
            'incremental_model_hash' => $pair->incremental->modelHash,
        ];
    }

    private function withPredictionManifest(Bt03StoredModelDto $model, string $hash): Bt03StoredModelDto
    {
        return new Bt03StoredModelDto(
            $model->modelId,
            $model->sourceRunId,
            $model->sourceFoldId,
            $model->sourceSignalSpecId,
            $model->foldCode,
            $model->statCode,
            $model->primaryFeatureCode,
            $model->cohortCode,
            $model->labelCode,
            $model->modelRole,
            $model->featureNames,
            $model->scalerMean,
            $model->scalerSd,
            $model->lambdaCandidates,
            $model->selectedLambda,
            $model->intercept,
            $model->coefficients,
            $model->objectiveVersion,
            $model->optimizerVersion,
            $model->probabilitySemantics,
            $model->convergenceStatus,
            $model->modelHash,
            $hash,
        );
    }

    private function withManifestMismatch(Bt03EvaluationSourceDto $source, string $label, string $role): Bt03EvaluationSourceDto
    {
        $manifests = $source->expectedPredictionManifests;
        $current = $manifests[$label];
        $replacement = str_repeat('f', 64);
        $manifests[$label] = new Bt03ExpectedPredictionManifestsDto(
            $role === 'baseline' ? $replacement : $current->baselinePredictionManifestHash,
            $role === 'incremental' ? $replacement : $current->incrementalPredictionManifestHash,
            $role === 'outcome' ? $replacement : $current->outcomeManifestHash,
        );
        $pairs = $source->modelPairs;
        if ($role === 'baseline') {
            $pairs[$label] = new Bt03ModelPairDto(
                $this->withPredictionManifest($pairs[$label]->baseline, $replacement),
                $pairs[$label]->incremental,
            );
        } elseif ($role === 'incremental') {
            $pairs[$label] = new Bt03ModelPairDto(
                $pairs[$label]->baseline,
                $this->withPredictionManifest($pairs[$label]->incremental, $replacement),
            );
        }

        return new Bt03EvaluationSourceDto(
            $source->sourceRunId,
            $source->sourceFoldId,
            $source->foldCode,
            $source->evaluationFrom,
            $source->evaluationTo,
            $source->sourceSignalSpecId,
            $source->statCode,
            $source->primaryFeatureCode,
            $source->cohortCode,
            $pairs,
            $manifests,
            $source->bins,
        );
    }

    private function bin(int $id, int $index, ?float $lower, ?float $upper): Bt03SourceBinDto
    {
        return new Bt03SourceBinDto($id, $index, 'NUMERIC_RANGE', $lower, $upper, null, 100, str_repeat('b', 64));
    }

    private function row(int $raceId, int $entryId, float $signal, bool $win, bool $top2, bool $top3): Bt02EvaluationRowDto
    {
        return new Bt02EvaluationRowDto(
            $raceId,
            $entryId,
            80.0 + $entryId / 1000,
            $signal,
            new Bt02BinaryLabelsDto($win, $top2, $top3),
        );
    }

    /** @param list<Bt03ComputedBinEffectDto> $effects */
    private function effect(array $effects, int $binIndex, string $label): Bt03ComputedBinEffectDto
    {
        foreach ($effects as $effect) {
            if ($effect->bin->binIndex === $binIndex && $effect->labelCode === $label) {
                return $effect;
            }
        }

        throw new RuntimeException("Effect {$binIndex}:{$label} was unavailable.");
    }
}
