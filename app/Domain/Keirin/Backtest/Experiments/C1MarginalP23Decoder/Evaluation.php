<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05AcceptanceGate;
use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e05PairedBootstrap;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Evaluation as OriginalEvaluation;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Support\Bt03e05MetricContributionSpool;
use Generator;
use RuntimeException;

final class Evaluation
{
    public function __construct(private readonly Reader $reader, private readonly Bt03e05MetricEvaluator $metrics,
        private readonly Bt03e05PairedBootstrap $bootstrap, private readonly Bt03e05AcceptanceGate $gate) {}

    public function evaluate(array $source, array $paths, string $directory, ?callable $check = null): array
    {
        $outer = $spools = $changes = [];
        foreach ([2024, 2025] as $year) {
            $summaries = array_fill_keys(['CANDIDATE-C1', 'CANDIDATE-STAT01', 'C1-STAT01', 'PRIMARY-CANDIDATE-STAT01', 'PRIMARY-C1-STAT01'], $this->metrics->emptySummary());
            foreach (['CANDIDATE-C1', 'CANDIDATE-STAT01'] as $name) {
                $spools[$name][$year] = new Bt03e05MetricContributionSpool($directory.'/'.$name.'-'.$year.'-working.bin');
            }
            $changes[$year] = ['P2' => ['both_hit' => 0, 'C1_only' => 0, 'candidate_only' => 0, 'both_miss' => 0, 'excluded' => 0],
                'P3' => ['both_hit' => 0, 'C1_only' => 0, 'candidate_only' => 0, 'both_miss' => 0, 'excluded' => 0],
                'Hit3' => ['improved_races' => 0, 'worsened_races' => 0, 'equal_races' => 0, 'excluded_races' => 0,
                    'C1_correct_positions' => 0, 'candidate_correct_positions' => 0]];
            JsonlArtifact::write($directory.'/contributions-'.$year.'.jsonl', $this->rows($source, $paths, $year, $summaries, $spools, $changes[$year], $check));
            foreach ($summaries as $name => $data) {
                if (min($data['denominators']) <= 0) {
                    throw new RuntimeException('Zero evaluation denominator: NOT_EVALUATED.');
                }
                $outer[$name][$year] = $this->metrics->finish($data) + ['candidate_numerators' => $data['candidate_numerators'], 'baseline_numerators' => $data['baseline_numerators']];
            }
            foreach ($spools as $byYear) {
                $byYear[$year]->seal();
            }
        }
        $intervals = [];
        foreach ($spools as $name => $byYear) {
            echo json_encode(['phase' => 'PAIRED_BOOTSTRAP', 'comparison' => $name, 'iterations' => 2000])."\n";
            $intervals[$name] = $this->bootstrap->evaluate($byYear);
        }
        $result = ['outer' => $outer, 'intervals' => $intervals, 'changes' => $changes];
        JsonlArtifact::json($directory.'/evaluation.json', $result);

        return $result;
    }

    public function gates(array $result, bool $integrity): array
    {
        return ['incremental_gate' => (new OriginalEvaluation($this->metrics, $this->bootstrap, $this->gate))->incrementalGate(
            $result['outer']['CANDIDATE-C1'], $result['intervals']['CANDIDATE-C1'], $integrity),
            'stat01_gate' => $this->gate->evaluate($result['outer']['CANDIDATE-STAT01'], $result['intervals']['CANDIDATE-STAT01'], $integrity)];
    }

    private function rows(array $source, array $paths, int $year, array &$summaries, array $spools, array &$changes, ?callable $check): Generator
    {
        $decisions = JsonlArtifact::read($paths[$year]);
        $decisions->rewind();
        foreach ($this->reader->labelled($source, $year, $paths) as $context) {
            if (! $decisions->valid()) {
                throw new RuntimeException('Missing sealed candidate race.');
            }
            $row = $decisions->current();
            Files::same([$context['year'], $context['race_id'], array_map(fn ($e) => [$e['id'], $e['bike']], $context['entries'])],
                [$row['year'], $row['race_id'], $row['cohort']], 'evaluation cohort');
            $first = $this->metrics->raceComparison($context, $row['baseline']);
            $second = $this->metrics->raceComparison($context, $row['candidate']);
            $incremental = $second;
            $incremental['baseline'] = $first['candidate'];
            $comparisons = ['CANDIDATE-C1' => $incremental, 'CANDIDATE-STAT01' => $second, 'C1-STAT01' => $first,
                'PRIMARY-CANDIDATE-STAT01' => $this->metrics->raceComparison($context, $this->primary($row['candidate'])),
                'PRIMARY-C1-STAT01' => $this->metrics->raceComparison($context, $this->primary($row['baseline']))];
            foreach (Bt03e05MetricEvaluator::METRIC_CODES as $metric) {
                if ($first['candidate'][$metric]['denominator'] !== $second['candidate'][$metric]['denominator']) {
                    throw new RuntimeException('Paired denominators disagreed.');
                }
            }
            if ($check !== null) {
                $check($context, $row, $comparisons);
            }
            foreach ($comparisons as $name => $comparison) {
                $this->metrics->add($summaries[$name], $comparison);
            }
            $spools['CANDIDATE-C1'][$year]->append($incremental);
            $spools['CANDIDATE-STAT01'][$year]->append($second);
            foreach ([2, 3] as $position) {
                $a = $first['candidate']['POSITION_'.$position.'_ACCURACY'];
                $b = $second['candidate']['POSITION_'.$position.'_ACCURACY'];
                $key = $a['denominator'] === 0.0 ? 'excluded'
                    : ($a['numerator'] > 0.0 ? ($b['numerator'] > 0.0 ? 'both_hit' : 'C1_only') : ($b['numerator'] > 0.0 ? 'candidate_only' : 'both_miss'));
                $changes['P'.$position][$key]++;
            }
            $a = $first['candidate']['POSITION_HIT_RATE_AT_3'];
            $b = $second['candidate']['POSITION_HIT_RATE_AT_3'];
            $key = $a['denominator'] === 0.0 ? 'excluded_races'
                : ($a['numerator'] === $b['numerator'] ? 'equal_races' : ($b['numerator'] > $a['numerator'] ? 'improved_races' : 'worsened_races'));
            $changes['Hit3'][$key]++;
            $changes['Hit3']['C1_correct_positions'] += (int) $a['numerator'];
            $changes['Hit3']['candidate_correct_positions'] += (int) $b['numerator'];
            yield ['year' => $year, 'race_id' => $context['race_id'], 'source_row' => $row['source_row'],
                'model_expected_gain' => $row['model_expected_gain'], 'comparisons' => $comparisons];
            $decisions->next();
        }
        if ($decisions->valid()) {
            throw new RuntimeException('Extra sealed candidate race.');
        }
    }

    private function primary(array $decision): array
    {
        $primary = [$decision['primary_position_1_bike'], $decision['primary_position_2_bike'], $decision['primary_position_3_bike']];
        $decision['map_ordered_top3'] = $decision['map_top3_set'] = $decision['top3_marginal_bikes'] = $decision['expected_ndcg_top3'] = $primary;
        $decision['top2_marginal_bikes'] = array_slice($primary, 0, 2);

        return $decision;
    }
}
