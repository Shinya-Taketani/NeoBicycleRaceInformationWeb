<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Repositories;

use App\Domain\Keirin\Scraping\Services\RaceTacticObservationService;
use App\Domain\Keirin\Statistics\DTO\Stat17DistributionInputDto;
use App\Models\Race;
use App\Models\RaceTacticObservation;
use DateTimeImmutable;
use RuntimeException;

class Stat17DistributionInputRepository
{
    public function __construct(private readonly RaceTacticObservationService $observations) {}

    public function inputs(int $raceId, int $fetchLogId, DateTimeImmutable $asOf): array
    {
        Race::query()->whereKey($raceId)->whereBetween('race_date', ['2022-01-01', '2025-12-31'])->firstOrFail();
        $check = $this->observations->import($raceId, $fetchLogId, true);
        if ($check['planned_new_observations'] !== 0) {
            throw new RuntimeException('Selected tactic capture has not been completely stored.');
        }
        $rows = RaceTacticObservation::query()->where('race_id', $raceId)->where('scraping_fetch_log_id', $fetchLogId)
            ->where('parser_version', RaceTacticObservationService::VERSION)->orderBy('bike_number')->limit(10)->get();
        if ($rows->count() !== count($check['entries']) || $rows->count() > 9) {
            throw new RuntimeException('Invalid stored tactic observation set.');
        }

        return $rows->map(function ($row) use ($asOf): Stat17DistributionInputDto {
            $attributes = $row->attributesToArray();
            $attributes['race_date'] = $row->race_date->format('Y-m-d');

            return new Stat17DistributionInputDto($attributes, $asOf);
        })->all();
    }
}
