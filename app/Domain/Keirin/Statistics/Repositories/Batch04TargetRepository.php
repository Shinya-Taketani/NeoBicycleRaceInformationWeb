<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Repositories;

use App\Domain\Keirin\Statistics\Calculators\Stat01Calculator;
use App\Domain\Keirin\Statistics\DTO\Batch04BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch04RaceInputDto;
use App\Domain\Keirin\Statistics\DTO\Batch04TargetEntryDto;
use App\Domain\Keirin\Statistics\DTO\Stat01TargetCountsDto;
use App\Models\StatisticFeatureRun;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
use UnexpectedValueException;

class Batch04TargetRepository
{
    public const TARGET_WORKING_BATCH_SIZE = 5;

    public function validatedStat01Run(int $runId): StatisticFeatureRun
    {
        $run = StatisticFeatureRun::query()->find($runId);
        if (! $run instanceof StatisticFeatureRun
            || $run->stat_code !== Stat01Calculator::STAT_CODE
            || $run->calculation_version !== Stat01Calculator::CALCULATION_VERSION
            || (int) $run->processed_race_count !== (int) $run->target_race_count
            || (int) $run->error_count !== 0) {
            throw new RuntimeException('The specified STAT-01 run was not complete.');
        }
        $resultCount = DB::table('statistic_feature_results')
            ->where('feature_run_id', $runId)
            ->where('stat_code', Stat01Calculator::STAT_CODE)
            ->count();
        if ((int) $resultCount !== (int) $run->target_entry_count) {
            throw new RuntimeException('The specified STAT-01 run result count was incomplete.');
        }

        return $run;
    }

    public function counts(Batch04BuildOptionsDto $options): Stat01TargetCountsDto
    {
        $query = $this->targetQuery($options);

        return new Stat01TargetCountsDto(
            races: (int) (clone $query)->distinct()->count('results.race_id'),
            entries: (int) $query->count('results.id'),
        );
    }

    public function assertTargetInputAsOfComplete(Batch04BuildOptionsDto $options): void
    {
        $missing = $this->targetQuery($options)
            ->whereNull('results.input_as_of')
            ->select(['results.race_id', 'results.race_entry_id'])
            ->orderBy('results.race_id')
            ->orderBy('results.race_entry_id')
            ->first();
        if ($missing === null) {
            return;
        }

        throw new RuntimeException(
            'Batch04 target contains missing STAT-01 input_as_of: '
            ."race_id={$missing->race_id}, race_entry_id={$missing->race_entry_id}.",
        );
    }

    public function earliestTargetDate(Batch04BuildOptionsDto $options): DateTimeImmutable
    {
        $date = $this->targetQuery($options)->min('races.race_date');
        if ($date === null) {
            throw new RuntimeException('No target races were found.');
        }

        return new DateTimeImmutable((string) $date);
    }

    public function latestTargetInputAsOf(Batch04BuildOptionsDto $options): DateTimeImmutable
    {
        $value = $this->targetQuery($options)->max('results.input_as_of');
        if ($value === null) {
            throw new RuntimeException('No target input_as_of was found.');
        }

        return new DateTimeImmutable((string) $value);
    }

    /** @return \Generator<int, list<Batch04RaceInputDto>> */
    public function workingBatches(Batch04BuildOptionsDto $options): \Generator
    {
        $lastInputAsOf = null;
        $lastRaceId = 0;
        while (true) {
            $keySubquery = $this->targetQuery($options)
                ->select([
                    'results.race_id',
                    DB::raw('MIN(results.input_as_of) AS sort_input_as_of'),
                ])
                ->groupBy('results.race_id');
            $keys = DB::query()
                ->fromSub($keySubquery, 'target_keys')
                ->when($lastInputAsOf !== null, function (Builder $query) use ($lastInputAsOf, $lastRaceId): void {
                    $query->where(function (Builder $cursor) use ($lastInputAsOf, $lastRaceId): void {
                        $cursor->where('sort_input_as_of', '>', $lastInputAsOf)
                            ->orWhere(function (Builder $tie) use ($lastInputAsOf, $lastRaceId): void {
                                $tie->where('sort_input_as_of', '=', $lastInputAsOf)
                                    ->where('race_id', '>', $lastRaceId);
                            });
                    });
                })
                ->orderBy('sort_input_as_of')
                ->orderBy('race_id')
                ->limit($options->chunkSize)
                ->get();
            if ($keys->isEmpty()) {
                return;
            }

            foreach ($keys->chunk(self::TARGET_WORKING_BATCH_SIZE) as $workingKeys) {
                yield $this->raceInputs($options, $workingKeys);
            }

            $last = $keys->last();
            $lastInputAsOf = (string) $last->sort_input_as_of;
            $lastRaceId = (int) $last->race_id;
            if ($keys->count() < $options->chunkSize) {
                return;
            }
        }
    }

