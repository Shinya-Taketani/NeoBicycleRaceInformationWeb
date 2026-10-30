<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Support;

use App\Domain\Keirin\Statistics\DTO\Batch02BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch02TargetEntryDto;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class AcquiredHistorySupport
{
    public const VERSION = 'ACQUIRED-HISTORY-JST-v2';

    public function __construct(private readonly DeterministicJsonHasher $hasher) {}

    public static function jst(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->setTimezone(new DateTimeZone('Asia/Tokyo'));
    }

    public function prepare(Batch02TargetEntryDto $target, array $histories, Batch02BuildOptionsDto $options): array
    {
        $inputAsOf = self::jst($target->inputAsOf);
        $historyFrom = self::jst($options->historyFrom);
        if ($inputAsOf->format('Y') < '2022' || $inputAsOf->format('Y') > '2025'
            || $historyFrom->format('Y') < '2022' || $historyFrom >= $inputAsOf) {
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
        $hash = $this->hasher->hash(['calendar_version' => self::VERSION, 'target' => [$target->raceId, $target->raceEntryId, $target->playerId, $target->bikeNumber,
            $inputAsOf->format('U.u'), $target->stat01InputHash], 'history_from' => $historyFrom->format('U.u'),
            'history' => array_map(fn ($e) => [$e->raceId, $e->raceEntryId, $e->playerId, $e->scheduledStartAt->format('U.u'),
                $e->resultState->value, $e->rank, $e->raceScore, $e->scoreExpectationResidual, $e->historicalScoreContextHash,
                $e->raceEntryFetchedAt->format('U.u'), $e->raceResultFetchedAt->format('U.u')], $events)]);
        $sourceMax = null;
        foreach ($events as $event) {
            $sourceMax = max($sourceMax, $event->raceEntryFetchedAt, $event->raceResultFetchedAt);
        }

        return ['events' => $events, 'hash' => $hash, 'evidence' => [
            'calendar_version' => self::VERSION, 'calendar_timezone' => 'Asia/Tokyo',
            'history_from' => $historyFrom->format(DATE_ATOM), 'history_event_count' => count($events),
            'history_reference_ids' => array_map(fn ($e) => ['race_id' => $e->raceId, 'entry_id' => $e->raceEntryId], $events),
            'excluded_history_references' => $excluded,
            'source_max_fetched_at' => $sourceMax === null ? null : self::jst($sourceMax)->format(DATE_ATOM),
            'data_role' => 'RETROSPECTIVE_ONLY', 'prediction_use' => 'NOT_AUTHORIZED', 'historical_as_of_available' => false,
            'fact_basis' => 'CONFIRMED_OR_CORRECTED_DB_RESULT_STATUS', 'publication_time_status' => 'UNKNOWN']];
    }
}
