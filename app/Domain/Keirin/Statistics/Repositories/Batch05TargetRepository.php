<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Repositories;

use App\Domain\Keirin\Statistics\Calculators\Stat01Calculator;
use App\Domain\Keirin\Statistics\DTO\Batch05BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch05RaceInputDto;
use App\Domain\Keirin\Statistics\DTO\Batch05TargetEntryDto;
use App\Domain\Keirin\Statistics\DTO\Stat01TargetCountsDto;
use App\Models\StatisticFeatureRun;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

class Batch05TargetRepository
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

    public function counts(Batch05BuildOptionsDto $options): Stat01TargetCountsDto
    {
        $query = $this->targetQuery($options);

        return new Stat01TargetCountsDto(
            races: (int) (clone $query)->distinct()->count('results.race_id'),
            entries: (int) $query->count('results.id'),
        );
    }

    public function assertTargetInputAsOfConsistent(Batch05BuildOptionsDto $options): void
    {
        $missing = $this->targetQuery($options)
            ->whereNull('results.input_as_of')
            ->select(['results.race_id', 'results.race_entry_id'])
            ->orderBy('results.race_id')
            ->orderBy('results.race_entry_id')
            ->first();
        if ($missing !== null) {
            throw new RuntimeException(
                'Batch05 target contains missing STAT-01 input_as_of: '
                ."race_id={$missing->race_id}, race_entry_id={$missing->race_entry_id}.",
            );
        }

        $inconsistent = $this->targetQuery($options)
            ->select('results.race_id')
            ->groupBy('results.race_id')
            ->havingRaw('COUNT(DISTINCT results.input_as_of) <> 1')
            ->orderBy('results.race_id')
            ->first();
        if ($inconsistent !== null) {
            throw new RuntimeException(
                "Batch05 target race {$inconsistent->race_id} had inconsistent STAT-01 input_as_of values.",
            );
        }
    }

    /** @return \Generator<int, list<Batch05RaceInputDto>> */
    public function workingBatches(Batch05BuildOptionsDto $options): \Generator
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

    private function targetQuery(Batch05BuildOptionsDto $options): Builder
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
     * @return list<Batch05RaceInputDto>
     */
    private function raceInputs(Batch05BuildOptionsDto $options, Collection $keys): array
    {
        $raceIds = $keys->pluck('race_id')->map(fn (mixed $id): int => (int) $id)->all();
        $rows = $this->targetQuery($options)
            ->whereIntegerInRaw('results.race_id', $raceIds)
            ->select([
                'results.race_id',
                'results.race_entry_id',
                'results.player_id',
                'results.bike_number',
                'results.input_as_of',
                'results.source_fetched_at',
                'results.input_hash as stat01_input_hash',
                'results.status as stat01_status',
                'results.quality_status as stat01_quality_status',
                'results.features as stat01_features',
                'results.evidence as stat01_evidence',
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
            $entries = [];
            foreach ($raceRows as $row) {
                $inputAsOf = $this->requiredDateTime($row->input_as_of, "race {$raceId} input_as_of");
                $features = $this->jsonObject($row->stat01_features, 'features');
                $evidence = $this->jsonObject($row->stat01_evidence, 'evidence');
                $entries[] = new Batch05TargetEntryDto(
                    raceEntryId: (int) $row->race_entry_id,
                    playerId: $row->player_id !== null ? (int) $row->player_id : null,
                    bikeNumber: $row->bike_number !== null ? (int) $row->bike_number : null,
                    inputAsOf: $inputAsOf,
                    stat01InputHash: (string) $row->stat01_input_hash,
                    stat01Status: (string) $row->stat01_status,
                    stat01QualityStatus: (string) $row->stat01_quality_status,
                    raceScoreRaw: $features['RACE_SCORE_RAW'] ?? null,
                    raceScoreAvailable: $features['RACE_SCORE_AVAILABLE'] ?? null,
                    expectedEntrantCount: $features['ENTRANT_COUNT'] ?? $evidence['expected_entrant_count'] ?? null,
                    sourceFetchedAt: $row->source_fetched_at !== null
                        ? $this->requiredDateTime($row->source_fetched_at, "race {$raceId} source_fetched_at")
                        : null,
                    stat01RaceInputHash: isset($evidence['race_input_hash']) ? (string) $evidence['race_input_hash'] : null,
                    stat01Rank: $features['RACE_SCORE_RANK'] ?? null,
                    stat01DenseRank: $features['RACE_SCORE_DENSE_RANK'] ?? null,
                    stat01StrengthPercentile: $features['RACE_SCORE_STRENGTH_PERCENTILE'] ?? null,
                );
            }
            $races[] = new Batch05RaceInputDto($raceId, $entries, $options->stat01RunId);
        }

        return $races;
    }

    /** @return array<string, mixed> */
    private function jsonObject(mixed $value, string $field): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value)) {
            throw new UnexpectedValueException("STAT-01 target {$field} was not an object.");
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException("STAT-01 target {$field} was invalid JSON.", previous: $exception);
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new UnexpectedValueException("STAT-01 target {$field} was not an object.");
        }

        return $decoded;
    }

    private function requiredDateTime(mixed $value, string $field): DateTimeImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            throw new UnexpectedValueException("Batch05 {$field} was missing.");
        }
        try {
            return new DateTimeImmutable($value);
        } catch (Throwable $throwable) {
            throw new UnexpectedValueException("Batch05 {$field} was invalid.", previous: $throwable);
        }
    }
}
