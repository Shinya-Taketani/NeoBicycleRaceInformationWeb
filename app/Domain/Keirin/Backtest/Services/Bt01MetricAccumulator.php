<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\DTO\CohortDecisionDto;
use App\Domain\Keirin\Backtest\Enums\BacktestCohort;
use App\Domain\Keirin\Backtest\Enums\BacktestMetricCode;

class Bt01MetricAccumulator
{
    /** @var array<string, array{sample: int, rank1_hits: int, top3_hits: int, rank1_size: int, top3_size: int}> */
    private array $cohorts = [];

    public function add(BacktestCohort $cohort, CohortDecisionDto $decision): void
    {
        if (! $decision->included) {
            return;
        }
        $values = $this->cohorts[$cohort->value] ?? ['sample' => 0, 'rank1_hits' => 0, 'top3_hits' => 0, 'rank1_size' => 0, 'top3_size' => 0];
        $values['sample']++;
        $values['rank1_hits'] += $decision->rank1Hit ? 1 : 0;
        $values['top3_hits'] += $decision->top3Hit ? 1 : 0;
        $values['rank1_size'] += $decision->rank1SetSize;
        $values['top3_size'] += $decision->top3SetSize;
        $this->cohorts[$cohort->value] = $values;
    }

    /** @return list<array{cohort: string, metric: string, numerator: int, denominator: int, sample_count: int, value: ?float}> */
    public function rows(int $targetRaces, int $predictedRaces): array
    {
        $rows = [];
        foreach (BacktestCohort::cases() as $cohort) {
            $values = $this->cohorts[$cohort->value] ?? ['sample' => 0, 'rank1_hits' => 0, 'top3_hits' => 0, 'rank1_size' => 0, 'top3_size' => 0];
            $rows[] = $this->row($cohort, BacktestMetricCode::FeatureCoverageRate, $predictedRaces, $targetRaces, $targetRaces);
            $rows[] = $this->row($cohort, BacktestMetricCode::Rank1SetWinHitRate, $values['rank1_hits'], $values['sample'], $values['sample']);
            $rows[] = $this->row($cohort, BacktestMetricCode::Top3SetWinHitRate, $values['top3_hits'], $values['sample'], $values['sample']);
            $rows[] = $this->row($cohort, BacktestMetricCode::Rank1SetSizeMean, $values['rank1_size'], $values['sample'], $values['sample']);
            $rows[] = $this->row($cohort, BacktestMetricCode::Top3SetSizeMean, $values['top3_size'], $values['sample'], $values['sample']);
        }

        return $rows;
    }

    /** @return array{cohort: string, metric: string, numerator: int, denominator: int, sample_count: int, value: ?float} */
    private function row(BacktestCohort $cohort, BacktestMetricCode $metric, int $numerator, int $denominator, int $sampleCount): array
    {
        return [
            'cohort' => $cohort->value,
            'metric' => $metric->value,
            'numerator' => $numerator,
            'denominator' => $denominator,
            'sample_count' => $sampleCount,
            'value' => $denominator > 0 ? $numerator / $denominator : null,
        ];
    }
}
