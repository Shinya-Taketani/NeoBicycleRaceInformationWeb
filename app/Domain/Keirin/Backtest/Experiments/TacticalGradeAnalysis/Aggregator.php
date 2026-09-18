<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalGradeAnalysis;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\Matcher;
use Generator;
use RuntimeException;

final class Aggregator
{
    public function __construct(private readonly Matcher $matcher, private readonly Bt03e05MetricEvaluator $metrics) {}

    public static function interval(int $hits, int $eligible): array
    {
        if ($hits < 0 || $eligible < 0 || $hits > $eligible) {
            throw new RuntimeException('Invalid binomial counts.');
        }
        if ($eligible === 0) {
            return ['hit_rate' => null, 'ci_lower' => null, 'ci_upper' => null, 'status' => 'NOT_EVALUABLE'];
        }
        $p = (float) $hits / $eligible;
        $z = 1.959963984540054;
        $center = ($p + $z * $z / (2 * $eligible)) / (1 + $z * $z / $eligible);
        $half = $z * sqrt($p * (1 - $p) / $eligible + $z * $z / (4 * $eligible * $eligible)) / (1 + $z * $z / $eligible);

        return ['hit_rate' => $p, 'ci_lower' => $center - $half, 'ci_upper' => $center + $half, 'status' => 'EVALUABLE'];
    }

    public function details(iterable $rows, array $reference, array &$summary): Generator
    {
        $cells = $this->emptyCells();
        $seen = $entriesSeen = $totals = $coverage = $unexpected = $stageCounts = [];
        $count = $entryCount = 0;
        foreach ($rows as $row) {
            $context = $row['context'];
            Contract::race($context);
            if (isset($seen[$context['race_id']])) {
                throw new RuntimeException('Duplicate analysis race.');
            }
            $seen[$context['race_id']] = true;
            $attributes = $this->attributes($row);
            $this->matcher->result($context);
            $input = $context;
            foreach ($input['entries'] as &$entry) {
                unset($entry['rank'], $entry['status']);
            }
            unset($entry);
            $this->matcher->prediction($input, ['probabilities' => $input, 'decision' => $row['decision']]);
            $comparison = $this->metrics->raceComparison($context, $row['decision']);
            $year = $context['year'];
            $count++;
            $stageCounts[$year][$row['metadata']['stage']] = ($stageCounts[$year][$row['metadata']['stage']] ?? 0) + 1;
            foreach ($attributes as $attribute) {
                if (isset($entriesSeen[$attribute['race_entry_id']])) {
                    throw new RuntimeException('Duplicate analysis entry.');
                }
                $entriesSeen[$attribute['race_entry_id']] = true;
                $entryCount++;
                $state = $attribute['verification_status'];
                $coverage[$year][$state] = ($coverage[$year][$state] ?? 0) + 1;
                if ($attribute['normalized_grade'] === 'UNKNOWN') {
                    $key = $attribute['grade_raw'] === null ? '<NULL>' : $attribute['grade_raw'];
                    $unexpected[$year][$key] = ($unexpected[$year][$key] ?? 0) + 1;
                }
            }
            foreach (Contract::METRICS as $position => $metric) {
                $value = $comparison['candidate'][$metric];
                Files::same($row['saved_contributions'][$position], $value, 'original per-race numerator/denominator');
                $bike = $row['decision']['primary_position_'.$position.'_bike'];
                $attribute = $attributes[$bike];
                $eligible = (int) $value['denominator'];
                $hit = (int) $value['numerator'];
                $detail = ['year' => $year, 'race_id' => $context['race_id'], 'race_date' => $row['race_date'],
                    'entrant_count' => count($attributes), 'stage' => $row['metadata']['stage'], 'position' => $position]
                    + $attribute + ['source' => $row['metadata']['source'], 'selected' => 1, 'eligible' => $eligible,
                        'hits' => $hit, 'misses' => $eligible - $hit, 'excluded' => 1 - $eligible,
                        'exclusion_reason' => $eligible ? null : 'NO_UNIQUE_OFFICIAL_POSITION'];
                foreach ($this->keys($detail) as $key) {
                    $this->add($cells[$key], $detail);
                }
                $totals[$year][$position]['hits'] = ($totals[$year][$position]['hits'] ?? 0) + $hit;
                $totals[$year][$position]['eligible'] = ($totals[$year][$position]['eligible'] ?? 0) + $eligible;
                yield $detail;
            }
        }
        foreach (Contract::YEARS as $year) {
            foreach (Contract::METRICS as $position => $metric) {
                $total = $totals[$year][$position] ?? ['hits' => 0, 'eligible' => 0];
                $saved = $reference[$year] ?? null;
                $byGrade = array_filter($cells, static fn (array $cell): bool => $cell['dimension'] === 'year'
                    && $cell['year'] === $year && $cell['position'] === $position);
                if (array_sum(array_column($byGrade, 'hits')) !== $total['hits']
                    || array_sum(array_column($byGrade, 'eligible')) !== $total['eligible']) {
                    throw new RuntimeException('Grade partition did not conserve original counts.');
                }
                if (! is_array($saved) || $total['eligible'] !== (int) ($saved['denominators'][$metric] ?? -1)
                    || ($total['eligible'] > 0 && (float) $total['hits'] / $total['eligible'] !== (float) $saved['candidate'][$metric])) {
                    throw new RuntimeException('All-grade rollup differs from saved unrounded evaluation.');
                }
            }
        }
        foreach ($cells as &$cell) {
            if ($cell['selected'] !== $cell['eligible'] + $cell['excluded'] || $cell['eligible'] !== $cell['hits'] + $cell['misses']) {
                throw new RuntimeException('Cell count conservation failed.');
            }
            ksort($cell['verification_statuses']);
            ksort($cell['exclusion_reasons']);
            $cell += self::interval($cell['hits'], $cell['eligible']);
        }
        unset($cell);
        foreach ([&$coverage, &$unexpected, &$stageCounts] as &$counts) {
            foreach ($counts as &$byYear) {
                ksort($byYear);
            }
            unset($byYear);
            ksort($counts);
        }
        unset($counts);
        $summary = ['races' => $count, 'entries' => $entryCount, 'position_selections' => 3 * $count,
            'integrity_errors' => 0, 'all_grade_rollup_verified' => true, 'year_totals' => $totals,
            'grade_coverage' => $coverage, 'unknown_raw_counts' => $unexpected, 'stage_counts' => $stageCounts,
            'publication_time_verified' => 'UNKNOWN', 'pooled_weighting' => 'SUM_HITS_DIVIDED_BY_SUM_ELIGIBLE',
            'ci_caveat' => 'INDEPENDENT_BINOMIAL_REFERENCE_ONLY; player/meeting correlation unadjusted; selection-conditioned',
            'adoption_gate' => 'NOT_APPLICABLE', 'cells' => array_values($cells)];
    }

