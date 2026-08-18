<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03BinEffectCalculator;
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
use App\Domain\Keirin\Backtest\DTO\Bt03EvaluationSourceDto;
use App\Domain\Keirin\Backtest\DTO\Bt03ExpectedPredictionManifestsDto;
use App\Domain\Keirin\Backtest\DTO\Bt03ModelPairDto;
use App\Domain\Keirin\Backtest\DTO\Bt03SourceBinDto;
use App\Domain\Keirin\Backtest\DTO\Bt03StoredModelDto;
use App\Domain\Keirin\Backtest\Enums\Bt02SignalCohort;
use App\Domain\Keirin\Backtest\Services\Bt02BaselineFingerprintManifest;
use App\Domain\Keirin\Backtest\Services\Bt02SourceManifest;
use App\Domain\Keirin\Backtest\Services\Bt03EvaluationReplayService;
use App\Domain\Keirin\Backtest\Services\Bt03EvaluationReplaySessionService;
use App\Domain\Keirin\Backtest\Services\Bt03SourceManifest;
use App\Domain\Keirin\Backtest\Support\Bt02ModelArtifactHasher;
use App\Domain\Keirin\Backtest\Support\Bt02PredictionManifestAccumulator;
use App\Domain\Keirin\Backtest\Support\Bt03EffectHasher;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Bt03EvaluationReplayReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const AUDITED_TABLES = [
        'backtest_runs',
        'backtest_folds',
        'backtest_signal_specs',
        'backtest_models',
        'backtest_signal_metrics',
        'backtest_effect_bins',
        'backtest_bin_effects',
        'statistic_feature_runs',
        'statistic_feature_run_items',
        'statistic_feature_results',
        'batch_runs',
        'batch_run_items',
        'scraping_fetch_logs',
        'players',
        'race_meetings',
        'race_days',
        'races',
        'race_entries',
        'race_results',
        'race_payouts',
    ];

    public function test_evaluation_replay_does_not_write_backtest_statistics_scraping_or_race_tables(): void
    {
        $before = $this->counts();

        $summary = $this->service()->replay('WF_2023', 'STAT-07', 'STRICT', 5, 20260812);

        $this->assertCount(6, $summary->effects);
        $this->assertSame(1, $summary->evaluationRowCount);
        $this->assertSame($before, $this->counts());
    }

    public function test_production_replay_session_core_is_resolvable_from_the_application_container(): void
    {
        $this->assertInstanceOf(Bt03EvaluationReplaySessionService::class, app(Bt03EvaluationReplaySessionService::class));
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return array_combine(
            self::AUDITED_TABLES,
            array_map(fn (string $table): int => DB::table($table)->count(), self::AUDITED_TABLES),
        );
    }

    private function service(): Bt03EvaluationReplayService
    {
        $source = $this->source();
        $provider = new class($source) implements Bt03EvaluationSourceProvider
        {
            public function __construct(private readonly Bt03EvaluationSourceDto $source) {}

            public function load(string $foldCode, string $statCode, string $cohortCode): Bt03EvaluationSourceDto
            {
                return $this->source;
            }
        };
        $dataset = new class implements Bt02EvaluationDataset
        {
            public function rows(DateTimeImmutable $from, DateTimeImmutable $to, string $statCode, Bt02SignalCohort $cohort): iterable
            {
                yield new Bt02EvaluationRowDto(
                    10,
                    101,
                    80.0,
                    -1.0,
                    new Bt02BinaryLabelsDto(true, true, true),
                );
            }
        };
        $boundaries = new class implements EffectBinBoundaryProvider
        {
            public function build(iterable $trainingValues): array
            {
                throw new \LogicException('BT-03 evaluation must not rebuild fixed bins.');
            }
        };
        $hasher = new Bt02ModelArtifactHasher;

        return new Bt03EvaluationReplayService(
            $provider,
            $dataset,
            new Bt03FixedBinAssigner(new EffectBinBuilder($boundaries)),
            new Bt03StoredModelReplayer(new RidgeLogisticRegression, $hasher),
            new Bt03BinEffectCalculator(new RaceClusterBootstrap, new Type7Quantile),
            new Bt03EffectHasher($hasher),
        );
    }

    private function source(): Bt03EvaluationSourceDto
    {
        $pairs = [];
        $expected = [];
        foreach (Bt03EvaluationReplayService::LABELS as $label) {
            $pair = new Bt03ModelPairDto(
                $this->model($label, 'BASELINE_MATCHED'),
                $this->model($label, 'INCREMENTAL'),
            );
            $accumulator = new Bt02PredictionManifestAccumulator([
                'source_manifest_hash' => Bt02SourceManifest::HASH,
                'baseline_fingerprint_manifest_hash' => Bt02BaselineFingerprintManifest::HASH,
                'fold' => 'WF_2023',
                'stat_code' => 'STAT-07',
                'cohort' => 'STRICT',
                'label_code' => $label,
                'baseline_model_hash' => $pair->baseline->modelHash,
                'incremental_model_hash' => $pair->incremental->modelHash,
            ]);
            $accumulator->append(10, 101, 1, 0.5, 0.5);
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
            $expected,
            [
                new Bt03SourceBinDto(9001, 1, 'NUMERIC_RANGE', null, 0.0, null, 100, str_repeat('b', 64)),
                new Bt03SourceBinDto(9002, 2, 'NUMERIC_RANGE', 0.0, null, null, 100, str_repeat('b', 64)),
            ],
        );
    }

    private function model(string $label, string $role): Bt03StoredModelDto
    {
        $features = $role === 'BASELINE_MATCHED'
            ? ['STAT01_RACE_SCORE']
            : ['STAT01_RACE_SCORE', 'STAT07_WIN_RATE'];

        return new Bt03StoredModelDto(
            $role === 'BASELINE_MATCHED' ? 1001 : 1002,
            5,
            81,
            701,
            'WF_2023',
            'STAT-07',
            'STAT07_WIN_RATE',
            'STRICT',
            $label,
            $role,
            $features,
            array_fill_keys($features, 0.0),
            array_fill_keys($features, 1.0),
            [0.1],
            0.1,
            0.0,
            array_fill(0, count($features), 0.0),
            Bt03SourceManifest::OBJECTIVE_VERSION,
            Bt03SourceManifest::OPTIMIZER_VERSION,
            Bt03SourceManifest::PROBABILITY_SEMANTICS,
            'CONVERGED_GRADIENT',
            str_repeat('a', 64),
            str_repeat('0', 64),
        );
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
}
