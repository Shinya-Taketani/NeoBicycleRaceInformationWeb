<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e02AlphaSelector;
use App\Domain\Keirin\Backtest\Calculators\Bt03e02MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e02PairedBootstrap;
use App\Domain\Keirin\Backtest\Calculators\Bt03e02ParameterLayoutBuilder;
use App\Domain\Keirin\Backtest\Calculators\Bt03e02Scorer;
use App\Domain\Keirin\Backtest\Calculators\DeterministicRandom;
use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\Calculators\InMemoryEffectBinBoundaryProvider;
use App\Domain\Keirin\Backtest\Calculators\Type7Quantile;
use App\Domain\Keirin\Backtest\Services\Bt03e02Contract;
use PHPUnit\Framework\TestCase;

class Bt03e02ContractsTest extends TestCase
{
    public function test_missing_signal_is_not_the_observed_zero_category(): void
    {
        $builder = $this->binBuilder();
        $layout = $this->layoutBuilder($builder)->build(fn (): array => [$this->rawRace(2022, range(0, 4))]);
        $missing = array_fill(0, 12, null);
        $zero = array_fill(0, 12, null);
        $zero[0] = 0;

        $this->assertNull($layout->assign($missing, $builder)[0]);
        $this->assertIsInt($layout->assign($zero, $builder)[0]);
    }

    public function test_stat26_bins_are_rebuilt_from_each_training_fold_raw_values(): void
    {
        $builder = $this->binBuilder();
        $layoutBuilder = $this->layoutBuilder($builder);
        $first = $layoutBuilder->build(fn (): array => [$this->rawRace(2022, range(0, 19), 7)]);
        $second = $layoutBuilder->build(fn (): array => [$this->rawRace(2023, range(100, 119), 7)]);

        $firstBins = $first->canonicalBins()['STAT-26'];
        $secondBins = $second->canonicalBins()['STAT-26'];

        $this->assertNotSame($firstBins, $secondBins);
        $this->assertSame('NUMERIC_RANGE', $firstBins[0]['kind']);
        $this->assertSame('NUMERIC_RANGE', $secondBins[0]['kind']);
        $this->assertNotSame($firstBins[0]['upper_bound'], $secondBins[0]['upper_bound']);
    }

    public function test_full_precision_ranking_does_not_round_before_comparison(): void
    {
        $scorer = new Bt03e02Scorer;
        $entries = [
            $this->prediction(1, 1.0),
            $this->prediction(2, 1.0 + 1e-12),
            $this->prediction(3, 0.0),
        ];
        $alpha = ['IS_WIN' => 1.0, 'IS_TOP2' => 0.0, 'IS_TOP3' => 0.0, 'key' => '20-00-00'];

        $ranked = $scorer->rank(10, $entries, $alpha);

        $this->assertSame([2, 1, 3], array_column($ranked['entries'], 'bike'));
        $this->assertSame(0, $ranked['diagnostics']['exact_ranking_score_tied_race']);
    }

    public function test_technical_tie_is_audited_when_only_a_subgroup_reaches_the_fallback(): void
    {
        $scorer = new Bt03e02Scorer;
        $entries = [
            $this->prediction(1, 2.0),
            $this->prediction(2, 1.0),
            $this->prediction(3, 1.0),
        ];
        foreach ($entries as &$entry) {
            $entry['raw'] = 0.0;
        }
        unset($entry);
        $alpha = ['IS_WIN' => 0.0, 'IS_TOP2' => 1.0, 'IS_TOP3' => 0.0, 'key' => '00-20-00'];

        $ranked = $scorer->rank(11, $entries, $alpha);

        $this->assertSame(1, $ranked['diagnostics']['technical_tiebreak_race']);
        $this->assertSame(2, $ranked['diagnostics']['technical_tiebreak_entries']);
    }

    public function test_paired_year_stratified_bootstrap_is_deterministic(): void
    {
        $metrics = new Bt03e02MetricEvaluator(new Bt03e02Scorer);
        $bootstrap = new Bt03e02PairedBootstrap($metrics, new Type7Quantile);
        $alpha = ['IS_WIN' => 1.0, 'IS_TOP2' => 0.0, 'IS_TOP3' => 0.0, 'key' => '20-00-00'];
        $years = [
            2024 => ['source' => fn (): array => [$this->predictionRace(2024, 1)], 'race_count' => 1, 'alpha' => $alpha],
            2025 => ['source' => fn (): array => [$this->predictionRace(2025, 2)], 'race_count' => 1, 'alpha' => $alpha],
        ];

        $first = $bootstrap->evaluate($years, 20);
        $second = $bootstrap->evaluate($years, 20);

        $this->assertSame($first, $second);
        $this->assertSame(0.0, $first['WINNER_HIT_AT_1']['ci_lower']);
        $this->assertSame(0.0, $first['WINNER_HIT_AT_1']['ci_upper']);
    }

