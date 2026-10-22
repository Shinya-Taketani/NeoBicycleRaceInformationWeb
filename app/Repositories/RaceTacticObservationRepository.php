<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Keirin\Scraping\DTO\RaceTacticObservationDto;
use App\Domain\Keirin\Statistics\Support\DeterministicJsonHasher;
use App\Models\RaceTacticObservation;
use DateTimeInterface;
use RuntimeException;

class RaceTacticObservationRepository
{
    public function append(RaceTacticObservationDto $dto, string $version, bool $dryRun): array
    {
        $attributes = $dto->attributes($version);
        $key = ['scraping_fetch_log_id' => $dto->capture->fetchLogId, 'race_entry_id' => $dto->entryId, 'parser_version' => $version];
        $row = RaceTacticObservation::query()->where($key)->first();
        $new = $row === null;
        if ($new && ! $dryRun) {
            $row = RaceTacticObservation::query()->createOrFirst($key, $attributes + ['created_at' => now()]);
            $new = $row->wasRecentlyCreated;
        }
        if ($row !== null) {
            foreach ($attributes as $name => $expected) {
                $actual = $row->getAttribute($name);
                if ($name === 'race_date') {
                    $actual = $actual->format('Y-m-d');
                } elseif ($actual instanceof DateTimeInterface || $expected instanceof DateTimeInterface) {
                    $actual = $actual?->format('U.u');
                    $expected = $expected?->format('U.u');
                } elseif (is_array($actual) && is_array($expected)) {
                    $hasher = new DeterministicJsonHasher;
                    $actual = $hasher->hash($actual);
                    $expected = $hasher->hash($expected);
                }
                if ($actual !== $expected) {
                    throw new RuntimeException('Immutable tactic observation conflict: '.$name);
                }
            }
        }

        return ['observation_id' => $row?->id, 'planned_new_observations' => (int) $new];
    }
}
