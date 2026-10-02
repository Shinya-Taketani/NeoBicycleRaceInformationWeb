<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\Stat17C1Comparison;

use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;

final class LayoutBuilder
{
    public function __construct(private readonly EffectBinBuilder $bins) {}

    /**
     * @param  callable(): iterable<array<string, mixed>>  $raceSource
     */
    public function build(callable $raceSource): Layout
    {
        $bins = [];
        $codes = Contract::features();
        foreach ($codes as $statOffset => $statCode) {
            $values = $this->values($raceSource, $statOffset);
            $values->rewind();
            $bins[$statCode] = $values->valid() ? $this->bins->build($values) : [];
        }

        return new Layout($bins);
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
