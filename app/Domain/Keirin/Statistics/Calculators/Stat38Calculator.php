<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Calculators;

use App\Domain\Keirin\Scraping\Enums\RaceEntryResultStatus;
use App\Domain\Keirin\Statistics\DTO\Stat38FeatureResultDto;
use App\Domain\Keirin\Statistics\DTO\Stat38InputDto;
use App\Domain\Keirin\Statistics\Enums\StatisticFeatureResultStatus as Status;
use App\Domain\Keirin\Statistics\Enums\StatisticQualityStatus;
use App\Domain\Keirin\Statistics\Support\DeterministicJsonHasher;
use DateTimeImmutable;
use InvalidArgumentException;

class Stat38Calculator
{
    public const STAT_CODE = 'STAT-38';

    public const CALCULATION_VERSION = 'STAT38-PLANNED-DISTANCE-MVP-v2-PR117';

    public function __construct(private readonly DeterministicJsonHasher $hasher) {}

    public function calculate(Stat38InputDto $input): Stat38FeatureResultDto
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $input->raceDate);
        if ($input->raceId < 1 || $date === false || $date->format('Y-m-d') !== $input->raceDate
            || $input->raceDate < '2022-01-01' || $input->raceDate > '2025-12-31'
            || ! is_int($input->entry['id'] ?? null) || $input->entry['id'] < 1 || ($input->entry['race_id'] ?? null) !== $input->raceId
            || (($input->entry['player_id'] ?? null) !== null && (! is_int($input->entry['player_id']) || $input->entry['player_id'] < 1))
            || preg_match('/^\d{6}$/D', $input->entry['external_player_id'] ?? '') !== 1
            || ! is_int($input->entry['bike_number'] ?? null) || $input->entry['bike_number'] < 1 || $input->entry['bike_number'] > 9) {
            throw new InvalidArgumentException('Invalid/forbidden STAT-38 target.');
        }
        $target = $input->predictionObservation;
        $retro = $input->retrospectiveObservation;
        foreach ([$target, $retro] as $observation) {
            if ($observation !== null && ($observation['race_id'] !== $input->raceId
                || $observation['race_date'] !== $input->raceDate || ! $this->entrant($observation, $input->entry))) {
                throw new InvalidArgumentException('STAT-38 target observation identity mismatch.');
            }
        }
        if ($target !== null && (! $this->availableBefore($target, $input->inputAsOf)
            || $target['scheduled_start_at'] === null || $input->inputAsOf >= new DateTimeImmutable($target['scheduled_start_at']))) {
            $target = null;
        }
        $counts = ['past_rows' => 0, 'prediction_verified' => 0, 'prediction_unknown' => 0,
            'prediction_nonstarts' => 0, 'prediction_same_distance' => 0,
            'retrospective_observed_entries' => 0, 'retrospective_same_distance_entries' => 0, 'retrospective_started' => 0,
            'retrospective_same_distance' => 0, 'retrospective_unknown_participation' => 0];
        $historyHash = hash_init('sha256');
        $lastRace = null;
        foreach ($input->history as $row) {
            if (($row['race_id'] ?? 0) < 1 || $row['race_id'] === $input->raceId || $row['race_date'] < '2022-01-01'
                || $row['race_date'] >= $input->raceDate || $row['external_player_id'] !== $input->entry['external_player_id']
                || $row['player_id'] !== $input->entry['player_id'] || $row['entry_id'] < 1
                || ($lastRace !== null && $row['race_id'] <= $lastRace)) {
                throw new InvalidArgumentException('Unordered/duplicate/future/other-player STAT-38 history.');
            }
            $lastRace = $row['race_id'];
            $counts['past_rows']++;
            hash_update($historyHash, $this->hasher->hash($row)."\n");
            $historyEntry = ['bike_number' => $row['bike_number'], 'external_player_id' => $row['external_player_id']];
            foreach (['observation', 'prediction_observation'] as $key) {
                $observation = $row[$key];
                if ($observation !== null && ($observation['race_id'] !== $row['race_id']
                    || $observation['race_date'] !== $row['race_date'] || ! $this->entrant($observation, $historyEntry))) {
                    throw new InvalidArgumentException('STAT-38 history observation identity mismatch.');
                }
            }
            $resultStatus = RaceEntryResultStatus::tryFrom($row['result_status'] ?? '');
            $nonstart = in_array($resultStatus, [RaceEntryResultStatus::DidNotStart, RaceEntryResultStatus::Withdrawn], true);
            $started = $resultStatus !== null && ! $nonstart;
            $participation = $row['result_link_verified'] && ($started || $nonstart);
            $past = $row['observation'];
            if ($past !== null && $past['planned_distance_m'] !== null) {
                $counts['retrospective_observed_entries']++;
                $counts['retrospective_same_distance_entries'] += (int) ($retro !== null && $past['planned_distance_m'] === $retro['planned_distance_m']);
                if ($participation && $started) {
                    $counts['retrospective_started']++;
                    $counts['retrospective_same_distance'] += (int) ($retro !== null && $past['planned_distance_m'] === $retro['planned_distance_m']);
                } elseif (! $participation) {
                    $counts['retrospective_unknown_participation']++;
                }
            }
            $prior = $row['prediction_observation'];
            $event = $row['scheduled_start_at'] === null ? null : new DateTimeImmutable($row['scheduled_start_at']);
            $resultAt = $row['result_fetched_at'] === null ? null : new DateTimeImmutable($row['result_fetched_at']);
            $savedAt = $row['result_saved_at'] === null ? null : new DateTimeImmutable($row['result_saved_at']);
            if (! $participation || $event === null || $event >= $input->inputAsOf
                || $resultAt === null || $resultAt >= $input->inputAsOf || $savedAt === null || $savedAt >= $input->inputAsOf
                || $prior === null || ! $this->availableBefore($prior, $input->inputAsOf) || $prior['planned_distance_m'] === null) {
                $counts['prediction_unknown']++;
            } elseif ($nonstart) {
                $counts['prediction_nonstarts']++;
            } else {
                $counts['prediction_verified']++;
                $counts['prediction_same_distance'] += (int) ($target !== null && $prior['planned_distance_m'] === $target['planned_distance_m']);
            }
        }
        $complete = $target !== null && $target['planned_distance_m'] !== null
            && $counts['past_rows'] > 0 && $counts['prediction_unknown'] === 0;
        $retrospectiveKnown = $counts['retrospective_observed_entries'] > 0 && $counts['retrospective_unknown_participation'] === 0;
        $features = [
            'PLANNED_DISTANCE_M' => $target['planned_distance_m'] ?? null,
            'PLANNED_LAPS' => $target['planned_laps'] ?? null,
            'PREDICTION_EXPERIENCE_COUNT' => $complete ? $counts['prediction_verified'] : null,
            'PREDICTION_SAME_DISTANCE_COUNT' => $complete ? $counts['prediction_same_distance'] : null,
            'PREDICTION_EXPERIENCE_STATUS' => $complete ? 'VERIFIED_SAVED_HISTORY_SCOPE' : 'NOT_ESTABLISHED',
            'RETROSPECTIVE' => ['use' => 'RETROSPECTIVE_ONLY', 'planned_distance_m' => $retro['planned_distance_m'] ?? null,
                'planned_laps' => $retro['planned_laps'] ?? null, 'observed_past_entry_count' => $counts['retrospective_observed_entries'],
                'same_distance_observed_entry_count' => ($retro['planned_distance_m'] ?? null) !== null ? $counts['retrospective_same_distance_entries'] : null,
                'confirmed_started_count' => $retrospectiveKnown ? $counts['retrospective_started'] : null,
                'same_distance_started_count' => $retrospectiveKnown && ($retro['planned_distance_m'] ?? null) !== null ? $counts['retrospective_same_distance'] : null],
            'ACTUAL_DISTANCE_M' => null, 'ACTUAL_LAPS' => null, 'POINTS' => null, 'CONFIDENCE' => null,
        ];
        $evidence = ['counts' => $counts, 'history_scope' => 'SAVED_2022_2025_PRIOR_CALENDAR_DATES_ONLY_NOT_CAREER_TOTAL',
            'distance_matching' => 'EXACT_OFFICIAL_PLANNED_METRES_NO_SIMILARITY_INFERENCE',
            'prediction_observation' => $target, 'retrospective_observation' => $retro,
            'reasons' => array_values(array_filter([$target === null ? 'TARGET_NOT_PROVEN_AVAILABLE_BEFORE_INPUT_AS_OF' : null,
                ! $complete ? 'HISTORY_IDENTITY_EVENT_PARTICIPATION_OR_ASOF_NOT_ESTABLISHED' : null])),
            'historical_as_of_available' => false, 'prediction_use' => 'NOT_AUTHORIZED',
            'history_sha256' => hash_final($historyHash)];

        return new Stat38FeatureResultDto($target === null ? Status::MissingInput : Status::Partial,
            StatisticQualityStatus::Degraded, $features, $evidence, $this->hasher->hash([
                'version' => self::CALCULATION_VERSION, 'race_id' => $input->raceId, 'entry' => $input->entry,
                'input_as_of' => $input->inputAsOf->format(DATE_ATOM), 'features' => $features, 'evidence' => $evidence]));
    }

    private function availableBefore(array $observation, DateTimeImmutable $cutoff): bool
    {
        return $observation['fetched_at'] !== null && $observation['saved_at'] !== null
            && new DateTimeImmutable($observation['fetched_at']) < $cutoff
            && new DateTimeImmutable($observation['saved_at']) < $cutoff;
    }

    private function entrant(array $observation, array $entry): bool
    {
        foreach ($observation['entrants'] as $candidate) {
            if ($candidate['bike_number'] === $entry['bike_number'] && $candidate['external_player_id'] === $entry['external_player_id']) {
                return true;
            }
        }

        return false;
    }
}
