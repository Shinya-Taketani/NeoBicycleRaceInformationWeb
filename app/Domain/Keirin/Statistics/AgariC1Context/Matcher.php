<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\AgariC1Context;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalMeetingGradeAnalysis\Classification;
use App\Domain\Keirin\Statistics\AgariC1Input\Contract as InputContract;
use RuntimeException;

final class Matcher
{
    public static function race(array $race, array $records): array
    {
        InputContract::keys($race, ['year', 'race_id', 'entries']);
        if (! is_array($race['entries']) || ! array_is_list($race['entries']) || count($race['entries']) < 5
            || count($race['entries']) > 9 || count($records) !== count($race['entries'])) {
            throw new RuntimeException('Invalid target/record race.');
        }
        $matches = $externals = $meetings = $classes = $ids = $bikes = [];
        foreach ($race['entries'] as $i => $target) {
            Contract::target($target, $race['year']);
            if ($target['race_id'] !== $race['race_id'] || isset($ids[$target['entry_id']]) || isset($bikes[$target['bike']])) {
                throw new RuntimeException('Target race identity/duplicates.');
            }
            $ids[$target['entry_id']] = $bikes[$target['bike']] = true;
            $match = self::entry($target, $records[$i]);
            $matches[] = $match;
            if ($match['checks']['identity_matched']) {
                $id = $match['context']['external_player_id'];
                $externals[$id] = ($externals[$id] ?? 0) + 1;
            }
            if ($match['checks']['meeting_matched']) {
                $m = $match['context']['meeting'];
                $meetings[Files::canonical([$m['meeting_id'], $m['starts_on'], $m['ends_on'], $match['context']['race_date']])] = true;
            }
            if ($match['checks']['class_known']) {
                $classes[$match['race_class']] = true;
            }
        }
        foreach ($matches as &$match) {
            if (count($meetings) > 1 || count($classes) > 1) {
                $match['reasons'][] = 'CONFLICTING_RACE_CONTEXT';
            }
            if ($match['checks']['identity_matched'] && $externals[$match['context']['external_player_id']] > 1) {
                $match['reasons'][] = 'DUPLICATE_EXTERNAL_PLAYER_ID';
            }
            $match['reasons'] = array_values(array_unique($match['reasons']));
            $match['candidate'] = $match['reasons'] === [];
        }
        unset($match);

        return $matches;
    }

