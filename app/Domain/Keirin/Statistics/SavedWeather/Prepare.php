<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\SavedWeather;

use PDO;
use RuntimeException;
use Throwable;

final class Prepare
{
    public function __construct(private readonly Snapshot $snapshot, private readonly MetadataExporter $exporter) {}

    public function run(string $parentManifest, string $sha, string $snapshotRoot, string $oldRawRoot, string $rawRoot, string $output, bool $supplement = false): array
    {
        $parentSeal = Files::seal($parentManifest);
        $code = Files::code();
        if ($parentSeal['sha256'] !== $sha) {
            throw new RuntimeException('PARENT_MANIFEST_SHA_MISMATCH');
        }
        $parent = Files::read($parentManifest);
        $complete = Files::read(dirname($parentManifest).'/COMPLETE.json');
        if (($complete['manifest']['sha256'] ?? null) !== $sha) {
            throw new RuntimeException('PARENT_COMPLETE_MISMATCH');
        }
        $source = $parent['working_sqlite'];
        Files::verify($source);
        foreach ([$rawRoot, $oldRawRoot] as $root) {
            if (realpath($root) !== $root || ! is_dir($root) || is_link($root)) {
                throw new RuntimeException('CANONICAL_RAW_ROOT_REQUIRED');
            }
        }
        Files::directory($output);
        $streams = [];
        try {
            $db = Files::sqlite($source['path']);
            $audit = $this->snapshot->scope($db, $snapshotRoot, $output.'/scope.jsonl');
            Files::write($output.'/source-schema-audit.json', $audit);
            $working = $source;
            $extraction = ['connections' => 0, 'writes' => 0, 'explicit_supplement' => $supplement];
            if ($supplement) {
                $target = $output.'/input.sqlite';
                $h = Files::create($target);
                $in = fopen($source['path'], 'rb');
                if (stream_copy_to_stream($in, $h) !== $source['bytes']) {
                    throw new RuntimeException('SQLITE_COPY_FAILED');
                }
                fclose($in);
                fclose($h);
                $write = new PDO('sqlite:'.$target, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $write->exec('PRAGMA foreign_keys=OFF');
                foreach (['races', 'entries', 'results', 'imports', 'logs', 'settings', 'query'] as $name) {
                    $streams[$name] = Files::create($output.'/export-'.$name.'.jsonl');
                }
                $scopeSeal = Files::seal($output.'/scope.jsonl');
                $extraction = $this->exporter->export($output.'/scope.jsonl', $db, function ($name, $row) use ($streams): void {
                    Files::append($streams[$name], $row);
                });
                foreach ($streams as $h) {
                    fclose($h);
                }
                $streams = [];
                Files::verify($scopeSeal);
                $side = Files::create($output.'/supplement-sidecar.jsonl');
                $write->beginTransaction();
                try {
                    $this->links($write, $output, $side);
                    $allowed = [];
                    foreach ($write->query('SELECT DISTINCT race_result_import_id FROM race_results WHERE race_result_import_id IS NOT NULL') as $row) {
                        $allowed[$row['race_result_import_id']] = true;
                    }
                    $logIds = [];
                    foreach (Files::rows($output.'/export-imports.jsonl') as $row) {
                        if (isset($allowed[$row['id']])) {
                            $logIds[$row['scraping_fetch_log_id']] = true;
                        }
                    }
                    foreach (['logs' => 'scraping_fetch_logs', 'imports' => 'race_result_imports'] as $name => $table) {
                        foreach (Files::rows($output.'/export-'.$name.'.jsonl') as $row) {
                            if (! isset(($name === 'logs' ? $logIds : $allowed)[$row['id']])) {
                                continue;
                            }
                            // Batch IDs are not fetched and are not identity evidence in Reader.
                            unset($row['batch_run_id'], $row['batch_run_item_id'], $row['error_message']);
                            self::insertMissing($write, $table, $row, $side);
                        }
                    }
                    $write->commit();
                } catch (Throwable $e) {
                    $write->rollBack();
                    throw $e;
                } finally {
                    fclose($side);
                }
                $write = null;
                $working = Files::seal($target);
            }
            $candidates = Files::create($output.'/candidates.jsonl');
            $bindingFailures = [];
            if (is_file($output.'/bindings.jsonl')) {
                foreach (Files::rows($output.'/bindings.jsonl') as $binding) {
                    if ($binding['binding']['state'] === 'SOURCE_ROW_DRIFT') {
                        $bindingFailures[$binding['race_id']] = 'SOURCE_ROW_DRIFT';
                    }
                }
            }
            try {
                foreach ((new Repository)->inventory(Files::sqlite($working['path']), $db, $output.'/scope.jsonl', ['existing' => $oldRawRoot, 'supplement' => $rawRoot], $bindingFailures) as $group) {
                    Files::append($candidates, $group);
                }
            } finally {
                fclose($candidates);
            }
            $files = [];
            foreach (glob($output.'/*.jsonl') as $file) {
                $files[basename($file)] = Files::seal($file);
            }
            $files['source-schema-audit.json'] = Files::seal($output.'/source-schema-audit.json');
            Files::verify($source);
            Files::verify($parentSeal);
            foreach ($code as $ref) {
                Files::verify($ref);
            }
            $manifest = ['contract' => Files::VERSION.'-INPUT', 'parent' => $parentSeal, 'original_sqlite' => $source,
                'sqlite' => $working, 'scope' => $audit['scope'], 'races' => $audit['races'], 'files' => $files,
                'raw_roots' => ['existing' => $oldRawRoot, 'supplement' => $rawRoot], 'extraction' => $extraction, 'code' => $code,
                'historical_as_of_available' => false, 'prediction_use' => 'NOT_AUTHORIZED'];
            Files::write($output.'/manifest.json', $manifest);
            Files::write($output.'/COMPLETE.json', ['manifest' => Files::seal($output.'/manifest.json')]);

            return $manifest;
        } catch (Throwable $e) {
            foreach ($streams as $h) {
                fclose($h);
            }
            Files::write($output.'/FAILED.json', ['exception' => $e::class, 'reason' => 'PREPARATION_FAILED', 'exit_code' => 1]);
            throw $e;
        }
    }

    public static function insertMissing(PDO $db, string $table, array $row, $side): void
    {
        if (! in_array($table, ['race_result_imports', 'scraping_fetch_logs'], true)) {
            throw new RuntimeException('SUPPLEMENT_TABLE_FORBIDDEN');
        }
        $old = Files::select($db, "SELECT * FROM $table WHERE id=?", [$row['id']]);
        if ($old !== []) {
            // W45 paths were adapted at original import time; retain the sealed original rows.
            $compare = $row;
            unset($compare['raw_file_path']);
            if (! Snapshot::matches($compare, $old[0])) {
                throw new RuntimeException('EXISTING_ID_CONFLICT');
            }

            return;
        }
        $columns = array_keys($row);
        $allowed = array_column(Files::select($db, 'PRAGMA table_info('.$table.')'), 'name');
        if (array_diff($columns, $allowed) !== []) {
            throw new RuntimeException('UNKNOWN_SUPPLEMENT_COLUMN');
        }
        $sql = "INSERT INTO $table (".implode(',', array_map(fn ($x) => '"'.$x.'"', $columns)).') VALUES ('.implode(',', array_fill(0, count($row), '?')).')';
        $db->prepare($sql)->execute(array_values($row));
        Files::append($side, ['table' => $table, 'id' => $row['id'], 'old' => 'ROW_NOT_EXTRACTED', 'new' => $row,
            'origin' => 'FIXED_SCOPE_READ_ONLY_EXPORT', 'request_identifiers_omitted' => true]);
    }

    private function links(PDO $db, string $root, $side): void
    {
        // Keep only byte offsets in memory; source payloads remain on disk.
        $indexes = $handles = [];
        foreach (['races', 'entries', 'results'] as $table) {
            $handles[$table] = fopen($root.'/export-'.$table.'.jsonl', 'rb');
            while (! feof($handles[$table])) {
                $offset = ftell($handles[$table]);
                $line = fgets($handles[$table]);
                if ($line === false) {
                    break;
                }
                $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                $indexes[$table][$table === 'races' ? $row['id'] : $row['race_id']][] = $offset;
            }
        }
        $bindings = Files::create($root.'/bindings.jsonl');
        try {
            foreach (Files::rows($root.'/scope.jsonl') as $scope) {
                if (count(array_filter($scope['results'], fn ($v) => ! isset($v['race_result_import_id']))) === 0) {
                    continue;
                }
                $current = ['races' => [], 'entries' => [], 'results' => []];
                foreach (array_keys($current) as $table) {
                    foreach ($indexes[$table][$scope['race']['id']] ?? [] as $offset) {
                        fseek($handles[$table], $offset);
                        $current[$table][] = json_decode(fgets($handles[$table]), true, flags: JSON_THROW_ON_ERROR);
                    }
                }
                $ok = count($current['races']) === 1 && Snapshot::matches($scope['race'], $current['races'][0])
                    && count($current['entries']) === count($scope['entries']) && count($current['results']) === count($scope['results']);
                foreach (['entries', 'results'] as $table) {
                    foreach ($scope[$table] as $old) {
                        $match = array_values(array_filter($current[$table], fn ($x) => $x['id'] === $old['id']));
                        $ok = $ok && count($match) === 1 && Snapshot::matches($old, $match[0], $table === 'results' ? ['race_result_import_id'] : []);
                    }
                }
                foreach ($scope['results'] as $old) {
                    if (isset($old['race_result_import_id'])) {
                        continue;
                    }
                    $match = array_values(array_filter($current['results'], fn ($x) => $x['id'] === $old['id']));
                    $binding = Snapshot::binding($old, $ok ? ($match[0] ?? []) : []);
                    Files::append($bindings, ['race_id' => $scope['race']['id'], 'result_id' => $old['id'], 'binding' => $binding]);
                    Files::append($side, ['table' => 'race_results', 'id' => $old['id'], 'column' => 'race_result_import_id', 'binding' => $binding]);
                    if ($binding['import_id'] !== null) {
                        $db->prepare('UPDATE race_results SET race_result_import_id=? WHERE id=? AND race_result_import_id IS NULL')->execute([$binding['import_id'], $old['id']]);
                    }
                }
            }
        } finally {
            fclose($bindings);
            foreach ($handles as $h) {
                fclose($h);
            }
        }
    }
}
