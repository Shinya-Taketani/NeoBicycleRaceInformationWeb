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

class Stat26Calculator implements Batch02Calculator
{
    private const DAY_WINDOWS = [7, 14, 21, 30, 45, 60];

    public function __construct(private readonly Batch02CalculatorSupport $support) {}

    public function stat(): Batch02Stat
    {
        return Batch02Stat::Stat26;
    }

    public function calculate(Batch02TargetEntryDto $target, array $histories, Batch02BuildOptionsDto $options, string $batchExecutionUuid): Batch02FeatureResultDto
    {
        $context = $this->support->context($target, $histories, $options, $batchExecutionUuid, $this->stat());
        $windows = [];
        $complete = true;
        foreach (self::DAY_WINDOWS as $days) {
            $window = $this->support->dayWindow($context->histories, $target, $options->historyFrom, $days);
            $windows[(string) $days] = [...$window['metadata'], ...$this->metrics($window['events'], $days)];
            $complete = $complete && $window['metadata']['window_complete'];
        }

        return $this->support->result(
            $target,
            $options,
            $context,
            $this->stat(),
            ['DAY_WINDOWS' => $windows],
            $complete,
            unavailableComponents: ['TRAVEL_DISTANCE', 'ROLE_LOAD'],
        );
    }

    /** @param list<HistoricalRaceDto> $events */
    private function metrics(array $events, int $days): array
    {
        $started = array_values(array_filter($events, fn (HistoricalRaceDto $event): bool => $event->started()));
        $meetings = [];
        $activeDates = [];
        foreach ($started as $event) {
            if ($event->raceMeetingId !== null) {
                $meetings[$event->raceMeetingId] = true;
            }
            $activeDates[$event->scheduledStartAt->format('Y-m-d')] = true;
        }
        $dates = array_keys($activeDates);
        sort($dates);
        $trackChanges = $missingTrack = 0;
        for ($index = 1; $index < count($started); $index++) {
            $previous = $started[$index - 1]->racetrackId;
            $current = $started[$index]->racetrackId;
            if ($previous === null || $current === null) {
                $missingTrack++;
            } elseif ($previous !== $current) {
                $trackChanges++;
            }
        }

        return [
            'started_race_count' => count($started),
            'distinct_meeting_count' => count($meetings),
            'active_day_count' => count($dates),
            'inactive_calendar_day_count' => $days - count($dates),
            'starts_per_active_day' => $dates !== [] ? count($started) / count($dates) : null,
            'track_change_count' => $trackChanges,
            'missing_track_context_count' => $missingTrack,
            'max_consecutive_active_days' => $this->maxConsecutiveDays($dates),
        ];
    }

    /** @param list<string> $dates */
    private function maxConsecutiveDays(array $dates): int
    {
        $maximum = $current = 0;
        $previous = null;
        foreach ($dates as $date) {
            if ($previous !== null && (new \DateTimeImmutable($previous))->modify('+1 day')->format('Y-m-d') === $date) {
                $current++;
            } else {
                $current = 1;
            }
            $maximum = max($maximum, $current);
            $previous = $date;
        }

        return $maximum;
    }
}
