<?php

declare(strict_types=1);

namespace App\Domain\Keirin\TrackContext;

use App\Domain\Keirin\Scraping\Enums\AgariStatus;
use App\Domain\Keirin\Scraping\Enums\RaceEntryResultStatus;
use Brick\Math\RoundingMode;

final class AgariSpeedCalculator
{
    public const VERSION = 'unadjusted-distance-speed-v1';

    public function calculate(LayoutResolution $resolution, ?string $seconds, string $definitionId, AgariStatus $agariStatus, RaceEntryResultStatus $resultStatus): array
    {
        // Validate supplied numbers even when a separate status blocks calculation.
        $time = $seconds === null ? null : StructureValues::positiveDecimal($seconds);
        $distanceText = $resolution->layout['fields']['segment_distance_m']['value'] ?? null;
        $distance = $distanceText === null ? null : StructureValues::positiveDecimal($distanceText);
        $reason = match (true) {
            $agariStatus !== AgariStatus::Valid => $agariStatus->value,
            ! in_array($resultStatus, [RaceEntryResultStatus::Finished, RaceEntryResultStatus::Tied], true) => 'ABNORMAL_RESULT',
            $time === null => 'MISSING_TIME',
            $resolution->status !== 'RESOLVED' => $resolution->status,
            ($resolution->definition['id'] ?? null) !== $definitionId => 'MEASUREMENT_DEFINITION_MISMATCH',
            ($resolution->definition['status'] ?? null) !== 'CONFIRMED' => 'UNKNOWN_MEASUREMENT_DEFINITION',
            $distance === null || ($resolution->layout['fields']['segment_distance_m']['status'] ?? null) !== 'CONFIRMED' => 'UNKNOWN_MEASUREMENT_DISTANCE',
            default => null,
        };

        return [
            'calculation_version' => self::VERSION, 'calculable' => $reason === null, 'reason' => $reason,
            'speed_mps' => $reason === null ? (string) $distance->dividedBy($time, 12, RoundingMode::HalfEven) : null,
            // Do not multiply the already rounded m/s value.
            'speed_kmh' => $reason === null ? (string) $distance->multipliedBy('3.6')->dividedBy($time, 12, RoundingMode::HalfEven) : null,
            'distance_m' => $distanceText, 'time_seconds' => $seconds, 'input_definition_id' => $definitionId,
            'master_version' => $resolution->masterVersion, 'layout_version' => $resolution->layout['layout_version'] ?? null,
            'resolved_definition_id' => $resolution->definition['id'] ?? null,
            'source_refs' => array_values(array_unique(array_merge($resolution->layout['fields']['segment_distance_m']['source_refs'] ?? [], $resolution->definition['source_refs'] ?? []))),
            'rounding' => '12_DECIMAL_PLACES_HALF_EVEN_OUTPUT_ONLY',
            'measurement_precision_seconds' => $resolution->definition['precision_seconds'] ?? null,
            'purpose' => 'DISTANCE_CONVERSION_ONLY_NO_CONDITION_ADJUSTMENT',
            'prediction_use' => 'NOT_AUTHORIZED', 'historical_as_of_available' => false,
        ];
    }
}
