<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03eDirectionRuleBuilder;
use App\Domain\Keirin\Backtest\Services\Bt03eContract;
use Tests\TestCase;

class Bt03eDirectionRuleBuilderTest extends TestCase
{
    public function test_direction_strength_uses_all_three_available_centered_effects(): void
    {
        $rows = [];
        foreach (Bt03eContract::STAT_CODES as $offset => $statCode) {
            $rows = [...$rows, ...$this->labels($statCode, 1, 0.2, 0.1, 0.3, kind: $statCode === 'STAT-11' ? 'CATEGORY' : 'NUMERIC_RANGE')];
        }
        $rules = (new Bt03eDirectionRuleBuilder)->build($rows);

        $this->assertCount(12, $rules);
        $this->assertSame(2, $rules[0]->directionStrength);
        $this->assertSame('NUMERIC_RANGE', $rules[0]->binKind);
        $category = array_values(array_filter($rules, fn ($rule): bool => $rule->statCode === 'STAT-11'))[0];
        $this->assertSame('CATEGORY', $category->binKind);
        $this->assertSame('A', $category->categoryValue);
    }

    public function test_direction_strength_supports_positive_negative_weak_and_zero_rules(): void
    {
        $rows = [];
        foreach (Bt03eContract::STAT_CODES as $offset => $statCode) {
            $rows = [...$rows, ...match ($offset) {
                0 => $this->labels($statCode, 1, 0.2, 0.1, 0.3),
                1 => $this->labels($statCode, 1, 0.2, -0.1, 0.3),
                2 => $this->labels($statCode, 1, -0.2, -0.3, -0.1),
                3 => $this->labels($statCode, 1, -0.2, -0.3, 0.1),
                4 => $this->mixedLabels($statCode),
                5 => $this->labels($statCode, 1, 0.2, null, null, 'NUMERIC_RANGE', 'SPARSE_BOOTSTRAP_UNSUPPORTED'),
                default => $this->labels($statCode, 1, 0.2, 0.1, 0.3),
            }];
        }
        $rules = (new Bt03eDirectionRuleBuilder)->build($rows);

        $this->assertSame([2, 1, -2, -1, 0, 0], array_slice(array_column($rules, 'directionStrength'), 0, 6));
    }

    /** @return list<object> */
    private function labels(string $statCode, int $bin, float $mean, ?float $lower, ?float $upper, string $kind = 'NUMERIC_RANGE', string $status = 'AVAILABLE'): array
    {
        return array_map(fn (string $label): object => (object) [
            'stat_code' => $statCode,
            'label_code' => $label,
            'bin_index' => $bin,
            'bin_origin' => 'TRAINING_BIN',
            'bin_kind' => $kind,
            'lower_bound' => $kind === 'NUMERIC_RANGE' ? null : null,
            'upper_bound' => $kind === 'NUMERIC_RANGE' ? 1.0 : null,
            'category_value' => $kind === 'CATEGORY' ? 'A' : null,
            'source_backtest_effect_bin_id' => 1000 + array_search($statCode, Bt03eContract::STAT_CODES, true),
            'boundaries_hash' => str_repeat(dechex((array_search($statCode, Bt03eContract::STAT_CODES, true) % 15) + 1), 64),
            'training_sample_count' => 100,
            'centered_ci_status' => $status,
            'centered_baseline_residual_mean' => $mean,
            'centered_baseline_residual_ci_lower' => $lower,
            'centered_baseline_residual_ci_upper' => $upper,
        ], Bt03eContract::LABELS);
    }

    /** @return list<object> */
    private function mixedLabels(string $statCode): array
    {
        $rows = $this->labels($statCode, 1, 0.2, 0.1, 0.3);
        $rows[1]->centered_baseline_residual_mean = -0.2;

        return $rows;
    }
}
