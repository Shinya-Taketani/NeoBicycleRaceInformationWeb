<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\DTO\Bt03BinEffectEntryDto;
use App\Domain\Keirin\Backtest\DTO\Bt03BinEffectResultDto;
use App\Domain\Keirin\Backtest\DTO\Bt03RaceBinPayloadDto;
use InvalidArgumentException;

class Bt03BinEffectCalculator
{
    public const CALCULATION_VERSION = 'BT03-BIN-EFFECT-v2';

    public function __construct(
        private readonly RaceClusterBootstrap $bootstrap,
        private readonly Type7Quantile $quantile,
    ) {}

    /** @param iterable<Bt03RaceBinPayloadDto> $racePayloads */
    public function calculate(
        iterable $racePayloads,
        int $iterations = RaceClusterBootstrap::ITERATIONS,
        int $seed = RaceClusterBootstrap::SEED,
    ): Bt03BinEffectResultDto {
        if ($iterations < 1) {
            throw new InvalidArgumentException('BT-03 bootstrap iterations must be positive.');
        }

        $races = $this->compactRaces($racePayloads);
        if ($races === []) {
            return $this->emptyResult($iterations, $seed);
        }

        $point = $this->estimates($races);
        $samples = [
            'observed_rate' => [],
            'baseline_residual_mean' => [],
            'incremental_residual_mean' => [],
            'probability_shift_mean' => [],
            'log_loss_delta' => [],
            'brier_delta' => [],
        ];
        foreach ($this->bootstrap->resampleIndexes(count($races), $iterations, $seed) as $indexes) {
            $estimate = $this->estimates($this->bootstrap->apply($races, $indexes));
            foreach (array_keys($samples) as $key) {
                $samples[$key][] = $estimate[$key];
            }
        }

        return new Bt03BinEffectResultDto(
            evaluationStatus: 'OBSERVED',
            evaluationSampleCount: $point['sample_count'],
            evaluationRaceCount: count($races),
            positiveCount: $point['positive_count'],
            observedRate: $point['observed_rate'],
            observedRateCiLower: $this->lower($samples['observed_rate']),
            observedRateCiUpper: $this->upper($samples['observed_rate']),
            baselineMeanProbability: $point['baseline_mean_probability'],
            incrementalMeanProbability: $point['incremental_mean_probability'],
            baselineResidualMean: $point['baseline_residual_mean'],
            baselineResidualCiLower: $this->lower($samples['baseline_residual_mean']),
            baselineResidualCiUpper: $this->upper($samples['baseline_residual_mean']),
            incrementalResidualMean: $point['incremental_residual_mean'],
            incrementalResidualCiLower: $this->lower($samples['incremental_residual_mean']),
            incrementalResidualCiUpper: $this->upper($samples['incremental_residual_mean']),
            probabilityShiftMean: $point['probability_shift_mean'],
            probabilityShiftCiLower: $this->lower($samples['probability_shift_mean']),
            probabilityShiftCiUpper: $this->upper($samples['probability_shift_mean']),
            logLossDelta: $point['log_loss_delta'],
            logLossDeltaCiLower: $this->lower($samples['log_loss_delta']),
            logLossDeltaCiUpper: $this->upper($samples['log_loss_delta']),
            brierDelta: $point['brier_delta'],
            brierDeltaCiLower: $this->lower($samples['brier_delta']),
            brierDeltaCiUpper: $this->upper($samples['brier_delta']),
            bootstrapIterations: $iterations,
            bootstrapSeed: $seed,
        );
    }

