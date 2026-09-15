<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalHistory;

use App\Domain\Keirin\Backtest\Calculators\Bt03e02CompensatedSum;
use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use App\Domain\Keirin\Backtest\Services\Bt03e02Contract;
use InvalidArgumentException;

final class Layout
{
    private readonly array $codes;

    /** @var array<string, list<EffectBinDto>> */
    private array $bins;

    /** @var array<string, array<int, int>> */
    private array $parameterByBin = [];

    /** @var array<string, list<int>> */
    private array $groups = [];

    /** @var list<array{0:int,1:int}> */
    private array $smoothEdges = [];

    /** @var list<float> */
    private array $supportWeights = [];

    /** @param array<string, list<EffectBinDto>> $bins */
    public function __construct(array $bins)
    {
        $this->codes = array_keys($bins);
        if (! in_array($this->codes, [Bt03e02Contract::STAT_CODES, [...Bt03e02Contract::STAT_CODES, ...HistoryAggregator::FEATURES]], true)) {
            throw new InvalidArgumentException('BT-03E-02 layout requires the fixed 12 STATs in canonical order.');
        }
        $this->bins = $bins;
        $parameter = 0;
        foreach ($bins as $statCode => $statBins) {
            if ($statBins === []) {
                if (in_array($statCode, HistoryAggregator::FEATURES, true)) {
                    continue;
                }
                throw new InvalidArgumentException("BT-03E-02 {$statCode} bins were empty.");
            }
            $kind = $statBins[0]->kind;
            $supportTotal = array_sum(array_map(static fn (EffectBinDto $bin): int => $bin->trainingSampleCount, $statBins));
            if ($supportTotal < 1) {
                throw new InvalidArgumentException("BT-03E-02 {$statCode} support was empty.");
            }
            $previous = null;
            foreach ($statBins as $bin) {
                if ($bin->trainingSampleCount === 0 && in_array($statCode, HistoryAggregator::FEATURES, true)) {
                    continue;
                }
                if ($bin->kind !== $kind || $bin->trainingSampleCount < 1 || isset($this->parameterByBin[$statCode][$bin->index])) {
                    throw new InvalidArgumentException("BT-03E-02 {$statCode} bin contract was invalid.");
                }
                $this->parameterByBin[$statCode][$bin->index] = $parameter;
                $this->groups[$statCode][] = $parameter;
                $this->supportWeights[$parameter] = $bin->trainingSampleCount / $supportTotal;
                if ($kind === 'NUMERIC_RANGE' && $previous !== null) {
                    $this->smoothEdges[] = [$previous, $parameter];
                }
                $previous = $parameter++;
            }
        }
    }

    public function size(): int
    {
        return count($this->supportWeights);
    }

    public function featureCount(): int
    {
        return count($this->codes);
    }

    /** @return array<string, list<int>> */
    public function groups(): array
    {
        return $this->groups;
    }

    /** @return list<array{0:int,1:int}> */
    public function smoothEdges(): array
    {
        return $this->smoothEdges;
    }

    /** @return list<float> */
    public function supportWeights(): array
    {
        return $this->supportWeights;
    }

    /** @param list<int|float|string|null> $values @return list<?int> */
    public function assign(array $values, EffectBinBuilder $builder): array
    {
        if (count($values) !== count($this->codes)) {
            throw new InvalidArgumentException('BT-03E-02 signal vector size was invalid.');
        }
        $indexes = [];
        foreach ($this->codes as $offset => $statCode) {
            if ($this->bins[$statCode] === []) {
                $indexes[] = null;

                continue;
            }
            $bin = $builder->assign($this->bins[$statCode], $values[$offset]);
            $indexes[] = $bin === null || $bin === 0 ? null : ($this->parameterByBin[$statCode][$bin] ?? null);
        }

        return $indexes;
    }

    /** @param list<float> $coefficients @return list<float> */
    public function project(array $coefficients): array
    {
        $this->assertVector($coefficients);
        foreach ($this->groups as $indexes) {
            $mean = new Bt03e02CompensatedSum;
            foreach ($indexes as $index) {
                $mean->add($this->supportWeights[$index] * $coefficients[$index]);
            }
            $shift = $mean->value();
            foreach ($indexes as $index) {
                $coefficients[$index] -= $shift;
                if ($coefficients[$index] === 0.0) {
                    $coefficients[$index] = 0.0;
                }
            }
        }

        return $coefficients;
    }

    /** @param list<float> $coefficients */
    public function weightedMeans(array $coefficients): array
    {
        $this->assertVector($coefficients);
        $means = [];
        foreach ($this->groups as $statCode => $indexes) {
            $sum = new Bt03e02CompensatedSum;
            foreach ($indexes as $index) {
                $sum->add($this->supportWeights[$index] * $coefficients[$index]);
            }
            $means[$statCode] = $sum->value();
        }

        return $means;
    }

    /** @return array<string, list<array<string, int|float|string|null>>> */
    public function canonicalBins(): array
    {
        return array_map(static fn (array $bins): array => array_map(static fn (EffectBinDto $bin): array => [
            'index' => $bin->index,
            'kind' => $bin->kind,
            'lower_bound' => $bin->lowerBound,
            'upper_bound' => $bin->upperBound,
            'category_value' => $bin->categoryValue,
            'training_support' => $bin->trainingSampleCount,
        ], $bins), $this->bins);
    }

    /** @param list<float> $vector */
    private function assertVector(array $vector): void
    {
        if (count($vector) !== $this->size() || array_filter($vector, 'is_finite') !== $vector) {
            throw new InvalidArgumentException('BT-03E-02 coefficient vector was invalid.');
        }
    }
}
