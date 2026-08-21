<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03BinEffectCalculator;
use App\Domain\Keirin\Backtest\Calculators\Bt03CenteredBaselineResidualCalculator;
use App\Domain\Keirin\Backtest\Calculators\RaceClusterBootstrap;
use App\Domain\Keirin\Backtest\Calculators\Type7Quantile;
use App\Domain\Keirin\Backtest\DTO\Bt03CenteredBinResidualDto;
use App\Domain\Keirin\Backtest\DTO\Bt03CenteredRacePayloadDto;
use App\Domain\Keirin\Backtest\DTO\Bt03CenteredResidualEntryDto;
use App\Domain\Keirin\Backtest\Support\Bt02ModelArtifactHasher;
use App\Domain\Keirin\Backtest\Support\Bt03EffectHasher;
use Generator;
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

    public function test_bounded_store_is_bit_exact_with_the_v2_naive_reference_and_effect_hash(): void
    {
        $races = [
            $this->race(40, [[9, 4, 1, 0.12345678901234566], [8, 1, 0, 0.9876543210987654]]),
            $this->race(10, [[2, 2, 1, 0.3141592653589793], [1, 1, 0, 0.2718281828459045]]),
            $this->race(30, [[7, 4, 0, 0.625], [6, 3, 1, 0.375], [5, 2, 0, 0.125]]),
            $this->race(20, [[4, 3, 0, 0.7777777777777778], [3, 1, 1, 0.2222222222222222]]),
        ];
        $bins = [4, 2, 5, 1, 3];
        $iterations = 257;
        $seed = 918273;

        $reference = $this->naiveV2Reference($races, $bins, $iterations, $seed);
        $bounded = $this->calculator()->calculate($races, $bins, $iterations, $seed);

        $this->assertCenteredResultsSame($reference, $bounded);
        $effectHasher = new Bt03EffectHasher(new Bt02ModelArtifactHasher);
        foreach ($reference as $binIndex => $result) {
            $this->assertSame(
                $effectHasher->hash($this->effectArtifact($binIndex, $result)),
                $effectHasher->hash($this->effectArtifact($binIndex, $bounded[$binIndex])),
            );
        }
    }

    public function test_compact_store_handles_twenty_five_thousand_races_with_bounded_peak_memory(): void
    {
        gc_collect_cycles();
        memory_reset_peak_usage();
        $baseline = memory_get_usage(true);

        $results = $this->calculator()->calculate($this->stressRaces(25_000, 7, 10), range(1, 10), 5, 20260812);
        $peakIncrease = memory_get_peak_usage(true) - $baseline;

        $this->assertCount(10, $results);
        $this->assertLessThan(64 * 1024 * 1024, $peakIncrease);
        $this->assertSame(array_fill(0, 10, 'AVAILABLE'), array_column($results, 'centeredCiStatus'));
    }

    public function test_two_thousand_iterations_do_not_accumulate_the_race_universe(): void
    {
        $races = iterator_to_array($this->stressRaces(80, 8, 10), false);

        $first = $this->calculator()->calculate($races, range(1, 10), 2000, 20260812);
        $second = $this->calculator()->calculate(array_reverse($races), range(10, 1), 2000, 20260812);

        $this->assertCenteredResultsSame($first, $second);
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

    /**
     * Test-only reference for the original BT03-BIN-EFFECT-v2 nested-array algorithm.
     *
     * @param  list<Bt03CenteredRacePayloadDto>  $payloads
     * @param  list<int>  $expectedBinIndexes
     * @return array<int, Bt03CenteredBinResidualDto>
     */
    private function naiveV2Reference(array $payloads, array $expectedBinIndexes, int $iterations, int $seed): array
    {
        $binIndexes = $expectedBinIndexes;
        sort($binIndexes, SORT_NUMERIC);
        $allowedBins = array_fill_keys($binIndexes, true);
        $races = [];
        foreach ($payloads as $payload) {
            $entries = $payload->entries;
            usort($entries, fn (Bt03CenteredResidualEntryDto $left, Bt03CenteredResidualEntryDto $right): int => $left->raceEntryId <=> $right->raceEntryId);
            $race = ['sample_count' => 0, 'residual_sum' => 0.0, 'bins' => []];
            foreach ($entries as $entry) {
                if (! isset($allowedBins[$entry->binIndex])) {
                    throw new \InvalidArgumentException('Reference received an invalid bin.');
                }
                $residual = $entry->label - $entry->baselineProbability;
                $race['sample_count']++;
                $race['residual_sum'] += $residual;
                $race['bins'][$entry->binIndex] ??= ['count' => 0, 'sum' => 0.0];
                $race['bins'][$entry->binIndex]['count']++;
                $race['bins'][$entry->binIndex]['sum'] += $residual;
            }
            ksort($race['bins'], SORT_NUMERIC);
            $races[$payload->raceId] = $race;
        }
        ksort($races, SORT_NUMERIC);
        $races = array_values($races);
        [$overallCount, $overallSum, $binPoints] = $this->naiveAggregate($races, $binIndexes);
        $overallMean = $overallSum / $overallCount;
        $samples = $validIterations = [];
        foreach ($binIndexes as $binIndex) {
            $samples[$binIndex] = [];
            $validIterations[$binIndex] = 0;
        }
        $bootstrap = new RaceClusterBootstrap;
        foreach ($bootstrap->resampleIndexes(count($races), $iterations, $seed) as $indexes) {
            [$replicateCount, $replicateSum, $replicateBins] = $this->naiveAggregate(
                $bootstrap->apply($races, $indexes),
                $binIndexes,
            );
            $replicateOverall = $replicateSum / $replicateCount;
            foreach ($binIndexes as $binIndex) {
                if ($replicateBins[$binIndex]['count'] === 0) {
                    continue;
                }
                $samples[$binIndex][] = ($replicateBins[$binIndex]['sum'] / $replicateBins[$binIndex]['count'])
                    - $replicateOverall;
                $validIterations[$binIndex]++;
            }
        }

        $results = [];
        $quantile = new Type7Quantile;
        foreach ($binIndexes as $binIndex) {
            $point = $binPoints[$binIndex];
            if ($point['count'] === 0) {
                $results[$binIndex] = new Bt03CenteredBinResidualDto($overallMean, null, null, null, 'NO_EVALUATION_ROWS', 0);
            } elseif ($validIterations[$binIndex] !== $iterations) {
                $results[$binIndex] = new Bt03CenteredBinResidualDto(
                    $overallMean,
                    ($point['sum'] / $point['count']) - $overallMean,
                    null,
                    null,
                    'SPARSE_BOOTSTRAP_UNSUPPORTED',
                    $validIterations[$binIndex],
                );
            } else {
                $results[$binIndex] = new Bt03CenteredBinResidualDto(
                    $overallMean,
                    ($point['sum'] / $point['count']) - $overallMean,
                    $quantile->calculate($samples[$binIndex], 0.025),
                    $quantile->calculate($samples[$binIndex], 0.975),
                    'AVAILABLE',
                    $iterations,
                );
            }
        }

        return $results;
    }

    /**
     * @param  list<array{sample_count: int, residual_sum: float, bins: array<int, array{count: int, sum: float}>}>  $races
     * @param  list<int>  $binIndexes
     * @return array{int, float, array<int, array{count: int, sum: float}>}
     */
    private function naiveAggregate(array $races, array $binIndexes): array
    {
        $count = 0;
        $sum = 0.0;
        $bins = array_fill_keys($binIndexes, null);
        foreach ($bins as $binIndex => $_) {
            $bins[$binIndex] = ['count' => 0, 'sum' => 0.0];
        }
        foreach ($races as $race) {
            $count += $race['sample_count'];
            $sum += $race['residual_sum'];
            foreach ($race['bins'] as $binIndex => $bin) {
                $bins[$binIndex]['count'] += $bin['count'];
                $bins[$binIndex]['sum'] += $bin['sum'];
            }
        }

        return [$count, $sum, $bins];
    }

    /** @param array<int, Bt03CenteredBinResidualDto> $expected @param array<int, Bt03CenteredBinResidualDto> $actual */
    private function assertCenteredResultsSame(array $expected, array $actual): void
    {
        $this->assertSame(array_keys($expected), array_keys($actual));
        foreach ($expected as $binIndex => $reference) {
            $bounded = $actual[$binIndex];
            foreach ([
                'overallBaselineResidualMean',
                'centeredBaselineResidualMean',
                'centeredBaselineResidualCiLower',
                'centeredBaselineResidualCiUpper',
            ] as $property) {
                $this->assertSame($reference->{$property}, $bounded->{$property});
                $this->assertSame(
                    $reference->{$property} === null ? null : bin2hex(pack('d', $reference->{$property})),
                    $bounded->{$property} === null ? null : bin2hex(pack('d', $bounded->{$property})),
                );
            }
            $this->assertSame($reference->centeredCiStatus, $bounded->centeredCiStatus);
            $this->assertSame($reference->centeredBootstrapValidIterations, $bounded->centeredBootstrapValidIterations);
        }
    }

    /** @return Generator<int, Bt03CenteredRacePayloadDto> */
    private function stressRaces(int $raceCount, int $entriesPerRace, int $binCount): Generator
    {
        for ($raceId = 1; $raceId <= $raceCount; $raceId++) {
            $entries = [];
            for ($position = 1; $position <= $entriesPerRace; $position++) {
                $entries[] = new Bt03CenteredResidualEntryDto(
                    (($raceId - 1) * $entriesPerRace) + $position,
                    (($raceId + $position - 2) % $binCount) + 1,
                    ($raceId + $position) % 2,
                    0.15 + ($position * 0.037),
                );
            }
            yield new Bt03CenteredRacePayloadDto($raceId, $entries);
        }
    }

    /** @return array<string, mixed> */
    private function effectArtifact(int $binIndex, Bt03CenteredBinResidualDto $centered): array
    {
        return [
            'source_bt02_run_id' => 5,
            'source_bt02_run_uuid' => '8e81ae0d-8018-4d99-b31d-203d8076e6cb',
            'source_fold_id' => 10,
            'source_signal_spec_id' => 20,
            'source_baseline_model_hash' => str_repeat('a', 64),
            'source_incremental_model_hash' => str_repeat('b', 64),
            'source_boundaries_hash' => str_repeat('c', 64),
            'source_backtest_effect_bin_id' => $binIndex,
            'cohort_code' => 'STRICT',
            'label_code' => 'IS_WIN',
            'bin_index' => $binIndex,
            'bin_origin' => 'TRAINING_BIN',
            'bin_kind' => 'NUMERIC_RANGE',
            'lower_bound' => 0.1,
            'upper_bound' => 0.2,
            'category_value' => null,
            'training_sample_count' => 100,
            'evaluation_status' => 'OBSERVED',
            'evaluation_sample_count' => 50,
            'evaluation_race_count' => 10,
            'positive_count' => 5,
            'observed_rate' => 0.1,
            'observed_rate_ci_lower' => 0.05,
            'observed_rate_ci_upper' => 0.15,
            'baseline_mean_probability' => 0.11,
            'incremental_mean_probability' => 0.12,
            'baseline_residual_mean' => -0.01,
            'baseline_residual_ci_lower' => -0.02,
            'baseline_residual_ci_upper' => 0.0,
            'incremental_residual_mean' => -0.02,
            'incremental_residual_ci_lower' => -0.03,
            'incremental_residual_ci_upper' => -0.01,
            'probability_shift_mean' => 0.01,
            'probability_shift_ci_lower' => 0.0,
            'probability_shift_ci_upper' => 0.02,
            'log_loss_delta' => -0.001,
            'log_loss_delta_ci_lower' => -0.002,
            'log_loss_delta_ci_upper' => 0.0,
            'brier_delta' => -0.001,
            'brier_delta_ci_lower' => -0.002,
            'brier_delta_ci_upper' => 0.0,
            'overall_baseline_residual_mean' => $centered->overallBaselineResidualMean,
            'centered_baseline_residual_mean' => $centered->centeredBaselineResidualMean,
            'centered_baseline_residual_ci_lower' => $centered->centeredBaselineResidualCiLower,
            'centered_baseline_residual_ci_upper' => $centered->centeredBaselineResidualCiUpper,
            'centered_ci_status' => $centered->centeredCiStatus,
            'centered_bootstrap_valid_iterations' => $centered->centeredBootstrapValidIterations,
            'bootstrap_iterations' => 257,
            'bootstrap_seed' => 918273,
            'calculation_version' => Bt03BinEffectCalculator::CALCULATION_VERSION,
        ];
    }
}
