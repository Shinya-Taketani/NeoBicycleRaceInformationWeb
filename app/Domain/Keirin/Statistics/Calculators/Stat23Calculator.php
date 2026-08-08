<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Calculators;

use App\Domain\Keirin\Statistics\Contracts\Batch03Calculator;
use App\Domain\Keirin\Statistics\DTO\Batch03BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch03FeatureResultDto;
use App\Domain\Keirin\Statistics\DTO\Batch03HistoricalRaceDto;
use App\Domain\Keirin\Statistics\DTO\Batch03TargetEntryDto;
use App\Domain\Keirin\Statistics\Enums\Batch03Stat;
use App\Domain\Keirin\Statistics\Enums\StatisticFeatureResultStatus;
use App\Domain\Keirin\Statistics\Support\Batch03CalculatorSupport;

class Stat23Calculator implements Batch03Calculator
{
    public function __construct(private readonly Batch03CalculatorSupport $support) {}

    public function stat(): Batch03Stat
    {
        return Batch03Stat::Stat23;
    }

    public function calculate(Batch03TargetEntryDto $target, array $histories, Batch03BuildOptionsDto $options, string $batchExecutionUuid): Batch03FeatureResultDto
    {
        $context = $this->support->context($target, $histories, $options, $batchExecutionUuid, $this->stat());
        $pastMeetings = array_values(array_filter(
            $context->histories,
            fn (Batch03HistoricalRaceDto $event): bool => $event->normalFinish()
                && ($target->raceMeetingId === null || $event->raceMeetingId !== $target->raceMeetingId),
        ));
        $sameDay = $target->dayNumber !== null ? array_values(array_filter(
            $pastMeetings,
            fn (Batch03HistoricalRaceDto $event): bool => $event->dayNumber === $target->dayNumber,
        )) : [];
        $finalDays = array_values(array_filter(
            $pastMeetings,
            fn (Batch03HistoricalRaceDto $event): bool => $event->dayNumber !== null
                && $event->meetingDurationDays !== null
                && $event->dayNumber === $event->meetingDurationDays,
        ));
        $sameMetrics = $this->support->performance($sameDay);
        $allMetrics = $this->support->performance($pastMeetings);
        $invalid = $target->dayNumber !== null
            && $target->meetingDurationDays !== null
            && ($target->dayNumber < 1 || $target->dayNumber > $target->meetingDurationDays);
        $status = match (true) {
            $target->playerId === null, $target->raceMeetingId === null, $target->dayNumber === null, $target->meetingDurationDays === null => StatisticFeatureResultStatus::MissingInput,
            $invalid => StatisticFeatureResultStatus::InvalidInput,
            $sameDay === [] => StatisticFeatureResultStatus::NoHistory,
            default => StatisticFeatureResultStatus::Valid,
        };
        $reasons = match (true) {
            $target->playerId === null => ['PLAYER_ID_UNRESOLVED'],
            $target->raceMeetingId === null => ['TARGET_MEETING_MISSING'],
            $target->dayNumber === null => ['TARGET_DAY_NUMBER_MISSING'],
            $target->meetingDurationDays === null => ['TARGET_MEETING_DURATION_MISSING'],
            $invalid => ['TARGET_MEETING_DAY_INVALID'],
            $sameDay === [] => ['NO_SAME_DAY_NUMBER_HISTORY'],
            default => [],
        };
        $progress = $target->dayNumber !== null && $target->meetingDurationDays !== null && $target->meetingDurationDays > 1
            ? ($target->dayNumber - 1) / ($target->meetingDurationDays - 1)
            : null;

        return $this->support->result(
            $target,
            $options,
            $context,
            $this->stat(),
            [
                'TARGET_MEETING_DAY' => [
                    'day_number' => $target->dayNumber,
                    'duration_days' => $target->meetingDurationDays,
                    'is_first_day' => $target->dayNumber === 1,
                    'is_final_day' => $target->dayNumber !== null && $target->dayNumber === $target->meetingDurationDays,
                    'meeting_progress' => $progress,
                    'single_day_meeting' => $target->meetingDurationDays === 1,
                ],
                'SAME_DAY_NUMBER_HISTORY' => $sameMetrics,
                'ALL_MEETING_DAY_HISTORY' => $allMetrics,
                'DELTA' => $this->support->delta($sameMetrics, $allMetrics),
                'FINAL_DAY_HISTORY' => $this->support->performance($finalDays),
            ],
            $status,
            $reasons,
            ['ADVANCEMENT_RULE', 'QUALIFICATION_CUTOFF'],
        );
    }
}
