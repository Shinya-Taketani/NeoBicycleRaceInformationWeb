<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture\Operational;

final class BudgetPreflight
{
    public const LIMIT_KEYS = ['max_tracks', 'max_dates', 'max_races', 'slots_per_race', 'max_attempts', 'max_concurrent',
        'min_interval_seconds', 'retry', 'connect_timeout_seconds', 'total_timeout_seconds'];

    public function evaluate(array $snapshot, array $limits, array $target): array
    {
        $extra = ['reservation_performed' => false, 'budget_consumed' => false, 'scope' => 'OFFLINE_SNAPSHOT_ONLY'];
        if (array_diff(array_keys($snapshot), ['ledger_known', 'stopped', 'active_attempts', 'attempts', 'planned_attempt', 'request_settings']) !== []
            || array_diff(array_keys($limits), self::LIMIT_KEYS) !== []) {
            return Contract::result(['BUDGET_FIELDS_INVALID'], $extra);
        }
        foreach (self::LIMIT_KEYS as $key) {
            if (! is_int($limits[$key] ?? null) || $limits[$key] < ($key === 'retry' ? 0 : 1)) {
                return Contract::result(['BUDGET_LIMITS_UNRESOLVED'], $extra);
            }
        }
        if ($limits['connect_timeout_seconds'] > $limits['total_timeout_seconds']) {
            return Contract::result(['BUDGET_CONFIGURATION_CONFLICT'], $extra);
        }
        $settings = $snapshot['request_settings'] ?? [];
        if (! is_array($settings) || array_diff(array_keys($settings), ['retry', 'connect_timeout_seconds', 'total_timeout_seconds']) !== []) {
            return Contract::result(['BUDGET_REQUEST_SETTINGS_UNRESOLVED'], $extra);
        }
        foreach (['retry', 'connect_timeout_seconds', 'total_timeout_seconds'] as $key) {
            if (! is_int($settings[$key] ?? null) || $settings[$key] < ($key === 'retry' ? 0 : 1)) {
                return Contract::result(['BUDGET_REQUEST_SETTINGS_UNRESOLVED'], $extra);
            }
            if ($settings[$key] > $limits[$key]) {
                return Contract::result(['REQUEST_SETTINGS_EXCEED_LIMITS'], $extra);
            }
        }
        if ($settings['connect_timeout_seconds'] > $settings['total_timeout_seconds']) {
            return Contract::result(['BUDGET_CONFIGURATION_CONFLICT'], $extra);
        }
        if (($snapshot['ledger_known'] ?? null) !== true || ($snapshot['stopped'] ?? null) !== false
            || ! is_int($snapshot['active_attempts'] ?? null) || $snapshot['active_attempts'] < 0
            || ! is_array($snapshot['attempts'] ?? null) || ! array_is_list($snapshot['attempts'])
            || count($snapshot['attempts']) > 1000 || ! is_array($snapshot['planned_attempt'] ?? null)) {
            return Contract::result(['BUDGET_STATE_UNKNOWN_OR_STOPPED'], $extra);
        }
        $planned = $snapshot['planned_attempt'];
        $planTime = Contract::instant($planned['request_started_at'] ?? null);
        if (! $this->attempt($planned) || $planTime === null || ! is_int($target['race_id'] ?? null)
            || ($planned['race_id'] ?? null) !== $target['race_id'] || ($planned['race_date'] ?? null) !== ($target['race_date'] ?? null)
            || ($planned['track_code'] ?? null) !== ($target['track_code'] ?? null)) {
            return Contract::result(['PLANNED_ATTEMPT_TARGET_OR_SLOT_INVALID'], $extra);
        }
        $reasons = $ids = $slots = $races = $dates = $tracks = [];
        $perRace = $times = [];
        foreach ([...$snapshot['attempts'], $planned] as $index => $attempt) {
            if (! is_array($attempt) || ! $this->attempt($attempt)) {
                return Contract::result(['BUDGET_HISTORY_UNVERIFIED'], $extra);
            }
            $time = Contract::instant($attempt['request_started_at'] ?? null);
            if ($time === null) {
                return Contract::result(['BUDGET_HISTORY_TIME_UNKNOWN'], $extra);
            }
            $race = $attempt['race_id'];
            $slot = $race.':'.$attempt['slot_id'];
            if (isset($ids[$attempt['attempt_id']]) || isset($slots[$slot])) {
                $reasons[] = 'DUPLICATE_ATTEMPT_OR_SLOT';
            }
            $ids[$attempt['attempt_id']] = $slots[$slot] = true;
            $races[$race] = $dates[$attempt['race_date']] = $tracks[$attempt['track_code']] = true;
            $perRace[$race] = ($perRace[$race] ?? 0) + 1;
            if ($perRace[$race] > $limits['slots_per_race'] || $attempt['slot_id'] > $limits['slots_per_race']) {
                $reasons[] = 'RACE_SLOT_LIMIT_EXCEEDED';
            }
            $times[] = $time;
            if ($index < count($snapshot['attempts']) && $time > $planTime) {
                $reasons[] = 'ATTEMPT_INTERVAL_OR_ORDER_INVALID';
            }
        }
        // Sort a validation-only copy; all scope attempts, including failures, share the interval limit.
        usort($times, static fn (int $a, int $b): int => $a <=> $b);
        for ($index = 1; $index < count($times); $index++) {
            if (intdiv($times[$index] - $times[$index - 1], 1000000) < $limits['min_interval_seconds']) {
                $reasons[] = 'ATTEMPT_INTERVAL_OR_ORDER_INVALID';
            }
        }
        if (count($snapshot['attempts']) >= $limits['max_attempts'] || count($races) > $limits['max_races']
            || count($dates) > $limits['max_dates'] || count($tracks) > $limits['max_tracks']) {
            $reasons[] = 'TOTAL_BUDGET_EXCEEDED';
        }
        if ($snapshot['active_attempts'] >= $limits['max_concurrent']) {
            $reasons[] = 'CONCURRENCY_LIMIT_EXCEEDED';
        }

        return Contract::result($reasons, [...$extra, 'observed_attempt_count' => count($snapshot['attempts']),
            'would_be_attempt_count' => count($snapshot['attempts']) + 1]);
    }

    private function attempt(array $attempt): bool
    {
        $date = is_string($attempt['race_date'] ?? null) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $attempt['race_date']) : false;

        return array_diff(array_keys($attempt), ['attempt_id', 'race_id', 'slot_id', 'race_date', 'track_code', 'request_started_at', 'state']) === []
            && Contract::reference($attempt['attempt_id'] ?? null) && is_int($attempt['race_id'] ?? null) && $attempt['race_id'] > 0
            && is_int($attempt['slot_id'] ?? null) && $attempt['slot_id'] > 0
            && $date !== false && $date->format('Y-m-d') === $attempt['race_date']
            && is_string($attempt['track_code'] ?? null) && preg_match('/^[0-9]{2}$/D', $attempt['track_code']) === 1
            && in_array($attempt['state'] ?? null, ['PLANNED', 'RESERVED', 'FAILED', 'SUCCEEDED', 'INTERRUPTED'], true);
    }
}
