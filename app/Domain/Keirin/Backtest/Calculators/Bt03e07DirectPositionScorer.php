<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\DTO\Bt03e07FitResultDto;
use App\Domain\Keirin\Backtest\Services\Bt03e07Contract;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use RuntimeException;

final class Bt03e07DirectPositionScorer
{
    public function __construct(private readonly CanonicalHasher $hasher) {}

    /** @param array<string,mixed> $race @return array<string,mixed> */
    public function predict(array $race, Bt03e07FitResultDto $fit): array
    {
        $entries = $race['entries'] ?? null;
        if (! is_array($entries) || count($entries) < 5 || count($entries) > 9) {
            throw new RuntimeException('BT-03E-07 direct scorer entrant count was invalid.');
        }
        $bikes = array_map(static fn (array $entry): int => (int) ($entry['bike'] ?? 0), $entries);
        if (count(array_unique($bikes)) !== count($bikes)
            || array_filter($bikes, static fn (int $bike): bool => $bike < 1 || $bike > 9) !== []) {
            throw new RuntimeException('BT-03E-07 direct scorer bike numbers were invalid.');
        }
        $distributions = [];
        foreach (Bt03e07Contract::POSITIONS as $position) {
            $coefficients = $fit->coefficients[$position] ?? null;
            if (! is_array($coefficients)) {
                throw new RuntimeException("BT-03E-07 {$position} coefficients were missing.");
            }
            $utilities = array_map(fn (array $entry): float => $this->utility($entry, $coefficients), $entries);
            $distributions[$position] = $this->softmax($utilities);
        }
        foreach ($distributions as $position => $values) {
            if (abs(array_sum($values) - 1.0) > Bt03e07Contract::PROBABILITY_TOLERANCE) {
                throw new RuntimeException("BT-03E-07 {$position} direct probability sum invariant failed.");
            }
        }
        $predictions = [];
        foreach ($entries as $offset => $entry) {
            $predictions[] = [
                'id' => $entry['id'],
                'bike' => $bikes[$offset],
                'direct_position_2_probability' => $distributions['POSITION_2'][$offset],
                'direct_position_3_probability' => $distributions['POSITION_3'][$offset],
            ];
        }

        return [
            'year' => $race['year'],
            'race_id' => $race['race_id'],
            'entries' => $predictions,
            'direct_p2_distribution_sha256' => $this->distributionHash($bikes, $distributions['POSITION_2']),
            'direct_p3_distribution_sha256' => $this->distributionHash($bikes, $distributions['POSITION_3']),
            'probability_invariants' => [
                'direct_position_2_sum' => array_sum($distributions['POSITION_2']),
                'direct_position_3_sum' => array_sum($distributions['POSITION_3']),
            ],
        ];
    }

    /** @param list<float> $utilities @return list<float> */
    public function softmax(array $utilities): array
    {
        if ($utilities === [] || array_filter($utilities, 'is_finite') !== $utilities) {
            throw new RuntimeException('BT-03E-07 direct softmax utilities were invalid.');
        }
        $maximum = max($utilities);
        $sum = new Bt03e03CompensatedSum;
        foreach ($utilities as $utility) {
            $sum->add(exp($utility - $maximum));
        }
        $denominator = $sum->value();

        return array_map(static fn (float $utility): float => exp($utility - $maximum) / $denominator, $utilities);
    }

    /** @param array<string,mixed> $entry @param list<float> $coefficients */
    private function utility(array $entry, array $coefficients): float
    {
        if (! is_array($entry['bins'] ?? null) || ! is_numeric($entry['anchor'] ?? null)) {
            throw new RuntimeException('BT-03E-07 direct utility entry was invalid.');
        }
        $sum = new Bt03e03CompensatedSum;
        $sum->add((float) $entry['anchor']);
        foreach ($entry['bins'] as $index) {
            if ($index !== null) {
                if (! is_int($index) || ! array_key_exists($index, $coefficients)) {
                    throw new RuntimeException('BT-03E-07 direct utility bin index was invalid.');
                }
                $sum->add($coefficients[$index]);
            }
        }

        return $sum->value();
    }

    /** @param list<int> $bikes @param list<float> $values */
    private function distributionHash(array $bikes, array $values): string
    {
        return $this->hasher->hash(array_map(
            static fn (int $bike, float $value): array => ['bike' => $bike, 'probability' => $value],
            $bikes,
            $values,
        ));
    }
}
