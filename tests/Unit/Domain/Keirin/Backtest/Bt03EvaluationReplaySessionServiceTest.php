<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\DTO\Bt02PreflightSummaryDto;
use App\Domain\Keirin\Backtest\DTO\Bt03EvaluationReplaySummaryDto;
use App\Domain\Keirin\Backtest\DTO\Bt03PreflightSummaryDto;
use App\Domain\Keirin\Backtest\DTO\Bt03SourceArtifactFingerprintsDto;
use App\Domain\Keirin\Backtest\DTO\Bt03SourceVerificationDto;
use App\Domain\Keirin\Backtest\Services\Bt02OutcomeContextSnapshotSession;
use App\Domain\Keirin\Backtest\Services\Bt03EvaluationReplayService;
use App\Domain\Keirin\Backtest\Services\Bt03EvaluationReplaySessionService;
use App\Domain\Keirin\Backtest\Services\Bt03OutcomeSnapshotVerifier;
use App\Domain\Keirin\Backtest\Services\Bt03PreflightService;
use App\Domain\Keirin\Backtest\Services\Bt03SourceManifest;
use App\Domain\Keirin\Backtest\Support\Bt02OutcomeContextSnapshotArtifact;
use LogicException;
use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class Bt03EvaluationReplaySessionServiceTest extends TestCase
{
    private const SNAPSHOT_PATH = 'private/backtest/bt02/outcome-context/'.Bt03SourceManifest::OUTCOME_SNAPSHOT_MANIFEST_HASH;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_start_preflight_precedes_verified_snapshot_activation_and_single_scope_replay(): void
    {
        $events = [];
        $preflightCall = 0;
        $preflight = Mockery::mock(Bt03PreflightService::class);
        $preflight->shouldReceive('run')->twice()->andReturnUsing(function () use (&$events, &$preflightCall): Bt03PreflightSummaryDto {
            $events[] = $preflightCall++ === 0 ? 'preflight:start' : 'preflight:end';

            return $this->preflightSummary();
        });
        $snapshot = $this->snapshot();
        $verifier = Mockery::mock(Bt03OutcomeSnapshotVerifier::class);
        $verifier->shouldReceive('open')->once()->with(self::SNAPSHOT_PATH)
            ->andReturnUsing(function () use (&$events, $snapshot): Bt02OutcomeContextSnapshotArtifact {
                $events[] = 'snapshot:open';

                return $snapshot;
            });
        $snapshotSession = Mockery::mock(Bt02OutcomeContextSnapshotSession::class);
        $snapshotSession->shouldReceive('activate')->once()->with($snapshot)->andReturnUsing(function () use (&$events): void {
            $events[] = 'snapshot:activate';
        });
        $snapshotSession->shouldReceive('deactivate')->once()->with(Bt03SourceManifest::OUTCOME_SNAPSHOT_MANIFEST_HASH)
            ->andReturnUsing(function () use (&$events): void {
                $events[] = 'snapshot:deactivate';
            });
        $summary = $this->replaySummary('STAT-07');
        $replay = Mockery::mock(Bt03EvaluationReplayService::class);
        $replay->shouldReceive('replay')->once()->with('WF_2023', 'STAT-07', 'STRICT', 5, 20260812, null)
            ->andReturnUsing(function () use (&$events, $summary): Bt03EvaluationReplaySummaryDto {
                $events[] = 'replay';

                return $summary;
            });

        $actual = $this->service($preflight, $verifier, $snapshotSession, $replay)
            ->replay('WF_2023', 'STAT-07', 'STRICT', 5, 20260812);

        $this->assertSame($summary, $actual);
        $this->assertSame([
            'preflight:start',
            'snapshot:open',
            'snapshot:activate',
            'replay',
            'preflight:end',
            'snapshot:deactivate',
        ], $events);
    }

    public function test_start_preflight_failure_stops_before_snapshot_or_replay_access(): void
    {
        $preflight = Mockery::mock(Bt03PreflightService::class);
        $preflight->shouldReceive('run')->once()->andThrow(new RuntimeException('effect_bins fingerprint mismatched'));
        $verifier = Mockery::mock(Bt03OutcomeSnapshotVerifier::class);
        $verifier->shouldNotReceive('open');
        $snapshotSession = Mockery::mock(Bt02OutcomeContextSnapshotSession::class);
        $snapshotSession->shouldNotReceive('activate');
        $snapshotSession->shouldNotReceive('deactivate');
        $replay = Mockery::mock(Bt03EvaluationReplayService::class);
        $replay->shouldNotReceive('replay');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('effect_bins fingerprint mismatched');
        $this->service($preflight, $verifier, $snapshotSession, $replay)
            ->replay('WF_2023', 'STAT-07', 'STRICT', 5, 20260812);
    }

    public function test_fresh_session_preflight_rejects_source_mutated_after_an_earlier_standalone_pass(): void
    {
        $call = 0;
        $preflight = Mockery::mock(Bt03PreflightService::class);
        $preflight->shouldReceive('run')->twice()->andReturnUsing(function () use (&$call): Bt03PreflightSummaryDto {
            if ($call++ === 0) {
                return $this->preflightSummary();
            }

            throw new RuntimeException('BT-03 source effect_bins fingerprint mismatched.');
        });
        $this->assertSame(Bt03SourceManifest::HASH, $preflight->run()->sourceManifestHash);
        $verifier = Mockery::mock(Bt03OutcomeSnapshotVerifier::class);
        $verifier->shouldNotReceive('open');
        $snapshotSession = Mockery::mock(Bt02OutcomeContextSnapshotSession::class);
        $snapshotSession->shouldNotReceive('activate');
        $snapshotSession->shouldNotReceive('deactivate');
        $replay = Mockery::mock(Bt03EvaluationReplayService::class);
        $replay->shouldNotReceive('replay');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('effect_bins fingerprint mismatched');
        $this->service($preflight, $verifier, $snapshotSession, $replay)
            ->replay('WF_2023', 'STAT-07', 'STRICT', 5, 20260812);
    }

    public function test_multiple_scopes_share_exactly_two_preflights_and_one_snapshot_session(): void
    {
        $preflight = Mockery::mock(Bt03PreflightService::class);
        $preflight->shouldReceive('run')->twice()->andReturn($this->preflightSummary());
        $snapshot = $this->snapshot();
        $verifier = Mockery::mock(Bt03OutcomeSnapshotVerifier::class);
        $verifier->shouldReceive('open')->once()->with(self::SNAPSHOT_PATH)->andReturn($snapshot);
        $snapshotSession = Mockery::mock(Bt02OutcomeContextSnapshotSession::class);
        $snapshotSession->shouldReceive('activate')->once()->with($snapshot);
        $snapshotSession->shouldReceive('deactivate')->once()->with(Bt03SourceManifest::OUTCOME_SNAPSHOT_MANIFEST_HASH);
        $replay = Mockery::mock(Bt03EvaluationReplayService::class);
        $replay->shouldReceive('replay')->times(3)->andReturnUsing(
            fn (string $fold, string $stat): Bt03EvaluationReplaySummaryDto => $this->replaySummary($stat),
        );
        $session = $this->service($preflight, $verifier, $snapshotSession, $replay);

        $summaries = $session->withVerifiedSession(fn (Bt03EvaluationReplayService $runner): array => [
            $runner->replay('WF_2023', 'STAT-07', 'STRICT', 5, 20260812),
            $runner->replay('WF_2023', 'STAT-08', 'STRICT', 5, 20260812),
            $runner->replay('WF_2023', 'STAT-10', 'STRICT', 5, 20260812),
        ]);

        $this->assertSame(['STAT-07', 'STAT-08', 'STAT-10'], array_map(
            fn (Bt03EvaluationReplaySummaryDto $summary): string => $summary->statCode,
            $summaries,
        ));
    }

    public function test_end_preflight_failure_rejects_the_computed_result_and_deactivates_snapshot(): void
    {
        $drift = new RuntimeException('effect_bins fingerprint mismatched');
        $preflight = Mockery::mock(Bt03PreflightService::class);
        $preflight->shouldReceive('run')->once()->andReturn($this->preflightSummary());
        $preflight->shouldReceive('run')->once()->andThrow($drift);
        $snapshot = $this->snapshot();
        $verifier = Mockery::mock(Bt03OutcomeSnapshotVerifier::class);
        $verifier->shouldReceive('open')->once()->andReturn($snapshot);
        $snapshotSession = Mockery::mock(Bt02OutcomeContextSnapshotSession::class);
        $snapshotSession->shouldReceive('activate')->once()->with($snapshot);
        $snapshotSession->shouldReceive('deactivate')->once()->with(Bt03SourceManifest::OUTCOME_SNAPSHOT_MANIFEST_HASH);
        $replay = Mockery::mock(Bt03EvaluationReplayService::class);
        $replay->shouldReceive('replay')->once()->andReturn($this->replaySummary('STAT-07'));

        try {
            $this->service($preflight, $verifier, $snapshotSession, $replay)
                ->replay('WF_2023', 'STAT-07', 'STRICT', 5, 20260812);
            $this->fail('End preflight drift must reject the computed result.');
        } catch (RuntimeException $exception) {
            $this->assertStringStartsWith('BT03_SOURCE_DRIFT_AFTER_REPLAY:', $exception->getMessage());
            $this->assertSame($drift, $exception->getPrevious());
        }
    }

    public function test_replay_failure_is_rethrown_without_end_preflight_and_snapshot_is_deactivated(): void
    {
        $failure = new LogicException('replay failed');
        $preflight = Mockery::mock(Bt03PreflightService::class);
        $preflight->shouldReceive('run')->once()->andReturn($this->preflightSummary());
        $snapshot = $this->snapshot();
        $verifier = Mockery::mock(Bt03OutcomeSnapshotVerifier::class);
        $verifier->shouldReceive('open')->once()->andReturn($snapshot);
        $snapshotSession = Mockery::mock(Bt02OutcomeContextSnapshotSession::class);
        $snapshotSession->shouldReceive('activate')->once()->with($snapshot);
        $snapshotSession->shouldReceive('deactivate')->once()->with(Bt03SourceManifest::OUTCOME_SNAPSHOT_MANIFEST_HASH);
        $replay = Mockery::mock(Bt03EvaluationReplayService::class);
        $replay->shouldReceive('replay')->once()->andThrow($failure);

        try {
            $this->service($preflight, $verifier, $snapshotSession, $replay)
                ->replay('WF_2023', 'STAT-07', 'STRICT', 5, 20260812);
            $this->fail('Replay failure must escape the verified session.');
        } catch (LogicException $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    public function test_only_the_preflight_verified_snapshot_path_is_opened(): void
    {
        $verifiedPath = 'private/backtest/bt02/custom/'.Bt03SourceManifest::OUTCOME_SNAPSHOT_MANIFEST_HASH;
        $preflight = Mockery::mock(Bt03PreflightService::class);
        $preflight->shouldReceive('run')->twice()->andReturn($this->preflightSummary($verifiedPath));
        $snapshot = $this->snapshot();
        $verifier = Mockery::mock(Bt03OutcomeSnapshotVerifier::class);
        $verifier->shouldReceive('open')->once()->with($verifiedPath)->andReturn($snapshot);
        $snapshotSession = Mockery::mock(Bt02OutcomeContextSnapshotSession::class);
        $snapshotSession->shouldReceive('activate')->once()->with($snapshot);
        $snapshotSession->shouldReceive('deactivate')->once()->with(Bt03SourceManifest::OUTCOME_SNAPSHOT_MANIFEST_HASH);
        $replay = Mockery::mock(Bt03EvaluationReplayService::class);
        $replay->shouldReceive('replay')->once()->andReturn($this->replaySummary('STAT-07'));

        $this->service($preflight, $verifier, $snapshotSession, $replay)
            ->replay('WF_2023', 'STAT-07', 'STRICT', 5, 20260812);

        $this->addToAssertionCount(1);
    }

    public function test_snapshot_opened_after_preflight_must_match_its_verified_manifest(): void
    {
        $preflight = Mockery::mock(Bt03PreflightService::class);
        $preflight->shouldReceive('run')->once()->andReturn($this->preflightSummary());
        $snapshot = $this->snapshot(str_repeat('f', 64));
        $verifier = Mockery::mock(Bt03OutcomeSnapshotVerifier::class);
        $verifier->shouldReceive('open')->once()->with(self::SNAPSHOT_PATH)->andReturn($snapshot);
        $snapshotSession = Mockery::mock(Bt02OutcomeContextSnapshotSession::class);
        $snapshotSession->shouldNotReceive('activate');
        $snapshotSession->shouldNotReceive('deactivate');
        $replay = Mockery::mock(Bt03EvaluationReplayService::class);
        $replay->shouldNotReceive('replay');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not match the preflight result');
        $this->service($preflight, $verifier, $snapshotSession, $replay)
            ->replay('WF_2023', 'STAT-07', 'STRICT', 5, 20260812);
    }

    public function test_nested_verified_session_is_rejected_and_outer_snapshot_is_deactivated(): void
    {
        $preflight = Mockery::mock(Bt03PreflightService::class);
        $preflight->shouldReceive('run')->once()->andReturn($this->preflightSummary());
        $snapshot = $this->snapshot();
        $verifier = Mockery::mock(Bt03OutcomeSnapshotVerifier::class);
        $verifier->shouldReceive('open')->once()->andReturn($snapshot);
        $snapshotSession = Mockery::mock(Bt02OutcomeContextSnapshotSession::class);
        $snapshotSession->shouldReceive('activate')->once()->with($snapshot);
        $snapshotSession->shouldReceive('deactivate')->once()->with(Bt03SourceManifest::OUTCOME_SNAPSHOT_MANIFEST_HASH);
        $replay = Mockery::mock(Bt03EvaluationReplayService::class);
        $replay->shouldNotReceive('replay');
        $session = $this->service($preflight, $verifier, $snapshotSession, $replay);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nesting was not allowed');
        $session->withVerifiedSession(fn (): mixed => $session->withVerifiedSession(fn (): null => null));
    }

    private function service(
        Bt03PreflightService $preflight,
        Bt03OutcomeSnapshotVerifier $verifier,
        Bt02OutcomeContextSnapshotSession $snapshotSession,
        Bt03EvaluationReplayService $replay,
    ): Bt03EvaluationReplaySessionService {
        return new Bt03EvaluationReplaySessionService($preflight, $verifier, $snapshotSession, $replay);
    }

    private function preflightSummary(string $snapshotPath = self::SNAPSHOT_PATH): Bt03PreflightSummaryDto
    {
        $hash = str_repeat('a', 64);

        return new Bt03PreflightSummaryDto(
            new Bt03SourceVerificationDto(
                5,
                3,
                14,
                432,
                648,
                668,
                432,
                432,
                new Bt03SourceArtifactFingerprintsDto($hash, $hash, $hash, $hash, $hash, $hash),
                $snapshotPath,
            ),
            Bt03SourceManifest::OUTCOME_SNAPSHOT_MANIFEST_HASH,
            4,
            new Bt02PreflightSummaryDto(56, 56, 56, str_repeat('b', 64)),
            Bt03SourceManifest::HASH,
        );
    }

    private function replaySummary(string $statCode): Bt03EvaluationReplaySummaryDto
    {
        return new Bt03EvaluationReplaySummaryDto(
            'WF_2023',
            $statCode,
            'STRICT',
            1,
            1,
            1,
            0,
            3,
            100,
            1,
            1,
            [],
        );
    }

    private function snapshot(string $hash = Bt03SourceManifest::OUTCOME_SNAPSHOT_MANIFEST_HASH): Bt02OutcomeContextSnapshotArtifact
    {
        $snapshot = Mockery::mock(Bt02OutcomeContextSnapshotArtifact::class);
        $snapshot->shouldReceive('manifestHash')->andReturn($hash);

        return $snapshot;
    }
}
