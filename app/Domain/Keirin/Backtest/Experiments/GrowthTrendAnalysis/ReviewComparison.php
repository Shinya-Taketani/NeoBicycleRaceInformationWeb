<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthTrendAnalysis;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use RuntimeException;

final class ReviewComparison
{
    public function report(array $sources, array $years, array $selection, array $outputs, array $expected, TemporalAccess $access): array
    {
        $access->authorize();
        $path = '/home/shinya/neo-keirin-artifacts/growth-trend-analysis-01-20260919-01/evaluations/outer-c1-growth-trend-2024-2025-01';
        $seal = Files::identity($path.'/manifest.json');
        if ($seal['sha256'] !== 'df83f8b88b1112f06bc98244ba356f01295d9a593e6157ce6719a1bff23e3ce4') {
            throw new RuntimeException('Initial analysis identity mismatch.');
        }
        $manifest = Files::json($path.'/manifest.json');
        $read = function (string $name) use ($path, $manifest): array {
            Files::verify($path.'/'.$name, $manifest['files'][$name]);

            return Files::json($path.'/'.$name);
        };
        $oldSources = $read('sources.json');
        $scorePath = $oldSources['score'].'/score-observations.jsonl';
        $sameScore = $oldSources['files'][$scorePath] === Files::identity($sources['score'].'/score-observations.jsonl');
        if (! $sameScore) {
            throw new RuntimeException('Score observations changed from initial run; STOP.');
        }
        $oldYears = [];
        foreach ([2024, 2025] as $year) {
            $oldYears[$year] = $read('candidate-results-'.$year.'.json');
        }
        $oldSelection = $read('granularity-selection.json');
        $report = $this->compare($oldYears, $years, $oldSelection, $selection);
        $report += ['source_observation_identity_same' => $sameScore,
            'trend_input_identity_same' => $manifest['files']['trend-input.jsonl'] === $expected['trend-input.jsonl'],
            'candidate_grid_same' => $read('candidate-grid.json') === Contract::grid(), 'diagnostics_diff' => []];
        foreach (['local-stability.json', 'c1-confidence-diagnostics.json', 'missed-winner-diagnostics.json',
            'grade-class-diagnostics.json', 'score-change-event-diagnostics.json'] as $name) {
            $old = $read($name);
            $new = $outputs[$name];
            $report['diagnostics_diff'][$name] = ['same' => $old === $new];
            if ($old !== $new) {
                $report['diagnostics_diff'][$name] += ['old' => $old, 'new' => $new];
            }
        }
        Files::verify($path.'/manifest.json', $seal);

        return $report;
    }

    public function compare(array $old, array $new, array $oldSelection, array $newSelection): array
    {
        $report = ['selected_old' => $oldSelection['selected'], 'selected_new' => $newSelection['selected'],
            'robust_rho_old' => $oldSelection['selected']['robust_rho'] ?? null, 'robust_rho_new' => $newSelection['selected']['robust_rho'] ?? null,
            'eligible_old' => array_column($oldSelection['eligible'], 'id'), 'eligible_new' => array_column($newSelection['eligible'], 'id'),
            'candidate_metrics' => [], 'day_changed' => [], 'meeting_changed' => []];
        foreach ([2024, 2025] as $year) {
            foreach (Contract::grid() as $c) {
                $id = $c['id'];
                $same = $old[$year][$id] === $new[$year][$id];
                $report['candidate_metrics'][$year][$id] = ['same' => $same, 'old' => $this->metrics($old[$year][$id]),
                    'new' => $this->metrics($new[$year][$id]), 'eligible_old' => in_array($id, $report['eligible_old'], true),
                    'eligible_new' => in_array($id, $report['eligible_new'], true)];
                if (! $same) {
                    $report[str_starts_with($c['family'], 'DAY_') ? 'day_changed' : 'meeting_changed'][] = $year.':'.$id;
                }
            }
        }
        $sameSelection = ($oldSelection['selected']['id'] ?? null) === ($newSelection['selected']['id'] ?? null);
        $report['status'] = ! $sameSelection ? 'SELECTION_CHANGED_AFTER_DAY_SEMANTICS_FIX'
            : ($report['day_changed'] === [] && $report['meeting_changed'] === [] && $oldSelection === $newSelection
                ? 'NUMERICALLY_UNCHANGED_AFTER_PR62_REVIEW_FIX' : 'DAY_SEMANTICS_CORRECTED_SELECTED_GRANULARITY_UNCHANGED');
        if ($report['meeting_changed'] !== []) {
            throw new RuntimeException('Unexpected MEETING candidate numerical drift.');
        }

        return $report;
    }

    private function metrics(array $m): array
    {
        return array_intersect_key($m, array_flip(['valid_entries', 'normal_entries', 'coverage', 'zero_rate', 'fp_pos_minus_neg']))
            + ['overall_rho' => $m['overall']['rho'], 'score_conditional_rho' => $m['score_conditional']['rho'],
                'c1_conditional_rho' => $m['c1_conditional']['rho'], 'first_rho' => $m['first_observation']['overall']['rho'],
                'first_fp_pos_minus_neg' => $m['first_observation']['fp_pos_minus_neg']];
    }
}