    public function test_compact_bootstrap_matches_the_naive_reference_ci(): void
    {
        $metrics = new Bt03e02MetricEvaluator(new Bt03e02Scorer);
        $alpha = ['IS_WIN' => 1.0, 'IS_TOP2' => 0.0, 'IS_TOP3' => 0.0, 'key' => '20-00-00'];
        $races = [$this->discordantRace(2024, 1, true), $this->discordantRace(2024, 2, false)];
        $years = [2024 => ['source' => fn (): array => $races, 'race_count' => 2, 'alpha' => $alpha]];

        $compact = (new Bt03e02PairedBootstrap($metrics, new Type7Quantile))->evaluate($years, 40);
        $naive = $this->naiveBootstrap($metrics, $years, 40);

        $this->assertSame($naive, $compact);
    }

    public function test_bootstrap_ranks_each_race_once_before_two_thousand_resamples(): void
    {
        $metrics = new class(new Bt03e02Scorer) extends Bt03e02MetricEvaluator
        {
            public int $comparisons = 0;

            public function raceComparison(array $race, array $alpha): array
            {
                $this->comparisons++;

                return parent::raceComparison($race, $alpha);
            }
        };
        $alpha = ['IS_WIN' => 1.0, 'IS_TOP2' => 0.0, 'IS_TOP3' => 0.0, 'key' => '20-00-00'];
        $races = array_map(fn (int $raceId): array => $this->predictionRace(2024, $raceId), range(1, 3));

        (new Bt03e02PairedBootstrap($metrics, new Type7Quantile))->evaluate([
            2024 => ['source' => fn (): array => $races, 'race_count' => 3, 'alpha' => $alpha],
        ], 2000);

        $this->assertSame(3, $metrics->comparisons);
    }

    public function test_outer_years_are_not_inputs_to_selection_contracts(): void
    {
        $plan = Bt03e02Contract::plan();

        $this->assertSame('inner 2022->2023; refit 2022-2023; outer 2024', $plan['outer_folds']['2024']);
        $this->assertSame('inner 2022->2023 + 2022-2023->2024; refit 2022-2024; outer 2025', $plan['outer_folds']['2025']);
        $this->assertSame('OPERATIONAL', $plan['cohort']);
    }

    public function test_alpha_selection_uses_year_equal_metrics_instead_of_race_weighting(): void
    {
        $metrics = new class(new Bt03e02Scorer) extends Bt03e02MetricEvaluator
        {
            public function evaluatePaired(callable $predictionSource, array $alpha): array
            {
                $rows = iterator_to_array((static function () use ($predictionSource): \Generator {
                    yield from $predictionSource();
                })());
                $year = (int) $rows[0]['year'];
                $deltaValue = $year === 2023 ? 1.0 - $alpha['IS_WIN'] : 0.2 * $alpha['IS_WIN'];
                $zero = array_fill_keys(self::METRIC_CODES, 0.0);
                $delta = array_fill_keys(self::METRIC_CODES, $deltaValue);

                return [
                    'candidate' => $delta,
                    'baseline' => $zero,
                    'delta' => $delta,
                    'race_count' => count($rows),
                ];
            }
        };
        $selection = (new Bt03e02AlphaSelector($metrics))->select([
            2023 => fn (): array => [['year' => 2023]],
            2024 => fn (): array => array_fill(0, 20, ['year' => 2024]),
        ]);

        $this->assertEquals(0.0, $selection['alpha']['IS_WIN']);
        $this->assertSame('00-00-20', $selection['alpha']['key']);
        $this->assertEqualsWithDelta(0.5, $selection['year_equal_deltas']['WINNER_HIT_AT_1'], 1e-15);
        $this->assertSame(1, $selection['per_year_metrics'][2023]['race_count']);
        $this->assertSame(20, $selection['per_year_metrics'][2024]['race_count']);

        $raceWeightedSelected = 1.0 / 21.0;
        $raceWeightedRejected = 4.0 / 21.0;
        $this->assertGreaterThan($raceWeightedSelected, $raceWeightedRejected);
    }

