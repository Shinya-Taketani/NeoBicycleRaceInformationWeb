<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\StartCountC1Candidate;

use PDO;
use PDOStatement;
use RuntimeException;

final class Index
{
    private PDO $db;

    private array $statements = [];

    private int $writes = 0;

    public function __construct(string $path)
    {
        if (file_exists($path)) {
            throw new RuntimeException('Local index overwrite forbidden.');
        }
        $this->db = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA cache_size=-2048; PRAGMA temp_store=FILE;
            CREATE TABLE entries(id INTEGER PRIMARY KEY, year INTEGER, race INTEGER, bike INTEGER, mapping TEXT, UNIQUE(race,bike));
            CREATE TABLE races(id INTEGER PRIMARY KEY, year INTEGER);
            CREATE TABLE fetches(id INTEGER PRIMARY KEY, race INTEGER, year INTEGER, expected INTEGER, seen INTEGER DEFAULT 0, data TEXT);
            CREATE INDEX fetch_race ON fetches(race);
            CREATE TABLE observations(race INTEGER, fetch INTEGER, row_index INTEGER, entry INTEGER, value INTEGER, signature TEXT, ref TEXT, PRIMARY KEY(fetch,row_index));
            CREATE INDEX obs_entry ON observations(entry,fetch,row_index);
            CREATE TABLE reasons(entry INTEGER, reason TEXT, PRIMARY KEY(entry,reason));
            CREATE TABLE outside(race INTEGER, bike TEXT, external TEXT, PRIMARY KEY(race,bike,external));');
    }

    public function run(string $sql, array $values = []): PDOStatement
    {
        $q = $this->statements[$sql] ??= $this->db->prepare($sql);
        $q->execute($values);

        return $q;
    }

    public function write(string $sql, array $values = []): void
    {
        if (! $this->db->inTransaction()) {
            $this->db->beginTransaction();
        }
        $this->run($sql, $values);
        if (++$this->writes % 1000 === 0) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->commit();
        }
    }

    public function entry(int $race, ?int $bike): array|false
    {
        return $this->run('SELECT * FROM entries WHERE race=? AND bike=?', [$race, $bike])->fetch(PDO::FETCH_ASSOC);
    }

    public function reason(int $entry, string $reason): void
    {
        $this->write('INSERT OR IGNORE INTO reasons VALUES (?,?)', [$entry, $reason]);
    }

    public function raceReason(int $race, string $reason): void
    {
        $this->write('INSERT OR IGNORE INTO reasons SELECT id,? FROM entries WHERE race=?', [$reason, $race]);
    }

    public function reasons(int $entry): array
    {
        return $this->run('SELECT reason FROM reasons WHERE entry=? ORDER BY reason', [$entry])->fetchAll(PDO::FETCH_COLUMN);
    }
}
