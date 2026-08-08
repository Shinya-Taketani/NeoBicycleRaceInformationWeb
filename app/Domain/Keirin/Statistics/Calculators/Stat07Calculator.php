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

class Stat07Calculator implements Batch03Calculator
{
    private const WINDOWS = [365, 730, 1095, 1825];

    public function __construct(private readonly Batch03CalculatorSupport $support) {}

    public function stat(): Batch03Stat
    {
        return Batch03Stat::Stat07;
    }

    public function calculate(Batch03TargetEntryDto $target, array $histories, Batch03BuildOptionsDto $options, string $batchExecutionUuid): Batch03FeatureResultDto
    {
        $context = $this->support->context($target, $histories, $options, $batchExecutionUuid, $this->stat());
        $all = array_values(array_filter($context->histories, fn (Batch03HistoricalRaceDto $event): bool => $event->normalFinish()));
        $same = $target->racetrackId !== null
            ? array_values(array_filter($all, fn (Batch03HistoricalRaceDto $event): bool => $event->racetrackId === $target->racetrackId))
            : [];
        $sameMetrics = $this->support->performance($same);
        $allMetrics = $this->support->performance($all);
        $windows = [];
        foreach (self::WINDOWS as $days) {
            $windows[(string) $days] = [
                'SAME_TRACK' => $this->support->window($same, $target, $options->historyFrom, $days),
                'ALL_TRACK' => $this->support->window($all, $target, $options->historyFrom, $days),
            ];
        }
        $status = match (true) {
            $target->playerId === null, $target->racetrackId === null => StatisticFeatureResultStatus::MissingInput,
            $same === [] => StatisticFeatureResultStatus::NoHistory,
            default => StatisticFeatureResultStatus::Valid,
        };
        $reasons = match (true) {
            $target->playerId === null => ['PLAYER_ID_UNRESOLVED'],
            $target->racetrackId === null => ['TARGET_RACETRACK_MISSING'],
            $same === [] => ['NO_SAME_TRACK_HISTORY'],
            default => [],
        };

        return $this->support->result(
            $target,
            $options,
            $context,
            $this->stat(),
            [
                'TARGET' => ['racetrack_id' => $target->racetrackId],
                'SAME_TRACK_ACQUIRED' => [...$sameMetrics, 'last_same_track_start_at' => $same !== [] ? $this->support->timestamp($same[array_key_last($same)]->scheduledStartAt) : null],
                'ALL_TRACK_ACQUIRED' => $allMetrics,
                'DELTA' => $this->support->delta($sameMetrics, $allMetrics),
                'DAY_WINDOWS' => $windows,
            ],
            $status,
            $reasons,
            ['TRACK_LAYOUT_VERSION', 'TRACK_RENOVATION_HISTORY'],
        );
    }
}
