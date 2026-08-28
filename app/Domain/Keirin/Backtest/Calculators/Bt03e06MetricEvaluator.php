<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

final class Bt03e06MetricEvaluator
{
    public const METRIC_CODES = Bt03e05MetricEvaluator::METRIC_CODES;

    public function __construct(private readonly Bt03e05MetricEvaluator $inner) {}

    /** @return array<string,mixed> */
    public function emptySummary(): array
    {
        return $this->inner->emptySummary();
    }

    /** @param array<string,mixed> $summary @param array<string,mixed> $comparison */
    public function add(array &$summary, array $comparison): void
    {
        $this->inner->add($summary, $comparison);
    }

    /** @param array<string,mixed> $summary @return array<string,mixed> */
    public function finish(array $summary): array
    {
        return $this->inner->finish($summary);
    }

    /** @param array<string,mixed> $context @param array<string,mixed> $decision @return array<string,mixed> */
    public function raceComparison(array $context, array $decision): array
    {
        return $this->inner->raceComparison($context, $decision);
    }
}
