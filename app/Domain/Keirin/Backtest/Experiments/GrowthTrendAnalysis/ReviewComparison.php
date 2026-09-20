<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthTrendAnalysis;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use RuntimeException;

class ReviewComparison
{
    protected function referencePath(): string
    {
        return '/home/shinya/neo-keirin-artifacts/growth-trend-analysis-01-20260919-01/evaluations/outer-c1-growth-trend-2024-2025-01';
    }

    protected function referenceHash(): string
    {
        return 'df83f8b88b1112f06bc98244ba356f01295d9a593e6157ce6719a1bff23e3ce4';
    }

    public function report(string $stage, array $sources, array $years, array $selection, array $outputs, array $expected, TemporalAccess $access): array
    {
        $access->authorize();
        $path = $this->referencePath();
        $seal = Files::identity($path.'/manifest.json');
        if ($seal['sha256'] !== $this->referenceHash()) {
            throw new RuntimeException('Initial analysis identity mismatch.');
        }
        $manifest = Files::json($path.'/manifest.json');
        $read = function (string $name) use ($path, $manifest): array {
            Files::verify($path.'/'.$name, $manifest['files'][$name]);

            return Files::json($path.'/'.$name);
        };
        $oldSources = $read('sources.json');
        $scorePath = $oldSources['score'].'/score-observations.jsonl';
        $oldScore = $oldSources['files'][$scorePath];
        $sameScore = ['bytes' => $oldScore['bytes'], 'sha256' => $oldScore['sha256']] === Files::identity($sources['score'].'/score-observations.jsonl');
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
        Files::verify($path.'/trend-input.jsonl', $manifest['files']['trend-input.jsonl']);
        $report['same_date_audit'] = $this->trendChanges($path.'/trend-input.jsonl', $stage.'/trend-input.jsonl');
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
        $report['status'] = $sameSelection ? 'SELECTED_GRANULARITY_UNCHANGED_AFTER_PR62_REVIEW_FIX' : 'SELECTION_CHANGED_AFTER_TIME_ORDER_FIX';
        $report['all_candidate_metrics_same'] = $old === $new;

        return $report;
    }

    public function trendChanges(string $oldPath, string $newPath): array
    {
        $old = JsonlArtifact::read($oldPath);
        $report = $meetings = [];
        foreach ([2024, 2025] as $year) {
            $report[$year] = ['targets' => 0, 'affected_targets' => 0, 'ambiguous_meeting_pairs' => 0,
                'candidate_state_changes' => [], 'candidate_raw_changes' => [], 'old_valid_to_partial_time_order' => []];
            $meetings[$year] = [];
        }
        foreach (JsonlArtifact::read($newPath) as $row) {
            $before = $old->valid() ? $old->current() : null;
            if ($before === null || array_intersect_key($before, array_flip(['year', 'race_id', 'entry_id'])) !== array_intersect_key($row, array_flip(['year', 'race_id', 'entry_id']))) {
                throw new RuntimeException('Old/new trend target identity mismatch.');
            }
            $year = $row['year'];
            \App\Domain\Keirin\Backtest\Experiments\GrowthTrendScoreSource\Contract::year($year, true);
            $report[$year]['targets']++;
            if ($row['target_boundary_partial_time_order'] ?? false) {
                $report[$year]['affected_targets']++;
                foreach ($row['ambiguous_meeting_ids'] as $id) {
                    $meetings[$year][$id] = true;
                    $report[$year]['ambiguous_meeting_pairs']++;
                }
            }
            foreach (Contract::grid() as $c) {
                $id = $c['id'];
                $a = $before['candidates'][$id];
                $b = $row['candidates'][$id];
                foreach (['candidate_state_changes' => $a['status'] !== $b['status'], 'candidate_raw_changes' => $a['raw'] !== $b['raw'],
                    'old_valid_to_partial_time_order' => $a['status'] === 'VALID' && $b['status'] === 'PARTIAL_TIME_ORDER'] as $kind => $changed) {
                    $report[$year][$kind][$id] = ($report[$year][$kind][$id] ?? 0) + (int) $changed;
                }
            }
            $old->next();
        }
        if ($old->valid()) {
            throw new RuntimeException('Extra old trend targets.');
        }
        foreach ([2024, 2025] as $year) {
            $report[$year]['ambiguous_meeting_ids'] = array_keys($meetings[$year]);
            sort($report[$year]['ambiguous_meeting_ids'], SORT_NUMERIC);
            $report[$year]['ambiguous_meeting_count'] = count($meetings[$year]);
        }

        return ['reason' => 'TARGET_BOUNDARY_PARTIAL_TIME_ORDER', 'years' => $report];
    }

    private function metrics(array $m): array
    {
        return array_intersect_key($m, array_flip(['valid_entries', 'normal_entries', 'coverage', 'zero_rate', 'fp_pos_minus_neg']))
            + ['overall_rho' => $m['overall']['rho'], 'score_conditional_rho' => $m['score_conditional']['rho'],
                'c1_conditional_rho' => $m['c1_conditional']['rho'], 'first_rho' => $m['first_observation']['overall']['rho'],
                'first_fp_pos_minus_neg' => $m['first_observation']['fp_pos_minus_neg']];
    }
}
