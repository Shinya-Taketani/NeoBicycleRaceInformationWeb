<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt02LabelDefinition;
use App\Domain\Keirin\Backtest\Calculators\Bt03e02AcceptanceGate;
use App\Domain\Keirin\Backtest\Calculators\Bt03e02CompensatedSum;
use App\Domain\Keirin\Backtest\Calculators\Bt03e02MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e02OneSeSelector;
use App\Domain\Keirin\Backtest\Calculators\Bt03e02PairwiseObjective;
use App\Domain\Keirin\Backtest\Calculators\Bt03e02ParameterLayout;
use App\Domain\Keirin\Backtest\Calculators\Bt03e02Scorer;
use App\Domain\Keirin\Backtest\DTO\Bt03e02FitResultDto;
use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use App\Domain\Keirin\Backtest\Services\Bt03e02Contract;
use App\Domain\Keirin\Backtest\Services\Bt03e02ReadOnlyQueryAudit;
use App\Domain\Keirin\Backtest\Support\Bt03e02RaceSpool;
use App\Domain\Keirin\Backtest\Support\Bt03e02ValidationLossSpool;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class Bt03e02CoreTest extends TestCase
{
    public function test_frozen_contract_has_exact_stats_lambda_alpha_and_development_boundary(): void
    {
        $this->assertCount(12, Bt03e02Contract::STAT_CODES);
        $this->assertSame('OPERATIONAL', Bt03e02Contract::COHORT);
        $this->assertSame([0.0, 1e-6, 1e-5, 1e-4, 1e-3, 1e-2, 1e-1, 1.0], Bt03e02Contract::LAMBDA_GRID);
        $this->assertCount(231, Bt03e02Contract::alphaCandidates());
        $this->assertSame([2022, 2023, 2024, 2025], Bt03e02Contract::DEVELOPMENT_YEARS);
        $this->assertStringNotContainsString('2026', implode(',', Bt03e02Contract::DEVELOPMENT_YEARS));
    }

    public function test_bt02_label_semantics_are_reused_for_pair_generation(): void
    {
        $labels = new Bt02LabelDefinition;
        $winner = $labels->labels('FINISHED', 1);
        $fourth = $labels->labels('FINISHED', 4);

        $this->assertTrue($winner->isWin);
        $this->assertTrue($winner->isTop2);
        $this->assertTrue($winner->isTop3);
        $this->assertFalse($fourth->isWin);
        $this->assertFalse($fourth->isTop2);
        $this->assertFalse($fourth->isTop3);
    }

    public function test_pairwise_loss_is_overflow_safe_and_race_balanced(): void
    {
        $layout = $this->layout();
        $objective = new Bt03e02PairwiseObjective;
        $coefficients = array_fill(0, $layout->size(), 0.0);
        $source = fn (): array => [
            $this->binnedRace(1, [1000.0, -1000.0], [1, 0]),
            $this->binnedRace(2, [0.0, 0.0, 0.0, 0.0], [1, 0, 0, 0]),
        ];

        $result = $objective->lossAndGradient($source, $layout, $coefficients, 'IS_WIN');

        $this->assertTrue(is_finite($result['loss']));
        $this->assertEqualsWithDelta(log(2) / 2, $result['loss'], 1e-12);
        $this->assertSame(2, $result['eligible_races']);
    }

    public function test_composite_penalty_has_l2_group_and_numeric_smoothness_but_no_category_edges(): void
    {
        $objective = new Bt03e02PairwiseObjective;
        $numeric = $this->layout('NUMERIC_RANGE');
        $category = $this->layout('CATEGORY');
        $coefficients = array_fill(0, $numeric->size(), 0.0);
        $coefficients[0] = -1.0;
        $coefficients[1] = 1.0;

        $this->assertGreaterThan(0.0, $objective->smoothPenalty($numeric, $coefficients, 1.0));
        $this->assertGreaterThan(0.0, $objective->groupPenalty($numeric, $coefficients, 1.0));
        $this->assertLessThan($objective->smoothPenalty($numeric, $coefficients, 1.0), $objective->smoothPenalty($category, $coefficients, 1.0));
    }

    public function test_support_weighted_centering_is_machine_precision_zero(): void
    {
        $layout = $this->layout();
        $projected = $layout->project(array_map(static fn (int $index): float => $index / 7, range(0, $layout->size() - 1)));

        foreach ($layout->weightedMeans($projected) as $mean) {
            $this->assertEqualsWithDelta(0.0, $mean, 2e-15);
        }
    }

    public function test_race_centered_rms_and_degenerate_channel_contract(): void
    {
        $scorer = new Bt03e02Scorer;
        $fit = $this->zeroFit($this->layout()->size());
        $available = $scorer->trainingScales(fn (): array => [$this->binnedRace(1, [-1.0, 0.0, 1.0], [1, 0, 0])], $fit);
        $degenerate = $scorer->trainingScales(fn (): array => [$this->binnedRace(2, [0.0, 0.0, 0.0], [1, 0, 0])], $fit);

        foreach (Bt03e02Contract::CHANNELS as $channel) {
            $this->assertEqualsWithDelta(sqrt(2 / 3), $available[$channel]['scale'], 1e-12);
            $this->assertSame('DEGENERATE_CHANNEL', $degenerate[$channel]['status']);
            $this->assertNull($degenerate[$channel]['scale']);
        }
    }

    public function test_zero_beta_nests_stat01_and_full_precision_technical_tie_is_deterministic(): void
    {
        $layout = $this->layout();
        $scorer = new Bt03e02Scorer;
        $fit = $this->zeroFit($layout->size());
        $race = $this->binnedRace(77, [2.0, 1.0, 0.0, -1.0, -2.0], [1, 0, 0, 0, 0]);
        $scales = $scorer->trainingScales(fn (): array => [$race], $fit);
        $predictions = $scorer->predictions($race, $fit, $scales);
        $alpha = ['IS_WIN' => 0.35, 'IS_TOP2' => 0.35, 'IS_TOP3' => 0.30, 'key' => '07-07-06'];
        $first = $scorer->rank(77, $predictions, $alpha);
        $second = $scorer->rank(77, $predictions, $alpha);

        $this->assertSame([1, 2, 3, 4, 5], array_column($first['entries'], 'bike'));
        $this->assertSame(array_column($first['entries'], 'technical_key'), array_column($second['entries'], 'technical_key'));

        $tieRace = $this->binnedRace(78, [0.0, 0.0, 0.0], [1, 0, 0]);
        $tiePredictions = array_map(static fn (array $entry): array => [...$entry, 'normalized' => ['IS_WIN' => 0.0, 'IS_TOP2' => 0.0, 'IS_TOP3' => 0.0]], $tieRace['entries']);
        $tied = $scorer->rank(78, $tiePredictions, $alpha);
        $this->assertSame(1, $tied['diagnostics']['technical_tiebreak_race']);
    }

    public function test_neumaier_sum_preserves_small_residual(): void
    {
        $sum = new Bt03e02CompensatedSum;
        foreach ([1e16, 1.0, -1e16] as $value) {
            $sum->add($value);
        }

        $this->assertSame(1.0, $sum->value());
    }

    public function test_one_se_uses_largest_lambda_inside_threshold(): void
    {
        $spool = new Bt03e02ValidationLossSpool(sys_get_temp_dir().'/bt03e02-one-se-'.bin2hex(random_bytes(8)).'.bin');
        try {
            for ($race = 0; $race < 2; $race++) {
                $losses = [];
                foreach (Bt03e02Contract::LAMBDA_GRID as $lambda) {
                    $key = sprintf('%.17g', $lambda);
                    foreach (Bt03e02Contract::CHANNELS as $channel) {
                        $losses[$key][$channel] = 0.5;
                    }
                }
                $spool->append($losses);
            }
            $spool->seal();

            $selected = (new Bt03e02OneSeSelector)->select([2023 => $spool], 20);

            $this->assertSame(1.0, $selected['lambda']);
            $this->assertSame(0.0, $selected['lambda_best']);
        } finally {
            $spool->cleanup();
        }
    }

    public function test_zero_beta_candidate_and_paired_baseline_share_the_technical_tie_break(): void
    {
        $layout = $this->layout();
        $scorer = new Bt03e02Scorer;
        $metrics = new Bt03e02MetricEvaluator($scorer);
        $fit = $this->zeroFit($layout->size());
        $race = $this->binnedRace(79, [1.0, 1.0, 0.0, -1.0, -1.0], [1, 0, 0, 0, 0]);
        $scales = $scorer->trainingScales(fn (): array => [$race], $fit);
        $predictions = $scorer->predictions($race, $fit, $scales);
        $alpha = ['IS_WIN' => 0.35, 'IS_TOP2' => 0.35, 'IS_TOP3' => 0.30, 'key' => '07-07-06'];

        $candidate = $scorer->rank(79, $predictions, $alpha)['entries'];
        $baseline = $metrics->rankBaseline(79, $predictions);

        $this->assertSame(array_column($baseline, 'bike'), array_column($candidate, 'bike'));
        $this->assertSame(array_column($baseline, 'technical_key'), array_column($candidate, 'technical_key'));
    }

    public function test_metric_denominators_and_acceptance_status_are_explicit(): void
    {
        $scorer = new Bt03e02Scorer;
        $metrics = new Bt03e02MetricEvaluator($scorer);
        $race = $this->predictionRace([1, 2, 3, 4, 5]);
        $alpha = ['IS_WIN' => 1.0, 'IS_TOP2' => 0.0, 'IS_TOP3' => 0.0, 'key' => '20-00-00'];
        $summary = $metrics->evaluatePaired(fn (): array => [$race], $alpha);

        $this->assertSame(1.0, $summary['candidate']['WINNER_HIT_AT_1']);
        $this->assertSame(3.0, $summary['denominators']['POSITION_HIT_RATE_AT_3']);

        $intervals = array_fill_keys(Bt03e02MetricEvaluator::METRIC_CODES, ['ci_lower' => 0.001, 'ci_upper' => 0.01]);
        $summary['delta'] = array_fill_keys(Bt03e02MetricEvaluator::METRIC_CODES, 0.001);
        $summary['tie_diagnostics']['baseline_exact_score_tied_races'] = 0;
        $gate = (new Bt03e02AcceptanceGate)->evaluate([2024 => $summary, 2025 => $summary], $intervals, true);
        $this->assertSame('PASS / GO_TO_FREEZE', $gate['status']);
    }

    public function test_2026_access_is_rejected_before_a_query_or_snapshot_read(): void
    {
        $audit = new Bt03e02ReadOnlyQueryAudit;
        $audit->start();
        try {
            $audit->recordSnapshotYear(2026);
            $this->fail('2026 must remain closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('forbidden', $exception->getMessage());
        } finally {
            $audit->finish();
        }
    }

    public function test_outer_partition_access_requires_the_corresponding_candidate_freeze(): void
    {
        $audit = new Bt03e02ReadOnlyQueryAudit;
        $audit->start();
        $audit->recordSnapshotYear(2022);
        $audit->recordSnapshotYear(2023);

        try {
            $audit->recordSnapshotYear(2024);
            $this->fail('Outer 2024 must not be read before its candidate is frozen.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('preceded candidate freeze', $exception->getMessage());
        }

        $audit->recordCandidateFrozen(2024);
        $audit->recordSnapshotYear(2024);
        try {
            $audit->recordSnapshotYear(2025);
            $this->fail('Outer 2025 must not be read before its candidate is frozen.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('preceded candidate freeze', $exception->getMessage());
        }

        $audit->recordCandidateFrozen(2025);
        $audit->recordSnapshotYear(2025);
        $result = $audit->finish();

        $this->assertSame([
            'SNAPSHOT_2022',
            'SNAPSHOT_2023',
            'FREEZE_OUTER_2024',
            'SNAPSHOT_2024',
            'FREEZE_OUTER_2025',
            'SNAPSHOT_2025',
        ], $result['temporal_access_order']);
    }

    public function test_integer_2026_query_binding_is_not_misclassified_as_year_access(): void
    {
        $audit = new Bt03e02ReadOnlyQueryAudit;
        $audit->start();
        DB::select('SELECT ? AS race_id', [2026]);
        $result = $audit->finish();

        $this->assertSame(0, $result['2026_query_or_binding_count']);
    }

    public function test_both_semantic_2026_access_entry_points_remain_forbidden(): void
    {
        foreach (['recordSnapshotYear', 'recordFeatureSourceYear'] as $method) {
            $audit = new Bt03e02ReadOnlyQueryAudit;
            $audit->start();
            try {
                $audit->{$method}(2026);
                $this->fail("{$method} must reject 2026.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('forbidden', $exception->getMessage());
            } finally {
                $audit->finish();
            }
        }
    }

    public function test_jsonl_spool_replays_more_than_a_chunk_without_retaining_rows(): void
    {
        $path = sys_get_temp_dir().'/bt03e02-bounded-'.bin2hex(random_bytes(8)).'.jsonl';
        $spool = new Bt03e02RaceSpool('BINNED', $path);
        try {
            for ($race = 1; $race <= 250; $race++) {
                $spool->append($this->binnedRace($race, [1.0, 0.0, -1.0], [1, 0, 0]));
            }
            $spool->seal();
            $count = 0;
            foreach ($spool->races() as $_) {
                $count++;
            }
            $this->assertSame(250, $count);
            $this->assertSame(750, $spool->metadata()['entry_count']);
        } finally {
            $spool->cleanup();
        }
    }

    private function layout(string $kind = 'CATEGORY'): Bt03e02ParameterLayout
    {
        $bins = [];
        foreach (Bt03e02Contract::STAT_CODES as $statCode) {
            $bins[$statCode] = [
                new EffectBinDto(1, $kind, $kind === 'NUMERIC_RANGE' ? null : null, $kind === 'NUMERIC_RANGE' ? 0.0 : null, $kind === 'CATEGORY' ? '0' : null, 1),
                new EffectBinDto(2, $kind, $kind === 'NUMERIC_RANGE' ? 0.0 : null, null, $kind === 'CATEGORY' ? '1' : null, 1),
            ];
        }

        return new Bt03e02ParameterLayout($bins);
    }

    private function zeroFit(int $size): Bt03e02FitResultDto
    {
        $coefficients = array_fill_keys(Bt03e02Contract::CHANNELS, array_fill(0, $size, 0.0));

        return new Bt03e02FitResultDto(0.0, $coefficients, array_fill_keys(Bt03e02Contract::CHANNELS, 0.0), array_fill_keys(Bt03e02Contract::CHANNELS, 0), array_fill_keys(Bt03e02Contract::CHANNELS, 1), array_fill_keys(Bt03e02Contract::CHANNELS, 0));
    }

    /** @param list<float> $anchors @param list<int> $winLabels @return array<string,mixed> */
    private function binnedRace(int $raceId, array $anchors, array $winLabels): array
    {
        $entries = [];
        foreach ($anchors as $offset => $anchor) {
            $rank = $offset + 1;
            $entries[] = [
                'id' => $raceId * 10 + $offset,
                'bike' => $offset + 1,
                'raw' => 100.0 + $anchor,
                'stat01_rank' => $rank,
                'anchor' => $anchor,
                'bins' => array_fill(0, 12, null),
                'labels' => [(bool) $winLabels[$offset], $rank <= 2, $rank <= 3],
                'rank' => $rank,
                'status' => 'FINISHED',
            ];
        }

        return ['year' => 2023, 'race_id' => $raceId, 'entries' => $entries];
    }

    /** @param list<int> $ranks @return array<string,mixed> */
    private function predictionRace(array $ranks): array
    {
        $entries = [];
        foreach ($ranks as $offset => $rank) {
            $score = 5.0 - $offset;
            $entries[] = [
                'id' => $offset + 1,
                'bike' => $offset + 1,
                'raw' => 100.0 - $offset,
                'stat01_rank' => $offset + 1,
                'normalized' => ['IS_WIN' => $score, 'IS_TOP2' => $score, 'IS_TOP3' => $score],
                'rank' => $rank,
                'status' => 'FINISHED',
            ];
        }

        return ['year' => 2024, 'race_id' => 1, 'entries' => $entries];
    }
}
