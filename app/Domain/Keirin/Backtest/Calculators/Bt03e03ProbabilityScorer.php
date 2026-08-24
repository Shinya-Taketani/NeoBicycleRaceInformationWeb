<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\DTO\Bt03e03FitResultDto;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use RuntimeException;

final class Bt03e03ProbabilityScorer
{
    /** @param array<string,mixed> $race @return array<string,mixed> */
    public function predict(array $race, Bt03e03FitResultDto $fit): array
    {
        $entries = $race['entries'] ?? null;
        if (! is_array($entries) || count($entries) < 5 || count($entries) > 9) {
            throw new RuntimeException('BT-03E-03 probability race entrant count was invalid.');
        }
        $bikes = array_map(static fn (array $entry): int => (int) ($entry['bike'] ?? 0), $entries);
        if (count(array_unique($bikes)) !== count($bikes)
            || array_filter($bikes, static fn (int $bike): bool => $bike < 1 || $bike > 9) !== []) {
            throw new RuntimeException('BT-03E-03 probability race bike numbers were invalid.');
        }
        $utilities = [];
        foreach (Bt03e03Contract::POSITIONS as $position) {
            $coefficients = $fit->coefficients[$position] ?? null;
            if (! is_array($coefficients)) {
                throw new RuntimeException("BT-03E-03 {$position} coefficients were missing.");
            }
            $utilities[$position] = array_map(
                fn (array $entry): float => $this->utility($entry, $coefficients),
                $entries,
            );
        }

        $count = count($entries);
        $p1Logs = $this->conditionalLogProbabilities($utilities['POSITION_1'], []);
        $p1 = array_map('exp', $p1Logs);
        $p2Sums = $p3Sums = $p2Log = $p3Log = [];
        for ($index = 0; $index < $count; $index++) {
            $p2Sums[$index] = new Bt03e03CompensatedSum;
            $p3Sums[$index] = new Bt03e03CompensatedSum;
            $p2Log[$index] = $p3Log[$index] = -INF;
        }
        $orderedSum = new Bt03e03CompensatedSum;
        $setSums = [];
        $mapOrdered = null;
        $mapOrderedProbability = -1.0;
        $mapOrderedTieCount = 0;

        for ($first = 0; $first < $count; $first++) {
            $p2ConditionalLogs = $this->conditionalLogProbabilities($utilities['POSITION_2'], [$first]);
            for ($second = 0; $second < $count; $second++) {
                if ($second === $first) {
                    continue;
                }
                $logP2Path = $p1Logs[$first] + $p2ConditionalLogs[$second];
                $p2Sums[$second]->add(exp($logP2Path));
                $p2Log[$second] = $this->logAddExp($p2Log[$second], $logP2Path);
                $p3ConditionalLogs = $this->conditionalLogProbabilities($utilities['POSITION_3'], [$first, $second]);
                for ($third = 0; $third < $count; $third++) {
                    if ($third === $first || $third === $second) {
                        continue;
                    }
                    $logJoint = $logP2Path + $p3ConditionalLogs[$third];
                    $joint = exp($logJoint);
                    $orderedSum->add($joint);
                    $p3Sums[$third]->add($joint);
                    $p3Log[$third] = $this->logAddExp($p3Log[$third], $logJoint);
                    $ordered = [$bikes[$first], $bikes[$second], $bikes[$third]];
                    $set = $ordered;
                    sort($set, SORT_NUMERIC);
                    $setKey = implode('-', $set);
                    $setSums[$setKey] ??= ['bikes' => $set, 'sum' => new Bt03e03CompensatedSum];
                    $setSums[$setKey]['sum']->add($joint);
                    if ($joint > $mapOrderedProbability) {
                        $mapOrdered = $ordered;
                        $mapOrderedProbability = $joint;
                        $mapOrderedTieCount = 1;
                    } elseif ($joint === $mapOrderedProbability) {
                        $mapOrderedTieCount++;
                        if ($this->orderedTieKey((int) $race['race_id'], $ordered)
                            < $this->orderedTieKey((int) $race['race_id'], $mapOrdered ?? [])) {
                            $mapOrdered = $ordered;
                        }
                    }
                }
            }
        }
        if ($mapOrdered === null) {
            throw new RuntimeException('BT-03E-03 MAP ordered top3 was unavailable.');
        }
        $p2 = $p3 = [];
        for ($index = 0; $index < $count; $index++) {
            $p2[$index] = $p2Sums[$index]->value();
            $p3[$index] = $p3Sums[$index]->value();
        }
        $mapSet = null;
        $mapSetProbability = -1.0;
        foreach ($setSums as $set) {
            $probability = $set['sum']->value();
            if ($probability > $mapSetProbability
                || ($probability === $mapSetProbability && $this->setTieKey((int) $race['race_id'], $set['bikes']) < $this->setTieKey((int) $race['race_id'], $mapSet ?? []))) {
                $mapSet = $set['bikes'];
                $mapSetProbability = $probability;
            }
        }
        if ($mapSet === null) {
            throw new RuntimeException('BT-03E-03 MAP top3 set was unavailable.');
        }
        $this->assertProbabilityInvariants($p1, $p2, $p3, $orderedSum->value());

        $rankedBikes = $mapOrdered;
        $remaining = [];
        foreach ($entries as $offset => $entry) {
            if (! in_array($bikes[$offset], $mapOrdered, true)) {
                $remaining[] = [
                    'bike' => $bikes[$offset],
                    'top3' => $p1[$offset] + $p2[$offset] + $p3[$offset],
                    'p1' => $p1[$offset],
                    'p2' => $p2[$offset],
                    'p3' => $p3[$offset],
                    'anchor' => (float) $entry['anchor'],
                    'key' => $this->entryTieKey((int) $race['race_id'], $bikes[$offset]),
                ];
            }
        }
        usort($remaining, static fn (array $left, array $right): int => [
            -$left['top3'], -$left['p1'], -$left['p2'], -$left['p3'], -$left['anchor'], $left['key'],
        ] <=> [
            -$right['top3'], -$right['p1'], -$right['p2'], -$right['p3'], -$right['anchor'], $right['key'],
        ]);
        array_push($rankedBikes, ...array_column($remaining, 'bike'));
        $predictedPosition = array_flip($rankedBikes);

        $predictions = [];
        foreach ($entries as $offset => $entry) {
            $top2 = $p1[$offset] + $p2[$offset];
            $top3 = $top2 + $p3[$offset];
            $predictions[] = [
                'id' => $entry['id'],
                'bike' => $bikes[$offset],
                'raw' => (float) $entry['raw'],
                'stat01_rank' => $entry['stat01_rank'],
                'anchor' => (float) $entry['anchor'],
                'rank' => $entry['rank'],
                'status' => $entry['status'],
                'position_1_probability' => $p1[$offset],
                'position_2_probability' => $p2[$offset],
                'position_3_probability' => $p3[$offset],
                'position_1_log_probability' => $p1Logs[$offset],
                'position_2_log_probability' => $p2Log[$offset],
                'position_3_log_probability' => $p3Log[$offset],
                'top2_probability' => $top2,
                'top3_probability' => $top3,
                'predicted_position' => $predictedPosition[$bikes[$offset]] + 1,
                'is_map_top3' => in_array($bikes[$offset], $mapOrdered, true),
                'map_ordered_top3' => $mapOrdered,
                'map_ordered_probability' => $mapOrderedProbability,
                'map_top3_set' => $mapSet,
                'map_top3_set_probability' => $mapSetProbability,
                'map_tie_diagnostics' => [
                    'ordered_probability_tied_race' => (int) ($mapOrderedTieCount > 1),
                    'ordered_probability_tied_combinations' => $mapOrderedTieCount > 1 ? $mapOrderedTieCount : 0,
                    'technical_tiebreak_used' => $mapOrderedTieCount > 1,
                ],
                'utilities' => [
                    'POSITION_1' => $utilities['POSITION_1'][$offset],
                    'POSITION_2' => $utilities['POSITION_2'][$offset],
                    'POSITION_3' => $utilities['POSITION_3'][$offset],
                ],
            ];
        }

        return [
            'year' => $race['year'],
            'race_id' => $race['race_id'],
            'entries' => $predictions,
            'map_ordered_top3' => $mapOrdered,
            'map_ordered_probability' => $mapOrderedProbability,
            'map_top3_set' => $mapSet,
            'map_top3_set_probability' => $mapSetProbability,
            'map_tie_diagnostics' => [
                'ordered_probability_tied_race' => (int) ($mapOrderedTieCount > 1),
                'ordered_probability_tied_combinations' => $mapOrderedTieCount > 1 ? $mapOrderedTieCount : 0,
                'technical_tiebreak_used' => $mapOrderedTieCount > 1,
            ],
            'probability_invariants' => [
                'position_1_sum' => $this->sum($p1),
                'position_2_sum' => $this->sum($p2),
                'position_3_sum' => $this->sum($p3),
                'ordered_joint_sum' => $orderedSum->value(),
            ],
        ];
    }

