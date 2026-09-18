<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysisV2;

use App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysis\Analysis as V1Analysis;
use App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysis\Workspace;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;

final class Analysis
{
    public function __construct(private readonly V1Analysis $v1) {}

    public function aggregate(Workspace $workspace, string $source): array
    {
        $result = $this->v1->aggregate($workspace);
        $result['summary']['version'] = Contract::VERSION;
        $result['summary']['point_semantics'] = 'SIGN_PRESERVING_POINT';
        $oldSummary = Files::json($source.'/summary.json');
        Files::same($oldSummary['year_totals'], $result['summary']['year_totals'], 'v1/v2 full cohort conservation');
        $old = Files::json($source.'/correlations.json');
        $oldClasses = Files::json($source.'/classification.json');
        $comparisons = [];
        Files::same(array_keys($old), array_keys($result['correlations']), 'v1/v2 stratum count');
        foreach ($result['correlations'] as $i => $row) {
            $group = array_intersect_key($row, array_flip(['dimension', 'year', 'grade', 'class', 'same', 'signal']));
            Files::same($group, array_intersect_key($old[$i], $group), 'v1/v2 stratum');
            Files::same(['raw' => $old[$i]['raw']], ['raw' => $row['raw']], 'unrounded raw Spearman');
            $cells = array_values(array_filter($result['summary']['cells'], fn ($c) => array_intersect_key($c, $group) === $group && $c['point'] !== 'MISSING'));
            $result['classification'][$i]['monotonicity'] = ['all_points' => $this->monotonicity($cells),
                'supported_points' => $this->monotonicity(array_values(array_filter($cells, fn ($c) => $c['normal'] >= 30)))];
            $comparisons[] = $group + ['raw_exactly_equal' => true, 'raw' => $row['raw'], 'point_v1' => $old[$i]['point'],
                'point_v2' => $row['point'], 'classification_v1' => $oldClasses[$i], 'classification_v2' => $result['classification'][$i]];
        }
        $result['comparison'] = ['raw_spearman_exactly_equal_all_strata' => true, 'strata' => $comparisons,
            'c1' => ['v1' => Files::json($source.'/c1-diagnostics.json'), 'v2' => $result['c1_diagnostics']]];

        return $result;
    }

    public function monotonicity(array $cells): array
    {
        $deltas = [];
        $up = $down = ['win_rate' => 0, 'top3_rate' => 0, 'mean_fp' => 0];
        for ($i = 1; $i < count($cells); $i++) {
            $row = ['from' => $cells[$i - 1]['point'], 'to' => $cells[$i]['point']];
            foreach (array_keys($up) as $metric) {
                $a = $cells[$i - 1][$metric];
                $b = $cells[$i][$metric];
                $delta = $a === null || $b === null ? null : $b - $a;
                $row[$metric] = $delta;
                $up[$metric] += $delta !== null && $delta < 0 ? 1 : 0;
                $down[$metric] += $delta !== null && $delta > 0 ? 1 : 0;
            }
            $deltas[] = $row;
        }

        return ['adjacent' => $deltas, 'increasing_violations' => $up, 'decreasing_violations' => $down];
    }
}
