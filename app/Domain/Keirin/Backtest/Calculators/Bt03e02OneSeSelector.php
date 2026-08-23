<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\Services\Bt03e02Contract;
use RuntimeException;

final class Bt03e02OneSeSelector
{
    /**
     * @param  array<int, array<string, array<string, array<int,float>>>>  $losses  year => lambda key => channel => race => loss
     * @return array{lambda:float,lambda_best:float,one_se_threshold:float,point_losses:array<string,float>,standard_errors:array<string,float>}
     */
    public function select(array $losses, int $iterations = Bt03e02Contract::BOOTSTRAP_ITERATIONS): array
    {
        if ($losses === [] || $iterations < 2) {
            throw new RuntimeException('BT-03E-02 One-SE input was empty or invalid.');
        }
        ksort($losses, SORT_NUMERIC);
        $lambdaKeys = array_map(fn (float $lambda): string => $this->key($lambda), Bt03e02Contract::LAMBDA_GRID);
        $point = [];
        foreach ($lambdaKeys as $key) {
            $yearMeans = [];
            foreach ($losses as $yearLosses) {
                $yearMeans[] = $this->aggregate($yearLosses[$key] ?? throw new RuntimeException("BT-03E-02 One-SE lambda {$key} was missing."));
            }
            $point[$key] = array_sum($yearMeans) / count($yearMeans);
        }

        $samples = array_fill_keys($lambdaKeys, []);
        $random = new DeterministicRandom(Bt03e02Contract::BOOTSTRAP_SEED);
        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            $replicateByLambda = array_fill_keys($lambdaKeys, []);
            foreach ($losses as $yearLosses) {
                $universe = [];
                foreach ($yearLosses[$lambdaKeys[0]] as $channelLosses) {
                    foreach (array_keys($channelLosses) as $raceId) {
                        $universe[(int) $raceId] = true;
                    }
                }
                $raceIds = array_map('intval', array_keys($universe));
                sort($raceIds, SORT_NUMERIC);
                if ($raceIds === []) {
                    throw new RuntimeException('BT-03E-02 One-SE year had no eligible races.');
                }
                $weights = array_fill_keys($raceIds, 0);
                for ($draw = 0; $draw < count($raceIds); $draw++) {
                    $weights[$raceIds[$random->integer(count($raceIds))]]++;
                }
                foreach ($lambdaKeys as $key) {
                    $replicateByLambda[$key][] = $this->weightedAggregate($yearLosses[$key], $weights);
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

    /** @param array<string,array<int,float>> $channelLosses */
    private function aggregate(array $channelLosses): float
    {
        $means = [];
        foreach (Bt03e02Contract::CHANNELS as $channel) {
            $values = $channelLosses[$channel] ?? [];
            if ($values === []) {
                throw new RuntimeException("BT-03E-02 {$channel} validation loss was empty.");
            }
            $means[] = array_sum($values) / count($values);
        }

        return array_sum($means) / count($means);
    }

    /** @param array<string,array<int,float>> $channelLosses @param array<int,int> $weights */
    private function weightedAggregate(array $channelLosses, array $weights): float
    {
        $channelMeans = [];
        foreach (Bt03e02Contract::CHANNELS as $channel) {
            $sum = new Bt03e02CompensatedSum;
            $denominator = 0;
            foreach ($channelLosses[$channel] ?? [] as $raceId => $loss) {
                $weight = $weights[$raceId] ?? 0;
                if ($weight > 0) {
                    $sum->add($weight * $loss);
                    $denominator += $weight;
                }
            }
            if ($denominator === 0) {
                throw new RuntimeException("BT-03E-02 {$channel} bootstrap sample had no eligible races.");
            }
            $channelMeans[] = $sum->value() / $denominator;
        }

        return array_sum($channelMeans) / count($channelMeans);
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

    private function key(float $lambda): string
    {
        return sprintf('%.17g', $lambda);
    }
}
