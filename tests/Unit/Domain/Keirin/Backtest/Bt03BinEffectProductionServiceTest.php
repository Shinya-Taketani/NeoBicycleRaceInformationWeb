<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\DTO\Bt02PreflightSummaryDto;
use App\Domain\Keirin\Backtest\DTO\Bt03EvaluationReplaySummaryDto;
use App\Domain\Keirin\Backtest\DTO\Bt03PreflightSummaryDto;
use App\Domain\Keirin\Backtest\DTO\Bt03ProductionProgressDto;
use App\Domain\Keirin\Backtest\DTO\Bt03ProductionSummaryDto;
use App\Domain\Keirin\Backtest\DTO\Bt03SourceArtifactFingerprintsDto;
use App\Domain\Keirin\Backtest\DTO\Bt03SourceVerificationDto;
use App\Domain\Keirin\Backtest\Repositories\Bt03BinEffectAuditRepository;
use App\Domain\Keirin\Backtest\Services\Bt03BinEffectProductionService;
use App\Domain\Keirin\Backtest\Services\Bt03EvaluationReplayService;
use App\Domain\Keirin\Backtest\Services\Bt03EvaluationReplaySessionService;
use App\Domain\Keirin\Backtest\Services\Bt03PreflightService;
use App\Domain\Keirin\Backtest\Services\Bt03ProductionAdvisoryLock;
use App\Domain\Keirin\Backtest\Services\Bt03ProductionContract;
use App\Domain\Keirin\Backtest\Services\Bt03ProductionSchemaService;
use App\Domain\Keirin\Backtest\Services\Bt03ReplaySummaryValidator;
use App\Domain\Keirin\Backtest\Services\Bt03SourceManifest;
use App\Models\BacktestBinEffectScope;
use App\Models\BacktestRun;
use LogicException;
use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

