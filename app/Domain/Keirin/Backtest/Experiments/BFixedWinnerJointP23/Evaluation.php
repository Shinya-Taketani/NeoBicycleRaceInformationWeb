<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\BFixedWinnerJointP23;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05AcceptanceGate;
use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e05PairedBootstrap;
use App\Domain\Keirin\Backtest\Calculators\Type7Quantile;
use App\Domain\Keirin\Backtest\Experiments\Stat35Trend6P1\MetricContributionSpool;
use App\Domain\Keirin\Backtest\Experiments\Stat35Trend6P1\PairedBootstrap as RetainedBootstrap;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Evaluation as FrozenEvaluation;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use RuntimeException;

final class Evaluation
{
    public function __construct(private readonly Reader $reader, private readonly Bt03e05MetricEvaluator $metrics,
        private readonly RetainedBootstrap $bootstrap) {}

    public function evaluate(array $source, array $paths, string $directory): array
    {
        $outer = $primary = $spools = $changes = [];
        foreach ([2024, 2025] as $year) {
            $summaries = $primarySummaries = array_fill_keys(['C-B', 'C-A', 'B-A'], $this->metrics->emptySummary());
            foreach (array_keys($summaries) as $name) {
                $spools[$name][$year] = new MetricContributionSpool($directory.'/'.$name.'-'.$year.'.bin');
            }
            $changes[$year] = ['races' => 0, 'changed_races' => 0, 'B_only_hit' => 0, 'C_only_hit' => 0,
                'both_hit' => 0, 'both_miss' => 0, 'excluded' => 0];
            JsonlArtifact::write($directory.'/contributions-'.$year.'.jsonl', (function () use ($source, $paths, $year, &$summaries, &$primarySummaries, $spools, &$changes) {
                $rows = JsonlArtifact::read($paths[$year]);
                $rows->rewind();
                foreach ($this->reader->labelled($source, $year, $paths) as $context) {
                    if (! $rows->valid()) {
                        throw new RuntimeException('Missing candidate decision.');
                    }
                    $row = $rows->current();
                    Files::same([$context['year'], $context['race_id'], array_map(fn ($e) => [$e['id'], $e['bike']], $context['entries'])],
                        [$row['year'], $row['race_id'], $row['cohort']], 'labelled ordered cohort');
                    $values = $primaryValues = [];
                    foreach (['A', 'B', 'C'] as $name) {
                        $values[$name] = $this->metrics->raceComparison($context, $row[$name]);
                        $primaryValues[$name] = self::primary($values[$name]);
                    }
                    $comparisons = $primaryComparisons = [];
                    foreach (['C-B' => ['C', 'B'], 'C-A' => ['C', 'A'], 'B-A' => ['B', 'A']] as $name => [$candidate, $baseline]) {
                        $comparison = $values[$candidate];
                        $comparison['baseline'] = $values[$baseline]['candidate'];
                        $p = $primaryValues[$candidate];
                        $p['baseline'] = $primaryValues[$baseline]['candidate'];
                        foreach (Bt03e05MetricEvaluator::METRIC_CODES as $metric) {
                            if ($comparison['candidate'][$metric]['denominator'] !== $comparison['baseline'][$metric]['denominator']) {
                                throw new RuntimeException('Paired denominator mismatch.');
                            }
                        }
                        $this->metrics->add($summaries[$name], $comparison);
                        $this->metrics->add($primarySummaries[$name], $p);
                        $spools[$name][$year]->append($p);
                        $comparisons[$name] = $comparison;
                        $primaryComparisons[$name] = $p;
                    }
                    if ($values['B']['candidate']['POSITION_1_ACCURACY'] !== $values['C']['candidate']['POSITION_1_ACCURACY']) {
                        throw new RuntimeException('Winner contribution changed.');
                    }
                    foreach (array_slice(Bt03e05MetricEvaluator::METRIC_CODES, 5) as $metric) {
                        if ($values['B']['candidate'][$metric] !== $values['C']['candidate'][$metric]) {
                            throw new RuntimeException('Supporting contribution changed.');
                        }
                    }
                    $b = $primaryValues['B']['candidate']['EXACT_ORDERED_TOP3_RATE'];
                    $c = $primaryValues['C']['candidate']['EXACT_ORDERED_TOP3_RATE'];
                    $key = $b['denominator'] === 0.0 ? 'excluded' : ($b['numerator'] === 1.0
                        ? ($c['numerator'] === 1.0 ? 'both_hit' : 'B_only_hit') : ($c['numerator'] === 1.0 ? 'C_only_hit' : 'both_miss'));
                    $changes[$year][$key]++;
                    $changes[$year]['races']++;
                    $changes[$year]['changed_races'] += (int) ([$row['B']['primary_position_2_bike'], $row['B']['primary_position_3_bike']]
                        !== [$row['C']['primary_position_2_bike'], $row['C']['primary_position_3_bike']]);
                    yield ['year' => $year, 'race_id' => $context['race_id'], 'source_row' => $row['source_row'],
                        'comparisons' => $comparisons, 'primary_comparisons' => $primaryComparisons];
                    $rows->next();
                }
                if ($rows->valid()) {
                    throw new RuntimeException('Extra candidate decision.');
                }
            })());
            foreach ($summaries as $name => $summary) {
                if (min($summary['denominators']) <= 0) {
                    throw new RuntimeException('Zero denominator: NOT_EVALUATED.');
                }
                $outer[$name][$year] = $this->finish($summary);
                $primary[$name][$year] = $this->finish($primarySummaries[$name]);
                $spools[$name][$year]->seal();
            }
        }
        $intervals = $gates = [];
        $frozenGate = new FrozenEvaluation($this->metrics, new Bt03e05PairedBootstrap(new Type7Quantile), new Bt03e05AcceptanceGate);
        foreach ($spools as $name => $years) {
            echo json_encode(['phase' => 'PAIRED_BOOTSTRAP', 'comparison' => $name, 'iterations' => 2000])."\n";
            $ci = $this->bootstrap->evaluate($years);
            $gates[$name] = $frozenGate->incrementalGate($outer[$name], $ci, true);
            $ci['PRIMARY_EXACT_ORDERED_TOP3_RATE'] = $ci['EXACT_ORDERED_TOP3_RATE'];
            unset($ci['EXACT_ORDERED_TOP3_RATE']);
            $intervals[$name] = $ci;
        }
        $result = ['outer' => $outer, 'primary_exact_order' => $primary, 'intervals' => $intervals,
            'incremental_gates' => $gates, 'exact_order_changes' => $changes];
        JsonlArtifact::json($directory.'/evaluation.json', $result);

        return $result;
    }

    public static function primary(array $comparison): array
    {
        // Only the CI slot changes: the original Supporting MAP metric remains separately saved.
        $comparison['candidate']['EXACT_ORDERED_TOP3_RATE'] = [
            'numerator' => $comparison['diagnostics']['PRIMARY_EXACT_ORDERED_TOP3_RATE'],
            'denominator' => $comparison['diagnostics']['ordered_eligible']];

        return $comparison;
    }

    private function finish(array $summary): array
    {
        return $this->metrics->finish($summary) + ['candidate_numerators' => $summary['candidate_numerators'],
            'baseline_numerators' => $summary['baseline_numerators']];
    }
}
