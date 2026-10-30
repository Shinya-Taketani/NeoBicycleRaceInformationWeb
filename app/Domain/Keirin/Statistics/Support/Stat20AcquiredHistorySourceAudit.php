<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Support;

use App\Domain\Keirin\Statistics\DTO\Stat20SourceInputDto;
use DateTimeImmutable;
use UnexpectedValueException;

final class Stat20AcquiredHistorySourceAudit
{
    public function describe(Stat20SourceInputDto $source, ?DateTimeImmutable $cutoff): array
    {
        $times = $windows = $samples = [];
        $e = $source->evidence;
        foreach (['history_min_at', 'history_max_at'] as $key) {
            if (isset($e[$key])) {
                $times['evidence.'.$key] = $e[$key];
            }
        }
        $this->features($source->features, 'features', $times, $windows, $samples);
        $sameTarget = false;
        foreach ($e['history_references'] ?? [] as $i => $ref) {
            if (! is_array($ref)) {
                throw new UnexpectedValueException('Invalid saved history reference.');
            }
            if (($ref['exclusion'] ?? null) === null) {
                if (isset($ref['scheduled_start_at'])) {
                    $times['evidence.history_references.'.$i.'.scheduled_start_at'] = $ref['scheduled_start_at'];
                }
                $sameTarget = $sameTarget || ($ref['race_id'] ?? null) === $source->raceId;
                if (isset($ref['player_id']) && $ref['player_id'] !== $source->playerId) {
                    throw new UnexpectedValueException('Saved history reference player mismatch.');
                }
            }
        }
        $max = null;
        $future = false;
        foreach ($times as $value) {
            $date = AcquiredHistoryTimestamp::parse($value);
            $max = $max === null ? $date : max($max, $date);
            $future = $future || ($cutoff !== null && $date >= $cutoff);
        }
        $coverage = match (true) {
            $times === [] => 'NOT_RECORDED',
            in_array($source->statCode, ['STAT-09', 'STAT-25', 'STAT-44'], true) => 'PARTIAL_DATED_FIELDS_NOT_ALL_HISTORY_REFERENCES',
            default => 'SAVED_DATED_FIELDS_CHECKED_NOT_ORIGINAL_SOURCE_PROOF',
        };

        return [
            'history_time_audit' => ['coverage' => $coverage, 'known_history_max_at' => Stat20SourceInputDto::timestamp($max),
                'recorded_time_fields' => $times, 'comparison' => $cutoff === null ? 'CUTOFF_NOT_RECORDED'
                    : ($times === [] ? 'NOT_VERIFIED' : ($future || $sameTarget ? 'INCONSISTENT' : 'RECORDED_FIELDS_STRICTLY_BEFORE')),
                'all_reference_times_independently_verified' => false],
            'history_inconsistent' => $future || $sameTarget,
            'history_window_audit' => $windows, 'sample_audit' => $samples,
            'history_from' => $e['history_from'] ?? 'NOT_RECORDED',
            'quality_reasons' => $e['quality_reasons'] ?? [],
            'unavailable_components' => ['unavailable_components' => $e['unavailable_components'] ?? [],
                'unavailable' => $e['unavailable'] ?? [], 'limitations' => $e['limitations'] ?? [],
                'feature_unavailable' => $source->features['UNAVAILABLE'] ?? []],
            'data_role' => $e['data_role'] ?? $e['history_result_mode'] ?? 'NOT_RECORDED',
            'prediction_use' => $e['prediction_use'] ?? $source->features['prediction_use'] ?? 'NOT_RECORDED',
            'historical_as_of_available' => $e['historical_as_of_available'] ?? $source->features['historical_as_of_available'] ?? 'NOT_RECORDED',
            'six_condition_coverage' => $source->features['mean_six_condition_coverage'] ?? null,
            'six_condition_coverage_scope' => in_array($source->statCode, ['STAT-16', 'STAT-27'], true) ? 'SIX_SELECTED_CONDITIONS_ONLY' : 'NOT_APPLICABLE',
            'official_publication_as_of' => 'NOT_VERIFIED', 'original_fetch_log_raw_linkage' => 'NOT_VERIFIED',
        ];
    }

    private function features(array $node, string $path, array &$times, array &$windows, array &$samples): void
    {
        if (array_key_exists('window_complete', $node)) {
            $windows[$path] = array_intersect_key($node, array_flip(['window_complete', 'sample_count', 'observed_rows',
                'started_race_count', 'history_start_at', 'history_end_at', 'window_start_at']));
        }
        foreach ($node as $key => $value) {
            $location = $path.'.'.$key;
            if (in_array($key, ['history_min_at', 'history_max_at', 'history_start_at', 'history_end_at', 'past_observed_peak_at'], true) && $value !== null) {
                $times[$location] = $value;
            }
            if ((str_ends_with((string) $key, '_count') || in_array($key, ['observed_rows', 'weighted_experience', 'effective_experience', 'performance_weight_sum'], true))
                && ! is_array($value)) {
                $samples[$location] = $value;
            }
            if (is_array($value)) {
                $this->features($value, $location, $times, $windows, $samples);
            }
        }
    }
}
