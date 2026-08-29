<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\Services\Bt03e07Contract;
use InvalidArgumentException;
use RuntimeException;

final class Bt03e07DirectPositionObjective
{
    /**
     * @param  callable():iterable<array<string,mixed>>  $raceSource
     * @param  list<float>  $coefficients
     * @return array{loss:float,gradient:list<float>,eligible_races:int,excluded_races:int}
     */
    public function lossAndGradient(callable $raceSource, Bt03e02ParameterLayout $layout, array $coefficients, string $position): array
    {
        $targetRank = $this->targetRank($position, $coefficients, $layout);
        $gradient = array_fill(0, $layout->size(), 0.0);
        $compensation = array_fill(0, $layout->size(), 0.0);
        $loss = new Bt03e03CompensatedSum;
        $eligible = $excluded = 0;

        foreach ($raceSource() as $race) {
            $selection = $this->eligibleEntries($race, $layout, $targetRank);
            if ($selection === null) {
                $excluded++;

                continue;
            }
            $eligible++;
            $utilities = array_map(fn (array $entry): float => $this->utility($entry, $coefficients), $selection['candidates']);
            $logDenominator = $this->logSumExp($utilities);
            $loss->add($logDenominator - $utilities[$selection['actual_offset']]);
            foreach ($selection['candidates'] as $offset => $entry) {
                $weight = exp($utilities[$offset] - $logDenominator) - (float) ($offset === $selection['actual_offset']);
                foreach ($entry['bins'] as $index) {
                    if ($index !== null) {
                        $this->add($gradient, $compensation, $index, $weight);
                    }
                }
            }
        }
        if ($eligible === 0) {
            throw new RuntimeException("BT-03E-07 {$position} had no direct-position eligible races.");
        }
        foreach ($gradient as $index => $value) {
            $gradient[$index] = ($value + $compensation[$index]) / $eligible;
        }

        return ['loss' => $loss->value() / $eligible, 'gradient' => $gradient, 'eligible_races' => $eligible, 'excluded_races' => $excluded];
    }

    /** @param callable():iterable<array<string,mixed>> $raceSource @param list<float> $coefficients */
    public function loss(callable $raceSource, Bt03e02ParameterLayout $layout, array $coefficients, string $position): float
    {
        return $this->lossAndGradient($raceSource, $layout, $coefficients, $position)['loss'];
    }

    /** @param array<string,mixed> $race @param list<float> $coefficients */
    public function raceLoss(array $race, Bt03e02ParameterLayout $layout, array $coefficients, string $position): ?float
    {
        $selection = $this->eligibleEntries($race, $layout, $this->targetRank($position, $coefficients, $layout));
        if ($selection === null) {
            return null;
        }
        $utilities = array_map(fn (array $entry): float => $this->utility($entry, $coefficients), $selection['candidates']);

        return $this->logSumExp($utilities) - $utilities[$selection['actual_offset']];
    }

    /** @param list<float> $coefficients */
    public function smoothPenalty(Bt03e02ParameterLayout $layout, array $coefficients, float $lambda): float
    {
        $size = $layout->size();
        if ($size < 1 || count($coefficients) !== $size || $lambda < 0.0 || ! is_finite($lambda)) {
            throw new InvalidArgumentException('BT-03E-07 smooth penalty input was invalid.');
        }
        $l2 = new Bt03e03CompensatedSum;
        foreach ($coefficients as $coefficient) {
            $l2->add($coefficient * $coefficient);
        }
        $smooth = new Bt03e03CompensatedSum;
        foreach ($layout->smoothEdges() as [$left, $right]) {
            $difference = $coefficients[$right] - $coefficients[$left];
            $smooth->add($difference * $difference);
        }
        $edges = $layout->smoothEdges();

        return $lambda * (($l2->value() / $size) + ($edges === [] ? 0.0 : $smooth->value() / count($edges)));
    }

    /** @param list<float> $coefficients @return list<float> */
    public function smoothPenaltyGradient(Bt03e02ParameterLayout $layout, array $coefficients, float $lambda): array
    {
        $gradient = array_map(static fn (float $value): float => 2.0 * $lambda * $value / $layout->size(), $coefficients);
        $edges = $layout->smoothEdges();
        if ($edges !== []) {
            $factor = 2.0 * $lambda / count($edges);
            foreach ($edges as [$left, $right]) {
                $difference = $coefficients[$right] - $coefficients[$left];
                $gradient[$left] -= $factor * $difference;
                $gradient[$right] += $factor * $difference;
            }
        }

        return $gradient;
    }

