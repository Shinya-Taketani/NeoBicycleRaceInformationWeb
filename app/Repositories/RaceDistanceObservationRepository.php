<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Keirin\Scraping\DTO\RaceDistanceObservationDto;
use App\Domain\Keirin\Statistics\Support\DeterministicJsonHasher;
use App\Models\RaceDistanceObservation;
use DateTimeInterface;
use RuntimeException;

class RaceDistanceObservationRepository
{
    /** The caller holds the race lock and verifies source integrity before transaction commit. */
    public function append(RaceDistanceObservationDto $observation, string $version, bool $dryRun): array
    {
        $attributes = $observation->attributes($version);
        $existing = RaceDistanceObservation::query()->where('scraping_fetch_log_id', $observation->fetchLogId)
            ->where('parser_version', $version)->first();
        if ($existing === null && ! $dryRun) {
            $existing = RaceDistanceObservation::query()->createOrFirst(
                ['scraping_fetch_log_id' => $observation->fetchLogId, 'parser_version' => $version],
                [...$attributes, 'created_at' => now()],
            );
        }
        if ($existing !== null) {
            foreach ($attributes as $key => $value) {
                $actual = $existing->getAttribute($key);
                if ($key === 'race_date') {
                    $actual = $actual->format('Y-m-d');
                } elseif ($actual instanceof DateTimeInterface || $value instanceof DateTimeInterface) {
                    $actual = $actual?->format('U.u');
                    $value = $value?->format('U.u');
                } elseif (is_array($actual) && is_array($value)) {
                    $hasher = new DeterministicJsonHasher;
                    $actual = $hasher->hash($actual);
                    $value = $hasher->hash($value);
                }
                if ($actual !== $value) {
                    throw new RuntimeException('Immutable distance observation conflict: '.$key);
                }
            }
        }

        return ['planned_new_observations' => $existing === null || $existing->wasRecentlyCreated ? 1 : 0,
            'observation_id' => $existing?->id];
    }
}
