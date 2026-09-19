<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthTrendScoreSource;

use App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysis\Signals;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use PDO;
use RuntimeException;

class Workspace
{
    public readonly PDO $db;

    public function __construct(string $path)
    {
        if (file_exists($path)) {
            throw new RuntimeException('New spool required.');
        }
        $this->db = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->db->exec('PRAGMA cache_size=-4096; PRAGMA temp_store=FILE; PRAGMA journal_mode=DELETE;
            CREATE TABLE targets(entry_id INTEGER PRIMARY KEY,race_id INTEGER,year INTEGER,bike INTEGER,player_id INTEGER,UNIQUE(race_id,bike));
            CREATE TABLE observations(entry_id INTEGER PRIMARY KEY,race_id INTEGER,player_id INTEGER,bike INTEGER,date TEXT,ts INTEGER,meeting_id INTEGER,start TEXT,score INTEGER,body TEXT,UNIQUE(race_id,bike));
            CREATE INDEX by_player ON observations(player_id,meeting_id,date,ts);
            CREATE INDEX target_race ON targets(race_id)');
    }

    public function targets(iterable $rows): void
    {
        $q = $this->db->prepare('INSERT INTO targets(entry_id,race_id,year,bike) VALUES(?,?,?,?)');
        $this->db->beginTransaction();
        foreach ($rows as $row) {
            $q->execute([$row['entry_id'], $row['race_id'], $row['year'], $row['bike']]);
        }
        $this->db->commit();
    }

    public function observations(iterable $rows): void
    {
        $q = $this->db->prepare('INSERT INTO observations VALUES(?,?,?,?,?,?,?,?,?,?)');
        $this->db->beginTransaction();
        foreach ($rows as $row) {
            self::validate($row);
            $q->execute([$row['entry_id'], $row['race_id'], $row['player_id'], $row['bike'], $row['race_date'],
                Signals::timestamp($row['race_date'], $row['scheduled_start_at']), $row['meeting_id'], $row['meeting_starts_on'],
                Signals::score($row['race_score']), Files::canonical($row)]);
        }
        $this->db->commit();
        if ($this->db->query('SELECT t.entry_id FROM targets t LEFT JOIN observations o ON o.entry_id=t.entry_id WHERE o.entry_id IS NULL OR t.race_id<>o.race_id OR t.bike<>o.bike OR t.year<>CAST(substr(o.date,1,4) AS INTEGER) LIMIT 1')->fetch()) {
            throw new RuntimeException('Target/score snapshot identity mismatch.');
        }
    }

    public static function validate(array $row): void
    {
        OuterSource::keys($row, ['entry_id', 'race_id', 'player_id', 'bike', 'race_date', 'scheduled_start_at', 'race_day_id',
            'day_date', 'meeting_id', 'meeting_starts_on', 'meeting_ends_on', 'meeting_grade', 'race_score', 'entry_fetched_at']);
        Contract::year((int) substr($row['race_date'], 0, 4));
        Signals::timestamp($row['race_date'], $row['scheduled_start_at']);
        Signals::score($row['race_score']);
        foreach (['entry_id', 'race_id', 'bike'] as $key) {
            if (! is_int($row[$key]) || $row[$key] < 1 || ($key === 'bike' && $row[$key] > 9)) {
                throw new RuntimeException('Invalid observation identity.');
            }
        }
        foreach (['player_id', 'race_day_id', 'meeting_id'] as $key) {
            if ($row[$key] !== null && (! is_int($row[$key]) || $row[$key] < 1)) {
                throw new RuntimeException('Invalid optional identity.');
            }
        }
        if (($row['day_date'] !== null && $row['day_date'] !== $row['race_date'])
            || ($row['meeting_starts_on'] !== null && $row['meeting_starts_on'] > $row['race_date'])
            || ($row['meeting_ends_on'] !== null && $row['meeting_ends_on'] < $row['race_date'])) {
            throw new RuntimeException('Observation meeting/date mismatch.');
        }
    }
}
