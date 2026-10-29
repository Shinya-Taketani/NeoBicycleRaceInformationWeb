<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Calculators;

use App\Domain\Keirin\Statistics\DTO\Batch02BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch02FeatureResultDto;
use App\Domain\Keirin\Statistics\DTO\Batch02TargetEntryDto;
use App\Domain\Keirin\Statistics\Enums\StatisticFeatureResultStatus;
use App\Domain\Keirin\Statistics\Enums\StatisticQualityStatus;
use App\Domain\Keirin\Statistics\Support\AcquiredHistorySupport;
use DateTimeImmutable;
use InvalidArgumentException;

final class Stat44Calculator
{
    public const VERSION = 'STAT-44-acquired-longitudinal-v1';

    public function __construct(private readonly AcquiredHistorySupport $history) {}

    public function calculate(Batch02TargetEntryDto $target, array $histories, Batch02BuildOptionsDto $options, ?DateTimeImmutable $birthDate,
        ?DateTimeImmutable $debutDate = null, ?array $debutEvidence = null): Batch02FeatureResultDto
    {
        $validated = $this->history->prepare($target, $histories, $options);
        if ($birthDate !== null && $birthDate >= $target->inputAsOf) {
            throw new InvalidArgumentException('Birth date must precede the target.');
        }
        if ($debutDate !== null && ($debutDate >= $target->inputAsOf || $debutEvidence === null)) {
            throw new InvalidArgumentException('Verified prior debut evidence is required.');
        }
        $periods = [];
        $last = $peak = null;
        foreach ($validated['events'] as $e) {
            $key = $e->scheduledStartAt->format('Y').'-Q'.(int) ceil((int) $e->scheduledStartAt->format('n') / 3);
            $periods[$key] ??= ['observed_rows' => 0, 'score_count' => 0, 'score_sum' => 0.0, 'residual_count' => 0, 'residual_sum' => 0.0];
            $periods[$key]['observed_rows']++;
            if ($e->raceScore !== null && is_numeric($e->raceScore) && is_finite((float) $e->raceScore)) {
                $score = (float) $e->raceScore;
                $periods[$key]['score_count']++;
                $periods[$key]['score_sum'] += $score;
                if ($peak === null || $score > (float) $peak->raceScore || ($score === (float) $peak->raceScore && $e->scheduledStartAt > $peak->scheduledStartAt)) {
                    $peak = $e;
                }
                if ($last === null || [$e->scheduledStartAt, $e->raceId] > [$last->scheduledStartAt, $last->raceId]) {
                    $last = $e;
                }
            }
            if ($e->scoreExpectationResidual !== null && $e->normalFinish()) {
                $periods[$key]['residual_count']++;
                $periods[$key]['residual_sum'] += $e->scoreExpectationResidual;
            }
        }
        ksort($periods);
        foreach ($periods as &$period) {
            $period['mean_score'] = $period['score_count'] > 0 ? $period['score_sum'] / $period['score_count'] : null;
            $period['mean_score_expectation_residual'] = $period['residual_count'] > 0 ? $period['residual_sum'] / $period['residual_count'] : null;
            unset($period['score_sum'], $period['residual_sum']);
        }
        unset($period);
        $features = ['age_years' => $birthDate?->diff($target->inputAsOf)->y, 'debut_date' => $debutDate?->format('Y-m-d'),
            'days_since_debut' => $debutDate === null ? null : (int) $debutDate->diff($target->inputAsOf)->format('%a'),
            'career_age_years' => $debutDate?->diff($target->inputAsOf)->y,
            'QUARTERLY_ACQUIRED_HISTORY' => $periods, 'past_observed_peak_score' => $peak === null ? null : (float) $peak->raceScore,
            'past_observed_peak_at' => $peak?->scheduledStartAt->format(DATE_ATOM),
            'last_score_minus_past_peak' => $last === null || $peak === null ? null : (float) $last->raceScore - (float) $peak->raceScore,
            'days_since_past_observed_peak' => $peak === null ? null : ($target->inputAsOf->getTimestamp() - $peak->scheduledStartAt->getTimestamp()) / 86400,
            'career_phase' => null, 'prediction_use' => 'NOT_AUTHORIZED', 'historical_as_of_available' => false];

        return new Batch02FeatureResultDto($target, $target->playerId === null ? StatisticFeatureResultStatus::MissingInput
            : ($validated['events'] === [] ? StatisticFeatureResultStatus::NoHistory : StatisticFeatureResultStatus::Partial), StatisticQualityStatus::Degraded, $features,
            $validated['evidence'] + ['quality_reasons' => ['PARTIAL_CAREER_HISTORY'],
                'debut_evidence' => $debutEvidence,
                'unavailable' => [...($debutDate === null ? ['verified_debut_date'] : []), 'age_cohort_comparison', 'rule_adjustment', 'career_phase_model'],
                'limitations' => ['PARTIAL_CAREER_HISTORY', 'UNADJUSTED_QUARTERLY_LEVELS_NOT_GROWTH_CAUSAL_EFFECT']],
            hash('sha256', self::VERSION.':'.$validated['hash'].':'.$birthDate?->format('Y-m-d').':'.$debutDate?->format('Y-m-d').':'.json_encode($debutEvidence, JSON_THROW_ON_ERROR)));
    }
}
