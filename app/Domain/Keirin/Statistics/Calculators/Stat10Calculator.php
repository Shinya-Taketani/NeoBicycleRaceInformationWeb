<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Calculators;

use App\Domain\Keirin\Statistics\Contracts\Batch02Calculator;
use App\Domain\Keirin\Statistics\DTO\Batch02BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch02FeatureResultDto;
use App\Domain\Keirin\Statistics\DTO\Batch02TargetEntryDto;
use App\Domain\Keirin\Statistics\DTO\HistoricalRaceDto;
use App\Domain\Keirin\Statistics\Enums\Batch02Stat;
use App\Domain\Keirin\Statistics\Support\Batch02CalculatorSupport;
use App\Domain\Keirin\Statistics\Support\StatisticalMath;

class Stat10Calculator implements Batch02Calculator
{
    private const COUNT_WINDOWS = [3, 5, 10, 20];

    private const DAY_WINDOWS = [30, 60, 90, 180, 365];

    public function __construct(
        private readonly Batch02CalculatorSupport $support,
        private readonly StatisticalMath $math,
    ) {}

    public function stat(): Batch02Stat
    {
        return Batch02Stat::Stat10;
    }

    public function calculate(
        Batch02TargetEntryDto $target,
        array $histories,
        Batch02BuildOptionsDto $options,
        string $batchExecutionUuid,
    ): Batch02FeatureResultDto {
        $context = $this->support->context($target, $histories, $options, $batchExecutionUuid, $this->stat());
        $pre = array_values(array_filter($context->preMeeting, fn (HistoricalRaceDto $event): bool => $event->normalFinish()));
        $inMeeting = array_values(array_filter($context->inMeeting, fn (HistoricalRaceDto $event): bool => $event->normalFinish()));
        $countWindows = [];
        $dayWindows = [];
        $complete = true;
        foreach (self::COUNT_WINDOWS as $size) {
            $window = $this->support->countWindow($pre, $size);
            $countWindows[(string) $size] = [...$window['metadata'], ...$this->metrics($window['events'])];
            $complete = $complete && $window['metadata']['window_complete'];
        }
        foreach (self::DAY_WINDOWS as $days) {
            $window = $this->support->dayWindow($pre, $target, $options->historyFrom, $days);
            $dayWindows[(string) $days] = [...$window['metadata'], ...$this->metrics($window['events'])];
            $complete = $complete && $window['metadata']['window_complete'];
        }

        $features = [
            'PRE_MEETING' => [
                'COUNT_WINDOWS' => $countWindows,
                'DAY_WINDOWS' => $dayWindows,
            ],
            'IN_MEETING' => $this->inMeetingMetrics($inMeeting),
            'SUMMARY' => [
                'mean_finish_percentile_3_minus_10' => $this->difference($countWindows['3']['mean_finish_strength_percentile'], $countWindows['10']['mean_finish_strength_percentile']),
                'mean_finish_percentile_5_minus_20' => $this->difference($countWindows['5']['mean_finish_strength_percentile'], $countWindows['20']['mean_finish_strength_percentile']),
                'mean_residual_3_minus_10' => $this->difference($countWindows['3']['mean_score_expectation_residual'], $countWindows['10']['mean_score_expectation_residual']),
                'mean_residual_5_minus_20' => $this->difference($countWindows['5']['mean_score_expectation_residual'], $countWindows['20']['mean_score_expectation_residual']),
                ...$this->streaks($pre),
            ],
        ];

        return $this->support->result($target, $options, $context, $this->stat(), $features, $complete);
    }

    /** @param list<HistoricalRaceDto> $events */
    private function metrics(array $events): array
    {
        $count = count($events);
        $ranks = array_values(array_filter(array_map(fn (HistoricalRaceDto $event): ?int => $event->rank, $events), fn (?int $value): bool => $value !== null));
        $percentiles = array_values(array_filter(array_map(fn (HistoricalRaceDto $event): ?float => $event->finishStrengthPercentile, $events), fn (?float $value): bool => $value !== null));
        $residuals = array_values(array_filter(array_map(fn (HistoricalRaceDto $event): ?float => $event->scoreExpectationResidual, $events), fn (?float $value): bool => $value !== null));
        $wins = count(array_filter($events, fn (HistoricalRaceDto $event): bool => $event->rank === 1));
        $top2 = count(array_filter($events, fn (HistoricalRaceDto $event): bool => $event->rank !== null && $event->rank <= 2));
        $top3 = count(array_filter($events, fn (HistoricalRaceDto $event): bool => $event->rank !== null && $event->rank <= 3));

        return [
            'mean_rank' => $this->math->mean($ranks),
            'mean_finish_strength_percentile' => $this->math->mean($percentiles),
            'win_count' => $wins,
            'win_rate' => $count > 0 ? $wins / $count : null,
            'top2_count' => $top2,
            'top2_rate' => $count > 0 ? $top2 / $count : null,
            'top3_count' => $top3,
            'top3_rate' => $count > 0 ? $top3 / $count : null,
            'residual_sample_count' => count($residuals),
            'mean_score_expectation_residual' => $this->math->mean($residuals),
        ];
    }

    /** @param list<HistoricalRaceDto> $events */
    private function inMeetingMetrics(array $events): array
    {
        $metrics = $this->metrics($events);

        return [
            'sample_count' => count($events),
            'mean_finish_strength_percentile' => $metrics['mean_finish_strength_percentile'],
            'top3_rate' => $metrics['top3_rate'],
            'mean_score_expectation_residual' => $metrics['mean_score_expectation_residual'],
        ];
    }

    /** @param list<HistoricalRaceDto> $events */
    private function streaks(array $events): array
    {
        $top3 = $outside = 0;
        for ($index = count($events) - 1; $index >= 0; $index--) {
            $isTop3 = $events[$index]->rank !== null && $events[$index]->rank <= 3;
            if ($index === count($events) - 1) {
                $isTop3 ? $top3++ : $outside++;
            } elseif (($top3 > 0 && $isTop3) || ($outside > 0 && ! $isTop3)) {
                $isTop3 ? $top3++ : $outside++;
            } else {
                break;
            }
        }

        return ['current_top3_streak' => $top3, 'current_outside_top3_streak' => $outside];
    }

    private function difference(?float $left, ?float $right): ?float
    {
        return $left !== null && $right !== null ? $left - $right : null;
    }
}
