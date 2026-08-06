<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Repositories;

use App\Domain\Keirin\Statistics\DTO\Stat01BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Stat01EntryInputDto;
use App\Domain\Keirin\Statistics\DTO\Stat01RaceInputDto;
use App\Domain\Keirin\Statistics\DTO\Stat01TargetCountsDto;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

class Stat01InputRepository
{
    public function counts(Stat01BuildOptionsDto $options): Stat01TargetCountsDto
    {
        $raceCount = $this->raceQuery($options)->count();
        $entryCount = $this->applyTargetFilters(
            DB::table('race_entries')
                ->join('races', 'races.id', '=', 'race_entries.race_id'),
            $options,
        )->count('race_entries.id');

        return new Stat01TargetCountsDto((int) $raceCount, (int) $entryCount);
    }

    /** @return \Generator<int, Stat01RaceInputDto> */
    public function raceInputs(Stat01BuildOptionsDto $options): \Generator
    {
        $lastId = 0;
        while (true) {
            $races = $this->raceQuery($options)
                ->where('races.id', '>', $lastId)
                ->orderBy('races.id')
                ->limit($options->chunkSize)
                ->get();
            if ($races->isEmpty()) {
                return;
            }

            $raceIds = $races->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
            $entriesByRace = DB::table('race_entries')
                ->select([
                    'id',
                    'race_id',
                    'player_id',
                    'bike_number',
                    'grade',
                    'race_score',
                    'fetched_at',
                ])
                ->whereIn('race_id', $raceIds)
                ->orderBy('race_id')
                ->orderBy('id')
                ->get()
                ->groupBy('race_id');

            foreach ($races as $race) {
                $entries = [];
                foreach ($entriesByRace->get($race->id, collect()) as $entry) {
                    $entries[] = new Stat01EntryInputDto(
                        id: (int) $entry->id,
                        playerId: $entry->player_id !== null ? (int) $entry->player_id : null,
                        bikeNumber: (int) $entry->bike_number,
                        grade: is_string($entry->grade) ? $entry->grade : null,
                        raceScore: $this->raceScore($entry->race_score),
                        fetchedAt: $this->dateTime($entry->fetched_at),
                    );
                }

                yield new Stat01RaceInputDto(
                    id: (int) $race->id,
                    raceDate: new DateTimeImmutable((string) $race->race_date),
                    raceType: (string) $race->race_type,
                    entrantCount: (int) $race->entrant_count,
                    salesCloseAt: $this->dateTime($race->sales_close_at),
                    scheduledStartAt: $this->dateTime($race->scheduled_start_at),
                    entries: $entries,
                );
            }

            $lastId = (int) $races->last()->id;
            if ($races->count() < $options->chunkSize) {
                return;
            }
            unset($races, $entriesByRace);
        }
    }

    private function raceQuery(Stat01BuildOptionsDto $options): Builder
    {
        return $this->applyTargetFilters(
            DB::table('races')->select([
                'races.id',
                'races.race_date',
                'races.race_type',
                'races.entrant_count',
                'races.sales_close_at',
                'races.scheduled_start_at',
            ]),
            $options,
        );
    }

    private function applyTargetFilters(Builder $query, Stat01BuildOptionsDto $options): Builder
    {
        return $query
            ->whereBetween('races.entrant_count', [5, 9])
            ->where(function (Builder $category): void {
                $category->where('races.race_type', 'like', 'Ａ級%')
                    ->orWhere('races.race_type', 'like', 'Ｓ級%');
            })
            ->when(
                $options->raceId !== null,
                fn (Builder $target): Builder => $target->where('races.id', $options->raceId),
                fn (Builder $target): Builder => $target
                    ->whereDate('races.race_date', '>=', $options->from?->format('Y-m-d'))
                    ->whereDate('races.race_date', '<=', $options->to?->format('Y-m-d')),
            );
    }

    private function raceScore(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_numeric($value)) {
            throw new UnexpectedValueException('race_entries.race_score was not numeric.');
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function dateTime(mixed $value): ?DateTimeImmutable
    {
        return $value !== null ? new DateTimeImmutable((string) $value) : null;
    }
}
