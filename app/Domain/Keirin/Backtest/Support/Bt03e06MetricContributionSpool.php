<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Support;

final class Bt03e06MetricContributionSpool
{
    private readonly Bt03e05MetricContributionSpool $inner;

    public function __construct(string $path)
    {
        $this->inner = new Bt03e05MetricContributionSpool($path);
    }

    /** @param array<string,mixed> $comparison */
    public function append(array $comparison): void
    {
        $this->inner->append($comparison);
    }

    public function seal(): void
    {
        $this->inner->seal();
    }

    public function raceCount(): int
    {
        return $this->inner->raceCount();
    }

    /** @return \Generator<int,list<float>> */
    public function records(): \Generator
    {
        yield from $this->inner->records();
    }

    public function e05Spool(): Bt03e05MetricContributionSpool
    {
        return $this->inner;
    }

    public function cleanup(): void
    {
        $this->inner->cleanup();
    }
}
