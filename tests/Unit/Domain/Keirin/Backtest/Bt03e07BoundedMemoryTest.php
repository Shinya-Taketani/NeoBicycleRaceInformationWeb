<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e05PairedBootstrap;
use App\Domain\Keirin\Backtest\Calculators\Bt03e07DirectPositionScorer;
use App\Domain\Keirin\Backtest\Calculators\Bt03e07P1FrozenDecisionDecoder;
use App\Domain\Keirin\Backtest\Calculators\Bt03e07PairedBootstrap;
use App\Domain\Keirin\Backtest\Calculators\Type7Quantile;
use App\Domain\Keirin\Backtest\DTO\Bt03e07FitResultDto;
use App\Domain\Keirin\Backtest\Services\Bt03e07Contract;
use App\Domain\Keirin\Backtest\Support\Bt03e06MetricContributionSpool;
use App\Domain\Keirin\Backtest\Support\Bt03e06RaceSpool;
use App\Domain\Keirin\Backtest\Support\Bt03e07PredictionManifestAccumulator;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use PHPUnit\Framework\TestCase;

final class Bt03e07BoundedMemoryTest extends TestCase
{
    public function test_two_thousand_nine_rider_predictions_metric_spools_and_bootstrap_are_bounded(): void
    {
        $hasher = new CanonicalHasher;
        $scorer = new Bt03e07DirectPositionScorer($hasher);
        $decoder = new Bt03e07P1FrozenDecisionDecoder;
        $fit = new Bt03e07FitResultDto(0.1, array_fill_keys(Bt03e07Contract::POSITIONS, []), [], [], [], [], []);
        $predictions = $metrics = [];
        try {
            foreach (Bt03e07Contract::OUTER_YEARS as $year) {
                $predictions[$year] = new Bt03e06RaceSpool('DECODER', sys_get_temp_dir()."/bt03e07-bounded-prediction-{$year}-".bin2hex(random_bytes(8)).'.jsonl');
                $metrics[$year] = new Bt03e06MetricContributionSpool(sys_get_temp_dir()."/bt03e07-bounded-metric-{$year}-".bin2hex(random_bytes(8)).'.bin');
                $manifest = new Bt03e07PredictionManifestAccumulator($year, ['source' => str_repeat((string) ($year - 2024), 64)], $hasher);
                foreach (range(1, 1000) as $offset) {
                    $raceId = ($year - 2024) * 1000 + $offset;
                    $decision = $decoder->decode($this->source($year, $raceId), $scorer->predict($this->race($year, $raceId), $fit));
                    $manifest->append($decision);
                    $predictions[$year]->append($decision);
                    $metrics[$year]->append($this->comparison($offset));
                }
                $predictions[$year]->seal();
                $metrics[$year]->seal();
                $this->assertSame(1000, $predictions[$year]->metadata()['race_count']);
                $this->assertSame(1000, $manifest->seal()['race_count']);
            }
            $intervals = (new Bt03e07PairedBootstrap(new Bt03e05PairedBootstrap(new Type7Quantile)))->evaluate($metrics);
            $this->assertSame(Bt03e05MetricEvaluator::METRIC_CODES, array_keys($intervals));
            $this->assertLessThan(128 * 1024 * 1024, memory_get_peak_usage(true));
        } finally {
            foreach ($predictions as $spool) {
                $spool->cleanup();
            }
            foreach ($metrics as $spool) {
                $spool->cleanup();
            }
        }
    }

    /** @return array<string,mixed> */
    private function race(int $year, int $raceId): array
    {
        return ['year' => $year, 'race_id' => $raceId, 'entries' => array_map(static fn (int $bike): array => ['id' => $raceId * 10 + $bike, 'bike' => $bike, 'anchor' => ($bike - 5.0) / 3.0, 'bins' => []], range(1, 9))];
    }

    /** @return array<string,mixed> */
    private function source(int $year, int $raceId): array
    {
        $entries = [];
        foreach (range(1, 9) as $bike) {
            $p1 = $bike === 1 ? 0.2 : 0.1;
            $entries[] = ['bike' => $bike, 'position_1_probability' => $p1, 'position_2_probability' => 1 / 9, 'position_3_probability' => 1 / 9, 'top2_probability' => $p1 + 1 / 9, 'top3_probability' => $p1 + 2 / 9];
        }

        return ['year' => $year, 'race_id' => $raceId, 'entries' => $entries, 'map_ordered_top3' => [1, 2, 3], 'map_ordered_probability' => 0.01, 'map_top3_set' => [1, 2, 3], 'map_top3_set_probability' => 0.02];
    }

    /** @return array<string,mixed> */
    private function comparison(int $offset): array
    {
        $comparison = ['candidate' => [], 'baseline' => []];
        foreach (Bt03e05MetricEvaluator::METRIC_CODES as $metricOffset => $metric) {
            $comparison['candidate'][$metric] = ['numerator' => (float) (($offset + $metricOffset) % 2), 'denominator' => 1.0];
            $comparison['baseline'][$metric] = ['numerator' => (float) (($offset + $metricOffset + 1) % 2), 'denominator' => 1.0];
        }

        return $comparison;
    }
}
