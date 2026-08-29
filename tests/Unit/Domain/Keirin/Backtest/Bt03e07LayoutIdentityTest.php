<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e02ParameterLayout;
use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use App\Domain\Keirin\Backtest\Services\Bt03e07Contract;
use App\Domain\Keirin\Backtest\Services\Bt03e07LayoutIdentityGuard;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class Bt03e07LayoutIdentityTest extends TestCase
{
    #[DataProvider('frozenRoles')]
    public function test_required_layouts_match_their_frozen_e03_outer_bins(string $role): void
    {
        $layout = $this->layout();
        $guard = new Bt03e07LayoutIdentityGuard(new CanonicalHasher);

        $this->assertSame(
            (new CanonicalHasher)->hash($layout->canonicalBins()),
            $guard->verify($layout, $layout->canonicalBins(), $role),
        );
    }

    public function test_any_canonical_bin_drift_is_rejected(): void
    {
        $layout = $this->layout();
        $expected = $layout->canonicalBins();
        $expected['STAT-07'][0]['training_support']++;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('layout differed');
        (new Bt03e07LayoutIdentityGuard(new CanonicalHasher))->verify($layout, $expected, 'outer-2024');
    }

    /** @return array<string,array{string}> */
    public static function frozenRoles(): array
    {
        return ['outer 2024' => ['outer-2024'], 'inner B' => ['inner-b'], 'outer 2025' => ['outer-2025']];
    }

    private function layout(): Bt03e02ParameterLayout
    {
        $bins = [];
        foreach (Bt03e07Contract::STAT_CODES as $statCode) {
            $bins[$statCode] = [new EffectBinDto(1, 'CATEGORY', null, null, 'x', 2)];
        }

        return new Bt03e02ParameterLayout($bins);
    }
}