class Bt03BinEffectProductionServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_plan_runs_full_preflight_and_schema_readiness_without_lock_or_audit_write(): void
    {
        $contract = new Bt03ProductionContract;
        [$service, $schema, $lock, $preflight, $session, $validator, $audit] = $this->dependencies($contract);
        $preflight->shouldReceive('run')->once()->andReturn($this->preflight());
        $schema->shouldReceive('readiness')->once()->andReturn([
            'backtest_bin_effects' => true,
            'backtest_bin_effect_scopes' => false,
        ]);
        $lock->shouldNotReceive('acquire');
        $session->shouldNotReceive('withVerifiedSession');
        $audit->shouldNotReceive('createRun');

        $plan = $service->plan();

        $this->assertSame(5, $plan->sourceRunId);
        $this->assertSame(72, $plan->scopeCount);
        $this->assertSame(668, $plan->sourceBinCount);
        $this->assertSame(2004, $plan->baseEffectCount);
        $this->assertFalse($plan->schemaReadiness['backtest_bin_effect_scopes']);
    }

    public function test_fresh_execution_processes_all_72_scopes_in_canonical_order_once(): void
    {
        $contract = new Bt03ProductionContract;
        $definitions = $contract->scopes();
        $run = $this->productionRun();
        $scopes = $this->scopes($definitions);
        $replayed = [];
        $progress = [];
        [$service, $schema, $lock, $preflight, $session, $validator, $audit, $replay] = $this->dependencies($contract);
        $schema->shouldReceive('assertReady')->once();
        $lock->shouldReceive('acquire')->once();
        $lock->shouldReceive('release')->once();
        $preflight->shouldNotReceive('run');
        $session->shouldReceive('withVerifiedSession')->once()->andReturnUsing(
            fn (callable $operation): mixed => $operation($replay, $this->preflight()),
        );
        $audit->shouldReceive('createRun')->once()->andReturn($run);
        $audit->shouldReceive('scopes')->once()->with($run)->andReturn($scopes);
        $audit->shouldReceive('startScope')->times(72)->andReturnUsing(fn (BacktestBinEffectScope $scope) => $scope);
        $replay->shouldReceive('replay')->times(72)->andReturnUsing(function (
            string $fold,
            string $stat,
            string $cohort,
            int $iterations,
            int $seed,
            mixed $selection,
        ) use (&$replayed): Bt03EvaluationReplaySummaryDto {
            $replayed[] = [$fold, $stat, $cohort, $iterations, $seed, $selection];

            return $this->replaySummary($fold, $stat, $cohort);
        });
        $validator->shouldReceive('validate')->times(72);
        $audit->shouldReceive('persistScope')->times(72);
        $expected = new Bt03ProductionSummaryDto(77, 'bt03-run', 72, 72, 0, 2004, 0, str_repeat('f', 64));
        $audit->shouldReceive('finalizeSuccess')->once()->with($run, 0)->andReturn($expected);

        $actual = $service->execute(null, function (Bt03ProductionProgressDto $update) use (&$progress): void {
            $progress[] = $update;
        });

        $this->assertSame($expected, $actual);
        $this->assertCount(72, $replayed);
        $this->assertSame(array_map(
            fn ($scope): array => [$scope->foldCode, $scope->statCode, $scope->cohortCode, 2000, 20260812, null],
            $definitions,
        ), $replayed);
        $this->assertSame(range(1, 72), array_column($progress, 'ordinal'));
        $this->assertSame(array_fill(0, 72, 'SUCCEEDED'), array_column($progress, 'status'));
    }

    public function test_resume_verifies_and_skips_all_succeeded_scopes_without_replay(): void
    {
        $contract = new Bt03ProductionContract;
        $run = $this->productionRun();
        $scopes = $this->scopes($contract->scopes(), 'SUCCEEDED');
        [$service, $schema, $lock, $preflight, $session, $validator, $audit, $replay] = $this->dependencies($contract);
        $schema->shouldReceive('assertReady')->once();
        $lock->shouldReceive('acquire')->once();
        $lock->shouldReceive('release')->once();
        $preflight->shouldNotReceive('run');
        $audit->shouldReceive('assertResumeAllowed')->once()->with(77)->andReturn($run);
        $session->shouldReceive('withVerifiedSession')->once()->andReturnUsing(
            fn (callable $operation): mixed => $operation($replay, $this->preflight()),
        );
        $audit->shouldReceive('resumeRun')->once()->with(77, Mockery::type(Bt03PreflightSummaryDto::class))->andReturn($run);
        $audit->shouldReceive('scopes')->once()->andReturn($scopes);
        $audit->shouldReceive('verifySucceededScope')->times(72)->andReturn(3);
        $audit->shouldNotReceive('startScope');
        $replay->shouldNotReceive('replay');
        $validator->shouldNotReceive('validate');
        $audit->shouldNotReceive('persistScope');
        $expected = new Bt03ProductionSummaryDto(77, 'bt03-run', 72, 72, 72, 2004, 0, str_repeat('e', 64));
        $audit->shouldReceive('finalizeSuccess')->once()->with($run, 72)->andReturn($expected);

        $actual = $service->execute(77);

        $this->assertSame($expected, $actual);
    }

    public function test_replay_failure_preserves_primary_failure_marks_scope_and_run_and_releases_lock(): void
    {
        $contract = new Bt03ProductionContract;
        $run = $this->productionRun();
        $scope = $this->scopes([$contract->scopes()[0]])[0];
        $failure = new LogicException('scope replay failed');
        [$service, $schema, $lock, $preflight, $session, $validator, $audit, $replay] = $this->dependencies($contract);
        $schema->shouldReceive('assertReady')->once();
        $lock->shouldReceive('acquire')->once();
        $lock->shouldReceive('release')->once();
        $session->shouldReceive('withVerifiedSession')->once()->andReturnUsing(
            fn (callable $operation): mixed => $operation($replay, $this->preflight()),
        );
        $audit->shouldReceive('createRun')->once()->andReturn($run);
        $audit->shouldReceive('scopes')->once()->andReturn([$scope]);
        $audit->shouldReceive('startScope')->once()->andReturn($scope);
        $replay->shouldReceive('replay')->once()->andThrow($failure);
        $validator->shouldNotReceive('validate');
        $audit->shouldReceive('failScope')->once()->with($scope, $failure);
        $preflight->shouldReceive('run')->once()->andReturn($this->preflight());
        $audit->shouldReceive('markRunFailure')->once()->with($run, $failure, true, null, null);
        $audit->shouldNotReceive('finalizeSuccess');

        try {
            $service->execute();
            $this->fail('The primary replay failure must escape.');
        } catch (Throwable $actual) {
            $this->assertSame($failure, $actual);
        }
    }

    public function test_end_preflight_drift_blocks_resume_and_never_finalizes_success(): void
    {
        $contract = new Bt03ProductionContract;
        $run = $this->productionRun();
        $driftCause = new RuntimeException('source changed');
        $drift = new RuntimeException('BT03_SOURCE_DRIFT_AFTER_REPLAY: source changed', previous: $driftCause);
        [$service, $schema, $lock, $preflight, $session, $validator, $audit, $replay] = $this->dependencies($contract);
        $schema->shouldReceive('assertReady')->once();
        $lock->shouldReceive('acquire')->once();
        $lock->shouldReceive('release')->once();
        $session->shouldReceive('withVerifiedSession')->once()->andReturnUsing(function (callable $operation) use ($replay, $drift): never {
            $operation($replay, $this->preflight());
            throw $drift;
        });
        $audit->shouldReceive('createRun')->once()->andReturn($run);
        $audit->shouldReceive('scopes')->once()->andReturn([]);
        $preflight->shouldNotReceive('run');
        $audit->shouldReceive('markRunFailure')->once()->with(
            $run,
            $drift,
            false,
            'SOURCE_DRIFT_AFTER_REPLAY',
            $driftCause,
        );
        $audit->shouldNotReceive('finalizeSuccess');

        try {
            $service->execute();
            $this->fail('End-preflight drift must fail Production.');
        } catch (Throwable $actual) {
            $this->assertSame($drift, $actual);
        }
    }

    public function test_secondary_audit_and_unlock_failures_never_replace_primary_replay_failure(): void
    {
        $contract = new Bt03ProductionContract;
        $run = $this->productionRun();
        $scope = $this->scopes([$contract->scopes()[0]])[0];
        $primary = new LogicException('primary replay failure');
        [$service, $schema, $lock, $preflight, $session, $validator, $audit, $replay] = $this->dependencies($contract);
        $schema->shouldReceive('assertReady')->once();
        $lock->shouldReceive('acquire')->once();
        $lock->shouldReceive('release')->once()->andThrow(new RuntimeException('unlock failed'));
        $session->shouldReceive('withVerifiedSession')->once()->andReturnUsing(
            fn (callable $operation): mixed => $operation($replay, $this->preflight()),
        );
        $audit->shouldReceive('createRun')->once()->andReturn($run);
        $audit->shouldReceive('scopes')->once()->andReturn([$scope]);
        $audit->shouldReceive('startScope')->once()->andReturn($scope);
        $replay->shouldReceive('replay')->once()->andThrow($primary);
        $validator->shouldNotReceive('validate');
        $audit->shouldReceive('failScope')->once()->andThrow(new RuntimeException('scope audit failed'));
        $preflight->shouldReceive('run')->once()->andReturn($this->preflight());
        $audit->shouldReceive('markRunFailure')->once()->andThrow(new RuntimeException('run audit failed'));

        try {
            $service->execute();
            $this->fail('The primary failure must escape all secondary failures.');
        } catch (Throwable $actual) {
            $this->assertSame($primary, $actual);
        }
    }

    public function test_schema_or_lock_failure_happens_before_run_creation(): void
    {
        $contract = new Bt03ProductionContract;
        [$service, $schema, $lock, $preflight, $session, $validator, $audit] = $this->dependencies($contract);
        $schemaFailure = new RuntimeException('scope ledger missing');
        $schema->shouldReceive('assertReady')->once()->andThrow($schemaFailure);
        $lock->shouldNotReceive('acquire');
        $audit->shouldNotReceive('createRun');
        $session->shouldNotReceive('withVerifiedSession');

        try {
            $service->execute();
            $this->fail('Schema readiness must fail closed.');
        } catch (Throwable $actual) {
            $this->assertSame($schemaFailure, $actual);
        }

        Mockery::close();
        [$service, $schema, $lock, $preflight, $session, $validator, $audit] = $this->dependencies($contract);
        $lockFailure = new RuntimeException('already running');
        $schema->shouldReceive('assertReady')->once();
        $lock->shouldReceive('acquire')->once()->andThrow($lockFailure);
        $lock->shouldNotReceive('release');
        $audit->shouldNotReceive('createRun');
        $session->shouldNotReceive('withVerifiedSession');

        try {
            $service->execute();
            $this->fail('Advisory lock acquisition must fail closed.');
        } catch (Throwable $actual) {
            $this->assertSame($lockFailure, $actual);
        }
    }

    /** @return array{Bt03BinEffectProductionService, mixed, mixed, mixed, mixed, mixed, mixed, mixed} */
    private function dependencies(Bt03ProductionContract $contract): array
    {
        $schema = Mockery::mock(Bt03ProductionSchemaService::class);
        $lock = Mockery::mock(Bt03ProductionAdvisoryLock::class);
        $preflight = Mockery::mock(Bt03PreflightService::class);
        $session = Mockery::mock(Bt03EvaluationReplaySessionService::class);
        $validator = Mockery::mock(Bt03ReplaySummaryValidator::class);
        $audit = Mockery::mock(Bt03BinEffectAuditRepository::class);
        $replay = Mockery::mock(Bt03EvaluationReplayService::class);

        return [
            new Bt03BinEffectProductionService($contract, $schema, $lock, $preflight, $session, $validator, $audit),
            $schema,
            $lock,
            $preflight,
            $session,
            $validator,
            $audit,
            $replay,
        ];
    }

    private function productionRun(): BacktestRun
    {
        return new BacktestRun(['id' => 77, 'run_uuid' => 'bt03-run', 'status' => 'RUNNING']);
    }

    /** @param array<int, object> $definitions @return list<BacktestBinEffectScope> */
    private function scopes(array $definitions, string $status = 'PENDING'): array
    {
        return array_map(fn (object $definition): BacktestBinEffectScope => new BacktestBinEffectScope([
            'id' => $definition->ordinal,
            'status' => $status,
            'fold_code' => $definition->foldCode,
            'stat_code' => $definition->statCode,
            'cohort_code' => $definition->cohortCode,
            'expected_training_bin_count' => 1,
            'source_backtest_run_id' => 5,
            'source_backtest_fold_id' => 10,
            'source_backtest_signal_spec_id' => 20,
            'source_boundaries_hash' => str_repeat('a', 64),
            'bootstrap_iterations' => 2000,
            'bootstrap_seed' => 20260812,
            'evaluation_row_count' => 10,
            'evaluation_race_count' => 2,
            'unseen_row_count' => 0,
        ]), $definitions);
    }

    private function replaySummary(string $fold, string $stat, string $cohort): Bt03EvaluationReplaySummaryDto
    {
        return new Bt03EvaluationReplaySummaryDto($fold, $stat, $cohort, 10, 2, 1, 0, 3, 100, 5, 2, []);
    }

    private function preflight(): Bt03PreflightSummaryDto
    {
        $hash = str_repeat('a', 64);

        return new Bt03PreflightSummaryDto(
            new Bt03SourceVerificationDto(
                5, 3, 14, 432, 648, 668, 432, 432,
                new Bt03SourceArtifactFingerprintsDto($hash, $hash, $hash, $hash, $hash, $hash),
                'private/backtest/bt02/outcome-context/'.$hash,
            ),
            Bt03SourceManifest::OUTCOME_SNAPSHOT_MANIFEST_HASH,
            4,
            new Bt02PreflightSummaryDto(56, 56, 56, $hash),
            Bt03SourceManifest::HASH,
        );
    }
}
