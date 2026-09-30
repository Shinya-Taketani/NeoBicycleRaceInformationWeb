<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\Stat35C1Diagnostic;

use App\Domain\Keirin\Backtest\Calculators\Bt03e03CompensatedSum;

final class UtilitySummary
{
    private array $years = [];

    public function add(int $year, array $entry): void
    {
        $this->years[$year] ??= ['entries' => 0, 'mean6_states' => [], 'numeric_zero' => 0, 'null_existing_changed' => 0,
            'max_abs_residual' => 0.0, 'positions' => []];
        $s = &$this->years[$year];
        $s['entries']++;
        $state = $entry['mean6_state'];
        $s['mean6_states'][$state] = ($s['mean6_states'][$state] ?? 0) + 1;
        $s['numeric_zero'] += (int) ($entry['mean6'] !== null && (float) $entry['mean6'] === 0.0);
        $changed = false;
        foreach ($entry['positions'] as $position => $values) {
            $changed = $changed || $values['delta_existing'] !== 0.0;
            $s['max_abs_residual'] = max($s['max_abs_residual'], ...array_values(array_map('abs', $values['residuals'])));
            foreach (['delta_existing', 'delta_stat35', 'delta_total'] as $key) {
                $s['positions'][$position][$key] ??= ['min' => $values[$key], 'max' => $values[$key], 'sum' => new Bt03e03CompensatedSum,
                    'sum_abs' => new Bt03e03CompensatedSum, 'negative' => 0, 'zero' => 0, 'positive' => 0];
                $v = &$s['positions'][$position][$key];
                $v['min'] = min($v['min'], $values[$key]);
                $v['max'] = max($v['max'], $values[$key]);
                $v['sum']->add($values[$key]);
                $v['sum_abs']->add(abs($values[$key]));
                $v[$values[$key] > 0 ? 'positive' : ($values[$key] < 0 ? 'negative' : 'zero')]++;
                unset($v);
            }
        }
        $s['null_existing_changed'] += (int) ($entry['mean6'] === null && $changed);
    }

    public function finish(): array
    {
        $result = $this->years;
        foreach ($result as &$s) {
            ksort($s['mean6_states']);
            foreach ($s['positions'] as &$position) {
                foreach ($position as &$v) {
                    $v['sum'] = $v['sum']->value();
                    $v['sum_abs'] = $v['sum_abs']->value();
                    $v['mean'] = $v['sum'] / $s['entries'];
                    $v['mean_abs'] = $v['sum_abs'] / $s['entries'];
                }
                unset($v);
            }
            unset($position);
        }
        unset($s);

        return $result;
    }
}
