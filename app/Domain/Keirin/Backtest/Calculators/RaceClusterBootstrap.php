<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use InvalidArgumentException;

class RaceClusterBootstrap
{
    public const ITERATIONS = 2000;

    public const SEED = 20260812;

    /** @return list<list<int>> */
    public function resampleIndexes(int $raceCount, int $iterations = self::ITERATIONS, int $seed = self::SEED): array
    {
        if ($raceCount < 1 || $iterations < 1) {
            throw new InvalidArgumentException('Bootstrap race and iteration counts must be positive.');
        }
        $random = new DeterministicRandom($seed);
        $samples = [];
        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            $sample = [];
            for ($index = 0; $index < $raceCount; $index++) {
                $sample[] = $random->integer($raceCount);
            }
            $samples[] = $sample;
        }

        return $samples;
    }

    /**
     * @template T
     *
     * @param  list<T>  $racePayloads
     * @param  list<int>  $indexes
     * @return list<T>
     */
    public function apply(array $racePayloads, array $indexes): array
    {
        return array_map(function (int $index) use ($racePayloads): mixed {
            if (! array_key_exists($index, $racePayloads)) {
                throw new InvalidArgumentException('Bootstrap race index was out of range.');
            }

            return $racePayloads[$index];
        }, $indexes);
    }
}
