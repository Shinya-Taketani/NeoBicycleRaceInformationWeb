<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\Stat35C1Diagnostic;

use App\Domain\Keirin\Backtest\Calculators\Bt03e03CompensatedSum;
use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\LoadedModel as C2Model;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\LoadedModel as C1Model;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use App\Domain\Keirin\Statistics\AgariC1Input\Contract as Input;
use RuntimeException;

final class Utility
{
    public function __construct(private readonly EffectBinBuilder $bins) {}

    /** No labels or predictions cross this calculation boundary. */
    public function entry(array $entry, int|float|null $mean, C1Model $c1, C2Model $c2): array
    {
        Input::keys($entry, Input::ENTRY_KEYS);
        $values = [...$entry['signals'], ...$entry['history']];
        $first = $c1->layout->assign($values, $this->bins);
        $second = $c2->layout->assign([...$values, $mean], $this->bins);
        $meanBins = $c2->layout->canonicalBins()['STAT35_MEAN6'];
        $bin = $meanBins === [] ? null : $this->bins->assign(array_map(fn ($b) => new EffectBinDto(
            $b['index'], $b['kind'], $b['lower_bound'], $b['upper_bound'], $b['category_value'], $b['training_support']), $meanBins), $mean);
        $parameter = $second[16];
        $state = $mean === null ? 'NULL_INACTIVE' : ($parameter !== null ? 'ACTIVE' :
            ($meanBins === [] ? 'NO_TRAINING_BINS' : ($bin === 0 ? 'UNSEEN_CATEGORY' : 'UNSUPPORTED_BIN')));
        $positions = [];
        foreach (Bt03e03Contract::POSITIONS as $position) {
            $a = (float) $entry['anchor'];
            $left = $this->terms($first, $c1->fit->coefficients[$position]);
            $right = $this->terms($second, $c2->fit->coefficients[$position]);
            $existing1 = $this->sum($left);
            $existing2 = $this->sum(array_slice($right, 0, 16));
            $direct = $right[16] ?? 0.0;
            // Skip inactive bins just as ProbabilityScorer::utility does; do not add artificial zeros.
            $u1 = $this->sum([$a, ...array_values(array_filter($left, fn ($v) => $v !== null))]);
            $u2 = $this->sum([$a, ...array_values(array_filter($right, fn ($v) => $v !== null))]);
            $deltaExisting = $existing2 - $existing1;
            $delta = $u2 - $u1;
            $scale = abs($a) + array_sum(array_map(fn ($v) => abs($v ?? 0.0), [...$left, ...$right]));
            $bound = Contract::ROUNDING_ULPS * PHP_FLOAT_EPSILON * max(1.0, $scale);
            $residuals = ['c1_regrouping' => $u1 - ($a + $existing1),
                'c2_regrouping' => $u2 - ($a + $existing2 + $direct),
                'delta_decomposition' => $delta - ($deltaExisting + $direct)];
            foreach ($residuals as $residual) {
                if (! is_finite($residual) || abs($residual) > $bound) {
                    throw new RuntimeException('Utility decomposition exceeded the frozen rounding bound.');
                }
            }
            $positions[$position] = ['anchor' => $a, 'c1_existing' => $existing1, 'c2_existing' => $existing2,
                'c1_utility' => $u1, 'c2_utility' => $u2, 'delta_existing' => $deltaExisting,
                'delta_stat35' => $direct, 'delta_total' => $delta,
                'mean6_coefficient' => $parameter === null ? null : $c2->fit->coefficients[$position][$parameter],
                'mean6_active_zero_coefficient' => $parameter !== null && $direct === 0.0,
                'residuals' => $residuals, 'rounding_bound' => $bound];
        }

        return ['id' => $entry['id'], 'bike' => $entry['bike'], 'mean6' => $mean, 'mean6_state' => $state,
            'mean6_bin_index' => $bin, 'mean6_parameter_index' => $parameter,
            'c1_parameter_indexes' => $first, 'c2_parameter_indexes' => $second, 'positions' => $positions];
    }

    public function verifySaved(array $detail, array $c1, array $c2): array
    {
        foreach (['C1' => $c1, 'C2' => $c2] as $name => $saved) {
            if ($saved['id'] !== $detail['id'] || $saved['bike'] !== $detail['bike']) {
                throw new RuntimeException('Saved utility identity differed.');
            }
            Input::keys($saved['utilities'], Bt03e03Contract::POSITIONS);
            foreach ($detail['positions'] as $position => &$values) {
                $value = $saved['utilities'][$position];
                if ((! is_int($value) && ! is_float($value)) || ! is_finite($value)
                    || (float) $value !== $values[strtolower($name).'_utility']) {
                    throw new RuntimeException('Saved '.$name.' utility did not match exactly.');
                }
                $values[strtolower($name).'_saved_residual'] = 0.0;
            }
            unset($values);
        }

        return $detail;
    }

    public function ledger(int $year, string $name, C1Model|C2Model $model): \Generator
    {
        foreach (Bt03e03Contract::POSITIONS as $position) {
            yield ['year' => $year, 'model' => $name, 'position' => $position, 'feature' => 'STAT01_ANCHOR', 'bin_index' => null,
                'kind' => 'FIXED_ANCHOR', 'lower_bound' => null, 'upper_bound' => null, 'category_value' => null,
                'training_support' => null, 'support_weight' => null, 'active_parameter_index' => null, 'coefficient' => 1.0];
            foreach ($model->layout->canonicalBins() as $feature => $bins) {
                $active = 0;
                foreach ($bins === [] ? [null] : $bins as $bin) {
                    $parameter = $bin !== null && $bin['training_support'] > 0 ? $model->layout->groups()[$feature][$active++] : null;
                    yield ['year' => $year, 'model' => $name, 'position' => $position, 'feature' => $feature,
                        'bin_index' => $bin['index'] ?? null, 'kind' => $bin['kind'] ?? 'NO_TRAINING_BINS',
                        'lower_bound' => $bin['lower_bound'] ?? null, 'upper_bound' => $bin['upper_bound'] ?? null,
                        'category_value' => $bin['category_value'] ?? null, 'training_support' => $bin['training_support'] ?? 0,
                        'support_weight' => $parameter === null ? null : $model->layout->supportWeights()[$parameter],
                        'active_parameter_index' => $parameter,
                        'coefficient' => $parameter === null ? null : $model->fit->coefficients[$position][$parameter]];
                }
            }
        }
    }

    private function terms(array $indexes, array $coefficients): array
    {
        return array_map(static fn ($index) => $index === null ? null : $coefficients[$index], $indexes);
    }

    private function sum(array $values): float
    {
        $sum = new Bt03e03CompensatedSum;
        foreach ($values as $value) {
            if ($value !== null) {
                $sum->add($value);
            }
        }

        return $sum->value();
    }
}
