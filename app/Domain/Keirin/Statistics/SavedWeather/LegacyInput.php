<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\SavedWeather;

use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;

class LegacyInput
{
    public const ROOT = '/home/shinya/neo-keirin-artifacts/stat21-saved-weather-batch-01/run-20261031-Ry7iILJL';

    public const SHA256 = '4199553e3cc591e8e6ad51acd86fb2d2117602e2ec518acf1dbf413c84a91dff';

    public const COMMIT = 'efa3cc2212382457776f480bba70ec0c84d7fcc5';

    public const SNAPSHOT = '/home/shinya/neo-keirin-artifacts/stat-existing-data-completion-01/snapshot-20261029-225509-adc95366';

    public function load(): array
    {
        $ref = ['path' => self::ROOT.'/prepared-accepted/manifest.json', 'bytes' => 20285, 'sha256' => self::SHA256];
        Files::verify($ref);
        $m = Files::read($ref['path']);
        if ($m['contract'] !== 'STAT-21-SAVED-WEATHER-BATCH-v1-INPUT'
            || Files::read(dirname($ref['path']).'/COMPLETE.json')['manifest'] !== $ref) {
            throw new RuntimeException('LEGACY_INPUT_TERMINAL_MISMATCH');
        }
        $data = array_merge(array_values($m['files']), [$m['sqlite'], $m['parent'], $m['original_sqlite'], $ref,
            Files::seal(dirname($ref['path']).'/COMPLETE.json')]);
        foreach ($data as $seal) {
            Files::verify($seal);
        }
        $code = [];
        foreach ($m['code'] as $seal) {
            if (! str_starts_with($seal['path'], base_path('app/'))) {
                throw new RuntimeException('LEGACY_CODE_PATH_INVALID');
            }
            $relative = substr($seal['path'], strlen(base_path()) + 1);
            $p = new Process(['git', 'show', self::COMMIT.':'.$relative], base_path());
            $p->mustRun();
            $bytes = $p->getOutput();
            if (strlen($bytes) !== $seal['bytes'] || hash('sha256', $bytes) !== $seal['sha256']) {
                throw new RuntimeException('LEGACY_GIT_CODE_SEAL_MISMATCH');
            }
            $p = new Process(['git', 'rev-parse', self::COMMIT.':'.$relative], base_path());
            $p->mustRun();
            $code[] = ['repository_path' => $relative, 'blob' => trim($p->getOutput()), 'bytes' => strlen($bytes), 'sha256' => $seal['sha256']];
        }
        $snapshot = Files::read(self::SNAPSHOT.'/manifest.json');
        if (Files::read(self::SNAPSHOT.'/COMPLETE.json')['manifest_sha256'] !== Files::seal(self::SNAPSHOT.'/manifest.json')['sha256']) {
            throw new RuntimeException('LEGACY_SNAPSHOT_MANIFEST_MISMATCH');
        }
        foreach ($snapshot['files'] as $seal) {
            $data[] = ['path' => self::SNAPSHOT.'/'.$seal['path'], 'bytes' => $seal['bytes'], 'sha256' => $seal['sha256']];
        }
        $data[] = Files::seal(self::SNAPSHOT.'/manifest.json');
        $data[] = Files::seal(self::SNAPSHOT.'/COMPLETE.json');
        foreach ($data as $seal) {
            Files::verify($seal);
        }
        $db = Files::sqlite($m['sqlite']['path']);
        $parent = Files::sqlite($m['original_sqlite']['path']);
        $rows = $this->rows($db, $parent, $m);

        return ['input' => $m, 'reference' => $ref, 'snapshot' => self::SNAPSHOT, 'data_seals' => $data,
            'historical_code' => ['commit' => self::COMMIT, 'files' => $code], 'row_audit' => $rows];
    }

    private function rows(PDO $db, PDO $parent, array $m): array
    {
        $counts = [];
        $metadata = ['race_result_imports', 'scraping_fetch_logs'];
        foreach ($parent->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
            $name = $table['name'];
            $a = $parent->query('SELECT * FROM "'.$name.'" ORDER BY rowid');
            $n = 0;
            if (in_array($name, $metadata, true)) {
                while ($row = $a->fetch(PDO::FETCH_ASSOC)) {
                    if (Files::select($db, 'SELECT * FROM "'.$name.'" WHERE id=?', [$row['id']]) !== [$row]) {
                        throw new RuntimeException('LEGACY_PARENT_METADATA_CHANGED');
                    }
                    $n++;
                }
            } else {
                $b = $db->query('SELECT * FROM "'.$name.'" ORDER BY rowid');
                while ($row = $a->fetch(PDO::FETCH_ASSOC)) {
                    if ($row !== $b->fetch(PDO::FETCH_ASSOC)) {
                        throw new RuntimeException('LEGACY_PARENT_BUSINESS_ROW_CHANGED');
                    }
                    $n++;
                }
                if ($b->fetch(PDO::FETCH_ASSOC) !== false) {
                    throw new RuntimeException('LEGACY_UNEXPECTED_BUSINESS_ROW');
                }
            }
            $counts[$name] = $n;
        }
        $added = [];
        foreach (Files::rows($m['files']['supplement-sidecar.jsonl']['path']) as $record) {
            $table = $record['table'];
            if (! in_array($table, $metadata, true) || $record['old'] !== 'ROW_NOT_EXTRACTED'
                || isset($added[$table][$record['id']])) {
                throw new RuntimeException('LEGACY_NOT_ALL_A_METADATA_ONLY');
            }
            $row = Files::select($db, 'SELECT * FROM '.$table.' WHERE id=?', [$record['id']]);
            if (count($row) !== 1 || ! Snapshot::matches($record['new'], $row[0])
                || Files::select($parent, 'SELECT id FROM '.$table.' WHERE id=?', [$record['id']]) !== []) {
                throw new RuntimeException('LEGACY_SIDECAR_ROW_MISMATCH');
            }
            $added[$table][$record['id']] = hash('sha256', Files::json($record['new']));
        }
        // Retain only ID/digest pairs, not the complete exported payloads.
        foreach (['logs' => 'scraping_fetch_logs', 'imports' => 'race_result_imports'] as $export => $table) {
            $n = 0;
            foreach (Files::rows($m['files']['export-'.$export.'.jsonl']['path']) as $row) {
                unset($row['batch_run_id'], $row['batch_run_item_id'], $row['error_message']);
                if (! isset($added[$table][$row['id']]) || $added[$table][$row['id']] !== hash('sha256', Files::json($row))) {
                    throw new RuntimeException('LEGACY_EXPORT_SIDECAR_MISMATCH');
                }
                unset($added[$table][$row['id']]);
                $n++;
            }
            if ($added[$table] !== [] || $n !== 25908
                || (int) $db->query('SELECT count(*) FROM '.$table)->fetchColumn() !== $counts[$table] + $n) {
                throw new RuntimeException('LEGACY_METADATA_SET_MISMATCH');
            }
        }
        if ((int) $db->query('SELECT count(*) FROM race_results WHERE race_result_import_id IS NULL')->fetchColumn() !== 0
            || Files::seal($m['files']['bindings.jsonl']['path'])['bytes'] !== 0) {
            throw new RuntimeException('LEGACY_B_C_MIGRATION_NOT_SUPPORTED');
        }

        return ['old_rows' => $counts, 'added_imports' => 25908, 'added_logs' => 25908, 'B_C_links' => 0];
    }
}
