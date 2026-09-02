<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e03ProbabilityScorer;
use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e05PairedBootstrap;
use App\Domain\Keirin\Backtest\Calculators\Bt03e06WinnerConditionedDecoder;
use App\Domain\Keirin\Backtest\Calculators\Bt03e07PairedBootstrap;
use App\Domain\Keirin\Backtest\Calculators\Bt03e08P1Q2FrozenDecoder;
use App\Domain\Keirin\Backtest\Calculators\Type7Quantile;
use App\Domain\Keirin\Backtest\Services\Bt03e08Contract;
use App\Domain\Keirin\Backtest\Support\Bt03e06MetricContributionSpool;
use App\Domain\Keirin\Backtest\Support\Bt03e06RaceSpool;
use App\Domain\Keirin\Backtest\Support\Bt03e08PredictionManifestAccumulator;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use PHPUnit\Framework\TestCase;

final class Bt03e08BoundedMemoryTest extends TestCase
{
    public function test_two_thousand_nine_rider_decisions_and_bootstrap_stay_below_128m(): void
    {
        $hasher = new CanonicalHasher;
        $sourceDecoder = new Bt03e06WinnerConditionedDecoder(new Bt03e03ProbabilityScorer, $hasher);
        $decoder = new Bt03e08P1Q2FrozenDecoder($sourceDecoder, $hasher);
        $predictions = $metrics = [];
        try {
            foreach (Bt03e08Contract::OUTER_YEARS as $year) {
                $predictions[$year] = new Bt03e06RaceSpool('DECODER', sys_get_temp_dir().'/bt03e08-bounded-prediction-'.$year.'-'.bin2hex(random_bytes(8)).'.jsonl');
                $metrics[$year] = new Bt03e06MetricContributionSpool(sys_get_temp_dir().'/bt03e08-bounded-metric-'.$year.'-'.bin2hex(random_bytes(8)).'.bin');
                $manifest = new Bt03e08PredictionManifestAccumulator($year, ['source' => str_repeat((string) ($year - 2024), 64)], $hasher);
                foreach (range(1, 1000) as $offset) {
                    $raceId = ($year - 2024) * 1000 + $offset;
                    $source = $this->source($year, $raceId);
                    $winner = $sourceDecoder->decode($source)['primary_position_1_bike'];
                    $p3 = ['year' => $year, 'race_id' => $raceId, 'winner_bike' => $winner, 'entries' => array_map(static fn (array $entry): array => ['id' => $entry['bike'], 'bike' => $entry['bike'], 'r3_probability' => $entry['bike'] === 1 ? 0.0 : 0.125], $source['entries'])];
                    $decision = $decoder->decode($source, $p3);
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
            } foreach ($metrics as $spool) {
                $spool->cleanup();
            }
        }
    }

    /** @return array<string,mixed> */
    private function source(int $year, int $raceId): array
    {
        $entries = [];
        foreach (range(1, 9) as $bike) {
            $p1 = $bike === 1 ? 0.2 : 0.1;
            $entries[] = ['bike' => $bike, 'position_1_probability' => $p1, 'position_2_probability' => 1 / 9, 'position_3_probability' => 1 / 9, 'top2_probability' => $p1 + 1 / 9, 'top3_probability' => $p1 + 2 / 9, 'utilities' => ['POSITION_1' => $bike === 1 ? 1.0 : 0.0, 'POSITION_2' => 0.0, 'POSITION_3' => 0.0]];
        }

        return ['year' => $year, 'race_id' => $raceId, 'entries' => $entries, 'map_ordered_top3' => [1, 2, 3], 'map_ordered_probability' => 0.01, 'map_top3_set' => [1, 2, 3], 'map_top3_set_probability' => 0.02];
    }

    /** @return array<string,mixed> */
    private function comparison(int $offset): array
    {
        $value = ['candidate' => [], 'baseline' => []];
        foreach (Bt03e05MetricEvaluator::METRIC_CODES as $metricOffset => $metric) {
            $value['candidate'][$metric] = ['numerator' => (float) (($offset + $metricOffset) % 2), 'denominator' => 1.0];
            $value['baseline'][$metric] = ['numerator' => (float) (($offset + $metricOffset + 1) % 2), 'denominator' => 1.0];
        }

return $value;
    }
}