    /** @param list<float> $utilities @param list<int> $excluded @return list<float> */
    public function conditionalLogProbabilities(array $utilities, array $excluded): array
    {
        if ($utilities === [] || array_filter($utilities, 'is_finite') !== $utilities) {
            throw new RuntimeException('BT-03E-03 conditional utility vector was invalid.');
        }
        $excludedLookup = array_fill_keys($excluded, true);
        $available = [];
        foreach ($utilities as $offset => $utility) {
            if (! isset($excludedLookup[$offset])) {
                $available[] = $utility;
            }
        }
        if ($available === []) {
            throw new RuntimeException('BT-03E-03 conditional candidate set was empty.');
        }
        $maximum = max($available);
        $denominator = new Bt03e03CompensatedSum;
        foreach ($available as $utility) {
            $denominator->add(exp($utility - $maximum));
        }
        $logDenominator = $maximum + log($denominator->value());
        $result = [];
        foreach ($utilities as $offset => $utility) {
            $result[$offset] = isset($excludedLookup[$offset]) ? -INF : $utility - $logDenominator;
        }

        return $result;
    }

    /** @param array<string,mixed> $entry @param list<float> $coefficients */
    private function utility(array $entry, array $coefficients): float
    {
        if (! is_array($entry['bins'] ?? null) || ! is_numeric($entry['anchor'] ?? null)) {
            throw new RuntimeException('BT-03E-03 utility entry was invalid.');
        }
        $sum = new Bt03e03CompensatedSum;
        $sum->add((float) $entry['anchor']);
        foreach ($entry['bins'] as $index) {
            if ($index !== null) {
                if (! is_int($index) || ! array_key_exists($index, $coefficients)) {
                    throw new RuntimeException('BT-03E-03 utility bin index was invalid.');
                }
                $sum->add($coefficients[$index]);
            }
        }

        return $sum->value();
    }

