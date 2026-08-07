<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Calculators;

use App\Domain\Keirin\Statistics\Contracts\Batch02Calculator;
use App\Domain\Keirin\Statistics\DTO\Batch02BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch02FeatureResultDto;
use App\Domain\Keirin\Statistics\DTO\Batch02TargetEntryDto;
use App\Domain\Keirin\Statistics\DTO\HistoricalRaceDto;
use App\Domain\Keirin\Statistics\Enums\Batch02Stat;
use App\Domain\Keirin\Statistics\Enums\HistoricalResultState;
use App\Domain\Keirin\Statistics\Support\Batch02CalculatorSupport;
use DateTimeImmutable;

class Stat11Calculator implements Batch02Calculator
{
    private const COUNT_WINDOWS = [3, 5, 10];

    private const DAY_WINDOWS = [30, 90, 180];

    public function __construct(private readonly Batch02CalculatorSupport $support) {}

    public function stat(): Batch02Stat
    {
        return Batch02Stat::Stat11;
    }

    public function calculate(Batch02TargetEntryDto $target, array $histories, Batch02BuildOptionsDto $options, string $batchExecutionUuid): Batch02FeatureResultDto
    {
        $context = $this->support->context($target, $histories, $options, $batchExecutionUuid, $this->stat());
        $started = array_values(array_filter($context->histories, fn (HistoricalRaceDto $event): bool => $event->started()));
        $countWindows = [];
        $dayWindows = [];
        $complete = true;
        foreach (self::COUNT_WINDOWS as $size) {
            $window = $this->support->countWindow($started, $size);
            $countWindows[(string) $size] = [...$window['metadata'], ...$this->metrics($window['events'])];
            $complete = $complete && $window['metadata']['window_complete'];
        }
        foreach (self::DAY_WINDOWS as $days) {
            $window = $this->support->dayWindow($context->histories, $target, $options->historyFrom, $days);
            $dayWindows[(string) $days] = [...$window['metadata'], ...$this->metrics($window['events'])];
            $complete = $complete && $window['metadata']['window_complete'];
        }

        $features = [
            'COUNT_WINDOWS' => $countWindows,
            'DAY_WINDOWS' => $dayWindows,
            'ACQUIRED_HISTORY' => $this->metrics($context->histories),
            'SUMMARY' => $this->recency($started, $target->inputAsOf),
        ];

        return $this->support->result($target, $options, $context, $this->stat(), $features, $complete);
    }

    /** @param list<HistoricalRaceDto> $events */
    private function metrics(array $events): array
    {
        $started = array_values(array_filter($events, fn (HistoricalRaceDto $event): bool => $event->started()));
        $denominator = count($started);
        $counts = [
            'normal_finish' => count(array_filter($started, fn (HistoricalRaceDto $event): bool => $event->normalFinish())),
            'abnormal' => count(array_filter($started, fn (HistoricalRaceDto $event): bool => $event->abnormal())),
            'disqualified' => count(array_filter($started, fn (HistoricalRaceDto $event): bool => $event->resultState === HistoricalResultState::Disqualified)),
            'fall_dnf' => count(array_filter($started, fn (HistoricalRaceDto $event): bool => $event->resultState === HistoricalResultState::FallDnf)),
            'other_dnf' => count(array_filter($started, fn (HistoricalRaceDto $event): bool => $event->resultState === HistoricalResultState::OtherDnf)),
            'unknown_abnormal' => count(array_filter($started, fn (HistoricalRaceDto $event): bool => $event->resultState === HistoricalResultState::UnknownAbnormal)),
        ];
        $result = [
            'started_race_count' => $denominator,
            'did_not_start_count' => count(array_filter($events, fn (HistoricalRaceDto $event): bool => $event->resultState === HistoricalResultState::DidNotStart)),
        ];
        foreach ($counts as $name => $count) {
            $result[$name.'_count'] = $count;
            $result[$name.'_rate'] = $denominator > 0 ? $count / $denominator : null;
        }

        return $result;
    }

    /** @param list<HistoricalRaceDto> $started */
    private function recency(array $started, DateTimeImmutable $inputAsOf): array
    {
        $lastIndex = null;
        foreach ($started as $index => $event) {
            if ($event->abnormal()) {
                $lastIndex = $index;
            }
        }
        $last = $lastIndex !== null ? $started[$lastIndex] : null;
        $streak = 0;
        for ($index = count($started) - 1; $index >= 0 && $started[$index]->abnormal(); $index--) {
            $streak++;
        }

        return [
            'last_abnormal_at' => $last?->scheduledStartAt->format('Y-m-d\TH:i:s.uP'),
            'days_since_last_abnormal' => $last !== null ? ($inputAsOf->getTimestamp() - $last->scheduledStartAt->getTimestamp()) / 86400 : null,
            'started_races_since_last_abnormal' => $lastIndex !== null ? count($started) - $lastIndex - 1 : null,
            'current_abnormal_streak' => $streak,
        ];
    }
}
