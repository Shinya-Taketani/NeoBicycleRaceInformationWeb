<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Calculators;

use App\Domain\Keirin\Statistics\Contracts\Batch04Calculator;
use App\Domain\Keirin\Statistics\DTO\Batch04BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch04FeatureResultDto;
use App\Domain\Keirin\Statistics\DTO\Batch04PositionHistoryContextDto;
use App\Domain\Keirin\Statistics\DTO\Batch04RaceInputDto;
use App\Domain\Keirin\Statistics\DTO\Batch04TargetEntryDto;
use App\Domain\Keirin\Statistics\Enums\Batch04Stat;
use App\Domain\Keirin\Statistics\Enums\StatisticFeatureResultStatus;
use App\Domain\Keirin\Statistics\Support\Batch04CalculatorSupport;

class Stat39Calculator implements Batch04Calculator
{
    public function __construct(private readonly Batch04CalculatorSupport $support) {}

    public function stat(): Batch04Stat
    {
        return Batch04Stat::Stat39;
    }

    public function calculate(
        Batch04TargetEntryDto $target,
        Batch04RaceInputDto $race,
        Batch04PositionHistoryContextDto $positionHistory,
        array $pairHistories,
        Batch04BuildOptionsDto $options,
        string $batchExecutionUuid,
    ): Batch04FeatureResultDto {
        $bikeNumbers = $target->participatingBikeNumbers;
        $duplicates = count($bikeNumbers) !== count(array_unique($bikeNumbers));
        $targetOrder = $target->bikeNumber !== null ? array_search($target->bikeNumber, $bikeNumbers, true) : false;
        $invalidContext = $target->declaredEntrantCount !== null
            && ($target->declaredEntrantCount !== $target->actualEntryCount
                || $duplicates
                || $targetOrder === false);
        $fieldBike = $positionHistory->groups['FIELD_BIKE'];
        $status = match (true) {
            $target->bikeNumber === null => StatisticFeatureResultStatus::MissingInput,
            $target->declaredEntrantCount === null => StatisticFeatureResultStatus::MissingInput,
            $invalidContext => StatisticFeatureResultStatus::InvalidInput,
            (int) $fieldBike['sample_count'] === 0 => StatisticFeatureResultStatus::NoHistory,
            default => StatisticFeatureResultStatus::Valid,
        };
        $statusReason = match (true) {
            $target->bikeNumber === null => 'MISSING_VEHICLE_NUMBER',
            $target->declaredEntrantCount === null => 'MISSING_ENTRANT_COUNT',
            $invalidContext => 'INCONSISTENT_TARGET_POSITION_CONTEXT',
            (int) $fieldBike['sample_count'] === 0 => 'NO_VEHICLE_HISTORY',
            default => null,
        };
        $qualityReasons = [];
        if ($target->frameNumber === null) {
            $qualityReasons[] = 'MISSING_FRAME_NUMBER';
        }
        if ($target->playerId === null) {
            $qualityReasons[] = 'PLAYER_ID_UNRESOLVED_FOR_PLAYER_SPECIFIC_COMPONENT';
        }
        if ($target->racetrackId === null) {
            $qualityReasons[] = 'RACETRACK_MISSING_FOR_TRACK_COMPONENT';
        }
        $orderIndex = $targetOrder !== false ? $targetOrder + 1 : null;
        $orderPercentile = $orderIndex !== null && count($bikeNumbers) > 1
            ? ($orderIndex - 1) / (count($bikeNumbers) - 1)
            : null;
        $fieldBaseline = $positionHistory->groups['FIELD_BASELINE'];
        $trackBike = $positionHistory->groups['TRACK_FIELD_BIKE'];
        $trackBaseline = $positionHistory->groups['TRACK_FIELD_BASELINE'];
        $targetContextHash = $this->support->targetContextHash($target, $race, false);

        return $this->support->result(
            target: $target,
            options: $options,
            stat: $this->stat(),
            targetContextHash: $targetContextHash,
            historyInputHash: $positionHistory->historyInputHash,
            features: [
                'TARGET_POSITION_CONTEXT' => [
                    'bike_number' => $target->bikeNumber,
                    'frame_number' => $target->frameNumber,
                    'declared_entrant_count' => $target->declaredEntrantCount,
                    'actual_entry_count' => $target->actualEntryCount,
                    'racetrack_id' => $target->racetrackId,
                    'participating_bike_numbers' => $bikeNumbers,
                    'participating_bike_order_index' => $orderIndex,
                    'participating_bike_order_percentile' => $orderPercentile,
                ],
                'FIELD_BIKE' => $this->metrics($fieldBike),
                'FIELD_BASELINE' => $this->metrics($fieldBaseline),
                'FIELD_BIKE_DELTA' => $this->support->delta($fieldBike, $fieldBaseline),
                'TRACK_FIELD_BIKE' => $this->metrics($trackBike),
                'TRACK_FIELD_BASELINE' => $this->metrics($trackBaseline),
                'TRACK_FIELD_BIKE_DELTA' => $this->support->delta($trackBike, $trackBaseline),
                'FIELD_FRAME' => $this->nullableMetrics($positionHistory->groups['FIELD_FRAME']),
                'TRACK_FIELD_FRAME' => $this->nullableMetrics($positionHistory->groups['TRACK_FIELD_FRAME']),
                'POSITION_BIAS_SCORE' => null,
            ],
            evidence: [
                ...$positionHistory->evidence,
                'batch_execution_uuid' => $batchExecutionUuid,
                'stat01_run_id' => $options->stat01RunId,
                'stat01_input_hash' => $target->stat01InputHash,
                'effect_interpretation' => 'OBSERVED_ASSOCIATION_NOT_CAUSAL_EFFECT',
                'relative_position_interpretation' => 'ORDER_WITHIN_PARTICIPATING_BIKE_NUMBERS',
            ],
            status: $status,
            qualityReasons: $qualityReasons,
            unavailableComponents: [
                'CURRENT_LINE_CONTEXT',
                'HISTORICAL_LINE_CONTEXT',
                'INITIAL_POSITION',
                'TACTIC_CONTEXT',
                'TRACK_STRUCTURE',
                'VEHICLE_ASSIGNMENT_MECHANISM',
                'VEHICLE_ASSIGNMENT_RULE_VERSION',
                'VEHICLE_CHANGE_HISTORY',
            ],
            statusReason: $statusReason,
        );
    }

    /** @param array<string, mixed> $group @return array<string, mixed> */
    private function metrics(array $group): array
    {
        unset($group['history_count'], $group['history_hash'], $group['source_max_fetched_at']);

        return $group;
    }

    /** @param array<string, mixed>|null $group @return array<string, mixed>|null */
    private function nullableMetrics(?array $group): ?array
    {
        return $group !== null ? $this->metrics($group) : null;
    }
}
