<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Support\Bt03e02RaceSpool;
use PHPUnit\Framework\TestCase;

class Bt03e02BoundedMemoryTest extends TestCase
{
    public function test_large_synthetic_race_source_is_written_and_replayed_with_bounded_memory(): void
    {
        $path = sys_get_temp_dir().'/bt03e02-memory-'.bin2hex(random_bytes(8)).'.jsonl';
        $spool = new Bt03e02RaceSpool('BINNED', $path);
        $startPeak = memory_get_peak_usage(true);

        try {
            for ($raceId = 1; $raceId <= 5000; $raceId++) {
                $entries = [];
                for ($bike = 1; $bike <= 9; $bike++) {
                    $entries[] = [
                        'id' => $raceId * 10 + $bike,
                        'bike' => $bike,
                        'raw' => 100.0 - $bike,
                        'stat01_rank' => $bike,
                        'anchor' => (float) (5 - $bike),
                        'bins' => array_fill(0, 12, null),
                        'labels' => [$bike === 1, $bike <= 2, $bike <= 3],
                        'rank' => $bike,
                        'status' => 'FINISHED',
                    ];
                }
                $spool->append(['year' => 2025, 'race_id' => $raceId, 'entries' => $entries]);
            }
            $spool->seal();
            $races = $entries = 0;
            foreach ($spool->races() as $race) {
                $races++;
                $entries += count($race['entries']);
            }

            $this->assertSame(5000, $races);
            $this->assertSame(45000, $entries);
            $this->assertLessThan(32 * 1024 * 1024, memory_get_peak_usage(true) - $startPeak);
        } finally {
            $spool->cleanup();
        }
    }
}
