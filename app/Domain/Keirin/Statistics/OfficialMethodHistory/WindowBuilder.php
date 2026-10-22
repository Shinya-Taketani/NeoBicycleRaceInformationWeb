<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\OfficialMethodHistory;

use App\Domain\Keirin\Statistics\DTO\OfficialMethodHistoryInputDto;
use App\Domain\Keirin\Statistics\DTO\OfficialMethodHistoryProfileDto;
use App\Domain\Keirin\Statistics\Support\DeterministicJsonHasher;
use DateTimeImmutable;
use RuntimeException;

class WindowBuilder
{
    public function __construct(private readonly DeterministicJsonHasher $hasher) {}

    public function build(OfficialMethodHistoryInputDto $input): OfficialMethodHistoryProfileDto
    {
        $target = $input->target;
        $cutoff = Contract::date($target['meeting_start']);
        $targetDate = Contract::date($target['race_date']);
        if ($targetDate < $cutoff || $targetDate > Contract::date($target['meeting_end']) || $input->inputAsOf < $cutoff
            || $target['race_date'] < '2022-01-01' || $target['race_date'] > '2025-12-31'
            || ($target['race_id'] ?? 0) < 1 || ($target['meeting_id'] ?? 0) < 1
            || ($input->entry['entry_id'] ?? 0) < 1 || preg_match('/^[0-9]{6}$/D', $input->entry['external_player_id'] ?? '') !== 1) {
            throw new RuntimeException('Invalid official method history input.');
        }
        $windows = [];
        foreach ([3, 5, 10, 20] as $n) {
            $windows['LAST_'.$n] = $this->bucket($n);
        }
        foreach ([30, 60, 90, 180] as $days) {
            $windows['DAYS_'.$days] = $this->bucket(null) + ['window_start' => $cutoff->modify('-'.$days.' days')->format(DATE_ATOM),
                'left_truncated' => $cutoff->modify('-'.$days.' days') < Contract::date('2022-01-01')];
        }
        $recent = $this->bucket(5);
        $baseline = $this->bucket(15);
        $audit = ['history_rows' => 0, 'candidate_starts' => 0, 'verified_started' => 0, 'nonstarts' => 0, 'cancelled' => 0,
            'fetched_at_or_after_input' => 0, 'corrected_results' => 0, 'references' => [], 'latest_fetched_at' => null];
        $hash = hash_init('sha256');
        $previous = null;
        $previousRace = null;
        foreach ($input->history as $row) {
            $date = Contract::date($row['race_date']);
            if ($row['race_date'] < '2022-01-01' || $row['race_date'] > '2025-12-31') {
                throw new RuntimeException('Forbidden official history year.');
            }
            if ($row['race_id'] === $target['race_id'] || $row['meeting_id'] === $target['meeting_id'] || $date >= $cutoff) {
                continue;
            }
            if (($row['race_id'] ?? 0) < 1 || ($row['entry_id'] ?? 0) < 1 || $row['external_player_id'] !== $input->entry['external_player_id']
                || ($row['player_id'] !== null && $input->entry['player_id'] !== null && $row['player_id'] !== $input->entry['player_id'])) {
                throw new RuntimeException('Official history player/race identity mismatch.');
            }
            $event = $row['scheduled_start_at'] === null ? null : new DateTimeImmutable($row['scheduled_start_at']);
            if ($event !== null && $event->setTimezone($cutoff->getTimezone())->format('Y-m-d') !== $row['race_date']) {
                throw new RuntimeException('Official history scheduled date mismatch.');
            }
            if ($event !== null && $event >= $cutoff) {
                continue;
            }
            $key = [$row['race_date'], $event?->getTimestamp() ?? PHP_INT_MAX, $row['race_id'], $row['entry_id']];
            if (($previous !== null && $key > $previous) || $previousRace === $row['race_id']) {
                throw new RuntimeException('Unordered/duplicate official history.');
            }
            $previous = $key;
            $previousRace = $row['race_id'];
            hash_update($hash, $this->hasher->hash($row)."\n");
            $audit['history_rows']++;
            if (($row['fetched_at'] ?? null) !== null) {
                $fetched = new DateTimeImmutable($row['fetched_at']);
                $audit['fetched_at_or_after_input'] += (int) ($fetched >= $input->inputAsOf);
                if ($audit['latest_fetched_at'] === null || $fetched > new DateTimeImmutable($audit['latest_fetched_at'])) {
                    $audit['latest_fetched_at'] = $fetched->format(DATE_ATOM);
                }
            }
            $audit['corrected_results'] += (int) ($row['race_status'] === 'CORRECTED');
            if ($row['participation'] === 'CANCELLED') {
                $audit['cancelled']++;

                continue;
            }
            if ($row['evidence_verified'] && $row['participation'] === 'NON_START') {
                $audit['nonstarts']++;

                continue;
            }
            $issues = $row['issues'];
            if ($row['method_missing']) {
                $issues[] = 'MISSING_TOP2_METHOD';
            }
            $issues = array_values(array_unique($issues));
            if ($event === null) {
                $issues[] = 'SCHEDULED_TIME_UNKNOWN';
            }
            if ($row['meeting_id'] === null) {
                $issues[] = 'MEETING_ID_UNKNOWN';
            }
            $verified = $row['evidence_verified'] && $row['participation'] === 'STARTED';
            if (! $verified) {
                $issues[] = 'PARTICIPATION_NOT_VERIFIED';
            }
            $audit['candidate_starts']++;
            $slot = $audit['candidate_starts'];
            $audit['verified_started'] += (int) $verified;
            if ($slot <= 20) {
                $audit['references'][] = $row;
                if ($slot <= 5) {
                    $this->add($recent, $row, $verified, $issues);
                } else {
                    $this->add($baseline, $row, $verified, $issues);
                }
            }
            foreach ([3, 5, 10, 20] as $n) {
                if ($slot <= $n) {
                    $this->add($windows['LAST_'.$n], $row, $verified, $issues);
                }
            }
            foreach ([30, 60, 90, 180] as $days) {
                if (($event ?? $date) >= $cutoff->modify('-'.$days.' days')) {
                    $this->add($windows['DAYS_'.$days], $row, $verified, $issues);
                }
            }
        }
        foreach ($windows as &$window) {
            $window = $this->finish($window);
        }
        unset($window);
        $audit += ['history_sha256' => hash_final($hash), 'contract' => Contract::VERSION,
            'coverage_scope' => 'OBSERVED_DB_HISTORY_2022_2025_NOT_CAREER_TOTAL', 'cutoff' => $cutoff->format(DATE_ATOM),
            'history_before_2022_status' => 'UNOBSERVED',
            'data_role' => 'DEVELOPMENT_RETROSPECTIVE', 'historical_as_of_available' => false,
            'prediction_use' => 'NOT_AUTHORIZED', 'official_publication_at' => null, 'correction_effective_time_status' => 'UNKNOWN'];

        return new OfficialMethodHistoryProfileDto($target, $input->entry, $input->inputAsOf, $windows,
            $this->finish($recent), $this->finish($baseline), $audit);
    }

