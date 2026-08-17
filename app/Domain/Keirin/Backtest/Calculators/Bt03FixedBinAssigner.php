<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\DTO\Bt03BinAssignmentDto;
use App\Domain\Keirin\Backtest\DTO\Bt03SourceBinDto;
use InvalidArgumentException;

class Bt03FixedBinAssigner
{
    public function __construct(private readonly EffectBinBuilder $bins) {}

    /** @param list<Bt03SourceBinDto> $sourceBins */
    public function assign(array $sourceBins, int|float|string|null $value): ?Bt03BinAssignmentDto
    {
        $this->assertBins($sourceBins);
        $index = $this->bins->assign(array_map(fn (Bt03SourceBinDto $bin) => $bin->effectBin(), $sourceBins), $value);
        if ($index === null) {
            return null;
        }
        if ($index === 0) {
            if ($sourceBins[0]->kind !== 'CATEGORY' || $value === null) {
                throw new InvalidArgumentException('BT-03 unseen bin was only valid for a category value.');
            }

            return new Bt03BinAssignmentDto(
                0,
                'UNSEEN_CATEGORY',
                'CATEGORY',
                null,
                null,
                null,
                0,
                null,
                $sourceBins[0]->boundariesHash,
            );
        }
        foreach ($sourceBins as $bin) {
            if ($bin->index === $index) {
                return new Bt03BinAssignmentDto(
                    $bin->index,
                    'TRAINING_BIN',
                    $bin->kind,
                    $bin->lowerBound,
                    $bin->upperBound,
                    $bin->categoryValue,
                    $bin->trainingSampleCount,
                    $bin->sourceEffectBinId,
                    $bin->boundariesHash,
                );
            }
        }

        throw new InvalidArgumentException('BT-03 assigned source bin was unavailable.');
    }

    /** @param list<Bt03SourceBinDto> $sourceBins */
    private function assertBins(array $sourceBins): void
    {
        if ($sourceBins === []) {
            throw new InvalidArgumentException('BT-03 fixed source bins were empty.');
        }
        $kind = $sourceBins[0]->kind;
        $hash = $sourceBins[0]->boundariesHash;
        $indexes = [];
        foreach ($sourceBins as $bin) {
            if (! $bin instanceof Bt03SourceBinDto
                || $bin->sourceEffectBinId < 1
                || $bin->index < 1
                || isset($indexes[$bin->index])
                || $bin->kind !== $kind
                || $bin->boundariesHash !== $hash
                || ! in_array($bin->kind, ['NUMERIC_RANGE', 'CATEGORY'], true)
                || preg_match('/\A[0-9a-f]{64}\z/', $bin->boundariesHash) !== 1) {
                throw new InvalidArgumentException('BT-03 fixed source bin contract was invalid.');
            }
            $indexes[$bin->index] = true;
        }
    }
}
