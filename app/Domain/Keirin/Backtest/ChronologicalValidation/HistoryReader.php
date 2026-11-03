<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\ChronologicalValidation;

use App\Domain\Keirin\Scraping\Enums\RaceCategory;
use App\Domain\Keirin\Scraping\Support\RaceCategoryPolicy;
use App\Domain\Keirin\Statistics\DTO\Batch03HistoricalRaceDto;
use App\Domain\Keirin\Statistics\DTO\Batch04HeadToHeadEventDto;
use App\Domain\Keirin\Statistics\DTO\Batch04PositionHistoryContextDto;
use App\Domain\Keirin\Statistics\DTO\HistoricalRaceDto;
use App\Domain\Keirin\Statistics\Enums\HistoricalResultState;
use App\Domain\Keirin\Statistics\Support\Batch04CalculatorSupport;
use App\Domain\Keirin\Statistics\Support\HistoricalResultStateNormalizer;
use App\Domain\Keirin\Statistics\Support\HistoricalScoreContext;
use App\Domain\Keirin\Statistics\Support\PositionHistoryBucket;
use App\Domain\Keirin\Statistics\Support\RaceStageNormalizer;
use App\Domain\Keirin\Statistics\Support\StatisticalMath;
use Generator;
use RuntimeException;

final class HistoryReader
{
    public const MAX_PERSON_EVENTS = 4096;

    public function __construct(private readonly Batch04CalculatorSupport $support, private readonly HistoricalResultStateNormalizer $normalizer, private readonly RaceStageNormalizer $stages, private readonly StatisticalMath $math) {}

    public function eligible(array $event, string $targetDay, string $asOf): bool
    {
        Scope::day($event['race']['race_date']);
        Scope::day($event['logical_available_from']);

        return $event['race']['race_date'] < $targetDay && $event['logical_available_from'] <= $targetDay
            && Scope::instant($event['race']['scheduled_start_at']) < Scope::instant($asOf)
            && $event['verified'] === true;
    }

    /** Sources are enumerated by the immutable epoch, never by a directory scan. */
    public function events(array $epoch, Store $store, Scope $scope): Generator
    {
        $last = null;
        $seenSources = [];
        foreach ($epoch['sources'] as $source) {
            BundleRules::require($source['material_kind'] === $scope->kind, 'History material scope mismatch.');
            BundleRules::require(! isset($seenSources[$source['path']]), 'Duplicate epoch history source.');
            $seenSources[$source['path']] = true;
            Json::verify($source['path'], $source['seal']);
            $stream = fopen($source['path'], 'rb');
            try {
                while (($line = fgets($stream, 1048577)) !== false) {
                    BundleRules::require(str_ends_with($line, "\n"), 'Oversized or unterminated history row.');
                    $event = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    $this->validate($event, $scope);
                    BundleRules::require($event['race']['race_date'] < $epoch['logical_available_from']
                        && $event['logical_available_from'] <= $epoch['logical_available_from']
                        && Scope::instant($event['race']['scheduled_start_at']) < Scope::instant($epoch['history_event_end_exclusive']), 'Initial epoch boundary violation.');
                    $key = [Scope::instant($event['race']['scheduled_start_at']), $event['race']['original_race_id']];
                    BundleRules::require($last === null || $key > $last, 'History must be ordered with one fixed version per race.');
                    $last = $key;
                    yield $event;
                }
            } finally {
                fclose($stream);
            }
            Json::verify($source['path'], $source['seal']);
        }
        foreach ($epoch['results'] as $ref) {
            $result = $store->get($ref)['data'];
            if ($result['history_event'] !== null) {
                $this->validate($result['history_event'], $scope);
                yield $result['history_event'];
            }
        }
    }

