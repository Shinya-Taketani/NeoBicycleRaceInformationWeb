<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Keirin\Statistics\Calculators\Stat01Calculator;
use App\Domain\Keirin\Statistics\DTO\Stat01EntryResultDto;
use App\Domain\Keirin\Statistics\DTO\Stat01RaceCalculationDto;
use App\Models\Race;
use App\Models\StatisticCalculationRun;
use App\Models\StatisticEntryResult;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

class StatisticRepository
{
    /**
     * @param  callable(Race):void  $callback
     */
    public function eachTargetRace(
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $to,
        ?int $raceId,
        int $chunkSize,
        callable $callback,
    ): void {
        $this->targetRaceQuery($from, $to, $raceId)
            ->with(['entries' => fn ($query) => $query->orderBy('id')])
            ->chunkById($chunkSize, function ($races) use ($callback): void {
                foreach ($races as $race) {
                    $callback($race);
                }
            }, 'races.id', 'id');
    }

    public function targetRaceQuery(
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $to,
        ?int $raceId,
    ): Builder {
        return Race::query()
            ->select('races.*')
            ->when($raceId !== null, fn (Builder $query): Builder => $query->where('races.id', $raceId))
            ->when($raceId === null && $from !== null, fn (Builder $query): Builder => $query->whereDate('races.race_date', '>=', $from->format('Y-m-d')))
            ->when($raceId === null && $to !== null, fn (Builder $query): Builder => $query->whereDate('races.race_date', '<=', $to->format('Y-m-d')));
    }

    /**
     * @throws JsonException
     */
    public function persistStat01(
        StatisticCalculationRun $run,
        Race $race,
        Stat01RaceCalculationDto $calculation,
        DateTimeImmutable $calculatedAt,
        bool $recalculate,
    ): void {
        DB::transaction(function () use ($run, $race, $calculation, $calculatedAt, $recalculate): void {
            $now = $calculatedAt->format('Y-m-d H:i:s.uP');
            $snapshot = json_encode(
                $calculation->inputSnapshot,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
            );
            $rows = array_map(
                fn (Stat01EntryResultDto $result): array => $this->resultRow(
                    $run,
                    $race,
                    $calculation,
                    $result,
                    $snapshot,
                    $now,
                ),
                $calculation->results,
            );

            $uniqueBy = ['stat_code', 'calculation_version', 'race_entry_id', 'input_hash'];
            if ($recalculate) {
                StatisticEntryResult::query()->upsert(
                    $rows,
                    $uniqueBy,
                    [
                        'player_id',
                        'bike_number',
                        'race_score',
                        'valid_score_count',
                        'missing_score_count',
                        'invalid_score_count',
                        'entrant_count',
                        'score_rank',
                        'dense_rank',
                        'strength_percentile',
                        'race_average_score',
                        'race_max_score',
                        'difference_from_average',
                        'difference_from_max',
                        'race_standard_deviation',
                        'z_score',
                        'quality_status',
                        'acquisition_mode',
                        'input_snapshot',
                        'source',
                        'source_fetched_at',
                        'raw_points',
                        'confidence',
                        'effective_points',
                        'calculated_at',
                        'updated_at',
                    ],
                );
            } else {
                StatisticEntryResult::query()->insertOrIgnore($rows);
            }

            $entryIds = array_map(
                static fn (Stat01EntryResultDto $result): int => $result->raceEntryId,
                $calculation->results,
            );
            $resultIds = StatisticEntryResult::query()
                ->where('stat_code', Stat01Calculator::STAT_CODE)
                ->where('calculation_version', Stat01Calculator::CALCULATION_VERSION)
                ->where('input_hash', $calculation->inputHash)
                ->whereIn('race_entry_id', $entryIds)
                ->pluck('id');
            if ($resultIds->count() !== count($entryIds)) {
                throw new RuntimeException("STAT-01 results could not be resolved for race {$race->id}.");
            }

            DB::table('statistic_run_entry_results')->insertOrIgnore(
                $resultIds->map(fn ($resultId): array => [
                    'calculation_run_id' => $run->id,
                    'statistic_entry_result_id' => $resultId,
                    'race_id' => $race->id,
                    'created_at' => $now,
                ])->all(),
            );
        });
    }

    /**
     * @return array<string,mixed>
     */
    private function resultRow(
        StatisticCalculationRun $run,
        Race $race,
        Stat01RaceCalculationDto $calculation,
        Stat01EntryResultDto $result,
        string $snapshot,
        string $now,
    ): array {
        return [
            'calculation_run_id' => $run->id,
            'stat_code' => Stat01Calculator::STAT_CODE,
            'calculation_version' => Stat01Calculator::CALCULATION_VERSION,
            'race_id' => $race->id,
            'race_entry_id' => $result->raceEntryId,
            'player_id' => $result->playerId,
            'bike_number' => $result->bikeNumber,
            'race_score' => $this->decimal($result->raceScore, 2),
            'valid_score_count' => $result->validScoreCount,
            'missing_score_count' => $result->missingScoreCount,
            'invalid_score_count' => $result->invalidScoreCount,
            'entrant_count' => $result->entrantCount,
            'score_rank' => $result->scoreRank,
            'dense_rank' => $result->denseRank,
            'strength_percentile' => $this->decimal($result->strengthPercentile, 8),
            'race_average_score' => $this->decimal($result->raceAverageScore, 4),
            'race_max_score' => $this->decimal($result->raceMaxScore, 2),
            'difference_from_average' => $this->decimal($result->differenceFromAverage, 4),
            'difference_from_max' => $this->decimal($result->differenceFromMax, 4),
            'race_standard_deviation' => $this->decimal($result->raceStandardDeviation, 6),
            'z_score' => $this->decimal($result->zScore, 8),
            'quality_status' => $result->qualityStatus->value,
            'acquisition_mode' => $result->acquisitionMode->value,
            'input_snapshot' => $snapshot,
            'input_hash' => $calculation->inputHash,
            'source' => $race->source,
            'source_fetched_at' => $result->sourceFetchedAt?->format('Y-m-d H:i:s.uP'),
            'raw_points' => null,
            'confidence' => null,
            'effective_points' => null,
            'calculated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function decimal(?float $value, int $scale): ?string
    {
        return $value === null ? null : number_format($value, $scale, '.', '');
    }
}
