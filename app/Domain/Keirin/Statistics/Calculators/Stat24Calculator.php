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

class Stat24Calculator implements Batch02Calculator
{
    private const COUNT_WINDOWS = [3, 5, 10, 20, 30];

    private const DAY_WINDOWS = [60, 90, 180, 365];

    public function __construct(
        private readonly Batch02CalculatorSupport $support,
        private readonly StatisticalMath $math,
    ) {}

    public function stat(): Batch02Stat
    {
        return Batch02Stat::Stat24;
    }

    public function calculate(Batch02TargetEntryDto $target, array $histories, Batch02BuildOptionsDto $options, string $batchExecutionUuid): Batch02FeatureResultDto
    {
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
        $inMetrics = $this->metrics($inMeeting);

        return $this->support->result($target, $options, $context, $this->stat(), [
            'PRE_MEETING' => ['COUNT_WINDOWS' => $countWindows, 'DAY_WINDOWS' => $dayWindows],
            'IN_MEETING' => [
                'sample_count' => count($inMeeting),
                'finish_percentile_stddev_pop' => $inMetrics['finish_percentile_stddev_pop'],
                'residual_stddev_pop' => $inMetrics['residual_stddev_pop'],
            ],
        ], $complete);
    }

    /** @param list<HistoricalRaceDto> $events */
    private function metrics(array $events): array
    {
        $percentiles = array_values(array_filter(array_map(fn (HistoricalRaceDto $event): ?float => $event->finishStrengthPercentile, $events), fn (?float $value): bool => $value !== null));
        $residuals = array_values(array_filter(array_map(fn (HistoricalRaceDto $event): ?float => $event->scoreExpectationResidual, $events), fn (?float $value): bool => $value !== null));
        $positive = array_values(array_filter($residuals, fn (float $value): bool => $value > 0));
        $negative = array_values(array_filter($residuals, fn (float $value): bool => $value < 0));
        $switches = 0;
        for ($index = 1; $index < count($events); $index++) {
            $previousTop3 = $events[$index - 1]->rank !== null && $events[$index - 1]->rank <= 3;
            $currentTop3 = $events[$index]->rank !== null && $events[$index]->rank <= 3;
            if ($previousTop3 !== $currentTop3) {
                $switches++;
            }
        }

        return [
            'finish_percentile_stddev_pop' => $this->math->populationStandardDeviation($percentiles),
            'finish_percentile_mad' => $this->math->medianAbsoluteDeviation($percentiles),
            'finish_percentile_iqr' => $this->math->interquartileRange($percentiles),
            'residual_sample_count' => count($residuals),
            'residual_stddev_pop' => $this->math->populationStandardDeviation($residuals),
            'residual_mad' => $this->math->medianAbsoluteDeviation($residuals),
            'residual_iqr' => $this->math->interquartileRange($residuals),
            'upside_count' => count($positive),
            'upside_rate' => $residuals !== [] ? count($positive) / count($residuals) : null,
            'downside_count' => count($negative),
            'downside_rate' => $residuals !== [] ? count($negative) / count($residuals) : null,
            'mean_positive_residual' => $this->math->mean($positive),
            'mean_negative_residual' => $this->math->mean($negative),
            'top3_switch_count' => $switches,
        ];
    }
}
