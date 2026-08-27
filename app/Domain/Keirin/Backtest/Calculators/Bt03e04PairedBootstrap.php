<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\Services\Bt03e04Contract;
use App\Domain\Keirin\Backtest\Support\Bt03e04MetricContributionSpool;
use RuntimeException;

final class Bt03e04PairedBootstrap
{
    public function __construct(private readonly Type7Quantile $quantile) {}

    /** @param array<int,Bt03e04MetricContributionSpool> $years @return array<string,array{ci_lower:float,ci_upper:float}> */
    public function evaluate(array $years, int $iterations = Bt03e04Contract::BOOTSTRAP_ITERATIONS): array
    {
        if (array_keys($years) !== Bt03e04Contract::DEVELOPMENT_YEARS || $iterations < 1) {
            throw new RuntimeException('BT-03E-04 paired bootstrap input was invalid.');
        }
        $samples = array_fill_keys(Bt03e04MetricEvaluator::METRIC_CODES, []);
        $random = new DeterministicRandom(Bt03e04Contract::BOOTSTRAP_SEED);
        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            $yearDeltas = array_fill_keys(Bt03e04MetricEvaluator::METRIC_CODES, []);
            foreach ($years as $spool) {
                $count = $spool->raceCount();
                if ($count < 1) {
                    throw new RuntimeException('BT-03E-04 paired bootstrap year was empty.');
                }
                $weights = array_fill(0, $count, 0);
                for ($draw = 0; $draw < $count; $draw++) {
                    $weights[$random->integer($count)]++;
                }
                $candidate = $baseline = $denominators = array_fill_keys(Bt03e04MetricEvaluator::METRIC_CODES, 0.0);
                $raceIndex = 0;
                foreach ($spool->records() as $values) {
                    $weight = $weights[$raceIndex++] ?? throw new RuntimeException('BT-03E-04 bootstrap source exceeded its seal.');
                    foreach (Bt03e04MetricEvaluator::METRIC_CODES as $offset => $metric) {
                        $candidate[$metric] += $weight * $values[$offset * 3];
                        $baseline[$metric] += $weight * $values[$offset * 3 + 1];
                        $denominators[$metric] += $weight * $values[$offset * 3 + 2];
                    }
                }
                if ($raceIndex !== $count) {
                    throw new RuntimeException('BT-03E-04 bootstrap source count drifted.');
                }
                foreach (Bt03e04MetricEvaluator::METRIC_CODES as $metric) {
                    $yearDeltas[$metric][] = $denominators[$metric] > 0.0
                        ? ($candidate[$metric] - $baseline[$metric]) / $denominators[$metric]
                        : 0.0;
                }
            }
            foreach ($samples as $metric => $_) {
                $samples[$metric][] = array_sum($yearDeltas[$metric]) / count($yearDeltas[$metric]);
            }
        }

        return array_map(fn (array $values): array => [
            'ci_lower' => $this->quantile->calculate($values, Bt03e04Contract::BOOTSTRAP_CI_LOWER),
            'ci_upper' => $this->quantile->calculate($values, Bt03e04Contract::BOOTSTRAP_CI_UPPER),
        ], $samples);
    }
}
