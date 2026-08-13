<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\DTO\StandardizationModelDto;
use InvalidArgumentException;

class TrainingStandardizer
{
    /** @param list<array<string, int|float>> $trainingRows */
    public function fit(array $trainingRows): StandardizationModelDto
    {
        if ($trainingRows === []) {
            throw new InvalidArgumentException('Standardizer training rows must not be empty.');
        }
        $names = array_keys($trainingRows[0]);
        if ($names === []) {
            throw new InvalidArgumentException('Standardizer requires at least one feature.');
        }
        $counts = $means = $m2 = array_fill_keys($names, 0.0);
        foreach ($trainingRows as $row) {
            if (array_keys($row) !== $names) {
                throw new InvalidArgumentException('Standardizer feature order changed within training rows.');
            }
            foreach ($names as $name) {
                $value = (float) $row[$name];
                if (! is_finite($value)) {
                    throw new InvalidArgumentException("Standardizer feature {$name} was not finite.");
                }
                $counts[$name]++;
                $delta = $value - $means[$name];
                $means[$name] += $delta / $counts[$name];
                $m2[$name] += $delta * ($value - $means[$name]);
            }
        }
        $sd = [];
        $zero = [];
        foreach ($names as $name) {
            $sd[$name] = sqrt($m2[$name] / $counts[$name]);
            if ($sd[$name] == 0.0) {
                $zero[] = $name;
            }
        }

        return new StandardizationModelDto($means, $sd, $zero);
    }

    /** @param array<string, int|float> $row @return array<string, float> */
    public function transform(StandardizationModelDto $model, array $row): array
    {
        if (array_keys($row) !== array_keys($model->means)) {
            throw new InvalidArgumentException('Standardizer transform row did not match fitted feature order.');
        }
        $result = [];
        foreach ($model->means as $name => $mean) {
            $value = (float) $row[$name];
            if (! is_finite($value)) {
                throw new InvalidArgumentException("Standardizer feature {$name} was not finite.");
            }
            $sd = $model->populationStandardDeviations[$name];
            $result[$name] = $sd == 0.0 ? 0.0 : ($value - $mean) / $sd;
        }

        return $result;
    }
}
