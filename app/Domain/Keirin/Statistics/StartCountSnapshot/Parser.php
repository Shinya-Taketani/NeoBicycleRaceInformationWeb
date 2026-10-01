<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\StartCountSnapshot;

use App\Domain\Keirin\Audit\Stat35DataReadiness\Contract as Audit;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;

final class Parser
{
    public function parse(string $html, array $target): array
    {
        Audit::date($target['race']['race_date']);
        $pc = PageJson::extract($html, 'PC0201');
        $pj = PageJson::extract($html, 'PJ0315');
        $context = $pc->C0201data ?? null;
        $summary = $context->C0201racedtl->C0201sensyu ?? null;
        $details = $pj->sensyuTypeInfo ?? null;
        $race = $target['race'];
        $issues = [];
        $observed = ['race_date' => $context->selKaisai ?? null, 'track_code' => $context->selKjyoCd ?? null,
            'race_number' => $context->selRaceNo ?? null];
        if (! $context instanceof \stdClass || (! (is_int($observed['race_date']) || is_string($observed['race_date']))
            || (string) $observed['race_date'] !== str_replace('-', '', $race['race_date']))
            || ! $this->digitsEqual($observed['track_code'], $race['track_code'])
            || ! $this->digitsEqual($observed['race_number'], $race['race_number'])) {
            $issues[] = 'RACE_IDENTITY_MISMATCH';
        }
        if (! is_array($summary) || ! is_array($details)) {
            return ['observed_race' => $observed, 'issues' => [...$issues, 'UNSUPPORTED_SCHEMA'], 'rows' => [],
                'missing_bikes' => array_column($target['entries'], 'bike_number')];
        }
        $pcBikes = $this->counts($summary, 'carNum');
        $pjBikes = $this->counts($details, 'syaban');
        $pcIds = $this->counts($summary, 'numPlayer');
        $pjIds = $this->counts($details, 'sensyuRegistNo');
        $ledger = array_column($target['entries'], null, 'bike_number');
        $pcEntries = [];
        foreach ($summary as $row) {
            if ($row instanceof \stdClass && ($bike = $this->bike($row->carNum ?? null)) !== null) {
                $pcEntries[$bike] = $row;
            }
        }
        $summaryIssues = [];
        foreach ($summary as $i => $s) {
            $bike = $this->bike($s->carNum ?? null);
            if ($bike === null || ! isset($ledger[$bike])) {
                $summaryIssues[] = ['row_index' => $i, 'reason' => 'PC0201_EXTRA_OR_INVALID_ENTRY'];
            }
        }
        $rows = [];
        $seen = [];
        foreach ($details as $i => $row) {
            $bike = $this->bike($row->syaban ?? null);
            $id = $row->sensyuRegistNo ?? null;
            $entry = $ledger[$bike ?? 0] ?? null;
            $identity = $issues;
            if ($bike === null) {
                $identity[] = 'INVALID_BIKE';
            } else {
                $seen[$bike] = true;
            }
            if (! is_string($id) || ! preg_match('/\A[0-9]{6}\z/D', $id)) {
                $identity[] = 'INVALID_EXTERNAL_ID';
            }
            if ($bike !== null && (($pcBikes[(string) $bike] ?? 0) > 1 || ($pjBikes[(string) $bike] ?? 0) > 1)) {
                $identity[] = 'DUPLICATE_BIKE';
            }
            if (is_string($id) && (($pcIds[$id] ?? 0) > 1 || ($pjIds[$id] ?? 0) > 1)) {
                $identity[] = 'DUPLICATE_EXTERNAL_ID';
            }
            if ($entry === null) {
                $identity[] = 'EXTRA_OR_INVALID_ENTRY';
            } elseif ($id !== $entry['external_player_id']) {
                $identity[] = 'LEDGER_IDENTITY_MISMATCH';
            }
            if ($bike === null || ! isset($pcEntries[$bike]) || ($pcEntries[$bike]->numPlayer ?? null) !== $id) {
                $identity[] = 'PC0201_PJ0315_IDENTITY_MISMATCH';
            }
            $field = $this->field($row);
            $signature = hash('sha256', Files::canonical($this->orderedObjects([
                'presence' => $field['presence'], 'type' => $field['type'], 'raw' => $field['raw'],
            ])));
            $rows[] = ['row_index' => $i, 'entry_id' => $entry['id'] ?? null, 'bike_number' => $bike,
                'ledger_external_id' => $entry['external_player_id'] ?? null, 'observed_external_id' => $id, 'observed_bike' => $row->syaban ?? null,
                'pc0201_external_id' => $pcEntries[$bike ?? 0]->numPlayer ?? null,
                'identity_issues' => array_values(array_unique($identity)), 'field' => $field,
                'displayed_start_count' => $identity === [] ? $field['parsed_value'] : null,
                'value_signature' => $signature, 'source_pointer' => 'PJ0315.sensyuTypeInfo['.$i.'].stTori'];
        }

        return ['observed_race' => $observed, 'issues' => $issues, 'summary_issues' => $summaryIssues, 'rows' => $rows,
            'missing_bikes' => array_values(array_diff(array_keys($ledger), array_keys($seen)))];
    }

    public function field(mixed $row): array
    {
        $present = $row instanceof \stdClass && property_exists($row, 'stTori');
        $value = $present ? $row->stTori : null;
        $state = match (true) {
            ! $present => 'MISSING', $value === null => 'NULL', $value === '' => 'EMPTY_STRING',
            $value === '－' => 'MISSING_SYMBOL',
            ! is_int($value) && ! is_string($value) => 'INVALID_FORMAT',
            ! preg_match('/\A[0-9]+\z/D', (string) $value) => 'INVALID_FORMAT',
            strlen(ltrim((string) $value, '0')) > 4 => 'OUT_OF_RANGE',
            default => 'NUMERIC',
        };

        return ['presence' => ! $present ? 'MISSING' : ($value === null ? 'NULL' : 'PRESENT'),
            'raw' => $value, 'type' => ! $present ? 'MISSING' : get_debug_type($value),
            'status' => $state, 'parsed_value' => $state === 'NUMERIC' ? (int) $value : null];
    }

    private function counts(array $rows, string $field): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $v = $row->$field ?? null;
            if (is_int($v) || is_string($v)) {
                $counts[(string) $v] = ($counts[(string) $v] ?? 0) + 1;
            }
        }

        return $counts;
    }

    private function bike(mixed $v): ?int
    {
        return (is_int($v) || is_string($v)) && preg_match('/\A[1-9]\z/D', (string) $v) ? (int) $v : null;
    }

    private function digitsEqual(mixed $a, mixed $b): bool
    {
        return (is_int($a) || is_string($a)) && (is_int($b) || is_string($b))
            && preg_match('/\A[0-9]+\z/D', (string) $a) && preg_match('/\A[0-9]+\z/D', (string) $b)
            && ltrim((string) $a, '0') === ltrim((string) $b, '0');
    }

    private function orderedObjects(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $fields = get_object_vars($value);
            ksort($fields, SORT_STRING);

            return (object) array_map($this->orderedObjects(...), $fields);
        }

        return is_array($value) ? array_map($this->orderedObjects(...), $value) : $value;
    }
}
