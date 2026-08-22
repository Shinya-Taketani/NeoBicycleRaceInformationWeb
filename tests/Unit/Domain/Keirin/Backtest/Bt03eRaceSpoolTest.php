<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Support\Bt03eRaceSpool;
use Tests\TestCase;

class Bt03eRaceSpoolTest extends TestCase
{
    public function test_spool_replays_a_bounded_synthetic_dataset_without_retaining_all_rows(): void
    {
        $path = sys_get_temp_dir().'/bt03e-spool-test-'.bin2hex(random_bytes(8)).'.jsonl';
        $spool = new Bt03eRaceSpool(2023, $path);
        $before = memory_get_usage(true);
        try {
            for ($raceId = 1; $raceId <= 3000; $raceId++) {
                $entries = [];
                for ($bike = 1; $bike <= 9; $bike++) {
                    $entries[] = [
                        'id' => (($raceId - 1) * 9) + $bike,
                        'bike' => $bike,
                        'raw' => 100.0 - $bike,
                        'directions' => array_fill(0, 12, 0),
                        'rank' => $bike,
                        'status' => 'FINISHED',
                    ];
                }
                $spool->append($raceId, $entries);
            }
            $metadata = $spool->seal();
            $replayed = 0;
            foreach ($spool->races() as $race) {
                $replayed += count($race['entries']);
            }

            $this->assertSame(3000, $metadata->raceCount);
            $this->assertSame(27000, $metadata->entryCount);
            $this->assertSame(27000, $replayed);
            $this->assertLessThan(24 * 1024 * 1024, memory_get_usage(true) - $before);
        } finally {
            $spool->cleanup();
        }
    }
}
