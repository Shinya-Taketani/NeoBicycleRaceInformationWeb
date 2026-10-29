<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Support;

use App\Domain\Keirin\Statistics\DTO\Batch02BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch02TargetEntryDto;
use InvalidArgumentException;

final class AcquiredHistorySupport
{
    public function __construct(private readonly DeterministicJsonHasher $hasher) {}

    public function prepare(Batch02TargetEntryDto $target, array $histories, Batch02BuildOptionsDto $options): array
    {
        if ($target->inputAsOf->format('Y') < '2022' || $target->inputAsOf->format('Y') > '2025'
            || $options->historyFrom->format('Y') < '2022' || $options->historyFrom >= $target->inputAsOf) {
            throw new InvalidArgumentException('A bounded 2022-2025 development history is required.');
        }
        $events = $seen = $excluded = [];
        foreach ($histories as $e) {
            if ($target->playerId === null || $e->playerId !== $target->playerId) {
                throw new InvalidArgumentException('History belongs to another player.');
            }
            if ($e->raceId === $target->raceId || $e->scheduledStartAt >= $target->inputAsOf || $e->scheduledStartAt < $options->historyFrom) {
                $excluded[] = ['race_id' => $e->raceId, 'entry_id' => $e->raceEntryId, 'scheduled_start_at' => $e->scheduledStartAt->format(DATE_ATOM),
                    'reason' => $e->raceId === $target->raceId ? 'TARGET_RACE' : ($e->scheduledStartAt >= $target->inputAsOf ? 'AT_OR_AFTER_INPUT_AS_OF' : 'BEFORE_ACQUIRED_SCOPE')];

                continue;
            }
            if (isset($seen[$e->raceId])) {
                throw new InvalidArgumentException('Duplicate historical race.');
            }
            $seen[$e->raceId] = true;
            $events[] = $e;
        }
        usort($events, fn ($a, $b) => [$a->scheduledStartAt, $a->raceId, $a->raceEntryId] <=> [$b->scheduledStartAt, $b->raceId, $b->raceEntryId]);
        $hash = $this->hasher->hash(['target' => [$target->raceId, $target->raceEntryId, $target->playerId, $target->bikeNumber,
            $target->inputAsOf->format('U.u'), $target->stat01InputHash], 'history_from' => $options->historyFrom->format(DATE_ATOM),
            'history' => array_map(fn ($e) => [$e->raceId, $e->raceEntryId, $e->playerId, $e->scheduledStartAt->format('U.u'),
                $e->resultState->value, $e->rank, $e->raceScore, $e->scoreExpectationResidual, $e->historicalScoreContextHash,
                $e->raceEntryFetchedAt->format('U.u'), $e->raceResultFetchedAt->format('U.u')], $events)]);
        $sourceMax = null;
        foreach ($events as $event) {
            $sourceMax = max($sourceMax, $event->raceEntryFetchedAt, $event->raceResultFetchedAt);
        }

        return ['events' => $events, 'hash' => $hash, 'evidence' => [
            'history_from' => $options->historyFrom->format(DATE_ATOM), 'history_event_count' => count($events),
            'history_reference_ids' => array_map(fn ($e) => ['race_id' => $e->raceId, 'entry_id' => $e->raceEntryId], $events),
            'excluded_history_references' => $excluded,
            'source_max_fetched_at' => $sourceMax?->format(DATE_ATOM),
            'data_role' => 'RETROSPECTIVE_ONLY', 'prediction_use' => 'NOT_AUTHORIZED', 'historical_as_of_available' => false,
            'fact_basis' => 'CONFIRMED_OR_CORRECTED_DB_RESULT_STATUS', 'publication_time_status' => 'UNKNOWN']];
    }
}