    /** @param list<float> $coefficients */
    public function groupPenalty(Bt03e02ParameterLayout $layout, array $coefficients, float $lambda): float
    {
        $sum = new Bt03e03CompensatedSum;
        foreach ($layout->groups() as $indexes) {
            $squares = new Bt03e03CompensatedSum;
            foreach ($indexes as $index) {
                $squares->add($coefficients[$index] * $coefficients[$index]);
            }
            $sum->add(sqrt($squares->value() / count($indexes)));
        }

        return $lambda * $sum->value() / count($layout->groups());
    }

    /** @param list<float> $values @return list<float> */
    public function groupProx(Bt03e02ParameterLayout $layout, array $values, float $step, float $lambda): array
    {
        foreach ($layout->groups() as $indexes) {
            $squares = new Bt03e03CompensatedSum;
            foreach ($indexes as $index) {
                $squares->add($values[$index] * $values[$index]);
            }
            $norm = sqrt($squares->value());
            $threshold = $step * $lambda / (count($layout->groups()) * sqrt(count($indexes)));
            $factor = $norm > 0.0 ? max(0.0, 1.0 - $threshold / $norm) : 0.0;
            foreach ($indexes as $index) {
                $values[$index] *= $factor;
            }
        }

        return $values;
    }

    /** @param list<float> $utilities */
    public function logSumExp(array $utilities): float
    {
        if ($utilities === [] || array_filter($utilities, 'is_finite') !== $utilities) {
            throw new RuntimeException('BT-03E-07 logsumexp input was empty or non-finite.');
        }
        $maximum = max($utilities);
        $sum = new Bt03e03CompensatedSum;
        foreach ($utilities as $utility) {
            $sum->add(exp($utility - $maximum));
        }

        return $maximum + log($sum->value());
    }

    /** @param array<string,mixed> $race @return array{candidates:list<array<string,mixed>>,actual_offset:int}|null */
    private function eligibleEntries(array $race, Bt03e02ParameterLayout $layout, int $targetRank): ?array
    {
        if (! is_array($race['entries'] ?? null) || $race['entries'] === []) {
            throw new RuntimeException('BT-03E-07 race entries were empty.');
        }
        $matches = [];
        foreach ($race['entries'] as $offset => $entry) {
            $this->assertEntry($entry, $layout);
            if (in_array($entry['status'], ['FINISHED', 'TIED'], true) && $entry['rank'] === $targetRank) {
                $matches[] = $offset;
            }
        }
        if (count($matches) !== 1) {
            return null;
        }

        return ['candidates' => array_values($race['entries']), 'actual_offset' => $matches[0]];
    }

    /** @param array<string,mixed> $entry @param list<float> $coefficients */
    private function utility(array $entry, array $coefficients): float
    {
        $sum = new Bt03e03CompensatedSum;
        $sum->add((float) $entry['anchor']);
        foreach ($entry['bins'] as $index) {
            if ($index !== null) {
                $sum->add($coefficients[$index]);
            }
        }

        return $sum->value();
    }

    /** @param list<float> $coefficients */
    private function targetRank(string $position, array $coefficients, Bt03e02ParameterLayout $layout): int
    {
        if (! in_array($position, Bt03e07Contract::POSITIONS, true) || count($coefficients) !== $layout->size()) {
            throw new InvalidArgumentException('BT-03E-07 objective input was invalid.');
        }

        return $position === 'POSITION_2' ? 2 : 3;
    }

    /** @param list<float> $sum @param list<float> $compensation */
    private function add(array &$sum, array &$compensation, int $index, float $value): void
    {
        $next = $sum[$index] + $value;
        $compensation[$index] += abs($sum[$index]) >= abs($value) ? ($sum[$index] - $next) + $value : ($value - $next) + $sum[$index];
        $sum[$index] = $next;
    }

    /** @param array<string,mixed> $entry */
    private function assertEntry(array $entry, Bt03e02ParameterLayout $layout): void
    {
        if (! is_numeric($entry['anchor'] ?? null) || ! is_finite((float) $entry['anchor'])
            || ! is_int($entry['bike'] ?? null) || ! is_array($entry['bins'] ?? null)
            || count($entry['bins']) !== count(Bt03e07Contract::STAT_CODES)
            || ! array_key_exists('rank', $entry) || ! is_string($entry['status'] ?? null)) {
            throw new RuntimeException('BT-03E-07 binned entry contract was invalid.');
        }
        foreach ($entry['bins'] as $index) {
            if ($index !== null && (! is_int($index) || $index < 0 || $index >= $layout->size())) {
                throw new RuntimeException('BT-03E-07 entry bin index was invalid.');
            }
        }
    }
}
