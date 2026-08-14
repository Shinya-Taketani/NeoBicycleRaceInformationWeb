<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\DTO\Bt02PreflightSummaryDto;
use App\Domain\Keirin\Backtest\Repositories\BacktestFeatureRepository;
use App\Domain\Keirin\Backtest\Repositories\Bt02AuditRepository;
use App\Domain\Keirin\Backtest\Services\Bt01SourceManifest;
use App\Domain\Keirin\Backtest\Services\Bt02BaselineFingerprintPreflightService;
use App\Domain\Keirin\Backtest\Services\Bt02EntrySignalEvaluator;
use App\Domain\Keirin\Backtest\Services\Bt02FingerprintPreflightService;
use App\Domain\Keirin\Backtest\Services\Bt02FoldProvider;
use App\Domain\Keirin\Backtest\Services\Bt02SignalEvaluationService;
use App\Domain\Keirin\Backtest\Services\Bt02SignalRegistry;
use App\Domain\Keirin\Backtest\Services\Bt02SourceManifest;
use App\Domain\Keirin\Backtest\Services\FinalHoldoutGuard;
use App\Models\BacktestFold;
use App\Models\BacktestRun;
use App\Models\BacktestSignalSpec;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class Bt02SignalEvaluationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_preflight_failure_happens_before_outcome_access_or_backtest_writes(): void
    {
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        $preflight = Mockery::mock(Bt02FingerprintPreflightService::class);
        $preflight->shouldReceive('run')->once()->andThrow(new RuntimeException('fingerprint mismatch'));
        $evaluator = Mockery::mock(Bt02EntrySignalEvaluator::class);
        $evaluator->shouldNotReceive('evaluate');

        $this->expectException(RuntimeException::class);
        try {
            $this->service($preflight, $evaluator)->execute();
        } finally {
            $this->assertSame(0, BacktestRun::query()->count());
            $this->assertSame([], array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'race_results'))));
            $this->assertSame([], array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'race_payouts'))));
        }
    }

    public function test_baseline_fingerprint_failure_happens_before_signal_preflight_outcome_or_backtest_writes(): void
    {
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        $baselinePreflight = Mockery::mock(Bt02BaselineFingerprintPreflightService::class);
        $baselinePreflight->shouldReceive('run')->once()->andThrow(new RuntimeException('baseline content mismatch'));
        $signalPreflight = Mockery::mock(Bt02FingerprintPreflightService::class);
        $signalPreflight->shouldNotReceive('run');
        $evaluator = Mockery::mock(Bt02EntrySignalEvaluator::class);
        $evaluator->shouldNotReceive('evaluate');

        $this->expectException(RuntimeException::class);
        try {
            $this->service($signalPreflight, $evaluator, $baselinePreflight)->execute();
        } finally {
            $this->assertSame(0, BacktestRun::query()->count());
            $this->assertSame([], array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'race_results'))));
            $this->assertSame([], array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'race_payouts'))));
        }
    }

    public function test_execution_uses_only_fixed_folds_and_twelve_entry_signals(): void
    {
        $preflight = Mockery::mock(Bt02FingerprintPreflightService::class);
        $preflight->shouldReceive('run')->once()->andReturn(new Bt02PreflightSummaryDto(56, 56, 56, Bt02SourceManifest::HASH));
        $calls = [];
        $evaluator = Mockery::mock(Bt02EntrySignalEvaluator::class);
        $evaluator->shouldReceive('evaluate')->times(36)->andReturnUsing(function (
            BacktestRun $run,
            BacktestFold $fold,
            $definition,
            $signal,
            BacktestSignalSpec $spec,
        ) use (&$calls): array {
            $calls[] = [$definition->code, $signal->statCode, $signal->analysisRole->value];
            $raceIds = ((int) substr($signal->statCode, 5)) % 2 === 0 ? [3, 4, 5] : [1, 2, 3, 4];

            return ['models' => 12, 'metrics' => 18, 'races' => count($raceIds), 'rows' => 35, 'race_ids' => $raceIds, 'manifest_hash' => hash('sha256', $definition->code.$signal->statCode)];
        });

        $summary = $this->service($preflight, $evaluator)->execute();

        $this->assertSame(3, $summary->foldCount);
        $this->assertSame(12, $summary->signalCount);
        $this->assertSame(432, $summary->modelCount);
        $this->assertSame(648, $summary->metricCount);
        $this->assertSame(['WF_2023', 'WF_2024', 'WF_2025'], BacktestFold::query()->orderBy('sequence')->pluck('fold_code')->all());
        $this->assertSame(['SUCCEEDED'], BacktestFold::query()->distinct()->pluck('status')->all());
        $this->assertNotContains('STAT-33', array_column($calls, 1));
        $this->assertNotContains('STAT-41', array_column($calls, 1));
        $this->assertSame(['ENTRY_INCREMENTAL'], array_values(array_unique(array_column($calls, 2))));
        $this->assertSame(14, BacktestSignalSpec::query()->count());
        $this->assertSame('SUCCEEDED', BacktestRun::query()->sole()->status);
        $folds = BacktestFold::query()->orderBy('sequence')->get();
        $this->assertSame([25561, 25624, 25273], $folds->pluck('target_race_count')->all());
        $this->assertSame([5, 5, 5], $folds->pluck('predicted_race_count')->all());
        $this->assertSame([25556, 25619, 25268], $folds->pluck('excluded_race_count')->all());
        $run = BacktestRun::query()->sole();
        $this->assertSame(76458, $run->target_race_count);
        $this->assertSame(15, $run->predicted_race_count);
        $this->assertSame(76443, $run->excluded_race_count);
    }

    public function test_exception_marks_fold_and_run_failed_without_deleting_audit_history(): void
    {
        $preflight = Mockery::mock(Bt02FingerprintPreflightService::class);
        $preflight->shouldReceive('run')->once()->andReturn(new Bt02PreflightSummaryDto(56, 56, 56, Bt02SourceManifest::HASH));
        $evaluator = Mockery::mock(Bt02EntrySignalEvaluator::class);
        $evaluator->shouldReceive('evaluate')->once()->andReturn([
            'models' => 12,
            'metrics' => 18,
            'races' => 4,
            'rows' => 28,
            'race_ids' => [1, 2, 3, 4],
            'manifest_hash' => hash('sha256', 'first'),
        ]);
        $evaluator->shouldReceive('evaluate')->once()->andReturn([
            'models' => 12,
            'metrics' => 18,
            'races' => 3,
            'rows' => 21,
            'race_ids' => [3, 4, 5],
            'manifest_hash' => hash('sha256', 'second'),
        ]);
        $evaluator->shouldReceive('evaluate')->once()->andThrow(new RuntimeException('model fit failed'));

        try {
            $this->service($preflight, $evaluator)->execute();
            $this->fail('Expected BT-02 execution failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('model fit failed', $exception->getMessage());
        }

        $this->assertSame('FAILED', BacktestRun::query()->sole()->status);
        $this->assertNotNull(BacktestRun::query()->sole()->finished_at);
        $this->assertSame('FAILED', BacktestFold::query()->sole()->status);
        $this->assertNotNull(BacktestFold::query()->sole()->finished_at);
        $this->assertSame(25561, BacktestFold::query()->sole()->target_race_count);
        $this->assertSame(5, BacktestFold::query()->sole()->predicted_race_count);
        $this->assertSame(25556, BacktestFold::query()->sole()->excluded_race_count);
        $this->assertSame(76458, BacktestRun::query()->sole()->target_race_count);
        $this->assertSame(5, BacktestRun::query()->sole()->predicted_race_count);
        $this->assertSame(76453, BacktestRun::query()->sole()->excluded_race_count);
        $this->assertSame(14, BacktestSignalSpec::query()->count());
    }

    private function service(
        Bt02FingerprintPreflightService $preflight,
        Bt02EntrySignalEvaluator $evaluator,
        ?Bt02BaselineFingerprintPreflightService $baselinePreflight = null,
    ): Bt02SignalEvaluationService {
        $features = Mockery::mock(BacktestFeatureRepository::class);
        $features->shouldReceive('validateSources')->once()->andReturn([]);
        if ($baselinePreflight === null) {
            $baselinePreflight = Mockery::mock(Bt02BaselineFingerprintPreflightService::class);
            $baselinePreflight->shouldReceive('run')->once();
        }

        return new Bt02SignalEvaluationService(
            $this->app->make(Bt01SourceManifest::class),
            $features,
            new Bt02FoldProvider,
            new FinalHoldoutGuard,
            $baselinePreflight,
            $preflight,
            new Bt02SourceManifest,
            new Bt02SignalRegistry,
            new Bt02AuditRepository,
            $evaluator,
        );
    }
}
