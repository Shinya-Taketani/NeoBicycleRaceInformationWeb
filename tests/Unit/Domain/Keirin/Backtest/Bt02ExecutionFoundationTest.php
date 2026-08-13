<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\ExternalSortEffectBinBoundaryProvider;
use App\Domain\Keirin\Backtest\Calculators\InMemoryEffectBinBoundaryProvider;
use App\Domain\Keirin\Backtest\Calculators\Type7Quantile;
use App\Domain\Keirin\Backtest\Contracts\Bt02FingerprintRunner;
use App\Domain\Keirin\Backtest\DTO\Bt02SourceManifestEntryDto;
use App\Domain\Keirin\Backtest\DTO\LogisticTrainingRowDto;
use App\Domain\Keirin\Backtest\DTO\PgConnectionConfigDto;
use App\Domain\Keirin\Backtest\Enums\Bt02FingerprintType;
use App\Domain\Keirin\Backtest\Exceptions\Bt02FingerprintMismatchException;
use App\Domain\Keirin\Backtest\Repositories\Bt02SourceVerifier;
use App\Domain\Keirin\Backtest\Repositories\PgCopyFingerprintRunner;
use App\Domain\Keirin\Backtest\Services\Bt02FingerprintPreflightService;
use App\Domain\Keirin\Backtest\Services\Bt02SourceManifest;
use App\Domain\Keirin\Backtest\Support\Bt02FingerprintCopySql;
use App\Domain\Keirin\Backtest\Support\Bt02TrainingSpoolFactory;
use App\Domain\Keirin\Backtest\Support\ImmutableBt02Spool;
use App\Domain\Keirin\Backtest\Support\SpoolLogisticTrainingRowSource;
use Generator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class Bt02ExecutionFoundationTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                @rmdir($path);
            }
        }
        parent::tearDown();
    }

    public function test_copy_sql_is_the_frozen_source_and_content_contract(): void
    {
        $sql = new Bt02FingerprintCopySql;
        $source = $sql->for(51, Bt02FingerprintType::Source);
        $content = $sql->for(51, Bt02FingerprintType::Content);

        $this->assertStringContainsString('WHERE feature_run_id = 51', $source);
        $this->assertStringContainsString("NULL '\\N'", $source);
        $this->assertStringContainsString("QUOTE E'\\x22'", $source);
        $this->assertStringContainsString('race_id ASC NULLS FIRST', $source);
        $this->assertStringNotContainsString('features::text', $source);
        foreach (['features::text', 'evidence::text', 'raw_points::text', 'confidence::text', 'effective_points::text'] as $column) {
            $this->assertStringContainsString($column, $content);
        }
    }

    public function test_psql_client_and_server_version_guards_fail_closed(): void
    {
        $wrongClient = $this->script(<<<'SH'
#!/bin/sh
echo 'psql (PostgreSQL) 17.9'
SH);
        $this->assertCallbackThrows(
            fn () => $this->pgRunner($wrongClient)->assertVersionContract(),
            RuntimeException::class,
        );

        $wrongServer = $this->script(<<<'SH'
#!/bin/sh
if [ "$1" = "--version" ]; then
  echo 'psql (PostgreSQL) 18.4'
  exit 0
fi
cat >/dev/null
echo '180003'
SH);
        $this->assertCallbackThrows(
            fn () => $this->pgRunner($wrongServer)->assertVersionContract(),
            RuntimeException::class,
        );
    }

    public function test_nonzero_psql_copy_exit_is_rejected_without_hashing_stderr(): void
    {
        $failure = $this->script(<<<'SH'
#!/bin/sh
cat >/dev/null
echo 'simulated psql failure' >&2
exit 7
SH);

        $this->assertCallbackThrows(
            fn () => $this->pgRunner($failure)->fingerprint(1, Bt02FingerprintType::Source),
            RuntimeException::class,
        );
    }

    public function test_preflight_requires_all_56_expected_source_and_content_digests(): void
    {
        $manifest = new Bt02SourceManifest;
        $runner = $this->manifestRunner($manifest);
        $progress = [];
        $summary = (new Bt02FingerprintPreflightService($manifest, $this->acceptingVerifier(), $runner))
            ->run(function ($event) use (&$progress): void {
                $progress[] = [$event->index, $event->stage];
            });

        $this->assertSame(56, $summary->verifiedRuns);
        $this->assertSame(56, $summary->sourceFingerprintMatches);
        $this->assertSame(56, $summary->contentFingerprintMatches);
        $this->assertSame(Bt02SourceManifest::HASH, $summary->manifestHash);
        $this->assertCount(168, $progress);
        $this->assertSame(112, $runner->fingerprintCalls);
        $this->assertTrue($runner->versionChecked);
    }

    public function test_preflight_rejects_wrong_digest_and_metadata_failure_prevents_psql(): void
    {
        $manifest = new Bt02SourceManifest;
        $wrong = $this->manifestRunner($manifest, true);
        $this->assertCallbackThrows(
            fn () => (new Bt02FingerprintPreflightService($manifest, $this->acceptingVerifier(), $wrong))->run(),
            Bt02FingerprintMismatchException::class,
        );

        $wrongContent = $this->manifestRunner($manifest, false, true);
        $this->assertCallbackThrows(
            fn () => (new Bt02FingerprintPreflightService($manifest, $this->acceptingVerifier(), $wrongContent))->run(),
            Bt02FingerprintMismatchException::class,
        );

        $unused = $this->manifestRunner($manifest);
        $this->assertCallbackThrows(
            fn () => (new Bt02FingerprintPreflightService($manifest, $this->rejectingVerifier(), $unused))->run(),
            RuntimeException::class,
        );
        $this->assertFalse($unused->versionChecked);
        $this->assertSame(0, $unused->fingerprintCalls);
    }

    public function test_immutable_spool_is_write_once_seal_once_and_replayable(): void
    {
        $spool = new ImmutableBt02Spool;
        $this->temporaryPaths[] = $spool->path();
        $rows = [
            new LogisticTrainingRowDto([1.0, -2.5], 0),
            new LogisticTrainingRowDto([0.0001, 3.25], 1),
        ];
        foreach ($rows as $row) {
            $spool->append($row);
        }
        $metadata = $spool->seal();

        $this->assertSame(2, $metadata->rowCount);
        $this->assertSame(filesize($spool->path()), $metadata->byteCount);
        $this->assertSame(hash_file('sha256', $spool->path()), $metadata->sha256);
        $this->assertSame(ImmutableBt02Spool::FORMAT_VERSION, $metadata->formatVersion);
        $handle = fopen($spool->path(), 'rb');
        fseek($handle, -1, SEEK_END);
        $this->assertSame("\n", fread($handle, 1));
        fclose($handle);
        $this->assertCallbackThrows(fn () => $spool->append($rows[0]), RuntimeException::class);
        $this->assertCallbackThrows(fn () => $spool->seal(), RuntimeException::class);

        $source = new SpoolLogisticTrainingRowSource($spool);
        $this->assertInstanceOf(Generator::class, $source->rows());
        $first = iterator_to_array($source->rows(), false);
        $second = iterator_to_array($source->rows(), false);
        $this->assertEquals($rows, $first);
        $this->assertEquals($first, $second);
    }

    public function test_spool_hash_is_deterministic_and_corruption_fails_closed(): void
    {
        $row = new LogisticTrainingRowDto([1.0, -0.0], 1);
        $first = new ImmutableBt02Spool;
        $second = new ImmutableBt02Spool;
        $this->temporaryPaths[] = $first->path();
        $this->temporaryPaths[] = $second->path();
        $first->append($row);
        $second->append($row);
        $this->assertSame($first->seal()->sha256, $second->seal()->sha256);

        file_put_contents($first->path(), "corrupt\n");
        $this->assertCallbackThrows(
            fn () => iterator_to_array((new SpoolLogisticTrainingRowSource($first))->rows()),
            \Throwable::class,
        );
    }

    public function test_incomplete_spool_cleanup_is_explicit(): void
    {
        $spool = new ImmutableBt02Spool;
        $path = $spool->path();
        $spool->append(new LogisticTrainingRowDto([1.0], 1));
        $spool->cleanup();

        $this->assertFileDoesNotExist($path);
        $this->assertCallbackThrows(fn () => $spool->seal(), RuntimeException::class);
    }

    public function test_training_spool_factory_seals_a_generator_once_and_cleans_up_failure(): void
    {
        $directory = $this->temporaryDirectory();
        $factory = new Bt02TrainingSpoolFactory($directory);
        $spool = $factory->create((function (): Generator {
            yield new LogisticTrainingRowDto([1.0, 2.0], 1);
            yield new LogisticTrainingRowDto([3.0, 4.0], 0);
        })());
        $this->temporaryPaths[] = $spool->path();

        $this->assertTrue($spool->isSealed());
        $this->assertSame(2, $spool->metadata()->rowCount);

        $this->assertCallbackThrows(
            fn () => $factory->create((function (): Generator {
                yield new LogisticTrainingRowDto([1.0], 1);
                yield 'invalid';
            })()),
            RuntimeException::class,
        );
        $this->assertSame([basename($spool->path())], array_values(array_diff(scandir($directory), ['.', '..'])));
    }

    public function test_external_sort_matches_in_memory_type7_for_numeric_streams(): void
    {
        $directory = $this->temporaryDirectory();
        $values = [-100.5, -3.0, -0.0001, 0.0, 1e-4, 0.5, 1.0, 2.5, 3.0, 10.0, 20.0, 100.0, 1e4];
        $external = new ExternalSortEffectBinBoundaryProvider($directory);
        $memory = new InMemoryEffectBinBoundaryProvider(new Type7Quantile);
        $expected = $memory->build($values);
        $actual = $external->build((function () use ($values): Generator {
            yield from array_reverse($values);
        })());

        $this->assertEquals($expected, $actual);
        $this->assertEquals($actual, $external->build($values));
        $this->assertSame([], array_values(array_diff(scandir($directory), ['.', '..'])));
    }

    public function test_external_sort_merges_duplicate_boundaries_and_counts_every_value(): void
    {
        $directory = $this->temporaryDirectory();
        $values = [...array_fill(0, 90, 0.0), ...range(1, 11)];
        $bins = (new ExternalSortEffectBinBoundaryProvider($directory))->build($values);

        $this->assertLessThanOrEqual(10, count($bins));
        $this->assertSame(count($values), array_sum(array_map(fn ($bin): int => $bin->trainingSampleCount, $bins)));
        $this->assertSame([], array_values(array_diff(scandir($directory), ['.', '..'])));
    }

    public function test_external_sort_preserves_natural_categories_and_rejects_high_cardinality_strings(): void
    {
        $directory = $this->temporaryDirectory();
        $provider = new ExternalSortEffectBinBoundaryProvider($directory);
        $bins = $provider->build(['B', 'A', 'A']);
        $this->assertSame(['A', 'B'], array_map(fn ($bin): ?string => $bin->categoryValue, $bins));
        $this->assertSame([2, 1], array_map(fn ($bin): int => $bin->trainingSampleCount, $bins));

        $this->assertCallbackThrows(
            fn () => $provider->build(array_map(fn (int $index): string => "category-{$index}", range(1, 11))),
            InvalidArgumentException::class,
        );
        $this->assertSame([], array_values(array_diff(scandir($directory), ['.', '..'])));
    }

    private function pgRunner(string $binary): PgCopyFingerprintRunner
    {
        return new PgCopyFingerprintRunner(
            new PgConnectionConfigDto('127.0.0.1', '5432', 'unused', 'unused', ''),
            $binary,
        );
    }

    private function script(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bt02-script-');
        if ($path === false) {
            throw new RuntimeException('Could not create test script.');
        }
        file_put_contents($path, $contents."\n");
        chmod($path, 0700);
        $this->temporaryPaths[] = $path;

        return $path;
    }

    private function temporaryDirectory(): string
    {
        $path = sys_get_temp_dir().'/bt02-test-'.bin2hex(random_bytes(8));
        mkdir($path, 0700, true);
        $this->temporaryPaths[] = $path;

        return $path;
    }

    private function acceptingVerifier(): Bt02SourceVerifier
    {
        return new class extends Bt02SourceVerifier
        {
            public function verify(array $entries): void
            {
                if (count($entries) !== 56) {
                    throw new RuntimeException('Expected 56 test entries.');
                }
            }
        };
    }

    private function rejectingVerifier(): Bt02SourceVerifier
    {
        return new class extends Bt02SourceVerifier
        {
            public function verify(array $entries): void
            {
                throw new RuntimeException('metadata mismatch');
            }
        };
    }

    private function manifestRunner(
        Bt02SourceManifest $manifest,
        bool $wrongFirstSource = false,
        bool $wrongFirstContent = false,
    ): Bt02FingerprintRunner {
        return new class($manifest, $wrongFirstSource, $wrongFirstContent) implements Bt02FingerprintRunner
        {
            public bool $versionChecked = false;

            public int $fingerprintCalls = 0;

            /** @var array<int, Bt02SourceManifestEntryDto> */
            private array $entries;

            public function __construct(
                Bt02SourceManifest $manifest,
                private readonly bool $wrongFirstSource,
                private readonly bool $wrongFirstContent,
            ) {
                $this->entries = [];
                foreach ($manifest->entries() as $entry) {
                    $this->entries[$entry->featureRunId] = $entry;
                }
            }

            public function assertVersionContract(): void
            {
                $this->versionChecked = true;
            }

            public function fingerprint(int $runId, Bt02FingerprintType $type): string
            {
                $this->fingerprintCalls++;
                $entry = $this->entries[$runId];
                if ($this->wrongFirstSource && $this->fingerprintCalls === 1) {
                    return str_repeat('0', 64);
                }
                if ($this->wrongFirstContent && $type === Bt02FingerprintType::Content) {
                    return str_repeat('0', 64);
                }

                return $type === Bt02FingerprintType::Source
                    ? $entry->sourceFingerprintSha256
                    : $entry->contentFingerprintSha256;
            }
        };
    }

    /** @param class-string<\Throwable> $exceptionClass */
    private function assertCallbackThrows(callable $callback, string $exceptionClass): void
    {
        try {
            $callback();
            $this->fail("Expected {$exceptionClass} was not thrown.");
        } catch (\Throwable $exception) {
            $this->assertInstanceOf($exceptionClass, $exception);
        }
    }
}
