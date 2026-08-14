<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Contracts\Bt02FingerprintRunner;
use App\Domain\Keirin\Backtest\Enums\Bt02FingerprintType;
use App\Domain\Keirin\Backtest\Exceptions\Bt02FingerprintMismatchException;
use RuntimeException;

class Bt02BaselineFingerprintPreflightService
{
    public function __construct(
        private readonly Bt02BaselineFingerprintManifest $manifest,
        private readonly Bt01SourceManifest $baselineSources,
        private readonly Bt02FingerprintRunner $fingerprintRunner,
    ) {}

    public function run(): void
    {
        if ($this->manifest->computedHash() !== Bt02BaselineFingerprintManifest::HASH) {
            throw new RuntimeException('BT-02 baseline fingerprint manifest hash was invalid.');
        }
        $entries = $this->manifest->entries();
        if (count($entries) !== 4) {
            throw new RuntimeException('BT-02 baseline fingerprint requires four fixed STAT-01 runs.');
        }
        foreach ($entries as $entry) {
            $source = $this->baselineSources->forYear($entry->year);
            if ($entry->featureRunId !== $source->featureRunId
                || $entry->featureRunUuid !== $source->featureRunUuid
                || $entry->calculationVersion !== Bt01SourceManifest::CALCULATION_VERSION
                || $entry->targetFrom !== $source->targetFrom
                || $entry->targetTo !== $source->targetTo
                || $entry->raceCount !== $source->expectedRaceCount
                || $entry->rowCount !== $source->expectedResultCount) {
                throw new RuntimeException("BT-02 baseline fingerprint metadata differed for {$entry->year}.");
            }
        }

        $this->fingerprintRunner->assertVersionContract();
        foreach ($entries as $entry) {
            $this->assertDigest($entry->year, $entry->featureRunId, Bt02FingerprintType::Source, $entry->sourceFingerprintSha256);
            $this->assertDigest($entry->year, $entry->featureRunId, Bt02FingerprintType::Content, $entry->contentFingerprintSha256);
        }
    }

    private function assertDigest(int $year, int $runId, Bt02FingerprintType $type, string $expected): void
    {
        $actual = $this->fingerprintRunner->fingerprint($runId, $type);
        if (! hash_equals($expected, $actual)) {
            throw new Bt02FingerprintMismatchException($year, 'STAT-01', $runId, $type->value, $expected, $actual);
        }
    }
}
