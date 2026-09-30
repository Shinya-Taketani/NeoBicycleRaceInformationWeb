<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\StartObservation;

use PDO;

final class Index
{
    private PDO $db;

    public function __construct(string $path)
    {
        $this->db = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA cache_size=-4096');
        $this->db->exec('CREATE TABLE imports (id INTEGER PRIMARY KEY)');
        $this->db->exec('CREATE TABLE entries (year INTEGER, race INTEGER, bike INTEGER, external TEXT, PRIMARY KEY(year,race,bike,external)) WITHOUT ROWID');
        $this->db->exec('CREATE TABLE versions (year INTEGER, race INTEGER, bike INTEGER, signature TEXT, PRIMARY KEY(year,race,bike,signature)) WITHOUT ROWID');
        $this->db->beginTransaction();
    }

    public function import(int $id): void
    {
        $this->db->prepare('INSERT INTO imports VALUES (?)')->execute([$id]);
    }

    public function entry(int $year, int $race, array $row, string $signature): void
    {
        if ($row['identity_status'] !== 'MATCHED_LEDGER_ENTRY') {
            return;
        }
        $this->db->prepare('INSERT OR IGNORE INTO entries VALUES (?,?,?,?)')->execute([$year, $race, $row['bike_number'], $row['external_player_id']]);
        $this->db->prepare('INSERT OR IGNORE INTO versions VALUES (?,?,?,?)')->execute([$year, $race, $row['bike_number'], $signature]);
    }

    public function finish(): array
    {
        $this->db->commit();
        $counts = [];
        foreach (Contract::YEARS as $year) {
            $q = $this->db->prepare('SELECT COUNT(*) FROM entries WHERE year=?');
            $q->execute([$year]);
            $counts[$year] = ['unique_matched_entries' => (int) $q->fetchColumn()];
            $q = $this->db->prepare('SELECT COUNT(*) FROM (SELECT race,bike FROM versions WHERE year=? GROUP BY race,bike HAVING COUNT(*)>1)');
            $q->execute([$year]);
            $counts[$year]['entries_with_multiple_display_versions'] = (int) $q->fetchColumn();
        }

        return $counts;
    }
}
