<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Repositories;

use App\Domain\Keirin\Statistics\DTO\Batch04PositionHistoryContextDto;
use App\Domain\Keirin\Statistics\DTO\Batch04RaceInputDto;
use App\Domain\Keirin\Statistics\DTO\Batch04TargetEntryDto;
use App\Domain\Keirin\Statistics\Enums\HistoricalResultState;
use App\Domain\Keirin\Statistics\Support\Batch04CalculatorSupport;
use App\Domain\Keirin\Statistics\Support\HistoricalResultStateNormalizer;
use App\Domain\Keirin\Statistics\Support\PositionHistoryBucket;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Batch04PositionBiasRepository
{
    private const HISTORY_ROW_BATCH_SIZE = 250;

    /** @var array<string, PositionHistoryBucket> */
    private array $buckets = [];

    private ?DateTimeImmutable $historyFrom = null;

    private ?DateTimeImmutable $maxInputAsOf = null;

    private ?string $lastScheduledStartAt = null;

    private int $lastRaceId = 0;

    private int $lastRaceEntryId = 0;

    /** @var Collection<int, object> */
    private Collection $rowBuffer;

    private int $rowBufferIndex = 0;

    private bool $sourceExhausted = false;

    private ?object $pendingRow = null;

    /** @var list<object>|null */
    private ?array $nextRaceRows = null;

    public function __construct(
        private readonly HistoricalResultStateNormalizer $resultNormalizer,
        private readonly Batch04CalculatorSupport $support,
    ) {
        $this->rowBuffer = collect();
    }

    public function begin(DateTimeImmutable $historyFrom, DateTimeImmutable $maxInputAsOf): void
    {
        $this->buckets = [];
        $this->historyFrom = $historyFrom;
        $this->maxInputAsOf = $maxInputAsOf;
        $this->lastScheduledStartAt = null;
        $this->lastRaceId = 0;
        $this->lastRaceEntryId = 0;
        $this->rowBuffer = collect();
        $this->rowBufferIndex = 0;
        $this->sourceExhausted = false;
        $this->pendingRow = null;
        $this->nextRaceRows = null;
    }

    /** @return array<int, Batch04PositionHistoryContextDto> */
    public function contextsForRace(Batch04RaceInputDto $race): array
    {
        $this->advanceTo($race->inputAsOf);
        $contexts = [];
        foreach ($race->entries as $target) {
            $groups = [
                'FIELD_BIKE' => $this->summary($this->fieldBikeKey($target)),
                'FIELD_BASELINE' => $this->summary($this->fieldBaselineKey($target)),
                'TRACK_FIELD_BIKE' => $this->summary($this->trackFieldBikeKey($target)),
                'TRACK_FIELD_BASELINE' => $this->summary($this->trackFieldBaselineKey($target)),
                'FIELD_FRAME' => $target->frameNumber !== null ? $this->summary($this->fieldFrameKey($target)) : null,
                'TRACK_FIELD_FRAME' => $target->frameNumber !== null ? $this->summary($this->trackFieldFrameKey($target)) : null,
            ];
            $hashInputs = [];
            $sourceMaxFetchedAt = null;
            foreach ($groups as $name => $group) {
                $hashInputs[$name] = $group === null ? null : [
                    'history_count' => $group['history_count'],
                    'history_hash' => $group['history_hash'],
                ];
                if (is_array($group) && isset($group['source_max_fetched_at'])) {
                    $sourceMaxFetchedAt = max($sourceMaxFetchedAt ?? '', (string) $group['source_max_fetched_at']);
                }
            }
            $contexts[$target->raceEntryId] = new Batch04PositionHistoryContextDto(
                groups: $groups,
                historyInputHash: $this->support->hash(['position_history_buckets' => $hashInputs]),
                evidence: [
                    'position_history_buckets' => $hashInputs,
                    'source_max_fetched_at' => $sourceMaxFetchedAt !== '' ? $sourceMaxFetchedAt : null,
                    'population_history_processing' => 'CHRONOLOGICAL_CUMULATIVE_HASH_CHAIN',
                ],
            );
        }

        return $contexts;
    }

    private function advanceTo(DateTimeImmutable $cutoff): void
    {
        while (true) {
            $this->nextRaceRows ??= $this->readNextRaceRows();
            if ($this->nextRaceRows === null) {
                return;
            }
            $scheduledStartAt = new DateTimeImmutable((string) $this->nextRaceRows[0]->scheduled_start_at);
            if ($scheduledStartAt >= $cutoff) {
                return;
            }
            $this->addHistoricalRace($this->nextRaceRows);
            $this->nextRaceRows = null;
        }
    }

    /** @return list<object>|null */
    private function readNextRaceRows(): ?array
    {
        $first = $this->pendingRow ?? $this->nextRow();
        $this->pendingRow = null;
        if ($first === null) {
            return null;
        }
        $raceId = (int) $first->race_id;
        $rows = [$first];
        while (($row = $this->nextRow()) !== null) {
            if ((int) $row->race_id !== $raceId) {
                $this->pendingRow = $row;

                break;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function nextRow(): ?object
    {
        if ($this->rowBufferIndex >= $this->rowBuffer->count()) {
            $this->loadRows();
        }
        if ($this->rowBufferIndex >= $this->rowBuffer->count()) {
            return null;
        }

        return $this->rowBuffer->get($this->rowBufferIndex++);
    }

    private function loadRows(): void
    {
        if ($this->sourceExhausted || ! $this->historyFrom instanceof DateTimeImmutable || ! $this->maxInputAsOf instanceof DateTimeImmutable) {
            $this->rowBuffer = collect();

            return;
        }
        $query = DB::table('race_entries as history_entries')
            ->join('races as history_races', 'history_races.id', '=', 'history_entries.race_id')
            ->leftJoin('race_results as history_results', function ($join): void {
                $join->on('history_results.race_id', '=', 'history_entries.race_id')
                    ->on('history_results.bike_number', '=', 'history_entries.bike_number');
            })
            ->where('history_races.scheduled_start_at', '>=', $this->historyFrom)
            ->where('history_races.scheduled_start_at', '<', $this->maxInputAsOf)
            ->whereIn('history_races.result_status', ['CONFIRMED', 'CORRECTED'])
            ->where(function (Builder $query): void {
                $query->where('history_races.race_type', 'like', 'Ａ級%')
                    ->orWhere('history_races.race_type', 'like', 'Ｓ級%');
            })
            ->when($this->lastScheduledStartAt !== null, function (Builder $query): void {
                $query->where(function (Builder $cursor): void {
                    $cursor->where('history_races.scheduled_start_at', '>', $this->lastScheduledStartAt)
                        ->orWhere(function (Builder $sameTime): void {
                            $sameTime->where('history_races.scheduled_start_at', '=', $this->lastScheduledStartAt)
                                ->where(function (Builder $raceCursor): void {
                                    $raceCursor->where('history_races.id', '>', $this->lastRaceId)
                                        ->orWhere(function (Builder $entryCursor): void {
                                            $entryCursor->where('history_races.id', '=', $this->lastRaceId)
                                                ->where('history_entries.id', '>', $this->lastRaceEntryId);
                                        });
                                });
                        });
                });
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
            ->limit(self::HISTORY_ROW_BATCH_SIZE);
        $this->rowBuffer = $query->get();
        $this->rowBufferIndex = 0;
        if ($this->rowBuffer->isEmpty()) {
            $this->sourceExhausted = true;

            return;
        }
        $last = $this->rowBuffer->last();
        $this->lastScheduledStartAt = (string) $last->scheduled_start_at;
        $this->lastRaceId = (int) $last->race_id;
        $this->lastRaceEntryId = (int) $last->race_entry_id;
        if ($this->rowBuffer->count() < self::HISTORY_ROW_BATCH_SIZE) {
            $this->sourceExhausted = true;
        }
    }

    /** @param list<object> $rows */
    private function addHistoricalRace(array $rows): void
    {
        $first = $rows[0];
        $raceId = (int) $first->race_id;
        $entrantCount = (int) $first->entrant_count;
        $scoreEntries = array_map(fn (object $row): array => [
            'race_entry_id' => (int) $row->race_entry_id,
            'player_id' => $row->player_id !== null ? (int) $row->player_id : null,
            'bike_number' => (int) $row->bike_number,
            'grade' => $row->grade !== null ? (string) $row->grade : null,
            'race_score' => $this->support->raceScore($row->race_score),
        ], $rows);
        $scoreContext = $this->support->scoreContext($raceId, $entrantCount, $scoreEntries);
        foreach ($rows as $row) {
            if ($row->result_status === null) {
                continue;
            }
            $normalized = $this->resultNormalizer->normalize((string) $row->result_status);
            if ($normalized->state !== HistoricalResultState::NormalFinish) {
                continue;
            }
            $rank = $row->rank !== null ? (int) $row->rank : null;
            $finishPercentile = $rank !== null && $entrantCount > 1
                ? ($entrantCount - $rank) / ($entrantCount - 1)
                : null;
            $scorePercentile = $scoreContext['percentiles'][(int) $row->race_entry_id] ?? null;
            $residual = $finishPercentile !== null && $scorePercentile !== null
                ? $finishPercentile - $scorePercentile
                : null;
            $event = [
                'race_id' => $raceId,
                'race_entry_id' => (int) $row->race_entry_id,
                'player_id' => $row->player_id !== null ? (int) $row->player_id : null,
                'scheduled_start_at' => $this->support->timestamp(new DateTimeImmutable((string) $row->scheduled_start_at)),
                'racetrack_id' => $row->racetrack_id !== null ? (int) $row->racetrack_id : null,
                'entrant_count' => $entrantCount,
                'bike_number' => (int) $row->bike_number,
                'frame_number' => $row->frame_number !== null ? (int) $row->frame_number : null,
                'normalized_result_state' => $normalized->state->value,
                'tied' => $normalized->tied,
                'rank' => $rank,
                'race_score' => $this->support->raceScore($row->race_score),
                'finish_strength_percentile' => $finishPercentile,
                'subject_score_percentile' => $scorePercentile,
                'score_expectation_residual' => $residual,
                'historical_score_context_hash' => $scoreContext['hash'],
                'race_entry_fetched_at' => (string) $row->race_entry_fetched_at,
                'race_result_fetched_at' => (string) $row->race_result_fetched_at,
                'result_confirmed_at' => $row->result_confirmed_at !== null ? (string) $row->result_confirmed_at : null,
            ];
            $eventHash = $this->support->hash($event);
            $keys = [
                'field|'.$entrantCount.'|'.(int) $row->bike_number,
                'field-baseline|'.$entrantCount,
                'track|'.($row->racetrack_id ?? 'null').'|'.$entrantCount.'|'.(int) $row->bike_number,
                'track-baseline|'.($row->racetrack_id ?? 'null').'|'.$entrantCount,
            ];
            if ($row->frame_number !== null) {
                $keys[] = 'frame|'.$entrantCount.'|'.(int) $row->frame_number;
                $keys[] = 'track-frame|'.($row->racetrack_id ?? 'null').'|'.$entrantCount.'|'.(int) $row->frame_number;
            }
            foreach ($keys as $key) {
                $this->addToBucket($key, $eventHash, $rank, $finishPercentile, $residual, (string) $row->race_result_fetched_at);
            }
        }
    }

    private function addToBucket(
        string $key,
        string $eventHash,
        ?int $rank,
        ?float $finishPercentile,
        ?float $residual,
        string $fetchedAt,
    ): void {
        $this->buckets[$key] ??= new PositionHistoryBucket($this->support);
        $this->buckets[$key]->add($eventHash, $rank, $finishPercentile, $residual, $fetchedAt);
    }

    /** @return array<string, mixed> */
    private function summary(string $key): array
    {
        return ($this->buckets[$key] ?? new PositionHistoryBucket($this->support))->summary();
    }

    private function fieldBikeKey(Batch04TargetEntryDto $target): string
    {
        return 'field|'.$target->declaredEntrantCount.'|'.$target->bikeNumber;
    }

    private function fieldBaselineKey(Batch04TargetEntryDto $target): string
    {
        return 'field-baseline|'.$target->declaredEntrantCount;
    }

    private function trackFieldBikeKey(Batch04TargetEntryDto $target): string
    {
        return 'track|'.($target->racetrackId ?? 'null').'|'.$target->declaredEntrantCount.'|'.$target->bikeNumber;
    }

    private function trackFieldBaselineKey(Batch04TargetEntryDto $target): string
    {
        return 'track-baseline|'.($target->racetrackId ?? 'null').'|'.$target->declaredEntrantCount;
    }

    private function fieldFrameKey(Batch04TargetEntryDto $target): string
    {
        return 'frame|'.$target->declaredEntrantCount.'|'.$target->frameNumber;
    }

    private function trackFieldFrameKey(Batch04TargetEntryDto $target): string
    {
        return 'track-frame|'.($target->racetrackId ?? 'null').'|'.$target->declaredEntrantCount.'|'.$target->frameNumber;
    }
}
