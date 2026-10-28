<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture\Observation;

use App\Domain\Keirin\Scraping\PrestartCapture\Operational\Contract;

final class RosterDifferenceEvaluator
{
    public function roster(array $baseline, array $actual): array
    {
        $map = static function (array $rows): array {
            $result = [];
            foreach ($rows as $row) {
                $result[$row['bike_number']] = $row['external_player_id'];
            }
            ksort($result);

            return $result;
        };

        $frames = $unknown = [];
        $byBike = array_column($actual, null, 'bike_number');
        foreach ($baseline as $row) {
            $other = $byBike[$row['bike_number']] ?? null;
            if ($other === null || $other['external_player_id'] !== $row['external_player_id']) {
                continue;
            }
            if ($row['frame_number'] === null || $other['frame_number'] === null) {
                $unknown[] = $row['bike_number'];
            } elseif ($row['frame_number'] !== $other['frame_number']) {
                $frames[] = ['bike_number' => $row['bike_number'], 'before' => $row['frame_number'], 'after' => $other['frame_number']];
            }
        }
        $sameIdentity = $map($baseline) === $map($actual);

        return ['status' => $sameIdentity && $frames === [] ? 'ROSTER_UNCHANGED' : 'ROSTER_CHANGED',
            'entrant_identity' => $sameIdentity ? 'BIKE_REGISTRATION_SET_MATCH' : 'BIKE_REGISTRATION_SET_CHANGED',
            'frame_changes' => $frames, 'unknown_frames' => $unknown,
            'frame_comparison' => $unknown === [] ? 'ESTABLISHED' : 'NOT_ESTABLISHED',
            'baseline' => $baseline, 'observed' => $actual, 'official_change_reason' => 'UNKNOWN'];
    }

    public function compare(array $before, array $after, bool $rawChanged): array
    {
        $changes = $checks = [];
        foreach (['race_attributes', 'entry_attributes'] as $section) {
            $left = $before[$section];
            $right = $after[$section];
            if ($section === 'race_attributes') {
                $left = ['race' => $left];
                $right = ['race' => $right];
            }
            foreach (array_unique([...array_keys($left), ...array_keys($right)]) as $identity) {
                foreach (array_unique([...array_keys($left[$identity] ?? []), ...array_keys($right[$identity] ?? [])]) as $field) {
                    $a = $left[$identity][$field] ?? ['present' => false];
                    $b = $right[$identity][$field] ?? ['present' => false];
                    $checks[] = ['section' => $section, 'identity' => $identity, 'field' => $field,
                        'equal' => Contract::digest($a) === Contract::digest($b)];
                    if (Contract::digest($a) !== Contract::digest($b)) {
                        $changes[] = ['section' => $section, 'identity' => $identity, 'field' => $field,
                            'difference' => ($a['present'] !== $b['present']) ? 'PRESENCE_CHANGED'
                                : (($a['type'] ?? null) !== ($b['type'] ?? null) ? 'TYPE_OR_NULL_CHANGED' : 'VALUE_CHANGED'),
                            'before' => $a, 'after' => $b];
                    }
                }
            }
        }

        return ['roster' => $this->roster($before['entries'], $after['entries']), 'field_checks' => $checks, 'attribute_changes' => $changes,
            'raw_changed' => $rawChanged, 'classification' => $changes !== [] ? 'SEMANTIC_ATTRIBUTE_CHANGE'
                : ($rawChanged ? 'HTML_ONLY_FOR_PROJECTED_ATTRIBUTES' : 'IDENTICAL'), 'official_change_reason' => 'UNKNOWN'];
    }
}
