<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalMeetingGradeAnalysis;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Experiments\TacticalGradeAnalysis\Aggregator as RiderAggregator;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\Matcher;
use Generator;
use RuntimeException;

final class Aggregator
{
    public function __construct(private readonly Matcher $matcher, private readonly Bt03e05MetricEvaluator $metrics) {}

    public function details(iterable $rows, array $meetings, array $reference, array &$summary): Generator
    {
        $cells = $seen = $coverage = $totals = $counts = [];
        foreach ([2024, 2025, 'POOLED'] as $year) {
            foreach (Contract::GRADES as $grade) {
                $key = [$year === 'POOLED' ? 'pooled' : 'year', $year, $grade, null];
                $cells[Files::canonical($key)] = $this->empty($key);
            }
        }
        foreach ($rows as $row) {
            $meta = $row['metadata'];
            Classification::validate($meta);
            $context = $row['context'];
            $id = $meta['race_id'];
            $year = $meta['year'];
            if (isset($seen[$id]) || $context['race_id'] !== $id || $context['year'] !== $year
                || count($context['entries']) !== $meta['entrant_count']) {
                throw new RuntimeException('Duplicate race or inconsistent analysis context.');
            }
            $seen[$id] = true;
            $counts[$year] = ($counts[$year] ?? 0) + 1;
            $this->matcher->result($context);
            $input = $context;
            foreach ($input['entries'] as &$entry) {
                unset($entry['rank'], $entry['status']);
            }
            unset($entry);
            $this->matcher->prediction($input, ['probabilities' => $input, 'decision' => $row['decision']]);
            $computed = $this->metrics->raceComparison($context, $row['decision']);
            $meeting = $meetings[(string) ($meta['meeting_id'] ?? 'missing:'.$id)] ?? throw new RuntimeException('Missing meeting classification.');
            $grade = $meeting['grade'];
            $coverage[$year][$meeting['reason']] = ($coverage[$year][$meeting['reason']] ?? 0) + 1;
            $values = [];
            foreach (Contract::METRICS as $key => $metric) {
                $value = $computed['candidate'][$metric];
                Files::same($row['saved'][$key], $value, 'original saved '.$metric.' contribution');
                $excluded = $value['denominator'] === 0.0;
                $reason = null;
                if ($excluded) {
                    $positions = $key === 'H3' ? [1, 2, 3] : [(int) substr($key, 1)];
                    $reasons = [];
                    foreach ($positions as $position) {
                        $n = count(array_filter($context['entries'], fn ($e) => $e['rank'] === $position));
                        if ($n !== 1) {
                            $reasons[] = ($n === 0 ? 'MISSING_POSITION_' : 'TIED_POSITION_').$position;
                        }
                    }
                    $reason = implode('+', $reasons);
                }
                $values[$key] = ['hits' => (int) $value['numerator'], 'denominator' => (int) $value['denominator'],
                    'eligible_races' => $excluded ? 0 : 1, 'excluded_races' => $excluded ? 1 : 0, 'exclusion_reason' => $reason];
                $totals[$year][$key]['hits'] = ($totals[$year][$key]['hits'] ?? 0) + $values[$key]['hits'];
                $totals[$year][$key]['denominator'] = ($totals[$year][$key]['denominator'] ?? 0) + $values[$key]['denominator'];
            }
            $detail = ['year' => $year, 'race_id' => $id, 'race_date' => $meta['race_date'], 'meeting_id' => $meta['meeting_id'],
                'grade' => $grade, 'grade_reason' => $meeting['reason'], 'entrant_count' => $meta['entrant_count'],
                'race_class' => Classification::raceClass($meta['race_type_raw']), 'stage' => Classification::stage($meta['race_type_raw']),
                'race_type_raw' => $meta['race_type_raw'], 'meeting_grade_raw' => $meta['meeting_grade_raw'], 'race_grade_raw' => $meta['race_grade_raw'],
                'metrics' => $values];
            $keys = [['year', $year, $grade, null], ['pooled', 'POOLED', $grade, null],
                ['entrants', $year, $grade, $detail['entrant_count']], ['race_class', $year, $grade, $detail['race_class']],
                ['stage', $year, $grade, $detail['stage']]];
            foreach ($keys as $key) {
                $index = Files::canonical($key);
                $cells[$index] ??= $this->empty($key);
                $cell = &$cells[$index];
                $cell['races']++;
                if ($meta['meeting_id'] !== null) {
                    $cell['meeting_ids'][$meta['meeting_id']] = true;
                }
                foreach ($values as $metric => $value) {
                    foreach (['hits', 'denominator', 'eligible_races', 'excluded_races'] as $field) {
                        $cell['metrics'][$metric][$field] += $value[$field];
                    }
                    if ($value['exclusion_reason'] !== null) {
                        $reasons = &$cell['metrics'][$metric]['exclusion_reasons'];
                        $reasons[$value['exclusion_reason']] = ($reasons[$value['exclusion_reason']] ?? 0) + 1;
                        unset($reasons);
                    }
                }
                unset($cell);
            }
            yield $detail;
        }
        foreach ([2024, 2025] as $year) {
            if (($reference[$year]['race_count'] ?? null) !== ($counts[$year] ?? 0)) {
                throw new RuntimeException('Original year race count mismatch.');
            }
            foreach (Contract::METRICS as $key => $metric) {
                $total = $totals[$year][$key] ?? ['hits' => 0, 'denominator' => 0];
                if ($total['denominator'] !== (int) $reference[$year]['denominators'][$metric]
                    || ($total['denominator'] > 0 && (float) $total['hits'] / $total['denominator'] !== (float) $reference[$year]['candidate'][$metric])) {
                    throw new RuntimeException('Original unrounded evaluation mismatch.');
                }
                $partition = array_filter($cells, fn ($c) => $c['dimension'] === 'year' && $c['year'] === $year);
                foreach (['hits', 'denominator'] as $field) {
                    if (array_sum(array_map(fn ($c) => $c['metrics'][$key][$field], $partition)) !== $total[$field]) {
                        throw new RuntimeException('Meeting partition conservation failed.');
                    }
                }
            }
        }
        ksort($cells, SORT_STRING);
        foreach ($cells as &$cell) {
            $cell['meetings'] = count($cell['meeting_ids']);
            unset($cell['meeting_ids']);
            foreach ($cell['metrics'] as $key => &$value) {
                if ($cell['races'] !== $value['eligible_races'] + $value['excluded_races']
                    || $value['denominator'] !== $value['eligible_races'] * ($key === 'H3' ? 3 : 1)) {
                    throw new RuntimeException('Metric denominator conservation failed.');
                }
                ksort($value['exclusion_reasons']);
                $value += $key === 'H3'
                    ? ['hit_rate' => $value['denominator'] ? (float) $value['hits'] / $value['denominator'] : null,
                        'ci_lower' => null, 'ci_upper' => null, 'status' => 'CI_NOT_COMPUTED']
                    : RiderAggregator::interval($value['hits'], $value['denominator']);
            }
            unset($value);
        }
        unset($cell);
        foreach ($coverage as &$reasons) {
            ksort($reasons);
        }
        unset($reasons);
        $summary = ['races' => count($seen), 'year_counts' => $counts, 'year_totals' => $totals,
            'all_grade_rollup_verified' => true, 'coverage' => $coverage, 'cells' => array_values($cells),
            'pooled_label' => 'COUNT_WEIGHTED', 'hit_at_3_ci' => 'NOT_COMPUTED',
            'wilson_caveat' => 'REFERENCE_ONLY_PLAYER_AND_MEETING_CORRELATION_UNADJUSTED', 'gate' => 'NOT_APPLICABLE'];
    }

    private function empty(array $key): array
    {
        $metrics = [];
        foreach (Contract::METRICS as $name => $_) {
            $metrics[$name] = ['hits' => 0, 'denominator' => 0, 'eligible_races' => 0, 'excluded_races' => 0, 'exclusion_reasons' => []];
        }

        return ['dimension' => $key[0], 'year' => $key[1], 'grade' => $key[2], 'stratum' => $key[3],
            'races' => 0, 'meeting_ids' => [], 'metrics' => $metrics];
    }
}
