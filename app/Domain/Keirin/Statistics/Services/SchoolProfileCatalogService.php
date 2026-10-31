<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Services;

use App\Domain\Keirin\Statistics\Support\SchoolProfiles\Files;
use App\Domain\Keirin\Statistics\Support\SchoolProfiles\Projection;
use RuntimeException;
use Throwable;

final class SchoolProfileCatalogService
{
    public const VERSION = 'SCHOOL-PROFILE-CATALOG-v1';

    public function build(string $manifest, string $sha, string $output, bool $dryRun = false): array
    {
        $parent = Files::read($manifest, $sha);
        $complete = Files::read(dirname($manifest).'/COMPLETE.json', Files::seal(dirname($manifest).'/COMPLETE.json')['sha256']);
        if (($parent['contract'] ?? null) !== 'STAT-EXISTING-DATA-COMPLETION-REVIEW-v1'
            || ($complete['manifest_sha256'] ?? null) !== $sha || ($complete['exit_code'] ?? null) !== 0) {
            throw new RuntimeException('Unsupported or incomplete sealed school source inventory.');
        }
        $ref = $parent['files']['executed-artifacts.json'] ?? [];
        $inventory = Files::read(Files::verify($ref), $ref['sha256']);
        $refs = $inventory['profiles']['files'] ?? [];
        $sources = Files::verify($refs['sources.jsonl'] ?? []);
        $projections = Files::verify($refs['projections.jsonl'] ?? []);
        if (! $dryRun) {
            Files::createDirectory($output);
        }
        $counts = ['input_rows' => 0, 'processed_versions' => 0, 'duplicate_rows' => 0, 'unsupported' => 0,
            'insufficient' => 0, 'invalid' => 0, 'identity_conflicts' => 0, 'source_rows' => 0, 'duplicate_source_rows' => 0];
        $index = $players = $sourceRows = $seen = [];
        $writer = null;
        try {
            foreach (Files::rows($sources) as $row) {
                $s = $row['value'];
                $counts['source_rows']++;
                $id = $s['fetch_log_id'] ?? null;
                if (! is_int($id) || $id < 1) {
                    throw new RuntimeException('Invalid school source fetch log.');
                }
                if (isset($sourceRows[$id])) {
                    if ($sourceRows[$id] !== $s) {
                        $counts['identity_conflicts']++;
                        throw new RuntimeException('Conflicting source identity for the same school fetch log.');
                    }
                    $counts['duplicate_source_rows']++;
                } else {
                    $sourceRows[$id] = $s;
                }
            }
            if (! $dryRun) {
                $writer = fopen($output.'/versions.jsonl', 'xb');
                if ($writer === false || ! chmod($output.'/versions.jsonl', 0600)) {
                    throw new RuntimeException('Cannot create school catalog spool.');
                }
            }
            foreach (Files::rows($projections) as $row) {
                $counts['input_rows']++;
                $p = $row['value'];
                $id = $p['source']['fetch_log_id'] ?? null;
                $fingerprint = hash('sha256', serialize($p));
                if (isset($seen[$id])) {
                    if ($seen[$id] !== $fingerprint) {
                        $counts['identity_conflicts']++;
                        throw new RuntimeException('Conflicting projection for the same school fetch log.');
                    }
                    $counts['duplicate_rows']++;

                    continue;
                }
                Projection::validate($p, $sourceRows[$id] ?? []);
                $seen[$id] = $fingerprint;
                $record = ['fetch_log_id' => $id, 'external_player_id' => $p['external_player_id'],
                    'projection_sha256' => hash('sha256', $row['raw']), 'projection_json' => $row['raw'],
                    'original_projection' => ['path' => $projections, 'line' => $row['line'], 'sha256' => $refs['projections.jsonl']['sha256']],
                    'source_record' => $sourceRows[$id]];
                $bytes = Files::json($record);
                $offset = $writer === null ? 0 : ftell($writer);
                if ($writer !== null && fwrite($writer, $bytes) !== strlen($bytes)) {
                    throw new RuntimeException('Incomplete school spool retained.');
                }
                $index[(string) $id] = ['external_player_id' => $p['external_player_id'], 'offset' => $offset,
                    'bytes' => strlen($bytes), 'record_sha256' => hash('sha256', $bytes), 'projection_sha256' => $record['projection_sha256']];
                $players[$p['external_player_id']][] = $id;
                $counts['processed_versions']++;
            }
            if (count($seen) !== count($sourceRows)) {
                throw new RuntimeException('School source/projection fetch-log set mismatch.');
            }
            ksort($index, SORT_NUMERIC);
            ksort($players, SORT_STRING);
            foreach ($players as &$ids) {
                sort($ids, SORT_NUMERIC);
            }
            unset($ids);
            $counts['unique_players'] = count($players);
            if ($writer !== null) {
                if (! fflush($writer) || ! fsync($writer)) {
                    throw new RuntimeException('Cannot flush school catalog.');
                }
                fclose($writer);
                $writer = null;
            }
            Files::verify($refs['sources.jsonl']);
            Files::verify($refs['projections.jsonl']);
            Files::verify($ref);
            Files::read($manifest, $sha);
            $result = ['contract' => self::VERSION, 'counts' => $counts, 'dry_run' => $dryRun,
                'graduation_period_raw_status' => 'NOT_RECORDED_IN_PROJECTION_V1',
                'original_raw_independently_reverified' => false];
            if (! $dryRun) {
                Files::write($output.'/index.json', Files::json(['contract' => self::VERSION, 'versions' => $index, 'players' => $players]));
                Files::write($output.'/summary.json', Files::json($result));
                $files = [];
                foreach (['versions.jsonl', 'index.json', 'summary.json'] as $name) {
                    $files[$name] = Files::seal($output.'/'.$name);
                }
                Files::write($output.'/manifest.json', Files::json(['contract' => self::VERSION, 'files' => $files,
                    'input_manifest' => Files::seal($manifest), 'input_inventory' => $ref, 'input_files' => $refs]));
                Files::write($output.'/COMPLETE.json', Files::json(['contract' => self::VERSION, 'manifest_sha256' => Files::seal($output.'/manifest.json')['sha256']]));
                $this->load($output.'/manifest.json', Files::seal($output.'/manifest.json')['sha256']);
            }

            return $result;
        } catch (Throwable $error) {
            if ($counts['identity_conflicts'] === 0) {
                $counts['invalid']++;
            }
            if (is_resource($writer)) {
                fclose($writer);
            }
            if (! $dryRun) {
                Files::write($output.'/FAILED.json', Files::json(['contract' => self::VERSION, 'counts' => $counts,
                    'current_record' => isset($row) ? ['line' => $row['line'], 'fetch_log_id' => $id ?? null] : null,
                    'remaining_records' => 'NOT_PROCESSED_AFTER_FATAL_ERROR',
                    'error_type' => $error::class, 'reason' => $error->getMessage()]));
            }
            throw $error;
        }
    }

