<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\DTO\Bt03eBinRuleDto;
use App\Domain\Keirin\Backtest\Services\Bt03eContract;
use RuntimeException;

class Bt03eDirectionRuleBuilder
{
    /** @param list<object> $rows @return list<Bt03eBinRuleDto> */
    public function build(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $statCode = (string) ($row->stat_code ?? '');
            $binIndex = (int) ($row->bin_index ?? 0);
            $labelCode = (string) ($row->label_code ?? '');
            if (! in_array($statCode, Bt03eContract::STAT_CODES, true)
                || $binIndex < 1
                || ! in_array($labelCode, Bt03eContract::LABELS, true)
                || isset($grouped[$statCode][$binIndex][$labelCode])) {
                throw new RuntimeException('BT-03E source effect identity was invalid or duplicated.');
            }
            $grouped[$statCode][$binIndex][$labelCode] = $row;
        }

        $rules = [];
        foreach (Bt03eContract::STAT_CODES as $statCode) {
            $bins = $grouped[$statCode] ?? throw new RuntimeException("BT-03E {$statCode} source effects were missing.");
            ksort($bins, SORT_NUMERIC);
            foreach ($bins as $binIndex => $labels) {
                if (array_diff(Bt03eContract::LABELS, array_keys($labels)) !== [] || count($labels) !== 3) {
                    throw new RuntimeException("BT-03E {$statCode} bin {$binIndex} required exactly three labels.");
                }
                $identity = $labels[Bt03eContract::LABELS[0]];
                foreach ($labels as $row) {
                    $this->assertSameIdentity($identity, $row);
                }
                $rules[] = new Bt03eBinRuleDto(
                    $statCode,
                    (int) $binIndex,
                    (string) $identity->bin_origin,
                    (string) $identity->bin_kind,
                    $this->nullableFloat($identity->lower_bound),
                    $this->nullableFloat($identity->upper_bound),
                    $identity->category_value === null ? null : (string) $identity->category_value,
                    (int) $identity->source_backtest_effect_bin_id,
                    (string) $identity->boundaries_hash,
                    (int) $identity->training_sample_count,
                    $this->direction(array_values($labels)),
                );
            }
        }
        if (count($grouped) !== count(Bt03eContract::STAT_CODES)) {
            throw new RuntimeException('BT-03E source effects exceeded the fixed stat contract.');
        }

        return $rules;
    }

    /** @param list<object> $labels */
    private function direction(array $labels): int
    {
        foreach ($labels as $row) {
            if (($row->centered_ci_status ?? null) !== 'AVAILABLE') {
                return 0;
            }
        }
        $means = array_map(fn (object $row): float => $this->requiredFloat($row->centered_baseline_residual_mean), $labels);
        if ($this->all($means, static fn (float $value): bool => $value > 0.0)) {
            $lowers = array_map(fn (object $row): float => $this->requiredFloat($row->centered_baseline_residual_ci_lower), $labels);

            return $this->all($lowers, static fn (float $value): bool => $value > 0.0) ? 2 : 1;
        }
        if ($this->all($means, static fn (float $value): bool => $value < 0.0)) {
            $uppers = array_map(fn (object $row): float => $this->requiredFloat($row->centered_baseline_residual_ci_upper), $labels);

            return $this->all($uppers, static fn (float $value): bool => $value < 0.0) ? -2 : -1;
        }

        return 0;
    }

    private function assertSameIdentity(object $expected, object $actual): void
    {
        foreach (['stat_code', 'bin_index', 'bin_origin', 'bin_kind', 'lower_bound', 'upper_bound', 'category_value', 'source_backtest_effect_bin_id', 'boundaries_hash', 'training_sample_count'] as $field) {
            if (($expected->{$field} ?? null) !== ($actual->{$field} ?? null)) {
                throw new RuntimeException('BT-03E label effects did not share one fixed bin identity.');
            }
        }
        if (($expected->bin_origin ?? null) !== 'TRAINING_BIN'
            || ! in_array($expected->bin_kind ?? null, ['NUMERIC_RANGE', 'CATEGORY'], true)
            || (int) ($expected->source_backtest_effect_bin_id ?? 0) < 1
            || (int) ($expected->training_sample_count ?? 0) < 1
            || preg_match('/\A[0-9a-f]{64}\z/', (string) ($expected->boundaries_hash ?? '')) !== 1) {
            throw new RuntimeException('BT-03E fixed bin identity was invalid.');
        }
    }

    /** @param list<float> $values */
    private function all(array $values, callable $predicate): bool
    {
        return count(array_filter($values, $predicate)) === count($values);
    }

    private function requiredFloat(mixed $value): float
    {
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            throw new RuntimeException('BT-03E centered residual effect was invalid.');
        }

        return (float) $value;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null ? null : $this->requiredFloat($value);
    }
}
