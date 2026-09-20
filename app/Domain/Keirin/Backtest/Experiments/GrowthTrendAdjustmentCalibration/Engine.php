<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthTrendAdjustmentCalibration;

use App\Domain\Keirin\Backtest\Experiments\GrowthAdjustmentCalibration\Metrics;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\LoadedModel;
use Generator;
use RuntimeException;

final class Engine
{
    public function __construct(private readonly Adjustment $adjustment, private readonly Metrics $metrics) {}

    private function rows(int $year, string $input, array $paths, TemporalAccess $access): Generator
    {
        $access->authorize($year, 'OUTCOME_FILE_OPEN');
        $predictions = JsonlArtifact::read($paths['prediction']);
        $labels = JsonlArtifact::read($paths['labels']);
        foreach (JsonlArtifact::read($input) as $row) {
            if ($row['race']['year'] !== $year || ! $predictions->valid() || ! $labels->valid()
                || $predictions->current()['probabilities']['race_id'] !== $row['race']['race_id']) {
                throw new RuntimeException('Incomplete evaluation universe.');
            }
            yield [$row, $predictions->current(), Metrics::context($labels->current(), $row['race'])];
            $predictions->next();
            $labels->next();
        }
        if ($predictions->valid() || $labels->valid()) {
            throw new RuntimeException('Extra evaluation source.');
        }
        $access->authorize($year, 'OUTCOME_STREAM_VERIFIED');
    }

    public function baseline(int $year, string $input, array $paths, TemporalAccess $access): array
    {
        $access->authorize($year, 'BASELINE_START');
        $stored = JsonlArtifact::read($paths['contributions']);
        $total = Metrics::empty();
        $n = 0;
        foreach ($this->rows($year, $input, $paths, $access) as [$row, $base, $context]) {
            if (! $stored->valid() || $stored->current()['race_id'] !== $row['race']['race_id']) {
                throw new RuntimeException('Missing baseline contribution.');
            }
            $value = $this->metrics->contribution($context, $base);
            Files::same(array_intersect_key($stored->current()['C1-STAT01']['candidate'], array_flip(Contract::METRICS)), $value, 'w=0 frozen contributions');
            Metrics::add($total, $value);
            $n++;
            $stored->next();
        }
        if ($stored->valid()) {
            throw new RuntimeException('Extra baseline contributions.');
        }

        return ['races' => $n, 'metrics' => $total, 'rates' => self::finish($total, $total)];
    }

    public static function finish(array $total, array $base): array
    {
        $out = Metrics::finish($total, $base);
        foreach ($out as &$v) {
            $v['delta_pp'] = $v['delta'] === null ? null : 100 * $v['delta'];
        }
        unset($v);

        return $out;
    }

    public static function changes(array $b, array $p): array
    {
        $bs = $b;
        $ps = $p;
        sort($bs);
        sort($ps);

        return ['position_1' => (int) ($b[0] !== $p[0]), 'position_2' => (int) ($b[1] !== $p[1]),
            'position_3' => (int) ($b[2] !== $p[2]), 'any_primary' => (int) ($b !== $p),
            'exact_same_primary' => (int) ($b === $p), 'top3_set' => (int) ($bs !== $ps)];
    }

    public function curve(int $year, string $input, array $paths, LoadedModel $model, float $scale, array $baseline, TemporalAccess $access, ?int $selected = null): array
    {
        $access->authorize($year, $selected === null ? 'GRID_START' : 'FIXED_TRANSFER_START');
        $grid = $selected === null ? Contract::grid() : array_values(array_filter(Contract::grid(), fn ($c) => $c['k'] === $selected));
        if ($grid === []) {
            throw new RuntimeException('Invalid fixed transfer coefficient.');
        }
        $totals = $changed = $hash = [];
        foreach ($grid as $c) {
            $totals[$c['k']] = Metrics::empty();
            $changed[$c['k']] = array_fill_keys(array_keys(self::changes([1, 2, 3], [1, 2, 3])), 0);
            $hash[$c['k']] = hash_init('sha256');
        }
        $baseTotal = Metrics::empty();
        $n = 0;
        foreach ($this->rows($year, $input, $paths, $access) as [$row, $base, $context]) {
            $bv = $this->metrics->contribution($context, $base);
            Metrics::add($baseTotal, $bv);
            $b = Adjustment::primary($base);
            $unchanged = count(array_filter($row['growth'], fn ($g) => $g['raw'] !== null && $g['raw'] != 0)) === 0;
            foreach ($grid as $c) {
                $k = $c['k'];
                $prediction = $k === 0 || $unchanged ? $base : $this->adjustment->predict($row, $k, $scale, $model->fit);
                $p = Adjustment::primary($prediction);
                Metrics::add($totals[$k], $k === 0 || $unchanged ? $bv : $this->metrics->contribution($context, $prediction));
                foreach (self::changes($b, $p) as $key => $count) {
                    $changed[$k][$key] += $count;
                }
                hash_update($hash[$k], Files::canonical(['year' => $year, 'race_id' => $row['race']['race_id'], 'primary' => $p])."\n");
            }
            if (++$n % 1000 === 0) {
                echo json_encode(['phase' => 'CURVE', 'year' => $year, 'races' => $n, 'candidate_count' => count($grid)])."\n";
            }
        }
        Files::same($baseline, $baseTotal, 'curve baseline');
        $candidates = [];
        foreach ($grid as $c) {
            $candidates[] = $c + ['metrics' => self::finish($totals[$c['k']], $baseline),
                'changes' => $changed[$c['k']], 'primary_semantic_sha256' => hash_final($hash[$c['k']])];
        }

        return ['year' => $year, 'purpose' => $year === 2024 ? 'PARAMETER_SELECTION_DEVELOPMENT' : 'DIAGNOSTIC_ONLY_NOT_FOR_SELECTION', 'races' => $n, 'candidates' => $candidates];
    }

