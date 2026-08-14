<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\BinaryMetricCalculator;
use App\Domain\Keirin\Backtest\Calculators\PairedRaceClusterMetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\RaceClusterBootstrap;
use App\Domain\Keirin\Backtest\Calculators\Type7Quantile;
use App\Domain\Keirin\Backtest\Support\Bt02PredictionSpool;
use Generator;
use PHPUnit\Framework\TestCase;

class PairedRaceClusterMetricEvaluatorTest extends TestCase
{
    public function test_shared_weighted_bootstrap_matches_naive_exact_reference_for_all_metrics(): void
    {
        $bootstrap = new class extends RaceClusterBootstrap
        {
            public int $calls = 0;

            public function resampleIndexes(int $raceCount, int $iterations = self::ITERATIONS, int $seed = self::SEED): Generator
            {
                $this->calls++;
                yield from parent::resampleIndexes($raceCount, $iterations, $seed);
            }
        };
        $directory = sys_get_temp_dir().'/bt02-paired-metrics-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        $spool = new Bt02PredictionSpool(['fold' => 'WF_2023', 'label_code' => 'IS_WIN'], $directory);
        $payloads = [
            ['race_id' => 1, 'labels' => [1, 0], 'baseline' => [0.8, 0.3], 'incremental' => [0.9, 0.2]],
            ['race_id' => 2, 'labels' => [0, 1], 'baseline' => [0.4, 0.8], 'incremental' => [0.4, 0.7]],
            ['race_id' => 3, 'labels' => [0, 0], 'baseline' => [0.1, 0.3], 'incremental' => [0.2, 0.3]],
            ['race_id' => 4, 'labels' => [1, 0], 'baseline' => [0.6, 0.6], 'incremental' => [0.6, 0.5]],
        ];
        foreach ($payloads as $payload) {
            foreach ($payload['labels'] as $index => $label) {
                $spool->append($payload['race_id'], $payload['race_id'] * 10 + $index, $label, $payload['baseline'][$index], $payload['incremental'][$index]);
            }
        }
        $spool->seal();
        $iterations = 64;
        $quantile = new Type7Quantile;
        $calculator = new BinaryMetricCalculator;

        try {
            $actual = (new PairedRaceClusterMetricEvaluator($bootstrap, $quantile, $directory))->evaluate($spool, $iterations);
            $expected = $this->naive($payloads, $iterations, $calculator, $quantile);

            $this->assertSame(1, $bootstrap->calls, 'All three metrics must share one bootstrap replicate stream.');
            $this->assertSame($iterations, $actual->bootstrapReplicateCount);
            $this->assertSame([1, 2, 3, 4], $actual->raceIds);
            $this->assertSame(8, $actual->rowCount);
            foreach (['AUC', 'LOG_LOSS', 'BRIER'] as $metric) {
                foreach (['baseline', 'incremental', 'delta', 'ci_lower', 'ci_upper'] as $field) {
                    $this->assertEqualsWithDelta($expected[$metric][$field], $actual->metrics[$metric][$field], 1e-12, "{$metric} {$field}");
                }
            }
        } finally {
            $spool->cleanup();
            $this->assertSame([], array_values(array_diff(scandir($directory), ['.', '..'])));
            rmdir($directory);
        }
    }

    /**
     * @param  list<array{race_id: int, labels: list<int>, baseline: list<float>, incremental: list<float>}>  $payloads
     * @return array<string, array{baseline: ?float, incremental: ?float, delta: ?float, ci_lower: ?float, ci_upper: ?float}>
     */
    private function naive(array $payloads, int $iterations, BinaryMetricCalculator $metrics, Type7Quantile $quantile): array
    {
        $point = $this->metricPairs($payloads, $metrics);
        $samples = ['AUC' => [], 'LOG_LOSS' => [], 'BRIER' => []];
        $bootstrap = new RaceClusterBootstrap;
        foreach ($bootstrap->resampleIndexes(count($payloads), $iterations, RaceClusterBootstrap::SEED) as $indexes) {
            $pairs = $this->metricPairs($bootstrap->apply($payloads, $indexes), $metrics);
            foreach ($pairs as $metric => [$baseline, $incremental]) {
                if ($baseline !== null && $incremental !== null) {
                    $samples[$metric][] = $incremental - $baseline;
                }
            }
        }
        $result = [];
        foreach ($point as $metric => [$baseline, $incremental]) {
            $result[$metric] = [
                'baseline' => $baseline,
                'incremental' => $incremental,
                'delta' => $baseline !== null && $incremental !== null ? $incremental - $baseline : null,
                'ci_lower' => $samples[$metric] === [] ? null : $quantile->calculate($samples[$metric], 0.025),
                'ci_upper' => $samples[$metric] === [] ? null : $quantile->calculate($samples[$metric], 0.975),
            ];
        }

        return $result;
    }

    /** @param list<array{race_id: int, labels: list<int>, baseline: list<float>, incremental: list<float>}> $payloads @return array<string, array{?float, ?float}> */
    private function metricPairs(array $payloads, BinaryMetricCalculator $metrics): array
    {
        $labels = $baseline = $incremental = [];
        foreach ($payloads as $payload) {
            array_push($labels, ...$payload['labels']);
            array_push($baseline, ...$payload['baseline']);
            array_push($incremental, ...$payload['incremental']);
        }

        return [
            'AUC' => [$metrics->auc($baseline, $labels), $metrics->auc($incremental, $labels)],
            'LOG_LOSS' => [$metrics->logLoss($baseline, $labels), $metrics->logLoss($incremental, $labels)],
            'BRIER' => [$metrics->brier($baseline, $labels), $metrics->brier($incremental, $labels)],
        ];
    }
}