    private function attributes(array $row): array
    {
        $context = $row['context'];
        $entries = $this->matcher->entries($context);
        $meta = $row['metadata'];
        if (($meta['year'] ?? null) !== $context['year'] || ($meta['race_id'] ?? null) !== $context['race_id']
            || ($meta['race_date'] ?? null) !== $row['race_date'] || ! str_starts_with($row['race_date'], $context['year'].'-')
            || ($meta['entrant_count'] ?? null) !== count($entries) || count($meta['entries'] ?? []) !== count($entries)
            || ($meta['stage'] ?? null) !== Grade::stage($meta['race_type_raw'] ?? null)) {
            throw new RuntimeException('Invalid analysis metadata context.');
        }
        $targets = array_column($row['targets'], null, 'id');
        if (count($targets) !== count($entries) || count($row['targets']) !== count($entries)) {
            throw new RuntimeException('Invalid fixed target set.');
        }
        $attributes = [];
        foreach ($meta['entries'] as $attribute) {
            $id = $attribute['race_entry_id'] ?? null;
            $bike = $attribute['bike_number'] ?? null;
            if (! is_int($id) || ! is_int($bike) || ! isset($entries[$id], $targets[$id]) || $entries[$id]['bike'] !== $bike
                || $targets[$id]['bike'] !== $bike || ($attribute['player_id'] ?? null) !== $targets[$id]['player_id']
                || isset($attributes[$bike]) || ! array_key_exists('grade_raw', $attribute)) {
                throw new RuntimeException('Invalid metadata entry ID/bike/player correspondence.');
            }
            foreach (Grade::classify($attribute['grade_raw']) as $key => $value) {
                if (($attribute[$key] ?? null) !== $value) {
                    throw new RuntimeException('Grade normalization evidence disagreed.');
                }
            }
            $attributes[$bike] = $attribute;
        }

        return $attributes;
    }

    private function keys(array $row): array
    {
        $suffix = $row['position'].':'.$row['normalized_grade'];

        return ['year:'.$row['year'].':'.$suffix, 'entrants:'.$row['year'].':'.$row['entrant_count'].':'.$suffix,
            'pooled:'.$suffix, 'stage:'.$row['year'].':'.$row['stage'].':'.$suffix];
    }

    private function emptyCells(): array
    {
        $cells = [];
        foreach (Contract::GRADES as $grade) {
            foreach ([1, 2, 3] as $position) {
                foreach (Contract::YEARS as $year) {
                    foreach (range(5, 9) as $entrants) {
                        foreach (['QUALIFIER', 'SEMIFINAL', 'FINAL', 'UNKNOWN'] as $stage) {
                            $row = ['year' => $year, 'position' => $position, 'normalized_grade' => $grade, 'entrant_count' => $entrants, 'stage' => $stage];
                            foreach ($this->keys($row) as $key) {
                                [$dimension] = explode(':', $key);
                                $cells[$key] = ['dimension' => $dimension, 'year' => $dimension === 'pooled' ? null : $year,
                                    'entrant_count' => $dimension === 'entrants' ? $entrants : null,
                                    'stage' => $dimension === 'stage' ? $stage : null, 'position' => $position, 'grade' => $grade,
                                    'selected' => 0, 'eligible' => 0, 'hits' => 0, 'misses' => 0, 'excluded' => 0,
                                    'exclusion_reasons' => [], 'verification_statuses' => []];
                            }
                        }
                    }
                }
            }
        }
        ksort($cells, SORT_STRING);

        return $cells;
    }

    private function add(array &$cell, array $row): void
    {
        foreach (['selected', 'eligible', 'hits', 'misses', 'excluded'] as $field) {
            $cell[$field] += $row[$field];
        }
        $status = $row['verification_status'];
        $cell['verification_statuses'][$status] = ($cell['verification_statuses'][$status] ?? 0) + 1;
        if ($row['exclusion_reason'] !== null) {
            $reason = $row['exclusion_reason'];
            $cell['exclusion_reasons'][$reason] = ($cell['exclusion_reasons'][$reason] ?? 0) + 1;
        }
    }
}