    public function load(string $manifest, string $sha): array
    {
        $m = Files::read($manifest, $sha);
        $root = dirname($manifest);
        $c = Files::read($root.'/COMPLETE.json', Files::seal($root.'/COMPLETE.json')['sha256']);
        $actual = scandir($root);
        $expected = ['.', '..', 'COMPLETE.json', 'index.json', 'manifest.json', 'summary.json', 'versions.jsonl'];
        sort($expected);
        if ($actual !== $expected || ($m['contract'] ?? null) !== self::VERSION || ($c['contract'] ?? null) !== self::VERSION
            || ($c['manifest_sha256'] ?? null) !== $sha || array_keys($m['files'] ?? []) !== ['versions.jsonl', 'index.json', 'summary.json']) {
            throw new RuntimeException('Invalid school catalog contract or terminal inventory.');
        }
        foreach ($m['files'] as $name => $ref) {
            if (($ref['path'] ?? null) !== $root.'/'.$name) {
                throw new RuntimeException('School catalog file binding mismatch.');
            }
            Files::verify($ref);
        }
        $index = Files::read($root.'/index.json', $m['files']['index.json']['sha256']);
        if (($index['contract'] ?? null) !== self::VERSION) {
            throw new RuntimeException('School catalog index contract mismatch.');
        }

        return ['manifest' => $m, 'sha256' => $sha, 'index' => $index];
    }

    public function record(array $catalog, int $id): array
    {
        $i = $catalog['index']['versions'][(string) $id] ?? throw new RuntimeException('Selected school fetch log absent.');
        $spool = $catalog['manifest']['files']['versions.jsonl'];
        if (! is_int($i['offset'] ?? null) || $i['offset'] < 0 || ! is_int($i['bytes'] ?? null)
            || $i['bytes'] < 1 || $i['bytes'] > 2 * 1024 * 1024 || $i['offset'] > $spool['bytes'] - $i['bytes']) {
            throw new RuntimeException('School index offset/size outside bounded catalog.');
        }
        $h = fopen($spool['path'], 'rb');
        if ($h === false) {
            throw new RuntimeException('Cannot reopen selected school projection.');
        }
        try {
            if (fseek($h, $i['offset']) !== 0) {
                throw new RuntimeException('School index offset unavailable.');
            }
            $line = fread($h, $i['bytes']);
        } finally {
            fclose($h);
        }
        if ($line === false || strlen($line) !== $i['bytes'] || hash('sha256', $line) !== $i['record_sha256']) {
            throw new RuntimeException('School index record seal mismatch.');
        }
        $r = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        $p = json_decode($r['projection_json'], true, flags: JSON_THROW_ON_ERROR);
        Projection::validate($p, $r['source_record']);
        if ($r['fetch_log_id'] !== $id || $r['external_player_id'] !== $i['external_player_id']
            || $p['external_player_id'] !== $r['external_player_id'] || $p['source']['fetch_log_id'] !== $id
            || $r['projection_sha256'] !== $i['projection_sha256'] || hash('sha256', $r['projection_json']) !== $r['projection_sha256']) {
            throw new RuntimeException('School indexed identity/projection SHA mismatch.');
        }

        return $r + ['projection' => $p];
    }
}
