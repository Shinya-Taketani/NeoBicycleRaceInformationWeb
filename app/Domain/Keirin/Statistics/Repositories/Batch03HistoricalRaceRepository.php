<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Repositories;

use App\Domain\Keirin\Statistics\Calculators\Stat01Calculator;
use App\Domain\Keirin\Statistics\DTO\Batch03BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch03HistoricalRaceDto;
use App\Domain\Keirin\Statistics\DTO\Batch03RaceInputDto;
use App\Domain\Keirin\Statistics\DTO\Batch03TargetEntryDto;
use App\Domain\Keirin\Statistics\DTO\Stat01TargetCountsDto;
use App\Domain\Keirin\Statistics\Support\DeterministicJsonHasher;
use App\Domain\Keirin\Statistics\Support\HistoricalResultStateNormalizer;
use App\Domain\Keirin\Statistics\Support\RaceStageNormalizer;
use App\Domain\Keirin\Statistics\Support\StatisticalMath;
use App\Models\StatisticFeatureRun;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use UnexpectedValueException;

class Batch03HistoricalRaceRepository
{
    // Batch02 completed the 2024 real-DB acceptance under 128 MB with this working set.
    private const TARGET_WORKING_BATCH_SIZE = 5;

    // Raw history and its race contexts are materialized only for this many subject rows.
    private const HISTORY_ROW_BATCH_SIZE = 250;

    public function __construct(
        private readonly HistoricalResultStateNormalizer $resultNormalizer,
        private readonly RaceStageNormalizer $stageNormalizer,
        private readonly DeterministicJsonHasher $hasher,
        private readonly StatisticalMath $math,
    ) {}

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

    public function counts(Batch03BuildOptionsDto $options): Stat01TargetCountsDto
    {
        $query = $this->targetQuery($options);

        return new Stat01TargetCountsDto(
            races: (int) (clone $query)->distinct()->count('results.race_id'),
            entries: (int) $query->count('results.id'),
        );
    }

    public function earliestTargetDate(Batch03BuildOptionsDto $options): DateTimeImmutable
    {
        $date = $this->targetQuery($options)->min('races.race_date');
        if ($date === null) {
            throw new RuntimeException('No target races were found.');
        }

        return new DateTimeImmutable((string) $date);
    }

