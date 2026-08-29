<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Calculators\Bt03e02ParameterLayout;
use App\Domain\Keirin\Backtest\DTO\Bt03e03FitResultDto;
use App\Domain\Keirin\Backtest\DTO\Bt03e06ReconstructedModelDto;
use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use RuntimeException;

final class Bt03e06ModelReconstructor
{
    public function __construct(private readonly CanonicalHasher $hasher) {}

    /** @param array<string,mixed> $model */
    public function reconstruct(int $year, array $model): Bt03e06ReconstructedModelDto
    {
        if (! in_array($year, Bt03e06Contract::DEVELOPMENT_YEARS, true)
            || ($model['optimizer_version'] ?? null) !== Bt03e06Contract::SOURCE_OPTIMIZER_VERSION
            || ($model['probability_version'] ?? null) !== Bt03e06Contract::SOURCE_PROBABILITY_VERSION
            || ($model['tie_rule_version'] ?? null) !== Bt03e06Contract::SOURCE_TIE_RULE_VERSION
            || ! is_float($model['stat01_anchor_coefficient'] ?? null)
            || $model['stat01_anchor_coefficient'] !== 1.0) {
            throw new RuntimeException('BT-03E-06 source model contract was invalid.');
        }

        $bins = $this->bins($model['bins'] ?? null);
        $layout = new Bt03e02ParameterLayout($bins);
        $canonicalBins = $layout->canonicalBins();
        if ($canonicalBins !== $model['bins']) {
            throw new RuntimeException('BT-03E-06 reconstructed bins differed from the source artifact.');
        }

        $coefficients = $this->coefficients($model['position_coefficients'] ?? null, $layout->size());
        $weightedMeans = $model['weighted_center_means'] ?? null;
        if (! is_array($weightedMeans) || array_keys($weightedMeans) !== Bt03e06Contract::POSITIONS) {
            throw new RuntimeException('BT-03E-06 source weighted center means were invalid.');
        }
        foreach (Bt03e06Contract::POSITIONS as $position) {
            if ($layout->weightedMeans($coefficients[$position]) !== $weightedMeans[$position]) {
                throw new RuntimeException("BT-03E-06 {$position} weighted center verification failed.");
            }
        }

        $lambda = $this->finiteFloat($model['lambda'] ?? null, 'lambda');
        $objectives = $this->numericMap($model['objectives'] ?? null, 'objectives');
        $iterations = $this->integerMap($model['iterations'] ?? null, 'iterations');
        $eligible = $this->integerMap($model['eligible_races'] ?? null, 'eligible races');
        $excluded = $this->integerMap($model['excluded_races'] ?? null, 'excluded races');
        $diagnostics = $model['optimizer_diagnostics'] ?? null;
        if (! is_array($diagnostics)) {
            throw new RuntimeException('BT-03E-06 optimizer diagnostics were invalid.');
        }
        $fit = new Bt03e03FitResultDto($lambda, $coefficients, $objectives, $iterations, $eligible, $excluded, $diagnostics);

        return new Bt03e06ReconstructedModelDto($year, $layout, $fit, $this->hasher->hash($model));
    }

    /** @return array<string,list<EffectBinDto>> */
    private function bins(mixed $value): array
    {
        if (! is_array($value) || array_keys($value) !== Bt03e06Contract::STAT_CODES) {
            throw new RuntimeException('BT-03E-06 source model bins were invalid.');
        }
        $result = [];
        foreach ($value as $statCode => $bins) {
            if (! is_array($bins) || $bins === []) {
                throw new RuntimeException("BT-03E-06 {$statCode} source bins were empty.");
            }
            foreach ($bins as $bin) {
                if (! is_array($bin) || array_keys($bin) !== ['index', 'kind', 'lower_bound', 'upper_bound', 'category_value', 'training_support']
                    || ! is_int($bin['index']) || ! is_string($bin['kind'])
                    || (! is_float($bin['lower_bound']) && $bin['lower_bound'] !== null)
                    || (! is_float($bin['upper_bound']) && $bin['upper_bound'] !== null)
                    || (! is_string($bin['category_value']) && $bin['category_value'] !== null)
                    || ! is_int($bin['training_support'])) {
                    throw new RuntimeException("BT-03E-06 {$statCode} source bin contract was invalid.");
                }
                $result[$statCode][] = new EffectBinDto(
                    $bin['index'],
                    $bin['kind'],
                    $bin['lower_bound'],
                    $bin['upper_bound'],
                    $bin['category_value'],
                    $bin['training_support'],
                );
            }
        }

        return $result;
    }

    /** @return array<string,list<float>> */
    private function coefficients(mixed $value, int $size): array
    {
        if (! is_array($value) || array_keys($value) !== Bt03e06Contract::POSITIONS) {
            throw new RuntimeException('BT-03E-06 source position coefficients were invalid.');
        }
        $result = [];
        foreach ($value as $position => $coefficients) {
            if (! is_array($coefficients) || ! array_is_list($coefficients) || count($coefficients) !== $size) {
                throw new RuntimeException("BT-03E-06 {$position} coefficient count differed from the layout.");
            }
            $result[$position] = array_map(fn (mixed $coefficient): float => $this->finiteFloat($coefficient, "{$position} coefficient"), $coefficients);
        }

        return $result;
    }

    /** @return array<string,float> */
    private function numericMap(mixed $value, string $field): array
    {
        if (! is_array($value)) {
            throw new RuntimeException("BT-03E-06 source {$field} were invalid.");
        }

        return array_map(fn (mixed $item): float => $this->finiteFloat($item, $field), $value);
    }

    /** @return array<string,int> */
    private function integerMap(mixed $value, string $field): array
    {
        if (! is_array($value) || array_filter($value, 'is_int') !== $value
            || array_filter($value, static fn (int $item): bool => $item < 0) !== []) {
            throw new RuntimeException("BT-03E-06 source {$field} were invalid.");
        }

        return $value;
    }

    private function finiteFloat(mixed $value, string $field): float
    {
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
            throw new RuntimeException("BT-03E-06 source {$field} was invalid.");
        }

        return (float) $value;
    }
}
