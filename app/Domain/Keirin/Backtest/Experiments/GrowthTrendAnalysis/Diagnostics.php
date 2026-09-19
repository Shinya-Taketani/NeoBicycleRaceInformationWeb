<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthTrendAnalysis;

final class Diagnostics
{
    public function run(Statistics $s, array $selection, array $years, array $audit): array
    {
        $selected = $selection['selected'];
        $empty = ['status' => 'NOT_APPLICABLE_NO_SELECTED_CANDIDATE'];
        $local = $confidence = $winner = $grade = $cycle = $empty;
        $events = [];
        foreach ([2024, 2025] as $year) {
            foreach (['LAST_SCORE_CHANGE_DELTA', 'MEETINGS_SINCE_LAST_SCORE_CHANGE', 'DAYS_SINCE_LAST_SCORE_CHANGE'] as $id) {
                $events[$year][$id] = $s->metrics($id, $year, conditional: false);
            }
        }
        if ($selected !== null) {
            $id = $selected['id'];
            $family = array_values(array_filter(Contract::grid(), fn ($c) => $c['family'] === $selected['family']));
            $index = array_search($id, array_column($family, 'id'), true);
            $neighbors = array_slice($family, max(0, $index - 1), $index === 0 ? 2 : 3);
            $stable = true;
            $local = $confidence = $winner = $grade = [];
            foreach ($neighbors as $c) {
                foreach ([2024, 2025] as $year) {
                    $m = $years[$year][$c['id']];
                    $local['neighbors'][$c['id']][$year] = array_intersect_key($m, array_flip(['coverage', 'overall', 'score_conditional', 'c1_conditional', 'fp_pos_minus_neg']));
                    $stable = $stable && $m['overall']['rho'] > 0 && $m['score_conditional']['rho'] > 0 && $m['c1_conditional']['rho'] > 0 && $m['fp_pos_minus_neg'] > 0;
                }
            }
            $local['status'] = $stable ? 'LOCAL_STABILITY_PRESENT' : 'ISOLATED_OPTIMUM_WARNING';
            foreach ([2024, 2025] as $year) {
                foreach ($s->query('SELECT DISTINCT margin_bin FROM entries WHERE year=? ORDER BY margin_bin', [$year]) as $bin) {
                    $q = $bin['margin_bin'];
                    $confidence[$year][$q] = $s->metrics($id, $year, 'e.margin_bin=?', [$q], false);
                    $pred = $s->query('SELECT count(*) AS n,sum(normal=1 AND rank=1) AS wins FROM entries WHERE year=? AND margin_bin=? AND predicted=1', [$year, $q])->fetch();
                    $confidence[$year][$q]['c1_candidate_win'] = $pred + ['rate' => $pred['n'] ? $pred['wins'] / $pred['n'] : null];
                }
                // Keep the race lookup before the second signal lookup; SQLite otherwise scans every candidate pair.
                $from = '(SELECT a.raw-p.raw AS gap FROM signals a JOIN entries ae ON ae.entry_id=a.entry_id
                    CROSS JOIN entries pe ON pe.race_id=ae.race_id AND pe.predicted=1 CROSS JOIN signals p ON p.entry_id=pe.entry_id AND p.candidate=a.candidate
                    WHERE a.candidate=? AND ae.year=? AND ae.unique_winner=1 AND ae.rank=1 AND a.raw IS NOT NULL AND p.raw IS NOT NULL) WHERE 1=1';
                $winner[$year] = $s->distribution($from, [$id, $year], 'gap');
                $counts = $s->query('SELECT sum(gap>0) AS positive,sum(gap=0) AS zero,sum(gap<0) AS negative FROM '.$from, [$id, $year])->fetch();
                foreach ($counts as $key => $n) {
                    $winner[$year][$key.'_fraction'] = $winner[$year]['n'] ? $n / $winner[$year]['n'] : null;
                }
                foreach ($s->query('SELECT DISTINCT grade,class FROM entries WHERE year=? ORDER BY grade,class', [$year]) as $stratum) {
                    $grade[$year]['grade_by_class'][] = $stratum + ['metrics' => $s->metrics($id, $year, 'e.grade=? AND e.class=?', [$stratum['grade'], $stratum['class']], false)];
                }
                foreach (['grade', 'class'] as $column) {
                    foreach ($s->query('SELECT DISTINCT '.$column.' FROM entries WHERE year=? ORDER BY '.$column, [$year]) as $stratum) {
                        $grade[$year][$column][] = $stratum + ['metrics' => $s->metrics($id, $year, 'e.'.$column.'=?', [$stratum[$column]], false)];
                    }
                }
            }
            $interval = $audit['distributions'][str_starts_with($selected['family'], 'DAY_') ? 'days_between_score_changes' : 'meetings_between_score_changes'] ?? null;
            $cycle = ['grain' => $selected['grain'], 'observed_change_interval' => $interval,
                'status' => $interval === null || $interval['n'] === 0 ? 'INSUFFICIENT_INTERVALS'
                    : ($selected['grain'] < $interval['P25'] || $selected['grain'] > $interval['P75'] ? 'GRANULARITY_UPDATE_CYCLE_MISMATCH_WARNING' : 'WITHIN_OBSERVED_IQR'),
                'rule' => 'REFERENCE_ONLY_OUTSIDE_CHANGE_INTERVAL_IQR; NOT_FOR_RESELECTION'];
        }

        return ['local-stability.json' => $local + ['update_cycle' => $cycle], 'c1-confidence-diagnostics.json' => $confidence,
            'missed-winner-diagnostics.json' => $winner, 'grade-class-diagnostics.json' => $grade, 'score-change-event-diagnostics.json' => $events];
    }
}
