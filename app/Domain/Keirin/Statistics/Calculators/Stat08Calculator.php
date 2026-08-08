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

class Stat08Calculator implements Batch03Calculator
{
    private const WINDOWS = [365, 730, 1095, 1825];

    public function __construct(private readonly Batch03CalculatorSupport $support) {}

    public function stat(): Batch03Stat
    {
        return Batch03Stat::Stat08;
    }

    public function calculate(Batch03TargetEntryDto $target, array $histories, Batch03BuildOptionsDto $options, string $batchExecutionUuid): Batch03FeatureResultDto
    {
        $context = $this->support->context($target, $histories, $options, $batchExecutionUuid, $this->stat());
        $local = $target->scheduledStartAt !== null ? $this->support->local($target->scheduledStartAt) : null;
        $hour = $local !== null ? (int) $local->format('G') : null;
        $minute = $local !== null ? ((int) $local->format('G') * 60) + (int) $local->format('i') : null;
        $all = array_values(array_filter($context->histories, fn (Batch03HistoricalRaceDto $event): bool => $event->normalFinish()));
        $same = $hour !== null ? array_values(array_filter(
            $all,
            fn (Batch03HistoricalRaceDto $event): bool => (int) $this->support->local($event->scheduledStartAt)->format('G') === $hour,
        )) : [];
        $sameMetrics = $this->support->performance($same);
        $allMetrics = $this->support->performance($all);
        $windows = [];
        foreach (self::WINDOWS as $days) {
            $windows[(string) $days] = [
                'SAME_HOUR' => $this->support->window($same, $target, $options->historyFrom, $days),
                'ALL_TIME' => $this->support->window($all, $target, $options->historyFrom, $days),
            ];
        }
        $status = match (true) {
            $target->playerId === null, $local === null => StatisticFeatureResultStatus::MissingInput,
            $same === [] => StatisticFeatureResultStatus::NoHistory,
            default => StatisticFeatureResultStatus::Valid,
        };
        $reasons = match (true) {
            $target->playerId === null => ['PLAYER_ID_UNRESOLVED'],
            $local === null => ['TARGET_SCHEDULED_START_MISSING'],
            $same === [] => ['NO_SAME_HOUR_HISTORY'],
            default => [],
        };
        $angle = $minute !== null ? 2 * M_PI * $minute / 1440 : null;

        return $this->support->result(
            $target,
            $options,
            $context,
            $this->stat(),
            [
                'TARGET_TIME' => [
                    'target_local_datetime' => $local !== null ? $this->support->timestamp($local) : null,
                    'minute_of_day' => $minute,
                    'hour_of_day' => $hour,
                    'minute_within_hour' => $local !== null ? (int) $local->format('i') : null,
                    'circular_sin' => $angle !== null ? sin($angle) : null,
                    'circular_cos' => $angle !== null ? cos($angle) : null,
                    'raw_day_kind' => $target->meetingDayKind,
                ],
                'SAME_HOUR_ACQUIRED' => $sameMetrics,
                'ALL_TIME_ACQUIRED' => $allMetrics,
                'DELTA' => $this->support->delta($sameMetrics, $allMetrics),
                'DAY_WINDOWS' => $windows,
            ],
            $status,
            $reasons,
            ['OFFICIAL_SESSION_CATEGORY_UNAVAILABLE'],
        );
    }
}