    private function bucket(?int $n): array
    {
        return ['requested_starts' => $n, 'selected_slots' => 0, 'verified_started_count' => 0, 'method_observed_count' => 0,
            'observed_method_counts' => array_fill_keys(array_values(Contract::METHODS), 0), 'race_ids' => [], 'issues' => []];
    }

    private function add(array &$bucket, array $row, bool $verified, array $issues): void
    {
        $bucket['selected_slots']++;
        $bucket['verified_started_count'] += (int) $verified;
        if ($bucket['requested_starts'] !== null) {
            $bucket['race_ids'][] = $row['race_id'];
        }
        foreach ($issues as $issue) {
            $bucket['issues'][$issue] = ($bucket['issues'][$issue] ?? 0) + 1;
        }
        if ($verified && $row['method'] !== null) {
            if (! array_key_exists($row['method'], $bucket['observed_method_counts'])) {
                throw new RuntimeException('Unknown normalized official method.');
            }
            $bucket['observed_method_counts'][$row['method']]++;
            $bucket['method_observed_count']++;
        }
    }

    private function finish(array $bucket): array
    {
        $reasons = array_keys($bucket['issues']);
        if (($bucket['left_truncated'] ?? false) === true) {
            $reasons[] = 'LEFT_TRUNCATED';
        }
        if ($bucket['requested_starts'] !== null && $bucket['selected_slots'] < $bucket['requested_starts']) {
            $reasons[] = 'INSUFFICIENT_SAMPLE';
        }
        if ($bucket['selected_slots'] === 0) {
            $reasons[] = 'NO_HISTORY';
        }
        $counts = $reasons === [] ? $bucket['observed_method_counts'] : null;
        $total = $counts === null ? null : array_sum($counts);

        return $bucket + ['status' => $reasons === [] ? 'AVAILABLE' : $reasons[0], 'reasons' => $reasons,
            'method_counts' => $counts, 'composition' => $total !== null && $total > 0 ? array_map(fn ($n) => $n / $total, $counts) : null,
            'composition_status' => $total === null ? 'NOT_ESTABLISHED' : ($total === 0 ? 'ZERO_METHOD_OBSERVATIONS' : 'COMPUTABLE')];
    }
}
