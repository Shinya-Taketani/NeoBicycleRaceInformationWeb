<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthAdjustmentCalibration;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\LoadedModel;
use Generator;
use RuntimeException;

final class Engine
{
    public function __construct(private readonly Adjustment $adjustment, private readonly Metrics $metrics) {}

    public function curve(int $year, string $input, array $paths, LoadedModel $model, array $baseline, ?string $selectionPath = null, ?array $seal = null): array
    {
        if (! in_array($year, Contract::YEARS, true)) {
            throw new RuntimeException('Forbidden curve year.');
        }
        if ($year === 2025) {
            if ($selectionPath === null || $seal === null) {
                throw new RuntimeException('Selection must be sealed before candidate validation.');
            }
            Files::verify($selectionPath, $seal);
        }
        $totals = $changed = $hashes = [];
        foreach (range(-50, 50) as $k) {
            $totals[$k] = Metrics::empty();
            $changed[$k] = ['position_1' => 0, 'position_2' => 0, 'position_3' => 0, 'any_primary' => 0, 'exact_same_primary' => 0];
            $hashes[$k] = hash_init('sha256');
        }
        $n = 0;
        foreach ($this->rows($input, $paths) as [$row, $base, $context]) {
            $primary = Adjustment::primary($base);
            $unchanged = count(array_filter($row['growth'], fn ($g) => $g['score_point'] !== null && $g['score_point'] !== 0)) === 0;
            $baseValues = $this->metrics->contribution($context, $base);
            foreach (range(-50, 50) as $k) {
                // This shortcut is exact only when every anchor is byte-for-byte unchanged.
                $prediction = $k === 0 || $unchanged ? $base : $this->adjustment->predict($row['race'], $row['growth'], $k, $model->fit);
                $p = Adjustment::primary($prediction);
                $values = $k === 0 || $unchanged ? $baseValues : $this->metrics->contribution($context, $prediction);
                Metrics::add($totals[$k], $values);
                foreach ([0, 1, 2] as $i) {
                    $changed[$k]['position_'.($i + 1)] += $p[$i] !== $primary[$i] ? 1 : 0;
                }
                $changed[$k]['any_primary'] += $p !== $primary ? 1 : 0;
                $changed[$k]['exact_same_primary'] += $p === $primary ? 1 : 0;
                hash_update($hashes[$k], Files::canonical(['year' => $year, 'race_id' => $row['race']['race_id'], 'primary' => $p])."\n");
            }
            $n++;
            if ($n % 1000 === 0) {
                echo json_encode(['phase' => 'CURVE', 'year' => $year, 'races' => $n])."\n";
            }
        }
        Files::same($baseline, $totals[0], 'curve baseline reproduction');
        $candidates = [];
        foreach (Contract::grid() as $candidate) {
            $k = $candidate['k'];
            $candidates[] = $candidate + ['metrics' => Metrics::finish($totals[$k], $baseline), 'changes' => $changed[$k],
                'primary_semantic_sha256' => hash_final($hashes[$k])];
        }
        if ($year === 2025) {
            Files::verify($selectionPath, $seal);
        }

        return ['year' => $year, 'purpose' => $year === 2024 ? 'CALIBRATION' : 'DIAGNOSTIC_ONLY_NOT_FOR_WEIGHT_SELECTION', 'races' => $n, 'candidates' => $candidates];
    }

