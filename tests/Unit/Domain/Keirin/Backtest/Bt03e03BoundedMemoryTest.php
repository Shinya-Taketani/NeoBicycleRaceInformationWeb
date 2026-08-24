<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e02ParameterLayout;
use App\Domain\Keirin\Backtest\Calculators\Bt03e03ProbabilityScorer;
use App\Domain\Keirin\Backtest\DTO\Bt03e03FitResultDto;
use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use App\Domain\Keirin\Backtest\Support\Bt03e02RaceSpool;
use PHPUnit\Framework\TestCase;

class Bt03e03BoundedMemoryTest extends TestCase
{
    public function test_two_thousand_nine_car_probability_races_are_streamed_with_bounded_memory(): void
    {
        $path = sys_get_temp_dir().'/bt03e03-memory-'.bin2hex(random_bytes(8)).'.jsonl';
        $spool = new Bt03e02RaceSpool('PREDICTION', $path);
        $scorer = new Bt03e03ProbabilityScorer;
        $fit = $this->fit();
        $startPeak = memory_get_peak_usage(true);
        try {
            foreach (range(1, 2000) as $raceId) {
                $spool->append($scorer->predict($this->race($raceId), $fit));
            }
            $spool->seal();
            $races = $entries = 0;
            foreach ($spool->races() as $race) {
                $races++;
                $entries += count($race['entries']);
            }

            $this->assertSame(2000, $races);
            $this->assertSame(18000, $entries);
            $this->assertLessThan(32 * 1024 * 1024, memory_get_peak_usage(true) - $startPeak);
        } finally {
            $spool->cleanup();
        }
    }

    /** @return array<string,mixed> */
    private function race(int $raceId): array
    {
        $entries = [];
        foreach (range(1, 9) as $bike) {
            $bins = array_fill(0, count(Bt03e03Contract::STAT_CODES), null);
            $bins[0] = $bike - 1;
            $entries[] = [
                'id' => $raceId * 10 + $bike,
                'bike' => $bike,
                'raw' => 100.0 - $bike,
                'stat01_rank' => $bike,
                'anchor' => (5 - $bike) / 3,
                'bins' => $bins,
                'rank' => $bike,
                'status' => 'FINISHED',
            ];
        }

        return ['year' => 2025, 'race_id' => $raceId, 'entries' => $entries];
    }

    private function fit(): Bt03e03FitResultDto
    {
        $size = $this->layout()->size();
        $coefficients = array_fill_keys(Bt03e03Contract::POSITIONS, array_fill(0, $size, 0.0));
        foreach ($coefficients as &$position) {
            foreach (range(0, 8) as $index) {
                $position[$index] = (9 - $index) / 10;
            }
        }
        unset($position);

        return new Bt03e03FitResultDto(
            0.1,
            $coefficients,
            array_fill_keys(Bt03e03Contract::POSITIONS, 0.0),
            array_fill_keys(Bt03e03Contract::POSITIONS, 1),
            array_fill_keys(Bt03e03Contract::POSITIONS, 1),
            array_fill_keys(Bt03e03Contract::POSITIONS, 0),
        );
    }

    private function layout(): Bt03e02ParameterLayout
    {
        $bins = [];
        foreach (Bt03e03Contract::STAT_CODES as $statCode) {
            foreach (range(1, 9) as $index) {
                $bins[$statCode][] = new EffectBinDto($index, 'CATEGORY', null, null, (string) $index, 1);
            }
        }

        return new Bt03e02ParameterLayout($bins);
    }
}
