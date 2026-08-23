<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\Services\Bt03e02Contract;
use App\Domain\Keirin\Backtest\Support\Bt03e02ValidationLossSpool;
use RuntimeException;

final class Bt03e02OneSeSelector
{
    /**
     * @param  array<int,Bt03e02ValidationLossSpool>  $losses
     * @return array{lambda:float,lambda_best:float,one_se_threshold:float,point_losses:array<string,float>,standard_errors:array<string,float>}
     */
    public function select(array $losses, int $iterations = Bt03e02Contract::BOOTSTRAP_ITERATIONS): array
    {
        if ($losses === [] || $iterations < 2) {
            throw new RuntimeException('BT-03E-02 One-SE input was empty or invalid.');
        }
        ksort($losses, SORT_NUMERIC);
        $lambdaKeys = array_map(fn (float $lambda): string => Bt03e02ValidationLossSpool::lambdaKey($lambda), Bt03e02Contract::LAMBDA_GRID);
        $point = $this->pointLosses($losses, $lambdaKeys);
        $samples = array_fill_keys($lambdaKeys, []);
        $random = new DeterministicRandom(Bt03e02Contract::BOOTSTRAP_SEED);

        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            $replicateByLambda = array_fill_keys($lambdaKeys, []);
            foreach ($losses as $yearLosses) {
                $raceCount = $yearLosses->raceCount();
                if ($raceCount < 1) {
                    throw new RuntimeException('BT-03E-02 One-SE year had no eligible races.');
                }
                $weights = array_fill(0, $raceCount, 0);
                for ($draw = 0; $draw < $raceCount; $draw++) {
                    $weights[$random->integer($raceCount)]++;
                }
                foreach ($this->weightedAggregates($yearLosses, $weights, $lambdaKeys) as $key => $value) {
                    $replicateByLambda[$key][] = $value;
                }
            }
            foreach ($lambdaKeys as $key) {
                $samples[$key][] = array_sum($replicateByLambda[$key]) / count($replicateByLambda[$key]);
            }
        }

        $standardErrors = [];
        foreach ($samples as $key => $values) {
            $standardErrors[$key] = $this->sampleStandardDeviation($values);
        }
        $bestKey = $lambdaKeys[0];
        foreach ($lambdaKeys as $key) {
            if ($point[$key] < $point[$bestKey]) {
                $bestKey = $key;
            }
        }
        $threshold = $point[$bestKey] + $standardErrors[$bestKey];
        $selectedKey = $bestKey;
        foreach ($lambdaKeys as $key) {
            if ($point[$key] <= $threshold) {
                $selectedKey = $key;
            }
        }

        return [
            'lambda' => (float) $selectedKey,
            'lambda_best' => (float) $bestKey,
            'one_se_threshold' => $threshold,
            'point_losses' => $point,
            'standard_errors' => $standardErrors,
        ];
    }

    /** @param array<int,Bt03e02ValidationLossSpool> $years @param list<string> $lambdaKeys @return array<string,float> */
    private function pointLosses(array $years, array $lambdaKeys): array
    {
        $yearValues = array_fill_keys($lambdaKeys, []);
        foreach ($years as $spool) {
            $weights = array_fill(0, $spool->raceCount(), 1);
            foreach ($this->weightedAggregates($spool, $weights, $lambdaKeys) as $key => $value) {
                $yearValues[$key][] = $value;
            }
        }
        $point = [];
        foreach ($yearValues as $key => $values) {
            $point[$key] = array_sum($values) / count($values);
        }

        return $point;
    }

    /**
     * @param  array<int,int>  $weights
     * @param  list<string>  $lambdaKeys
     * @return array<string,float>
     */
    private function weightedAggregates(Bt03e02ValidationLossSpool $spool, array $weights, array $lambdaKeys): array
    {
        $sums = $counts = [];
        foreach ($lambdaKeys as $key) {
            $sums[$key] = array_fill_keys(Bt03e02Contract::CHANNELS, 0.0);
            $counts[$key] = array_fill_keys(Bt03e02Contract::CHANNELS, 0);
        }
        $raceIndex = 0;
        foreach ($spool->records() as $values) {
            $weight = $weights[$raceIndex++] ?? throw new RuntimeException('BT-03E-02 One-SE weight count drifted.');
            if ($weight === 0) {
                continue;
            }
            foreach ($lambdaKeys as $lambdaOffset => $key) {
                foreach (Bt03e02Contract::CHANNELS as $channelOffset => $channel) {
                    $loss = $values[$lambdaOffset * count(Bt03e02Contract::CHANNELS) + $channelOffset];
                    if ($loss !== null) {
                        $sums[$key][$channel] += $weight * $loss;
                        $counts[$key][$channel] += $weight;
                    }
                }
            }
        }
        if ($raceIndex !== count($weights)) {
            throw new RuntimeException('BT-03E-02 One-SE loss count drifted.');
        }
        $aggregates = [];
        foreach ($lambdaKeys as $key) {
            $channelMeans = [];
            foreach (Bt03e02Contract::CHANNELS as $channel) {
                if ($counts[$key][$channel] === 0) {
                    throw new RuntimeException("BT-03E-02 {$channel} bootstrap sample had no eligible races.");
                }
                $channelMeans[] = $sums[$key][$channel] / $counts[$key][$channel];
            }
            $aggregates[$key] = array_sum($channelMeans) / count($channelMeans);
        }

        return $aggregates;
    }

    /** @param list<float> $values */
    private function sampleStandardDeviation(array $values): float
    {
        $mean = array_sum($values) / count($values);
        $squares = new Bt03e02CompensatedSum;
        foreach ($values as $value) {
            $squares->add(($value - $mean) ** 2);
        }

        return sqrt($squares->value() / (count($values) - 1));
    }
}
