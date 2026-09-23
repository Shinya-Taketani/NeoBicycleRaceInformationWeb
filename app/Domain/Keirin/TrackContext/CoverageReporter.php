<?php

declare(strict_types=1);

namespace App\Domain\Keirin\TrackContext;

use InvalidArgumentException;

final class CoverageReporter
{
    /** Input is a frozen, result-free track/date inventory, not race outcomes. */
    public function report(TrackContextMaster $master, array $targets): array
    {
        $tracks = [];
        $seen = [];
        $totals = ['RESOLVED' => 0, 'UNKNOWN_LAYOUT_VERSION' => 0, 'SOURCE_CONFLICT' => 0];
        foreach ($targets as $target) {
            $date = StructureValues::date($target['race_date'] ?? null);
            if ($date < '2022-01-01' || $date > '2025-12-31'
                || ($target['source'] ?? null) !== 'keirin_jp' || ! is_string($target['external_track_id'] ?? null)) {
                throw new InvalidArgumentException('Target must be a 2022-2025 keirin_jp track/date.');
            }
            $key = $target['source'].':'.$target['external_track_id'];
            if (isset($seen[$key.':'.$date])) {
                throw new InvalidArgumentException('Duplicate target track/date.');
            }
            $seen[$key.':'.$date] = true;
            $resolution = $master->resolve($target['source'], $target['external_track_id'], $date);
            if (! isset($tracks[$key])) {
                $tracks[$key] = ['source' => $target['source'], 'external_track_id' => $target['external_track_id'],
                    'racetrack_id' => $target['racetrack_id'] ?? null, 'name' => $target['name'] ?? null,
                    'target_days' => 0, 'status_counts' => array_fill_keys(array_keys($totals), 0), 'distance_resolved_days' => 0,
                    'observations' => $master->tracks[$key]['layouts'] ?? [], 'days' => []];
            }
            $tracks[$key]['target_days']++;
            $tracks[$key]['status_counts'][$resolution->status]++;
            $totals[$resolution->status]++;
            $distance = $resolution->status === 'RESOLVED' && $resolution->definition['status'] === 'CONFIRMED'
                && $resolution->layout['fields']['segment_distance_m']['status'] === 'CONFIRMED';
            $tracks[$key]['distance_resolved_days'] += (int) $distance;
            $tracks[$key]['days'][] = ['race_date' => $date, 'status' => $resolution->status,
                'layout_version' => $resolution->layout['layout_version'] ?? null, 'distance_confirmed' => $distance,
                'candidate_versions' => $resolution->candidateVersions];
        }
        ksort($tracks);

        return ['version' => 'track-context-coverage-v1', 'master_version' => $master->version,
            'manifest_sha256' => $master->manifestSha256, 'track_count' => count($tracks), 'target_days' => count($seen),
            'status_counts' => $totals, 'distance_resolved_days' => array_sum(array_column($tracks, 'distance_resolved_days')),
            'historical_as_of_available' => false, 'prediction_use' => 'NOT_AUTHORIZED', 'tracks' => $tracks];
    }
}