    private static function entry(array $target, array $raw): array
    {
        InputContract::keys($raw, ['entry_id', 'entry', 'race', 'day', 'meeting']);
        if ($raw['entry_id'] !== $target['entry_id']) {
            throw new RuntimeException('Extraction/target stream identity mismatch.');
        }
        $row = $raw;
        foreach (Contract::COLUMNS as $alias => $fields) {
            if ($raw[$alias] === null) {
                continue;
            }
            InputContract::keys($raw[$alias], $fields);
            foreach ($fields as $field) {
                $value = $raw[$alias][$field];
                if (in_array($field, ['id', 'race_id', 'player_id', 'bike_number', 'race_number', 'race_day_id', 'race_meeting_id'], true)) {
                    if (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) && filter_var($value, FILTER_VALIDATE_INT) !== false) {
                        $row[$alias][$field] = (int) $value;
                    } elseif ($value !== null && ! InputContract::id($value)) {
                        throw new RuntimeException('Invalid database identifier type.');
                    }
                } elseif ($value !== null && ! is_string($value)) {
                    throw new RuntimeException('Database text must remain text.');
                }
            }
        }
        $e = $row['entry'];
        $r = $row['race'];
        $d = $row['day'];
        $m = $row['meeting'];
        $reasons = [];
        $dbMatched = $e !== null && $r !== null;
        if (! $dbMatched) {
            $reasons[] = 'DB_ENTRY_OR_RACE_MISSING_OR_OUT_OF_SCOPE';
        }
        $associated = $dbMatched && $e['id'] === $target['entry_id'] && $e['race_id'] === $target['race_id']
            && $e['bike_number'] === $target['bike'] && $r['id'] === $target['race_id'];
        if ($dbMatched && ! $associated) {
            $reasons[] = 'ENTRY_ASSOCIATION_MISMATCH';
        }
        $dateMatches = $r !== null && $r['race_date'] === $target['race_date'];
        if ($r !== null && ! $dateMatches) {
            $reasons[] = 'RACE_DATE_MISMATCH';
        }
        $sourceMatches = ($r['source'] ?? null) === 'keirin_jp' && ($m['source'] ?? null) === 'keirin_jp';
        if (($r !== null && $r['source'] !== 'keirin_jp') || ($m !== null && $m['source'] !== 'keirin_jp')) {
            $reasons[] = 'SOURCE_MISMATCH';
        }
        if ($d === null) {
            $reasons[] = 'MISSING_RACE_DAY';
        }
        if ($m === null) {
            $reasons[] = 'MISSING_MEETING';
        }
        $meetingValid = $d !== null && $m !== null && $r !== null && InputContract::id($m['id'])
            && $r['race_day_id'] === $d['id'] && $d['race_meeting_id'] === $m['id'] && $d['race_date'] === $r['race_date']
            && InputContract::date($m['starts_on']) && InputContract::date($m['ends_on'])
            && $m['starts_on'] <= $target['race_date'] && $m['ends_on'] >= $target['race_date'];
        if ($d !== null && $m !== null && ! $meetingValid) {
            $reasons[] = 'INVALID_MEETING_CONTEXT';
        }
        $meetingMatches = $meetingValid && $m['id'] === $target['meeting_id']
            && $m['starts_on'] === $target['meeting_start'] && $m['ends_on'] === $target['meeting_end'];
        if ($meetingValid && ! $meetingMatches) {
            $reasons[] = 'MEETING_TARGET_MISMATCH';
        }
        $playerStatus = match (true) {
            $target['player_id'] === null => 'MISSING_FIXED_PLAYER_ID',
            ($e['player_id'] ?? null) === null => 'MISSING_SAVED_PLAYER_ID',
            $e['player_id'] !== $target['player_id'] => 'PLAYER_ID_MISMATCH',
            default => 'MATCH',
        };
        if ($playerStatus !== 'MATCH') {
            $reasons[] = $playerStatus;
        }
        $external = $e['external_player_id'] ?? null;
        $validExternal = is_string($external) && preg_match('/\A[0-9]{6}\z/', $external) === 1;
        if (! $validExternal) {
            $reasons[] = $external === null ? 'MISSING_EXTERNAL_PLAYER_ID' : 'INVALID_EXTERNAL_PLAYER_ID';
        }
        $class = Classification::raceClass($r['race_type'] ?? null);
        if ($class === 'UNKNOWN') {
            $reasons[] = 'UNKNOWN_RACE_CLASS';
        }
        $scope = $associated && $dateMatches && $sourceMatches && $meetingMatches;
        $context = ['source' => $r['source'] ?? null, 'external_player_id' => $external,
            'race_id' => $r['id'] ?? null, 'entry_id' => $e['id'] ?? null, 'bike' => $e['bike_number'] ?? null,
            'race_date' => $r['race_date'] ?? null,
            'meeting' => ['meeting_id' => $m['id'] ?? null, 'starts_on' => $m['starts_on'] ?? null, 'ends_on' => $m['ends_on'] ?? null],
            'race_type' => $r['race_type'] ?? null, 'observed_at' => null,
            'source_record_id' => implode(';', array_map(fn ($alias) => Contract::TABLES[$alias].':'.($row[$alias]['id'] ?? 'MISSING'), array_keys(Contract::TABLES)))];

        return ['target' => $target, 'context' => $context, 'race_class' => $class, 'player_id_status' => $playerStatus,
            'checks' => ['db_matched' => $dbMatched, 'valid_external_id' => $validExternal,
                'identity_matched' => $scope && $validExternal && $playerStatus === 'MATCH',
                'meeting_matched' => $scope, 'class_known' => $scope && $class !== 'UNKNOWN'],
            'timing' => ['entry_fetched_at' => $e['fetched_at'] ?? null, 'field_observation_time_verified' => false,
                'historical_as_of_available' => false, 'meaning' => 'CURRENT_SAVED_RACECARD_RECONSTRUCTION'],
            'reasons' => $reasons];
    }
}
