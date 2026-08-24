<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e02ParameterLayout;
use App\Domain\Keirin\Backtest\Calculators\Bt03e03AcceptanceGate;
use App\Domain\Keirin\Backtest\Calculators\Bt03e03MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e03ProbabilityScorer;
use App\Domain\Keirin\Backtest\DTO\Bt03e03FitResultDto;
use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use PHPUnit\Framework\TestCase;

class Bt03e03MetricsTest extends TestCase
{
    public function test_uniform_probability_log_loss_brier_and_calibration_are_known(): void
    {
        $race = (new Bt03e03ProbabilityScorer)->predict($this->race(), $this->fit());
        $metrics = (new Bt03e03MetricEvaluator)->evaluate(fn (): array => [$race]);

        foreach (Bt03e03Contract::POSITIONS as $position) {
            $this->assertEqualsWithDelta(log(5), $metrics['probability_metrics'][$position.'_LOG_LOSS'], 1e-13);
            $this->assertEqualsWithDelta(0.8, $metrics['probability_metrics'][$position.'_BRIER'], 1e-13);
            $bin = $metrics['calibration']['positions'][$position][2];
            $this->assertSame(5, $bin['count']);
            $this->assertEqualsWithDelta(0.2, $bin['mean_predicted_probability'], 1e-14);
            $this->assertEqualsWithDelta(0.2, $bin['observed_rate'], 1e-14);
        }
    }

    public function test_probability_metric_eligibility_stops_at_the_first_non_unique_position(): void
    {
        $race = $this->race([1, 2, 2, 4, 5]);
        $predicted = (new Bt03e03ProbabilityScorer)->predict($race, $this->fit());
        $metrics = (new Bt03e03MetricEvaluator)->evaluate(fn (): array => [$predicted]);

        $this->assertSame(1, $metrics['probability_metric_eligible_races']['POSITION_1']);
        $this->assertSame(0, $metrics['probability_metric_eligible_races']['POSITION_2']);
        $this->assertSame(0, $metrics['probability_metric_eligible_races']['POSITION_3']);
        $this->assertNull($metrics['probability_metrics']['POSITION_2_LOG_LOSS']);
    }

    public function test_existing_ranking_metrics_keep_the_perfect_order_contract(): void
    {
        $race = (new Bt03e03ProbabilityScorer)->predict($this->race(), $this->fit());
        foreach ($race['entries'] as &$entry) {
            $entry['predicted_position'] = $entry['bike'];
            $entry['map_top3_set'] = [1, 2, 3];
        }
        unset($entry);
        $metrics = (new Bt03e03MetricEvaluator)->evaluate(fn (): array => [$race]);

        $this->assertSame(Bt03e03MetricEvaluator::METRIC_CODES, array_keys($metrics['candidate']));
        foreach (Bt03e03MetricEvaluator::METRIC_CODES as $metric) {
            $this->assertSame(1.0, $metrics['candidate'][$metric], $metric);
            $this->assertSame(1.0, $metrics['baseline'][$metric], $metric);
        }
        $this->assertSame(3.0, $metrics['denominators']['POSITION_HIT_RATE_AT_3']);
    }

    public function test_acceptance_gate_adds_position_redesign_and_win_preservation_without_hiding_performance(): void
    {
        $outer = [];
        foreach ([2024, 2025] as $year) {
            $outer[$year] = [
                'delta' => array_fill_keys(Bt03e03MetricEvaluator::METRIC_CODES, 0.01),
                'tie_diagnostics' => [
                    'ordered_probability_tied_races' => 0,
                    'baseline_exact_score_tied_races' => 1,
                    'technical_tiebreak_races' => 0,
                ],
                'race_count' => 100,
            ];
        }
        $intervals = array_fill_keys(
            Bt03e03MetricEvaluator::METRIC_CODES,
            ['ci_lower' => 0.001, 'ci_upper' => 0.02],
        );
        $gate = new Bt03e03AcceptanceGate;

        $passed = $gate->evaluate($outer, $intervals, true);
        $this->assertSame('PASS / GO_TO_FREEZE', $passed['status']);
        $this->assertTrue($passed['gates']['position_redesign']);
        $this->assertTrue($passed['gates']['win_preservation']);

        $unverified = $gate->evaluate($outer, $intervals, false);
        $this->assertSame('FAIL / REDESIGN_REQUIRED', $unverified['status']);
        $this->assertSame('PASS / GO_TO_FREEZE', $unverified['performance_status']);

        $outer[2024]['delta']['POSITION_3_ACCURACY'] = -0.01;
        $failed = $gate->evaluate($outer, $intervals, true);
        $this->assertFalse($failed['gates']['position_redesign']);
        $this->assertSame('FAIL / REDESIGN_REQUIRED', $failed['status']);
    }

    /** @param list<int> $ranks @return array<string,mixed> */
    private function race(array $ranks = [1, 2, 3, 4, 5]): array
    {
        $entries = [];
        foreach ($ranks as $offset => $rank) {
            $bins = array_fill(0, count(Bt03e03Contract::STAT_CODES), null);
            $bins[0] = $offset;
            $entries[] = [
                'id' => $offset + 1,
                'bike' => $offset + 1,
                'raw' => 100.0 - $offset,
                'stat01_rank' => $offset + 1,
                'anchor' => 0.0,
                'bins' => $bins,
                'rank' => $rank,
                'status' => $rank === 2 && count(array_keys($ranks, 2, true)) > 1 ? 'TIED' : 'FINISHED',
            ];
        }

        return ['year' => 2024, 'race_id' => 1, 'entries' => $entries];
    }

    private function fit(): Bt03e03FitResultDto
    {
        $size = $this->layout()->size();

        return new Bt03e03FitResultDto(
            0.1,
            array_fill_keys(Bt03e03Contract::POSITIONS, array_fill(0, $size, 0.0)),
            array_fill_keys(Bt03e03Contract::POSITIONS, 0.0),
            array_fill_keys(Bt03e03Contract::POSITIONS, 1),
            array_fill_keys(Bt03e03Contract::POSITIONS, 1),
            array_fill_keys(Bt03e03Contract::POSITIONS, 0),
        );
    }

    private function layout(): Bt03e02ParameterLayout
    {
        $bins = [];
        foreach (Bt03e03Contract::STAT_CODES as $statCode) {
            foreach (range(1, 5) as $index) {
                $bins[$statCode][] = new EffectBinDto($index, 'CATEGORY', null, null, (string) $index, 1);
            }
        }

        return new Bt03e02ParameterLayout($bins);
    }
}
