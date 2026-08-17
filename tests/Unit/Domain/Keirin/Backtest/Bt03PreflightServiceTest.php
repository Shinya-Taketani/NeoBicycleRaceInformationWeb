<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\DTO\Bt02PreflightSummaryDto;
use App\Domain\Keirin\Backtest\DTO\Bt03SourceArtifactFingerprintsDto;
use App\Domain\Keirin\Backtest\DTO\Bt03SourceVerificationDto;
use App\Domain\Keirin\Backtest\Services\Bt02BaselineFingerprintPreflightService;
use App\Domain\Keirin\Backtest\Services\Bt02FingerprintPreflightService;
use App\Domain\Keirin\Backtest\Services\Bt03OutcomeSnapshotVerifier;
use App\Domain\Keirin\Backtest\Services\Bt03PreflightService;
use App\Domain\Keirin\Backtest\Services\Bt03SourceManifest;
use App\Domain\Keirin\Backtest\Services\Bt03SourceVerifier;
use App\Domain\Keirin\Backtest\Support\Bt02ModelArtifactHasher;
use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class Bt03PreflightServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_verifies_fixed_source_snapshot_and_existing_bt02_fingerprints(): void
    {
        $source = $this->sourceVerification();
        $sourceVerifier = Mockery::mock(Bt03SourceVerifier::class);
        $sourceVerifier->shouldReceive('verify')->once()->ordered()->andReturn($source);
        $snapshotVerifier = Mockery::mock(Bt03OutcomeSnapshotVerifier::class);
        $snapshotVerifier->shouldReceive('verify')
            ->once()->ordered()->with($source->outcomeSnapshotPath)
            ->andReturn(Bt03SourceManifest::OUTCOME_SNAPSHOT_MANIFEST_HASH);
        $baseline = Mockery::mock(Bt02BaselineFingerprintPreflightService::class);
        $baseline->shouldReceive('run')->once()->ordered();
        $bt02 = Mockery::mock(Bt02FingerprintPreflightService::class);
        $bt02->shouldReceive('run')->once()->ordered()->andReturn(new Bt02PreflightSummaryDto(56, 56, 56, str_repeat('b', 64)));

        $summary = (new Bt03PreflightService(
            new Bt03SourceManifest(new Bt02ModelArtifactHasher),
            $sourceVerifier,
            $snapshotVerifier,
            $baseline,
            $bt02,
        ))->run();

        $this->assertSame($source, $summary->source);
        $this->assertSame(Bt03SourceManifest::OUTCOME_SNAPSHOT_MANIFEST_HASH, $summary->outcomeSnapshotManifestHash);
        $this->assertSame(4, $summary->baselineFingerprintMatches);
        $this->assertSame(56, $summary->bt02->sourceFingerprintMatches);
        $this->assertSame(Bt03SourceManifest::HASH, $summary->sourceManifestHash);
    }

    public function test_it_stops_before_dependent_checks_when_source_verification_fails(): void
    {
        $sourceVerifier = Mockery::mock(Bt03SourceVerifier::class);
        $sourceVerifier->shouldReceive('verify')->once()->andThrow(new RuntimeException('source rejected'));
        $snapshotVerifier = Mockery::mock(Bt03OutcomeSnapshotVerifier::class);
        $snapshotVerifier->shouldNotReceive('verify');
        $baseline = Mockery::mock(Bt02BaselineFingerprintPreflightService::class);
        $baseline->shouldNotReceive('run');
        $bt02 = Mockery::mock(Bt02FingerprintPreflightService::class);
        $bt02->shouldNotReceive('run');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('source rejected');
        (new Bt03PreflightService(
            new Bt03SourceManifest(new Bt02ModelArtifactHasher),
            $sourceVerifier,
            $snapshotVerifier,
            $baseline,
            $bt02,
        ))->run();
    }

    private function sourceVerification(): Bt03SourceVerificationDto
    {
        $hash = str_repeat('a', 64);

        return new Bt03SourceVerificationDto(
            5,
            3,
            14,
            432,
            648,
            668,
            432,
            432,
            new Bt03SourceArtifactFingerprintsDto($hash, $hash, $hash, $hash, $hash, $hash),
            'private/backtest/bt02/outcome-context/'.Bt03SourceManifest::OUTCOME_SNAPSHOT_MANIFEST_HASH,
        );
    }
}
