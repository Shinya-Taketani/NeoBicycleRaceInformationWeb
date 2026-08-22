<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\DTO\Bt03eCandidateDto;
use App\Domain\Keirin\Backtest\DTO\Bt03eMetricSummaryDto;
use App\Domain\Keirin\Backtest\Services\Bt03eContract;
use RuntimeException;

class Bt03eCoordinateDescentOptimizer
{
    public function __construct(private readonly Bt03eRaceMetricEvaluator $metrics) {}

    /**
     * @param  callable(): iterable<array{race_id: int, entries: list<array{id: int, bike: int, raw: float, directions: list<int>, rank: ?int, status: string}>}>  $raceSource
     * @return array{candidate: Bt03eCandidateDto, metrics: Bt03eMetricSummaryDto, evaluated_candidate_count: int, starts: int}
     */
    public function optimize(callable $raceSource): array
    {
        $cache = [];
        $evaluate = function (Bt03eCandidateDto $candidate) use (&$cache, $raceSource): Bt03eMetricSummaryDto {
            return $cache[$candidate->key()] ??= $this->metrics->evaluate($raceSource(), $candidate);
        };
        $finalists = [];
        foreach ($this->starts() as $candidate) {
            $currentMetrics = $evaluate($candidate);
            for ($pass = 0; $pass < 20; $pass++) {
                $changed = false;
                foreach (['BASE_STEP', ...Bt03eContract::STAT_CODES] as $coordinate) {
                    $bestCandidate = $candidate;
                    $bestMetrics = $currentMetrics;
                    $grid = $coordinate === 'BASE_STEP' ? Bt03eContract::BASE_STEP_GRID : Bt03eContract::WEIGHT_GRID;
                    foreach ($grid as $value) {
                        $challenger = $this->with($candidate, $coordinate, $value);
                        $challengerMetrics = $evaluate($challenger);
                        if ($this->better($challenger, $challengerMetrics, $bestCandidate, $bestMetrics)) {
                            $bestCandidate = $challenger;
                            $bestMetrics = $challengerMetrics;
                        }
                    }
                    if ($bestCandidate->key() !== $candidate->key()) {
                        $changed = true;
                        $candidate = $bestCandidate;
                        $currentMetrics = $bestMetrics;
                    }
                }
                if (! $changed) {
                    break;
                }
                if ($pass === 19) {
                    throw new RuntimeException('BT-03E coordinate descent did not converge deterministically.');
                }
            }
            $finalists[] = [$candidate, $currentMetrics];
        }

        [$winner, $winnerMetrics] = $finalists[0];
        foreach (array_slice($finalists, 1) as [$candidate, $candidateMetrics]) {
            if ($this->better($candidate, $candidateMetrics, $winner, $winnerMetrics)) {
                $winner = $candidate;
                $winnerMetrics = $candidateMetrics;
            }
        }

        return [
            'candidate' => $winner,
            'metrics' => $winnerMetrics,
            'evaluated_candidate_count' => count($cache),
            'starts' => count($finalists),
        ];
    }

    /** @return list<Bt03eCandidateDto> */
    private function starts(): array
    {
        $zero = $ten = [];
        foreach (Bt03eContract::STAT_CODES as $statCode) {
            $zero[$statCode] = 0;
            $ten[$statCode] = 10;
        }
        $prior = [
            'STAT-07' => 20, 'STAT-08' => 20, 'STAT-10' => 5, 'STAT-11' => 10,
            'STAT-12' => 5, 'STAT-23' => 10, 'STAT-24' => 10, 'STAT-26' => 5,
            'STAT-31' => 5, 'STAT-32' => 20, 'STAT-39' => 20, 'STAT-42' => 20,
        ];

        return [
            new Bt03eCandidateDto(0, $zero),
            new Bt03eCandidateDto(10, $ten),
            new Bt03eCandidateDto(20, $prior),
        ];
    }

    private function with(Bt03eCandidateDto $candidate, string $coordinate, int $value): Bt03eCandidateDto
    {
        if ($coordinate === 'BASE_STEP') {
            return new Bt03eCandidateDto($value, $candidate->weights);
        }
        $weights = $candidate->weights;
        $weights[$coordinate] = $value;

        return new Bt03eCandidateDto($candidate->baseStep, $weights);
    }

    private function better(
        Bt03eCandidateDto $left,
        Bt03eMetricSummaryDto $leftMetrics,
        Bt03eCandidateDto $right,
        Bt03eMetricSummaryDto $rightMetrics,
    ): bool {
        foreach (['POSITION_HIT_RATE_AT_3', 'WINNER_HIT_AT_1', 'EXACT_TOP3_SET_RATE', 'TOP3_COVERAGE_AT_3', 'EXACT_ORDERED_TOP3_RATE'] as $metric) {
            $comparison = $leftMetrics->metrics[$metric] <=> $rightMetrics->metrics[$metric];
            if ($comparison !== 0) {
                return $comparison > 0;
            }
        }
        if ($left->complexity() !== $right->complexity()) {
            return $left->complexity() < $right->complexity();
        }

        return strcmp($left->key(), $right->key()) < 0;
    }
}