    /** @return \Generator<int, Batch03RaceInputDto> */
    public function raceInputs(Batch03BuildOptionsDto $options): \Generator
    {
        $lastRaceId = 0;
        while (true) {
            $raceIds = $this->targetQuery($options)
                ->where('results.race_id', '>', $lastRaceId)
                ->select('results.race_id')
                ->distinct()
                ->orderBy('results.race_id')
                ->limit($options->chunkSize)
                ->pluck('race_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
            if ($raceIds === []) {
                return;
            }

            foreach (array_chunk($raceIds, self::TARGET_WORKING_BATCH_SIZE) as $workingRaceIds) {
                $targets = $this->targetRows($options, $workingRaceIds);
                $historiesByPlayer = $this->historiesForTargets($targets, $options);
                $targetsByRace = $targets->groupBy('race_id');
                foreach ($workingRaceIds as $raceId) {
                    $entries = [];
                    foreach ($targetsByRace->get($raceId, collect()) as $target) {
                        $entries[] = $this->targetDto($target);
                    }
                    yield new Batch03RaceInputDto($raceId, $entries, $historiesByPlayer);
                }
                unset($targets, $historiesByPlayer, $targetsByRace);
            }

            $lastRaceId = $raceIds[array_key_last($raceIds)];
            if (count($raceIds) < $options->chunkSize) {
                return;
            }
        }
    }

    private function targetQuery(Batch03BuildOptionsDto $options): Builder
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

    /** @param list<int> $raceIds */
    private function targetRows(Batch03BuildOptionsDto $options, array $raceIds): Collection
    {
        return $this->targetQuery($options)
            ->leftJoin('race_days', 'race_days.id', '=', 'races.race_day_id')
            ->leftJoin('race_meetings', 'race_meetings.id', '=', 'race_days.race_meeting_id')
            ->whereIntegerInRaw('results.race_id', $raceIds)
            ->select([
                'results.race_id',
                'results.race_entry_id',
                'results.player_id',
                'results.bike_number',
                'results.input_as_of',
                'results.input_hash as stat01_input_hash',
                'races.scheduled_start_at',
                'races.racetrack_id',
                'races.race_day_id',
                'races.race_type',
                'races.name as race_name',
                'races.entrant_count',
                'race_days.race_meeting_id',
                'race_days.day_number',
                'race_meetings.duration_days',
                'race_meetings.grade as meeting_grade',
                'race_meetings.day_kind as meeting_day_kind',
            ])
            ->orderBy('results.race_id')
            ->orderBy('results.race_entry_id')
            ->get();
    }

    private function targetDto(object $row): Batch03TargetEntryDto
    {
        if ($row->input_as_of === null) {
            throw new UnexpectedValueException('STAT-01 target input_as_of was missing.');
        }

        return new Batch03TargetEntryDto(
            raceId: (int) $row->race_id,
            raceEntryId: (int) $row->race_entry_id,
            playerId: $row->player_id !== null ? (int) $row->player_id : null,
            bikeNumber: (int) $row->bike_number,
            inputAsOf: new DateTimeImmutable((string) $row->input_as_of),
            scheduledStartAt: $row->scheduled_start_at !== null ? new DateTimeImmutable((string) $row->scheduled_start_at) : null,
            stat01InputHash: (string) $row->stat01_input_hash,
            racetrackId: $row->racetrack_id !== null ? (int) $row->racetrack_id : null,
            raceDayId: $row->race_day_id !== null ? (int) $row->race_day_id : null,
            raceMeetingId: $row->race_meeting_id !== null ? (int) $row->race_meeting_id : null,
            dayNumber: $row->day_number !== null ? (int) $row->day_number : null,
            meetingDurationDays: $row->duration_days !== null ? (int) $row->duration_days : null,
            meetingGrade: $row->meeting_grade !== null ? (string) $row->meeting_grade : null,
            meetingDayKind: $row->meeting_day_kind !== null ? (string) $row->meeting_day_kind : null,
            rawRaceType: $row->race_type !== null ? (string) $row->race_type : null,
            rawRaceName: $row->race_name !== null ? (string) $row->race_name : null,
            entrantCount: (int) $row->entrant_count,
            normalizedStage: $this->stageNormalizer->normalize($row->race_type !== null ? (string) $row->race_type : null),
        );
    }

    /**
     * @param  Collection<int, object>  $targets
     * @return array<int, list<Batch03HistoricalRaceDto>>
     */
    private function historiesForTargets(Collection $targets, Batch03BuildOptionsDto $options): array
    {
        $playerIds = $targets->pluck('player_id')
            ->filter(fn (mixed $id): bool => $id !== null)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        if ($playerIds === []) {
            return [];
        }
        $maxInputAsOf = $targets->pluck('input_as_of')
            ->filter()
            ->map(fn (mixed $value): DateTimeImmutable => new DateTimeImmutable((string) $value))
            ->max();
        if (! $maxInputAsOf instanceof DateTimeImmutable) {
            return [];
        }

        $query = DB::table('race_entries as history_entries')
            ->join('races as history_races', 'history_races.id', '=', 'history_entries.race_id')
            ->join('race_results as history_results', function ($join): void {
                $join->on('history_results.race_id', '=', 'history_entries.race_id')
                    ->on('history_results.bike_number', '=', 'history_entries.bike_number');
            })
            ->leftJoin('race_days as history_days', 'history_days.id', '=', 'history_races.race_day_id')
            ->leftJoin('race_meetings as history_meetings', 'history_meetings.id', '=', 'history_days.race_meeting_id')
            ->whereIntegerInRaw('history_entries.player_id', $playerIds)
            ->where('history_races.scheduled_start_at', '>=', $options->historyFrom)
            ->where('history_races.scheduled_start_at', '<', $maxInputAsOf)
            ->whereIn('history_races.result_status', ['CONFIRMED', 'CORRECTED'])
            ->where(function (Builder $query): void {
                $query->where('history_races.race_type', 'like', 'Ａ級%')
                    ->orWhere('history_races.race_type', 'like', 'Ｓ級%');
            })
            ->select([
                'history_entries.player_id',
                'history_entries.id as race_entry_id',
                'history_entries.race_id',
                'history_entries.race_score',
                'history_entries.fetched_at as race_entry_fetched_at',
                'history_races.scheduled_start_at',
                'history_races.racetrack_id',
                'history_races.entrant_count',
                'history_races.race_type',
                'history_races.name as race_name',
                'history_races.result_confirmed_at',
                'history_days.race_meeting_id',
                'history_days.day_number',
                'history_meetings.duration_days',
                'history_meetings.grade as meeting_grade',
                'history_meetings.day_kind as meeting_day_kind',
                'history_results.rank',
                'history_results.result_status',
                'history_results.fetched_at as race_result_fetched_at',
            ])
            ->orderBy('history_entries.id');

        $historiesByPlayer = [];
        $lastRaceEntryId = 0;
        while (true) {
            $rows = (clone $query)
                ->where('history_entries.id', '>', $lastRaceEntryId)
                ->limit(self::HISTORY_ROW_BATCH_SIZE)
                ->get();
            if ($rows->isEmpty()) {
                break;
            }
            $entrantCounts = [];
            foreach ($rows as $row) {
                $entrantCounts[(int) $row->race_id] = (int) $row->entrant_count;
            }
            $contexts = $this->scoreContexts(array_keys($entrantCounts), $entrantCounts);
            foreach ($rows as $row) {
                $raceId = (int) $row->race_id;
                $raceEntryId = (int) $row->race_entry_id;
                $entrantCount = (int) $row->entrant_count;
                $scorePercentile = $contexts[$raceId]['percentiles'][$raceEntryId] ?? null;
                $normalized = $this->resultNormalizer->normalize((string) $row->result_status);
                $rank = $row->rank !== null ? (int) $row->rank : null;
                $finishPercentile = $normalized->state->value === 'NORMAL_FINISH'
                    && $rank !== null
                    && $entrantCount > 1
                        ? ($entrantCount - $rank) / ($entrantCount - 1)
                        : null;
                $playerId = (int) $row->player_id;
                $historiesByPlayer[$playerId][] = new Batch03HistoricalRaceDto(
                    playerId: $playerId,
                    raceId: $raceId,
                    raceEntryId: $raceEntryId,
                    scheduledStartAt: new DateTimeImmutable((string) $row->scheduled_start_at),
                    racetrackId: $row->racetrack_id !== null ? (int) $row->racetrack_id : null,
                    raceMeetingId: $row->race_meeting_id !== null ? (int) $row->race_meeting_id : null,
                    dayNumber: $row->day_number !== null ? (int) $row->day_number : null,
                    meetingDurationDays: $row->duration_days !== null ? (int) $row->duration_days : null,
                    meetingGrade: $row->meeting_grade !== null ? (string) $row->meeting_grade : null,
                    meetingDayKind: $row->meeting_day_kind !== null ? (string) $row->meeting_day_kind : null,
                    rawRaceType: $row->race_type !== null ? (string) $row->race_type : null,
                    rawRaceName: $row->race_name !== null ? (string) $row->race_name : null,
                    normalizedStage: $this->stageNormalizer->normalize($row->race_type !== null ? (string) $row->race_type : null),
                    entrantCount: $entrantCount,
                    resultState: $normalized->state,
                    tied: $normalized->tied,
                    rank: $rank,
                    raceScore: $this->raceScore($row->race_score),
                    finishStrengthPercentile: $finishPercentile,
                    scoreExpectationResidual: $finishPercentile !== null && $scorePercentile !== null
                        ? $finishPercentile - $scorePercentile
                        : null,
                    historicalScoreContextHash: $contexts[$raceId]['hash'],
                    raceScoreMean: $contexts[$raceId]['score_mean'],
                    raceScoreMax: $contexts[$raceId]['score_max'],
                    raceScoreStddevPop: $contexts[$raceId]['score_stddev_pop'],
                    subjectScorePercentile: $scorePercentile,
                    raceEntryFetchedAt: new DateTimeImmutable((string) $row->race_entry_fetched_at),
                    raceResultFetchedAt: new DateTimeImmutable((string) $row->race_result_fetched_at),
                    resultConfirmedAt: $row->result_confirmed_at !== null
                        ? new DateTimeImmutable((string) $row->result_confirmed_at)
                        : null,
                );
            }

            $lastRaceEntryId = (int) $rows->last()->race_entry_id;
            if ($rows->count() < self::HISTORY_ROW_BATCH_SIZE) {
                break;
            }
            unset($rows, $contexts, $entrantCounts);
        }

        return $historiesByPlayer;
    }

    /**
     * @param  list<int>  $raceIds
     * @param  array<int, int>  $entrantCounts
     * @return array<int, array{hash:string,percentiles:array<int,?float>,score_mean:?float,score_max:?float,score_stddev_pop:?float}>
     */
    private function scoreContexts(array $raceIds, array $entrantCounts): array
    {
        $summaries = [];
        $currentRaceId = null;
        $context = [];
        foreach (DB::table('race_entries')
            ->whereIntegerInRaw('race_id', $raceIds)
            ->select(['id', 'race_id', 'player_id', 'bike_number', 'grade', 'race_score'])
            ->orderBy('race_id')
            ->orderBy('id')
            ->cursor() as $entry) {
            $raceId = (int) $entry->race_id;
            if ($currentRaceId !== null && $raceId !== $currentRaceId) {
                $summaries[$currentRaceId] = $this->scoreContextSummary($currentRaceId, $entrantCounts[$currentRaceId], $context);
                $context = [];
            }
            $currentRaceId = $raceId;
            $context[] = [
                'race_entry_id' => (int) $entry->id,
                'player_id' => $entry->player_id !== null ? (int) $entry->player_id : null,
                'bike_number' => (int) $entry->bike_number,
                'grade' => $entry->grade !== null ? (string) $entry->grade : null,
                'race_score' => $this->raceScore($entry->race_score),
            ];
        }
        if ($currentRaceId !== null) {
            $summaries[$currentRaceId] = $this->scoreContextSummary($currentRaceId, $entrantCounts[$currentRaceId], $context);
        }
        foreach ($entrantCounts as $raceId => $entrantCount) {
            $summaries[$raceId] ??= $this->scoreContextSummary($raceId, $entrantCount, []);
        }

        return $summaries;
    }

    /**
     * @param  list<array{race_entry_id:int,player_id:?int,bike_number:int,grade:?string,race_score:?string}>  $context
     * @return array{hash:string,percentiles:array<int,?float>,score_mean:?float,score_max:?float,score_stddev_pop:?float}
     */
    private function scoreContextSummary(int $raceId, int $entrantCount, array $context): array
    {
        $hash = $this->hasher->hash(['race_id' => $raceId, 'entrant_count' => $entrantCount, 'entries' => $context]);
        $complete = count($context) === $entrantCount;
        $scores = [];
        foreach ($context as $entry) {
            $score = $entry['race_score'];
            if ($score === null || (float) $score <= 0) {
                $complete = false;
            } else {
                $scores[$entry['race_entry_id']] = (float) $score;
            }
        }
        $percentiles = array_fill_keys(array_column($context, 'race_entry_id'), null);
        if ($complete && count($scores) > 1) {
            foreach ($scores as $raceEntryId => $targetScore) {
                $rank = 1 + count(array_filter($scores, fn (float $score): bool => $score > $targetScore));
                $percentiles[$raceEntryId] = (count($scores) - $rank) / (count($scores) - 1);
            }
        }
        $numericScores = $complete ? array_values($scores) : [];

        return [
            'hash' => $hash,
            'percentiles' => $percentiles,
            'score_mean' => $this->math->mean($numericScores),
            'score_max' => $numericScores !== [] ? max($numericScores) : null,
            'score_stddev_pop' => $this->math->populationStandardDeviation($numericScores),
        ];
    }

    private function raceScore(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_numeric($value)) {
            throw new UnexpectedValueException('Historical race_score was not numeric.');
        }

        return number_format((float) $value, 2, '.', '');
    }
}
