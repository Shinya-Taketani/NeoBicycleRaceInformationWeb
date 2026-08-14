<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Contracts\Bt02FingerprintRunner;
use App\Domain\Keirin\Backtest\Enums\Bt02FingerprintType;
use App\Domain\Keirin\Backtest\Exceptions\Bt02FingerprintMismatchException;
use App\Domain\Keirin\Backtest\Services\Bt01SourceManifest;
use App\Domain\Keirin\Backtest\Services\Bt02BaselineFingerprintManifest;
use App\Domain\Keirin\Backtest\Services\Bt02BaselineFingerprintPreflightService;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use PHPUnit\Framework\TestCase;

class Bt02BaselineFingerprintManifestTest extends TestCase
{
    public function test_fixed_four_run_manifest_has_frozen_metadata_content_and_identity(): void
    {
        $manifest = new Bt02BaselineFingerprintManifest;
        $entries = $manifest->entries();

        $this->assertSame(Bt02BaselineFingerprintManifest::HASH, $manifest->computedHash());
        $this->assertSame([2022, 2023, 2024, 2025], array_column($entries, 'year'));
        $this->assertSame([25, 26, 1, 27], array_column($entries, 'featureRunId'));
        $this->assertSame([174152, 181548, 182004, 180005], array_column($entries, 'rowCount'));
        foreach ($entries as $entry) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $entry->sourceFingerprintSha256);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $entry->contentFingerprintSha256);
            $this->assertSame(Bt01SourceManifest::CALCULATION_VERSION, $entry->calculationVersion);
        }
    }

    public function test_preflight_verifies_source_and_content_for_each_fixed_run(): void
    {
        $manifest = new Bt02BaselineFingerprintManifest;
        $digests = [];
        foreach ($manifest->entries() as $entry) {
            $digests[$entry->featureRunId] = [
                Bt02FingerprintType::Source->value => $entry->sourceFingerprintSha256,
                Bt02FingerprintType::Content->value => $entry->contentFingerprintSha256,
            ];
        }
        $runner = new class($digests) implements Bt02FingerprintRunner
        {
            /** @var list<string> */
            public array $calls = [];

            public function __construct(private readonly array $digests) {}

            public function assertVersionContract(): void
            {
                $this->calls[] = 'version';
            }

            public function fingerprint(int $runId, Bt02FingerprintType $type): string
            {
                $this->calls[] = "{$runId}:{$type->value}";

                return $this->digests[$runId][$type->value];
            }
        };

        (new Bt02BaselineFingerprintPreflightService(
            $manifest,
            new Bt01SourceManifest(new CanonicalHasher),
            $runner,
        ))->run();

        $this->assertSame([
            'version',
            '25:source', '25:content',
            '26:source', '26:content',
            '1:source', '1:content',
            '27:source', '27:content',
        ], $runner->calls);
    }

    public function test_one_byte_baseline_digest_mismatch_fails_closed(): void
    {
        $runner = new class implements Bt02FingerprintRunner
        {
            public function assertVersionContract(): void {}

            public function fingerprint(int $runId, Bt02FingerprintType $type): string
            {
                return str_repeat('0', 64);
            }
        };

        $this->expectException(Bt02FingerprintMismatchException::class);
        (new Bt02BaselineFingerprintPreflightService(
            new Bt02BaselineFingerprintManifest,
            new Bt01SourceManifest(new CanonicalHasher),
            $runner,
        ))->run();
    }
}