    public function selected(array $inputs, array $paths, array $models, int $k, array &$diagnostics): Generator
    {
        $diagnostics = ['years' => [], 'strata' => [], 'movement_semantics' => 'PRIMARY_POSITION_1_2_3_OR_OUTSIDE_TOP3'];
        foreach (Contract::YEARS as $year) {
            $d = ['races' => 0, 'entries' => 0, 'zero' => 0, 'missing' => 0, 'same_zero' => 0, 'anchor_violations' => 0,
                'movement_by_point' => [], 'changed' => ['any_primary' => 0, 'position_1' => 0, 'position_2' => 0, 'position_3' => 0],
                'gain_loss' => [], 'baseline' => Metrics::empty(), 'adjusted' => Metrics::empty()];
            foreach ($this->rows($inputs[$year], $paths[$year]) as [$row, $base, $context]) {
                $adjusted = $this->adjustment->apply($row['race'], $row['growth'], $k);
                $prediction = $adjusted === $row['race'] ? $base : $this->adjustment->predict($row['race'], $row['growth'], $k, $models[$year]->fit);
                $b = Adjustment::primary($base);
                $p = Adjustment::primary($prediction);
                $bv = $this->metrics->contribution($context, $base);
                $pv = $this->metrics->contribution($context, $prediction);
                Metrics::add($d['baseline'], $bv);
                Metrics::add($d['adjusted'], $pv);
                $d['races']++;
                $changed = ['any_primary' => $b !== $p];
                foreach ([0, 1, 2] as $i) {
                    $changed['position_'.($i + 1)] = $b[$i] !== $p[$i];
                }
                foreach ($changed as $group => $isChanged) {
                    $d['changed'][$group] += $isChanged ? 1 : 0;
                    foreach (Contract::METRICS as $metric) {
                        $d['gain_loss'][$group][$metric] ??= ['gained_hits' => 0.0, 'lost_hits' => 0.0, 'net_hits' => 0.0, 'eligible_denominator' => 0.0];
                        if ($isChanged) {
                            $delta = $pv[$metric]['numerator'] - $bv[$metric]['numerator'];
                            $d['gain_loss'][$group][$metric]['gained_hits'] += max(0, $delta);
                            $d['gain_loss'][$group][$metric]['lost_hits'] += max(0, -$delta);
                            $d['gain_loss'][$group][$metric]['net_hits'] += $delta;
                            $d['gain_loss'][$group][$metric]['eligible_denominator'] += $pv[$metric]['denominator'];
                        }
                    }
                }
                $details = [];
                foreach ($row['race']['entries'] as $i => $entry) {
                    $g = $row['growth'][$i];
                    $point = $g['score_point'];
                    $from = array_search($entry['bike'], $b, true);
                    $to = array_search($entry['bike'], $p, true);
                    $from = $from === false ? 4 : $from + 1;
                    $to = $to === false ? 4 : $to + 1;
                    $status = $point === null ? 'GROWTH_MISSING_NO_ADJUSTMENT' : ($point === 0 ? 'GROWTH_ZERO_NO_ADJUSTMENT' : 'GROWTH_ADJUSTED');
                    $d['entries']++;
                    $d['missing'] += $point === null ? 1 : 0;
                    $d['zero'] += $point === 0 ? 1 : 0;
                    $d['same_zero'] += $g['same_meeting_previous'] === true ? 1 : 0;
                    if (($k === 0 || $point === null || $point === 0) && $entry['anchor'] !== $adjusted['entries'][$i]['anchor']) {
                        throw new RuntimeException('Zero/missing anchor changed.');
                    }
                    $key = $point ?? 'MISSING';
                    $d['movement_by_point'][$key] ??= ['up' => 0, 'down' => 0, 'same' => 0];
                    $d['movement_by_point'][$key][$to < $from ? 'up' : ($to > $from ? 'down' : 'same')]++;
                    $details[] = ['entry_id' => $entry['id'], 'player_id' => $g['player_id'], 'bike' => $entry['bike'], 'point' => $point,
                        'status' => $status, 'same_meeting_previous' => $g['same_meeting_previous'], 'original_anchor' => $entry['anchor'],
                        'adjusted_anchor' => $adjusted['entries'][$i]['anchor'], 'primary_before' => $from, 'primary_after' => $to];
                }
                foreach (['grade', 'class'] as $dimension) {
                    $key = $year.':'.$dimension.':'.$row['metadata'][$dimension];
                    $diagnostics['strata'][$key] ??= ['year' => $year, 'dimension' => $dimension, 'value' => $row['metadata'][$dimension],
                        'races' => 0, 'baseline' => Metrics::empty(), 'adjusted' => Metrics::empty()];
                    $s = &$diagnostics['strata'][$key];
                    $s['races']++;
                    Metrics::add($s['baseline'], $bv);
                    Metrics::add($s['adjusted'], $pv);
                    unset($s);
                }
                yield ['year' => $year, 'race_id' => $row['race']['race_id'], 'k' => $k, 'metadata' => $row['metadata'],
                    'baseline_primary' => $b, 'adjusted_primary' => $p, 'baseline_metrics' => $bv, 'adjusted_metrics' => $pv, 'entries' => $details];
            }
            $diagnostics['years'][$year] = $d;
        }
    }

    public static function pooled(array $a, array $b): array
    {
        $rows = [];
        foreach ($a['candidates'] as $i => $left) {
            $right = $b['candidates'][$i];
            $total = $base = Metrics::empty();
            foreach (Contract::METRICS as $metric) {
                $l = $left['metrics'][$metric];
                $r = $right['metrics'][$metric];
                $total[$metric] = ['numerator' => $l['numerator'] + $r['numerator'], 'denominator' => $l['denominator'] + $r['denominator']];
                $base[$metric] = ['numerator' => $l['baseline_numerator'] + $r['baseline_numerator'], 'denominator' => $total[$metric]['denominator']];
            }
            $changes = [];
            foreach ($left['changes'] as $key => $v) {
                $changes[$key] = $v + $right['changes'][$key];
            }
            $rows[] = ['k' => $left['k'], 'w' => $left['w'], 'metrics' => Metrics::finish($total, $base), 'changes' => $changes];
        }

        return ['year' => '2024-2025', 'purpose' => 'COUNT_WEIGHTED_DIAGNOSTIC_ONLY_NOT_FOR_SELECTION', 'races' => $a['races'] + $b['races'], 'candidates' => $rows];
    }

    private function rows(string $input, array $paths): Generator
    {
        $predictions = JsonlArtifact::read($paths['prediction']);
        $labels = JsonlArtifact::read($paths['labels']);
        $predictions->rewind();
        $labels->rewind();
        foreach (JsonlArtifact::read($input) as $row) {
            if (! $predictions->valid() || ! $labels->valid() || $predictions->current()['probabilities']['race_id'] !== $row['race']['race_id']) {
                throw new RuntimeException('Incomplete curve universe.');
            }
            yield [$row, $predictions->current(), Metrics::context($labels->current(), $row['race'])];
            $predictions->next();
            $labels->next();
        }
        if ($predictions->valid() || $labels->valid()) {
            throw new RuntimeException('Extra curve source rows.');
        }
    }
}
