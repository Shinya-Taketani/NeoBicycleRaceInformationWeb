<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\DTO\Bt03BinEffectEntryDto;
use App\Domain\Keirin\Backtest\DTO\Bt03BinSpoolIdentityDto;
use App\Domain\Keirin\Backtest\Support\Bt03BinEffectSpool;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class Bt03BinEffectSpoolTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/bt03-bin-effect-spool-test-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_spool_is_deterministic_and_replays_each_race_as_one_payload(): void
    {
        $first = $this->spool();
        $second = $this->spool();

        foreach ([$first, $second] as $spool) {
            $spool->append(10, new Bt03BinEffectEntryDto(101, 1, 0.2, 0.3));
            $spool->append(10, new Bt03BinEffectEntryDto(102, 0, 0.4, 0.5));
            $spool->append(11, new Bt03BinEffectEntryDto(103, 1, 0.6, 0.7));
            $spool->seal();
        }

        $this->assertSame($first->metadata()->sha256, $second->metadata()->sha256);
        $this->assertSame(3, $first->metadata()->rowCount);
        $this->assertSame(2, $first->metadata()->raceCount);
        $payloads = iterator_to_array($first->payloads(), false);
        $this->assertCount(2, $payloads);
        $this->assertSame(10, $payloads[0]->raceId);
        $this->assertSame([101, 102], array_map(fn ($entry): int => $entry->raceEntryId, $payloads[0]->entries));
        $this->assertSame(11, $payloads[1]->raceId);
    }

    public function test_empty_spool_is_sealed_and_replayed_without_rows(): void
    {
        $spool = $this->spool();
        $metadata = $spool->seal();

        $this->assertSame(0, $metadata->rowCount);
        $this->assertSame(0, $metadata->raceCount);
        $this->assertSame([], iterator_to_array($spool->payloads(), false));
    }

    public function test_corruption_fails_closed(): void
    {
        $spool = $this->spool();
        $spool->append(10, new Bt03BinEffectEntryDto(101, 1, 0.2, 0.3));
        $spool->seal();
        file_put_contents($spool->path(), "corrupt\n", FILE_APPEND);

        $this->expectException(RuntimeException::class);
        iterator_to_array($spool->payloads(), false);
    }

    public function test_corruption_after_first_payload_fails_during_the_same_replay_pass(): void
    {
        $spool = $this->spool();
        $spool->append(10, new Bt03BinEffectEntryDto(101, 1, 0.2, 0.3));
        $spool->append(11, new Bt03BinEffectEntryDto(102, 0, 0.4, 0.5));
        $spool->append(12, new Bt03BinEffectEntryDto(103, 1, 0.6, 0.7));
        $spool->seal();
        $payloads = $spool->payloads();

        $this->assertSame(10, $payloads->current()->raceId);
        file_put_contents($spool->path(), "corrupt\n", FILE_APPEND);

        $this->expectException(RuntimeException::class);
        while ($payloads->valid()) {
            $payloads->next();
        }
    }

    public function test_race_reappearance_and_duplicate_entry_fail_closed(): void
    {
        $spool = $this->spool();
        $spool->append(10, new Bt03BinEffectEntryDto(101, 1, 0.2, 0.3));
        $spool->append(11, new Bt03BinEffectEntryDto(102, 0, 0.4, 0.5));

        $this->expectException(RuntimeException::class);
        $spool->append(10, new Bt03BinEffectEntryDto(103, 1, 0.6, 0.7));
    }

    public function test_cleanup_removes_the_temporary_file(): void
    {
        $spool = $this->spool();
        $path = $spool->path();
        $this->assertFileExists($path);

        $spool->cleanup();

        $this->assertFileDoesNotExist($path);
    }

    private function spool(): Bt03BinEffectSpool
    {
        return new Bt03BinEffectSpool(new Bt03BinSpoolIdentityDto(
            'WF_2023',
            'STAT-07',
            'STRICT',
            'IS_WIN',
            1,
        ), $this->directory);
    }
}
