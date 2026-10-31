<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\SavedWeather;

use Generator;
use PDO;
use RuntimeException;

final class Repository
{
    public function inventory(PDO $db, PDO $original, string $scopeFile, array $roots, array $bindingFailures = []): Generator
    {
        foreach (Files::rows($scopeFile) as $scope) {
            $race = $scope['race'];
            $results = Files::select($db, 'SELECT * FROM race_results WHERE race_id=? ORDER BY bike_number,id', [$race['id']]);
            $imports = Files::select($db, 'SELECT * FROM race_result_imports WHERE race_id=? ORDER BY id', [$race['id']]);
            $refs = array_values(array_unique(array_filter(array_column($results, 'race_result_import_id'), fn ($x) => $x !== null)));
            sort($refs, SORT_NUMERIC);
            $nulls = count(array_filter($results, fn ($v) => $v['race_result_import_id'] === null));
            $ids = array_unique(array_merge($refs, array_column($imports, 'id')));
            sort($ids, SORT_NUMERIC);
            if ($ids === []) {
                $ids = [null];
            }
            $candidates = [];
            foreach ($ids as $id) {
                $import = array_values(array_filter($imports, fn ($x) => $x['id'] === $id))[0] ?? null;
                $log = $import === null ? null : (Files::select($db, 'SELECT * FROM scraping_fetch_logs WHERE id=?', [$import['scraping_fetch_log_id']])[0] ?? null);
                $selected = array_values(array_filter($results, fn ($x) => $id !== null && $x['race_result_import_id'] === $id));
                $anchor = $selected[0] ?? null;
                $entries = $anchor === null ? [] : Files::select($db, 'SELECT * FROM race_entries WHERE race_id=? AND bike_number=? ORDER BY id', [$race['id'], $anchor['bike_number']]);
                $old = $id !== null && Files::select($original, 'SELECT id FROM race_result_imports WHERE id=?', [$id]) !== [];
                $root = $roots[$old ? 'existing' : 'supplement'];
                $relative = $import['raw_file_path'] ?? null;
                $path = null;
                if ($relative !== null) {
                    if (! is_string($relative) || str_starts_with($relative, '/') || preg_match('~(?:^|/)(?:\.|\.\.)(?:/|$)|//~', $relative)) {
                        throw new RuntimeException('INVALID_RAW_RELATIVE_PATH');
                    }
                    $path = $root.'/'.$relative;
                }
                $reason = match (true) {
                    isset($bindingFailures[$race['id']]) => $bindingFailures[$race['id']],
                    $race['result_status'] === 'CANCELLED' => 'CANCELLED_NO_CURRENT_RESULT_ANCHOR',
                    $results === [] => 'NO_CURRENT_RESULT',
                    ! in_array($race['result_status'], ['CONFIRMED', 'CORRECTED'], true) => 'RACE_NOT_CONFIRMED',
                    count($refs) > 1 => 'AMBIGUOUS_CURRENT_IMPORT',
                    $id === null => 'NO_IMPORT_REFERENCE',
                    ! in_array($id, $refs, true) => 'NOT_REFERENCED_BY_CURRENT_RESULTS',
                    $import === null => 'IMPORT_NOT_EXTRACTED',
                    $log === null => 'FETCH_LOG_NOT_EXTRACTED',
                    $import['import_status'] !== 'SUCCEEDED' => 'IMPORT_NOT_SUCCEEDED',
                    ! in_array($import['requested_result_status'], ['CONFIRMED', 'CORRECTED'], true) => 'IMPORT_NOT_CONFIRMED',
                    count($entries) !== 1 => 'ANCHOR_NOT_UNIQUE_OR_MISSING',
                    $path === null || ! file_exists($path) => 'RAW_NOT_AVAILABLE_AT_PREPARATION',
                    default => null,
                };
                $candidates[] = ['key' => $race['id'].':'.($id ?? 'NO_IMPORT').':'.($log['id'] ?? 'NO_LOG'),
                    'race_id' => $race['id'], 'race_date' => $race['race_date'], 'import_id' => $id, 'fetch_log_id' => $log['id'] ?? null,
                    'result_id' => $anchor['id'] ?? null, 'entry_id' => count($entries) === 1 ? $entries[0]['id'] : null,
                    'current_result_count' => count($results), 'current_import_ids' => $refs, 'null_import_pointers' => $nulls,
                    'existing_w45' => $old, 'reason' => $reason, 'raw_root' => $root,
                    'raw' => $reason === null ? ['path' => $path, 'bytes' => $import['raw_response_size'], 'sha256' => $import['source_hash']] : null];
            }
            yield ['race_id' => $race['id'], 'race_date' => $race['race_date'], 'current_result_count' => count($results),
                'current_import_ids' => $refs, 'null_import_pointers' => $nulls, 'candidates' => $candidates];
        }
    }
}
