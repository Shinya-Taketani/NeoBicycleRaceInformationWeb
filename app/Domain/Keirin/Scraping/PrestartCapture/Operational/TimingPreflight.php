<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture\Operational;

final class TimingPreflight
{
    private const EVENTS = ['request_started_at', 'response_received_at', 'raw_saved_at', 'validation_completed_at',
        'publication_completed_at', 'prediction_input_as_of'];

    public function evaluate(array $timing, string $scenario): array
    {
        $unknown = ['validation_result' => 'UNKNOWN', 'failure_reasons' => ['CLOCK_OR_SCHEDULE_EVIDENCE_MISSING'],
            'missing_evidence' => ['COMPARABLE_CLOCK_AND_SCHEDULE_EVIDENCE'],
            'timing_status' => 'TIMING_UNKNOWN', 'clock_basis' => $scenario, 'scheduled_start_precision' => 'MINUTE',
            'actual_start_at' => null, 'official_publication_at' => null, 'historical_as_of_available' => false];
        $allowed = ['clock_evidence', 'schedule_timezone', 'schedule_precision', 'minute_convention',
            'observed_scheduled_start_at', 'uncertainty_microseconds', ...self::EVENTS];
        if (array_diff(array_keys($timing), $allowed) !== []) {
            return [...$unknown, 'failure_reasons' => ['TIMING_FIELDS_INVALID']];
        }
        if ($scenario !== Contract::SIMULATED || ($timing['clock_evidence'] ?? null) !== 'SIMULATED_CLOCK_WITH_BOUNDS'
            || ($timing['schedule_timezone'] ?? null) !== 'Asia/Tokyo' || ($timing['schedule_precision'] ?? null) !== 'MINUTE'
            || ($timing['minute_convention'] ?? null) !== 'START_OF_MINUTE') {
            return $unknown;
        }
        $scheduleValue = $timing['observed_scheduled_start_at'] ?? null;
        $schedule = Contract::instant($scheduleValue);
        $scheduleError = $timing['uncertainty_microseconds']['observed_scheduled_start_at'] ?? null;
        if ($schedule === null || ! is_string($scheduleValue) || (new \DateTimeImmutable($scheduleValue))->format('s.u') !== '00.000000'
            || ! $this->error($schedule, $scheduleError)) {
            return $unknown;
        }
        // Minute precision is an interval, not a fabricated exact start second.
        $start = [$schedule - $scheduleError, $schedule + 59999999 + $scheduleError];
        $events = [];
        foreach (self::EVENTS as $key) {
            $instant = Contract::instant($timing[$key] ?? null);
            $error = $timing['uncertainty_microseconds'][$key] ?? null;
            if ($instant === null || ! $this->error($instant, $error)) {
                return [...$unknown, 'failure_reasons' => ['CLOCK_OR_EVENT_EVIDENCE_MISSING:'.$key]];
            }
            $events[$key] = [$instant - $error, $instant + $error];
        }
        $previous = null;
        foreach ($events as $key => $interval) {
            if ($previous !== null && ($previous[1] > $interval[0]
                || ($key === 'response_received_at' && $previous[1] === $interval[0]))) {
                return [...$unknown, 'failure_reasons' => ['CLOCK_EVENT_ORDER_NOT_ESTABLISHED']];
            }
            $previous = $interval;
        }
        $received = $events['response_received_at'];
        if ($received[1] >= $start[0]) {
            return [...$unknown, 'failure_reasons' => [$received[0] > $start[1] ? 'AFTER_SCHEDULED_INTERVAL' : 'CLOCK_INTERVALS_OVERLAP'],
                'timing_status' => $received[0] > $start[1] ? 'AT_OR_AFTER_SCHEDULE' : 'TIMING_UNKNOWN'];
        }
        if ($events['prediction_input_as_of'][1] >= $start[0]) {
            return [...$unknown, 'failure_reasons' => ['INPUT_AS_OF_NOT_STRICTLY_BEFORE_SCHEDULE']];
        }

        return ['validation_result' => 'VALIDATED', 'failure_reasons' => [], 'missing_evidence' => [], 'timing_status' => 'SIMULATED_PRESTART_CONDITIONS_SATISFIED',
            'clock_basis' => Contract::SIMULATED, 'scheduled_start_precision' => 'MINUTE',
            'scheduled_start_interval_utc_microseconds' => $start, 'event_intervals_utc_microseconds' => $events,
            'actual_start_at' => null, 'official_publication_at' => null, 'historical_as_of_available' => false];
    }

    private function error(int $instant, mixed $error): bool
    {
        return is_int($error) && $error >= 0 && $error <= PHP_INT_MAX - abs($instant) - 60000000;
    }
}
