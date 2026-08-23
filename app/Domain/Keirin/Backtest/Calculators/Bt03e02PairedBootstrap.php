<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\Services\Bt03e02Contract;
use RuntimeException;

final class Bt03e02PairedBootstrap
{
    public function __construct(
        private readonly Bt03e02MetricEvaluator $metrics,
        private readonly Type7Quantile $quantile,
    ) {}

    /**
     * @param  array<int, array{source:callable():iterable<array<string,mixed>>,race_count:int,alpha:array{IS_WIN:float,IS_TOP2:float,IS_TOP3:float,key:string}}>  $years
     * @return array<string,array{ci_lower:float,ci_upper:float}>
     */
    public function evaluate(array $years, int $iterations = Bt03e02Contract::BOOTSTRAP_ITERATIONS): array
    {
        if ($years === [] || $iterations < 1) {
            throw new RuntimeException('BT-03E-02 paired bootstrap input was invalid.');
        }
        ksort($years, SORT_NUMERIC);
        $samples = array_fill_keys(Bt03e02MetricEvaluator::METRIC_CODES, []);
        $random = new DeterministicRandom(Bt03e02Contract::BOOTSTRAP_SEED);
        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            $yearDeltas = array_fill_keys(Bt03e02MetricEvaluator::METRIC_CODES, []);
            foreach ($years as $year) {
                $count = $year['race_count'];
                if ($count < 1) {
                    throw new RuntimeException('BT-03E-02 paired bootstrap year was empty.');
                }
                $weights = array_fill(0, $count, 0);
                for ($draw = 0; $draw < $count; $draw++) {
                    $weights[$random->integer($count)]++;
                }
                $candidateNumerators = $baselineNumerators = $denominators = array_fill_keys(Bt03e02MetricEvaluator::METRIC_CODES, 0.0);
                $raceIndex = 0;
                foreach (($year['source'])() as $race) {
                    if (! array_key_exists($raceIndex, $weights)) {
                        throw new RuntimeException('BT-03E-02 paired bootstrap source exceeded its sealed count.');
                    }
                    $weight = $weights[$raceIndex++];
                    if ($weight === 0) {
                        continue;
                    }
                    $comparison = $this->metrics->raceComparison($race, $year['alpha']);
                    foreach (Bt03e02MetricEvaluator::METRIC_CODES as $metric) {
                        $candidateNumerators[$metric] += $weight * $comparison['candidate'][$metric]['numerator'];
                        $baselineNumerators[$metric] += $weight * $comparison['baseline'][$metric]['numerator'];
                        $denominators[$metric] += $weight * $comparison['candidate'][$metric]['denominator'];
                    }
                }
                if ($raceIndex !== $count) {
                    throw new RuntimeException('BT-03E-02 paired bootstrap source count drifted.');
                }
                foreach (Bt03e02MetricEvaluator::METRIC_CODES as $metric) {
                    $yearDeltas[$metric][] = $denominators[$metric] > 0.0
                        ? ($candidateNumerators[$metric] - $baselineNumerators[$metric]) / $denominators[$metric]
                        : 0.0;
                }
            }
            foreach ($samples as $metric => $_) {
                $samples[$metric][] = array_sum($yearDeltas[$metric]) / count($yearDeltas[$metric]);
            }
        }
        $intervals = [];
        foreach ($samples as $metric => $values) {
            $intervals[$metric] = [
                'ci_lower' => $this->quantile->calculate($values, 0.025),
                'ci_upper' => $this->quantile->calculate($values, 0.975),
            ];
        }

        return $intervals;
    }
}
