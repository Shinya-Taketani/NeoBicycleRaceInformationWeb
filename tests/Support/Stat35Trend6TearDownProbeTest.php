<?php

declare(strict_types=1);

namespace Tests\Support;

use ReflectionProperty;
use RuntimeException;
use Tests\Feature\Stat35Trend6P1Test;

// Explicit subprocess-only artificial failures; not discovered by the normal Feature/Unit suites.
final class Stat35Trend6TearDownProbeTest extends Stat35Trend6P1Test
{
    private CompositionResultTemporaryDirectory $probeDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $property = new ReflectionProperty(Stat35Trend6P1Test::class, 'temporary');
        $this->probeDirectory = $property->getValue($this);
        $mode = getenv('PR103_PROBE_MODE');
        if (in_array($mode, ['move_failure', 'mount'], true)) {
            $this->probeDirectory->retire($this->probeDirectory->path(), 'TEST_COMPLETE');
            $this->probeDirectory = $mode === 'mount' ? Stat35Trend6ProbeMountDirectory::create() : Stat35Trend6ProbeFailingMoveDirectory::create();
            $property->setValue($this, $this->probeDirectory);
        }
        file_put_contents($this->probeDirectory->path().'/sentinel', 'artificial original retained');
        if ($mode === 'symlink') {
            symlink($this->probeDirectory->path().'/sentinel', $this->probeDirectory->path().'/link');
        }
    }

    public function test_probe(): void
    {
        $this->assertSame('128M', ini_get('memory_limit'));
        if (getenv('PR103_PROBE_MODE') === 'failure') {
            $this->fail('INTENTIONAL_ASSERTION_FAILURE');
        }
        if (getenv('PR103_PROBE_MODE') !== 'success') {
            throw new RuntimeException('INTENTIONAL_TEST_EXCEPTION');
        }
        $this->assertTrue(true);
    }

    protected function tearDown(): void
    {
        $error = null;
        try {
            parent::tearDown();
        } catch (\Throwable $failure) {
            $error = $failure->getMessage();
            throw $failure;
        } finally {
            $handle = fopen(getenv('PR103_PROBE_OUTPUT').'/teardown.json', 'xb');
            if ($handle === false) {
                throw new RuntimeException('Cannot exclusively save synthetic teardown evidence.');
            }
            fwrite($handle, json_encode(['source' => $this->probeDirectory->path(), 'parent_executed' => $this->app === null,
                'status' => $this->status()->asString(), 'cleanup_error' => $error], JSON_THROW_ON_ERROR)."\n");
            fclose($handle);
        }
    }
}

final class Stat35Trend6ProbeFailingMoveDirectory extends CompositionResultTemporaryDirectory
{
    protected function renameOnly(string $source, string $destination): void
    {
        throw new RuntimeException('INTENTIONAL_RENAME_FAILURE');
    }
}

final class Stat35Trend6ProbeMountDirectory extends CompositionResultTemporaryDirectory
{
    protected function mountPoints(): array
    {
        return [...parent::mountPoints(), $this->path().'/artificial-same-device-mount'];
    }
}
