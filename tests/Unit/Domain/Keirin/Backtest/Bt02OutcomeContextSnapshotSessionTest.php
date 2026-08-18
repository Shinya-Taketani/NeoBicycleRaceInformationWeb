<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Contracts\Bt02OutcomeContextSnapshot;
use App\Domain\Keirin\Backtest\DTO\FoldDefinitionDto;
use App\Domain\Keirin\Backtest\Services\Bt02OutcomeContextSnapshotSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class Bt02OutcomeContextSnapshotSessionTest extends TestCase
{
    public function test_matching_manifest_deactivates_the_snapshot(): void
    {
        $session = new Bt02OutcomeContextSnapshotSession;
        $snapshot = $this->snapshot(str_repeat('a', 64));
        $session->activate($snapshot);

        $session->deactivate(str_repeat('a', 64));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('was not activated');
        $session->snapshot();
    }

    public function test_mismatched_manifest_does_not_release_the_active_snapshot(): void
    {
        $session = new Bt02OutcomeContextSnapshotSession;
        $snapshot = $this->snapshot(str_repeat('a', 64));
        $session->activate($snapshot);

        try {
            $session->deactivate(str_repeat('b', 64));
            $this->fail('A mismatched manifest must not release the snapshot.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('did not match', $exception->getMessage());
        }
        $this->assertSame($snapshot, $session->snapshot());
    }

    private function snapshot(string $hash): Bt02OutcomeContextSnapshot
    {
        return new class($hash) implements Bt02OutcomeContextSnapshot
        {
            public function __construct(private readonly string $hash) {}

            public function chunks(FoldDefinitionDto $fold, int $chunkSize): \Generator
            {
                if (false) {
                    yield [];
                }
            }

            public function auditParameters(): array
            {
                return [];
            }

            public function manifestHash(): string
            {
                return $this->hash;
            }
        };
    }
}
