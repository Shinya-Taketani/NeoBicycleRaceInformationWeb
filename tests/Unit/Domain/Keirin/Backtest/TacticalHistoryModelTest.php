<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e02ParameterLayout;
use App\Domain\Keirin\Backtest\Calculators\Bt03e03ConditionalSoftmaxObjective;
use App\Domain\Keirin\Backtest\Calculators\Bt03e03FistaOptimizer;
use App\Domain\Keirin\Backtest\Calculators\Bt03e03OneSeSelector;
use App\Domain\Keirin\Backtest\Calculators\Bt03e03ProbabilityScorer;
use App\Domain\Keirin\Backtest\Calculators\Bt03e06WinnerConditionedDecoder;
use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\Calculators\ExternalSortEffectBinBoundaryProvider;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\HistoryAggregator;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Layout;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\LayoutBuilder;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Objective;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Optimizer;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Predictor;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use App\Domain\Keirin\Backtest\Support\Bt03e03ValidationLossSpool;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TacticalHistoryModelTest extends TestCase
{
    public function test_c0_layout_loss_gradient_penalty_fit_and_prediction_match_frozen_e03_exactly(): void
    {
        $builder = $this->bins();
        $raw = $this->races(false);
        $layout = (new LayoutBuilder($builder))->build(fn () => $raw);
        $bins = [];
        foreach (Bt03e03Contract::STAT_CODES as $offset => $code) {
            $bins[$code] = $builder->build(array_merge(...array_map(fn ($r) => array_column(array_column($r['entries'], 'signals'), $offset), $raw)));
        }
        $frozen = new Bt03e02ParameterLayout($bins);
        $this->assertSame($frozen->canonicalBins(), $layout->canonicalBins());
        $this->assertSame($frozen->groups(), $layout->groups());
        $this->assertSame($frozen->smoothEdges(), $layout->smoothEdges());
        $this->assertSame($frozen->supportWeights(), $layout->supportWeights());
        $races = $this->binned($raw, $layout);
        $source = fn () => $races;
        $coefficients = $layout->project(array_fill(0, $layout->size(), 0.1));
        foreach (Bt03e03Contract::POSITIONS as $position) {
            $this->assertSame((new Bt03e03ConditionalSoftmaxObjective)->lossAndGradient($source, $frozen, $coefficients, $position), (new Objective)->lossAndGradient($source, $layout, $coefficients, $position));
        }
        $old = (new Bt03e03FistaOptimizer(new Bt03e03ConditionalSoftmaxObjective))->fit($source, $frozen, 1.0);
        $new = (new Optimizer(new Objective))->fit($source, $layout, 1.0);
        $this->assertSame((array) $old, (array) $new);
        $scorer = new Bt03e03ProbabilityScorer;
        $this->assertSame($scorer->predict($races[0], $old), $scorer->predict($races[0], $new));
    }

    public function test_four_count_inputs_reach_all_position_gradients_coefficients_and_predictions(): void
    {
        $raw = $this->races(true);
        $layout = (new LayoutBuilder($this->bins()))->build(fn () => $raw, true);
        $races = $this->binned($raw, $layout);
        $this->assertSame(16, $layout->featureCount());
        $zero = array_fill(0, $layout->size(), 0.0);
        foreach (Bt03e03Contract::POSITIONS as $position) {
            $gradient = (new Objective)->lossAndGradient(fn () => $races, $layout, $zero, $position)['gradient'];
            foreach (HistoryAggregator::FEATURES as $code) {
                $this->assertNotSame([0.0, 0.0], array_map(fn ($i) => $gradient[$i], $layout->groups()[$code]), $position.' gradient '.$code);
            }
        }
        $fit = (new Optimizer(new Objective))->fit(fn () => $races, $layout, 1.0);
        foreach ($fit->coefficients as $coefficients) {
            foreach (HistoryAggregator::FEATURES as $code) {
                $this->assertNotSame([0.0, 0.0], array_map(fn ($i) => $coefficients[$i], $layout->groups()[$code]));
            }
        }
        $race = $races[0];
        foreach ($race['entries'] as &$entry) {
            unset($entry['rank'], $entry['status']);
        }
        unset($entry);
        $predictor = new Predictor(new Bt03e03ProbabilityScorer, new Bt03e06WinnerConditionedDecoder(new Bt03e03ProbabilityScorer, new CanonicalHasher));
        $prediction = $predictor->predict($race, $fit);
        $this->assertSame($prediction, $predictor->predict($race, $fit));
        foreach ($prediction['probabilities']['entries'] as $entry) {
            $this->assertArrayNotHasKey('rank', $entry);
            $this->assertArrayNotHasKey('status', $entry);
        }
        $this->expectException(RuntimeException::class);
        $predictor->predict($races[0], $fit);
    }

    private function bins(): EffectBinBuilder
    {
        return new EffectBinBuilder(new ExternalSortEffectBinBoundaryProvider);
    }

    public function test_future_and_same_meeting_changes_preserve_bins_fits_selection_and_predictions(): void
    {
        $original = $this->races(true);
        $changed = $this->races(true, true);
        $this->assertSame($original, $changed);
        $layout = (new LayoutBuilder($this->bins()))->build(fn () => $original, true);
        $other = (new LayoutBuilder($this->bins()))->build(fn () => $changed, true);
        $this->assertSame($layout->canonicalBins(), $other->canonicalBins());
        $binned = $this->binned($original, $layout);
        $otherBinned = $this->binned($changed, $other);
        $audit = [];
        $path = (new Optimizer(new Objective))->withAudit(function ($candidate) use (&$audit): void {
            $audit[] = $candidate;
        })->fitPath(fn () => $binned, $layout);
        $repeat = (new Optimizer(new Objective))->fitPath(fn () => $otherBinned, $other);
        $this->assertSame($path['candidate_statuses'], $repeat['candidate_statuses']);
        $this->assertCount(8, $audit);
        $losses = [];
        try {
            foreach ([$path, $repeat] as $index => $run) {
                $spool = new Bt03e03ValidationLossSpool(sys_get_temp_dir().'/history-selection-'.bin2hex(random_bytes(8)).'.bin', array_keys($run['fits']));
                $losses[] = $spool;
                foreach ($binned as $race) {
                    $values = [];
                    foreach ($run['fits'] as $key => $fit) {
                        foreach (Bt03e03Contract::POSITIONS as $position) {
                            $values[$key][$position] = (new Objective)->raceLoss($race, $layout, $fit->coefficients[$position], $position);
                        }
                    }
                    $spool->append($values);
                }
                $spool->seal();
            }
            $first = (new Bt03e03OneSeSelector)->select([2023 => $losses[0]]);
            $second = (new Bt03e03OneSeSelector)->select([2023 => $losses[1]]);
            $this->assertSame($first, $second);
            $key = sprintf('%.17g', $first['lambda']);
            $this->assertSame((array) $path['fits'][$key], (array) $repeat['fits'][$key]);
            $this->assertSame((new Bt03e03ProbabilityScorer)->predict($binned[0], $path['fits'][$key]),
                (new Bt03e03ProbabilityScorer)->predict($otherBinned[0], $repeat['fits'][$key]));
        } finally {
            foreach ($losses as $spool) {
                $spool->cleanup();
            }
        }
    }

    private function races(bool $history, bool $appendExcluded = false): array
    {
        $races = [];
        foreach (range(1, 12) as $id) {
            $entries = [];
            foreach (range(1, 5) as $bike) {
                $signals = array_fill(0, 12, 0);
                $signals[0] = $bike === 1 ? 0 : 1;
                if ($history) {
                    $target = ['race_id' => $id, 'meeting_id' => 100, 'player_id' => $bike, 'race_date' => '2023-06-10',
                        'meeting_start' => '2023-06-10', 'meeting_end' => '2023-06-12', 'input_as_of' => '2023-06-10T12:00:00+09:00'];
                    $historyRows = [];
                    foreach (["\u{9003}\u{3052}", "\u{6372}\u{308a}", "\u{5dee}\u{3057}", "\u{30de}\u{30fc}\u{30af}"] as $offset => $method) {
                        $historyRows[] = ['race_id' => 1000 + $offset, 'meeting_id' => 99, 'player_id' => $bike, 'entry_id' => 1000 + $bike,
                            'bike' => $bike, 'male_category' => true, 'race_date' => '2023-06-01', 'scheduled_start_at' => '2023-06-01T10:00:00+09:00',
                            'result_player_id' => null, 'result_entry_id' => null, 'race_status' => 'CONFIRMED', 'result_id' => 1000 + $offset,
                            'result_status' => 'FINISHED', 'rank' => in_array($bike, [1, 3], true) ? 3 : 2, 'winning_technique' => $method];
                    }
                    if ($appendExcluded) {
                        $base = $historyRows[0];
                        $historyRows[] = array_replace($base, ['race_id' => 2000, 'meeting_id' => 100, 'winning_technique' => 'unusable']);
                        $historyRows[] = array_replace($base, ['race_id' => $id, 'winning_technique' => 'changed']);
                        $historyRows[] = array_replace($base, ['race_id' => 3000, 'race_date' => '2023-09-01', 'scheduled_start_at' => '2023-09-01T12:00:00+09:00', 'rank' => 1]);
                        $historyRows[] = array_replace($base, ['race_id' => 4000, 'race_date' => '2026-01-01', 'scheduled_start_at' => '2026-01-01T12:00:00+09:00']);
                    }
                    $counts = (new HistoryAggregator)->aggregate($target, $historyRows);
                    $this->assertSame('AVAILABLE', $counts['status']);
                    $signals = [...$signals, ...$counts['values']];
                }
                $entries[] = ['id' => $id * 10 + $bike, 'bike' => $bike, 'raw' => 100.0 - $bike, 'stat01_rank' => $bike,
                    'anchor' => 0.0, 'signals' => $signals, 'rank' => $bike, 'status' => 'FINISHED'];
            }
            $races[] = ['year' => 2023, 'race_id' => $id, 'entries' => $entries];
        }

        return $races;
    }

    private function binned(array $races, Layout $layout): array
    {
        foreach ($races as &$race) {
            foreach ($race['entries'] as &$entry) {
                $entry['bins'] = $layout->assign($entry['signals'], $this->bins());
                unset($entry['signals']);
            }
            unset($entry);
        }
        unset($race);

        return $races;
    }
}
