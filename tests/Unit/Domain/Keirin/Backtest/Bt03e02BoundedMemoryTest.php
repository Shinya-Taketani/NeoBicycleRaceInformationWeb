<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e02MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e02OneSeSelector;
use App\Domain\Keirin\Backtest\Calculators\Bt03e02PairedBootstrap;
use App\Domain\Keirin\Backtest\Calculators\Bt03e02Scorer;
use App\Domain\Keirin\Backtest\Calculators\Type7Quantile;
use App\Domain\Keirin\Backtest\Services\Bt03e02Contract;
use App\Domain\Keirin\Backtest\Support\Bt03e02RaceSpool;
use App\Domain\Keirin\Backtest\Support\Bt03e02ValidationLossSpool;
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

    public function test_compact_bootstraps_complete_two_thousand_iterations_with_bounded_memory(): void
    {
        $lossSpool = new Bt03e02ValidationLossSpool(sys_get_temp_dir().'/bt03e02-memory-loss-'.bin2hex(random_bytes(8)).'.bin');
        $startPeak = memory_get_peak_usage(true);
        try {
            for ($race = 0; $race < 200; $race++) {
                $losses = [];
                foreach (Bt03e02Contract::LAMBDA_GRID as $lambda) {
                    foreach (Bt03e02Contract::CHANNELS as $channel) {
                        $losses[Bt03e02ValidationLossSpool::lambdaKey($lambda)][$channel] = 0.5 + $race / 10000;
                    }
                }
                $lossSpool->append($losses);
            }
            $lossSpool->seal();
            $oneSe = (new Bt03e02OneSeSelector)->select([2024 => $lossSpool], 2000);

            $races = array_map(fn (int $raceId): array => $this->predictionRace($raceId), range(1, 200));
            $alpha = ['IS_WIN' => 1.0, 'IS_TOP2' => 0.0, 'IS_TOP3' => 0.0, 'key' => '20-00-00'];
            $intervals = (new Bt03e02PairedBootstrap(
                new Bt03e02MetricEvaluator(new Bt03e02Scorer),
                new Type7Quantile,
            ))->evaluate([
                2024 => ['source' => fn (): array => $races, 'race_count' => count($races), 'alpha' => $alpha],
            ], 2000);

            $this->assertArrayHasKey('lambda', $oneSe);
            $this->assertArrayHasKey('WINNER_HIT_AT_1', $intervals);
            $this->assertLessThan(32 * 1024 * 1024, memory_get_peak_usage(true) - $startPeak);
        } finally {
            $lossSpool->cleanup();
        }
    }

    /** @return array<string,mixed> */
    private function predictionRace(int $raceId): array
    {
        $entries = [];
        foreach (range(1, 5) as $bike) {
            $score = 6.0 - $bike;
            $entries[] = [
                'id' => $raceId * 10 + $bike,
                'bike' => $bike,
                'raw' => $score,
                'stat01_rank' => $bike,
                'normalized' => ['IS_WIN' => $score, 'IS_TOP2' => $score, 'IS_TOP3' => $score],
                'rank' => $bike,
                'status' => 'FINISHED',
            ];
        }

        return ['year' => 2024, 'race_id' => $raceId, 'entries' => $entries];
    }
}
