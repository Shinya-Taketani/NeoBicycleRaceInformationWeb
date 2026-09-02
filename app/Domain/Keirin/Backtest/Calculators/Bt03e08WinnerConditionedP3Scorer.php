<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\DTO\Bt03e08FitResultDto;
use App\Domain\Keirin\Backtest\Services\Bt03e08Contract;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use RuntimeException;

final class Bt03e08WinnerConditionedP3Scorer
{
    public function __construct(private readonly Bt03e03ProbabilityScorer $probabilities, private readonly CanonicalHasher $hasher) {}

    /** @param array<string,mixed> $race @return array<string,mixed> */
    public function predict(array $race, Bt03e08FitResultDto $fit, int $winnerBike): array
    {
        $entries = $race['entries'] ?? null;
        if (! is_array($entries) || count($entries) < 5 || count($entries) > 9) {
            throw new RuntimeException('BT-03E-08 P3 scorer entrant count was invalid.');
        }
        $bikes = array_map(static fn (array $entry): int => (int) ($entry['bike'] ?? 0), $entries);
        if (count(array_unique($bikes)) !== count($bikes) || ! in_array($winnerBike, $bikes, true)
            || array_filter($bikes, static fn (int $bike): bool => $bike < 1 || $bike > 9) !== []) {
            throw new RuntimeException('BT-03E-08 P3 scorer bike identities were invalid.');
        }
        $winnerOffset = array_search($winnerBike, $bikes, true);
        $utilities = array_map(fn (array $entry): float => $this->utility($entry, $fit->coefficients), $entries);
        $values = array_map('exp', $this->probabilities->conditionalLogProbabilities($utilities, [$winnerOffset]));
        if ($values[$winnerOffset] !== 0.0 || abs(array_sum($values) - 1.0) > Bt03e08Contract::PROBABILITY_TOLERANCE) {
            throw new RuntimeException('BT-03E-08 winner-conditioned P3 probability invariant failed.');
        }
        $distribution = array_map(static fn (int $bike, float $probability): array => ['bike' => $bike, 'probability' => $probability], $bikes, $values);

        return [
            'year' => $race['year'], 'race_id' => $race['race_id'], 'winner_bike' => $winnerBike,
            'entries' => array_map(static fn (array $entry, float $probability): array => ['id' => $entry['id'], 'bike' => $entry['bike'], 'r3_probability' => $probability], $entries, $values),
            'r3_distribution_sha256' => $this->hasher->hash($distribution),
            'probability_invariants' => ['winner_probability' => $values[$winnerOffset], 'nonwinner_sum' => array_sum($values)],
        ];
    }

    /** @param array<string,mixed> $entry @param list<float> $coefficients */
    private function utility(array $entry, array $coefficients): float
    {
        if (! is_array($entry['bins'] ?? null) || ! is_numeric($entry['anchor'] ?? null)) {
            throw new RuntimeException('BT-03E-08 P3 utility entry was invalid.');
        }
        $sum = new Bt03e03CompensatedSum;
        $sum->add((float) $entry['anchor']);
        foreach ($entry['bins'] as $index) {
            if ($index !== null) {
                if (! is_int($index) || ! array_key_exists($index, $coefficients)) {
                    throw new RuntimeException('BT-03E-08 P3 utility bin index was invalid.');
                }
                $sum->add($coefficients[$index]);
            }
        }

        return $sum->value();
    }
}
