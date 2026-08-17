<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03BinEffectCalculator;
use App\Domain\Keirin\Backtest\Calculators\RaceClusterBootstrap;
use App\Domain\Keirin\Backtest\Calculators\Type7Quantile;
use App\Domain\Keirin\Backtest\DTO\Bt03BinEffectEntryDto;
use App\Domain\Keirin\Backtest\DTO\Bt03RaceBinPayloadDto;
use Generator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class Bt03BinEffectCalculatorTest extends TestCase
{
    #[DataProvider('residualCases')]
    public function test_baseline_residual_sign_and_zero_are_calculated(float $baseline, int $label, float $expected): void
    {
        $result = $this->calculator()->calculate([
            $this->race(1, [new Bt03BinEffectEntryDto(11, $label, $baseline, $baseline)]),
        ], 8);

        $this->assertSame('OBSERVED', $result->evaluationStatus);
        $this->assertEqualsWithDelta($expected, $result->baselineResidualMean, 1e-15);
        $this->assertEqualsWithDelta($result->observedRate - $result->baselineMeanProbability, $result->baselineResidualMean, 0.0);
        $this->assertSame(1, $result->evaluationSampleCount);
        $this->assertSame(1, $result->evaluationRaceCount);
    }

    /** @return iterable<string, array{float, int, float}> */
    public static function residualCases(): iterable
    {
        yield 'positive' => [0.4, 1, 0.6];
        yield 'negative' => [0.4, 0, -0.4];
        yield 'zero' => [1.0, 1, 0.0];
    }

    public function test_incremental_improvement_and_degradation_have_the_defined_delta_signs(): void
    {
        $improved = $this->calculator()->calculate([
            $this->race(1, [new Bt03BinEffectEntryDto(11, 1, 0.2, 0.8)]),
        ], 8);
        $worsened = $this->calculator()->calculate([
            $this->race(1, [new Bt03BinEffectEntryDto(11, 1, 0.8, 0.2)]),
        ], 8);

        $this->assertEqualsWithDelta(0.8, $improved->baselineResidualMean, 1e-15);
        $this->assertEqualsWithDelta(0.2, $improved->incrementalResidualMean, 1e-15);
        $this->assertLessThan(0.0, $improved->logLossDelta);
        $this->assertLessThan(0.0, $improved->brierDelta);
        $this->assertGreaterThan(0.0, $worsened->logLossDelta);
        $this->assertGreaterThan(0.0, $worsened->brierDelta);
    }

    public function test_one_shared_bootstrap_stream_resamples_whole_race_clusters(): void
    {
        $bootstrap = new class extends RaceClusterBootstrap
        {
            public int $calls = 0;

            public int $raceCount = 0;

            public function resampleIndexes(int $raceCount, int $iterations = self::ITERATIONS, int $seed = self::SEED): Generator
            {
                $this->calls++;
                $this->raceCount = $raceCount;
                yield [0, 0];
                yield [1, 1];
            }
        };
        $calculator = new Bt03BinEffectCalculator($bootstrap, new Type7Quantile);
        $result = $calculator->calculate([
            $this->race(1, [
                new Bt03BinEffectEntryDto(11, 1, 0.5, 0.6),
                new Bt03BinEffectEntryDto(12, 1, 0.5, 0.6),
            ]),
            $this->race(2, [new Bt03BinEffectEntryDto(21, 0, 0.5, 0.4)]),
        ], 2);

        $this->assertSame(1, $bootstrap->calls);
        $this->assertSame(2, $bootstrap->raceCount);
        $this->assertEqualsWithDelta(2 / 3, $result->observedRate, 1e-15);
        $this->assertEqualsWithDelta(0.025, $result->observedRateCiLower, 1e-15);
        $this->assertEqualsWithDelta(0.975, $result->observedRateCiUpper, 1e-15);
    }

    public function test_seed_and_semantic_input_order_are_deterministic(): void
    {
        $first = $this->calculator()->calculate([
            $this->race(2, [
                new Bt03BinEffectEntryDto(22, 0, 0.3, 0.2),
                new Bt03BinEffectEntryDto(21, 1, 0.7, 0.8),
            ]),
            $this->race(1, [new Bt03BinEffectEntryDto(11, 1, 0.6, 0.7)]),
        ], 32, 99);
        $second = $this->calculator()->calculate([
            $this->race(1, [new Bt03BinEffectEntryDto(11, 1, 0.6, 0.7)]),
            $this->race(2, [
                new Bt03BinEffectEntryDto(21, 1, 0.7, 0.8),
                new Bt03BinEffectEntryDto(22, 0, 0.3, 0.2),
            ]),
        ], 32, 99);

        $this->assertEquals($first, $second);
    }

    public function test_empty_evaluation_bin_is_explicit_and_has_no_estimates(): void
    {
        $result = $this->calculator()->calculate([], 8);

        $this->assertSame('NO_EVALUATION_ROWS', $result->evaluationStatus);
        $this->assertSame(0, $result->evaluationSampleCount);
        $this->assertSame(0, $result->evaluationRaceCount);
        $this->assertSame(0, $result->positiveCount);
        $this->assertNull($result->observedRate);
        $this->assertNull($result->baselineResidualMean);
        $this->assertNull($result->logLossDelta);
    }

    #[DataProvider('invalidEntries')]
    public function test_invalid_entry_values_fail_closed(Bt03BinEffectEntryDto $entry): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator()->calculate([$this->race(1, [$entry])], 4);
    }

    /** @return iterable<string, array{Bt03BinEffectEntryDto}> */
    public static function invalidEntries(): iterable
    {
        yield 'label' => [new Bt03BinEffectEntryDto(1, 2, 0.5, 0.5)];
        yield 'baseline below zero' => [new Bt03BinEffectEntryDto(1, 1, -0.1, 0.5)];
        yield 'incremental above one' => [new Bt03BinEffectEntryDto(1, 1, 0.5, 1.1)];
        yield 'non finite baseline' => [new Bt03BinEffectEntryDto(1, 1, NAN, 0.5)];
        yield 'non finite incremental' => [new Bt03BinEffectEntryDto(1, 1, 0.5, INF)];
    }

    public function test_duplicate_race_entry_and_non_positive_iterations_fail_closed(): void
    {
        $entries = [
            new Bt03BinEffectEntryDto(11, 1, 0.5, 0.5),
            new Bt03BinEffectEntryDto(11, 0, 0.5, 0.5),
        ];
        foreach ([
            fn () => $this->calculator()->calculate([$this->race(1, $entries)], 2),
            fn () => $this->calculator()->calculate([], 0),
        ] as $callback) {
            try {
                $callback();
                $this->fail('Expected invalid BT-03 input to fail.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function calculator(): Bt03BinEffectCalculator
    {
        return new Bt03BinEffectCalculator(new RaceClusterBootstrap, new Type7Quantile);
    }

    /** @param list<Bt03BinEffectEntryDto> $entries */
    private function race(int $raceId, array $entries): Bt03RaceBinPayloadDto
    {
        return new Bt03RaceBinPayloadDto($raceId, $entries);
    }
}
