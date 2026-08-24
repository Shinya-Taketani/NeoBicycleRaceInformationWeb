<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use App\Domain\Keirin\Backtest\Support\Bt03e03MetricContributionSpool;
use RuntimeException;

final class Bt03e03PairedBootstrap
{
    public function __construct(
        private readonly Bt03e03MetricEvaluator $metrics,
        private readonly Type7Quantile $quantile,
    ) {}

    /**
     * @param  array<int,array{source:callable():iterable<array<string,mixed>>,race_count:int}>  $years
     * @return array<string,array{ci_lower:float,ci_upper:float}>
     */
    public function evaluate(array $years, int $iterations = Bt03e03Contract::BOOTSTRAP_ITERATIONS): array
    {
        if ($years === [] || $iterations < 1) {
            throw new RuntimeException('BT-03E-03 paired bootstrap input was invalid.');
        }
        ksort($years, SORT_NUMERIC);
        $spools = [];
        try {
            foreach ($years as $year => $definition) {
                $spool = new Bt03e03MetricContributionSpool(sys_get_temp_dir().'/bt03e03-bootstrap-'.$year.'-'.bin2hex(random_bytes(8)).'.bin');
                $spools[$year] = $spool;
                foreach (($definition['source'])() as $race) {
                    $spool->append($this->metrics->raceComparison($race));
                }
                $spool->seal();
                if ($spool->raceCount() !== $definition['race_count']) {
                    throw new RuntimeException('BT-03E-03 paired bootstrap source count drifted.');
                }
            }

            return $this->bootstrap($spools, $iterations);
        } finally {
            foreach ($spools as $spool) {
                $spool->cleanup();
            }
        }
    }

    /** @param array<int,Bt03e03MetricContributionSpool> $years @return array<string,array{ci_lower:float,ci_upper:float}> */
    private function bootstrap(array $years, int $iterations): array
    {
        $samples = array_fill_keys(Bt03e03MetricEvaluator::METRIC_CODES, []);
        $random = new DeterministicRandom(Bt03e03Contract::BOOTSTRAP_SEED);
        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            $yearDeltas = array_fill_keys(Bt03e03MetricEvaluator::METRIC_CODES, []);
            foreach ($years as $spool) {
                $count = $spool->raceCount();
                if ($count < 1) {
                    throw new RuntimeException('BT-03E-03 paired bootstrap year was empty.');
                }
                $weights = array_fill(0, $count, 0);
                for ($draw = 0; $draw < $count; $draw++) {
                    $weights[$random->integer($count)]++;
                }
                $candidate = $baseline = $denominators = array_fill_keys(Bt03e03MetricEvaluator::METRIC_CODES, 0.0);
                $raceIndex = 0;
                foreach ($spool->records() as $values) {
                    $weight = $weights[$raceIndex++] ?? throw new RuntimeException('BT-03E-03 paired bootstrap source exceeded its seal.');
                    foreach (Bt03e03MetricEvaluator::METRIC_CODES as $offset => $metric) {
                        $candidate[$metric] += $weight * $values[$offset * 3];
                        $baseline[$metric] += $weight * $values[$offset * 3 + 1];
                        $denominators[$metric] += $weight * $values[$offset * 3 + 2];
                    }
                }
                if ($raceIndex !== $count) {
                    throw new RuntimeException('BT-03E-03 paired bootstrap source count drifted.');
                }
                foreach (Bt03e03MetricEvaluator::METRIC_CODES as $metric) {
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
            'ci_lower' => $this->quantile->calculate($values, 0.025),
            'ci_upper' => $this->quantile->calculate($values, 0.975),
        ], $samples);
    }
}
