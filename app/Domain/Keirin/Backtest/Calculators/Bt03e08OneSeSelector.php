<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\Services\Bt03e08Contract;
use App\Domain\Keirin\Backtest\Support\Bt03e08ValidationLossSpool;
use RuntimeException;

final class Bt03e08OneSeSelector
{
    /** @param array<int,Bt03e08ValidationLossSpool> $losses @return array<string,mixed> */
    public function select(array $losses, int $iterations = Bt03e08Contract::BOOTSTRAP_ITERATIONS): array
    {
        if ($losses === [] || $iterations < 2) {
            throw new RuntimeException('BT-03E-08 One-SE input was empty or invalid.');
        }
        ksort($losses, SORT_NUMERIC);
        $canonical = array_map(Bt03e08ValidationLossSpool::lambdaKey(...), Bt03e08Contract::LAMBDA_GRID);
        $available = $canonical;
        foreach ($losses as $spool) {
            $available = array_values(array_intersect($available, $spool->availableLambdaKeys()));
        }
        if ($available === []) {
            throw new RuntimeException('BT-03E-08 One-SE had no fully converged shared lambda candidate.');
        }
        $point = $this->pointLosses($losses, $available);
        $samples = array_fill_keys($available, []);
        $random = new DeterministicRandom(Bt03e08Contract::BOOTSTRAP_SEED);
        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            $replicate = array_fill_keys($available, []);
            foreach ($losses as $spool) {
                $count = $spool->raceCount();
                if ($count < 1) {
                    throw new RuntimeException('BT-03E-08 One-SE year had no races.');
                }
                $weights = array_fill(0, $count, 0);
                for ($draw = 0; $draw < $count; $draw++) {
                    $weights[$random->integer($count)]++;
                }
                foreach ($this->weightedAggregates($spool, $weights, $available) as $key => $value) {
                    $replicate[$key][] = $value;
                }
            }
            foreach ($available as $key) {
                $samples[$key][] = array_sum($replicate[$key]) / count($replicate[$key]);
            }
        }
        $standardErrors = [];
        foreach ($samples as $key => $values) {
            $standardErrors[$key] = $this->standardDeviation($values);
        }
        $best = $available[0];
        foreach ($available as $key) {
            if ($point[$key] < $point[$best]) {
                $best = $key;
            }
        }
        $threshold = $point[$best] + $standardErrors[$best];
        $selected = $best;
        foreach ($available as $key) {
            if ($point[$key] <= $threshold) {
                $selected = $key;
            }
        }

        return ['lambda' => (float) $selected, 'lambda_best' => (float) $best, 'one_se_threshold' => $threshold, 'point_losses' => $point, 'standard_errors' => $standardErrors, 'eligible_lambda_keys' => $available, 'excluded_lambda_keys' => array_values(array_diff($canonical, $available))];
    }

    /** @param array<int,Bt03e08ValidationLossSpool> $years @param list<string> $keys @return array<string,float> */
    private function pointLosses(array $years, array $keys): array
    {
        $byYear = array_fill_keys($keys, []);
        foreach ($years as $spool) {
            foreach ($this->weightedAggregates($spool, array_fill(0, $spool->raceCount(), 1), $keys) as $key => $value) {
                $byYear[$key][] = $value;
            }
        }

        return array_map(static fn (array $values): float => array_sum($values) / count($values), $byYear);
    }

    /** @param array<int,int> $weights @param list<string> $keys @return array<string,float> */
    private function weightedAggregates(Bt03e08ValidationLossSpool $spool, array $weights, array $keys): array
    {
        $offsets = array_flip(array_map(Bt03e08ValidationLossSpool::lambdaKey(...), Bt03e08Contract::LAMBDA_GRID));
        $sums = $counts = array_fill_keys($keys, 0.0);
        foreach ($keys as $key) {
            $counts[$key] = 0;
        }
        $raceIndex = 0;
        foreach ($spool->records() as $values) {
            $weight = $weights[$raceIndex++] ?? throw new RuntimeException('BT-03E-08 One-SE weight count drifted.');
            if ($weight === 0) {
                continue;
            }
            foreach ($keys as $key) {
                $loss = $values[$offsets[$key]];
                if ($loss !== null) {
                    $sums[$key] += $weight * $loss;
                    $counts[$key] += $weight;
                }
            }
        }
        if ($raceIndex !== count($weights)) {
            throw new RuntimeException('BT-03E-08 One-SE loss count drifted.');
        }
        $result = [];
        foreach ($keys as $key) {
            if ($counts[$key] === 0) {
                throw new RuntimeException('BT-03E-08 P3 bootstrap sample had no eligible races.');
            }
            $result[$key] = $sums[$key] / $counts[$key];
        }

        return $result;
    }

    /** @param list<float> $values */
    private function standardDeviation(array $values): float
    {
        $mean = array_sum($values) / count($values);
        $sum = new Bt03e03CompensatedSum;
        foreach ($values as $value) {
            $sum->add(($value - $mean) ** 2);
        }

        return sqrt($sum->value() / (count($values) - 1));
    }
}
