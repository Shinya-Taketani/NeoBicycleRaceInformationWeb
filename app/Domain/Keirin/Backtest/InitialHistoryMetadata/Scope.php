<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\InitialHistoryMetadata;

use App\Domain\Keirin\Backtest\ChronologicalValidation\Json;
use Generator;
use PDO;

final class Scope
{
    public static function index(string $path): PDO
    {
        Evidence::require(! file_exists($path) && ! is_link($path), 'Local index must be new.');
        $db = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA journal_mode=MEMORY; PRAGMA temp_store=MEMORY; PRAGMA cache_size=-4096; CREATE TABLE scope (type TEXT, k TEXT, rid INTEGER, data TEXT, PRIMARY KEY(type,k)); CREATE INDEX scope_race ON scope(type,rid)');

        return $db;
    }

    public static function add(PDO $db, string $type, array $row): void
    {
        Evidence::require(in_array($type, Evidence::TYPES, true), 'Unknown scope type.');
        Evidence::day($row['race_date']);
        Evidence::require(is_int($row['race_id']) && $row['race_id'] > 0 && is_string($row['key']) && $row['key'] !== '', 'Invalid scope key.');
        $e = $row['expected'];
        Evidence::require(($type === 'CONTEXT' ? ($e['id'] ?? null) : ($e['race_id'] ?? null)) === $row['race_id'], 'Scope parent identity invalid.');
        if (in_array($type, ['CONTEXT', 'ENTRY', 'IMPORT', 'FETCH'], true)) {
            Evidence::require(is_int($e['id'] ?? null) && $e['id'] > 0 && (string) $e['id'] === $row['key'], 'Scope original ID invalid.');
        }
        if (in_array($type, ['ENTRY', 'RESULT'], true)) {
            Evidence::require(is_int($e['bike_number'] ?? null) && $e['bike_number'] >= 1 && $e['bike_number'] <= 9, 'Scope bike invalid.');
        }
        if ($type === 'ENTRY') {
            Evidence::require(is_string($e['external_player_id'] ?? null) && ctype_digit($e['external_player_id']), 'Original external identity must remain a string.');
        }
        $s = $db->prepare('INSERT INTO scope VALUES (?,?,?,?)');
        $s->execute([$type, $row['key'], $row['race_id'], Json::encode($row)]);
    }

    public static function get(PDO $db, string $type, string $key): ?array
    {
        $s = $db->prepare('SELECT data FROM scope WHERE type=? AND k=?');
        $s->execute([$type, $key]);
        $value = $s->fetchColumn();

        return $value === false ? null : json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }

    public static function rows(PDO $db, string $type): Generator
    {
        $s = $db->prepare('SELECT data FROM scope WHERE type=? ORDER BY rid,k');
        $s->execute([$type]);
        while (($value = $s->fetchColumn()) !== false) {
            yield json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        }
    }

