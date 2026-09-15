<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalHistory;

use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\Services\Bt03e02Contract;

final class LayoutBuilder
{
    public function __construct(private readonly EffectBinBuilder $bins) {}

    /**
     * @param  callable(): iterable<array<string, mixed>>  $raceSource
     */
    public function build(callable $raceSource, bool $withHistory = false): Layout
    {
        $bins = [];
        $codes = $withHistory ? [...Bt03e02Contract::STAT_CODES, ...HistoryAggregator::FEATURES] : Bt03e02Contract::STAT_CODES;
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
