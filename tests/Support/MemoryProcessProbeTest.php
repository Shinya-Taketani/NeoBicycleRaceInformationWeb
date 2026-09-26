<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\TestCase;
use RuntimeException;

// Invoked explicitly by the helper regression, never discovered by the normal suite.
final class MemoryProcessProbeTest extends TestCase
{
    public function test_probe(): void
    {
        switch (getenv('PR72_PROBE_MODE')) {
            case 'failure':
                $this->fail('synthetic child assertion failure');
            case 'exit':
                fwrite(STDERR, 'synthetic child exit');
                exit(17);
            case 'timeout':
                sleep(30);
                break;
        }
        $this->assertTrue(true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (getenv('PR72_PROBE_MODE') === 'teardown') {
            throw new RuntimeException('synthetic child teardown failure');
        }
    }
}
