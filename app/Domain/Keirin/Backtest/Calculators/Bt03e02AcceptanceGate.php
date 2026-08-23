<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

final class Bt03e02AcceptanceGate
{
    private const PRIMARY = ['WINNER_HIT_AT_1', 'POSITION_2_ACCURACY', 'POSITION_3_ACCURACY', 'POSITION_HIT_RATE_AT_3'];

    private const SUPPORTING = ['EXACT_ORDERED_TOP3_RATE', 'EXACT_TOP3_SET_RATE', 'TOP3_COVERAGE_AT_3', 'EXACT_TOP2_SET_RATE', 'TOP2_COVERAGE_AT_2', 'NDCG_AT_3'];

    /**
     * @param  array<int,array<string,mixed>>  $outerResults
     * @param  array<string,array{ci_lower:float,ci_upper:float}>  $intervals
     * @return array<string,mixed>
     */
    public function evaluate(array $outerResults, array $intervals, bool $integrity): array
    {
        $nonInferiority = $integrity;
        foreach (self::PRIMARY as $metric) {
            $nonInferiority = $nonInferiority && ($intervals[$metric]['ci_lower'] ?? -INF) > -0.0015;
        }
        $superiorityA = ($intervals['POSITION_HIT_RATE_AT_3']['ci_lower'] ?? -INF) > 0.0;
        $superiorityB = count(array_filter(
            ['WINNER_HIT_AT_1', 'POSITION_2_ACCURACY', 'POSITION_3_ACCURACY'],
            static fn (string $metric): bool => ($intervals[$metric]['ci_lower'] ?? -INF) > 0.0,
        )) >= 1;
        $yearEqual = [];
        foreach (Bt03e02MetricEvaluator::METRIC_CODES as $metric) {
            $yearEqual[$metric] = array_sum(array_map(static fn (array $result): float => $result['delta'][$metric], $outerResults)) / count($outerResults);
        }
        $superiorityC = count(array_filter(self::PRIMARY, static fn (string $metric): bool => $yearEqual[$metric] > 0.0)) >= 3;
        $superiority = $superiorityA && $superiorityB && $superiorityC;
        $temporal = true;
        foreach ($outerResults as $result) {
            foreach (self::PRIMARY as $metric) {
                $temporal = $temporal && $result['delta'][$metric] >= -0.0030;
            }
        }
        $supporting = count(array_filter(self::SUPPORTING, static fn (string $metric): bool => $yearEqual[$metric] >= 0.0)) >= 4
            && count(array_filter(self::SUPPORTING, static fn (string $metric): bool => $yearEqual[$metric] < -0.0020)) === 0;
        $candidateTies = $baselineTies = $technicalTies = $races = 0;
        foreach ($outerResults as $result) {
            $diagnostics = $result['tie_diagnostics'];
            $candidateTies += $diagnostics['exact_ranking_score_tied_races'];
            $baselineTies += $diagnostics['baseline_exact_score_tied_races'];
            $technicalTies += $diagnostics['technical_tiebreak_races'];
            $races += $result['race_count'];
        }
        $tieQuality = $races > 0 && $candidateTies / $races <= $baselineTies / $races && $technicalTies / $races <= 0.001;
        $gates = [
            'integrity' => $integrity,
            'non_inferiority' => $nonInferiority,
            'superiority' => $superiority,
            'temporal_stability' => $temporal,
            'supporting' => $supporting,
            'tie_quality' => $tieQuality,
        ];
        $status = match (true) {
            ! $integrity || ! $nonInferiority || ! $temporal || ! $supporting || ! $tieQuality => 'FAIL / REDESIGN_REQUIRED',
            ! $superiority => 'HOLD / PROMISING_NOT_ADOPTABLE',
            default => 'PASS / GO_TO_FREEZE',
        };

        return ['status' => $status, 'gates' => $gates, 'year_equal_deltas' => $yearEqual];
    }
}
