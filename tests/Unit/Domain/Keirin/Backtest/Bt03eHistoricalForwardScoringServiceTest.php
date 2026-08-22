<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03eCoordinateDescentOptimizer;
use App\Domain\Keirin\Backtest\Calculators\Bt03eDirectionRuleBuilder;
use App\Domain\Keirin\Backtest\Calculators\Bt03ePointScorer;
use App\Domain\Keirin\Backtest\Calculators\Bt03eRaceMetricEvaluator;
use App\Domain\Keirin\Backtest\Repositories\Bt03eRuleSourceRepository;
use App\Domain\Keirin\Backtest\Services\Bt03eArtifactWriter;
use App\Domain\Keirin\Backtest\Services\Bt03eDatasetBuilder;
use App\Domain\Keirin\Backtest\Services\Bt03eHistoricalForwardScoringService;
use App\Domain\Keirin\Backtest\Services\Bt03eOutcomeSnapshotProvider;
use App\Domain\Keirin\Backtest\Services\Bt03eReadOnlyDatabaseGuard;
use App\Domain\Keirin\Backtest\Services\Bt03eReadOnlyQueryAudit;
use App\Domain\Keirin\Backtest\Services\Bt03eSelectedSourcePreflightService;
use App\Domain\Keirin\Backtest\Services\Bt03SourceManifest;
use App\Domain\Keirin\Backtest\Support\Bt02OutcomeContextSnapshotArtifact;
use App\Domain\Keirin\Backtest\Support\Bt03eRaceSpool;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class Bt03eHistoricalForwardScoringServiceTest extends TestCase
{
    public function test_start_full_effect_verification_failure_prevents_all_artifact_publication(): void
    {
        $ruleSource = Mockery::mock(Bt03eRuleSourceRepository::class);
        $ruleSource->shouldReceive('sourceSnapshot')->once()->andThrow(new RuntimeException('start full effect verification failed'));
        $preflight = Mockery::mock(Bt03eSelectedSourcePreflightService::class);
        $preflight->shouldReceive('run')->once()->andReturn(['verification_digest' => 'fixed']);
        $audit = Mockery::mock(Bt03eReadOnlyQueryAudit::class);
        $audit->shouldReceive('start')->once();
        $audit->shouldReceive('finish')->once()->andReturn([]);
        $audit->allows('active')->andReturn(true);
        $guard = Mockery::mock(Bt03eReadOnlyDatabaseGuard::class);
        $guard->shouldReceive('begin')->once();
        $guard->shouldReceive('rollback')->once()->andReturn([]);
        $guard->allows('active')->andReturn(true);
        $artifacts = Mockery::mock(Bt03eArtifactWriter::class);
        $artifacts->shouldNotReceive('write');
        $metrics = new Bt03eRaceMetricEvaluator(new Bt03ePointScorer);
        $service = new Bt03eHistoricalForwardScoringService(
            $ruleSource,
            Mockery::mock(Bt03eDirectionRuleBuilder::class),
            $preflight,
            Mockery::mock(Bt03eDatasetBuilder::class),
            new Bt03eCoordinateDescentOptimizer($metrics),
            $metrics,
            $artifacts,
            $audit,
            $guard,
            Mockery::mock(Bt03eOutcomeSnapshotProvider::class),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('start full effect verification failed');
        $service->run('/tmp');
    }

    public function test_changing_only_2024_outcomes_does_not_change_the_2023_frozen_candidate(): void
    {
        $first = $this->runService([1, 2, 3, 4, 5]);
        $second = $this->runService([5, 4, 3, 2, 1]);

        $this->assertSame($first['chosen_candidate'], $second['chosen_candidate']);
    }

    public function test_changing_2023_outcomes_can_change_the_service_chosen_candidate(): void
    {
        $incrementalWins = $this->runService([1, 2, 3, 4, 5], trainingRanks: [2, 1, 3, 4, 5]);
        $baselineWins = $this->runService([1, 2, 3, 4, 5], trainingRanks: [1, 2, 3, 4, 5]);

        $this->assertNotSame($incrementalWins['chosen_candidate'], $baselineWins['chosen_candidate']);
    }

    public function test_end_feature_preflight_drift_fails_before_artifact_publication(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('selected feature source preflight drifted');
        $this->runService([1, 2, 3, 4, 5], preflightDrift: true);
    }

    public function test_end_effect_semantic_digest_drift_fails_before_artifact_publication(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('effect source semantic digest drifted');
        $this->runService([1, 2, 3, 4, 5], effectDrift: true);
    }

    public function test_end_full_effect_verification_failure_prevents_artifact_publication(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('full effect verification failed');
        $this->runService([1, 2, 3, 4, 5], endFullVerificationFailure: true);
    }

    /** @param list<int> $evaluationRanks @return array<string, mixed> */
    private function runService(
        array $evaluationRanks,
        bool $preflightDrift = false,
        bool $effectDrift = false,
        array $trainingRanks = [2, 1, 3, 4, 5],
        bool $endFullVerificationFailure = false,
    ): array {
        $training = $this->spool(2023, $trainingRanks, true);
        $evaluation = $this->spool(2024, $evaluationRanks, false);
        $ruleSource = Mockery::mock(Bt03eRuleSourceRepository::class);
        $sourceCall = 0;
        $ruleSource->shouldReceive('sourceSnapshot')->times($preflightDrift ? 1 : 2)->andReturnUsing(function () use (&$sourceCall, $effectDrift, $endFullVerificationFailure): array {
            $sourceCall++;
            if ($endFullVerificationFailure && $sourceCall === 2) {
                throw new RuntimeException('full effect verification failed');
            }

            return [
                'audit' => ['status' => 'SUCCEEDED'],
                'semantic_digest' => $effectDrift && $sourceCall === 2 ? str_repeat('b', 64) : str_repeat('a', 64),
                'used_effect_row_count' => 333,
                'full_verified_scope_count' => 12,
                'full_verified_effect_count' => 333,
                'rows' => [],
            ];
        });
        $ruleSource->shouldReceive('outcomeSnapshotPath')->once()->andReturn('private/test-snapshot');

        $ruleBuilder = Mockery::mock(Bt03eDirectionRuleBuilder::class);
        $ruleBuilder->shouldReceive('build')->once()->with([])->andReturn([]);
        $preflight = Mockery::mock(Bt03eSelectedSourcePreflightService::class);
        $preflightCall = 0;
        $preflight->shouldReceive('run')->twice()->andReturnUsing(function () use (&$preflightCall, $preflightDrift): array {
            $preflightCall++;

            return ['verification_digest' => $preflightDrift && $preflightCall === 2 ? 'changed' : 'fixed'];
        });
        $datasets = Mockery::mock(Bt03eDatasetBuilder::class);
        $datasets->shouldReceive('build')->twice()->andReturn(
            $this->dataset($training),
            $this->dataset($evaluation),
        );
        $snapshot = Mockery::mock(Bt02OutcomeContextSnapshotArtifact::class);
        $snapshot->allows('manifestHash')->andReturn(Bt03SourceManifest::OUTCOME_SNAPSHOT_MANIFEST_HASH);
        $snapshot->allows('partitionAudit')->andReturnUsing(fn (int $year): array => [
            'year' => $year,
            'file' => "{$year}.jsonl",
            'race_count' => 1,
            'result_row_count' => 5,
            'byte_count' => 100,
            'sha256' => str_repeat((string) ($year % 10), 64),
        ]);
        $snapshot->allows('verifyPartition');
        $snapshots = Mockery::mock(Bt03eOutcomeSnapshotProvider::class);
        $snapshots->shouldReceive('open')->twice()->andReturn($snapshot);

        $audit = Mockery::mock(Bt03eReadOnlyQueryAudit::class);
        $audit->shouldReceive('start')->once();
        $audit->shouldReceive('recordSnapshotYear')->times(4);
        $audit->shouldReceive('finish')->once()->andReturn([
            'query_count' => 1,
            'db_write_count' => 0,
            'executed_write_query_count' => 0,
            'forbidden_year_query_or_binding_count' => [2025 => 0, 2026 => 0],
            'snapshot_partition_access' => [2023 => 2, 2024 => 2, 2025 => 0, 2026 => 0],
            'feature_source_access' => [2023 => 1, 2024 => 1, 2025 => 0, 2026 => 0],
        ]);
        $audit->allows('active')->andReturn(true);
        $guard = Mockery::mock(Bt03eReadOnlyDatabaseGuard::class);
        $guard->shouldReceive('begin')->once();
        $guard->shouldReceive('rollback')->once()->andReturn([
            'db_read_only_transaction' => true,
            'db_transaction_rolled_back' => true,
        ]);
        $guard->allows('active')->andReturn(true);
        $artifacts = Mockery::mock(Bt03eArtifactWriter::class);
        if ($preflightDrift || $effectDrift || $endFullVerificationFailure) {
            $artifacts->shouldNotReceive('write');
        } else {
            $artifacts->shouldReceive('write')->once()->andReturn(['bundle_directory' => '/tmp/bundle', 'json' => '/tmp/result.json', 'csv' => '/tmp/result.csv']);
        }
        $metrics = new Bt03eRaceMetricEvaluator(new Bt03ePointScorer);
        $service = new Bt03eHistoricalForwardScoringService(
            $ruleSource,
            $ruleBuilder,
            $preflight,
            $datasets,
            new Bt03eCoordinateDescentOptimizer($metrics),
            $metrics,
            $artifacts,
            $audit,
            $guard,
            $snapshots,
        );

        return $service->run('/tmp');
    }

    /** @return array<string, mixed> */
    private function dataset(Bt03eRaceSpool $spool): array
    {
        return [
            'spool' => $spool,
            'snapshot_races' => 1,
            'excluded_races' => 0,
            'excluded_reasons' => [],
        ];
    }

    /** @param list<int> $ranks */
    private function spool(int $year, array $ranks, bool $signal): Bt03eRaceSpool
    {
        $spool = new Bt03eRaceSpool($year, sys_get_temp_dir().'/bt03e-service-test-'.bin2hex(random_bytes(8)).'.jsonl');
        $entries = [];
        foreach ($ranks as $offset => $rank) {
            $directions = array_fill(0, 12, 0);
            if ($signal && $offset === 0) {
                $directions[0] = -1;
            } elseif ($signal && $offset === 1) {
                $directions[0] = 1;
            }
            $entries[] = [
                'id' => $offset + 1,
                'bike' => $offset + 1,
                'raw' => 100.0 - $offset,
                'stat01_rank' => $offset + 1,
                'directions' => $directions,
                'rank' => $rank,
                'status' => 'FINISHED',
            ];
        }
        $spool->append(1, $entries);
        $spool->seal();

        return $spool;
    }
}