    public function project(array $input, array $epoch, Store $store, Scope $scope): array
    {
        $budget = new HistoryMemoryBudget;
        $race = $input['race'];
        $players = array_column($input['entries'], 'original_player_id');
        $histories2 = $histories3 = $c1 = $pairs = $used = $excluded = $seenRaces = [];
        $buckets = [];
        foreach ($input['entries'] as $entry) {
            foreach (['FIELD_BIKE', 'FIELD_BASELINE', 'TRACK_FIELD_BIKE', 'TRACK_FIELD_BASELINE', 'FIELD_FRAME', 'TRACK_FIELD_FRAME'] as $group) {
                $buckets[$entry['original_entry_id']][$group] = new PositionHistoryBucket($this->support);
            }
        }
        foreach ($this->events($epoch, $store, $scope) as $event) {
            $budget->check();
            if ($event['race']['race_date'] >= $race['race_date'] || $event['logical_available_from'] > $race['race_date']
                || Scope::instant($event['race']['scheduled_start_at']) >= Scope::instant($input['input_as_of']) || $event['race']['original_race_id'] === $race['original_race_id']
                || (new RaceCategoryPolicy)->classify($event['race']['race_type']) !== RaceCategory::Men) {
                if (count($excluded) >= 8192) {
                    throw new RuntimeException('Bounded excluded-history audit capacity exceeded.');
                }
                $excluded[] = ['source_id' => $event['source_id'], 'race_id' => $event['race']['original_race_id'], 'reason' => 'DATE_EPOCH_CUTOFF_CATEGORY_OR_RESULT_NOT_ELIGIBLE'];

                continue;
            }
            $h = $event['race'];
            if (Scope::instant($h['scheduled_start_at']) < Scope::day($epoch['history_event_from'])) {
                $excluded[] = ['source_id' => $event['source_id'], 'race_id' => $h['original_race_id'], 'reason' => 'BEFORE_DECLARED_HISTORY_RANGE'];
                BundleRules::require(count($excluded) <= 8192, 'Bounded excluded-history audit capacity exceeded.');

                continue;
            }
            // C1 needs incomplete evidence as well as counted results to retain PARTIAL_HISTORY.
            foreach ($event['entries'] as $e) {
                $pid = $e['original_player_id'];
                if (in_array($pid, $players, true)) {
                    BundleRules::require(count($c1[$pid] ?? []) < self::MAX_PERSON_EVENTS, 'Bounded offline person-history capacity exceeded; no truncation applied.');
                    $c1[$pid][] = ['race_id' => $h['original_race_id'], 'meeting_id' => $h['meeting_id'], 'player_id' => $pid, 'race_date' => $h['race_date'], 'male_category' => true,
                        'entry_id' => $e['original_entry_id'], 'bike' => $e['bike'], 'result_player_id' => $pid, 'result_entry_id' => $e['original_entry_id'], 'race_status' => $event['race_status'],
                        'scheduled_start_at' => $h['scheduled_start_at'], 'result_id' => $event['source_id'], 'formal_provenance_verified' => $event['verified'],
                        'result_status' => $e['status'], 'rank' => $e['rank'], 'winning_technique' => $e['winning_technique']];
                }
            }
            if (! $event['verified'] || ! in_array($event['race_status'], ['CONFIRMED', 'CORRECTED'], true)) {
                $excluded[] = ['source_id' => $event['source_id'], 'race_id' => $h['original_race_id'], 'reason' => 'UNVERIFIED_OR_UNCONFIRMED_C1_EVIDENCE_ONLY'];
                BundleRules::require(count($excluded) <= 8192, 'Bounded excluded-history audit capacity exceeded.');

                continue;
            }
            BundleRules::require(! isset($seenRaces[$h['original_race_id']]), 'Multiple historical versions for one race in an epoch.');
            $seenRaces[$h['original_race_id']] = true;
            $at = Scope::instant($h['scheduled_start_at']);
            $contextEntries = array_map(fn (array $e): array => ['race_entry_id' => $e['original_entry_id'], 'player_id' => $e['original_player_id'],
                'bike_number' => $e['bike'], 'grade' => $e['grade'], 'race_score' => $this->support->raceScore($e['race_score_raw'])], $event['entries']);
            usort($contextEntries, fn (array $a, array $b): int => $a['race_entry_id'] <=> $b['race_entry_id']);
            $score = $this->support->scoreContext($h['original_race_id'], $h['entrant_count'], $contextEntries);
            $competition = HistoricalScoreContext::summarize($h['entrant_count'], $contextEntries, $this->math);
            $meetingStart = $h['meeting_start'] === null ? null : Scope::day($h['meeting_start']);
            $meetingEnd = $h['meeting_end'] === null ? null : Scope::day($h['meeting_end']);
            $meetingDuration = $meetingStart !== null && $meetingEnd !== null ? (int) $meetingStart->diff($meetingEnd)->days + 1 : null;
            $rows = [];
            foreach ($event['entries'] as $e) {
                $state = $this->normalizer->normalize($e['status']);
                $finish = $state->state === HistoricalResultState::NormalFinish && $e['rank'] !== null && $h['entrant_count'] > 1
                    ? ($h['entrant_count'] - $e['rank']) / ($h['entrant_count'] - 1) : null;
                $percentile = $score['percentiles'][$e['original_entry_id']] ?? null;
                $residual = $finish !== null && $percentile !== null ? $finish - $percentile : null;
                $raw = $this->support->raceScore($e['race_score_raw']);
                $fetched = Scope::instant($event['input_fetched_at']);
                $resultAt = Scope::instant($event['fetched_at']);
                $confirmed = $event['confirmed_at'] === null ? null : Scope::instant($event['confirmed_at']);
                $rows[$e['original_player_id']] = [$e, $state, $finish, $percentile];
                if (in_array($e['original_player_id'], $players, true)) {
                    $pid = $e['original_player_id'];
                    if (count($histories2[$pid] ?? []) >= self::MAX_PERSON_EVENTS) {
                        throw new RuntimeException('Bounded offline person-history capacity exceeded; no truncation applied.');
                    }
                    $histories2[$pid][] = new HistoricalRaceDto($pid, $h['original_race_id'], $e['original_entry_id'], $at, $h['meeting_id'], $event['context']['racetrack_id'],
                        $h['entrant_count'], $state->state, $state->tied, $e['rank'], $raw, $finish, $residual, $score['hash'], $fetched, $resultAt);
                    $histories3[$pid][] = new Batch03HistoricalRaceDto($pid, $h['original_race_id'], $e['original_entry_id'], $at, $event['context']['racetrack_id'], $h['meeting_id'],
                        $h['day_number'], $meetingDuration, $event['context']['meeting_grade'], $event['context']['meeting_day_kind'],
                        $h['race_type'], $h['race_type'], $this->stages->normalize($h['race_type']), $h['entrant_count'], $state->state, $state->tied, $e['rank'], $raw, $finish, $residual,
                        $score['hash'], $competition['score_mean'], $competition['score_max'], $competition['score_stddev_pop'], $percentile, $fetched, $resultAt, $confirmed);
                }
                if ($state->state !== HistoricalResultState::NormalFinish) {
                    continue;
                }
                foreach ($input['entries'] as $target) {
                    $groups = ['FIELD_BASELINE' => $h['entrant_count'] === $race['entrant_count'],
                        'FIELD_BIKE' => $h['entrant_count'] === $race['entrant_count'] && $e['bike'] === $target['bike'],
                        'TRACK_FIELD_BASELINE' => $h['entrant_count'] === $race['entrant_count'] && $event['context']['racetrack_id'] === $input['context']['racetrack_id'],
                        'TRACK_FIELD_BIKE' => $h['entrant_count'] === $race['entrant_count'] && $e['bike'] === $target['bike'] && $event['context']['racetrack_id'] === $input['context']['racetrack_id'],
                        'FIELD_FRAME' => $h['entrant_count'] === $race['entrant_count'] && $target['frame_number'] !== null && $e['frame_number'] === $target['frame_number'],
                        'TRACK_FIELD_FRAME' => $h['entrant_count'] === $race['entrant_count'] && $target['frame_number'] !== null && $e['frame_number'] === $target['frame_number'] && $event['context']['racetrack_id'] === $input['context']['racetrack_id']];
                    foreach ($groups as $name => $matches) {
                        if ($matches) {
                            $buckets[$target['original_entry_id']][$name]->add(Json::hash([$event['source_id'], $e]), $e['rank'], $finish, $residual, $event['fetched_at']);
                        }
                    }
                }
            }
            foreach ($players as $a) {
                foreach ($players as $b) {
                    if ($a >= $b || ! isset($rows[$a], $rows[$b])) {
                        continue;
                    }
                    [$x, $sx, $fx, $px] = $rows[$a];
                    [$y, $sy, $fy, $py] = $rows[$b];
                    $pairs[$a.':'.$b][] = new Batch04HeadToHeadEventDto($h['original_race_id'], $at, $h['entrant_count'], $event['context']['racetrack_id'], $a, $b,
                        $x['original_entry_id'], $y['original_entry_id'], $x['bike'], $y['bike'], $x['frame_number'], $y['frame_number'], $sx->state, $sy->state, $sx->tied, $sy->tied,
                        $x['rank'], $y['rank'], $fx, $fy, $this->support->raceScore($x['race_score_raw']), $this->support->raceScore($y['race_score_raw']), $px, $py, $score['hash'], $fetched, $fetched, $resultAt, $resultAt, $confirmed);
                }
            }
            $used[] = ['source_id' => $event['source_id'], 'race_id' => $h['original_race_id'], 'sha256' => Json::hash($event)];
            if (count($used) > 8192) {
                throw new RuntimeException('Bounded audit capacity exceeded.');
            }
        }
        $positions = [];
        foreach ($input['entries'] as $e) {
            $groups = array_map(fn (PositionHistoryBucket $b): array => $b->summary(), $buckets[$e['original_entry_id']]);
            if ($e['frame_number'] === null) {
                $groups['FIELD_FRAME'] = $groups['TRACK_FIELD_FRAME'] = null;
            }
            $positions[$e['original_entry_id']] = new Batch04PositionHistoryContextDto($groups, Json::hash($groups), ['population_scope' => 'ALL_EPOCH_RACES_NOT_PERSON_HISTORY']);
        }

        $budget->check();

        return ['batch02' => $histories2, 'batch03' => $histories3, 'c1' => $c1, 'pairs' => $pairs, 'positions' => $positions, 'used' => $used, 'excluded' => $excluded,
            'memory_budget' => ['baseline_bytes' => $budget->baselineBytes, 'working_limit_bytes' => $budget->workingLimitBytes, 'php_limit_bytes' => $budget->phpLimitBytes]];
    }

