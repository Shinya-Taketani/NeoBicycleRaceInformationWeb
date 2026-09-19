<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthAdjustmentCalibration;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use PDO;
use RuntimeException;

final class Workspace
{
    public readonly PDO $db;

    public function __construct(string $path)
    {
        if (file_exists($path)) {
            throw new RuntimeException('New workspace required.');
        }
        $this->db = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->db->exec('PRAGMA temp_store=FILE');
        $this->db->exec('PRAGMA cache_size=-4096');
        $this->db->exec('CREATE TABLE growth (entry_id INTEGER PRIMARY KEY, race_id INTEGER, year INTEGER, data TEXT, used INTEGER DEFAULT 0)');
        $this->db->exec('CREATE INDEX growth_race ON growth(race_id)');
        $this->db->exec('CREATE TABLE metadata (race_id INTEGER PRIMARY KEY, data TEXT)');
        $this->db->exec('CREATE TABLE seen (race_id INTEGER PRIMARY KEY)');
    }

    public function load(string $growth, string $cohort): array
    {
        $insert = $this->db->prepare('INSERT INTO growth(entry_id,race_id,year,data) VALUES(?,?,?,?)');
        $counts = $missing = $same = array_fill_keys(Contract::YEARS, 0);
        $this->db->beginTransaction();
        foreach (JsonlArtifact::read($growth) as $row) {
            Projection::validate($row);
            $insert->execute([$row['entry_id'], $row['race_id'], $row['year'], Files::canonical($row)]);
            $counts[$row['year']]++;
            $missing[$row['year']] += $row['score_point'] === null ? 1 : 0;
            $same[$row['year']] += $row['same_meeting_previous'] === true ? 1 : 0;
        }
        $insert = $this->db->prepare('INSERT INTO metadata VALUES(?,?)');
        foreach (JsonlArtifact::read($cohort) as $row) {
            Contract::race($row['context']);
            $meta = ['year' => $row['context']['year'], 'race_id' => $row['context']['race_id'], 'grade' => $row['grade'], 'class' => $row['class'], 'targets' => $row['targets']];
            if (! in_array($meta['grade'], ['GP', 'G1', 'G2', 'G3', 'F1', 'F2', 'UNKNOWN'], true)
                || ! in_array($meta['class'], ['S_CLASS', 'A1_A2', 'A_CHALLENGE', 'UNKNOWN'], true)) {
                throw new RuntimeException('Invalid fixed metadata.');
            }
            $insert->execute([$meta['race_id'], Files::canonical($meta)]);
        }
        $this->db->commit();

        return ['entries' => $counts, 'missing' => $missing, 'same' => $same];
    }

    public function join(array $race): array
    {
        Contract::race($race);
        $this->db->prepare('INSERT INTO seen VALUES(?)')->execute([$race['race_id']]);
        $q = $this->db->prepare('SELECT data FROM metadata WHERE race_id=?');
        $q->execute([$race['race_id']]);
        $text = $q->fetchColumn();
        if ($text === false) {
            throw new RuntimeException('Missing cohort race.');
        }
        $meta = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        $q = $this->db->prepare('SELECT entry_id,data FROM growth WHERE race_id=?');
        $q->execute([$race['race_id']]);
        $growth = [];
        foreach ($q as $row) {
            $growth[$row['entry_id']] = json_decode($row['data'], true, flags: JSON_THROW_ON_ERROR);
        }
        $targets = array_column($meta['targets'], null, 'id');
        if ($meta['year'] !== $race['year'] || count($growth) !== count($race['entries']) || count($targets) !== count($race['entries'])) {
            throw new RuntimeException('Cohort/growth/input entrant count mismatch.');
        }
        $aligned = [];
        $mark = $this->db->prepare('UPDATE growth SET used=1 WHERE entry_id=? AND used=0');
        foreach ($race['entries'] as $entry) {
            $g = $growth[$entry['id']] ?? throw new RuntimeException('Missing growth entry.');
            if (($targets[$entry['id']]['bike'] ?? null) !== $entry['bike'] || ($targets[$entry['id']]['player_id'] ?? null) !== $g['player_id']) {
                throw new RuntimeException('Fixed player/bike identity mismatch.');
            }
            $mark->execute([$entry['id']]);
            if ($mark->rowCount() !== 1) {
                throw new RuntimeException('Duplicate input entry.');
            }
            $aligned[] = $g;
        }
        unset($meta['targets']);

        return ['race' => $race, 'growth' => $aligned, 'metadata' => $meta];
    }

    public function complete(): void
    {
        if ((int) $this->db->query('SELECT count(*) FROM growth WHERE used=0')->fetchColumn() !== 0
            || $this->db->query('SELECT count(*) FROM seen')->fetchColumn() !== $this->db->query('SELECT count(*) FROM metadata')->fetchColumn()) {
            throw new RuntimeException('Incomplete fixed cohort.');
        }
    }
}
