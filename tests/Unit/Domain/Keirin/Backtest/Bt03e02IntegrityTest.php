<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Services\Bt03e02ArtifactWriter;
use App\Domain\Keirin\Backtest\Services\Bt03e02SourceIntegrityGuard;
use App\Domain\Keirin\Backtest\Services\Bt03eArtifactFilesystem;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class Bt03e02IntegrityTest extends TestCase
{
    public function test_source_drift_fails_closed(): void
    {
        $guard = new Bt03e02SourceIntegrityGuard;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('feature source fingerprints drifted');

        $guard->assertUnchanged(['digest' => str_repeat('a', 64)], ['digest' => str_repeat('b', 64)], 'feature source fingerprints');
    }

    public function test_failed_publication_removes_the_temporary_bundle(): void
    {
        $directory = sys_get_temp_dir().'/bt03e02-artifact-'.bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $filesystem = new class extends Bt03eArtifactFilesystem
        {
            public function publish(string $temporaryDirectory, string $finalDirectory): void
            {
                throw new RuntimeException('synthetic publication failure');
            }
        };

        try {
            $writer = new Bt03e02ArtifactWriter($filesystem, new CanonicalHasher);
            try {
                $writer->write($directory, ['run_identity' => 'synthetic']);
                $this->fail('Publication must fail.');
            } catch (RuntimeException $exception) {
                $this->assertSame('synthetic publication failure', $exception->getMessage());
            }

            $this->assertSame([], array_values(array_diff(scandir($directory) ?: [], ['.', '..'])));
        } finally {
            rmdir($directory);
        }
    }

    public function test_reproducibility_hash_excludes_run_identity_and_runtime(): void
    {
        $directory = sys_get_temp_dir().'/bt03e02-reproducibility-'.bin2hex(random_bytes(8));
        $writer = new Bt03e02ArtifactWriter(new Bt03eArtifactFilesystem, new CanonicalHasher);

        try {
            $first = $writer->write($directory, [
                'run_identity' => 'run-1',
                'runtime' => ['seconds' => 1.0],
                'metrics' => ['WINNER_HIT_AT_1' => 0.4],
            ]);
            $second = $writer->write($directory, [
                'run_identity' => 'run-2',
                'runtime' => ['seconds' => 2.0],
                'metrics' => ['WINNER_HIT_AT_1' => 0.4],
            ]);

            $this->assertSame($first['reproducibility_hash'], $second['reproducibility_hash']);
            $this->assertNotSame($first['result_sha256'], $second['result_sha256']);
        } finally {
            if (isset($first['bundle_directory'])) {
                (new Bt03eArtifactFilesystem)->removeDirectory($first['bundle_directory']);
            }
            if (isset($second['bundle_directory'])) {
                (new Bt03eArtifactFilesystem)->removeDirectory($second['bundle_directory']);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }
}