    private static function aggregate(array &$d, array $bv, array $pv, array $changes, int $entries): void
    {
        if ($d === []) {
            $d = ['races' => 0, 'entries' => 0, 'baseline' => Metrics::empty(), 'adjusted' => Metrics::empty(), 'changes' => array_fill_keys(array_keys($changes), 0), 'gain_loss' => []];
        }
        $d['races']++;
        $d['entries'] += $entries;
        Metrics::add($d['baseline'], $bv);
        Metrics::add($d['adjusted'], $pv);
        foreach ($changes as $key => $n) {
            $d['changes'][$key] += $n;
        }
        foreach (Contract::METRICS as $metric) {
            $delta = $pv[$metric]['numerator'] - $bv[$metric]['numerator'];
            $d['gain_loss'][$metric] ??= ['gained' => 0.0, 'lost' => 0.0, 'net' => 0.0];
            $d['gain_loss'][$metric]['gained'] += max(0, $delta);
            $d['gain_loss'][$metric]['lost'] += max(0, -$delta);
            $d['gain_loss'][$metric]['net'] += $delta;
        }
    }

    public function selected(array $inputs, array $paths, array $models, int $k, float $scale, array $marginCuts, TemporalAccess $access, array &$diagnostics): Generator
    {
        $diagnostics = array_fill_keys(['changed-race', 'growth-state', 'growth-magnitude', 'c1-confidence', 'grade-class', 'first-observation'], []);
        foreach (Contract::YEARS as $year) {
            foreach ($this->rows($year, $inputs[$year], $paths[$year], $access) as [$row, $base, $context]) {
                $prediction = $this->adjustment->predict($row, $k, $scale, $models[$year]->fit);
                $b = Adjustment::primary($base);
                $p = Adjustment::primary($prediction);
                $bv = $this->metrics->contribution($context, $base);
                $pv = $this->metrics->contribution($context, $prediction);
                $changes = self::changes($b, $p);
                $details = $groups = [];
                foreach ($row['growth'] as $i => $g) {
                    $norm = Signal::normalized($g, $scale);
                    $state = $norm === null ? 'MISSING' : ($norm === 0.0 ? 'ZERO' : ($norm > 0 ? 'POSITIVE' : 'NEGATIVE'));
                    $groups['growth-state'][$state] = ($groups['growth-state'][$state] ?? 0) + 1;
                    $mag = Signal::magnitude($norm);
                    $groups['growth-magnitude'][$mag] = ($groups['growth-magnitude'][$mag] ?? 0) + 1;
                    $original = $row['race']['entries'][$i]['anchor'];
                    $adjusted = $k === 0 || $norm === null || $norm === 0.0 ? $original : $original + ($k / 100) * $norm;
                    $details[] = $g + ['g_normalized' => $norm, 'adjustment_status' => $norm === null ? 'NO_ADJUSTMENT_'.$g['status'] : ($norm === 0.0 || $k === 0 ? 'NO_ADJUSTMENT_ZERO' : 'ADJUSTED'),
                        'original_anchor' => $original, 'adjusted_anchor' => $adjusted];
                }
                $q = 1;
                foreach ($marginCuts[$year] as $cut) {
                    if ($row['p1_margin'] > $cut) {
                        $q++;
                    }
                }
                $n = count($details);
                $groups['changed-race'] = ['ALL' => $n];
                $groups['c1-confidence'] = ['Q'.$q => $n];
                $groups['grade-class'] = ['GRADE:'.$row['metadata']['grade'] => $n, 'CLASS:'.$row['metadata']['class'] => $n, $row['metadata']['grade'].':'.$row['metadata']['class'] => $n];
                $first = count(array_filter($row['growth'], fn ($g) => $g['first_observation'] === true));
                $groups['first-observation'] = ['ALL' => $n];
                if ($first > 0) {
                    $groups['first-observation']['ANY_ENTRY_TRUE'] = $first;
                }
                if ($first === $n) {
                    $groups['first-observation']['ALL_ENTRIES_TRUE'] = $n;
                }
                foreach ($groups as $kind => $bins) {
                    foreach ($bins as $group => $entries) {
                        $diagnostics[$kind][$year][$group] ??= [];
                        self::aggregate($diagnostics[$kind][$year][$group], $bv, $pv, $changes, $entries);
                    }
                }
                yield ['year' => $year, 'race_id' => $row['race']['race_id'], 'k' => $k, 'w' => $k / 100,
                    'baseline_primary' => $b, 'adjusted_primary' => $p, 'baseline_metrics' => $bv, 'adjusted_metrics' => $pv,
                    'metadata' => $row['metadata'], 'margin_group' => 'Q'.$q, 'entries' => $details];
            }
        }
        foreach ($diagnostics as &$years) {
            foreach ($years as &$bins) {
                foreach ($bins as &$d) {
                    $d['metrics'] = self::finish($d['adjusted'], $d['baseline']);
                }
            }
        }
        unset($years, $bins, $d);
    }

    public static function pooled(array $a, array $b): array
    {
        $out = \App\Domain\Keirin\Backtest\Experiments\GrowthAdjustmentCalibration\Engine::pooled($a, $b);
        foreach ($out['candidates'] as &$c) {
            foreach ($c['metrics'] as &$v) {
                $v['delta_pp'] = 100 * $v['delta'];
            }
        }
        unset($c, $v);

        return $out;
    }
}
