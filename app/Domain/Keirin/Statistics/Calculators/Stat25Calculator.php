<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Calculators;

use App\Domain\Keirin\Statistics\DTO\Batch02BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch02FeatureResultDto;
use App\Domain\Keirin\Statistics\DTO\Batch02TargetEntryDto;
use App\Domain\Keirin\Statistics\DTO\HistoricalRaceDto;
use App\Domain\Keirin\Statistics\Enums\HistoricalResultState;
use App\Domain\Keirin\Statistics\Enums\StatisticFeatureResultStatus;
use App\Domain\Keirin\Statistics\Enums\StatisticQualityStatus;
use App\Domain\Keirin\Statistics\Support\AcquiredHistorySupport;
use App\Domain\Keirin\Statistics\Support\Batch02CalculatorSupport;
use App\Domain\Keirin\Statistics\Support\DeterministicJsonHasher;

final class Stat25Calculator
{
    public const VERSION = 'STAT-25-disqualification-history-v1';

    public function __construct(private readonly Batch02CalculatorSupport $windows, private readonly DeterministicJsonHasher $hasher) {}

    /** @param list<HistoricalRaceDto> $histories */
    public function calculate(Batch02TargetEntryDto $target, array $histories, Batch02BuildOptionsDto $options, array $stages = []): Batch02FeatureResultDto
    {
        $context = (new AcquiredHistorySupport($this->hasher))->prepare($target, $histories, $options);
        $events = $context['events'];
        $started = array_values(array_filter($events, fn ($e) => $e->started()));
        $ten = $this->windows->countWindow($started, 10);
        $yearStart = $target->inputAsOf->modify('-1 year');
        $year = array_values(array_filter($events, fn ($e) => $e->scheduledStartAt >= $yearStart));
        $last = $lastIndex = null;
        foreach ($started as $index => $event) {
            if ($event->resultState === HistoricalResultState::Disqualified) {
                $last = $event;
                $lastIndex = $index;
            }
        }
        $acquired = $this->metrics($events);
        $ambiguousRecency = false;
        foreach ($started as $index => $event) {
            if ($event->resultState === HistoricalResultState::UnknownAbnormal && ($lastIndex === null || $index > $lastIndex)) {
                $ambiguousRecency = true;
            }
        }
        $stageGroups = [];
        $usedStages = [];
        foreach ($events as $event) {
            $stage = $stages[$event->raceId] ?? null;
            $usedStages[$event->raceId] = $stage;
            $stageGroups[$stage['stage'] ?? 'UNKNOWN'][] = $event;
        }
        ksort($stageGroups);
        $reasons = ['RETROSPECTIVE_ONLY', 'PUBLICATION_AND_CORRECTION_TIMES_UNVERIFIED'];
        if (! $ten['metadata']['window_complete'] || $options->historyFrom > $yearStart) {
            $reasons[] = 'HISTORY_WINDOW_INCOMPLETE';
        }
        if ($acquired['unknown_status_count'] > 0) {
            $reasons[] = 'RESULT_STATE_UNKNOWN';
        }
        $features = [
            'LAST_10_STARTED' => $ten['metadata'] + $this->metrics($ten['events']),
            'PAST_YEAR' => ['window_start_at' => $yearStart->format(DATE_ATOM), 'window_complete' => $options->historyFrom <= $yearStart] + $this->metrics($year),
            'ACQUIRED_HISTORY' => $acquired,
            'STAGE_BREAKDOWN' => array_map($this->metrics(...), $stageGroups),
            'RECENCY' => [
                'last_known_disqualification_at' => $last?->scheduledStartAt->format(DATE_ATOM),
                'last_disqualification_at' => $ambiguousRecency ? null : $last?->scheduledStartAt->format(DATE_ATOM),
                'days_since_last_disqualification' => $last === null || $ambiguousRecency ? null : ($target->inputAsOf->getTimestamp() - $last->scheduledStartAt->getTimestamp()) / 86400,
                'started_races_since_last_disqualification' => $lastIndex === null || $ambiguousRecency ? null : count($started) - $lastIndex - 1,
                'repeated_disqualification_observed' => $acquired['observed_disqualification_count'] >= 2 ? true : ($acquired['disqualification_count'] === null ? null : false),
                'recency_status' => $ambiguousRecency ? 'RESULT_STATE_UNKNOWN' : ($last === null ? 'NO_OBSERVED_DISQUALIFICATION' : 'AVAILABLE'),
            ],
            'UNAVAILABLE' => ['official_violation_code', 'warning_count', 'sanction_duration', 'adjudication_effective_at'],
            'prediction_use' => 'NOT_AUTHORIZED', 'historical_as_of_available' => false,
        ];
        $input = ['version' => self::VERSION, 'history_context_hash' => $context['hash'], 'stages' => $usedStages];
        $status = $target->playerId === null ? StatisticFeatureResultStatus::MissingInput
            : ($events === [] ? StatisticFeatureResultStatus::NoHistory
                : (count($reasons) > 2 ? StatisticFeatureResultStatus::PartialHistory : StatisticFeatureResultStatus::Valid));

        return new Batch02FeatureResultDto($target, $status, StatisticQualityStatus::Degraded, $features,
            $context['evidence'] + ['quality_reasons' => $reasons,
                'reason_text_status' => 'NOT_INTERPRETED_AS_OFFICIAL_CODE_OR_SANCTION'], $this->hasher->hash($input));
    }

    private function metrics(array $events): array
    {
        $started = array_values(array_filter($events, fn ($e) => $e->started()));
        $unknown = count(array_filter($events, fn ($e) => $e->resultState === HistoricalResultState::UnknownAbnormal));
        $dq = count(array_filter($started, fn ($e) => $e->resultState === HistoricalResultState::Disqualified));

        return ['observed_rows' => count($events), 'started_race_count' => count($started),
            'excluded_non_starts' => count($events) - count($started), 'unknown_status_count' => $unknown,
            'observed_disqualification_count' => $dq,
            'disqualification_count' => $events === [] || $unknown > 0 ? null : $dq,
            'disqualification_rate' => count($started) === 0 || $unknown > 0 ? null : (float) ($dq / count($started))];
    }
}
