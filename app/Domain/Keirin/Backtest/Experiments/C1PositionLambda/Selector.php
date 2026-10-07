<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1PositionLambda;

use App\Domain\Keirin\Backtest\Calculators\Bt03e03CompensatedSum;
use App\Domain\Keirin\Backtest\Calculators\DeterministicRandom;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use RuntimeException;

final class Selector
{
    public function select(array $years, string $position, int $iterations = Bt03e03Contract::BOOTSTRAP_ITERATIONS): array
    {
        if ($years === [] || $iterations < 2 || ! in_array($position, Bt03e03Contract::POSITIONS, true)) {
            throw new RuntimeException('Invalid per-position One-SE input.');
        }
        ksort($years, SORT_NUMERIC);
        $canonical = array_map(LossSpool::key(...), Bt03e03Contract::LAMBDA_GRID);
        $available = $canonical;
        $audit = $byYear = [];
        foreach ($years as $year => $spool) {
            if (! in_array($year, [2023, 2024], true) || $spool->position !== $position) {
                throw new RuntimeException('Loss year/position mismatch.');
            }
            $spool->verify();
            $audit[$year] = $spool->audit();
            $available = array_values(array_intersect($available, $spool->available));
        }
        if ($available === []) {
            throw new RuntimeException('No converged common per-position lambda candidate.');
        }
        foreach ($years as $year => $spool) {
            $byYear[$year] = $this->aggregate($spool, array_fill(0, $spool->audit()['races'], 1), $available);
        }
        $point = $samples = [];
        foreach ($available as $key) {
            $point[$key] = array_sum(array_column($byYear, $key)) / count($years);
            $samples[$key] = [];
        }
        $random = new DeterministicRandom(Bt03e03Contract::BOOTSTRAP_SEED);
        for ($i = 0; $i < $iterations; $i++) {
            $replicate = array_fill_keys($available, []);
            foreach ($years as $spool) {
                $count = $spool->audit()['races'];
                $weights = array_fill(0, $count, 0);
                for ($draw = 0; $draw < $count; $draw++) {
                    $weights[$random->integer($count)]++;
                }
                foreach ($this->aggregate($spool, $weights, $available) as $key => $value) {
                    $replicate[$key][] = $value;
                }
            }
            foreach ($available as $key) {
                $samples[$key][] = array_sum($replicate[$key]) / count($years);
            }
        }
        $se = [];
        foreach ($samples as $key => $values) {
            $mean = array_sum($values) / count($values);
            $squares = new Bt03e03CompensatedSum;
            foreach ($values as $value) {
                $squares->add(($value - $mean) ** 2);
            }
            $se[$key] = sqrt($squares->value() / (count($values) - 1));
        }
        $choice = self::choose($available, $point, $se);
        foreach ($years as $spool) {
            $spool->verify();
        }

        return ['version' => Contract::SELECTOR_VERSION, 'position' => $position, ...$choice,
            'point_losses' => $point, 'standard_errors' => $se, 'year_losses' => $byYear,
            'eligible_lambda_keys' => $available, 'excluded_lambda_keys' => array_values(array_diff($canonical, $available)),
            'denominator_audit' => $audit, 'bootstrap_iterations' => $iterations, 'bootstrap_seed' => Bt03e03Contract::BOOTSTRAP_SEED];
    }

    public static function choose(array $available, array $point, array $se): array
    {
        if ($available === []) {
            throw new RuntimeException('Empty One-SE candidates.');
        }
        $best = $available[0];
        foreach ($available as $key) {
            foreach ([$point[$key] ?? null, $se[$key] ?? null] as $v) {
                if ((! is_float($v) && ! is_int($v)) || ! is_finite($v) || $v < 0) {
                    throw new RuntimeException('Invalid One-SE estimate.');
                }
            }
            if ($point[$key] < $point[$best]) {
                $best = $key;
            }
        }
        $threshold = $point[$best] + $se[$best];
        $selected = $best;
        foreach ($available as $key) {
            if ($point[$key] <= $threshold) {
                $selected = $key;
            }
        }

        return ['selected_lambda' => (float) $selected, 'lambda_best' => (float) $best, 'one_se_threshold' => $threshold];
    }

    public static function validate(array $selection, string $position): void
    {
        $canonical = array_map(LossSpool::key(...), Bt03e03Contract::LAMBDA_GRID);
        $keys = $selection['eligible_lambda_keys'] ?? [];
        if (($selection['version'] ?? null) !== Contract::SELECTOR_VERSION || ($selection['position'] ?? null) !== $position
            || $keys === [] || $keys !== array_values(array_intersect($canonical, $keys))
            || ($selection['excluded_lambda_keys'] ?? []) !== array_values(array_diff($canonical, $keys))
            || ($selection['bootstrap_iterations'] ?? null) !== Bt03e03Contract::BOOTSTRAP_ITERATIONS
            || ($selection['bootstrap_seed'] ?? null) !== Bt03e03Contract::BOOTSTRAP_SEED
            || array_map('strval', array_keys($selection['point_losses'] ?? [])) !== $keys
            || array_map('strval', array_keys($selection['standard_errors'] ?? [])) !== $keys) {
            throw new RuntimeException('Invalid saved per-position selection.');
        }
        $choice = self::choose($keys, $selection['point_losses'], $selection['standard_errors']);
        foreach ($choice as $key => $value) {
            if (($selection[$key] ?? null) !== $value) {
                throw new RuntimeException('Saved One-SE choice mismatch.');
            }
        }
        $years = $selection['year_losses'] ?? [];
        if (! in_array(array_keys($years), [[2023], [2023, 2024]], true)
            || array_keys($selection['denominator_audit'] ?? []) !== array_keys($years)) {
            throw new RuntimeException('Saved validation year keys disagreed.');
        }
        foreach ($years as $year => $losses) {
            $audit = $selection['denominator_audit'][$year];
            if (array_map('strval', array_keys($losses)) !== $keys || ($audit['position'] ?? null) !== $position
                || ! is_int($audit['eligible'] ?? null) || $audit['eligible'] < 1
                || ! is_int($audit['excluded'] ?? null) || $audit['excluded'] < 0
                || ($audit['races'] ?? null) !== $audit['eligible'] + $audit['excluded']) {
                throw new RuntimeException('Saved eligibility audit disagreed.');
            }
        }
        foreach ($keys as $key) {
            $values = array_column($years, $key);
            foreach ($values as $value) {
                if ((! is_float($value) && ! is_int($value)) || ! is_finite($value) || $value < 0) {
                    throw new RuntimeException('Invalid saved year loss.');
                }
            }
            if ($selection['point_losses'][$key] !== array_sum($values) / count($years)) {
                throw new RuntimeException('Saved year-equal point loss mismatch.');
            }
        }
    }

    private function aggregate(LossSpool $spool, array $weights, array $keys): array
    {
        $offsets = array_flip(array_map(LossSpool::key(...), Bt03e03Contract::LAMBDA_GRID));
        $sums = array_fill_keys($keys, 0.0);
        $denominator = 0;
        $index = 0;
        foreach ($spool->records() as $values) {
            $weight = $weights[$index++];
            if ($values[$offsets[$keys[0]]] !== null) {
                $denominator += $weight;
                foreach ($keys as $key) {
                    $sums[$key] += $weight * $values[$offsets[$key]];
                }
            }
        }
        if ($denominator === 0) {
            throw new RuntimeException('Position bootstrap/validation eligible denominator was zero.');
        }

        return array_map(static fn ($sum) => $sum / $denominator, $sums);
    }
}
