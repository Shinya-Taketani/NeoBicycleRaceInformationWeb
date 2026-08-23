<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\Services\Bt03e02Contract;
use InvalidArgumentException;
use RuntimeException;

final class Bt03e02PairwiseObjective
{
    /**
     * @param  callable(): iterable<array<string, mixed>>  $raceSource
     * @param  list<float>  $coefficients
     * @return array{loss: float, gradient: list<float>, eligible_races: int, excluded_races: int}
     */
    public function lossAndGradient(callable $raceSource, Bt03e02ParameterLayout $layout, array $coefficients, string $channel): array
    {
        $channelIndex = array_search($channel, Bt03e02Contract::CHANNELS, true);
        if ($channelIndex === false || count($coefficients) !== $layout->size()) {
            throw new InvalidArgumentException('BT-03E-02 pairwise objective input was invalid.');
        }
        $gradient = array_fill(0, $layout->size(), 0.0);
        $gradientCompensation = array_fill(0, $layout->size(), 0.0);
        $loss = new Bt03e02CompensatedSum;
        $eligible = $excluded = 0;

        foreach ($raceSource() as $race) {
            $positive = $negative = [];
            foreach ($race['entries'] as $entry) {
                $this->assertEntry($entry, $layout);
                if (($entry['labels'][$channelIndex] ?? null) === true) {
                    $positive[] = $entry;
                } else {
                    $negative[] = $entry;
                }
            }
            if ($positive === [] || $negative === []) {
                $excluded++;

                continue;
            }
            $eligible++;
            $pairCount = count($positive) * count($negative);
            foreach ($positive as $positiveEntry) {
                $positiveScore = $this->score($positiveEntry, $coefficients);
                foreach ($negative as $negativeEntry) {
                    $negativeScore = $this->score($negativeEntry, $coefficients);
                    $difference = $positiveScore - $negativeScore;
                    $loss->add($this->softplus(-$difference) / $pairCount);
                    $derivative = $this->lossDerivative($difference) / $pairCount;
                    foreach ($positiveEntry['bins'] as $index) {
                        if ($index !== null) {
                            $this->add($gradient, $gradientCompensation, $index, $derivative);
                        }
                    }
                    foreach ($negativeEntry['bins'] as $index) {
                        if ($index !== null) {
                            $this->add($gradient, $gradientCompensation, $index, -$derivative);
                        }
                    }
                }
            }
        }
        if ($eligible === 0) {
            throw new RuntimeException("BT-03E-02 {$channel} had no pairwise-eligible races.");
        }
        foreach ($gradient as $index => $value) {
            $gradient[$index] = ($value + $gradientCompensation[$index]) / $eligible;
        }

        return [
            'loss' => $loss->value() / $eligible,
            'gradient' => $gradient,
            'eligible_races' => $eligible,
            'excluded_races' => $excluded,
        ];
    }

    /**
     * @param  callable(): iterable<array<string, mixed>>  $raceSource
     * @param  list<float>  $coefficients
     */
    public function loss(callable $raceSource, Bt03e02ParameterLayout $layout, array $coefficients, string $channel): float
    {
        return $this->lossAndGradient($raceSource, $layout, $coefficients, $channel)['loss'];
    }

    /**
     * @param  callable(): iterable<array<string, mixed>>  $raceSource
     * @param  list<float>  $coefficients
     * @return array<int, float>
     */
    public function raceLosses(callable $raceSource, Bt03e02ParameterLayout $layout, array $coefficients, string $channel): array
    {
        $channelIndex = array_search($channel, Bt03e02Contract::CHANNELS, true);
        if ($channelIndex === false) {
            throw new InvalidArgumentException('BT-03E-02 race loss channel was invalid.');
        }
        $losses = [];
        foreach ($raceSource() as $race) {
            $loss = $this->raceLoss($race, $layout, $coefficients, $channel);
            if ($loss !== null) {
                $losses[(int) $race['race_id']] = $loss;
            }
        }

        return $losses;
    }