    public static function validate(PDO $db): array
    {
        // Resolve every binding on disk before opening the business connection.
        $bad = $db->query("SELECT count(*) FROM scope s LEFT JOIN scope r ON r.type='CONTEXT' AND r.k=CAST(s.rid AS TEXT)
            WHERE r.k IS NULL OR json_extract(s.data,'$.race_date')<>json_extract(r.data,'$.race_date')")->fetchColumn();
        Evidence::require($bad === 0, 'Scope race/date binding mismatch.');
        foreach (['ENTRY' => 'id', 'FETCH' => 'id', 'IMPORT' => 'id'] as $type => $field) {
            $s = $db->prepare("SELECT count(*) FROM scope WHERE type=? AND (k<>CAST(json_extract(data,'$.expected.$field') AS TEXT) OR rid<>json_extract(data,'$.expected.race_id'))");
            $s->execute([$type]);
            Evidence::require($s->fetchColumn() === 0, 'Scope identity binding mismatch.');
        }
        foreach ([['IMPORT', 'FETCH', 'scraping_fetch_log_id'], ['RESULT', 'IMPORT', 'race_result_import_id']] as [$from, $to, $field]) {
            $s = $db->prepare("SELECT count(*) FROM scope s LEFT JOIN scope t ON t.type=? AND t.k=CAST(json_extract(s.data,'$.expected.$field') AS TEXT)
                WHERE s.type=? AND (t.k IS NULL OR t.rid<>s.rid)");
            $s->execute([$to, $from]);
            Evidence::require($s->fetchColumn() === 0, 'Scope import/fetch binding mismatch.');
        }
        $bad = $db->query("SELECT count(*) FROM scope WHERE type='RESULT' AND (k<>CAST(rid AS TEXT)||':'||CAST(json_extract(data,'$.expected.bike_number') AS TEXT) OR rid<>json_extract(data,'$.expected.race_id'))")->fetchColumn();
        Evidence::require($bad === 0, 'Scope result key mismatch.');
        $bad = $db->query("SELECT count(*) FROM (SELECT rid FROM scope WHERE type='ENTRY' GROUP BY rid
            HAVING count(*)<>count(distinct json_extract(data,'$.expected.bike_number'))
            OR count(*)<>count(distinct json_extract(data,'$.expected.external_player_id')))")->fetchColumn();
        Evidence::require($bad === 0, 'Duplicate scope roster identity.');
        $bad = $db->query("SELECT count(*) FROM scope r LEFT JOIN (SELECT rid,count(*) n FROM scope WHERE type='ENTRY' GROUP BY rid) e ON e.rid=r.rid
            WHERE r.type='CONTEXT' AND coalesce(e.n,0)<>json_extract(r.data,'$.expected.entrant_count')")->fetchColumn();
        Evidence::require($bad === 0, 'Scope full-roster count mismatch.');
        $bad = $db->query("SELECT count(*) FROM scope s JOIN scope f ON f.type='FETCH' AND f.k=CAST(json_extract(s.data,'$.expected.scraping_fetch_log_id') AS TEXT)
            WHERE s.type='IMPORT' AND (json_extract(f.data,'$.expected.parameters.disp_value')<>'PJ0326'
            OR json_extract(s.data,'$.expected.source_hash')<>json_extract(f.data,'$.expected.sha256')
            OR json_extract(s.data,'$.expected.raw_response_size')<>json_extract(f.data,'$.expected.response_size')
            OR json_extract(s.data,'$.expected.raw_file_path')<>json_extract(f.data,'$.expected.raw_file_path'))")->fetchColumn();
        Evidence::require($bad === 0, 'Scope import Raw binding mismatch.');
        $counts = [];
        foreach (Evidence::TYPES as $type) {
            $s = $db->prepare('SELECT count(*) FROM scope WHERE type=?');
            $s->execute([$type]);
            $counts[$type] = $s->fetchColumn();
        }

        return $counts;
    }

    public static function load(string $manifest, string $index): array
    {
        $root = dirname($manifest);
        Evidence::require(basename($manifest) === 'scope-manifest.json', 'Scope manifest filename required.');
        $m = Evidence::open($root, 'scope-manifest.json', 'SCOPE');
        $names = array_keys($m['files']);
        $wanted = [...array_values(Evidence::SCOPE_FILES), 'scope-index.sqlite'];
        sort($names);
        sort($wanted);
        Evidence::require($names === $wanted, 'Scope files contract mismatch.');
        Json::verify($root.'/scope-index.sqlite', $m['files']['scope-index.sqlite']);
        $db = self::index($index);
        $db->beginTransaction();
        foreach (Evidence::SCOPE_FILES as $type => $name) {
            foreach (Evidence::rows($root.'/'.$name, $m['files'][$name]) as $row) {
                self::add($db, $type, $row);
            }
        }
        $counts = self::validate($db);
        Evidence::require($counts === $m['counts'], 'Scope counts mismatch.');
        $db->commit();

        return [$m, $db];
    }
}
