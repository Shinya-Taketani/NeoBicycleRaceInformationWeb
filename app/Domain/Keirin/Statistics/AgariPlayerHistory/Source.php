<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\AgariPlayerHistory;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Contract as Relative;
use Generator;
use RuntimeException;

final class Source
{
    // Tests supply independently sealed fixtures; the command always uses the reviewed identities.
    public function __construct(private readonly array $inputSeal = Contract::INPUT_SEAL, private readonly array $resultSeal = Contract::RESULT_SEAL) {}

    public function verify(string $input, string $result): array
    {
        $in = Artifacts::input($input);
        Files::verify($result.'/manifest.json', Files::json($result.'/COMPLETE.json'));
        $out = Files::json($result.'/manifest.json');
        foreach ([$in, $out] as $manifest) {
            foreach (Relative::DISCLOSURE as $key => $value) {
                if (! array_key_exists($key, $manifest) || $manifest[$key] !== $value) {
                    throw new RuntimeException('Source disclosure mismatch: '.$key);
                }
            }
            if (! is_array($manifest['code'] ?? null) || $manifest['code'] === []) {
                throw new RuntimeException('Missing source code identity.');
            }
            foreach ($manifest['code'] as $path => $hash) {
                if (! is_string($path) || ! is_string($hash) || ! preg_match('/\A[a-f0-9]{64}\z/', $hash)) {
                    throw new RuntimeException('Invalid source code identity.');
                }
            }
        }
        $names = array_keys($out['files'] ?? []);
        sort($names);
        if (($out['version'] ?? null) !== Relative::VERSION || ($out['kind'] ?? null) !== 'RESULT'
            || $names !== ['details.jsonl', 'summary.csv', 'summary.json']
            || ($out['master_version'] ?? null) !== 'v2' || ! preg_match('/\A[a-f0-9]{64}\z/', $out['master_sha256'] ?? '')
            || $in['race_count'] < 0 || $in['result_count'] < 0) {
            throw new RuntimeException('Invalid RESULT manifest.');
        }
        Files::verify($input.'/manifest.json', $out['input_manifest'] ?? []);
        Files::same($this->inputSeal, $in['files']['races.jsonl'], 'pinned input');
        Files::same($this->resultSeal, $out['files']['details.jsonl'], 'pinned result');
        foreach ($out['files'] as $name => $seal) {
            Files::verify($result.'/'.$name, $seal);
        }

        return ['input_identity' => Files::identity($input.'/manifest.json'), 'result_identity' => Files::identity($result.'/manifest.json'),
            'input' => $in, 'result' => $out];
    }

    public function rows(string $input, string $result, array $source): Generator
    {
        $details = Artifacts::lines($result.'/details.jsonl');
        $details->rewind();
        $last = $races = $entries = 0;
        foreach (Artifacts::lines($input.'/races.jsonl') as $race) {
            Relative::race($race, $source['input']['from'], $source['input']['to'], $last);
            $last = $race['race_id'];
            if (! $details->valid()) {
                throw new RuntimeException('Missing RESULT race.');
            }
            $detail = $details->current();
            foreach (['race_id', 'race_date', 'race_status'] as $key) {
                if (($detail[$key] ?? null) !== $race[$key]) {
                    throw new RuntimeException('Input/RESULT race mismatch: '.$key);
                }
            }
            if (($detail['calculation_version'] ?? null) !== Relative::VERSION
                || ($detail['measurement_definition_id'] ?? null) !== Relative::DEFINITION
                || ! in_array($detail['comparison_completeness'] ?? null, ['COMPLETE', 'PARTIAL'], true)
                || ! is_array($detail['results'] ?? null) || ! array_is_list($detail['results'])
                || count($detail['results']) !== count($race['results']) || ! is_array($detail['classification'] ?? null)) {
                throw new RuntimeException('Invalid RESULT row schema.');
            }
            foreach (Relative::DISCLOSURE as $key => $value) {
                if (! array_key_exists($key, $detail) || $detail[$key] !== $value) {
                    throw new RuntimeException('RESULT row disclosure mismatch.');
                }
            }
            $c = $detail['classification'];
            if (($c['year'] ?? null) !== (int) substr($race['race_date'], 0, 4)
                || ! array_key_exists('meeting_id', $c) || $c['meeting_id'] !== $race['context']['meeting_id']
                || ! array_key_exists('track_code', $c) || $c['track_code'] !== $race['context']['track_code']
                || ! in_array($c['race_class'] ?? null, ['S_CLASS', 'A1_A2', 'A_CHALLENGE', 'UNKNOWN'], true)
                || ! in_array($c['meeting_grade'] ?? null, ['GP', 'G1', 'G2', 'G3', 'F1', 'F2', 'UNKNOWN'], true)) {
                throw new RuntimeException('Invalid RESULT classification.');
            }
            $byId = [];
            $bikes = [];
            foreach ($race['results'] as $row) {
                if (isset($byId[$row['id']]) || isset($bikes[$row['bike_number']]) || $row['bike_number'] < 1 || $row['bike_number'] > 9) {
                    throw new RuntimeException('Duplicate/invalid result identity.');
                }
                $byId[$row['id']] = $row;
                $bikes[$row['bike_number']] = true;
            }
            foreach ($detail['results'] as $row) {
                $original = $byId[$row['result_id'] ?? 0] ?? null;
                if ($original === null) {
                    throw new RuntimeException('RESULT identity missing or duplicated.');
                }
                unset($byId[$row['result_id']]);
                $expected = ['bike_number' => $original['bike_number'], 'race_result_import_id' => $original['race_result_import_id'],
                    'observation_id' => $original['observation']['id'] ?? null,
                    'external_player_id' => $original['observation']['external_player_id'] ?? null,
                    'current_player_id' => $original['player_id'] ?? null, 'current_race_entry_id' => $original['race_entry_id'] ?? null,
                    'observation_player_id' => $original['observation']['player_id'] ?? null,
                    'observation_race_entry_id' => $original['observation']['race_entry_id'] ?? null,
                    'result_status' => $original['result_status'], 'agari_status' => $original['agari_status']];
                foreach ($expected as $key => $value) {
                    if (! array_key_exists($key, $row) || $row[$key] !== $value) {
                        throw new RuntimeException('Input/RESULT entry mismatch: '.$key);
                    }
                }
                if (! is_string($row['relative_status'] ?? null)) {
                    throw new RuntimeException('Missing relative status.');
                }
                $n = $row['percentile_numerator'] ?? null;
                $d = $row['percentile_denominator'] ?? null;
                if ($row['relative_status'] === 'CALCULATED' ? (! is_int($n) || ! is_int($d) || $d <= 0 || $n < 0 || $n > $d) : ($n !== null || $d !== null)) {
                    throw new RuntimeException('Invalid exact percentile.');
                }
            }
            $races++;
            $entries += count($detail['results']);
            yield [$race, $detail];
            $details->next();
        }
        if ($details->valid() || $races !== $source['input']['race_count'] || $entries !== $source['input']['result_count']) {
            throw new RuntimeException('Input/RESULT counts or race sets differ.');
        }
    }
}