    private function targetQuery(Batch04BuildOptionsDto $options): Builder
    {
        return DB::table('statistic_feature_results as results')
            ->join('races', 'races.id', '=', 'results.race_id')
            ->where('results.feature_run_id', $options->stat01RunId)
            ->where('results.stat_code', Stat01Calculator::STAT_CODE)
            ->where('results.subject_type', 'RACE_ENTRY')
            ->when(
                $options->raceId !== null,
                fn (Builder $query): Builder => $query->where('results.race_id', $options->raceId),
                fn (Builder $query): Builder => $query
                    ->whereDate('races.race_date', '>=', $options->from?->format('Y-m-d'))
                    ->whereDate('races.race_date', '<=', $options->to?->format('Y-m-d')),
            );
    }

    /**
     * @param  Collection<int, object>  $keys
     * @return list<Batch04RaceInputDto>
     */
    private function raceInputs(Batch04BuildOptionsDto $options, Collection $keys): array
    {
        $raceIds = $keys->pluck('race_id')->map(fn (mixed $id): int => (int) $id)->all();
        $rows = $this->targetQuery($options)
            ->leftJoin('race_entries as source_entries', 'source_entries.id', '=', 'results.race_entry_id')
            ->whereIntegerInRaw('results.race_id', $raceIds)
            ->select([
                'results.race_id',
                'results.race_entry_id',
                'results.player_id',
                'results.bike_number',
                'results.input_as_of',
                'results.input_hash as stat01_input_hash',
                'results.features as stat01_features',
                'source_entries.frame_number',
                'races.entrant_count',
                'races.racetrack_id',
                'races.scheduled_start_at',
            ])
            ->orderBy('results.input_as_of')
            ->orderBy('results.race_id')
            ->orderBy('results.race_entry_id')
            ->get()
            ->groupBy('race_id');
        $races = [];
        foreach ($keys as $key) {
            $raceId = (int) $key->race_id;
            $raceRows = $rows->get($raceId, collect());
            if ($raceRows->isEmpty()) {
                throw new UnexpectedValueException("STAT-01 target race {$raceId} had no entries.");
            }
            $rawInputAsOfValues = $raceRows->pluck('input_as_of');
            if ($rawInputAsOfValues->contains(
                fn (mixed $value): bool => $value === null || trim((string) $value) === '',
            )) {
                throw new UnexpectedValueException("STAT-01 target race {$raceId} had missing input_as_of.");
            }
            $inputAsOfValues = $rawInputAsOfValues
                ->map(fn (mixed $value): string => (string) $value)
                ->unique();
            if ($inputAsOfValues->count() !== 1) {
                throw new UnexpectedValueException("STAT-01 target race {$raceId} had inconsistent input_as_of values.");
            }
            $inputAsOf = new DateTimeImmutable($inputAsOfValues->sole());
            $bikeNumbers = $raceRows->pluck('bike_number')->map(fn (mixed $bike): int => (int) $bike)->sort()->values()->all();
            $actualEntryCount = $raceRows->count();
            $entries = [];
            foreach ($raceRows as $row) {
                $features = $this->stat01Features($row->stat01_features);
                $entries[] = new Batch04TargetEntryDto(
                    raceId: $raceId,
                    raceEntryId: (int) $row->race_entry_id,
                    playerId: $row->player_id !== null ? (int) $row->player_id : null,
                    bikeNumber: $row->bike_number !== null ? (int) $row->bike_number : null,
                    frameNumber: $row->frame_number !== null ? (int) $row->frame_number : null,
                    inputAsOf: $inputAsOf,
                    scheduledStartAt: $row->scheduled_start_at !== null ? new DateTimeImmutable((string) $row->scheduled_start_at) : null,
                    stat01InputHash: (string) $row->stat01_input_hash,
                    stat01RaceScore: isset($features['RACE_SCORE_RAW']) && is_numeric($features['RACE_SCORE_RAW'])
                        ? (float) $features['RACE_SCORE_RAW']
                        : null,
                    stat01Rank: isset($features['RACE_SCORE_RANK']) && is_numeric($features['RACE_SCORE_RANK'])
                        ? (int) $features['RACE_SCORE_RANK']
                        : null,
                    stat01StrengthPercentile: isset($features['RACE_SCORE_STRENGTH_PERCENTILE']) && is_numeric($features['RACE_SCORE_STRENGTH_PERCENTILE'])
                        ? (float) $features['RACE_SCORE_STRENGTH_PERCENTILE']
                        : null,
                    declaredEntrantCount: $row->entrant_count !== null ? (int) $row->entrant_count : null,
                    actualEntryCount: $actualEntryCount,
                    racetrackId: $row->racetrack_id !== null ? (int) $row->racetrack_id : null,
                    participatingBikeNumbers: $bikeNumbers,
                );
            }
            $races[] = new Batch04RaceInputDto($raceId, $inputAsOf, $entries);
        }

        return $races;
    }

    /** @return array<string, mixed> */
    private function stat01Features(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value)) {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('STAT-01 target features were invalid JSON.', previous: $exception);
        }

        return is_array($decoded) ? $decoded : [];
    }
}
