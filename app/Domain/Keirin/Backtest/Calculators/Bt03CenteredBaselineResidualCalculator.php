<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\DTO\Bt03CenteredBinResidualDto;
use App\Domain\Keirin\Backtest\DTO\Bt03CenteredRacePayloadDto;
use App\Domain\Keirin\Backtest\DTO\Bt03CenteredResidualEntryDto;
use InvalidArgumentException;

class Bt03CenteredBaselineResidualCalculator
{
    public function __construct(
        private readonly RaceClusterBootstrap $bootstrap,
        private readonly Type7Quantile $quantile,
    ) {}

    /**
     * @param  iterable<Bt03CenteredRacePayloadDto>  $racePayloads
     * @param  list<int>  $expectedBinIndexes
     * @return array<int, Bt03CenteredBinResidualDto>
     */
    public function calculate(
        iterable $racePayloads,
        array $expectedBinIndexes,
        int $iterations = RaceClusterBootstrap::ITERATIONS,
        int $seed = RaceClusterBootstrap::SEED,
    ): array {
        if ($iterations < 1 || $expectedBinIndexes === []) {
            throw new InvalidArgumentException('BT-03 centered residual execution contract was invalid.');
        }
        $binIndexes = $expectedBinIndexes;
        sort($binIndexes, SORT_NUMERIC);
        if ($binIndexes !== array_values(array_unique($binIndexes))
            || count(array_filter($binIndexes, fn (mixed $index): bool => ! is_int($index) || $index < 0)) > 0) {
            throw new InvalidArgumentException('BT-03 centered residual expected bin set was invalid.');
        }

        $store = $this->compactRaces($racePayloads, $binIndexes);
        if ($store['race_count'] === 0) {
            throw new InvalidArgumentException('BT-03 centered residual full evaluation universe was empty.');
        }
        [$overallCount, $overallSum, $binPoints] = $this->aggregate($store, $binIndexes);
        $overallMean = $overallSum / $overallCount;
        $samples = $validIterations = [];
        foreach ($binIndexes as $binIndex) {
            $samples[$binIndex] = [];
            $validIterations[$binIndex] = 0;
        }

        foreach ($this->bootstrap->resampleIndexes($store['race_count'], $iterations, $seed) as $indexes) {
            [$replicateOverallCount, $replicateOverallSum, $replicateBins] = $this->aggregate($store, $binIndexes, $indexes);
            $replicateOverall = $replicateOverallSum / $replicateOverallCount;
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
        foreach ($binIndexes as $binIndex) {
            $point = $binPoints[$binIndex];
            if ($point['count'] === 0) {
                $results[$binIndex] = new Bt03CenteredBinResidualDto(
                    $overallMean,
                    null,
                    null,
                    null,
                    'NO_EVALUATION_ROWS',
                    0,
                );

                continue;
            }
            $centeredMean = ($point['sum'] / $point['count']) - $overallMean;
            if ($validIterations[$binIndex] !== $iterations) {
                $results[$binIndex] = new Bt03CenteredBinResidualDto(
                    $overallMean,
                    $centeredMean,
                    null,
                    null,
                    'SPARSE_BOOTSTRAP_UNSUPPORTED',
                    $validIterations[$binIndex],
                );

                continue;
            }
            $results[$binIndex] = new Bt03CenteredBinResidualDto(
                $overallMean,
                $centeredMean,
                $this->quantile->calculate($samples[$binIndex], 0.025),
                $this->quantile->calculate($samples[$binIndex], 0.975),
                'AVAILABLE',
                $iterations,
            );
        }

        return $results;
    }

    /**
     * @param  iterable<Bt03CenteredRacePayloadDto>  $payloads
     * @param  list<int>  $binIndexes
     * @return array{values: list<int|float>, offsets: list<int>, race_count: int}
     */
    private function compactRaces(iterable $payloads, array $binIndexes): array
    {
        $binPositions = array_flip($binIndexes);
        $values = $raceIds = $offsets = [];
        $stride = 2 + (count($binIndexes) * 2);
        foreach ($payloads as $payload) {
            if (! $payload instanceof Bt03CenteredRacePayloadDto || $payload->raceId < 1 || $payload->entries === []) {
                throw new InvalidArgumentException('BT-03 centered race payload was invalid.');
            }
            $entries = $payload->entries;
            usort($entries, fn (Bt03CenteredResidualEntryDto $left, Bt03CenteredResidualEntryDto $right): int => $left->raceEntryId <=> $right->raceEntryId);
            $seenEntries = [];
            $sampleCount = 0;
            $residualSum = 0.0;
            $binCounts = array_fill(0, count($binIndexes), 0);
            $binSums = array_fill(0, count($binIndexes), 0.0);
            foreach ($entries as $entry) {
                if (! $entry instanceof Bt03CenteredResidualEntryDto
                    || $entry->raceEntryId < 1 || ! isset($binPositions[$entry->binIndex])
                    || ! in_array($entry->label, [0, 1], true)
                    || ! is_finite($entry->baselineProbability)
                    || $entry->baselineProbability < 0.0 || $entry->baselineProbability > 1.0) {
                    throw new InvalidArgumentException('BT-03 centered residual entry was invalid.');
                }
                if (isset($seenEntries[$entry->raceEntryId])) {
                    throw new InvalidArgumentException('BT-03 centered race payload contained a duplicate entry.');
                }
                $seenEntries[$entry->raceEntryId] = true;
                $residual = $entry->label - $entry->baselineProbability;
                $sampleCount++;
                $residualSum += $residual;
                $binPosition = $binPositions[$entry->binIndex];
                $binCounts[$binPosition]++;
                $binSums[$binPosition] += $residual;
            }
            $raceIds[] = $payload->raceId;
            $offsets[] = count($values);
            $values[] = $sampleCount;
            $values[] = $residualSum;
            foreach ($binCounts as $position => $binCount) {
                $values[] = $binCount;
                $values[] = $binSums[$position];
            }
        }
        if ($raceIds === []) {
            return ['values' => [], 'offsets' => [], 'race_count' => 0];
        }
        array_multisort($raceIds, SORT_ASC, SORT_NUMERIC, $offsets, SORT_ASC, SORT_NUMERIC);
        for ($index = 1, $count = count($raceIds); $index < $count; $index++) {
            if ($raceIds[$index] === $raceIds[$index - 1]) {
                throw new InvalidArgumentException('BT-03 centered race payload contained a duplicate race.');
            }
        }
        foreach ($offsets as $offset) {
            if ($offset < 0 || $offset + $stride > count($values)) {
                throw new InvalidArgumentException('BT-03 centered race compact offset was invalid.');
            }
        }

        return ['values' => $values, 'offsets' => $offsets, 'race_count' => count($offsets)];
    }

    /**
     * @param  array{values: list<int|float>, offsets: list<int>, race_count: int}  $store
     * @param  list<int>  $binIndexes
     * @param  list<int>|null  $indexes
     * @return array{int, float, array<int, array{count: int, sum: float}>}
     */
    private function aggregate(array $store, array $binIndexes, ?array $indexes = null): array
    {
        $count = 0;
        $sum = 0.0;
        $bins = array_fill_keys($binIndexes, null);
        foreach ($bins as $binIndex => $_) {
            $bins[$binIndex] = ['count' => 0, 'sum' => 0.0];
        }
        $indexes ??= range(0, $store['race_count'] - 1);
        foreach ($indexes as $index) {
            if (! isset($store['offsets'][$index])) {
                throw new InvalidArgumentException('Bootstrap race index was out of range.');
            }
            $cursor = $store['offsets'][$index];
            $count += $store['values'][$cursor++];
            $sum += $store['values'][$cursor++];
            foreach ($binIndexes as $binIndex) {
                $binCount = $store['values'][$cursor++];
                $binSum = $store['values'][$cursor++];
                if ($binCount === 0) {
                    continue;
                }
                $bins[$binIndex]['count'] += $binCount;
                $bins[$binIndex]['sum'] += $binSum;
            }
        }
        if ($count < 1 || ! is_finite($sum)) {
            throw new InvalidArgumentException('BT-03 centered residual aggregate was invalid.');
        }

        return [$count, $sum, $bins];
    }
}
