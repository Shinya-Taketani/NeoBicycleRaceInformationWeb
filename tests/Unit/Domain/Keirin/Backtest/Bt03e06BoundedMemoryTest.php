<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e03ProbabilityScorer;
use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e05PairedBootstrap;
use App\Domain\Keirin\Backtest\Calculators\Bt03e06PairedBootstrap;
use App\Domain\Keirin\Backtest\Calculators\Bt03e06WinnerConditionedDecoder;
use App\Domain\Keirin\Backtest\Calculators\Type7Quantile;
use App\Domain\Keirin\Backtest\DTO\Bt03e03FitResultDto;
use App\Domain\Keirin\Backtest\Services\Bt03e06Contract;
use App\Domain\Keirin\Backtest\Support\Bt03e06DecoderManifestAccumulator;
use App\Domain\Keirin\Backtest\Support\Bt03e06MetricContributionSpool;
use App\Domain\Keirin\Backtest\Support\Bt03e06RaceSpool;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use PHPUnit\Framework\TestCase;

class Bt03e06BoundedMemoryTest extends TestCase
{
    public function test_two_thousand_nine_rider_forward_decodes_and_bootstrap_are_bounded(): void
    {
        $hasher = new CanonicalHasher;
        $scorer = new Bt03e03ProbabilityScorer;
        $decoder = new Bt03e06WinnerConditionedDecoder($scorer, $hasher);
        $fit = new Bt03e03FitResultDto(
            0.1,
            array_fill_keys(Bt03e06Contract::POSITIONS, []),
            [],
            [],
            [],
            [],
        );
        $decisions = $metrics = [];
        try {
            foreach (Bt03e06Contract::DEVELOPMENT_YEARS as $year) {
                $decisions[$year] = new Bt03e06RaceSpool(
                    'DECODER',
                    sys_get_temp_dir()."/bt03e06-bounded-decoder-{$year}-".bin2hex(random_bytes(8)).'.jsonl',
                );
                $metrics[$year] = new Bt03e06MetricContributionSpool(
                    sys_get_temp_dir()."/bt03e06-bounded-metric-{$year}-".bin2hex(random_bytes(8)).'.bin',
                );
                $manifest = new Bt03e06DecoderManifestAccumulator(
                    $year,
                    ['source_model_canonical_sha256' => str_repeat((string) ($year - 2024), 64)],
                    $hasher,
                );
                foreach (range(1, 1000) as $offset) {
                    $prediction = $scorer->predict($this->race($year, ($year - 2024) * 1000 + $offset), $fit);
                    $decision = $decoder->decode($prediction);
                    $manifest->append($decision);
                    $decisions[$year]->append($decision);
                    $metrics[$year]->append($this->comparison($offset));
                }
                $decisions[$year]->seal();
                $metrics[$year]->seal();
                $this->assertSame(1000, $decisions[$year]->metadata()['race_count']);
                $this->assertSame(1000, $manifest->seal()['race_count']);
            }

            $intervals = (new Bt03e06PairedBootstrap(
                new Bt03e05PairedBootstrap(new Type7Quantile),
            ))->evaluate($metrics);
            $this->assertSame(Bt03e05MetricEvaluator::METRIC_CODES, array_keys($intervals));
            $this->assertLessThan(128 * 1024 * 1024, memory_get_peak_usage(true));
        } finally {
            foreach ($decisions as $spool) {
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
        $entries = [];
        foreach (range(1, 9) as $bike) {
            $entries[] = [
                'id' => $raceId * 10 + $bike,
                'bike' => $bike,
                'raw' => 80.0 + $bike,
                'stat01_rank' => 10 - $bike,
                'anchor' => ($bike - 5.0) / 3.0,
                'bins' => [],
                'rank' => null,
                'status' => 'PREDICTION_ONLY',
            ];
        }

        return ['year' => $year, 'race_id' => $raceId, 'entries' => $entries];
    }

    /** @return array<string,mixed> */
    private function comparison(int $offset): array
    {
        $comparison = ['candidate' => [], 'baseline' => []];
        foreach (Bt03e05MetricEvaluator::METRIC_CODES as $metricOffset => $metric) {
            $comparison['candidate'][$metric] = [
                'numerator' => (float) (($offset + $metricOffset) % 2),
                'denominator' => 1.0,
            ];
            $comparison['baseline'][$metric] = [
                'numerator' => (float) (($offset + $metricOffset + 1) % 2),
                'denominator' => 1.0,
            ];
        }

        return $comparison;
    }
}
