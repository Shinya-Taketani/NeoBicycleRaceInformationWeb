<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\SavedWeather;

use App\Domain\Keirin\Statistics\Services\SavedResultWeatherProjectionService;
use RuntimeException;

final class Store
{
    public static function input(string $path, string $sha): array
    {
        if (Files::seal($path)['sha256'] !== $sha) {
            throw new RuntimeException('INPUT_MANIFEST_SHA_MISMATCH');
        }
        $m = Files::read($path);
        if (! isset($m['code']) || $m['code'] !== Files::code()) {
            throw new RuntimeException('CURRENT_EXECUTION_CODE_SEAL_MISMATCH');
        }
        if ($m['contract'] !== Files::VERSION.'-INPUT' || file_exists(dirname($path).'/FAILED.json')
            || Files::read(dirname($path).'/COMPLETE.json')['manifest'] !== Files::seal($path)) {
            throw new RuntimeException('INPUT_NOT_COMPLETE');
        }
        foreach (array_merge($m['files'], $m['code'] ?? [], [$m['sqlite'], $m['parent'], $m['original_sqlite']]) as $ref) {
            Files::verify($ref);
        }
        if (isset($m['historical_input'])) {
            Files::verify($m['historical_input']);
            $lineage = Files::read($m['files']['offline-derivation.json']['path']);
            foreach ($lineage['data_seals'] as $ref) {
                Files::verify($ref);
            }
        }

        return $m;
    }

    public function verify(string $root, string $sha): array
    {
        $expected = ['race-coverage.jsonl', 'candidate-inventory.jsonl', 'projections.jsonl', 'coverage.json', 'coverage.csv', 'manifest.json', 'COMPLETE.json'];
        $actual = array_values(array_diff(scandir($root), ['.', '..']));
        sort($actual);
        sort($expected);
        if ($actual !== $expected || Files::seal($root.'/manifest.json')['sha256'] !== $sha) {
            throw new RuntimeException('BATCH_TERMINAL_OR_FILE_SET_INVALID');
        }
        $manifest = Files::read($root.'/manifest.json');
        if ($manifest['contract'] !== Files::VERSION || Files::read($root.'/COMPLETE.json')['manifest'] !== Files::seal($root.'/manifest.json')) {
            throw new RuntimeException('BATCH_MANIFEST_MISMATCH');
        }
        foreach ($manifest['files'] as $ref) {
            Files::verify($ref);
        }
        $input = self::input($manifest['input']['path'], $manifest['input']['sha256']);
        $saved = Files::rows($root.'/candidate-inventory.jsonl');
        $projections = Files::rows($root.'/projections.jsonl');
        $races = Files::rows($root.'/race-coverage.jsonl');
        $n = $p = $r = 0;
        foreach (Files::rows($input['files']['candidates.jsonl']['path']) as $group) {
            $race = $races->current();
            if (($race['race_id'] ?? null) !== $group['race_id']) {
                throw new RuntimeException('RACE_COVERAGE_SEQUENCE_MISMATCH');
            }
            $r++;
            $races->next();
            foreach ($group['candidates'] as $candidate) {
                $row = $saved->current();
                if (($row['candidate'] ?? null) !== $candidate) {
                    throw new RuntimeException('CANDIDATE_SEQUENCE_MISMATCH');
                }
                if ($row['status'] === 'PROJECTED') {
                    $projection = $projections->current();
                    if (($projection['candidate_key'] ?? null) !== $candidate['key']) {
                        throw new RuntimeException('PROJECTION_SEQUENCE_MISMATCH');
                    }
                    self::binding($candidate, $projection['projection']);
                    $projections->next();
                    $p++;
                } elseif ($row['status'] !== 'EXCLUDED' || $row['reason'] !== $candidate['reason'] || $candidate['reason'] === null) {
                    throw new RuntimeException('INVALID_COMPLETE_CLASSIFICATION');
                }
                $n++;
                $saved->next();
            }
        }
        if ($saved->valid() || $projections->valid() || $races->valid()) {
            throw new RuntimeException('EXTRA_BATCH_ROWS');
        }
        $coverage = Files::read($root.'/coverage.json');
        if ($r !== $input['races'] || $r !== $coverage['races'] || $n !== $coverage['candidates'] || $p !== $coverage['projected']) {
            throw new RuntimeException('COVERAGE_DENOMINATOR_MISMATCH');
        }

        return ['status' => 'VERIFIED', 'races' => $r, 'candidates' => $n, 'projections' => $p];
    }

    public static function binding(array $candidate, array $projection): void
    {
        if ($projection['version'] !== SavedResultWeatherProjectionService::VERSION
            || $projection['race_id'] !== $candidate['race_id'] || $projection['identity_checked_entry_id'] !== $candidate['entry_id']
            || $projection['evidence']['import_id'] !== $candidate['import_id'] || $projection['evidence']['fetch_log_id'] !== $candidate['fetch_log_id']) {
            throw new RuntimeException('FIXED_CANDIDATE_BINDING_MISMATCH');
        }
    }
}
