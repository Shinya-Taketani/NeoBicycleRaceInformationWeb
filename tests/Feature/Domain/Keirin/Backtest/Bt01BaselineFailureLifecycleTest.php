<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Repositories\BacktestAuditRepository;
use App\Domain\Keirin\Backtest\Repositories\BacktestFeatureRepository;
use App\Domain\Keirin\Backtest\Repositories\BacktestLabelRepository;
use App\Domain\Keirin\Backtest\Services\Bt01BaselineService;
use App\Domain\Keirin\Backtest\Services\Bt01FoldProvider;
use App\Domain\Keirin\Backtest\Services\Bt01SourceManifest;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use App\Domain\Keirin\Backtest\Support\PredictionSpool;
use App\Domain\Keirin\Backtest\Support\PredictionSpoolFactory;
use App\Models\BacktestRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\Bt01Fixture;
use Tests\TestCase;

class Bt01BaselineFailureLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bt01Fixture::seed();
        $this->app->singleton(Bt01SourceManifest::class, fn ($app): Bt01SourceManifest => new Bt01SourceManifest(
            $app->make(CanonicalHasher::class),
            Bt01Fixture::manifest(),
        ));
        $this->app->bind(Bt01FoldProvider::class, fn (): Bt01FoldProvider => new class extends Bt01FoldProvider
        {
            public function folds(): array
            {
                return [(new Bt01FoldProvider)->folds()[0]];
            }
        });
    }

    public function test_store_sources_failure_closes_the_run_as_failed(): void
    {
        $this->app->bind(BacktestAuditRepository::class, fn (): BacktestAuditRepository => new class extends BacktestAuditRepository
        {
            public function storeSources(BacktestRun $run, array $sources, string $manifestHash): void
            {
                throw new RuntimeException('synthetic source storage failure');
            }
        });

        $this->expectServiceFailure('synthetic source storage failure');

        $this->assertTerminalFailure(runTarget: 0, runPredicted: 0, foldExpected: false);
    }

    public function test_mid_prediction_failure_closes_fold_and_run_with_saved_prediction_counts(): void
    {
        $this->app->bind(Bt01FoldProvider::class, fn (): Bt01FoldProvider => new class extends Bt01FoldProvider
        {
            public function folds(): array
            {
                return array_slice((new Bt01FoldProvider)->folds(), 0, 2);
            }
        });
        $this->app->bind(BacktestFeatureRepository::class, fn (): BacktestFeatureRepository => new class extends BacktestFeatureRepository
        {
            private int $calls = 0;

            public function forRaces(int $featureRunId, array $raceIds): array
            {
                if (++$this->calls === 2) {
                    throw new RuntimeException('synthetic prediction failure');
                }

                return parent::forRaces($featureRunId, $raceIds);
            }
        });

        $this->expectServiceFailure('synthetic prediction failure', chunkSize: 1);

        $savedRaces = DB::table('backtest_predictions')->distinct()->count('race_id');
        $this->assertSame(1, $savedRaces);
        $this->assertTerminalFailure(runTarget: 1, runPredicted: $savedRaces, foldExpected: true);
        $this->assertSame(1, DB::table('backtest_folds')->count());
        $this->assertDatabaseMissing('backtest_folds', ['fold_code' => 'WF_2023']);
    }

    public function test_mid_label_failure_closes_fold_and_run_and_cleans_the_spool(): void
    {
        $this->app->bind(BacktestLabelRepository::class, fn (): BacktestLabelRepository => new class extends BacktestLabelRepository
        {
            public function forRaces(array $raceIds): array
            {
                throw new RuntimeException('synthetic label failure');
            }
        });
        $factory = new class extends PredictionSpoolFactory
        {
            public ?PredictionSpool $spool = null;

            public function create(): PredictionSpool
            {
                return $this->spool = parent::create();
            }
        };
        $this->app->instance(PredictionSpoolFactory::class, $factory);
        $sourceBefore = $this->sourceCounts();

        $this->expectServiceFailure('synthetic label failure', chunkSize: 1);

        $this->assertTerminalFailure(runTarget: 2, runPredicted: 1, foldExpected: true);
        $this->assertSame($sourceBefore, $this->sourceCounts());
        $this->assertNotNull($factory->spool);
        $this->assertTrue($factory->spool->isClosed());
        $this->assertFileDoesNotExist($factory->spool->path());
    }

    private function expectServiceFailure(string $message, int $chunkSize = 200): void
    {
        try {
            $this->app->make(Bt01BaselineService::class)->build(false, $chunkSize);
            $this->fail('Expected a synthetic BT-01 failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }

    private function assertTerminalFailure(int $runTarget, int $runPredicted, bool $foldExpected): void
    {
        $run = DB::table('backtest_runs')->sole();
        $this->assertSame('FAILED', $run->status);
        $this->assertSame($runTarget, $run->target_race_count);
        $this->assertSame($runPredicted, $run->predicted_race_count);
        $this->assertSame(1, $run->error_count);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(0, DB::table('backtest_runs')->where('status', 'RUNNING')->count());
        $this->assertSame(0, DB::table('backtest_folds')->where('status', 'RUNNING')->count());
        if ($foldExpected) {
            $fold = DB::table('backtest_folds')->sole();
            $this->assertSame('FAILED', $fold->status);
            $this->assertSame($runTarget, $fold->target_race_count);
            $this->assertSame($runPredicted, $fold->predicted_race_count);
            $this->assertNotNull($fold->finished_at);
        } else {
            $this->assertSame(0, DB::table('backtest_folds')->count());
        }
    }

    /** @return array<string, int> */
    private function sourceCounts(): array
    {
        $counts = [];
        foreach (['races', 'race_results', 'statistic_feature_runs', 'statistic_feature_results'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
