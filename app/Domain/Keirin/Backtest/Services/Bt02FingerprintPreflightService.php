<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Contracts\Bt02FingerprintRunner;
use App\Domain\Keirin\Backtest\DTO\Bt02PreflightProgressDto;
use App\Domain\Keirin\Backtest\DTO\Bt02PreflightSummaryDto;
use App\Domain\Keirin\Backtest\DTO\Bt02SourceManifestEntryDto;
use App\Domain\Keirin\Backtest\Enums\Bt02FingerprintType;
use App\Domain\Keirin\Backtest\Exceptions\Bt02FingerprintMismatchException;
use App\Domain\Keirin\Backtest\Repositories\Bt02SourceVerifier;
use RuntimeException;

class Bt02FingerprintPreflightService
{
    public function __construct(
        private readonly Bt02SourceManifest $manifest,
        private readonly Bt02SourceVerifier $sourceVerifier,
        private readonly Bt02FingerprintRunner $fingerprintRunner,
    ) {}

    /** @param (callable(Bt02PreflightProgressDto): void)|null $progress */
    public function run(?callable $progress = null): Bt02PreflightSummaryDto
    {
        if ($this->manifest->computedHash() !== Bt02SourceManifest::HASH) {
            throw new RuntimeException('BT-02 source manifest hash did not match its frozen V1 identity.');
        }
        $entries = $this->manifest->entries();
        if (count($entries) !== 56) {
            throw new RuntimeException('BT-02 fingerprint preflight requires all 56 fixed runs.');
        }
        $this->sourceVerifier->verify($entries);
        $this->fingerprintRunner->assertVersionContract();

        $sourceMatches = 0;
        $contentMatches = 0;
        foreach ($entries as $offset => $entry) {
            $this->progress($progress, $offset + 1, $entry, 'metadata');
            $source = $this->fingerprintRunner->fingerprint($entry->featureRunId, Bt02FingerprintType::Source);
            $this->assertDigest($entry, Bt02FingerprintType::Source, $entry->sourceFingerprintSha256, $source);
            $sourceMatches++;
            $this->progress($progress, $offset + 1, $entry, 'source');

            $content = $this->fingerprintRunner->fingerprint($entry->featureRunId, Bt02FingerprintType::Content);
            $this->assertDigest($entry, Bt02FingerprintType::Content, $entry->contentFingerprintSha256, $content);
            $contentMatches++;
            $this->progress($progress, $offset + 1, $entry, 'content');
        }

        return new Bt02PreflightSummaryDto(count($entries), $sourceMatches, $contentMatches, Bt02SourceManifest::HASH);
    }

    private function progress(?callable $progress, int $index, Bt02SourceManifestEntryDto $entry, string $stage): void
    {
        if ($progress !== null) {
            $progress(new Bt02PreflightProgressDto($index, 56, $entry->year, $entry->statCode, $entry->featureRunId, $stage));
        }
    }

    private function assertDigest(Bt02SourceManifestEntryDto $entry, Bt02FingerprintType $type, string $expected, string $actual): void
    {
        if (! hash_equals($expected, $actual)) {
            throw new Bt02FingerprintMismatchException($entry->year, $entry->statCode, $entry->featureRunId, $type->value, $expected, $actual);
        }
    }
}
