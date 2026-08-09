<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

use App\Domain\Keirin\Backtest\DTO\FoldDefinitionDto;
use App\Domain\Keirin\Backtest\Repositories\BacktestContextRepository;
use App\Domain\Keirin\Backtest\Repositories\BacktestFeatureRepository;
use App\Domain\Keirin\Backtest\Repositories\BacktestLabelRepository;
use App\Domain\Keirin\Backtest\Services\Bt01BaselineService;
use App\Domain\Keirin\Backtest\Services\Bt01FoldProvider;
use App\Domain\Keirin\Backtest\Services\Bt01SourceManifest;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use App\Models\BacktestPrediction;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Mockery;
use Tests\Support\Bt01Fixture;
use Tests\TestCase;

class BuildBt01BaselineCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bt01Fixture::seed();
        $this->bindFixtureManifest();
    }

    public function test_fixed_four_folds_predictions_exclusions_and_metrics_are_stored(): void
    {
        $sourceBefore = $this->sourceCounts();
        $this->artisan('keirin:backtest:bt01-baseline')
            ->expectsOutputToContain('target_races=5 predicted_races=4 excluded_races=1 errors=0')
            ->assertExitCode(0);

        $this->assertDatabaseCount('backtest_runs', 1);
        $this->assertDatabaseCount('backtest_folds', 4);
        $this->assertDatabaseCount('backtest_feature_sources', 4);
        $this->assertDatabaseCount('backtest_predictions', 20);
        $this->assertDatabaseCount('backtest_metrics', 40);
        $this->assertDatabaseHas('backtest_exclusions', ['stage' => 'FEATURE', 'reason_code' => 'FEATURE_RESULT_COUNT_MISMATCH']);
        $this->assertDatabaseHas('backtest_exclusions', ['stage' => 'COHORT', 'reason_code' => 'LABEL_ABNORMAL_RESULT_PRESENT']);
        $this->assertDatabaseHas('backtest_exclusions', ['stage' => 'LABEL', 'reason_code' => 'LABEL_RACE_NOT_CONFIRMED']);
        $this->assertSame(['DEV_2022', 'WF_2023', 'WF_2024', 'WF_2025'], DB::table('backtest_folds')->orderBy('sequence')->pluck('fold_code')->all());
        $this->assertFalse(DB::table('backtest_folds')->whereDate('evaluation_to', '>', '2025-12-31')->exists());
        $this->assertSame($sourceBefore, $this->sourceCounts());
        $this->assertFalse(Schema::hasColumn('backtest_predictions', 'features'));
        $this->assertFalse(Schema::hasColumn('backtest_predictions', 'evidence'));
    }

    public function test_predictions_are_locked_and_evaluation_does_not_change_them(): void
    {
        $this->artisan('keirin:backtest:bt01-baseline')->assertExitCode(0);
        $prediction = BacktestPrediction::query()->firstOrFail();
        $immutableFields = ['prediction_score', 'predicted_rank', 'is_rank1_set', 'is_top3_set', 'prediction_hash'];
        $original = $prediction->only($immutableFields);
        $lockedAt = $prediction->locked_at->format('Y-m-d H:i:s.uP');
        $this->assertDatabaseHas('backtest_metrics', ['cohort_code' => 'OPERATIONAL', 'metric_code' => 'RANK1_SET_WIN_HIT_RATE']);
        $this->assertSame($original, $prediction->refresh()->only($immutableFields));
        $this->assertSame($lockedAt, $prediction->locked_at->format('Y-m-d H:i:s.uP'));

        $this->expectException(LogicException::class);
        $prediction->forceFill(['predicted_rank' => 9])->save();
    }

    public function test_dry_run_writes_no_backtest_rows(): void
    {
        $this->artisan('keirin:backtest:bt01-baseline', ['--dry-run' => true])
            ->expectsOutputToContain('run=dry-run')
            ->assertExitCode(0);
        foreach ($this->backtestTables() as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }

    public function test_chunk_size_does_not_change_prediction_or_metric_manifests(): void
    {
        $service = $this->app->make(Bt01BaselineService::class);
        $service->build(false, 1);
        $service->build(false, 200);
        $runs = DB::table('backtest_runs')->orderBy('id')->pluck('id');
        $firstFolds = DB::table('backtest_folds')->where('backtest_run_id', $runs[0])->orderBy('sequence')->get();
        $secondFolds = DB::table('backtest_folds')->where('backtest_run_id', $runs[1])->orderBy('sequence')->get();

        $this->assertSame($firstFolds->pluck('prediction_manifest_hash')->all(), $secondFolds->pluck('prediction_manifest_hash')->all());
        $this->assertSame($firstFolds->pluck('label_manifest_hash')->all(), $secondFolds->pluck('label_manifest_hash')->all());
        $firstMetrics = DB::table('backtest_metrics')->where('backtest_run_id', $runs[0])->orderBy('backtest_fold_id')->orderBy('cohort_code')->orderBy('metric_code')->get(['cohort_code', 'metric_code', 'numerator', 'denominator', 'sample_count', 'metric_value']);
        $secondMetrics = DB::table('backtest_metrics')->where('backtest_run_id', $runs[1])->orderBy('backtest_fold_id')->orderBy('cohort_code')->orderBy('metric_code')->get(['cohort_code', 'metric_code', 'numerator', 'denominator', 'sample_count', 'metric_value']);
        $this->assertSame($firstMetrics->toJson(), $secondMetrics->toJson());
    }

    public function test_query_and_write_isolation_excludes_current_entry_player_payout_and_scraping_tables(): void
    {
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        $this->artisan('keirin:backtest:bt01-baseline')->assertExitCode(0);

        $sql = implode("\n", $queries);
        foreach (['race_entries', 'race_payouts', 'players', 'scraping_fetch_logs'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $sql);
        }
        foreach ($queries as $query) {
            if (preg_match('/^(insert|update|delete)/', ltrim($query)) === 1) {
                $this->assertMatchesRegularExpression('/backtest_/', $query);
                $this->assertStringNotContainsString('statistic_feature_', $query);
            }
        }
    }

    public function test_prediction_repository_phase_reads_no_labels_and_label_repository_reads_only_labels(): void
    {
        $featureQueries = [];
        $phase = 'feature';
        DB::listen(function (QueryExecuted $query) use (&$featureQueries, &$phase): void {
            $featureQueries[$phase][] = strtolower($query->sql);
        });
        $fold = (new Bt01FoldProvider)->folds()[0];
        $contexts = iterator_to_array((new BacktestContextRepository)->chunks($fold, 200))[0];
        (new BacktestFeatureRepository)->forRaces(25, array_map(fn ($race): int => $race->raceId, $contexts));
        $phase = 'label';
        (new BacktestLabelRepository)->forRaces(array_map(fn ($race): int => $race->raceId, $contexts));

        $this->assertStringNotContainsString('race_results', implode("\n", $featureQueries['feature']));
        $this->assertStringContainsString('race_results', implode("\n", $featureQueries['label']));
        foreach (['race_entries', 'players', 'race_payouts', 'scraping_fetch_logs', 'statistic_feature_results'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, implode("\n", $featureQueries['label']));
        }
    }

    public function test_holdout_failure_occurs_before_any_label_query_or_backtest_write(): void
    {
        $this->app->bind(Bt01FoldProvider::class, fn (): Bt01FoldProvider => new class extends Bt01FoldProvider
        {
            public function folds(): array
            {
                return [new FoldDefinitionDto('FORBIDDEN', 1, null, null, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-01'))];
            }
        });
        $labels = Mockery::mock(BacktestLabelRepository::class);
        $labels->shouldNotReceive('forRaces');
        $this->app->instance(BacktestLabelRepository::class, $labels);

        try {
            $this->app->make(Bt01BaselineService::class)->build(false);
            $this->fail('Expected holdout guard failure.');
        } catch (DomainException) {
            $this->assertSame(0, DB::table('backtest_runs')->count());
        }
    }

    private function bindFixtureManifest(): void
    {
        $this->app->singleton(Bt01SourceManifest::class, fn ($app): Bt01SourceManifest => new Bt01SourceManifest(
            $app->make(CanonicalHasher::class),
            Bt01Fixture::manifest(),
        ));
    }

    /** @return array<string, int> */
    private function sourceCounts(): array
    {
        $tables = ['players', 'races', 'race_entries', 'race_results', 'race_payouts', 'scraping_fetch_logs', 'statistic_feature_runs', 'statistic_feature_run_items', 'statistic_feature_results'];
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    /** @return list<string> */
    private function backtestTables(): array
    {
        return ['backtest_runs', 'backtest_folds', 'backtest_feature_sources', 'backtest_predictions', 'backtest_metrics', 'backtest_exclusions'];
    }
}
