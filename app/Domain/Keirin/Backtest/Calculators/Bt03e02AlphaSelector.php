<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\Services\Bt03e02Contract;
use RuntimeException;

final class Bt03e02AlphaSelector
{
    private const PRIMARY = [
        'WINNER_HIT_AT_1',
        'POSITION_2_ACCURACY',
        'POSITION_3_ACCURACY',
        'POSITION_HIT_RATE_AT_3',
    ];

    public function __construct(private readonly Bt03e02MetricEvaluator $metrics) {}

    /**
     * @param  callable(): iterable<array<string,mixed>>  $predictionSource
     * @param  list<string>  $degenerateChannels
     * @return array{alpha:array{IS_WIN:float,IS_TOP2:float,IS_TOP3:float,key:string},metrics:array<string,mixed>,candidate_count:int}
     */
    public function select(callable $predictionSource, array $degenerateChannels = []): array
    {
        $evaluated = [];
        foreach (Bt03e02Contract::alphaCandidates($degenerateChannels) as $alpha) {
            $evaluated[] = ['alpha' => $alpha, 'metrics' => $this->metrics->evaluatePaired($predictionSource, $alpha)];
        }
        if ($evaluated === []) {
            throw new RuntimeException('BT-03E-02 alpha grid had no valid candidates.');
        }
        $frontier = array_values(array_filter($evaluated, function (array $candidate) use ($evaluated): bool {
            foreach ($evaluated as $other) {
                if ($other === $candidate) {
                    continue;
                }
                $allAtLeast = true;
                $oneGreater = false;
                foreach (self::PRIMARY as $metric) {
                    $allAtLeast = $allAtLeast && $other['metrics']['delta'][$metric] >= $candidate['metrics']['delta'][$metric];
                    $oneGreater = $oneGreater || $other['metrics']['delta'][$metric] > $candidate['metrics']['delta'][$metric];
                }
                if ($allAtLeast && $oneGreater) {
                    return false;
                }
            }

            return true;
        }));
        usort($frontier, fn (array $left, array $right): int => $this->compare($left, $right));

        return [
            'alpha' => $frontier[0]['alpha'],
            'metrics' => $frontier[0]['metrics'],
            'candidate_count' => count($evaluated),
        ];
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function compare(array $left, array $right): int
    {
        $leftDelta = $left['metrics']['delta'];
        $rightDelta = $right['metrics']['delta'];
        $criteria = [
            min(array_intersect_key($leftDelta, array_flip(self::PRIMARY))) <=> min(array_intersect_key($rightDelta, array_flip(self::PRIMARY))),
            $leftDelta['POSITION_HIT_RATE_AT_3'] <=> $rightDelta['POSITION_HIT_RATE_AT_3'],
            $leftDelta['EXACT_ORDERED_TOP3_RATE'] <=> $rightDelta['EXACT_ORDERED_TOP3_RATE'],
            $leftDelta['EXACT_TOP3_SET_RATE'] <=> $rightDelta['EXACT_TOP3_SET_RATE'],
            $leftDelta['NDCG_AT_3'] <=> $rightDelta['NDCG_AT_3'],
        ];
        foreach ($criteria as $criterion) {
            if ($criterion !== 0) {
                return -$criterion;
            }
        }

        return $left['alpha']['key'] <=> $right['alpha']['key'];
    }
}