    /** @param array<string,mixed> $race @param list<float> $coefficients */
    public function raceLoss(array $race, Bt03e02ParameterLayout $layout, array $coefficients, string $channel): ?float
    {
        $channelIndex = array_search($channel, Bt03e02Contract::CHANNELS, true);
        if ($channelIndex === false || count($coefficients) !== $layout->size()) {
            throw new InvalidArgumentException('BT-03E-02 race loss input was invalid.');
        }
        $positive = $negative = [];
        foreach ($race['entries'] as $entry) {
            $this->assertEntry($entry, $layout);
            if (($entry['labels'][$channelIndex] ?? false) === true) {
                $positive[] = $entry;
            } else {
                $negative[] = $entry;
            }
        }
        if ($positive === [] || $negative === []) {
            return null;
        }
        $sum = new Bt03e02CompensatedSum;
        $pairs = count($positive) * count($negative);
        foreach ($positive as $positiveEntry) {
            foreach ($negative as $negativeEntry) {
                $sum->add($this->softplus(-($this->score($positiveEntry, $coefficients) - $this->score($negativeEntry, $coefficients))));
            }
        }

        return $sum->value() / $pairs;
    }

    /** @param list<float> $coefficients */
    public function smoothPenalty(Bt03e02ParameterLayout $layout, array $coefficients, float $lambda): float
    {
        $size = $layout->size();
        if ($size < 1 || $lambda < 0.0 || ! is_finite($lambda)) {
            throw new InvalidArgumentException('BT-03E-02 smooth penalty input was invalid.');
        }
        $l2 = new Bt03e02CompensatedSum;
        foreach ($coefficients as $coefficient) {
            $l2->add($coefficient * $coefficient);
        }
        $edges = $layout->smoothEdges();
        $smooth = new Bt03e02CompensatedSum;
        foreach ($edges as [$left, $right]) {
            $difference = $coefficients[$right] - $coefficients[$left];
            $smooth->add($difference * $difference);
        }

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
        $groups = $layout->groups();
        $sum = new Bt03e02CompensatedSum;
        foreach ($groups as $indexes) {
            $squares = new Bt03e02CompensatedSum;
            foreach ($indexes as $index) {
                $squares->add($coefficients[$index] * $coefficients[$index]);
            }
            $sum->add(sqrt($squares->value() / count($indexes)));
        }

        return $lambda * $sum->value() / count($groups);
    }

    /** @param list<float> $values @return list<float> */
    public function groupProx(Bt03e02ParameterLayout $layout, array $values, float $step, float $lambda): array
    {
        foreach ($layout->groups() as $indexes) {
            $squares = new Bt03e02CompensatedSum;
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

    /** @param array<string, mixed> $entry @param list<float> $coefficients */
    private function score(array $entry, array $coefficients): float
    {
        $sum = new Bt03e02CompensatedSum;
        $sum->add((float) $entry['anchor']);
        foreach ($entry['bins'] as $index) {
            if ($index !== null) {
                $sum->add($coefficients[$index]);
            }
        }

        return $sum->value();
    }

    private function softplus(float $value): float
    {
        return $value > 0.0
            ? $value + log1p(exp(-$value))
            : log1p(exp($value));
    }

    private function lossDerivative(float $difference): float
    {
        if ($difference >= 0.0) {
            $exponential = exp(-$difference);

            return -$exponential / (1.0 + $exponential);
        }

        return -1.0 / (1.0 + exp($difference));
    }

    /** @param list<float> $sum @param list<float> $compensation */
    private function add(array &$sum, array &$compensation, int $index, float $value): void
    {
        $next = $sum[$index] + $value;
        $compensation[$index] += abs($sum[$index]) >= abs($value)
            ? ($sum[$index] - $next) + $value
            : ($value - $next) + $sum[$index];
        $sum[$index] = $next;
    }

    /** @param array<string, mixed> $entry */
    private function assertEntry(array $entry, Bt03e02ParameterLayout $layout): void
    {
        if (! is_float($entry['anchor'] ?? null) && ! is_int($entry['anchor'] ?? null)) {
            throw new RuntimeException('BT-03E-02 entry anchor was invalid.');
        }
        if (! is_finite((float) $entry['anchor'])
            || ! is_array($entry['bins'] ?? null)
            || count($entry['bins']) !== count(Bt03e02Contract::STAT_CODES)
            || ! is_array($entry['labels'] ?? null)
            || count($entry['labels']) !== count(Bt03e02Contract::CHANNELS)) {
            throw new RuntimeException('BT-03E-02 binned entry contract was invalid.');
        }
        foreach ($entry['bins'] as $index) {
            if ($index !== null && (! is_int($index) || $index < 0 || $index >= $layout->size())) {
                throw new RuntimeException('BT-03E-02 entry bin index was invalid.');
            }
        }
    }
}
