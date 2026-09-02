<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05AcceptanceGate;
use App\Domain\Keirin\Backtest\Calculators\Bt03e05DecisionDecoder;
use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e05PairedBootstrap;
use App\Domain\Keirin\Backtest\Calculators\Bt03e06AcceptanceGate;
use App\Domain\Keirin\Backtest\Calculators\Bt03e06MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e06PairedBootstrap;
use App\Domain\Keirin\Backtest\Calculators\Bt03e07AcceptanceGate;
use App\Domain\Keirin\Backtest\Calculators\Bt03e07MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e07PairedBootstrap;
use App\Domain\Keirin\Backtest\Calculators\Bt03e08AcceptanceGate;
use App\Domain\Keirin\Backtest\Calculators\Bt03e08MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e08PairedBootstrap;
use App\Domain\Keirin\Backtest\Calculators\Type7Quantile;
use App\Domain\Keirin\Backtest\Services\Bt03e05Contract;
use App\Domain\Keirin\Backtest\Services\Bt03e06Contract;
use App\Domain\Keirin\Backtest\Services\Bt03e07Contract;
use App\Domain\Keirin\Backtest\Services\Bt03e08Contract;
use App\Domain\Keirin\Backtest\Support\Bt03e06MetricContributionSpool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Bt03e08FrozenRegressionTest extends TestCase
{
    public function test_e08_gate_contract_is_semantically_identical_to_e05_e06_and_e07(): void
    {
        $e05 = Bt03e05Contract::acceptanceGate();
        unset($e05['metrics']);

        $this->assertSame($e05, Bt03e06Contract::acceptanceGate());
        $this->assertSame($e05, Bt03e07Contract::acceptanceGate());
        $this->assertSame($e05, Bt03e08Contract::acceptanceGate());
    }

    public function test_every_frozen_gate_boundary_is_bit_exact_across_e05_e06_e07_and_e08(): void
    {
        [$outer, $intervals] = $this->passingGateInput();
        $intervals['WINNER_HIT_AT_1']['ci_lower'] = -0.0015;
        $this->assertGateSame($outer, $intervals, 'non_inferiority', false);
        $intervals['WINNER_HIT_AT_1']['ci_lower'] = -0.0015 + PHP_FLOAT_EPSILON;
        $this->assertGateSame($outer, $intervals, 'non_inferiority', true);

        [$outer, $intervals] = $this->passingGateInput();
        $intervals['POSITION_HIT_RATE_AT_3']['ci_lower'] = 0.0;
        $this->assertGateSame($outer, $intervals, 'superiority', false);
        $intervals['POSITION_HIT_RATE_AT_3']['ci_lower'] = PHP_FLOAT_EPSILON;
        $this->assertGateSame($outer, $intervals, 'superiority', true);

        [$outer, $intervals] = $this->passingGateInput();
        foreach (['WINNER_HIT_AT_1', 'POSITION_2_ACCURACY', 'POSITION_3_ACCURACY'] as $metric) {
            $intervals[$metric]['ci_lower'] = 0.0;
        }
        $this->assertGateSame($outer, $intervals, 'superiority', false);
        $intervals['POSITION_2_ACCURACY']['ci_lower'] = PHP_FLOAT_EPSILON;
        $this->assertGateSame($outer, $intervals, 'superiority', true);

        [$outer, $intervals] = $this->passingGateInput();
        foreach ($outer as &$year) {
            $year['delta']['WINNER_HIT_AT_1'] = 0.0;
            $year['delta']['POSITION_2_ACCURACY'] = 0.0;
        }
        unset($year);
        $this->assertGateSame($outer, $intervals, 'superiority', false);
        foreach ($outer as &$year) {
            $year['delta']['POSITION_2_ACCURACY'] = PHP_FLOAT_EPSILON;
        }
        unset($year);
        $this->assertGateSame($outer, $intervals, 'superiority', true);

        [$outer, $intervals] = $this->passingGateInput();
        $outer[2024]['delta']['WINNER_HIT_AT_1'] = -0.003;
        $this->assertGateSame($outer, $intervals, 'temporal_stability', true);
        $outer[2024]['delta']['WINNER_HIT_AT_1'] = -0.0030000001;
        $this->assertGateSame($outer, $intervals, 'temporal_stability', false);

        [$outer, $intervals] = $this->passingGateInput();
        $supporting = ['EXACT_ORDERED_TOP3_RATE', 'EXACT_TOP3_SET_RATE', 'TOP3_COVERAGE_AT_3', 'EXACT_TOP2_SET_RATE', 'TOP2_COVERAGE_AT_2', 'NDCG_AT_3'];
        foreach ($outer as &$year) {
            foreach ($supporting as $offset => $metric) {
                $year['delta'][$metric] = $offset < 4 ? 0.0 : -0.002;
            }
        }
        unset($year);
        $this->assertGateSame($outer, $intervals, 'supporting', true);
        foreach ($outer as &$year) {
            $year['delta']['NDCG_AT_3'] = -0.0020000001;
        }
        unset($year);
        $this->assertGateSame($outer, $intervals, 'supporting', false);

        [$outer, $intervals] = $this->passingGateInput();
        foreach ($outer as &$year) {
            $year['tie_diagnostics']['primary_score_tied_races'] = 1;
            $year['tie_diagnostics']['baseline_exact_score_tied_races'] = 1;
            $year['tie_diagnostics']['technical_tiebreak_races'] = 1;
        }
        unset($year);
        $this->assertGateSame($outer, $intervals, 'tie_quality', true);
        $outer[2024]['tie_diagnostics']['technical_tiebreak_races'] = 2;
        $this->assertGateSame($outer, $intervals, 'tie_quality', false);

        foreach ([
            'POSITION_2_ACCURACY' => true,
            'WINNER_HIT_AT_1' => false,
            'POSITION_3_ACCURACY' => false,
            'POSITION_HIT_RATE_AT_3' => false,
        ] as $metric => $expected) {
            [$outer, $intervals] = $this->passingGateInput();
            foreach ($outer as &$year) {
                $year['delta'][$metric] = 0.0;
            }
            unset($year);
            $this->assertGateSame($outer, $intervals, 'position_redesign', $expected);
        }

        [$outer, $intervals] = $this->passingGateInput();
        $outer[2024]['delta']['WINNER_HIT_AT_1'] = 0.0;
        $this->assertGateSame($outer, $intervals, 'win_preservation', true);
        $outer[2024]['delta']['WINNER_HIT_AT_1'] = -PHP_FLOAT_EPSILON;
        $this->assertGateSame($outer, $intervals, 'win_preservation', false);
    }

    #[DataProvider('rankCases')]
    public function test_all_eleven_baseline_metrics_are_bit_exact_with_frozen_evaluators(array $ranks): void
    {
        $e05 = new Bt03e05MetricEvaluator;
        $e06 = new Bt03e06MetricEvaluator($e05);
        $e07 = new Bt03e07MetricEvaluator($e05);
        $e08 = new Bt03e08MetricEvaluator($e05);
        $context = $this->context($ranks);
        $decision = (new Bt03e05DecisionDecoder)->decode($this->sourceRace());
        $frozen = $e05->raceComparison($context, $decision)['baseline'];

        $this->assertCount(11, $frozen);
        $this->assertSame([], array_values(array_diff(Bt03e05MetricEvaluator::METRIC_CODES, array_keys($frozen))));
        $this->assertSame($frozen, $e06->raceComparison($context, $decision)['baseline']);
        $this->assertSame($frozen, $e07->raceComparison($context, $decision)['baseline']);
        $this->assertSame($frozen, $e08->raceComparison($context, $decision)['baseline']);
    }

    public function test_e08_bootstrap_is_bit_exact_with_frozen_implementations_and_deterministic(): void
    {
        $this->assertSame(2000, Bt03e08Contract::BOOTSTRAP_ITERATIONS);
        $this->assertSame(20260812, Bt03e08Contract::BOOTSTRAP_SEED);
        $this->assertSame(0.025, Bt03e08Contract::BOOTSTRAP_CI_LOWER);
        $this->assertSame(0.975, Bt03e08Contract::BOOTSTRAP_CI_UPPER);
        $this->assertSame(7, Bt03e08Contract::plan()['bootstrap']['type']);
        $this->assertSame('YEAR_STRATIFIED_PAIRED_RACE_CLUSTER', Bt03e08Contract::plan()['bootstrap']['unit']);

        $spools = [2024 => $this->metricSpool(2024), 2025 => $this->metricSpool(2025)];
        try {
            $e05 = new Bt03e05PairedBootstrap(new Type7Quantile);
            $e06 = new Bt03e06PairedBootstrap($e05);
            $e07 = new Bt03e07PairedBootstrap($e05);
            $e08 = new Bt03e08PairedBootstrap($e05);
            $e05Input = array_map(static fn (Bt03e06MetricContributionSpool $spool) => $spool->e05Spool(), $spools);
            $frozen = $e05->evaluate($e05Input);

            $this->assertSame($frozen, $e06->evaluate($spools));
            $this->assertSame($frozen, $e07->evaluate($spools));
            $this->assertSame($frozen, $e08->evaluate($spools));
            $this->assertSame($e08->evaluate($spools), $e08->evaluate($spools));
        } finally {
            foreach ($spools as $spool) {
                $spool->cleanup();
            }
        }
    }

    /** @return iterable<string,array{list<int>}> */
    public static function rankCases(): iterable
    {
        yield 'normal' => [[1, 2, 3, 4, 5]];
        yield 'tie first' => [[1, 1, 3, 4, 5]];
        yield 'tie second' => [[1, 2, 2, 4, 5]];
        yield 'tie third' => [[1, 2, 3, 3, 5]];
    }

    /** @param array<int,array<string,mixed>> $outer @param array<string,array<string,float>> $intervals */
    private function assertGateSame(array $outer, array $intervals, string $gate, bool $expected): void
    {
        $e05 = new Bt03e05AcceptanceGate;
        $frozen = $e05->evaluate($outer, $intervals, true);
        $evaluations = [
            (new Bt03e06AcceptanceGate($e05))->evaluate($outer, $intervals, true),
            (new Bt03e07AcceptanceGate($e05))->evaluate($outer, $intervals, true),
            (new Bt03e08AcceptanceGate)->evaluate($outer, $intervals, true),
        ];

        $this->assertSame($expected, $frozen['gates'][$gate], $gate);
        foreach ($evaluations as $actual) {
            $this->assertSame($frozen, $actual);
        }
    }

    /** @return array{array<int,array<string,mixed>>,array<string,array{ci_lower:float,ci_upper:float}>} */
    private function passingGateInput(): array
    {
        $outer = [];
        foreach ([2024, 2025] as $year) {
            $outer[$year] = [
                'delta' => array_fill_keys(Bt03e05MetricEvaluator::METRIC_CODES, 0.01),
                'tie_diagnostics' => [
                    'primary_score_tied_races' => 0,
                    'baseline_exact_score_tied_races' => 1,
                    'technical_tiebreak_races' => 0,
                ],
                'race_count' => 1000,
            ];
        }

        return [$outer, array_fill_keys(Bt03e05MetricEvaluator::METRIC_CODES, ['ci_lower' => 0.001, 'ci_upper' => 0.02])];
    }

    /** @return array<string,mixed> */
    private function sourceRace(): array
    {
        $entries = [];
        foreach (range(1, 5) as $bike) {
            $p1 = $bike === 1 ? 0.6 : 0.1;
            $entries[] = [
                'bike' => $bike,
                'position_1_probability' => $p1,
                'position_2_probability' => 0.2,
                'position_3_probability' => 0.2,
                'top2_probability' => $p1 + 0.2,
                'top3_probability' => $p1 + 0.4,
            ];
        }

        return ['year' => 2024, 'race_id' => 10, 'entries' => $entries, 'map_ordered_top3' => [1, 2, 3], 'map_ordered_probability' => 0.1, 'map_top3_set' => [1, 2, 3], 'map_top3_set_probability' => 0.2];
    }

    /** @param list<int> $ranks @return array<string,mixed> */
    private function context(array $ranks): array
    {
        $entries = [];
        foreach ($ranks as $offset => $rank) {
            $entries[] = ['id' => $offset + 1, 'bike' => $offset + 1, 'raw' => 100.0 - $offset, 'stat01_rank' => $offset + 1, 'rank' => $rank, 'status' => count(array_keys($ranks, $rank, true)) > 1 ? 'TIED' : 'FINISHED'];
        }

        return ['year' => 2024, 'race_id' => 10, 'entries' => $entries];
    }

    private function metricSpool(int $year): Bt03e06MetricContributionSpool
    {
        $spool = new Bt03e06MetricContributionSpool(sys_get_temp_dir()."/bt03e08-bootstrap-{$year}-".bin2hex(random_bytes(8)).'.bin');
        foreach (range(1, 5) as $race) {
            $comparison = ['candidate' => [], 'baseline' => []];
            foreach (Bt03e05MetricEvaluator::METRIC_CODES as $offset => $metric) {
                $comparison['candidate'][$metric] = ['numerator' => (float) (($race + $offset + $year) % 3), 'denominator' => 3.0];
                $comparison['baseline'][$metric] = ['numerator' => (float) (($race + $offset) % 2), 'denominator' => 3.0];
            }
            $spool->append($comparison);
        }
        $spool->seal();

        return $spool;
    }
}
