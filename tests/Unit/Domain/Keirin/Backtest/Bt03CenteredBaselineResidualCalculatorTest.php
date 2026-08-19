<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03CenteredBaselineResidualCalculator;
use App\Domain\Keirin\Backtest\Calculators\RaceClusterBootstrap;
use App\Domain\Keirin\Backtest\Calculators\Type7Quantile;
use App\Domain\Keirin\Backtest\DTO\Bt03CenteredRacePayloadDto;
use App\Domain\Keirin\Backtest\DTO\Bt03CenteredResidualEntryDto;
use PHPUnit\Framework\TestCase;

class Bt03CenteredBaselineResidualCalculatorTest extends TestCase
{
    public function test_global_calibration_offset_is_cancelled_by_centering(): void
    {
        $results = $this->calculator()->calculate([
            $this->race(1, [[1, 1, 1, 0.4], [2, 1, 0, 0.4]]),
            $this->race(2, [[3, 2, 1, 0.4], [4, 2, 0, 0.4]]),
        ], [1, 2], 40, 20260812);

        $this->assertEqualsWithDelta(0.1, $results[1]->overallBaselineResidualMean, 1e-12);
        $this->assertEqualsWithDelta(0.0, $results[1]->centeredBaselineResidualMean, 1e-12);
        $this->assertEqualsWithDelta(0.0, $results[2]->centeredBaselineResidualMean, 1e-12);
    }

    public function test_local_residual_above_global_offset_is_preserved(): void
    {
        $results = $this->calculator()->calculate([
            $this->race(1, [[1, 1, 1, 0.35], [2, 1, 0, 0.35]]),
            $this->race(2, [[3, 2, 1, 0.45], [4, 2, 0, 0.45]]),
        ], [1, 2], 40, 20260812);

        $this->assertEqualsWithDelta(0.1, $results[1]->overallBaselineResidualMean, 1e-12);
        $this->assertEqualsWithDelta(0.05, $results[1]->centeredBaselineResidualMean, 1e-12);
        $this->assertEqualsWithDelta(-0.05, $results[2]->centeredBaselineResidualMean, 1e-12);
    }

    public function test_shared_race_replicates_match_manual_reference_and_not_marginal_endpoint_subtraction(): void
    {
        $races = [
            $this->race(1, [[1, 1, 1, 0.20], [2, 2, 0, 0.70]]),
            $this->race(2, [[3, 1, 0, 0.30], [4, 2, 1, 0.40]]),
            $this->race(3, [[5, 1, 1, 0.60], [6, 2, 0, 0.10]]),
        ];
        $iterations = 30;
        $seed = 77;
        $results = $this->calculator()->calculate($races, [1, 2], $iterations, $seed);

        $samples = [];
        foreach ((new RaceClusterBootstrap)->resampleIndexes(count($races), $iterations, $seed) as $indexes) {
            $overall = $bin = [];
            foreach ($indexes as $index) {
                foreach ($races[$index]->entries as $entry) {
                    $residual = $entry->label - $entry->baselineProbability;
                    $overall[] = $residual;
                    if ($entry->binIndex === 1) {
                        $bin[] = $residual;
                    }
                }
            }
            $samples[] = array_sum($bin) / count($bin) - array_sum($overall) / count($overall);
        }
        $quantile = new Type7Quantile;
        $this->assertEqualsWithDelta($quantile->calculate($samples, 0.025), $results[1]->centeredBaselineResidualCiLower, 1e-15);
        $this->assertEqualsWithDelta($quantile->calculate($samples, 0.975), $results[1]->centeredBaselineResidualCiUpper, 1e-15);

        $binResiduals = [0.8, -0.3, 0.4];
        $overallResiduals = [0.05, 0.15, 0.35];
        $marginalLower = $quantile->calculate($binResiduals, 0.025)
            - $quantile->calculate($overallResiduals, 0.975);
        $this->assertNotEquals($marginalLower, $results[1]->centeredBaselineResidualCiLower);
    }

    public function test_empty_and_sparse_bins_are_fail_visible(): void
    {
        $results = $this->calculator()->calculate([
            $this->race(1, [[1, 1, 1, 0.2]]),
            $this->race(2, [[2, 2, 0, 0.3]]),
        ], [1, 2, 3], 100, 20260812);

        $this->assertSame('NO_EVALUATION_ROWS', $results[3]->centeredCiStatus);
        $this->assertNull($results[3]->centeredBaselineResidualMean);
        $this->assertNull($results[3]->centeredBaselineResidualCiLower);
        $this->assertSame(0, $results[3]->centeredBootstrapValidIterations);
        $this->assertTrue(is_finite($results[3]->overallBaselineResidualMean));

        $this->assertSame('SPARSE_BOOTSTRAP_UNSUPPORTED', $results[1]->centeredCiStatus);
        $this->assertNotNull($results[1]->centeredBaselineResidualMean);
        $this->assertNull($results[1]->centeredBaselineResidualCiLower);
        $this->assertLessThan(100, $results[1]->centeredBootstrapValidIterations);
    }

    public function test_seed_and_canonical_input_order_are_deterministic(): void
    {
        $races = [
            $this->race(2, [[4, 2, 1, 0.4], [3, 1, 0, 0.2]]),
            $this->race(1, [[2, 2, 0, 0.3], [1, 1, 1, 0.5]]),
        ];
        $reordered = [$races[1], $races[0]];

        $first = $this->calculator()->calculate($races, [2, 1], 40, 91);
        $second = $this->calculator()->calculate($reordered, [1, 2], 40, 91);

        $this->assertEquals($first, $second);
    }

    private function calculator(): Bt03CenteredBaselineResidualCalculator
    {
        return new Bt03CenteredBaselineResidualCalculator(new RaceClusterBootstrap, new Type7Quantile);
    }

    /** @param list<array{int, int, int, float}> $entries */
    private function race(int $raceId, array $entries): Bt03CenteredRacePayloadDto
    {
        return new Bt03CenteredRacePayloadDto($raceId, array_map(
            fn (array $entry): Bt03CenteredResidualEntryDto => new Bt03CenteredResidualEntryDto(...$entry),
            $entries,
        ));
    }
}
