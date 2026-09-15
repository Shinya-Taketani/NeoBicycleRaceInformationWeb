<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalHistory;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05AcceptanceGate;
use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e05PairedBootstrap;
use App\Domain\Keirin\Backtest\Support\Bt03e05MetricContributionSpool;
use Generator;
use RuntimeException;

final class Evaluation
{
    public const PRIMARY = ['WINNER_HIT_AT_1', 'POSITION_2_ACCURACY', 'POSITION_3_ACCURACY', 'POSITION_HIT_RATE_AT_3'];

    public function __construct(private readonly Bt03e05MetricEvaluator $metrics, private readonly Bt03e05PairedBootstrap $bootstrap, private readonly Bt03e05AcceptanceGate $gate) {}

    public function evaluate(array $years, string $directory, bool $integrity): array
    {
        $outer = $spools = [];
        foreach ($years as $year => $paths) {
            $summary = array_fill_keys(['C1-C0', 'C1-STAT01', 'C0-STAT01', 'PRIMARY-C1-STAT01', 'PRIMARY-C0-STAT01'], $this->metrics->emptySummary());
            foreach (['C1-C0', 'C1-STAT01'] as $comparison) {
                $spools[$comparison][$year] = new Bt03e05MetricContributionSpool($directory.'/'.$comparison.'-'.$year.'-working.bin');
            }
            $rows = $this->comparisons($paths, $summary, $spools, $year);
            JsonlArtifact::write($directory.'/contributions-'.$year.'.jsonl', $rows);
            foreach ($summary as $name => $data) {
                $outer[$name][$year] = $this->metrics->finish($data);
            }
            foreach ($spools as $byYear) {
                $byYear[$year]->seal();
            }
        }
        $result = ['outer' => $outer, 'intervals' => []];
        foreach ($spools as $name => $byYear) {
            echo json_encode(['phase' => 'PAIRED_BOOTSTRAP', 'comparison' => $name, 'iterations' => 2000])."\n";
            $result['intervals'][$name] = $this->bootstrap->evaluate($byYear);
        }
        $result['incremental_gate'] = $this->incrementalGate($outer['C1-C0'], $result['intervals']['C1-C0'], $integrity);
        $result['stat01_gate'] = $this->gate->evaluate($outer['C1-STAT01'], $result['intervals']['C1-STAT01'], $integrity);
        JsonlArtifact::json($directory.'/comparisons.json', $result);

        return $result;
    }

    public function incrementalGate(array $outer, array $intervals, bool $integrity): array
    {
        $ni = $temporal = true;
        foreach (self::PRIMARY as $metric) {
            $ni = $ni && ($intervals[$metric]['ci_lower'] ?? -INF) > -0.0015;
            foreach ($outer as $year) {
                $temporal = $temporal && $year['delta'][$metric] >= -0.0030;
            }
        }
        foreach ($outer as $year) {
            $temporal = $temporal && $year['delta']['POSITION_HIT_RATE_AT_3'] >= 0.0;
        }
        $superiority = ($intervals['POSITION_HIT_RATE_AT_3']['ci_lower'] ?? -INF) > 0.0;

        return ['status' => $ni && $temporal && $superiority && $integrity ? 'PASS_DEVELOPMENT_INCREMENTAL_EFFECT_ONLY' : 'NOT_PASSED',
            'non_inferiority' => $ni, 'temporal' => $temporal, 'superiority' => $superiority, 'integrity' => $integrity];
    }

    private function comparisons(array $paths, array &$summary, array $spools, int $year): Generator
    {
        $c0 = JsonlArtifact::read($paths['C0']);
        $c1 = JsonlArtifact::read($paths['C1']);
        $c0->rewind();
        $c1->rewind();
        foreach (JsonlArtifact::read($paths['labels']) as $context) {
            if (! $c0->valid() || ! $c1->valid()) {
                throw new RuntimeException('Comparison prediction universe was incomplete.');
            }
            $first = $this->metrics->raceComparison($context, $c0->current()['decision']);
            $second = $this->metrics->raceComparison($context, $c1->current()['decision']);
            $primaryFirst = $this->metrics->raceComparison($context, $this->primaryDecision($c0->current()['decision']));
            $primarySecond = $this->metrics->raceComparison($context, $this->primaryDecision($c1->current()['decision']));
            foreach (Bt03e05MetricEvaluator::METRIC_CODES as $metric) {
                if ($first['candidate'][$metric]['denominator'] !== $second['candidate'][$metric]['denominator']) {
                    throw new RuntimeException('Paired metric denominator disagreed.');
                }
            }
            $incremental = $second;
            $incremental['baseline'] = $first['candidate'];
            $spools['C1-C0'][$year]->append($incremental);
            $spools['C1-STAT01'][$year]->append($second);
            foreach (['C0-STAT01' => $first, 'C1-STAT01' => $second, 'C1-C0' => $incremental,
                'PRIMARY-C0-STAT01' => $primaryFirst, 'PRIMARY-C1-STAT01' => $primarySecond] as $name => $comparison) {
                $this->metrics->add($summary[$name], $comparison);
            }
            yield ['race_id' => $context['race_id'], 'C0-STAT01' => $first, 'C1-STAT01' => $second,
                'PRIMARY-C0-STAT01' => $primaryFirst, 'PRIMARY-C1-STAT01' => $primarySecond];
            $c0->next();
            $c1->next();
        }
        if ($c0->valid() || $c1->valid()) {
            throw new RuntimeException('Labels did not cover the prediction universe.');
        }
    }

    private function primaryDecision(array $decision): array
    {
        $primary = [$decision['primary_position_1_bike'], $decision['primary_position_2_bike'], $decision['primary_position_3_bike']];
        $decision['map_ordered_top3'] = $decision['map_top3_set'] = $decision['top3_marginal_bikes'] = $decision['expected_ndcg_top3'] = $primary;
        $decision['top2_marginal_bikes'] = array_slice($primary, 0, 2);

        return $decision;
    }
}
