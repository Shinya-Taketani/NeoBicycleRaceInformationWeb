<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\Stat39C1FieldBikeComparison;

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
        $entries = 0;
        foreach ($raceSource() as $race) {
            $entries += count($race['entries']);
        }
        $codes = Contract::features();
        foreach ($codes as $statOffset => $statCode) {
            $values = $this->values($raceSource, $statOffset);
            $values->rewind();
            $bins[$statCode] = $statCode === Contract::EXTRA_FEATURE
                ? CategoryBins::build($values)
                : ($values->valid() ? $this->bins->build($values) : []);
        }

        return new Layout($bins, $entries);
    }

    /**
     * @param  callable(): iterable<array<string, mixed>>  $raceSource
     * @return \Generator<int, int|float|string>
     */
    private function values(callable $raceSource, int $statOffset): \Generator
    {
        foreach ($raceSource() as $race) {
            foreach ($race['entries'] as $entry) {
                if (! array_is_list($entry['signals']) || count($entry['signals']) !== 17) {
                    throw new \InvalidArgumentException('Expected full 17-feature vector.');
                }
                $value = $entry['signals'][$statOffset];
                if ($statOffset === 16) {
                    yield CategoryBins::value($value);

                    continue;
                }
                if ($value !== null) {
                    yield $value;
                }
            }
        }
    }
}
