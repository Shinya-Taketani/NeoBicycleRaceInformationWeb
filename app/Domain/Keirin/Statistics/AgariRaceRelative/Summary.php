<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\AgariRaceRelative;

use Generator;

final class Summary
{
    private array $groups = [];

    private array $reasons = [];

    private array $rowReasons = [];

    private array $speedReasons = [];

    public function add(array $race): void
    {
        $c = $race['classification'];
        $relative = count(array_filter($race['results'], fn ($r) => $r['relative_status'] === 'CALCULATED'));
        $speed = count(array_filter($race['results'], fn ($r) => $r['speed']['calculable']));
        $counts = ['races' => 1, 'current_result_rows' => count($race['results']),
            'normal_finisher_rows' => $race['normal_finisher_count'], 'valid_timing_rows' => $race['valid_timing_count'],
            'comparison_rows' => $race['exclusion_reasons'] === [] ? $race['valid_timing_count'] : 0,
            'relative_rows' => $relative, 'speed_rows' => $speed,
            'complete_comparison_races' => (int) ($race['relative_status'] === 'CALCULATED' && $race['comparison_completeness'] === 'COMPLETE'),
            'partial_comparison_races' => (int) ($race['relative_status'] === 'CALCULATED' && $race['comparison_completeness'] === 'PARTIAL'),
            'unusable_races' => (int) ($race['relative_status'] !== 'CALCULATED')];
        foreach ([['ALL'], ['YEAR_GRADE', $c['year'], $c['meeting_grade']], ['TRACK', $c['track_code'] ?? 'UNKNOWN'],
            ['RACE_CLASS', $c['race_class']], ['COMPLETENESS', $race['comparison_completeness']],
            ['DISTANCE', $race['distance_status']]] as $dimensions) {
            $key = implode('|', $dimensions);
            $this->groups[$key] ??= ['dimensions' => $dimensions, 'counts' => array_fill_keys(array_keys($counts), 0)];
            foreach ($counts as $name => $value) {
                $this->groups[$key]['counts'][$name] += $value;
            }
        }
        foreach ($race['exclusion_reasons'] ?: ($race['relative_status'] === 'INSUFFICIENT_COMPARISON' ? ['INSUFFICIENT_COMPARISON'] : []) as $reason) {
            $this->reasons[$reason] = ($this->reasons[$reason] ?? 0) + 1;
        }
        foreach ($race['results'] as $row) {
            $reason = $row['relative_status'];
            $this->rowReasons[$reason] = ($this->rowReasons[$reason] ?? 0) + 1;
            $reason = $row['speed']['reason'] ?? 'CALCULATED';
            $this->speedReasons[$reason] = ($this->speedReasons[$reason] ?? 0) + 1;
        }
    }

    public function data(): array
    {
        ksort($this->groups, SORT_STRING);
        ksort($this->reasons, SORT_STRING);
        ksort($this->rowReasons, SORT_STRING);
        ksort($this->speedReasons, SORT_STRING);

        return [...Contract::DISCLOSURE, 'version' => Contract::VERSION,
            'totals' => $this->groups['ALL']['counts'] ?? ['races' => 0, 'current_result_rows' => 0],
            'groups' => array_values($this->groups), 'race_exclusion_reason_counts_nonexclusive' => $this->reasons,
            'relative_row_status_counts' => $this->rowReasons, 'speed_row_reason_counts' => $this->speedReasons];
    }

    public function csv(): Generator
    {
        yield "axis,dimension_1,dimension_2,races,current_result_rows,normal_finisher_rows,valid_timing_rows,comparison_rows,relative_rows,speed_rows,complete_comparison_races,partial_comparison_races,unusable_races,analysis_mode,historical_as_of_available,prediction_use,points\n";
        foreach ($this->data()['groups'] as $group) {
            $values = [...array_pad($group['dimensions'], 3, ''), ...array_values($group['counts']),
                'FINAL_RESULT_DESCRIPTIVE_ONLY', 'false', 'NOT_AUTHORIZED', 'null'];
            yield implode(',', array_map(static fn ($v) => '"'.str_replace('"', '""', (string) $v).'"', $values))."\n";
        }
    }
}
