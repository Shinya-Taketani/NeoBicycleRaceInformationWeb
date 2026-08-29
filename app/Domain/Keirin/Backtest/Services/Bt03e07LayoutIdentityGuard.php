<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Calculators\Bt03e02ParameterLayout;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use RuntimeException;

final class Bt03e07LayoutIdentityGuard
{
    public function __construct(private readonly CanonicalHasher $hasher) {}

    /** @param array<string,mixed>|null $expected */
    public function verify(Bt03e02ParameterLayout $layout, ?array $expected, string $role): string
    {
        $bins = $layout->canonicalBins();
        if ($expected !== null && $bins !== $expected) {
            throw new RuntimeException("BT-03E-07 {$role} layout differed from its frozen E03 outer bins.");
        }

        return $this->hasher->hash($bins);
    }
}