    /**
     * @param  iterable<Bt03RaceBinPayloadDto>  $payloads
     * @return list<array{race_id: int, sample_count: int, positive_count: int, baseline_sum: float, incremental_sum: float, log_loss_delta_sum: float, brier_delta_sum: float}>
     */
    private function compactRaces(iterable $payloads): array
    {
        $races = [];
        foreach ($payloads as $payload) {
            if (! $payload instanceof Bt03RaceBinPayloadDto || $payload->raceId < 1 || $payload->entries === []) {
                throw new InvalidArgumentException('BT-03 race bin payload was invalid.');
            }
            if (isset($races[$payload->raceId])) {
                throw new InvalidArgumentException('BT-03 race bin payload contained a duplicate race.');
            }
            $entries = $payload->entries;
            usort($entries, fn (Bt03BinEffectEntryDto $left, Bt03BinEffectEntryDto $right): int => $left->raceEntryId <=> $right->raceEntryId);
            $seenEntries = [];
            $race = [
                'race_id' => $payload->raceId,
                'sample_count' => 0,
                'positive_count' => 0,
                'baseline_sum' => 0.0,
                'incremental_sum' => 0.0,
                'log_loss_delta_sum' => 0.0,
                'brier_delta_sum' => 0.0,
            ];
            foreach ($entries as $entry) {
                $this->assertEntry($entry);
                if (isset($seenEntries[$entry->raceEntryId])) {
                    throw new InvalidArgumentException('BT-03 race bin payload contained a duplicate entry.');
                }
                $seenEntries[$entry->raceEntryId] = true;
                $race['sample_count']++;
                $race['positive_count'] += $entry->label;
                $race['baseline_sum'] += $entry->baselineProbability;
                $race['incremental_sum'] += $entry->incrementalProbability;
                $race['log_loss_delta_sum'] += $this->logLoss($entry->incrementalProbability, $entry->label)
                    - $this->logLoss($entry->baselineProbability, $entry->label);
                $race['brier_delta_sum'] += ($entry->incrementalProbability - $entry->label) ** 2
                    - ($entry->baselineProbability - $entry->label) ** 2;
            }
            $races[$payload->raceId] = $race;
        }
        ksort($races, SORT_NUMERIC);

        return array_values($races);
    }

    private function assertEntry(mixed $entry): void
    {
        if (! $entry instanceof Bt03BinEffectEntryDto
            || $entry->raceEntryId < 1
            || ! in_array($entry->label, [0, 1], true)
            || ! is_finite($entry->baselineProbability)
            || ! is_finite($entry->incrementalProbability)
            || $entry->baselineProbability < 0.0
            || $entry->baselineProbability > 1.0
            || $entry->incrementalProbability < 0.0
            || $entry->incrementalProbability > 1.0) {
            throw new InvalidArgumentException('BT-03 bin effect entry was invalid.');
        }
    }

    private function logLoss(float $probability, int $label): float
    {
        $probability = min(max($probability, BinaryMetricCalculator::LOG_LOSS_EPSILON), 1.0 - BinaryMetricCalculator::LOG_LOSS_EPSILON);

        return -($label * log($probability) + (1 - $label) * log(1.0 - $probability));
    }

    /**
     * @param  list<array{race_id: int, sample_count: int, positive_count: int, baseline_sum: float, incremental_sum: float, log_loss_delta_sum: float, brier_delta_sum: float}>  $races
     * @return array{sample_count: int, positive_count: int, observed_rate: float, baseline_mean_probability: float, incremental_mean_probability: float, baseline_residual_mean: float, incremental_residual_mean: float, probability_shift_mean: float, log_loss_delta: float, brier_delta: float}
     */
    private function estimates(array $races): array
    {
        $sampleCount = $positiveCount = 0;
        $baselineSum = $incrementalSum = $logLossDeltaSum = $brierDeltaSum = 0.0;
        foreach ($races as $race) {
            $sampleCount += $race['sample_count'];
            $positiveCount += $race['positive_count'];
            $baselineSum += $race['baseline_sum'];
            $incrementalSum += $race['incremental_sum'];
            $logLossDeltaSum += $race['log_loss_delta_sum'];
            $brierDeltaSum += $race['brier_delta_sum'];
        }
        $observedRate = $positiveCount / $sampleCount;
        $baselineMean = $baselineSum / $sampleCount;
        $incrementalMean = $incrementalSum / $sampleCount;

        return [
            'sample_count' => $sampleCount,
            'positive_count' => $positiveCount,
            'observed_rate' => $observedRate,
            'baseline_mean_probability' => $baselineMean,
            'incremental_mean_probability' => $incrementalMean,
            'baseline_residual_mean' => $observedRate - $baselineMean,
            'incremental_residual_mean' => $observedRate - $incrementalMean,
            'probability_shift_mean' => $incrementalMean - $baselineMean,
            'log_loss_delta' => $logLossDeltaSum / $sampleCount,
            'brier_delta' => $brierDeltaSum / $sampleCount,
        ];
    }

    /** @param list<float> $values */
    private function lower(array $values): float
    {
        return $this->quantile->calculate($values, 0.025);
    }

    /** @param list<float> $values */
    private function upper(array $values): float
    {
        return $this->quantile->calculate($values, 0.975);
    }

    private function emptyResult(int $iterations, int $seed): Bt03BinEffectResultDto
    {
        return new Bt03BinEffectResultDto(
            'NO_EVALUATION_ROWS',
            0,
            0,
            0,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $iterations,
            $seed,
        );
    }
}
