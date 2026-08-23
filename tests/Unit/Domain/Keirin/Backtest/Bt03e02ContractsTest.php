<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e02MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e02PairedBootstrap;
use App\Domain\Keirin\Backtest\Calculators\Bt03e02ParameterLayoutBuilder;
use App\Domain\Keirin\Backtest\Calculators\Bt03e02Scorer;
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

    public function test_outer_years_are_not_inputs_to_selection_contracts(): void
    {
        $plan = Bt03e02Contract::plan();

        $this->assertSame('inner 2022->2023; refit 2022-2023; outer 2024', $plan['outer_folds']['2024']);
        $this->assertSame('inner 2022->2023 + 2022-2023->2024; refit 2022-2024; outer 2025', $plan['outer_folds']['2025']);
        $this->assertSame('OPERATIONAL', $plan['cohort']);
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
}
