<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\Services\Bt03e02Contract;

final class Bt03e02ParameterLayoutBuilder
{
    public function __construct(private readonly EffectBinBuilder $bins) {}

    /**
     * @param  callable(): iterable<array<string, mixed>>  $raceSource
     */
    public function build(callable $raceSource): Bt03e02ParameterLayout
    {
        $bins = [];
        foreach (Bt03e02Contract::STAT_CODES as $statOffset => $statCode) {
            $bins[$statCode] = $this->bins->build($this->values($raceSource, $statOffset));
        }

        return new Bt03e02ParameterLayout($bins);
    }

    /**
     * @param  callable(): iterable<array<string, mixed>>  $raceSource
     * @return \Generator<int, int|float|string>
     */
    private function values(callable $raceSource, int $statOffset): \Generator
    {
        foreach ($raceSource() as $race) {
            foreach ($race['entries'] as $entry) {
                $value = $entry['signals'][$statOffset] ?? null;
                if ($value !== null) {
                    yield $value;
                }
            }
        }
    }
}
