<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Experiments\Stat17C1Comparison\Contract;
use App\Domain\Keirin\Backtest\Experiments\Stat17C1Comparison\DiversityProjector as Projector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class Stat17DiversityProjectorTest extends TestCase
{
    #[DataProvider('known')]
    public function test_fixed_formula_missing_semantics_and_no_rounding(array $history, string $status, ?float $value, string $state, ?int $n): void
    {
        $this->assertSame(['value' => $value, 'state' => $state, 'N' => $n, 'clamped' => false], Projector::project($history, $status));
    }

    public static function known(): array
    {
        $rows = [[[8, 0, 0, 0], 'AVAILABLE', 0.0, 'AVAILABLE', 8], [[4, 4, 0, 0], 'AVAILABLE', 2 / 3, 'AVAILABLE', 8],
            [[2, 2, 2, 2], 'AVAILABLE', 1.0, 'AVAILABLE', 8], [[0, 0, 0, 0], 'AVAILABLE', null, 'NO_TOP2_METHOD_OBSERVATIONS', 0],
            [[1, 0, 0, 0], 'AVAILABLE', 0.0, 'AVAILABLE', 1]];
        foreach (['NO_HISTORY', 'MISSING_TARGET_METADATA', 'LEFT_TRUNCATED', 'INVALID_HISTORY', 'PARTIAL_HISTORY', 'MISSING_METHOD_HISTORY'] as $status) {
            $rows[] = [[null, null, null, null], $status, null, $status, null];
        }

        return $rows;
    }

    public function test_scale_and_permutation_invariance_of_index_not_c1_feature_order(): void
    {
        $history = [1, 3, 5, 7];
        $value = Projector::project($history, 'AVAILABLE')['value'];
        foreach ([1, 2, 7, 1000] as $scale) {
            $this->assertSame($value, Projector::project(array_map(fn ($n) => $n * $scale, $history), 'AVAILABLE')['value']);
        }
        foreach ($this->permutations($history) as $permutation) {
            $this->assertSame($value, Projector::project($permutation, 'AVAILABLE')['value']);
        }
        $this->assertSame(0.0, Projector::project([PHP_INT_MAX, 0, 0, 0], 'AVAILABLE')['value']);
    }

    #[DataProvider('invalid')]
    public function test_invalid_counts_and_status_are_rejected_not_imputed(array $history, string $status): void
    {
        $this->expectException(RuntimeException::class);
        Projector::project($history, $status);
    }

    public static function invalid(): array
    {
        return [[[1, 2, 3], 'AVAILABLE'], [['1', 2, 3, 4], 'AVAILABLE'], [[-1, 2, 3, 4], 'AVAILABLE'],
            [[1.0, 2, 3, 4], 'AVAILABLE'], [[true, 2, 3, 4], 'AVAILABLE'], [[INF, 2, 3, 4], 'AVAILABLE'],
            [[NAN, 2, 3, 4], 'AVAILABLE'], [[null, 2, 3, 4], 'AVAILABLE'], [[null, null, null, null], 'AVAILABLE'],
            [[0, 0, 0, 0], 'NO_HISTORY'], [[null, null, null, null], 'UNKNOWN'], [[PHP_INT_MAX, 1, 0, 0], 'AVAILABLE'],
            [[0 => 1, 2 => 2, 3 => 3, 4 => 4], 'AVAILABLE']];
    }

    public function test_boundary_residuals_are_fixed_and_audited(): void
    {
        $this->assertSame(1e-12, Contract::BOUNDARY_TOLERANCE);
        $this->assertSame(['value' => 0.0, 'clamped' => true], Projector::bounded(-1e-12));
        $this->assertSame(['value' => 1.0, 'clamped' => true], Projector::bounded(1 + 1e-12));
        $this->assertSame(['value' => 1.0, 'clamped' => false], Projector::bounded(1.0));
        foreach ([-1.1e-12, 1 + 1.1e-12, INF, -INF, NAN] as $value) {
            try {
                Projector::bounded($value);
                $this->fail('Out-of-contract result accepted.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('out of range', $e->getMessage());
            }
        }
    }

    private function permutations(array $values): \Generator
    {
        if ($values === []) {
            yield [];
        }
        foreach ($values as $i => $value) {
            $remaining = $values;
            array_splice($remaining, $i, 1);
            foreach ($this->permutations($remaining) as $tail) {
                yield [$value, ...$tail];
            }
        }
    }
}
