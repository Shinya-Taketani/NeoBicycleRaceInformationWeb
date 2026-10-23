<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Keirin\Scraping\DTO\RaceRosterSnapshotDto;
use App\Domain\Keirin\Statistics\Support\DeterministicJsonHasher;
use App\Models\RaceRosterSnapshot;
use DateTimeImmutable;
use RuntimeException;

class RaceRosterSnapshotRepository
{
    public function append(RaceRosterSnapshotDto $dto, bool $dryRun): array
    {
        $attributes = $dto->attributes;
        $key = array_intersect_key($attributes, array_flip(['scraping_fetch_log_id', 'parser_version']));
        $row = RaceRosterSnapshot::query()->where($key)->first();
        $new = $row === null;
        if ($new && ! $dryRun) {
            $row = RaceRosterSnapshot::query()->createOrFirst($key, [...$attributes, 'created_at' => now()]);
            $new = $row->wasRecentlyCreated;
        }
        if ($row !== null) {
            $this->verify($row, $dto);
        }

        return ['snapshot_id' => $row?->id, 'planned_new_snapshots' => (int) $new];
    }

    public function verify(RaceRosterSnapshot $row, RaceRosterSnapshotDto $dto): void
    {
        foreach ($dto->attributes as $key => $expected) {
            $actual = $row->$key;
            if ($key === 'race_date') {
                $actual = $actual->format('Y-m-d');
            } elseif (in_array($key, ['fetched_at', 'fetch_log_saved_at', 'scheduled_start_at', 'official_publication_at'], true)) {
                $actual = $actual?->format('U.u');
                $expected = $expected === null ? null : (new DateTimeImmutable($expected))->format('U.u');
            } elseif (is_array($expected)) {
                $hasher = new DeterministicJsonHasher;
                $actual = $hasher->hash($actual);
                $expected = $hasher->hash($expected);
            }
            if ($actual !== $expected) {
                throw new RuntimeException('Stored roster snapshot conflict: '.$key);
            }
        }
    }
}
