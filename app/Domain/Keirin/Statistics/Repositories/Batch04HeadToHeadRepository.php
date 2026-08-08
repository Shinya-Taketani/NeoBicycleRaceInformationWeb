<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Repositories;

use App\Domain\Keirin\Statistics\DTO\Batch04BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch04HeadToHeadEventDto;
use App\Domain\Keirin\Statistics\DTO\Batch04RaceInputDto;
use App\Domain\Keirin\Statistics\Support\Batch04CalculatorSupport;
use App\Domain\Keirin\Statistics\Support\HistoricalResultStateNormalizer;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class Batch04HeadToHeadRepository
{
    public function __construct(
        private readonly HistoricalResultStateNormalizer $resultNormalizer,
        private readonly Batch04CalculatorSupport $support,
    ) {}

    /**
     * @param  list<Batch04RaceInputDto>  $races
     * @return array<string, list<Batch04HeadToHeadEventDto>>
     */
    public function historiesForWorkingBatch(array $races, Batch04BuildOptionsDto $options): array
    {
        $playerIds = [];
        $maxInputAsOf = null;
        foreach ($races as $race) {
            $maxInputAsOf = $maxInputAsOf === null || $race->inputAsOf > $maxInputAsOf
                ? $race->inputAsOf
                : $maxInputAsOf;
            foreach ($race->entries as $entry) {
                if ($entry->playerId !== null) {
                    $playerIds[$entry->playerId] = true;
                }
            }
        }
        $playerIds = array_map('intval', array_keys($playerIds));
        if (count($playerIds) < 2 || ! $maxInputAsOf instanceof DateTimeImmutable) {
            return [];
        }

        $candidateRaces = DB::table('race_entries as candidate_entries')
            ->join('races as candidate_races', 'candidate_races.id', '=', 'candidate_entries.race_id')
            ->whereIntegerInRaw('candidate_entries.player_id', $playerIds)
            ->where('candidate_races.scheduled_start_at', '>=', $options->historyFrom)
            ->where('candidate_races.scheduled_start_at', '<', $maxInputAsOf)
            ->whereIn('candidate_races.result_status', ['CONFIRMED', 'CORRECTED'])
            ->where(function (Builder $query): void {
                $query->where('candidate_races.race_type', 'like', 'Ａ級%')
                    ->orWhere('candidate_races.race_type', 'like', 'Ｓ級%');
            })
            ->select('candidate_entries.race_id')
            ->groupBy('candidate_entries.race_id')
            ->havingRaw('COUNT(DISTINCT candidate_entries.player_id) >= 2');

        $rows = DB::table('race_entries as history_entries')
            ->joinSub($candidateRaces, 'candidate_history_races', function ($join): void {
                $join->on('candidate_history_races.race_id', '=', 'history_entries.race_id');
            })
            ->join('races as history_races', 'history_races.id', '=', 'history_entries.race_id')
            ->leftJoin('race_results as history_results', function ($join): void {
                $join->on('history_results.race_id', '=', 'history_entries.race_id')
                    ->on('history_results.bike_number', '=', 'history_entries.bike_number');
            })
            ->select([
                'history_races.id as race_id',
                'history_races.scheduled_start_at',
                'history_races.racetrack_id',
                'history_races.entrant_count',
                'history_races.result_confirmed_at',
                'history_entries.id as race_entry_id',
                'history_entries.player_id',
                'history_entries.bike_number',
                'history_entries.frame_number',
                'history_entries.grade',
                'history_entries.race_score',
                'history_entries.fetched_at as race_entry_fetched_at',
                'history_results.rank',
                'history_results.result_status',
                'history_results.fetched_at as race_result_fetched_at',
            ])
            ->orderBy('history_races.scheduled_start_at')
            ->orderBy('history_races.id')
            ->orderBy('history_entries.id')
            ->cursor();

        $events = [];
        $raceRows = [];
        $currentRaceId = null;
        foreach ($rows as $row) {
            $raceId = (int) $row->race_id;
            if ($currentRaceId !== null && $raceId !== $currentRaceId) {
                $this->appendRaceEvents($events, $raceRows, $playerIds);
                $raceRows = [];
            }
            $currentRaceId = $raceId;
            $raceRows[] = $row;
        }
        if ($raceRows !== []) {
            $this->appendRaceEvents($events, $raceRows, $playerIds);
        }

        return $events;
    }

    /**
     * @param  array<string, list<Batch04HeadToHeadEventDto>>  $events
     * @param  list<object>  $rows
     * @param  list<int>  $candidatePlayerIds
     */
    private function appendRaceEvents(array &$events, array $rows, array $candidatePlayerIds): void
    {
        $firstRow = $rows[0];
        $raceId = (int) $firstRow->race_id;
        $entrantCount = (int) $firstRow->entrant_count;
        $scoreEntries = array_map(fn (object $row): array => [
            'race_entry_id' => (int) $row->race_entry_id,
            'player_id' => $row->player_id !== null ? (int) $row->player_id : null,
            'bike_number' => (int) $row->bike_number,
            'grade' => $row->grade !== null ? (string) $row->grade : null,
            'race_score' => $this->support->raceScore($row->race_score),
        ], $rows);
        $scoreContext = $this->support->scoreContext($raceId, $entrantCount, $scoreEntries);
        $candidateLookup = array_fill_keys($candidatePlayerIds, true);
        $participants = [];
        foreach ($rows as $row) {
            if ($row->player_id === null || ! isset($candidateLookup[(int) $row->player_id])) {
                continue;
            }
            $participants[(int) $row->player_id][] = $row;
        }
        $participants = array_filter($participants, fn (array $playerRows): bool => count($playerRows) === 1);
        ksort($participants, SORT_NUMERIC);
        $playerIds = array_keys($participants);
        for ($leftIndex = 0; $leftIndex < count($playerIds); $leftIndex++) {
            for ($rightIndex = $leftIndex + 1; $rightIndex < count($playerIds); $rightIndex++) {
                $first = $participants[$playerIds[$leftIndex]][0];
                $second = $participants[$playerIds[$rightIndex]][0];
                if ($first->result_status === null || $second->result_status === null) {
                    continue;
                }
                $firstResult = $this->resultNormalizer->normalize((string) $first->result_status);
                $secondResult = $this->resultNormalizer->normalize((string) $second->result_status);
                $firstRank = $first->rank !== null ? (int) $first->rank : null;
                $secondRank = $second->rank !== null ? (int) $second->rank : null;
                $event = new Batch04HeadToHeadEventDto(
                    raceId: $raceId,
                    scheduledStartAt: new DateTimeImmutable((string) $firstRow->scheduled_start_at),
                    entrantCount: $entrantCount,
                    racetrackId: $firstRow->racetrack_id !== null ? (int) $firstRow->racetrack_id : null,
                    firstPlayerId: (int) $first->player_id,
                    secondPlayerId: (int) $second->player_id,
                    firstRaceEntryId: (int) $first->race_entry_id,
                    secondRaceEntryId: (int) $second->race_entry_id,
                    firstBikeNumber: (int) $first->bike_number,
                    secondBikeNumber: (int) $second->bike_number,
                    firstFrameNumber: $first->frame_number !== null ? (int) $first->frame_number : null,
                    secondFrameNumber: $second->frame_number !== null ? (int) $second->frame_number : null,
                    firstResultState: $firstResult->state,
                    secondResultState: $secondResult->state,
                    firstTied: $firstResult->tied,
                    secondTied: $secondResult->tied,
                    firstRank: $firstRank,
                    secondRank: $secondRank,
                    firstFinishPercentile: $this->finishPercentile($firstResult->state->value, $firstRank, $entrantCount),
                    secondFinishPercentile: $this->finishPercentile($secondResult->state->value, $secondRank, $entrantCount),
                    firstRaceScore: $this->support->raceScore($first->race_score),
                    secondRaceScore: $this->support->raceScore($second->race_score),
                    firstScorePercentile: $scoreContext['percentiles'][(int) $first->race_entry_id] ?? null,
                    secondScorePercentile: $scoreContext['percentiles'][(int) $second->race_entry_id] ?? null,
                    historicalScoreContextHash: $scoreContext['hash'],
                    firstRaceEntryFetchedAt: new DateTimeImmutable((string) $first->race_entry_fetched_at),
                    secondRaceEntryFetchedAt: new DateTimeImmutable((string) $second->race_entry_fetched_at),
                    firstRaceResultFetchedAt: $first->race_result_fetched_at !== null ? new DateTimeImmutable((string) $first->race_result_fetched_at) : null,
                    secondRaceResultFetchedAt: $second->race_result_fetched_at !== null ? new DateTimeImmutable((string) $second->race_result_fetched_at) : null,
                    resultConfirmedAt: $firstRow->result_confirmed_at !== null ? new DateTimeImmutable((string) $firstRow->result_confirmed_at) : null,
                );
                $events[$event->pairKey()][] = $event;
            }
        }
    }

    private function finishPercentile(string $state, ?int $rank, int $entrantCount): ?float
    {
        return $state === 'NORMAL_FINISH' && $rank !== null && $entrantCount > 1
            ? ($entrantCount - $rank) / ($entrantCount - 1)
            : null;
    }
}
