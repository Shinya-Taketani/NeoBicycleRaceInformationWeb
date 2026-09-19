<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthTrendAnalysis;

final class Selection
{
    public function select(array $years): array
    {
        $max = [];
        foreach ([2024, 2025] as $year) {
            foreach (Contract::grid() as $c) {
                $max[$year][$c['family']] = max($max[$year][$c['family']] ?? 0.0, $years[$year][$c['id']]['coverage'] ?? 0.0);
            }
        }
        $all = $eligible = [];
        foreach (Contract::grid() as $c) {
            $failures = $rhos = $coverages = [];
            foreach ([2024, 2025] as $year) {
                $m = $years[$year][$c['id']];
                $coverages[] = $m['coverage'];
                foreach (['overall', 'score_conditional', 'c1_conditional'] as $name) {
                    $rho = $m[$name]['rho'];
                    $rhos[] = $rho;
                    if ($rho === null || $rho <= 0) {
                        $failures[] = $year.':'.$name;
                    }
                }
                $first = $m['first_observation'];
                $rhos[] = $first['overall']['rho'];
                if ($first['overall']['rho'] === null || $first['overall']['rho'] <= 0) {
                    $failures[] = $year.':first_rho';
                }
                foreach (['all' => $m['fp_pos_minus_neg'], 'first' => $first['fp_pos_minus_neg']] as $name => $value) {
                    if ($value === null || $value <= 0) {
                        $failures[] = $year.':'.$name.'_fp';
                    }
                }
                if ($m['coverage'] === null || $m['coverage'] < $max[$year][$c['family']] * 0.8) {
                    $failures[] = $year.':coverage';
                }
                if ($m['normal_entries'] < 10000) {
                    $failures[] = $year.':minimum_normal';
                }
            }
            $row = $c + ['eligible' => $failures === [], 'failures' => $failures,
                'robust_rho' => in_array(null, $rhos, true) ? null : min($rhos), 'minimum_coverage' => min($coverages)];
            $all[] = $row;
            if ($row['eligible']) {
                $eligible[] = $row;
            }
        }
        usort($eligible, self::compare(...));
        $families = [];
        foreach ($eligible as $row) {
            $families[$row['family']][] = $row;
        }

        return ['status' => $eligible === [] ? 'NO_STABLE_GROWTH_GRANULARITY_SELECTED' : 'DEVELOPMENT_SELECTED_GRANULARITY_AWAITING_REVIEW',
            'selection_kind' => 'MULTI_YEAR_DEVELOPMENT_STABILITY_SELECTION', 'selected' => $eligible[0] ?? null,
            'eligible' => $eligible, 'all_candidates' => $all, 'family_ranking' => $families, 'family_max_coverage' => $max];
    }

    public static function compare(array $a, array $b): int
    {
        return ($b['robust_rho'] <=> $a['robust_rho']) ?: (($b['minimum_coverage'] <=> $a['minimum_coverage']) ?: strcmp($a['id'], $b['id']));
    }
}
