<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\StartCountSnapshot;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use App\Domain\Keirin\Statistics\StartObservation\Ledger;
use PDO;
use RuntimeException;

final class Index
{
    private PDO $db;

    private int $pending = 0;

    public function __construct(string $path)
    {
        if (file_exists($path)) {
            throw new RuntimeException('Index overwrite forbidden.');
        }
        $this->db = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA cache_size=-2048');
        $this->db->exec('CREATE TABLE targets (id INTEGER PRIMARY KEY, date TEXT, number INTEGER)');
        $this->db->exec('CREATE TABLE fetches (race INTEGER, id INTEGER, PRIMARY KEY(race,id))');
        $this->db->exec('CREATE TABLE requests (race INTEGER, encp TEXT, PRIMARY KEY(race,encp))');
        $this->db->exec('CREATE TABLE versions (year INTEGER, race INTEGER, bike INTEGER, external TEXT, signature TEXT, PRIMARY KEY(race,bike,external,signature))');
    }

    public function load(string $source, array $manifest, Ledger $ledger): void
    {
        $accepted = $ledger->open($manifest['ledger']['path']);
        Files::same($accepted, $manifest['ledger'], 'fixed ledger source');
        $targets = Artifacts::lines($source.'/targets.jsonl');
        $this->db->beginTransaction();
        $n = 0;
        foreach ($ledger->races($accepted) as $item) {
            $expected = Bundle::target($item);
            if (! $targets->valid()) {
                throw new RuntimeException('Target ledger is incomplete.');
            }
            Files::same($expected, $targets->current(), 'target projection');
            $r = $expected['race'];
            $this->run('INSERT INTO targets VALUES (?,?,?)', [$r['race_id'], $r['race_date'], $r['race_number']]);
            foreach ($expected['historical_fetch_ids'] as $id) {
                $this->run('INSERT INTO fetches VALUES (?,?)', [$r['race_id'], $id]);
            }
            $targets->next();
            $n++;
        }
        if ($targets->valid() || $n !== $manifest['targets']) {
            throw new RuntimeException('Target count mismatch.');
        }
        foreach (Artifacts::lines($source.'/race-requests.jsonl') as $r) {
            $target = $this->run('SELECT date,number FROM targets WHERE id=?', [$r['race_id']])->fetch(PDO::FETCH_ASSOC);
            if (! $target || $target['date'] !== $r['race_date'] || (int) $target['number'] !== $r['race_number'] || $r['source'] !== 'keirin_jp') {
                throw new RuntimeException('Current request race identity mismatch.');
            }
            $this->request($r['race_id'], $r['encrypted_parameter']);
        }
        foreach (Artifacts::lines($source.'/historical-requests.jsonl') as $r) {
            if (! $this->run('SELECT 1 FROM fetches WHERE race=? AND id=?', [$r['race_id'], $r['id']])->fetchColumn()
                || $r['source'] !== 'keirin_jp') {
                throw new RuntimeException('Historical request not in accepted ledger.');
            }
            if (in_array($r['request_parameters']['disp'] ?? null, ['PJ0315', 'PJ0326'], true)) {
                $this->request($r['race_id'], $r['request_parameters']['encp'] ?? null);
            }
        }
        $this->db->commit();
    }

    private function request(int $race, mixed $encp): void
    {
        if (is_string($encp) && $encp !== '') {
            $this->run('INSERT OR IGNORE INTO requests VALUES (?,?)', [$race, $encp]);
        }
    }

    public function candidate(array $row): void
    {
        if ($row['source'] !== 'keirin_jp' || ($row['request_parameters']['disp'] ?? null) !== 'PJ0315'
            || ! is_string($row['request_parameters']['encp'] ?? null)
            || ! $this->run('SELECT 1 FROM requests WHERE race=? AND encp=?', [$row['race_id'], $row['request_parameters']['encp']])->fetchColumn()) {
            throw new RuntimeException('Unbound PJ0315 request.');
        }
    }

    public function record(int $year, int $race, array $row): void
    {
        if ($row['identity_issues'] === []) {
            if (! $this->db->inTransaction()) {
                $this->db->beginTransaction();
            }
            $this->run('INSERT OR IGNORE INTO versions VALUES (?,?,?,?,?)', [$year, $race, $row['bike_number'], $row['observed_external_id'], $row['value_signature']]);
            if (++$this->pending % 1000 === 0) {
                $this->db->commit();
            }
        }
    }

    public function distinct(int $year): array
    {
        if ($this->db->inTransaction()) {
            $this->db->commit();
        }
        $result = $this->run('SELECT COUNT(*) AS entries, COALESCE(SUM(n>1),0) AS changed FROM
            (SELECT COUNT(*) n FROM versions WHERE year=? GROUP BY race,bike,external)', [$year])->fetch(PDO::FETCH_ASSOC);

        return ['unique_matched_entries' => (int) $result['entries'], 'entries_with_multiple_value_versions' => (int) $result['changed']];
    }

    private function run(string $sql, array $bindings): \PDOStatement
    {
        $s = $this->db->prepare($sql);
        $s->execute($bindings);

        return $s;
    }
}