    private function validate(array $event, Scope $scope): void
    {
        BundleRules::require(array_key_exists('logical_available_from', $event) && is_string($event['logical_available_from']), 'Historical logical_available_from must be a required date string.');
        Scope::day($event['logical_available_from']);
        $h = $event['race'];
        Scope::day($h['race_date']);
        BundleRules::require(is_bool($event['verified']) && is_int($h['original_race_id']) && $h['original_race_id'] > 0, 'Historical version/identity type invalid.');
        foreach (['input_fetched_at', 'fetched_at', 'saved_at'] as $field) {
            Scope::instant($event[$field]);
        }
        BundleRules::require(Scope::instant($event['fetched_at']) <= Scope::instant($event['saved_at']), 'Historical save precedes fetch.');
        BundleRules::require($event['material_kind'] === $scope->kind && in_array((int) substr($h['race_date'], 0, 4), $scope->years(), true), 'History scope/year mismatch.');
        BundleRules::require(count($event['entries']) === $h['entrant_count'] && $h['entrant_count'] >= 5 && $h['entrant_count'] <= 9, 'Incomplete historical score/roster context.');
        BundleRules::require(in_array($event['race_status'], ['CONFIRMED', 'CORRECTED', 'CANCELLED', 'PROVISIONAL', 'UNDER_REVIEW', 'UNAVAILABLE'], true), 'Unknown historical page status.');
        $ids = $players = $bikes = $external = [];
        foreach ($event['entries'] as $e) {
            BundleRules::require($e['race_score_raw'] === null || (is_numeric($e['race_score_raw']) && is_finite((float) $e['race_score_raw'])), 'Non-finite historical score.');
            BundleRules::require(in_array($e['status'], ['FINISHED', 'TIED'], true)
                ? is_int($e['rank']) && $e['rank'] > 0 && $e['rank'] <= $h['entrant_count'] : $e['rank'] === null, 'Historical rank/state mismatch.');
            BundleRules::require(is_int($e['original_entry_id']) && $e['original_entry_id'] > 0 && is_int($e['original_player_id']) && $e['original_player_id'] > 0
                && is_int($e['bike']) && $e['bike'] >= 1 && $e['bike'] <= 9 && is_string($e['external_player_id']) && ctype_digit($e['external_player_id'])
                && in_array($e['status'], ['FINISHED', 'TIED', 'DISQUALIFIED', 'CRASHED', 'DID_NOT_FINISH', 'DID_NOT_START', 'WITHDRAWN', 'UNKNOWN'], true), 'History identity/state invalid.');
            $ids[] = $e['original_entry_id'];
            $players[] = $e['original_player_id'];
            $bikes[] = $e['bike'];
            $external[] = $e['external_player_id'];
        }
        foreach ([$ids, $players, $bikes, $external] as $values) {
            BundleRules::require(count($values) === count(array_unique($values)), 'Duplicate historical identity.');
        }
        BundleRules::require(Scope::instant($h['scheduled_start_at'])->setTimezone(new \DateTimeZone('Asia/Tokyo'))->format('Y-m-d') === $h['race_date'], 'History event date mismatch.');
    }
}
