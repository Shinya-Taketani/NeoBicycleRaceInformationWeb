<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\DTO\Bt03CenteredResidualEntryDto;
use App\Domain\Keirin\Backtest\DTO\Bt03CenteredSpoolIdentityDto;
use App\Domain\Keirin\Backtest\Support\Bt03CenteredResidualSpool;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class Bt03CenteredResidualSpoolTest extends TestCase
{
    public function test_sealed_scope_spool_replays_grouped_races_and_detects_corruption(): void
    {
        $spool = $this->spool();
        $spool->append(10, new Bt03CenteredResidualEntryDto(101, 1, 1, 0.2));
        $spool->append(10, new Bt03CenteredResidualEntryDto(102, 2, 0, 0.3));
        $spool->append(11, new Bt03CenteredResidualEntryDto(103, 1, 0, 0.4));
        $metadata = $spool->seal();

        $payloads = iterator_to_array($spool->payloads(), false);
        $this->assertSame(3, $metadata->rowCount);
        $this->assertSame(2, $metadata->raceCount);
        $this->assertSame([10, 11], array_column($payloads, 'raceId'));
        $this->assertSame([1, 2], array_column($payloads[0]->entries, 'binIndex'));

        file_put_contents($spool->path(), "corrupt\n", FILE_APPEND);
        try {
            iterator_to_array($spool->payloads(), false);
            $this->fail('Corrupted centered residual spool must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('spool', $exception->getMessage());
        } finally {
            $path = $spool->path();
            $spool->cleanup();
            $this->assertFileDoesNotExist($path);
        }
    }

    public function test_race_reappearance_is_rejected_before_seal(): void
    {
        $spool = $this->spool();
        try {
            $spool->append(10, new Bt03CenteredResidualEntryDto(101, 1, 1, 0.2));
            $spool->append(11, new Bt03CenteredResidualEntryDto(102, 1, 0, 0.3));
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('race grouping');
            $spool->append(10, new Bt03CenteredResidualEntryDto(103, 2, 0, 0.4));
        } finally {
            $spool->cleanup();
        }
    }

    private function spool(): Bt03CenteredResidualSpool
    {
        return new Bt03CenteredResidualSpool(new Bt03CenteredSpoolIdentityDto(
            'WF_2023',
            'STAT-07',
            'STRICT',
            'IS_WIN',
        ));
    }
}