    /** @param list<float> $p1 @param list<float> $p2 @param list<float> $p3 */
    private function assertProbabilityInvariants(array $p1, array $p2, array $p3, float $jointSum): void
    {
        foreach ([$p1, $p2, $p3] as $probabilities) {
            foreach ($probabilities as $probability) {
                if (! is_finite($probability) || $probability < 0.0 || $probability > 1.0) {
                    throw new RuntimeException('BT-03E-03 probability was outside [0,1].');
                }
            }
            if (abs($this->sum($probabilities) - 1.0) > Bt03e03Contract::PROBABILITY_TOLERANCE) {
                throw new RuntimeException('BT-03E-03 position probability sum invariant failed.');
            }
        }
        if (abs($jointSum - 1.0) > Bt03e03Contract::PROBABILITY_TOLERANCE) {
            throw new RuntimeException('BT-03E-03 ordered joint probability sum invariant failed.');
        }
        foreach (array_keys($p1) as $index) {
            $top3 = $p1[$index] + $p2[$index] + $p3[$index];
            if (! is_finite($top3) || $top3 > 1.0 + Bt03e03Contract::PROBABILITY_TOLERANCE) {
                throw new RuntimeException('BT-03E-03 top3 probability invariant failed.');
            }
        }
    }

    /** @param list<float> $values */
    private function sum(array $values): float
    {
        $sum = new Bt03e03CompensatedSum;
        foreach ($values as $value) {
            $sum->add($value);
        }

        return $sum->value();
    }

    private function logAddExp(float $left, float $right): float
    {
        if ($left === -INF) {
            return $right;
        }
        $maximum = max($left, $right);

        return $maximum + log(exp($left - $maximum) + exp($right - $maximum));
    }

    /** @param list<int> $ordered */
    private function orderedTieKey(int $raceId, array $ordered): string
    {
        return hash('sha256', Bt03e03Contract::TIE_RULE_VERSION.'|'.$raceId.'|'.implode('|', $ordered));
    }

    /** @param list<int> $set */
    private function setTieKey(int $raceId, array $set): string
    {
        return hash('sha256', Bt03e03Contract::TIE_RULE_VERSION.'|SET|'.$raceId.'|'.implode('|', $set));
    }

    private function entryTieKey(int $raceId, int $bike): string
    {
        return hash('sha256', Bt03e03Contract::TIE_RULE_VERSION.'|ENTRY|'.$raceId.'|'.$bike);
    }
}
