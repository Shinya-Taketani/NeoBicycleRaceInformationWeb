<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\Stat35Trend6P1;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Evaluation as FrozenGate;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use RuntimeException;

final class Evaluation
{
    public function __construct(private readonly Bt03e05MetricEvaluator $metrics, private readonly PairedBootstrap $bootstrap,
        private readonly FrozenGate $gate) {}

    public static function primary(array $decision): array
    {
        $primary = array_map(fn ($p) => $decision['primary_position_'.$p.'_bike'], [1, 2, 3]);
        $decision['map_ordered_top3'] = $decision['map_top3_set'] = $decision['top3_marginal_bikes'] = $decision['expected_ndcg_top3'] = $primary;
        $decision['top2_marginal_bikes'] = array_slice($primary, 0, 2);

        return $decision;
    }

    public function evaluate(array $years, string $directory): array
    {
        $outer = $spools = $changes = [];
        foreach ($years as $year => $paths) {
            $summary = $streams = [];
            foreach (['C-B', 'C-A', 'B-A'] as $pair) {
                foreach ([$pair, 'PRIMARY-'.$pair] as $name) {
                    $summary[$name] = $this->metrics->emptySummary();
                    $spools[$name][$year] = new MetricContributionSpool($directory.'/'.$name.'-'.$year.'.bin');
                }
            }
            foreach (['A', 'B', 'C'] as $model) {
                $streams[$model] = JsonlArtifact::read($paths[$model]);
                $streams[$model]->rewind();
            }
            JsonlArtifact::write($directory.'/contributions-'.$year.'.jsonl', (function () use ($year, $paths, $streams, &$summary, &$changes, $spools) {
                foreach (JsonlArtifact::read($paths['labels']) as $race) {
                    $values = $primary = $decisions = [];
                    foreach ($streams as $key => $stream) {
                        if (! $stream->valid()) {
                            throw new RuntimeException('Evaluation prediction missing.');
                        }
                        $row = $stream->current();
                        if ($key === 'A') {
                            Dataset::predictionCohort($race, $row);
                        } else {
                            Files::same([$race['year'], $race['race_id'], array_map(fn ($e) => [$e['id'], $e['bike']], $race['entries'])],
                                [$row['year'], $row['race_id'], $row['cohort']], 'composition evaluation cohort');
                        }
                        $decisions[$key] = $key === 'A' ? $row['decision'] : $row['candidate'];
                        $values[$key] = $this->metrics->raceComparison($race, $decisions[$key]);
                        $primary[$key] = $this->metrics->raceComparison($race, self::primary($decisions[$key]));
                    }
                    $rows = [];
                    foreach (['C-B', 'C-A', 'B-A'] as $pair) {
                        [$candidate, $baseline] = explode('-', $pair);
                        foreach ([$pair => $values, 'PRIMARY-'.$pair => $primary] as $name => $data) {
                            $comparison = $data[$candidate];
                            $comparison['baseline'] = $data[$baseline]['candidate'];
                            foreach (Bt03e05MetricEvaluator::METRIC_CODES as $code) {
                                if ($comparison['candidate'][$code]['denominator'] !== $comparison['baseline'][$code]['denominator']) {
                                    throw new RuntimeException('Paired denominator changed.');
                                }
                            }
                            $this->metrics->add($summary[$name], $comparison);
                            $spools[$name][$year]->append($comparison);
                            $rows[$name] = $comparison;
                        }
                        $change = &$changes[$pair][$year];
                        $change['races'] = ($change['races'] ?? 0) + 1;
                        $changed = false;
                        foreach ([1, 2, 3] as $p) {
                            $a = $values[$baseline]['candidate']['POSITION_'.$p.'_ACCURACY'];
                            $b = $values[$candidate]['candidate']['POSITION_'.$p.'_ACCURACY'];
                            $hit = $a['denominator'] === 0.0 ? 'excluded' : ($a['numerator'] > 0.0
                                ? ($b['numerator'] > 0.0 ? 'both_hit' : 'baseline_only') : ($b['numerator'] > 0.0 ? 'candidate_only' : 'both_miss'));
                            $change['P'.$p][$hit] = ($change['P'.$p][$hit] ?? 0) + 1;
                            $diff = $decisions[$baseline]['primary_position_'.$p.'_bike'] !== $decisions[$candidate]['primary_position_'.$p.'_bike'];
                            $change['P'.$p]['changed'] = ($change['P'.$p]['changed'] ?? 0) + (int) $diff;
                            $changed = $changed || $diff;
                        }
                        $change['changed_races'] = ($change['changed_races'] ?? 0) + (int) $changed;
                        foreach (['Hit3' => 'POSITION_HIT_RATE_AT_3', 'Primary_exact_order' => 'EXACT_ORDERED_TOP3_RATE'] as $label => $metric) {
                            $data = $label === 'Hit3' ? $values : $primary;
                            $a = $data[$baseline]['candidate'][$metric];
                            $b = $data[$candidate]['candidate'][$metric];
                            $name = $a['denominator'] === 0.0 ? 'excluded' : ($a['numerator'] === $b['numerator'] ? 'equal'
                                : ($a['numerator'] < $b['numerator'] ? 'improved' : 'worsened'));
                            $change[$label][$name] = ($change[$label][$name] ?? 0) + 1;
                        }
                        unset($change);
                    }
                    yield ['year' => $year, 'race_id' => $race['race_id'], 'comparisons' => $rows];
                    foreach ($streams as $stream) {
                        $stream->next();
                    }
                }
            })());
            foreach ($streams as $stream) {
                if ($stream->valid()) {
                    throw new RuntimeException('Evaluation prediction extra.');
                }
            }
            foreach ($summary as $name => $data) {
                if (min($data['denominators']) <= 0) {
                    throw new RuntimeException('No evaluation denominator.');
                }
                $outer[$name][$year] = $this->metrics->finish($data) + ['candidate_numerators' => $data['candidate_numerators'], 'baseline_numerators' => $data['baseline_numerators']];
                $spools[$name][$year]->seal();
            }
        }
        $intervals = [];
        foreach ($spools as $name => $byYear) {
            echo json_encode(['phase' => 'PAIRED_BOOTSTRAP', 'pair' => $name, 'iterations' => 2000])."\n";
            $intervals[$name] = $this->bootstrap->evaluate($byYear);
        }
        $result = ['outer' => $outer, 'intervals' => $intervals, 'changes' => $changes];
        JsonlArtifact::json($directory.'/evaluation.json', $result);

        return $result;
    }

    public function gates(array $result, bool $integrity): array
    {
        $gates = [];
        foreach (['C-B', 'C-A', 'B-A'] as $pair) {
            $gates[$pair] = $this->gate->incrementalGate($result['outer'][$pair], $result['intervals'][$pair], $integrity);
        }

        return $gates;
    }
}
