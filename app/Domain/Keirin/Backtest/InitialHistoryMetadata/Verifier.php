<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\InitialHistoryMetadata;

use App\Domain\Keirin\Backtest\ChronologicalValidation\Json;
use PDO;
use Throwable;

final class Verifier
{
    public function verify(string $bundle, string $output): array
    {
        Evidence::create($output);
        $started = Evidence::now();
        $clock = hrtime(true);
        try {
            $manifest = Evidence::open($bundle, 'manifest.json', 'EXPORT');
            $index = Scope::index($output.'/verification-index.sqlite');
            $index->exec('CREATE TABLE saved (type TEXT,k TEXT,rid INTEGER,data TEXT,PRIMARY KEY(type,k)); CREATE TABLE unresolved (type TEXT,k TEXT,data TEXT,PRIMARY KEY(type,k))');
            $index->beginTransaction();
            foreach (array_unique(array_values(Exporter::OUTPUTS)) as $file) {
                foreach (Evidence::rows($bundle.'/'.$file, $manifest['files'][$file]) as $row) {
                    $type = $row['record_type'];
                    Evidence::require((Exporter::OUTPUTS[$type] ?? null) === $file, 'Wrong record partition.');
                    Scope::add($index, $type, $row['scope']);
                    $s = $index->prepare('INSERT INTO saved VALUES (?,?,?,?)');
                    $s->execute([$type, $row['scope']['key'], $row['scope']['race_id'], Json::encode($row)]);
                }
            }
            foreach (Evidence::rows($bundle.'/unresolved.jsonl', $manifest['files']['unresolved.jsonl']) as $row) {
                $s = $index->prepare('INSERT INTO unresolved VALUES (?,?,?)');
                $s->execute([$row['record_type'], $row['key'], Json::encode($row)]);
            }
            Evidence::require(Scope::validate($index) === $manifest['counts'], 'Verified scope counts mismatch.');
            $index->commit();
            foreach ($manifest['files'] as $file => $seal) {
                if (! str_ends_with($file, '.jsonl')) {
                    Json::verify($bundle.'/'.$file, array_intersect_key($seal, array_flip(['bytes', 'sha256'])));
                }
            }
            PostgresSource::guard(Json::read($bundle.'/connection-precheck.json'), Json::read($bundle.'/snapshot.json'));
            $proof = Json::read($bundle.'/scope-proof.json');
            Evidence::require(Json::hash($proof) === Json::hash($manifest['scope']), 'Scope proof mismatch.');
            $inspection = new Inspection($index);
            $index->beginTransaction();
            $expectedUnresolved = 0;
            foreach (Evidence::TYPES as $type) {
                $s = $index->prepare('SELECT data FROM saved WHERE type=? ORDER BY rid,k');
                $s->execute([$type]);
                while (($data = $s->fetchColumn()) !== false) {
                    $row = json_decode($data, true, flags: JSON_THROW_ON_ERROR);
                    $recomputed = $inspection->row($type, $row['scope']['key'], $row['current']);
                    Evidence::require(Json::hash($row) === Json::hash($recomputed), 'Saved comparison/timing inconsistent.');
                    if ($row['reasons'] !== []) {
                        $this->checkUnresolved($index, Exporter::unresolved($row));
                        $expectedUnresolved++;
                    }
                }
            }
            $s = $index->query("SELECT data FROM unresolved WHERE type IN ('EXTRA_ENTRY','EXTRA_RESULT') ORDER BY type,k");
            while (($data = $s->fetchColumn()) !== false) {
                $row = json_decode($data, true, flags: JSON_THROW_ON_ERROR);
                $computed = $inspection->row($row['record_type'], $row['key'], $row['current']);
                Evidence::require($computed !== null && Json::hash($computed) === Json::hash($row), 'Additional key audit inconsistent.');
                $expectedUnresolved++;
            }
            Evidence::require($index->query('SELECT count(*) FROM unresolved')->fetchColumn() === $expectedUnresolved, 'Missing or extra unresolved reasons.');
            $summary = $inspection->finish($proof);
            Evidence::require(Json::hash($summary) === Json::hash(Json::read($bundle.'/summary.json')), 'Saved summary inconsistent.');
            $index->commit();
            $index = null;
            $files = ['verification-index.sqlite' => Json::seal($output.'/verification-index.sqlite'),
                'verification.json' => Evidence::json($output, 'verification.json', ['status' => 'VERIFIED_METADATA_ONLY', 'bundle' => ['path' => $bundle, 'manifest' => Json::seal($bundle.'/manifest.json')],
                    'counts' => $manifest['counts'], 'scope_digests' => $summary['scope_digests'], 'business_connections' => 0, 'http_requests' => 0,
                    'started_at' => $started, 'finished_at' => Evidence::now(), 'elapsed_seconds' => (hrtime(true) - $clock) / 1e9,
                    'peak_memory_bytes' => memory_get_peak_usage(true), 'memory_limit' => ini_get('memory_limit'), 'exit' => 0])];

            return Evidence::publish($output, 'manifest.json', ['version' => Evidence::VERSION, 'kind' => 'VERIFICATION', 'files' => $files,
                'bundle' => Json::seal($bundle.'/manifest.json'), 'code' => Evidence::code(), 'business_connections' => 0]);
        } catch (Throwable $e) {
            Evidence::failed($output, $e, 'VERIFY', $started, $clock);
            throw $e;
        }
    }

    private function checkUnresolved(PDO $db, array $expected): void
    {
        $s = $db->prepare('SELECT data FROM unresolved WHERE type=? AND k=?');
        $s->execute([$expected['record_type'], $expected['key']]);
        $value = $s->fetchColumn();
        Evidence::require($value !== false && Json::hash(json_decode($value, true, flags: JSON_THROW_ON_ERROR)) === Json::hash($expected), 'Unresolved audit not preserved.');
    }
}
