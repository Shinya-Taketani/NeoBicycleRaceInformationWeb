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

        $races = $this->compactRaces($racePayloads, $binIndexes);
        if ($races === []) {
            throw new InvalidArgumentException('BT-03 centered residual full evaluation universe was empty.');
        }
        [$overallCount, $overallSum, $binPoints] = $this->aggregate($races, $binIndexes);
        $overallMean = $overallSum / $overallCount;
        $samples = $validIterations = [];
        foreach ($binIndexes as $binIndex) {
            $samples[$binIndex] = [];
            $validIterations[$binIndex] = 0;
        }

        foreach ($this->bootstrap->resampleIndexes(count($races), $iterations, $seed) as $indexes) {
            [$replicateOverallCount, $replicateOverallSum, $replicateBins] = $this->aggregate(
                $this->bootstrap->apply($races, $indexes),
                $binIndexes,
            );
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
     * @return list<array{race_id: int, sample_count: int, residual_sum: float, bins: array<int, array{count: int, sum: float}>}>
     */
    private function compactRaces(iterable $payloads, array $binIndexes): array
    {
        $allowedBins = array_fill_keys($binIndexes, true);
        $races = [];
        foreach ($payloads as $payload) {
            if (! $payload instanceof Bt03CenteredRacePayloadDto || $payload->raceId < 1 || $payload->entries === []) {
                throw new InvalidArgumentException('BT-03 centered race payload was invalid.');
            }
            if (isset($races[$payload->raceId])) {
                throw new InvalidArgumentException('BT-03 centered race payload contained a duplicate race.');
            }
            $entries = $payload->entries;
            usort($entries, fn (Bt03CenteredResidualEntryDto $left, Bt03CenteredResidualEntryDto $right): int => $left->raceEntryId <=> $right->raceEntryId);
            $seenEntries = [];
            $race = [
                'race_id' => $payload->raceId,
                'sample_count' => 0,
                'residual_sum' => 0.0,
                'bins' => [],
            ];
            foreach ($entries as $entry) {
                if (! $entry instanceof Bt03CenteredResidualEntryDto
                    || $entry->raceEntryId < 1 || ! isset($allowedBins[$entry->binIndex])
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

        return array_values($races);
    }

    /**
     * @param  list<array{race_id: int, sample_count: int, residual_sum: float, bins: array<int, array{count: int, sum: float}>}>  $races
     * @param  list<int>  $binIndexes
     * @return array{int, float, array<int, array{count: int, sum: float}>}
     */
    private function aggregate(array $races, array $binIndexes): array
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
        if ($count < 1 || ! is_finite($sum)) {
            throw new InvalidArgumentException('BT-03 centered residual aggregate was invalid.');
        }

        return [$count, $sum, $bins];
    }
}