    private function binBuilder(): EffectBinBuilder
    {
        return new EffectBinBuilder(new InMemoryEffectBinBoundaryProvider(new Type7Quantile));
    }

    private function layoutBuilder(EffectBinBuilder $builder): Bt03e02ParameterLayoutBuilder
    {
        return new Bt03e02ParameterLayoutBuilder($builder);
    }

    /** @param list<int> $values @return array<string, mixed> */
    private function rawRace(int $year, array $values, int $targetOffset = 0): array
    {
        $entries = [];
        foreach ($values as $offset => $value) {
            $signals = array_fill(0, 12, 0);
            $signals[$targetOffset] = $value;
            $entries[] = ['signals' => $signals];
        }

        return ['year' => $year, 'race_id' => $year, 'entries' => $entries];
    }

    /** @return array<string, mixed> */
    private function prediction(int $bike, float $score): array
    {
        return [
            'id' => $bike,
            'bike' => $bike,
            'raw' => $score,
            'stat01_rank' => $bike,
            'normalized' => ['IS_WIN' => $score, 'IS_TOP2' => 0.0, 'IS_TOP3' => 0.0],
            'rank' => $bike,
            'status' => 'FINISHED',
        ];
    }

    /** @return array<string, mixed> */
    private function predictionRace(int $year, int $raceId): array
    {
        return [
            'year' => $year,
            'race_id' => $raceId,
            'entries' => array_map(fn (int $bike): array => $this->prediction($bike, 6.0 - $bike), range(1, 5)),
        ];
    }

    /** @return array<string,mixed> */
    private function discordantRace(int $year, int $raceId, bool $candidateCorrect): array
    {
        $entries = [];
        foreach (range(1, 5) as $bike) {
            $candidateScore = $candidateCorrect ? 6.0 - $bike : (float) $bike;
            $raw = $candidateCorrect ? (float) $bike : 6.0 - $bike;
            $entries[] = [
                ...$this->prediction($bike, $candidateScore),
                'raw' => $raw,
            ];
        }

        return ['year' => $year, 'race_id' => $raceId, 'entries' => $entries];
    }

    /**
     * @param  array<int,array{source:callable():iterable<array<string,mixed>>,race_count:int,alpha:array<string,mixed>}>  $years
     * @return array<string,array{ci_lower:float,ci_upper:float}>
     */
    private function naiveBootstrap(Bt03e02MetricEvaluator $metrics, array $years, int $iterations): array
    {
        $samples = array_fill_keys(Bt03e02MetricEvaluator::METRIC_CODES, []);
        $random = new DeterministicRandom(Bt03e02Contract::BOOTSTRAP_SEED);
        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            $yearDeltas = array_fill_keys(Bt03e02MetricEvaluator::METRIC_CODES, []);
            foreach ($years as $year) {
                $weights = array_fill(0, $year['race_count'], 0);
                for ($draw = 0; $draw < $year['race_count']; $draw++) {
                    $weights[$random->integer($year['race_count'])]++;
                }
                $candidate = $baseline = $denominators = array_fill_keys(Bt03e02MetricEvaluator::METRIC_CODES, 0.0);
                foreach (($year['source'])() as $raceIndex => $race) {
                    $comparison = $metrics->raceComparison($race, $year['alpha']);
                    foreach (Bt03e02MetricEvaluator::METRIC_CODES as $metric) {
                        $candidate[$metric] += $weights[$raceIndex] * $comparison['candidate'][$metric]['numerator'];
                        $baseline[$metric] += $weights[$raceIndex] * $comparison['baseline'][$metric]['numerator'];
                        $denominators[$metric] += $weights[$raceIndex] * $comparison['candidate'][$metric]['denominator'];
                    }
                }
                foreach (Bt03e02MetricEvaluator::METRIC_CODES as $metric) {
                    $yearDeltas[$metric][] = $denominators[$metric] > 0.0
                        ? ($candidate[$metric] - $baseline[$metric]) / $denominators[$metric]
                        : 0.0;
                }
            }
            foreach ($samples as $metric => $_) {
                $samples[$metric][] = array_sum($yearDeltas[$metric]) / count($yearDeltas[$metric]);
            }
        }
        $quantile = new Type7Quantile;

        return array_map(static fn (array $values): array => [
            'ci_lower' => $quantile->calculate($values, 0.025),
            'ci_upper' => $quantile->calculate($values, 0.975),
        ], $samples);
    }
}
